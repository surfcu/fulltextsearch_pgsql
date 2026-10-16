<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use InvalidArgumentException;
use OCA\FullTextSearch_PgSql\AppInfo\Application;
use OCP\IAppConfig;

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
	public const PDFTOTEXT_PATH = 'pdftotext_path';
	public const PDF_EXTRACTOR = 'pdf_extractor';

	/** auto: pdftotext when installed, else the bundled parser. */
	public const PDF_EXTRACTORS = ['auto', 'pdftotext', 'pdfparser'];

	public const DEFAULTS = [
		self::LANGUAGE => 'english',
		self::USE_TRIGRAM => true,
		// Accent variants and spelling corrections from the index vocabulary.
		self::TYPO_CORRECTION => true,
		self::MAX_RESULTS => 100,
		// Bytes of extracted text kept per document. PostgreSQL refuses tsvectors over 1 MB.
		self::MAX_CONTENT_SIZE => 512000,
		// Empty means "look for pdftotext on PATH".
		self::PDFTOTEXT_PATH => '',
		self::PDF_EXTRACTOR => 'auto',
	];

	public function __construct(
		private IAppConfig $appConfig,
		private SchemaService $schemaService,
	) {
	}

	public function getConfig(): array {
		return [
			self::LANGUAGE => $this->getLanguage(),
			self::USE_TRIGRAM => $this->useTrigram(),
			self::TYPO_CORRECTION => $this->useTypoCorrection(),
			self::MAX_RESULTS => $this->getMaxResults(),
			self::MAX_CONTENT_SIZE => $this->getMaxContentSize(),
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
			$language = (string)$config[self::LANGUAGE];
			$available = $this->schemaService->getTextSearchConfigs();
			if (!in_array($language, $available, true)) {
				throw new InvalidArgumentException(
					'Unsupported language "' . $language . '". This PostgreSQL server supports: '
					. implode(', ', $available)
				);
			}
			$languageChanged = $language !== $this->getLanguage();
			$writes[] = fn () => $this->appConfig->setValueString(Application::APP_ID, self::LANGUAGE, $language);
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

		foreach ([self::MAX_RESULTS => [1, 10000], self::MAX_CONTENT_SIZE => [0, 1000000]] as $key => [$min, $max]) {
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
			if ($path !== '' && !is_executable($path)) {
				throw new InvalidArgumentException('pdftotext_path is not an executable file: ' . $path);
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
		return $languageChanged;
	}

	/** PostgreSQL text search configuration (regconfig) used for new documents and queries. */
	public function getLanguage(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::LANGUAGE, self::DEFAULTS[self::LANGUAGE]);
	}

	public function useTrigram(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::USE_TRIGRAM, self::DEFAULTS[self::USE_TRIGRAM]);
	}

	public function useTypoCorrection(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::TYPO_CORRECTION, self::DEFAULTS[self::TYPO_CORRECTION]);
	}

	public function getMaxResults(): int {
		return max(1, $this->appConfig->getValueInt(Application::APP_ID, self::MAX_RESULTS, self::DEFAULTS[self::MAX_RESULTS]));
	}

	public function getMaxContentSize(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, self::MAX_CONTENT_SIZE, self::DEFAULTS[self::MAX_CONTENT_SIZE]));
	}

	public function getPdfToTextPath(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::PDFTOTEXT_PATH, self::DEFAULTS[self::PDFTOTEXT_PATH]);
	}

	public function getPdfExtractor(): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, self::PDF_EXTRACTOR, self::DEFAULTS[self::PDF_EXTRACTOR]);
		return in_array($value, self::PDF_EXTRACTORS, true) ? $value : self::DEFAULTS[self::PDF_EXTRACTOR];
	}
}
