<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCA\FullTextSearch_PgSql\Model\ExtractedContent;
use XMLReader;
use ZipArchive;

/**
 * Text from the zip-based office formats: OpenDocument (odt, ods, odp and their templates)
 * and Office Open XML (docx, xlsx, pptx, their macro-enabled and template variants).
 *
 * The XML is read node by node rather than tag-stripped. Word, Excel and PowerPoint split a
 * single word into several runs whenever part of it was edited, spell-checked or formatted
 * (`<w:t>hippo</w:t></w:r><w:r><w:t>potamus</w:t>`), so a break is only added where a
 * paragraph, cell or line ends, never between runs. Each format names the elements that
 * hold text and the ones to leave out: deleted tracked changes, field codes, formulas, the
 * shared-string indexes stored in spreadsheet cells.
 */
class OfficeExtractor {

	/** Uncompressed bytes read from any one entry, whatever its size (zip bombs, huge sheets). */
	private const MAX_ENTRY_BYTES = 32 * 1024 * 1024;

	/**
	 * Per format, in reading order: the entries holding text and how to read them. The main
	 * body comes first, so it survives truncation at max_content_size.
	 *
	 * Rule keys (element local names):
	 *  - text:   collect text only inside these; absent = every text node
	 *  - breaks: their end separates words (paragraph, cell, line break)
	 *  - spaces: empty elements standing for a space (tab, ODF's <text:s/>)
	 *  - skip:   whole subtrees left out
	 *  - attrs:  element => attribute whose value is text (sheet names)
	 *  - cells:  spreadsheet cells, where <v> holds a shared-string index for t="s"
	 *
	 * @var array<string, list<array{0: string, 1: 'WORD'|'DRAWING'|array<string, mixed>}>>
	 */
	private const FORMATS = [
		'odf' => [
			['#^content\.xml$#', [
				'breaks' => ['p', 'h', 'line-break', 'table-cell', 'list-item', 'note-citation', 'annotation-end'],
				'spaces' => ['s', 'tab'],
				// Deleted text kept for change tracking; annotation author and date.
				'skip' => ['tracked-changes', 'creator', 'date', 'sequence-decls', 'automatic-styles', 'font-face-decls'],
			]],
		],
		'docx' => [
			['#^word/document\d*\.xml$#', 'WORD'],
			['#^word/(footnotes|endnotes)\.xml$#', 'WORD'],
			['#^word/(header|footer)\d*\.xml$#', 'WORD'],
			['#^word/comments(Extended)?\.xml$#', 'WORD'],
			['#^word/diagrams/data\d+\.xml$#', 'DRAWING'],
		],
		'xlsx' => [
			['#^xl/sharedStrings\.xml$#', [
				'text' => ['t'],
				'breaks' => ['si'],
				// Phonetic guides (furigana) repeat the text in another script.
				'skip' => ['rPh', 'phoneticPr'],
			]],
			['#^xl/workbook\.xml$#', [
				'text' => [],
				'attrs' => ['sheet' => 'name'],
			]],
			['#^xl/worksheets/sheet\d+\.xml$#', [
				'text' => ['v', 't'],
				'breaks' => ['c'],
				'skip' => ['f', 'rPh'],
				'cells' => true,
			]],
			['#^xl/comments\d*\.xml$#', [
				'text' => ['t'],
				'breaks' => ['comment', 'si'],
				'skip' => ['authors'],
			]],
			['#^xl/threadedComments/threadedComment\d*\.xml$#', [
				'text' => ['text'],
				'breaks' => ['threadedComment'],
			]],
			// Text boxes and shapes, SmartArt.
			['#^xl/drawings/drawing\d+\.xml$#', 'DRAWING'],
			['#^xl/diagrams/data\d+\.xml$#', 'DRAWING'],
		],
		'pptx' => [
			['#^ppt/slides/slide\d+\.xml$#', 'DRAWING'],
			['#^ppt/notesSlides/notesSlide\d+\.xml$#', 'DRAWING'],
			['#^ppt/diagrams/data\d+\.xml$#', 'DRAWING'],
			['#^ppt/comments/(comment|modernComment_)[^/]*\.xml$#', [
				'text' => ['text', 't'],
				'breaks' => ['cm', 'p'],
			]],
		],
	];

	/**
	 * WordprocessingML: text sits in <w:t>; <w:delText>, <w:instrText> are left out by
	 * omission. Text boxes and shapes are written twice, as DrawingML and again inside
	 * <mc:Fallback> as VML for old readers: the fallback copy is skipped.
	 */
	private const WORD = [
		'text' => ['t'],
		'breaks' => ['p', 'br', 'cr', 'tc'],
		'spaces' => ['tab', 'ptab'],
		'skip' => ['Fallback'],
	];

	/** DrawingML (slides, notes, drawings, SmartArt): text sits in <a:t>. */
	private const DRAWING = [
		'text' => ['t'],
		'breaks' => ['p', 'br', 'tc'],
		'skip' => ['Fallback'],
	];

	/** Cell types whose <v> is not text to index: shared-string index, boolean, error. */
	private const CELL_TYPES_WITHOUT_TEXT = ['s', 'b', 'e'];

	/**
	 * @param int $textBudget stop reading once this many bytes of text are collected
	 * @return ExtractedContent|null null when the zip is not a supported office document
	 */
	public function extract(ZipArchive $zip, int $textBudget): ?ExtractedContent {
		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = (string)$zip->getNameIndex($i);
		}

		$format = $this->format($names);
		if ($format === null) {
			return null;
		}

		$parts = [];
		$length = 0;
		$truncated = false;
		foreach (self::FORMATS[$format] as [$pattern, $rule]) {
			$matching = array_values(array_filter($names, static fn (string $n): bool => (bool)preg_match($pattern, $n)));
			// slide2 before slide10, sheet2 before sheet10.
			natsort($matching);
			foreach ($matching as $name) {
				if ($length >= $textBudget) {
					break 2;
				}
				$stat = $zip->statName($name);
				$size = is_array($stat) ? (int)$stat['size'] : 0;
				$xml = $zip->getFromName($name, min(max($size, 1), self::MAX_ENTRY_BYTES));
				if (!is_string($xml) || $xml === '') {
					continue;
				}
				$truncated = $truncated || $size > self::MAX_ENTRY_BYTES;
				$text = $this->readXml($xml, $this->rule($rule), $textBudget - $length);
				if ($text !== '') {
					$parts[] = $text;
					$length += strlen($text) + 1;
				}
			}
		}

		return new ExtractedContent(
			implode("\n", $parts),
			$truncated ? 'office document part larger than ' . intdiv(self::MAX_ENTRY_BYTES, 1024 * 1024) . ' MB, content partially indexed' : null
		);
	}

	/** @param list<string> $names */
	private function format(array $names): ?string {
		foreach ($names as $name) {
			if ($name === 'content.xml') {
				return 'odf';
			}
		}
		foreach ($names as $name) {
			if (str_starts_with($name, 'word/')) {
				return 'docx';
			}
			if (str_starts_with($name, 'xl/')) {
				return 'xlsx';
			}
			if (str_starts_with($name, 'ppt/')) {
				return 'pptx';
			}
		}
		return null;
	}

	/**
	 * @param string|array<string, mixed> $rule
	 * @return array{text: ?list<string>, breaks: list<string>, spaces: list<string>, skip: list<string>, attrs: array<string, string>, cells: bool}
	 */
	private function rule(string|array $rule): array {
		if (is_string($rule)) {
			$rule = $rule === 'WORD' ? self::WORD : self::DRAWING;
		}
		/** @var array{text?: list<string>, breaks?: list<string>, spaces?: list<string>, skip?: list<string>, attrs?: array<string, string>, cells?: bool} $rule */
		return [
			'text' => $rule['text'] ?? null,
			'breaks' => $rule['breaks'] ?? [],
			'spaces' => $rule['spaces'] ?? [],
			'skip' => $rule['skip'] ?? [],
			'attrs' => $rule['attrs'] ?? [],
			'cells' => $rule['cells'] ?? false,
		];
	}

	/**
	 * @param array{text: ?list<string>, breaks: list<string>, spaces: list<string>, skip: list<string>, attrs: array<string, string>, cells: bool} $rule
	 */
	private function readXml(string $xml, array $rule, int $budget): string {
		$previous = libxml_use_internal_errors(true);
		$reader = new XMLReader();
		// No network, no external entities: office files come from users.
		if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
			libxml_use_internal_errors($previous);
			return '';
		}

		$out = '';
		$skipDepth = null;
		$inText = 0;
		$cellHasText = true;
		$textOnly = $rule['text'] !== null;

		try {
			// read() fails at the first malformed or truncated spot: what came before is kept.
			while ($reader->read()) {
				$type = $reader->nodeType;

				if ($type === XMLReader::ELEMENT) {
					$name = $this->localName($reader);
					$empty = $reader->isEmptyElement;
					if ($skipDepth !== null) {
						continue;
					}
					if (in_array($name, $rule['skip'], true)
						|| ($rule['cells'] && $name === 'v' && !$cellHasText)) {
						if (!$empty) {
							$skipDepth = $reader->depth;
						}
						continue;
					}
					if ($rule['cells'] && $name === 'c') {
						$cellHasText = !in_array((string)$reader->getAttribute('t'), self::CELL_TYPES_WITHOUT_TEXT, true);
					}
					if (isset($rule['attrs'][$name])) {
						$value = $reader->getAttribute($rule['attrs'][$name]);
						if ($value !== null && $value !== '') {
							$out .= $value . "\n";
						}
					}
					if (in_array($name, $rule['spaces'], true)) {
						$out .= ' ';
					}
					if ($empty) {
						if (in_array($name, $rule['breaks'], true)) {
							$out .= "\n";
						}
					} elseif ($textOnly && in_array($name, (array)$rule['text'], true)) {
						$inText++;
					}
				} elseif ($type === XMLReader::END_ELEMENT) {
					if ($skipDepth !== null) {
						if ($reader->depth === $skipDepth) {
							$skipDepth = null;
						}
						continue;
					}
					$name = $this->localName($reader);
					if ($textOnly && $inText > 0 && in_array($name, (array)$rule['text'], true)) {
						$inText--;
					}
					if (in_array($name, $rule['breaks'], true)) {
						$out .= "\n";
						if (strlen($out) >= $budget) {
							break;
						}
					}
				} elseif ($skipDepth === null
					&& ($type === XMLReader::TEXT || $type === XMLReader::CDATA || $type === XMLReader::SIGNIFICANT_WHITESPACE)
					&& (!$textOnly || $inText > 0)) {
					$out .= $reader->value;
				}
			}
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		return trim($out);
	}

	/**
	 * The element name without its prefix. libxml already strips declared prefixes; an
	 * undeclared one (sloppy generators) stays in localName as "w:t".
	 */
	private function localName(XMLReader $reader): string {
		$name = $reader->localName;
		$colon = strrpos($name, ':');
		return $colon === false ? $name : substr($name, $colon + 1);
	}
}
