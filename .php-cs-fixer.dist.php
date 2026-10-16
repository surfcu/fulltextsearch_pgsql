<?php

declare(strict_types=1);

require_once './vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

// Nextcloud's coding standard (https://github.com/nextcloud/coding-standard).
// Check: composer cs:check   Fix: composer cs:fix
$config = new Config();
$config
	->getFinder()
	->in(__DIR__)
	->ignoreVCSIgnored(true)
	// Generated: smalot/pdfparser rewritten by scripts/scope-pdfparser.php.
	->notPath('lib/Vendor')
	->notPath('build')
	->notPath('vendor');
return $config;
