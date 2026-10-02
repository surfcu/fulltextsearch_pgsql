<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCA\FullTextSearch_PgSql\Model\ExtractedContent;
use OCP\FullTextSearch\Model\IIndexDocument;
use ZipArchive;

/**
 * Turns whatever a provider put in IIndexDocument::getContent() into plain text.
 *
 * Providers such as files_fulltextsearch send the raw file base64-encoded and expect the
 * platform to extract text (Elasticsearch does it with its attachment pipeline). Here:
 *  - plain text files are decoded directly,
 *  - OpenDocument and Office Open XML files are unzipped and their XML text is read,
 *  - PDFs go through `pdftotext` (poppler-utils) when it is installed,
 *  - anything else is indexed without content and reported as a warning.
 */
class ContentExtractor {

	/** zip entry patterns holding the text of each format family */
	private const ZIP_TEXT_ENTRIES = [
		'#^content\.xml$#',                                      // OpenDocument (odt, ods, odp)
		'#^word/(document|footnotes|endnotes|header\d*|footer\d*)\.xml$#', // docx
		'#^xl/sharedStrings\.xml$#',                             // xlsx
		'#^ppt/(slides/slide|notesSlides/notesSlide)\d+\.xml$#', // pptx
	];

	private const PDF_TIMEOUT_SECONDS = 60;

	public function __construct(
		private ConfigService $configService,
	) {
	}

	public function extract(IIndexDocument $document): ExtractedContent {
		$content = $document->getContent();
		if ($content === '') {
			return new ExtractedContent('');
		}

		if ($document->isContentEncoded() !== IIndexDocument::ENCODED_BASE64) {
			return new ExtractedContent($this->clean($content));
		}

		$raw = base64_decode($content, true);
		if ($raw === false) {
			return new ExtractedContent('', 'content is not valid base64');
		}

		return $this->extractFromBytes($raw);
	}

	public function extractFromBytes(string $raw): ExtractedContent {
		if (str_starts_with($raw, '%PDF-')) {
			return $this->extractPdf($raw);
		}
		if (str_starts_with($raw, "PK\x03\x04")) {
			return $this->extractZipXml($raw);
		}
		if ($this->looksLikeText($raw)) {
			return new ExtractedContent($this->clean($this->toUtf8($raw)));
		}
		return new ExtractedContent('', 'binary content in an unsupported format, indexed without content');
	}

	/**
	 * Make a short field (title, tag) storable without otherwise changing it: valid UTF-8,
	 * no NUL bytes. Titles must round-trip through getDocument() unchanged.
	 */
	public function sanitize(string $text): string {
		return str_replace("\0", '', $this->toUtf8($text));
	}

	/**
	 * Normalise text for PostgreSQL: valid UTF-8, no NUL bytes, no markup, bounded size.
	 */
	public function clean(string $text): string {
		$text = $this->toUtf8($text);
		$text = str_replace("\0", ' ', $text);
		if (preg_match('#</?[a-zA-Z][^>]*>#', $text)) {
			$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		$text = trim((string)preg_replace('/\s+/u', ' ', $text));

		$max = $this->configService->getMaxContentSize();
		if (strlen($text) > $max) {
			// mb_strcut never splits a multibyte character.
			$text = mb_strcut($text, 0, $max, 'UTF-8');
		}
		return $text;
	}

	private function extractPdf(string $raw): ExtractedContent {
		$binary = $this->findPdfToText();
		if ($binary === null) {
			return new ExtractedContent('', 'PDF indexed without content: install poppler-utils (pdftotext) to index PDF text');
		}

		$tmp = tempnam(sys_get_temp_dir(), 'ftspg');
		if ($tmp === false || file_put_contents($tmp, $raw) === false) {
			return new ExtractedContent('', 'PDF indexed without content: could not write temporary file');
		}

		try {
			$process = proc_open(
				[$binary, '-q', '-enc', 'UTF-8', $tmp, '-'],
				[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
				$pipes
			);
			if (!is_resource($process)) {
				return new ExtractedContent('', 'PDF indexed without content: could not run pdftotext');
			}

			stream_set_blocking($pipes[1], false);
			$output = '';
			$limit = $this->configService->getMaxContentSize() * 2 + 1;
			$deadline = time() + self::PDF_TIMEOUT_SECONDS;
			while (!feof($pipes[1]) && time() < $deadline && strlen($output) < $limit) {
				$chunk = fread($pipes[1], 65536);
				if ($chunk === false || $chunk === '') {
					usleep(20000);
					continue;
				}
				$output .= $chunk;
			}
			$timedOut = !feof($pipes[1]) && time() >= $deadline;
			fclose($pipes[1]);
			fclose($pipes[2]);
			if ($timedOut) {
				proc_terminate($process);
			}
			proc_close($process);

			$text = $this->clean($output);
			if ($text === '') {
				return new ExtractedContent('', 'PDF contains no extractable text (scanned images?)');
			}
			return new ExtractedContent($text, $timedOut ? 'pdftotext timed out, content partially indexed' : null);
		} finally {
			@unlink($tmp);
		}
	}

	private function extractZipXml(string $raw): ExtractedContent {
		if (!class_exists(ZipArchive::class)) {
			return new ExtractedContent('', 'php-zip is not installed, office document indexed without content');
		}

		$tmp = tempnam(sys_get_temp_dir(), 'ftspg');
		if ($tmp === false || file_put_contents($tmp, $raw) === false) {
			return new ExtractedContent('', 'could not write temporary file');
		}

		try {
			$zip = new ZipArchive();
			if ($zip->open($tmp, ZipArchive::RDONLY) !== true) {
				return new ExtractedContent('', 'unreadable zip/office file, indexed without content');
			}

			$names = [];
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = (string)$zip->getNameIndex($i);
				foreach (self::ZIP_TEXT_ENTRIES as $pattern) {
					if (preg_match($pattern, $name)) {
						$names[] = $name;
						break;
					}
				}
			}
			// Slides in presentation order: slide2 before slide10.
			natsort($names);

			$budget = $this->configService->getMaxContentSize() * 4 + 1;
			$parts = [];
			foreach ($names as $name) {
				$xml = $zip->getFromName($name, $budget);
				if ($xml === false) {
					continue;
				}
				$budget -= strlen($xml);
				// Keep word boundaries between paragraphs/cells before stripping tags.
				$parts[] = strip_tags(str_replace('<', ' <', $xml));
				if ($budget <= 0) {
					break;
				}
			}
			$zip->close();

			if ($names === []) {
				return new ExtractedContent('', 'zip archive is not a supported office document, indexed without content');
			}
			return new ExtractedContent($this->clean(implode(' ', $parts)));
		} finally {
			@unlink($tmp);
		}
	}

	private function findPdfToText(): ?string {
		$configured = $this->configService->getPdfToTextPath();
		if ($configured !== '') {
			return is_executable($configured) ? $configured : null;
		}
		foreach (explode(PATH_SEPARATOR, (string)getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') as $dir) {
			$candidate = rtrim($dir, '/') . '/pdftotext';
			if (@is_executable($candidate)) {
				return $candidate;
			}
		}
		return null;
	}

	private function looksLikeText(string $raw): bool {
		$sample = substr($raw, 0, 8192);
		if (str_contains($sample, "\0")) {
			// UTF-16 with BOM is text; other NUL-containing data is binary.
			return str_starts_with($sample, "\xFF\xFE") || str_starts_with($sample, "\xFE\xFF");
		}
		// The sample may end in the middle of a multibyte character.
		for ($cut = 0; $cut < 4; $cut++) {
			if (mb_check_encoding(substr($sample, 0, strlen($sample) - $cut), 'UTF-8')) {
				return true;
			}
		}
		// Legacy 8-bit encodings: accept when control characters are rare.
		$controls = preg_match_all('/[\x00-\x08\x0E-\x1F\x7F]/', $sample);
		return $controls < strlen($sample) * 0.02;
	}

	private function toUtf8(string $text): string {
		if (str_starts_with($text, "\xEF\xBB\xBF")) {
			return substr($text, 3);
		}
		if (str_starts_with($text, "\xFF\xFE")) {
			return (string)mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE');
		}
		if (str_starts_with($text, "\xFE\xFF")) {
			return (string)mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE');
		}
		if (mb_check_encoding($text, 'UTF-8')) {
			return $text;
		}
		// Windows-1254 (Turkish) is a superset-compatible guess for most legacy Latin text.
		$converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1254');
		return is_string($converted) ? $converted : mb_scrub($text, 'UTF-8');
	}
}
