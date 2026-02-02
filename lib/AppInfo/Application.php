<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'fulltextsearch_pgsql';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register services, hooks, etc.
    }

    public function boot(IBootContext $context): void {
        // Boot the application
    }
}
