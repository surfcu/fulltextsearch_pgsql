<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCA\FullTextSearch_PgSql\Model\ExtractedContent;
use OCA\FullTextSearch_PgSql\Vendor\Smalot\PdfParser\Config as PdfParserConfig;
use OCA\FullTextSearch_PgSql\Vendor\Smalot\PdfParser\Parser as PdfParser;
use OCP\FullTextSearch\Model\IIndexDocument;
use Throwable;
use ZipArchive;

/**
 * Turns whatever a provider put in IIndexDocument::getContent() into plain text.
 *
 * Providers such as files_fulltextsearch send the raw file base64-encoded and expect the
 * platform to extract text (Elasticsearch does it with its attachment pipeline). Here:
 *  - plain text files are decoded directly,
 *  - OpenDocument and Office Open XML files go to OfficeExtractor,
 *  - PDFs go through `pdftotext` (poppler-utils) when it is installed, and through the
 *    bundled smalot/pdfparser otherwise (slower and hungrier, but pure PHP),
 *  - anything else is indexed without content and reported as a warning.
 */
class ContentExtractor {

	private const PDF_TIMEOUT_SECONDS = 60;
	private const TOOL_TIMEOUT_SECONDS = 60;
	private const MAX_STDERR_BYTES = 65536;

	private const BLOCK_TAGS_PATTERN = '#</?(?:p|div|td|th|li|ul|ol|table|tr|h[1-6]|header|footer|section|article|blockquote|pre|aside|nav|main|figure|figcaption|br|hr)\b[^>]*>#i';

	/** Compound File (OLE2): Office 97–2003 documents, and password-protected Office files. */
	private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

	/**
	 * Office 97–2003 formats, recognised by the name of their main stream in the compound
	 * file's directory (stored as UTF-16LE). In order: a Word or PowerPoint file with an
	 * embedded chart also contains a "Workbook" stream, so those are checked first.
	 * Each is read with a tool from the catdoc package.
	 *
	 * @var array<string, array{streams: list<string>, tool: string, args: list<string>, label: string}>
	 */
	private const LEGACY_OFFICE = [
		'doc' => ['streams' => ['WordDocument'], 'tool' => 'catdoc', 'args' => ['-d', 'utf-8', '-w'], 'label' => 'Word 97–2003 document'],
		'ppt' => ['streams' => ['PowerPoint Document'], 'tool' => 'catppt', 'args' => ['-d', 'utf-8'], 'label' => 'PowerPoint 97–2003 presentation'],
		'xls' => ['streams' => ['Workbook', 'Book'], 'tool' => 'xls2csv', 'args' => ['-d', 'utf-8'], 'label' => 'Excel 97–2003 workbook'],
	];

	/**
	 * The bundled parser holds the whole file and its decoded objects in PHP memory. Above
	 * this size it is not tried at all; below it, it is still skipped when the free memory
	 * under memory_limit is less than PDF_PARSER_MEMORY_FACTOR times the file size.
	 */
	private const PDF_PARSER_MAX_BYTES = 64 * 1024 * 1024;
	private const PDF_PARSER_MEMORY_FACTOR = 12;

	public function __construct(
		private ConfigService $configService,
		private OfficeExtractor $officeExtractor = new OfficeExtractor(),
	) {
	}

	public function extract(IIndexDocument $document): ExtractedContent {
		$content = $document->getContent();
		if ($content === '' || $this->configService->getMaxContentSize() === 0) {
			return new ExtractedContent('');
		}

		if ($document->isContentEncoded() !== IIndexDocument::ENCODED_BASE64) {
			$strip = $this->looksLikeMarkup($content, $document->getTitle(), true);
			return new ExtractedContent($this->clean($content, $strip));
		}

		$raw = base64_decode($content, true);
		if ($raw === false) {
			return new ExtractedContent('', 'content is not valid base64');
		}

		return $this->extractFromBytes($raw, $document->getTitle());
	}

	public function extractFromBytes(string $raw, string $title = ''): ExtractedContent {
		if ($this->configService->getMaxContentSize() === 0) {
			return new ExtractedContent('');
		}
		if (str_starts_with($raw, '%PDF-')) {
			return $this->extractPdf($raw);
		}
		if (str_starts_with($raw, "PK\x03\x04")) {
			return $this->extractZipXml($raw);
		}
		if (str_starts_with($raw, self::OLE_SIGNATURE)) {
			return $this->extractLegacyOffice($raw);
		}
		if ($this->looksLikeText($raw)) {
			$text = $this->toUtf8($raw);
			$strip = $this->looksLikeMarkup($text, $title, false);
			return new ExtractedContent($this->clean($text, $strip));
		}
		return new ExtractedContent('', 'binary content in an unsupported format, indexed without content');
	}

	/**
	 * Detect genuine markup (HTML, XML, SVG) to avoid stripping valid angle brackets in
	 * plain text files or plain comments.
	 */
	public function looksLikeMarkup(string $text, string $title = '', bool $unencoded = false): bool {
		if (str_starts_with($text, "\xEF\xBB\xBF")) {
			$text = substr($text, 3);
		} elseif (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
			$text = substr($text, 2);
		}
		$trimmed = ltrim($text);

		$prefix = strtolower(substr($trimmed, 0, 10));
		if (str_starts_with($prefix, '<!doctype')
			|| str_starts_with($prefix, '<html')
			|| str_starts_with($prefix, '<?xml')
			|| str_starts_with($prefix, '<svg')
		) {
			return true;
		}

		if ($title !== '') {
			$lowerTitle = strtolower($title);
			foreach (['.html', '.htm', '.xhtml', '.xml', '.svg'] as $ext) {
				if (str_ends_with($lowerTitle, $ext)) {
					return true;
				}
			}
		}

		if ($unencoded && preg_match('#</(?:p|div|span|a|li|td)\s*>|<br\s*/?>#i', $text) === 1) {
			return true;
		}

		return false;
	}

	/**
	 * Make a short field (title, tag) storable without otherwise changing it: valid UTF-8,
	 * no NUL bytes. Titles must round-trip through getDocument() unchanged.
	 */
	public function sanitize(string $text): string {
		return str_replace("\0", '', $this->toUtf8($text));
	}

	/**
	 * Normalise text for PostgreSQL: valid UTF-8, no NUL bytes, bounded size.
	 *
	 * @param bool $stripMarkup remove HTML found in the text. Off by default so plain text
	 *                          keeps angle brackets intact; enabled when markup is detected.
	 */
	public function clean(string $text, bool $stripMarkup = false): string {
		$text = $this->toUtf8($text);
		$text = str_replace("\0", ' ', $text);
		if ($stripMarkup) {
			if (str_contains($text, '<')) {
				$text = (string)preg_replace(self::BLOCK_TAGS_PATTERN, ' ', $text);
				$text = strip_tags($text);
			}
			if (str_contains($text, '&')) {
				$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
		}
		$text = trim((string)preg_replace('/\s+/u', ' ', $text));

		$max = $this->configService->getMaxContentSize();
		$cap = $max > 0 ? $max : ConfigService::MAX_CONTENT_SIZE_LIMIT;
		if (strlen($text) > $cap) {
			// mb_strcut never splits a multibyte character.
			$text = mb_strcut($text, 0, $cap, 'UTF-8');
		}
		return $text;
	}

	/**
	 * Which tool PDFs currently go through, for the admin panel and the index log:
	 * 'pdftotext', 'pdfparser', or null when neither can run.
	 */
	public function pdfTool(): ?string {
		$mode = $this->configService->getPdfExtractor();
		if ($mode !== 'pdfparser' && $this->findPdfToText() !== null) {
			return 'pdftotext';
		}
		if ($mode !== 'pdftotext' && $this->pdfParserAvailable()) {
			return 'pdfparser';
		}
		return null;
	}

	public function pdfParserAvailable(): bool {
		return class_exists(PdfParser::class) && function_exists('gzuncompress');
	}

	public function findPdfToTextPath(): ?string {
		return $this->findPdfToText();
	}

	private function extractPdf(string $raw): ExtractedContent {
		$mode = $this->configService->getPdfExtractor();

		$failure = null;
		if ($mode !== 'pdfparser') {
			$binary = $this->findPdfToText();
			if ($binary !== null) {
				[$result, $ran] = $this->extractPdfWithPdfToText($binary, $raw);
				if ($ran || $mode === 'pdftotext') {
					return $result;
				}
				$failure = $result->warning;
			} elseif ($mode === 'pdftotext') {
				return new ExtractedContent('', 'PDF indexed without content: pdftotext is selected but not found; install poppler-utils or set pdf_extractor to auto');
			}
		}

		$result = $this->extractPdfWithParser($raw);
		if ($failure !== null) {
			// pdftotext is installed but broken: say so, whatever the parser managed.
			return new ExtractedContent($result->text, $failure . ', read with the built-in parser instead'
				. ($result->warning !== null ? ' (' . $result->warning . ')' : ''));
		}
		return $result;
	}

	/**
	 * @return array{0: ExtractedContent, 1: bool} the result, and whether pdftotext actually ran
	 *                                             (false: worth trying the bundled parser)
	 */
	private function extractPdfWithPdfToText(string $binary, string $raw): array {
		[$output, $failure, $timedOut] = $this->runTool('pdftotext', [$binary, '-q', '-enc', 'UTF-8', '{file}', '-'], $raw, self::PDF_TIMEOUT_SECONDS);
		if ($failure !== null) {
			return [new ExtractedContent('', $failure), false];
		}

		$text = $this->clean($output, false);
		if ($text === '') {
			return [new ExtractedContent('', 'PDF contains no extractable text (scanned images?)'), true];
		}
		return [new ExtractedContent($text, $timedOut ? 'pdftotext timed out, content partially indexed' : null), true];
	}

	/**
	 * Office 97–2003 files (.doc, .xls, .ppt) through catdoc, xls2csv or catppt, when the
	 * catdoc package is installed. Other compound files (Outlook .msg, Visio, password-
	 * protected Office files) are indexed without content.
	 */
	private function extractLegacyOffice(string $raw): ExtractedContent {
		$kind = $this->legacyOfficeKind($raw);
		if ($kind === null) {
			if (str_contains($raw, $this->utf16('EncryptedPackage'))) {
				return new ExtractedContent('', 'password-protected Office document, indexed without content');
			}
			return new ExtractedContent('', 'compound document in an unsupported format, indexed without content');
		}

		$format = self::LEGACY_OFFICE[$kind];
		$binary = $this->findBinary($format['tool']);
		if ($binary === null) {
			return new ExtractedContent('', $format['label'] . ' indexed without content: install catdoc (it provides catdoc, xls2csv and catppt) to index .doc, .xls and .ppt files');
		}

		[$output, $failure, $timedOut] = $this->runTool($format['tool'], [$binary, ...$format['args'], '{file}'], $raw, self::TOOL_TIMEOUT_SECONDS);
		if ($failure !== null) {
			return new ExtractedContent('', $format['label'] . ' indexed without content: ' . $failure);
		}
		if ($kind === 'xls') {
			$output = $this->csvToText($output);
		}

		$text = $this->clean($output, false);
		if ($text === '') {
			return new ExtractedContent('', $format['label'] . ' contains no extractable text');
		}
		return new ExtractedContent($text, $timedOut ? $format['tool'] . ' timed out, content partially indexed' : null);
	}

	/** 'doc', 'xls', 'ppt', or null for any other compound file. */
	public function legacyOfficeKind(string $raw): ?string {
		foreach (self::LEGACY_OFFICE as $kind => $format) {
			foreach ($format['streams'] as $stream) {
				if (str_contains($raw, $this->utf16($stream))) {
					return $kind;
				}
			}
		}
		return null;
	}

	/** Where the catdoc tools are, or null when the package is not installed. */
	public function findCatdoc(): ?string {
		return $this->findBinary('catdoc');
	}

	/** xls2csv prints one CSV line per row and a form feed between sheets: keep the values. */
	private function csvToText(string $csv): string {
		$lines = [];
		foreach (preg_split('/[\r\n\f]+/', $csv) ?: [] as $line) {
			if ($line === '') {
				continue;
			}
			$fields = array_filter(str_getcsv($line, ',', '"', ''), static fn (?string $f): bool => $f !== null && $f !== '');
			if ($fields !== []) {
				$lines[] = implode(' ', $fields);
			}
		}
		return implode("\n", $lines);
	}

	private function utf16(string $ascii): string {
		return (string)mb_convert_encoding($ascii, 'UTF-16LE', 'ASCII');
	}

	/**
	 * Runs an extraction tool on a temporary copy of $raw, without a shell. '{file}' in
	 * $command is replaced with the copy's path. Output is read until the tool ends, the
	 * timeout passes, or enough text for max_content_size has arrived.
	 *
	 * @param list<string> $command
	 * @return array{0: string, 1: ?string, 2: bool} output, failure message (the tool did not
	 *                                               run, or failed with no output), timed out
	 */
	private function runTool(string $label, array $command, string $raw, int $timeout): array {
		$tmp = tempnam(sys_get_temp_dir(), 'ftspg');
		if ($tmp === false || file_put_contents($tmp, $raw) === false) {
			if ($tmp !== false) {
				@unlink($tmp);
			}
			return ['', 'could not write a temporary file for ' . $label, false];
		}

		try {
			$command = array_map(static fn (string $arg): string => $arg === '{file}' ? $tmp : $arg, $command);
			$pipes = [];
			$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			if (!is_resource($process)) {
				return ['', 'could not run ' . $label, false];
			}

			// Non-blocking: a tool that hangs or floods stderr must not stall indexing.
			stream_set_blocking($pipes[1], false);
			stream_set_blocking($pipes[2], false);
			$output = '';
			$errors = '';
			$limit = $this->configService->getMaxContentSize() * 2 + 1;
			$deadline = microtime(true) + (float)$timeout;
			$stdoutOpen = true;
			$stderrOpen = true;

			while (($stdoutOpen || $stderrOpen) && strlen($output) < $limit && microtime(true) < $deadline) {
				$read = [];
				if ($stdoutOpen) {
					$read[] = $pipes[1];
				}
				if ($stderrOpen) {
					$read[] = $pipes[2];
				}
				$remainingUs = max(1, (int)ceil(($deadline - microtime(true)) * 1000000.0));
				$sec = intdiv($remainingUs, 1000000);
				$usec = $remainingUs % 1000000;
				$write = null;
				$except = null;
				$ready = @stream_select($read, $write, $except, $sec, $usec);
				if ($ready === false) {
					usleep(10000);
					continue;
				}
				if ($ready === 0) {
					continue;
				}
				if ($stdoutOpen && in_array($pipes[1], $read, true)) {
					$chunk = fread($pipes[1], 65536);
					if ($chunk === false) {
						$stdoutOpen = false;
					} else {
						if ($chunk !== '') {
							$output .= $chunk;
						}
						if (feof($pipes[1])) {
							$stdoutOpen = false;
						}
					}
				}
				if ($stderrOpen && in_array($pipes[2], $read, true)) {
					$chunk = fread($pipes[2], 65536);
					if ($chunk === false) {
						$stderrOpen = false;
					} else {
						if ($chunk !== '' && strlen($errors) < self::MAX_STDERR_BYTES) {
							$errors .= substr($chunk, 0, self::MAX_STDERR_BYTES - strlen($errors));
						}
						if (feof($pipes[2])) {
							$stderrOpen = false;
						}
					}
				}
			}

			$hitLimit = strlen($output) >= $limit;
			$allEof = !$stdoutOpen && !$stderrOpen;
			$timedOut = !$hitLimit && !$allEof && microtime(true) >= $deadline;
			fclose($pipes[1]);
			fclose($pipes[2]);
			// Skip proc_get_status() on normal EOF so proc_close() preserves the real exit code.
			if (!$allEof) {
				@proc_terminate($process);
				$running = true;
				for ($i = 0; $i < 10; $i++) {
					$status = proc_get_status($process);
					if (!$status['running']) {
						$running = false;
						break;
					}
					usleep(20000);
				}
				if ($running) {
					@proc_terminate($process, 9);
				}
			}
			$exitCode = proc_close($process);

			if (!$timedOut && !$hitLimit && $exitCode !== 0 && trim($output) === '') {
				$reason = trim(strtok($errors, "\n") ?: '');
				return ['', $label . ' failed (exit code ' . $exitCode . ($reason !== '' ? ': ' . $reason : '') . ')', false];
			}
			// Every tool is asked for UTF-8: drop the odd broken sequence rather than let
			// toUtf8() mistake the whole output for a legacy 8-bit encoding.
			return [mb_scrub($output, 'UTF-8'), null, $timedOut];
		} finally {
			@unlink($tmp);
		}
	}

	private function extractPdfWithParser(string $raw): ExtractedContent {
		if (!$this->pdfParserAvailable()) {
			return new ExtractedContent('', 'PDF indexed without content: install poppler-utils (pdftotext) to index PDF text');
		}

		$size = strlen($raw);
		if ($size > self::PDF_PARSER_MAX_BYTES) {
			return new ExtractedContent('', sprintf(
				'PDF indexed without content: %d MB is too large for the built-in parser; install poppler-utils (pdftotext) to index it',
				intdiv($size, 1024 * 1024)
			));
		}
		$free = $this->freeMemory();
		if ($free !== null && $free < $size * self::PDF_PARSER_MEMORY_FACTOR) {
			return new ExtractedContent('', sprintf(
				'PDF indexed without content: not enough memory_limit for the built-in parser (%d MB file, %d MB free); install poppler-utils (pdftotext) or raise memory_limit',
				max(1, intdiv($size, 1024 * 1024)),
				intdiv($free, 1024 * 1024)
			));
		}

		$config = new PdfParserConfig();
		// Images are never indexed; keeping their bytes only costs memory.
		$config->setRetainImageContent(false);
		// Caps what a single compressed stream may inflate to (zip-bomb style PDFs).
		$config->setDecodeMemoryLimit(self::PDF_PARSER_MAX_BYTES);

		// Malformed files make the parser raise PHP warnings and notices on its way to an
		// exception or a partial result. They say nothing an administrator can act on and
		// would flood nextcloud.log on every indexing run, so they are dropped here; the
		// outcome is still reported through the returned warning.
		$vendorDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Vendor' . DIRECTORY_SEPARATOR;
		set_error_handler(static fn (int $level, string $message, string $file): bool => str_starts_with($file, $vendorDir));
		try {
			$document = (new PdfParser([], $config))->parseContent($raw);
			// Page by page, so a long document stops once enough text has been read.
			$limit = $this->configService->getMaxContentSize() * 2 + 1;
			$text = '';
			foreach ($document->getPages() as $page) {
				$text .= $page->getText() . "\n";
				if (strlen($text) >= $limit) {
					break;
				}
			}
		} catch (Throwable $e) {
			return new ExtractedContent('', 'PDF indexed without content: the built-in parser could not read it (' . $e->getMessage() . ')');
		} finally {
			restore_error_handler();
		}

		$text = $this->clean($text, false);
		if ($text === '') {
			return new ExtractedContent('', 'PDF contains no extractable text (scanned images?)');
		}
		return new ExtractedContent($text);
	}

	/** Bytes left under memory_limit, or null when there is no limit. */
	private function freeMemory(): ?int {
		$limit = trim((string)ini_get('memory_limit'));
		if ($limit === '' || $limit === '-1') {
			return null;
		}
		$bytes = (int)$limit;
		switch (strtolower(substr($limit, -1))) {
			case 'g': $bytes *= 1024;
				// no break
			case 'm': $bytes *= 1024;
				// no break
			case 'k': $bytes *= 1024;
		}
		return max(0, $bytes - memory_get_usage(true));
	}

	private function extractZipXml(string $raw): ExtractedContent {
		if (!class_exists(ZipArchive::class)) {
			return new ExtractedContent('', 'php-zip is not installed, office document indexed without content');
		}

		$tmp = tempnam(sys_get_temp_dir(), 'ftspg');
		if ($tmp === false || file_put_contents($tmp, $raw) === false) {
			if ($tmp !== false) {
				@unlink($tmp);
			}
			return new ExtractedContent('', 'could not write temporary file');
		}

		try {
			$zip = new ZipArchive();
			if ($zip->open($tmp, ZipArchive::RDONLY) !== true) {
				return new ExtractedContent('', 'unreadable zip/office file, indexed without content');
			}
			try {
				$extracted = $this->officeExtractor->extract($zip, $this->configService->getMaxContentSize() * 2 + 1);
			} finally {
				$zip->close();
			}

			if ($extracted === null) {
				return new ExtractedContent('', 'zip archive is not a supported office document, indexed without content');
			}
			return new ExtractedContent($this->clean($extracted->text, false), $extracted->warning);
		} finally {
			@unlink($tmp);
		}
	}

	private function findPdfToText(): ?string {
		$configured = $this->configService->getPdfToTextPath();
		if ($configured !== '') {
			return (basename($configured) === 'pdftotext' && is_file($configured) && is_executable($configured))
				? $configured
				: null;
		}
		return $this->findBinary('pdftotext');
	}

	/**
	 * An executable on PATH. PHP-FPM usually clears the environment, so the common
	 * install directories are searched as well.
	 */
	private function findBinary(string $name): ?string {
		$dirs = array_merge(
			explode(PATH_SEPARATOR, (string)getenv('PATH')),
			['/usr/local/bin', '/usr/bin', '/bin', '/opt/homebrew/bin']
		);
		foreach (array_unique(array_filter($dirs)) as $dir) {
			$candidate = rtrim($dir, '/') . '/' . $name;
			if (@is_file($candidate) && @is_executable($candidate)) {
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
		// Legacy 8-bit encodings: accept when control characters are rare and printable ASCII / whitespace is present.
		$len = strlen($sample);
		if ($len === 0) {
			return true;
		}
		$controls = preg_match_all('/[\x00-\x08\x0E-\x1F\x7F]/', $sample);
		$ascii = preg_match_all('/[\x09\x0A\x0D\x20-\x7E]/', $sample);
		return (float)$controls < (float)$len * 0.02 && (float)$ascii >= (float)$len * 0.15;
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
		// UTF-8 with a few damaged bytes (a cut-off file, or a tool's broken output for rare
		// characters) stays UTF-8: re-reading it all as Windows-1254 would garble every
		// non-ASCII character. Legacy 8-bit text has almost no valid multibyte sequences.
		$scrubbed = mb_scrub($text, 'UTF-8');
		$damaged = substr_count($scrubbed, '?') - substr_count($text, '?');
		$multibyte = (int)preg_match_all('/[^\x00-\x7F]/u', $scrubbed);
		if ($multibyte > 0 && $multibyte >= 2 * $damaged) {
			return $scrubbed;
		}
		// Windows-1254 (Turkish) is a superset-compatible guess for most legacy Latin text.
		$converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1254');
		return is_string($converted) ? $converted : mb_scrub($text, 'UTF-8');
	}
}
