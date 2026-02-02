<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCP\IConfig;

class ConfigService {
    
    private const APP_ID = 'fulltextsearch_pgsql';
    
    /**
     * PostgreSQL built-in text search configurations
     * Reference: https://www.postgresql.org/docs/current/textsearch-dictionaries.html
     */
    private const SUPPORTED_LANGUAGES = [
        'arabic',
        'armenian',
        'basque',
        'catalan',
        'danish',
        'dutch',
        'english',
        'finnish',
        'french',
        'german',
        'greek',
        'hindi',
        'hungarian',
        'indonesian',
        'irish',
        'italian',
        'lithuanian',
        'nepali',
        'norwegian',
        'portuguese',
        'romanian',
        'russian',
        'serbian',
        'spanish',
        'swedish',
        'tamil',
        'turkish',  // Turkish is fully supported!
        'yiddish',
    ];
    
    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    /**
     * Get platform configuration
     */
    public function getConfig(): array {
        return [
            'language' => $this->getAppValue('language', 'english'),
            'use_trigram' => $this->getAppValue('use_trigram', 'true') === 'true',
            'min_word_length' => (int)$this->getAppValue('min_word_length', '3'),
            'max_results' => (int)$this->getAppValue('max_results', '100'),
        ];
    }

    /**
     * Set platform configuration
     */
    public function setConfig(array $config): void {
        if (isset($config['language'])) {
            // Validate language is supported
            if ($this->isLanguageSupported($config['language'])) {
                $this->setAppValue('language', $config['language']);
            } else {
                throw new \InvalidArgumentException(
                    'Unsupported language: ' . $config['language'] . 
                    '. Supported languages: ' . implode(', ', self::SUPPORTED_LANGUAGES)
                );
            }
        }
        if (isset($config['use_trigram'])) {
            $this->setAppValue('use_trigram', $config['use_trigram'] ? 'true' : 'false');
        }
        if (isset($config['min_word_length'])) {
            $this->setAppValue('min_word_length', (string)$config['min_word_length']);
        }
        if (isset($config['max_results'])) {
            $this->setAppValue('max_results', (string)$config['max_results']);
        }
    }

    /**
     * Get PostgreSQL text search language configuration
     */
    public function getLanguage(): string {
        return $this->getAppValue('language', 'english');
    }

    /**
     * Get list of supported languages
     */
    public function getSupportedLanguages(): array {
        return self::SUPPORTED_LANGUAGES;
    }

    /**
     * Validate if a language is supported
     */
    public function isLanguageSupported(string $language): bool {
        return in_array($language, self::SUPPORTED_LANGUAGES, true);
    }

    /**
     * Check if trigram similarity search is enabled
     */
    public function useTrigramSearch(): bool {
        return $this->getAppValue('use_trigram', 'true') === 'true';
    }

    /**
     * Get minimum word length for indexing
     */
    public function getMinWordLength(): int {
        return (int)$this->getAppValue('min_word_length', '3');
    }

    /**
     * Get maximum number of search results
     */
    public function getMaxResults(): int {
        return (int)$this->getAppValue('max_results', '100');
    }

    private function getAppValue(string $key, string $default = ''): string {
        return $this->config->getAppValue(self::APP_ID, $key, $default);
    }

    private function setAppValue(string $key, string $value): void {
        $this->config->setAppValue(self::APP_ID, $key, $value);
    }
}
