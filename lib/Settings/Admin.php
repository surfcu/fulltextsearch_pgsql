<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Settings;

use OCA\FullTextSearch_PgSql\AppInfo\Application;
use OCA\FullTextSearch_PgSql\Service\ConfigService;
use OCA\FullTextSearch_PgSql\Service\SchemaService;
use OCA\FullTextSearch_PgSql\Service\StatusService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;
use Throwable;

/**
 * Settings panel in Administration settings → Full text search, next to the framework's own
 * settings (the section belongs to the fulltextsearch app, which this platform requires).
 */
class Admin implements ISettings {

	public function __construct(
		private ConfigService $configService,
		private SchemaService $schemaService,
		private StatusService $statusService,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'settings-admin');
		Util::addStyle(Application::APP_ID, 'settings-admin');

		try {
			$languages = $this->schemaService->isPostgres() ? $this->schemaService->getTextSearchConfigs() : [];
		} catch (Throwable) {
			$languages = [];
		}
		$config = $this->configService->getConfig();
		$selected = $this->configService->getLanguages();
		foreach ($selected as $language) {
			if (!in_array($language, $languages, true)) {
				// Keep a stored value selectable even if the server stopped offering it.
				$languages[] = $language;
			}
		}

		return new TemplateResponse(Application::APP_ID, 'settings-admin', [
			'config' => $config,
			'defaults' => ConfigService::DEFAULTS,
			'languages' => $languages,
			'selectedLanguages' => $selected,
			'maxLanguages' => ConfigService::MAX_LANGUAGES,
			'pdfExtractors' => ConfigService::PDF_EXTRACTORS,
			'status' => $this->statusService->getStatus(),
			'saveUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.settings.save'),
		], '');
	}

	public function getSection(): string {
		return 'fulltextsearch';
	}

	public function getPriority(): int {
		return 31;
	}
}
