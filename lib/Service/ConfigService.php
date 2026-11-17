<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use InvalidArgumentException;
use OCA\FullTextSearch_PgSql\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Typed access to the app's settings.
 *
 * Keys are compatible with `occ config:app:set fulltextsearch_pgsql <key> --value=<value>`,
 * but `occ fulltextsearch_pgsql:configure` is preferred because it validates values.
 */
class ConfigService {

	public const LANGUAGE = 'language';
	public const USE_TRIGRAM = 'use_trigram';
	public const TYPO_CORRECTION = 'typo_correction';
	public const MAX_RESULTS = 'max_results';
	public const MAX_CONTENT_SIZE = 'max_content_size';
	public const SEARCH_TIMEOUT = 'search_timeout';
	public const PDFTOTEXT_PATH = 'pdftotext_path';
	public const PDF_EXTRACTOR = 'pdf_extractor';

	/** auto: pdftotext when installed, else the bundled parser. */
	public const PDF_EXTRACTORS = ['auto', 'pdftotext', 'pdfparser'];

	/** Each extra language costs one ts_debug pass per indexed document and one query branch. */
	public const MAX_LANGUAGES = 5;

	public const DEFAULTS = [
		// One language, or several separated by commas: the first is the fallback when a
		// document's language cannot be told, the others are detected per document.
		self::LANGUAGE => 'english',
		self::USE_TRIGRAM => true,
		// Accent variants and spelling corrections from the index vocabulary.
		self::TYPO_CORRECTION => true,
		self::MAX_RESULTS => 100,
		// Bytes of extracted text kept per document. PostgreSQL refuses tsvectors over 1 MB.
		self::MAX_CONTENT_SIZE => 512000,
		self::SEARCH_TIMEOUT => 10,
		// Empty means "look for pdftotext on PATH".
		self::PDFTOTEXT_PATH => '',
		self::PDF_EXTRACTOR => 'auto',
	];

	/** @var non-empty-list<string>|null */
	private ?array $cachedLanguages = null;
	private ?string $lastRawLanguage = null;

	public function __construct(
		private IAppConfig $appConfig,
		private SchemaService $schemaService,
		private ?LoggerInterface $logger = null,
	) {
	}

	public function getConfig(): array {
		return [
			self::LANGUAGE => implode(',', $this->getLanguages()),
			self::USE_TRIGRAM => $this->useTrigram(),
			self::TYPO_CORRECTION => $this->useTypoCorrection(),
			self::MAX_RESULTS => $this->getMaxResults(),
			self::MAX_CONTENT_SIZE => $this->getMaxContentSize(),
			self::SEARCH_TIMEOUT => $this->getSearchTimeout(),
			self::PDFTOTEXT_PATH => $this->getPdfToTextPath(),
			self::PDF_EXTRACTOR => $this->getPdfExtractor(),
		];
	}

	/**
	 * Validate and store settings. Unknown keys are rejected. Every value is checked before
	 * any is written, so an invalid one leaves all settings unchanged.
	 *
	 * @return bool true when the language changed (existing documents need reindexing)
	 * @throws InvalidArgumentException
	 */
	public function setConfig(array $config): bool {
		$unknown = array_diff(array_keys($config), array_keys(self::DEFAULTS));
		if ($unknown !== []) {
			throw new InvalidArgumentException('Unknown setting(s): ' . implode(', ', $unknown));
		}

		/** @var list<callable(): void> $writes */
		$writes = [];
		$languageChanged = false;

		if (array_key_exists(self::LANGUAGE, $config)) {
			$languages = $this->parseLanguages($config[self::LANGUAGE]);
			if ($languages === []) {
				throw new InvalidArgumentException('language must name at least one language');
			}
			if (count($languages) > self::MAX_LANGUAGES) {
				throw new InvalidArgumentException('language accepts at most ' . self::MAX_LANGUAGES . ' languages');
			}
			$available = $this->schemaService->getTextSearchConfigs();
			$unsupported = array_diff($languages, $available);
			if ($unsupported !== []) {
				throw new InvalidArgumentException(
					'Unsupported language "' . implode('", "', $unsupported) . '". This PostgreSQL server supports: '
					. implode(', ', $available)
				);
			}
			$value = implode(',', $languages);

			$current = $this->getLanguages();
			$currentPrimary = $current[0];
			$currentExtras = array_slice($current, 1);
			sort($currentExtras);

			$newPrimary = $languages[0];
			$newExtras = array_slice($languages, 1);
			sort($newExtras);

			$languageChanged = ($newPrimary !== $currentPrimary) || ($newExtras !== $currentExtras);
			$writes[] = fn () => $this->appConfig->setValueString(Application::APP_ID, self::LANGUAGE, $value);
		}

		foreach ([self::USE_TRIGRAM, self::TYPO_CORRECTION] as $key) {
			if (!array_key_exists($key, $config)) {
				continue;
			}
			$value = filter_var($config[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
			if ($value === null) {
				throw new InvalidArgumentException($key . ' must be true or false');
			}
			$writes[] = fn () => $this->appConfig->setValueBool(Application::APP_ID, $key, $value);
		}

		foreach ([self::MAX_RESULTS => [1, 10000], self::MAX_CONTENT_SIZE => [0, 1000000], self::SEARCH_TIMEOUT => [0, 300]] as $key => [$min, $max]) {
			if (!array_key_exists($key, $config)) {
				continue;
			}
			$value = filter_var($config[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
			if ($value === false) {
				throw new InvalidArgumentException("$key must be an integer between $min and $max");
			}
			$writes[] = fn () => $this->appConfig->setValueInt(Application::APP_ID, $key, $value);
		}

		if (array_key_exists(self::PDFTOTEXT_PATH, $config)) {
			$path = trim((string)$config[self::PDFTOTEXT_PATH]);
			if ($path !== '') {
				if (basename($path) !== 'pdftotext') {
					throw new InvalidArgumentException('pdftotext_path binary must be named pdftotext: ' . $path);
				}
				if (!is_file($path) || !is_executable($path)) {
					throw new InvalidArgumentException('pdftotext_path is not an executable file: ' . $path);
				}
				if (!$this->validatePdfToTextVersion($path)) {
					throw new InvalidArgumentException('pdftotext_path does not output pdftotext version information: ' . $path);
				}
			}
			$writes[] = fn () => $this->appConfig->setValueString(Application::APP_ID, self::PDFTOTEXT_PATH, $path);
		}

		if (array_key_exists(self::PDF_EXTRACTOR, $config)) {
			$extractor = (string)$config[self::PDF_EXTRACTOR];
			if (!in_array($extractor, self::PDF_EXTRACTORS, true)) {
				throw new InvalidArgumentException(self::PDF_EXTRACTOR . ' must be one of: ' . implode(', ', self::PDF_EXTRACTORS));
			}
			$writes[] = fn () => $this->appConfig->setValueString(Application::APP_ID, self::PDF_EXTRACTOR, $extractor);
		}

		foreach ($writes as $write) {
			$write();
		}
		$this->cachedLanguages = null;
		$this->lastRawLanguage = null;
		return $languageChanged;
	}

	/**
	 * The fallback language: the first configured PostgreSQL text search configuration.
	 * Documents whose language cannot be told are indexed in it.
	 */
	public function getLanguage(): string {
		return $this->getLanguages()[0];
	}

	/**
	 * Every configured language, fallback first. Search tries each of them.
	 * Invalid or uninstalled languages are dropped with a warning; falls back to ['simple']
	 * if all configured languages are invalid.
	 *
	 * @return non-empty-list<string>
	 */
	public function getLanguages(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::LANGUAGE, self::DEFAULTS[self::LANGUAGE]);
		if ($raw === $this->lastRawLanguage && $this->cachedLanguages !== null) {
			return $this->cachedLanguages;
		}

		$languages = $this->parseLanguages($raw);
		if ($languages === []) {
			$languages = [self::DEFAULTS[self::LANGUAGE]];
		}

		$installed = $this->schemaService->getTextSearchConfigs();
		if ($installed !== []) {
			$valid = [];
			$dropped = [];
			foreach ($languages as $lang) {
				if (in_array($lang, $installed, true)) {
					$valid[] = $lang;
				} else {
					$dropped[] = $lang;
				}
			}
			if ($dropped !== []) {
				$this->logger?->warning('Configured language(s) not installed in PostgreSQL, dropping: ' . implode(', ', $dropped));
			}
			$languages = $valid !== [] ? $valid : ['simple'];
		}

		$this->lastRawLanguage = $raw;
		$this->cachedLanguages = $languages;
		return $this->cachedLanguages;
	}

	/**
	 * "turkish, english" or ["turkish", "english"] -> ['turkish', 'english']: trimmed,
	 * lowercased, duplicates dropped, order kept.
	 *
	 * @return list<string>
	 */
	private function parseLanguages(mixed $value): array {
		$items = is_array($value) ? $value : explode(',', (string)$value);
		$languages = [];
		foreach ($items as $item) {
			$language = strtolower(trim((string)$item));
			if ($language !== '' && !in_array($language, $languages, true)) {
				$languages[] = $language;
			}
		}
		return $languages;
	}

	public function useTrigram(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::USE_TRIGRAM, self::DEFAULTS[self::USE_TRIGRAM]);
	}

	public function useTypoCorrection(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::TYPO_CORRECTION, self::DEFAULTS[self::TYPO_CORRECTION]);
	}

	public function getMaxResults(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, self::MAX_RESULTS, self::DEFAULTS[self::MAX_RESULTS]);
		return max(1, min(10000, $value));
	}

	public function getMaxContentSize(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, self::MAX_CONTENT_SIZE, self::DEFAULTS[self::MAX_CONTENT_SIZE]));
	}

	public function getSearchTimeout(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, self::SEARCH_TIMEOUT, self::DEFAULTS[self::SEARCH_TIMEOUT]);
		return max(0, min(300, $value));
	}

	public function getPdfToTextPath(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::PDFTOTEXT_PATH, self::DEFAULTS[self::PDFTOTEXT_PATH]);
	}

	public function getPdfExtractor(): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, self::PDF_EXTRACTOR, self::DEFAULTS[self::PDF_EXTRACTOR]);
		return in_array($value, self::PDF_EXTRACTORS, true) ? $value : self::DEFAULTS[self::PDF_EXTRACTOR];
	}

	/**
	 * Run `<path> -v` and check that the output contains "pdftotext" (case-insensitive).
	 */
	private function validatePdfToTextVersion(string $path): bool {
		$pipes = [];
		$process = @proc_open(
			[$path, '-v'],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		if (!is_resource($process)) {
			$output = [];
			@exec(escapeshellarg($path) . ' -v 2>&1', $output);
			$combined = implode("\n", $output);
			return stripos($combined, 'pdftotext') !== false;
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$stdout = '';
		$stderr = '';
		$deadline = time() + 2;
		while ((!feof($pipes[1]) || !feof($pipes[2])) && time() < $deadline) {
			$chunk1 = fread($pipes[1], 4096);
			if ($chunk1 !== false && $chunk1 !== '') {
				$stdout .= $chunk1;
			}
			$chunk2 = fread($pipes[2], 4096);
			if ($chunk2 !== false && $chunk2 !== '') {
				$stderr .= $chunk2;
			}
			if (feof($pipes[1]) && feof($pipes[2])) {
				break;
			}
			usleep(10000);
		}
		if (time() >= $deadline) {
			@proc_terminate($process);
		}
		@fclose($pipes[1]);
		@fclose($pipes[2]);
		@proc_close($process);

		$combined = $stdout . "\n" . $stderr;
		return stripos($combined, 'pdftotext') !== false;
	}
}
