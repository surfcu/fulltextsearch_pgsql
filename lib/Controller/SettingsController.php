<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Controller;

use OCA\FullTextSearch_PgSql\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SettingsController extends Controller {
    
    private ConfigService $configService;

    public function __construct(
        string $appName,
        IRequest $request,
        ConfigService $configService
    ) {
        parent::__construct($appName, $request);
        $this->configService = $configService;
    }

    /**
     * Get current configuration
     * 
     * @NoAdminRequired
     */
    public function getConfig(): JSONResponse {
        return new JSONResponse($this->configService->getConfig());
    }

    /**
     * Update configuration
     * 
     * @param string $language PostgreSQL text search language
     * @param bool $useTrigram Enable trigram similarity search
     * @param int $minWordLength Minimum word length for indexing
     * @param int $maxResults Maximum number of search results
     */
    public function setConfig(
        string $language = 'english',
        bool $useTrigram = true,
        int $minWordLength = 3,
        int $maxResults = 100
    ): JSONResponse {
        $config = [
            'language' => $language,
            'use_trigram' => $useTrigram,
            'min_word_length' => $minWordLength,
            'max_results' => $maxResults,
        ];

        $this->configService->setConfig($config);

        return new JSONResponse([
            'status' => 'success',
            'config' => $config
        ]);
    }
}
