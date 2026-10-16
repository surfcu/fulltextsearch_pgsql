<?php

declare(strict_types=1);

/**
 * Copies smalot/pdfparser into lib/Vendor under the app's own namespace.
 *
 *   php scripts/scope-pdfparser.php [path/to/smalot/pdfparser]
 *
 * Nextcloud loads every app into one PHP process and does not isolate their dependencies:
 * if another app ships its own copy of smalot/pdfparser, whichever class loads first wins.
 * Rewriting `Smalot\PdfParser` to `OCA\FullTextSearch_PgSql\Vendor\Smalot\PdfParser` makes
 * our copy a separate set of classes, and Nextcloud's PSR-4 autoloading of lib/ finds them
 * with no Composer autoloader at runtime.
 *
 * The default source is vendor/smalot/pdfparser, as installed by `composer install`.
 * lib/Vendor is generated: it is not committed, `make appstore` builds it.
 */

$root = dirname(__DIR__);
$source = rtrim($argv[1] ?? $root . '/vendor/smalot/pdfparser', '/');
$target = $root . '/lib/Vendor/Smalot/PdfParser';
$prefix = 'OCA\\FullTextSearch_PgSql\\Vendor\\';

$sourceSrc = $source . '/src/Smalot/PdfParser';
if (!is_dir($sourceSrc)) {
	fwrite(STDERR, "smalot/pdfparser not found at $source (run composer install first)\n");
	exit(1);
}

// Start from a clean copy so files removed upstream do not linger.
if (is_dir($root . '/lib/Vendor')) {
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root . '/lib/Vendor', FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($items as $item) {
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($root . '/lib/Vendor');
}

$count = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceSrc, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
	$relative = substr($file->getPathname(), strlen($sourceSrc) + 1);
	$destination = $target . '/' . $relative;
	if (!is_dir(dirname($destination))) {
		mkdir(dirname($destination), 0755, true);
	}

	$code = (string)file_get_contents($file->getPathname());
	if (str_ends_with($relative, '.php')) {
		// Class names written inside strings, with escaped backslashes ('\\Smalot\\PdfParser\\…').
		$code = str_replace('Smalot\\\\PdfParser', str_replace('\\', '\\\\', $prefix) . 'Smalot\\\\PdfParser', $code);
		// Everything else: namespace and use statements, \Smalot\PdfParser\… references.
		$code = (string)preg_replace('/(?<![\\\\\w])(\\\\?)Smalot\\\\PdfParser(?=[\\\\;\s\'"(:])/', '$1' . str_replace('\\', '\\\\', $prefix) . 'Smalot\\\\PdfParser', $code);
		$count++;
	}
	file_put_contents($destination, $code);
}

// LGPL-3.0: the licence travels with the code.
copy($source . '/LICENSE.txt', $root . '/lib/Vendor/Smalot/LICENSE.txt');

$composer = json_decode((string)file_get_contents($source . '/composer.json'), true);
$version = $composer['version'] ?? trim((string)@shell_exec('git -C ' . escapeshellarg($source) . ' describe --tags 2>/dev/null'));
file_put_contents(
	$root . '/lib/Vendor/Smalot/README.md',
	'smalot/pdfparser ' . ($version !== '' ? $version . ' ' : '') . "(LGPL-3.0, see LICENSE.txt), namespaced under\n"
	. "`OCA\\FullTextSearch_PgSql\\Vendor` by scripts/scope-pdfparser.php. Generated: do not edit.\n"
	. "Upstream: https://github.com/smalot/pdfparser\n"
);

// Any reference left unprefixed would load (or miss) a foreign copy at runtime.
$leftovers = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)) as $file) {
	$code = (string)file_get_contents($file->getPathname());
	if (preg_match_all('/(?<!Vendor\\\\|Vendor\\\\\\\\)Smalot\\\\{1,2}PdfParser/', $code, $m)) {
		$leftovers[] = $file->getPathname() . ' (' . count($m[0]) . ')';
	}
}
if ($leftovers !== []) {
	fwrite(STDERR, "Unprefixed references remain:\n  " . implode("\n  ", $leftovers) . "\n");
	exit(1);
}

echo "Scoped $count files of smalot/pdfparser into lib/Vendor\n";
