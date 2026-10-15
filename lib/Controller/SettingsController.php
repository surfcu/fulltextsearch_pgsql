<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Controller;

use InvalidArgumentException;
use OCA\FullTextSearch_PgSql\Service\ConfigService;
use OCA\FullTextSearch_PgSql\Service\StatusService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Saves the admin panel. Admin-only and CSRF-checked: the App Framework applies both to
 * every controller method that does not opt out, and this one does not.
 */
class SettingsController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private StatusService $statusService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param array<string, mixed> $settings the same keys as `occ fulltextsearch_pgsql:configure`
	 */
	public function save(array $settings = []): JSONResponse {
		try {
			$languageChanged = $this->configService->setConfig($settings);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse([
			'config' => $this->configService->getConfig(),
			'status' => $this->statusService->getStatus(),
			'languageChanged' => $languageChanged,
		]);
	}
}
