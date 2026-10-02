<?php

declare(strict_types=1);

/**
 * Test bootstrap: real OCP FullTextSearch interfaces + small fakes for the parts of
 * Nextcloud that need a running server (database connection, app config).
 *
 * Needs: nextcloud/ocp in vendor/ (composer install) and a PostgreSQL database given by
 * FTSPG_TEST_DSN, e.g. "pgsql:host=localhost;port=5432;dbname=test;user=postgres".
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

// Minimal versions of large server interfaces, declared before the real ones can autoload.
require __DIR__ . '/fakes/interfaces.php';

spl_autoload_register(static function (string $class) use ($root): void {
	if (str_starts_with($class, 'OCP\\')) {
		$file = $root . '/vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
});

require __DIR__ . '/stubs/DocumentAccess.php';
require __DIR__ . '/stubs/IndexDocument.php';
require __DIR__ . '/stubs/psr-log.php';
require __DIR__ . '/fakes/fakes.php';
