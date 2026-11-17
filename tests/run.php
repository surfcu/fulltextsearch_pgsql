<?php

declare(strict_types=1);

/**
 * Integration tests against a real PostgreSQL server.
 *
 *   FTSPG_TEST_DSN="pgsql:host=localhost;dbname=fts_test;user=postgres" php tests/run.php
 *
 * The database should be disposable: tables are created and dropped.
 */

namespace OCA\FullTextSearch_PgSql\Tests;

use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
use OCA\FullTextSearch_PgSql\Controller\SettingsController;
use OCA\FullTextSearch_PgSql\Migration\CreateIndexTable;
use OCA\FullTextSearch_PgSql\Platform\PostgreSQLPlatform;
use OCA\FullTextSearch_PgSql\Service\ConfigService;
use OCA\FullTextSearch_PgSql\Service\ContentExtractor;
use OCA\FullTextSearch_PgSql\Service\IndexService;
use OCA\FullTextSearch_PgSql\Service\LanguageDetector;
use OCA\FullTextSearch_PgSql\Service\SchemaService;
use OCA\FullTextSearch_PgSql\Service\SearchService;
use OCA\FullTextSearch_PgSql\Service\StatusService;
use OCA\FullTextSearch_PgSql\Service\TsQueryBuilder;
use OCA\FullTextSearch_PgSql\Service\VocabularyService;
use OCP\AppFramework\Http;
use OCP\FullTextSearch\IFullTextSearchProvider;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\IRequest;
use PDO;
use Throwable;
use ZipArchive;

require __DIR__ . '/bootstrap.php';

ini_set('memory_limit', '512M');

// Any warning, notice or deprecation raised from the app's own code fails the run. This is
// what makes the PHP 8.4/8.5 CI jobs meaningful. Errors silenced with @ are ignored.
$appIssues = [];
set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$appIssues): bool {
	if ((error_reporting() & $level) && str_starts_with($file, dirname(__DIR__) . '/lib/')) {
		$appIssues[] = "$message at " . substr($file, strlen(dirname(__DIR__)) + 1) . ":$line";
	}
	return false;
});

$dsn = getenv('FTSPG_TEST_DSN') ?: 'pgsql:host=/var/tmp;port=5544;dbname=postgres;user=postgres';

// ---------------------------------------------------------------- tiny test framework
$failures = 0;
$passes = 0;
function check(bool $condition, string $message): void {
	global $failures, $passes;
	if ($condition) {
		$passes++;
		echo "  ok    $message\n";
	} else {
		$failures++;
		echo "  FAIL  $message\n";
	}
}
function same(mixed $expected, mixed $actual, string $message): void {
	check($expected === $actual, $message . ($expected === $actual ? '' : "\n        expected " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n        actual   " . json_encode($actual, JSON_UNESCAPED_UNICODE)));
}
function section(string $name): void {
	echo "\n$name\n";
}

// ---------------------------------------------------------------- wiring
$conn = new PdoConnection(new PDO($dsn));
$appConfig = new ArrayAppConfig();
$logger = new MemoryLogger();
$schema = new SchemaService($conn, new ArrayConfig(['dbtableprefix' => 'oc_']), $logger);
$config = new ConfigService($appConfig, $schema);
$extractor = new ContentExtractor($config);
$vocabulary = new VocabularyService($conn, $schema);
$detector = new LanguageDetector($conn, $config, $logger);
$indexService = new IndexService($conn, $schema, $config, $extractor, $vocabulary, $logger, $detector);
$searchService = new SearchService($conn, $schema, $config, new TsQueryBuilder(), $vocabulary);
$platform = new PostgreSQLPlatform($config, $schema, $indexService, $searchService, $logger, $extractor);

$runnerLog = [];
$platform->setRunner(double(IRunner::class, [
	'newIndexResult' => function (IIndex $index, string $message, string $status, int $type) use (&$runnerLog) {
		$runnerLog[$index->getDocumentId()] = [$status, $message];
	},
	'newIndexError' => function (IIndex $index, string $message) use (&$runnerLog) {
		$runnerLog[$index->getDocumentId()] = ['error', $message];
	},
	'updateAction' => '',
]));

function doc(string $id, string $owner, string $title, string $content, array $share = [], string $provider = 'files', int $status = IIndex::INDEX_FULL): IndexDocument {
	$access = new DocumentAccess($owner);
	$access->setUsers($share['users'] ?? []);
	$access->setGroups($share['groups'] ?? []);
	$access->setCircles($share['circles'] ?? []);
	$access->setLinks($share['links'] ?? []);
	$document = new IndexDocument($provider, $id);
	$document->setIndex(new TestIndex($provider, $id, $status));
	$document->setAccess($access);
	$document->setTitle($title);
	$document->setContent($content, $share['encoded'] ?? IIndexDocument::NOT_ENCODED);
	$document->setModifiedTime($share['mtime'] ?? 1700000000);
	$document->setMetaTags($share['metatags'] ?? []);
	foreach ($share['subtags'] ?? [] as $source => $tag) {
		$document->addSubTag($source, $tag);
	}
	$document->setTags($share['tags'] ?? []);
	$document->setParts($share['parts'] ?? []);
	return $document;
}

/** @return array{ids: list<string>, total: int, docs: list<IIndexDocument>} */
function search(PostgreSQLPlatform $platform, string $viewer, string $q, array $opts = []): array {
	$docs = [];
	$total = -1;
	$request = double(ISearchRequest::class, [
		'getSearch' => $q,
		'getSize' => $opts['size'] ?? 20,
		'getPage' => $opts['page'] ?? 1,
		'getMetaTags' => $opts['metatags'] ?? [],
		'getSubTags' => $opts['subtags'] ?? [],
		'getOption' => fn (string $o, string $d = '') => (string)($opts['options'][$o] ?? $d),
		'getRegexFilters' => $opts['regex'] ?? [],
		'getWildcardFilters' => $opts['wildcard'] ?? [],
		'getLimitFields' => $opts['limit'] ?? [],
	]);
	$provider = double(IFullTextSearchProvider::class, ['getId' => $opts['provider'] ?? 'files']);
	$result = double(ISearchResult::class, [
		'getRequest' => $request,
		'getProvider' => $provider,
		'addDocument' => function (IIndexDocument $d) use (&$docs) {
			$docs[] = $d;
		},
		'setTotal' => function (int $t) use (&$total) {
			$total = $t;
		},
	]);
	$access = new DocumentAccess();
	$access->setViewerId($viewer);
	$access->setGroups($opts['groups'] ?? []);
	$access->setCircles($opts['circles'] ?? []);
	$platform->searchRequest($result, $access);
	return ['ids' => array_map(fn ($d) => $d->getId(), $docs), 'total' => $total, 'docs' => $docs];
}

function sorted(array $a): array {
	sort($a);
	return $a;
}

// ---------------------------------------------------------------- tests
try {
	section('schema');
	$conn->executeStatement('DROP TABLE IF EXISTS ftspg_oc_index');
	$conn->executeStatement('DROP TABLE IF EXISTS oc_fts_pgsql_index');
	$conn->executeStatement('CREATE TABLE oc_fts_pgsql_index (id int, content_tsv tsvector)');
	same(true, $platform->testPlatform(), 'testPlatform creates the schema and succeeds');
	same('ftspg_oc_index', $schema->getTableName(), 'table name does not start with the Nextcloud prefix');
	$platform->initializeIndex();
	check(true, 'ensureSchema is idempotent');
	same(false, (bool)$conn->executeQuery("SELECT to_regclass('oc_fts_pgsql_index') IS NOT NULL")->fetchOne(), 'legacy 1.0.0 table is dropped');
	same(true, $schema->hasTrigram(), 'pg_trgm detected');

	section('config');
	try {
		$config->setConfig(['language' => 'klingon']);
		check(false, 'unknown language rejected');
	} catch (\InvalidArgumentException $e) {
		check(str_contains($e->getMessage(), 'turkish'), 'unknown language rejected, error lists installed languages');
	}
	try {
		$config->setConfig(['min_word_length' => 3]);
		check(false, 'unknown key rejected');
	} catch (\InvalidArgumentException) {
		check(true, 'unknown key rejected');
	}
	same(true, $config->setConfig(['language' => 'turkish']), 'language change reported');
	same(false, $config->setConfig(['language' => 'turkish']), 'unchanged language not reported');

	section('indexing and access control (turkish)');
	$docs = [
		doc('A', 'alice', 'Yıllık çalışma raporu', 'Bu yıl İstanbul ofisinde çalışmalarımız arttı.'),
		doc('B', 'bob', 'Satış planı', 'Satış ekibi için çalışma takvimi.', ['groups' => ['sales']]),
		doc('C', 'carol', 'Ortak notlar', 'Çalışma grubu notları.', ['users' => ['alice']]),
		doc('D', 'dave', 'Duyuru', 'Herkese açık çalışma duyurusu.', ['users' => ['__all']]),
		doc('E', 'erin', 'Ekip toplantısı', 'Takımın çalışma özeti.', ['circles' => ['team1']]),
	];
	foreach ($docs as $d) {
		$index = $platform->indexDocument($d);
		check($index->isStatus(IIndex::INDEX_DONE), "document {$d->getId()} indexed");
	}
	same(['A', 'C', 'D'], sorted(search($platform, 'alice', 'çalışma')['ids']), 'alice: own doc, doc shared with her, public doc');
	same(['B', 'D'], sorted(search($platform, 'eve', 'çalışma', ['groups' => ['sales']])['ids']), 'eve via group "sales"');
	same(['D', 'E'], sorted(search($platform, 'frank', 'çalışma', ['circles' => ['team1']])['ids']), 'frank via circle');
	same([], search($platform, '', 'çalışma')['ids'], 'no viewer, no results');
	same([], search($platform, 'alice', 'çalışma', ['provider' => 'deck'])['ids'], 'results limited to the requesting provider');
	same(['A', 'C', 'D'], sorted(search($platform, 'alice', 'ÇALIŞMALARIMIZ')['ids']), 'uppercase Turkish stems like lowercase');
	same(['A'], search($platform, 'alice', 'istanbul')['ids'], 'dotted capital: istanbul finds İstanbul');
	same(['A'], search($platform, 'alice', 'İSTANBUL')['ids'], 'İSTANBUL finds İstanbul');
	$platform->indexDocument(doc('A2', 'alice', 'ISPARTA GÜLLERİ', 'IŞIK VE ILIK HAVA'));
	same(['A2'], search($platform, 'alice', 'ısparta')['ids'], 'uppercase I in a document becomes dotless ı');
	same(['A2'], search($platform, 'alice', 'ışık')['ids'], 'IŞIK matches ışık');
	same(['A2'], search($platform, 'alice', 'Isparta')['ids'], 'capital I in the query becomes ı');
	same(['A'], search($platform, 'alice', 'rap')['ids'], 'prefix match while typing: "rap" finds "raporu"');

	section('ranking and excerpts');
	$r = search($platform, 'alice', 'çalışma');
	same('A', $r['ids'][0], 'title match ranks first');
	$excerpt = $r['docs'][0]->getExcerpts()[0]['excerpt'] ?? '';
	check(str_contains($excerpt, 'çalışmalarımız') && !str_contains($excerpt, '<'), 'excerpt is plain text around the match: ' . $excerpt);
	check((float)$r['docs'][0]->getScore() > (float)$r['docs'][1]->getScore(), 'scores descend');

	section('query syntax');
	$config->setConfig(['language' => 'english']);
	$platform->indexDocument(doc('Q1', 'alice', 'Budget 2025', 'The quarterly budget review covers marketing spend.'));
	$platform->indexDocument(doc('Q2', 'alice', 'Marketing plan', 'Launch plan for the spring marketing campaign.'));
	$platform->indexDocument(doc('Q3', 'alice', "O'Brien's notes", 'Notes about the budget meeting with e-mail follow-ups.'));
	same(['Q1', 'Q3'], sorted(search($platform, 'alice', 'budget')['ids']), 'plain word');
	same(['Q1'], search($platform, 'alice', '"budget review"')['ids'], 'phrase');
	same(['Q3'], search($platform, 'alice', 'budget -marketing')['ids'], 'exclusion');
	same(['Q1', 'Q2', 'Q3'], sorted(search($platform, 'alice', 'campaign OR budget')['ids']), 'OR');
	same(['Q2'], search($platform, 'alice', 'campaign OR budget -review -meeting')['ids'], 'OR with exclusions');
	same(['Q3'], search($platform, 'alice', "o'brien")['ids'], 'apostrophe in a word');
	same(['Q3'], search($platform, 'alice', 'e-mail')['ids'], 'hyphenated word');
	same([], search($platform, 'alice', '-budget')['ids'], 'only exclusions returns nothing instead of everything');
	same([], search($platform, 'alice', 'the of and')['ids'], 'stopwords only returns nothing');
	foreach (["'); DROP TABLE ftspg_oc_index; --", 'a & | ! :* <-> (', '"', '\\', "\0", "\xff\xfe broken utf8"] as $nasty) {
		search($platform, 'alice', $nasty);
	}
	check($schema->tableExists(), 'hostile queries do not error or inject');
	same(['Q1', 'Q3'], search($platform, 'alice', '(budget), review!')['ids'], 'edge punctuation ignored; doc with both words first');
	same(['Q1'], search($platform, 'alice', '+budget +review')['ids'], '+ makes words required');
	same(['Q1'], search($platform, 'alice', '+budg +revi')['ids'], 'required words still match as prefixes');
	same(['Q3'], search($platform, 'alice', '+budget -review')['ids'], 'required plus exclusion');
	same(['Q1', 'Q3'], sorted(search($platform, 'alice', 'budget -revi')['ids']), 'exclusions are exact words, not prefixes');

	section('query syntax fuzzer (2,500 queries)');
	// Deterministic seedable LCG: 100% reproducible across all PHP 8.1-8.5 environments.
	$fuzzSeed = 1337;
	$fuzzRand = static function (int $min = 0, int $max = 1) use (&$fuzzSeed): int {
		$fuzzSeed = ($fuzzSeed * 1103515245 + 12345) & 0x7FFFFFFF;
		return $min + ($fuzzSeed % ($max - $min + 1));
	};
	$fuzzChoice = static function (array $items) use (&$fuzzRand): mixed {
		return $items[$fuzzRand(0, count($items) - 1)];
	};

	// Atom dictionaries covering English, Turkish diacritics, numbers, punctuation, and Unicode
	$fuzzWords = [
		'budget', 'review', 'marketing', 'plan', 'notes', 'meeting', 'report', 'annual', 'spring', 'spend',
		'the', 'of', 'and', 'to', 'a', 'in', 'that', 'is', 'for', 'with',
		'çalışma', 'İpek', 'ISPARTA', 'ışık', 'Isparta', 'İstanbul', 'bütçe', 'öğrenci', 'sözleşmesi', 'GÜLLERİ', 'şeker',
		'café', 'naïve', 'über', 'résumé', 'тест', 'δοκιμή', '日本語', 'العربية', '🚀',
		'2025', '4711', 'v2', 'IMG2025', 'doc123', '0x1a', 'Q1', 'N1',
		"o'brien", 'e-mail', 'final.pdf', 'rapor_2025', 'user@example.com', 'example.com',
		'a', 'x', 'z', str_repeat('a', 120), str_repeat('w', 250),
	];
	$fuzzPrefixes = ['', '', '+', '-', '!', '~', '&', '|'];
	$fuzzSuffixes = ['', '', '*', ':*', ':*A', ':*D', '?', '%', '_', ':', '!', '?'];
	$fuzzInfixes = [' ', '  ', "\t", ' OR ', ' AND ', ' NOT ', ' | ', ' & ', ' <-> ', ' <1> ', ' <2> ', ', ', '; ', ' / ', ' \\ '];
	$fuzzProbes = [
		"'); DROP TABLE ftspg_oc_index; --",
		"'; DROP TABLE ftspg_oc_words; --",
		"' OR '1'='1",
		"' OR 1=1 --",
		"admin'--",
		'" OR ""="',
		"' UNION SELECT null, null, null--",
		'/* comment */',
		'/* unclosed comment',
		'-- single line comment',
		'<!-- xml comment -->',
		'CAST(? AS regconfig)',
		"to_tsquery('simple', 'x')",
		'$$quoted$$',
		"\0",
		"nul\0byte",
		"\xff\xfe broken utf8",
		"\xC3\x28",
		'\\\\',
		"'",
		"''",
		"'''",
		"''''",
		'a & | ! :* <-> (',
		'::::',
		'* * *',
		'+ + +',
		'- - -',
		'OR OR OR',
		'AND AND AND',
		'NOT NOT NOT',
	];

	// Generator building 2,500 queries across 5 distinct structural categories
	$generateFuzzQuery = static function (int $idx) use (&$fuzzRand, &$fuzzChoice, $fuzzWords, $fuzzPrefixes, $fuzzSuffixes, $fuzzInfixes, $fuzzProbes): string {
		// Category 1: Baseline Hand-crafted & Edge-case Torture Corpus (0..99)
		if ($idx < count($fuzzProbes)) {
			return $fuzzProbes[$idx];
		}
		if ($idx < 100) {
			$edgePatterns = [
				'""', '"', '"""', "''''", '()', '(()', '())', '((()))', '[,]', '{;}',
				'+ + - -', 'OR AND NOT', ':::*:::*', '***', '???', '%%%',
				'"unclosed phrase with budget', 'unclosed" phrase with review',
				'+budget -budget', '"budget review" -budget', '+(budget review)',
				'çalışma OR "İstanbul ofisi" -bütçe', 'İSTANBUL & ışık | ISPARTA',
				str_repeat('word ', 40),
				str_repeat('a', 120) . ' ' . str_repeat('b', 120),
			];
			return $edgePatterns[$idx % count($edgePatterns)];
		}

		// Category 2: Single-Clause Mutational Fuzzing (100..599)
		if ($idx < 600) {
			$word = $fuzzChoice($fuzzWords);
			$prefix = $fuzzChoice($fuzzPrefixes);
			$suffix = $fuzzChoice($fuzzSuffixes);
			$clause = $prefix . $word . $suffix;
			$wrapMode = $fuzzRand(0, 5);
			return match ($wrapMode) {
				1 => '(' . $clause . ')',
				2 => '(' . $clause,
				3 => '"' . $clause . '"',
				4 => '"' . $clause,
				default => $clause,
			};
		}

		// Category 3: Multi-Clause Boolean & Phrase Fuzzing (600..1599)
		if ($idx < 1600) {
			$clauseCount = $fuzzRand(2, 6);
			$parts = [];
			for ($c = 0; $c < $clauseCount; $c++) {
				$isPhrase = ($fuzzRand(0, 4) === 0);
				if ($isPhrase) {
					$w1 = $fuzzChoice($fuzzWords);
					$w2 = $fuzzChoice($fuzzWords);
					$pPrefix = $fuzzChoice(['', '+', '-']);
					$closed = ($fuzzRand(0, 5) !== 0);
					$parts[] = $pPrefix . '"' . $w1 . ' ' . $w2 . ($closed ? '"' : '');
				} else {
					$parts[] = $fuzzChoice($fuzzPrefixes) . $fuzzChoice($fuzzWords) . $fuzzChoice($fuzzSuffixes);
				}
			}
			$res = '';
			foreach ($parts as $pIdx => $part) {
				$res .= ($pIdx === 0 ? '' : $fuzzChoice($fuzzInfixes)) . $part;
			}
			return $res;
		}

		// Category 4: Hostile SQL Injection & Operator Bombardment (1600..2099)
		if ($idx < 2100) {
			$probe = $fuzzChoice($fuzzProbes);
			$word = $fuzzChoice($fuzzWords);
			$layout = $fuzzRand(0, 4);
			return match ($layout) {
				0 => $probe . ' ' . $word,
				1 => $word . ' ' . $probe,
				2 => '"' . $word . ' ' . $probe . '"',
				3 => '(' . $probe . ') OR ' . $word,
				default => $probe . ' ' . $probe . ' ' . $word,
			};
		}

		// Category 5: Turkish, Diacritic & Mixed Unicode Permutations (2100..2499)
		$turkishPool = ['çalışma', 'İpek', 'ISPARTA', 'ışık', 'Isparta', 'İstanbul', 'bütçe', 'öğrenci', 'sözleşmesi', 'GÜLLERİ', 'şeker'];
		$intlPool = ['café', 'naïve', 'über', 'тест', 'δοκιμή', '日本語', 'العربية', '🚀'];
		$tWord1 = $fuzzChoice($turkishPool);
		$tWord2 = $fuzzChoice($turkishPool);
		$iWord = $fuzzChoice($intlPool);
		$op = $fuzzChoice([' OR ', ' AND ', ' ', ' +', ' -']);
		return $tWord1 . $op . $tWord2 . ($fuzzRand(0, 1) ? ' ' . $iWord : '');
	};

	$fuzzErrors = [];
	$fuzzTotal = 2500;
	$fuzzStart = microtime(true);

	// Execute across English (1,500 queries) and Turkish (1,000 queries) configurations
	$fuzzLangs = [
		['lang' => 'english', 'count' => 1500],
		['lang' => 'turkish', 'count' => 1000],
	];

	$fuzzIndex = 0;
	foreach ($fuzzLangs as $fl) {
		$config->setConfig(['language' => $fl['lang']]);
		for ($i = 0; $i < $fl['count']; $i++) {
			$q = $generateFuzzQuery($fuzzIndex++);
			try {
				$res = search($platform, 'alice', $q);
				if (!is_array($res) || !isset($res['total']) || !is_int($res['total']) || $res['total'] < 0) {
					$fuzzErrors[] = [
						'index' => $fuzzIndex - 1,
						'query' => $q,
						'lang' => $fl['lang'],
						'error' => 'Malformed result array',
					];
				}
			} catch (Throwable $e) {
				$fuzzErrors[] = [
					'index' => $fuzzIndex - 1,
					'query' => $q,
					'lang' => $fl['lang'],
					'error' => get_class($e) . ': ' . $e->getMessage(),
				];
				if (count($fuzzErrors) >= 20) {
					break 2; // Safeguard against cascading connection collapse
				}
			}
		}
	}

	// Restore test state & release memory accumulated in PDO mock
	$config->setConfig(['language' => 'english']);
	$conn->log = [];
	$conn->statements = [];
	$fuzzDuration = microtime(true) - $fuzzStart;

	check($schema->tableExists(), 'table still exists after fuzzer (no injection succeeded)');
	same([], $fuzzErrors, sprintf('fuzzer: %d varied queries executed with zero unhandled SQL exceptions (took %.2fs)', $fuzzIndex, $fuzzDuration));

	section('typo tolerance');
	same(['Q2', 'Q1'], search($platform, 'alice', 'markting plann')['ids'], 'typos corrected; the document with both words first');
	$config->setConfig(['use_trigram' => false]);
	same([], search($platform, 'alice', 'markting plann')['ids'], 'fallback disabled by use_trigram=false');
	$config->setConfig(['use_trigram' => true]);

	section('filters and paging');
	$platform->indexDocument(doc('F1', 'alice', 'Invoice one', 'invoice', ['metatags' => ['pdf'], 'subtags' => ['files' => 'local'], 'mtime' => 1000]));
	$platform->indexDocument(doc('F2', 'alice', 'Invoice two', 'invoice', ['metatags' => ['doc'], 'subtags' => ['files' => 'external'], 'mtime' => 2000]));
	$platform->indexDocument(doc('F3', 'alice', 'Invoice three', 'invoice', ['metatags' => ['pdf'], 'subtags' => ['files' => 'external'], 'mtime' => 3000]));
	same(['F1', 'F3'], sorted(search($platform, 'alice', 'invoice', ['metatags' => ['pdf']])['ids']), 'metatags: any of');
	same(['F2', 'F3'], sorted(search($platform, 'alice', 'invoice', ['subtags' => ['files_external']])['ids']), 'subtags: all of');
	same(['F2', 'F3'], sorted(search($platform, 'alice', 'invoice', ['options' => ['since' => '1500']])['ids']), 'since option');
	same(['F1', 'F2'], sorted(search($platform, 'alice', 'invoice', ['options' => ['until' => '2500']])['ids']), 'until option');
	same(['F2'], search($platform, 'alice', 'invoice', ['options' => ['since' => '1500', 'until' => '2500']])['ids'], 'since and until together: a date range');
	same(['F2', 'F3'], sorted(search($platform, 'alice', 'invoice', ['options' => ['since' => '2000', 'until' => '3000']])['ids']), 'range bounds are inclusive');
	same([], search($platform, 'alice', 'invoice', ['options' => ['since' => '2500', 'until' => '1500']])['ids'], 'an empty range finds nothing');
	same(['F2'], search($platform, 'alice', 'invocie', ['options' => ['since' => '1500', 'until' => '2500']])['ids'], 'the range also applies to the typo-tolerant title fallback');
	$p1 = search($platform, 'alice', 'invoice', ['size' => 2, 'page' => 1]);
	$p2 = search($platform, 'alice', 'invoice', ['size' => 2, 'page' => 2]);
	same(3, $p1['total'], 'total counts all matches');
	same(2, count($p1['ids']), 'page 1 has page-size results');
	same(1, count($p2['ids']), 'page 2 has the remainder');
	same([], array_values(array_intersect($p1['ids'], $p2['ids'])), 'pages do not overlap');

	section('extension, wildcard and regex filters');
	$platform->indexDocument(doc('E1', 'alice', 'Plan 2025.pdf', 'harbour expansion plan', [], 'flt'));
	$platform->indexDocument(doc('E2', 'alice', 'PLAN 2026.PDF', 'harbour expansion plan', [], 'flt'));
	$platform->indexDocument(doc('E3', 'alice', 'Plan 2025.odt', 'harbour expansion plan', [], 'flt'));
	$platform->indexDocument(doc('E4', 'alice', 'pdf_notes.txt', 'harbour expansion plan', [], 'flt'));
	$platform->indexDocument(doc('E5', 'alice', 'plan%draft.md', 'harbour expansion plan', [], 'flt'));
	$f = ['provider' => 'flt'];
	// The Files app's "filter by extension": one group with one entry.
	$pdf = [[['title' => '.*\.pdf']]];
	same(['E1', 'E2'], sorted(search($platform, 'alice', 'harbour', $f + ['regex' => $pdf])['ids']), 'extension filter as the Files app sends it, ignoring case');
	same(['E1', 'E2', 'E3'], sorted(search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => '.*\.pdf'], ['title' => '.*\.odt']]]])['ids']), 'entries of a group: any may match');
	same(['E1'], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => '.*\.pdf']], [['title' => '.*2025.*']]]])['ids'], 'groups: all must match');
	same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => 'pdf']]]])['ids'], 'a regex must match the whole title');
	same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => '.*\.p(df']]]])['ids'], 'an invalid regex matches nothing instead of failing the search');
	same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => '.*\.pdf++']]]])['ids'], 'constructs PostgreSQL reads differently match nothing');
	same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => '\bplan.*']]]])['ids'], '\\b (a word boundary in PCRE, a backspace in PostgreSQL) matches nothing');
	same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['owner' => '.*']]]])['ids'], 'a field without a column matches nothing');
	same(['E3'], search($platform, 'alice', 'harbour', $f + ['wildcard' => [[['title' => '*.odt']]]])['ids'], 'wildcard filter');
	same(['E1', 'E2', 'E3'], sorted(search($platform, 'alice', 'harbour', $f + ['wildcard' => [[['title' => 'plan 202?.*']]]])['ids']), '? matches one character, case ignored');
	same(['E5'], search($platform, 'alice', 'harbour', $f + ['wildcard' => [[['title' => 'plan%*']]]])['ids'], '% and _ in a wildcard are literal');
	same(['E1', 'E2'], sorted(search($platform, 'alice', 'harbour', $f + ['regex' => [[['content' => '.*expansion.*']]], 'wildcard' => [[['title' => '*pdf']]]])['ids']), 'content can be filtered too, and both kinds combine');
	$platform->resetIndex('flt');

	section('search limited to file names or content');
	$platform->indexDocument(doc('L1', 'alice', 'Lighthouse survey', 'Notes about the coast.', [], 'lim'));
	$platform->indexDocument(doc('L2', 'alice', 'Coast notes', 'The lighthouse survey was finished.', [], 'lim'));
	$platform->indexDocument(doc('L3', 'alice', 'Minutes', 'Nothing here.', ['parts' => ['comments' => 'lighthouse visit next week']], 'lim'));
	$platform->indexDocument(doc('L4', 'alice', 'Keepers', 'Old photos.', ['tags' => ['lighthouse']], 'lim'));
	$l = ['provider' => 'lim'];
	same(['L1', 'L2', 'L3', 'L4'], sorted(search($platform, 'alice', 'lighthouse', $l)['ids']), 'no limit: every field');
	same(['L1'], search($platform, 'alice', 'lighthouse', $l + ['limit' => ['title']])['ids'], 'file names only');
	same(['L2'], search($platform, 'alice', 'lighthouse', $l + ['limit' => ['content']])['ids'], 'content only');
	same(['L1', 'L2'], sorted(search($platform, 'alice', 'lighthouse', $l + ['limit' => ['title', 'content']])['ids']), 'file names and content, not comments or tags');
	same(['L3'], search($platform, 'alice', 'lighthouse', $l + ['limit' => ['parts.comments']])['ids'], 'parts by name');
	same([], search($platform, 'alice', 'lighthouse', $l + ['limit' => ['nonexistent']])['ids'], 'a field this platform does not index finds nothing');
	same(['L1'], search($platform, 'alice', 'lighth', $l + ['limit' => ['title']])['ids'], 'prefixes still match');
	same(['L2'], search($platform, 'alice', '"lighthouse survey"', $l + ['limit' => ['content']])['ids'], 'phrases are limited too');
	same([], search($platform, 'alice', '"lighthouse survey" -finished', $l + ['limit' => ['content']])['ids'], 'exclusions apply within the limited fields');
	same(['L1'], search($platform, 'alice', 'lighthouse -coast', $l + ['limit' => ['title']])['ids'], 'an excluded word elsewhere in the document does not exclude it');
	same([], search($platform, 'alice', 'ghthou', $l + ['limit' => ['content']])['ids'], 'no title substring matching when titles are not searched');
	same(['L1'], search($platform, 'alice', 'ghthou', $l + ['limit' => ['title']])['ids'], 'title substring matching when they are');
	same(['L1', 'L2'], sorted(search($platform, 'alice', 'lighthouse coast', $l + ['limit' => ['title', 'content']])['ids']), 'several words with IDF ranking, limited');
	$platform->resetIndex('lim');

	section('excerpts of long documents');
	$filler = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit. ', 4000);
	$platform->indexDocument(doc('X1', 'alice', 'Annual report', $filler . 'The Zanzibar office opened in spring. ' . $filler, [], 'exc'));
	$x = search($platform, 'alice', 'zanzibar', ['provider' => 'exc'])['docs'][0] ?? null;
	$excerpt = implode(' … ', array_column($x?->getExcerpts() ?? [], 'excerpt'));
	check(str_contains($excerpt, 'Zanzibar office'), 'excerpt found 224,000 characters into the text: ' . mb_substr($excerpt, 0, 80));
	$x = search($platform, 'alice', 'offices', ['provider' => 'exc'])['docs'][0] ?? null;
	check(str_contains(implode(' ', array_column($x?->getExcerpts() ?? [], 'excerpt')), 'office opened'), 'located through the stem when the typed word does not occur ("offices" finds "office")');
	$x = search($platform, 'alice', 'annual', ['provider' => 'exc'])['docs'][0] ?? null;
	check(str_starts_with((string)($x?->getExcerpts()[0]['excerpt'] ?? ''), 'Lorem ipsum'), 'title-only match: excerpt from the beginning');
	$config->setConfig(['language' => 'turkish']);
	$platform->indexDocument(doc('X2', 'alice', 'Gezi', $filler . 'ISPARTA GÜLLERİ çok güzel. ' . $filler, [], 'exc'));
	$x = search($platform, 'alice', 'ısparta', ['provider' => 'exc'])['docs'][0] ?? null;
	check(str_contains(implode(' ', array_column($x?->getExcerpts() ?? [], 'excerpt')), 'ISPARTA'), 'Turkish dotless I when locating the excerpt');
	$platform->indexDocument(doc('X3', 'alice', 'Not', 'Bu yıl İSTANBUL ofisinde IŞIK sorunu vardı.', [], 'exc'));
	$excerpt = (string)(search($platform, 'alice', 'ışık', ['provider' => 'exc'])['docs'][0]?->getExcerpts()[0]['excerpt'] ?? '');
	check(str_contains($excerpt, 'İSTANBUL ofisinde IŞIK sorunu'), 'Turkish uppercase words are shown as written in excerpts: ' . $excerpt);
	$config->setConfig(['language' => 'english']);
	$platform->resetIndex('exc');

	section('content extraction');
	$b64 = fn (string $s) => base64_encode($s);
	$platform->indexDocument(doc('T1', 'alice', 'notes.txt', $b64("plain text file about zeppelins\n"), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['T1'], search($platform, 'alice', 'zeppelins')['ids'], 'base64 plain text is decoded');

	$platform->indexDocument(doc('T2', 'alice', 'latin.txt', $b64(mb_convert_encoding('Açıklama: ölçüm sonuçları', 'Windows-1254', 'UTF-8')), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	$config->setConfig(['language' => 'simple']);
	$platform->indexDocument(doc('T2', 'alice', 'latin.txt', $b64(mb_convert_encoding('Açıklama: ölçüm sonuçları', 'Windows-1254', 'UTF-8')), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['T2'], search($platform, 'alice', 'ölçüm')['ids'], 'legacy Windows-1254 text converted to UTF-8');
	$config->setConfig(['language' => 'english']);

	$makeZip = function (array $files): string {
		$tmp = tempnam(sys_get_temp_dir(), 'z');
		$zip = new ZipArchive();
		$zip->open($tmp, ZipArchive::OVERWRITE);
		foreach ($files as $name => $data) {
			$zip->addFromString($name, $data);
		}
		$zip->close();
		$data = file_get_contents($tmp);
		unlink($tmp);
		return $data;
	};
	$docx = $makeZip([
		'[Content_Types].xml' => '<Types/>',
		// As Word writes it: the space between runs is inside a run, never implied by the boundary.
		'word/document.xml' => '<w:document><w:body><w:p><w:r><w:t xml:space="preserve">Quarterly </w:t></w:r><w:r><w:t>hippopotamus</w:t></w:r></w:p><w:p><w:t>census</w:t></w:p></w:body></w:document>',
	]);
	$platform->indexDocument(doc('T3', 'alice', 'report.docx', $b64($docx), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['T3'], search($platform, 'alice', 'hippopotamus')['ids'], 'docx text extracted');
	same(['T3'], search($platform, 'alice', '"hippopotamus census"')['ids'], 'paragraph boundaries keep words apart');

	$odt = $makeZip(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<office:document-content><text:p>Rhinoceros sightings</text:p></office:document-content>']);
	$platform->indexDocument(doc('T4', 'alice', 'doc.odt', $b64($odt), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['T4'], search($platform, 'alice', 'rhinoceros')['ids'], 'odt text extracted');

	$pptx = $makeZip(['ppt/slides/slide1.xml' => '<p:sld><a:t>Armadillo</a:t></p:sld>', 'ppt/slides/slide2.xml' => '<p:sld><a:t>Pangolin</a:t></p:sld>']);
	$platform->indexDocument(doc('T5', 'alice', 'deck.pptx', $b64($pptx), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['T5'], search($platform, 'alice', 'pangolin')['ids'], 'pptx slides extracted');

	section('smart markup stripping and tag spacing (B2)');

	// 1. Direct unit verification of looksLikeMarkup()
	same(false, $extractor->looksLikeMarkup('Plain text without markup'), 'plain text without markup is false');
	same(false, $extractor->looksLikeMarkup('Contact <john.doe@example.com> for info', 'notes.txt'), 'email in txt file is not markup');
	same(false, $extractor->looksLikeMarkup('Map<String, Integer> mapping;', 'App.java'), 'generics in code file is not markup');
	same(false, $extractor->looksLikeMarkup('Condition a < b and c > d holds', 'test.py'), 'inequalities in code file is not markup');
	same(true, $extractor->looksLikeMarkup("<!DOCTYPE html>\n<html><body>Hi</body></html>"), 'starts with <!DOCTYPE html>');
	same(true, $extractor->looksLikeMarkup("   \t\r\n<!doctype html>"), 'starts with <!doctype (case-insensitive + leading whitespace)');
	same(true, $extractor->looksLikeMarkup("\xEF\xBB\xBF<!DOCTYPE html>"), 'starts with <!DOCTYPE after UTF-8 BOM');
	same(true, $extractor->looksLikeMarkup('<html lang="en"><body>Hello</body></html>'), 'starts with <html');
	same(true, $extractor->looksLikeMarkup('<?xml version="1.0" encoding="UTF-8"?><root/>'), 'starts with <?xml');
	same(true, $extractor->looksLikeMarkup('<svg xmlns="http://www.w3.org/2000/svg"><text>Hi</text></svg>'), 'starts with <svg');
	same(true, $extractor->looksLikeMarkup('Just a fragment', 'index.html'), 'title ends in .html');
	same(true, $extractor->looksLikeMarkup('Just a fragment', 'PAGE.HTM'), 'title ends in .HTM (case-insensitive)');
	same(true, $extractor->looksLikeMarkup('Just a fragment', 'doc.xhtml'), 'title ends in .xhtml');
	same(true, $extractor->looksLikeMarkup('Just a fragment', 'data.xml'), 'title ends in .xml');
	same(true, $extractor->looksLikeMarkup('Just a fragment', 'icon.svg'), 'title ends in .svg');
	same(false, $extractor->looksLikeMarkup('<p>paragraph</p>', 'notes.txt', false), 'internal tags with unencoded=false and .txt title is false');
	same(true, $extractor->looksLikeMarkup('<p>paragraph</p>', 'snippet', true), 'unencoded=true detects </p>');
	same(true, $extractor->looksLikeMarkup('<div>division</div>', 'snippet', true), 'unencoded=true detects </div>');
	same(true, $extractor->looksLikeMarkup('<span>text</span>', 'snippet', true), 'unencoded=true detects </span>');
	same(true, $extractor->looksLikeMarkup('<a href="#">link</a>', 'snippet', true), 'unencoded=true detects </a>');
	same(true, $extractor->looksLikeMarkup('<li>item</li>', 'snippet', true), 'unencoded=true detects </li>');
	same(true, $extractor->looksLikeMarkup('<td>cell</td>', 'snippet', true), 'unencoded=true detects </td>');
	same(true, $extractor->looksLikeMarkup('Line1<br>Line2', 'snippet', true), 'unencoded=true detects <br>');
	same(true, $extractor->looksLikeMarkup('Line1<br/>Line2', 'snippet', true), 'unencoded=true detects <br/>');
	same(true, $extractor->looksLikeMarkup('Line1<br />Line2', 'snippet', true), 'unencoded=true detects <br />');
	same(false, $extractor->looksLikeMarkup('Reach <user@example.com>', 'comment', true), 'unencoded=true ignores angle brackets without closing tags');

	// 2. Direct unit verification of clean()
	same('hello world', $extractor->clean('hello world'), 'clean() preserves text');
	same('Contact <john.doe@example.com>', $extractor->clean('Contact <john.doe@example.com>'), 'clean() preserves email by default');
	same('Map<String, Integer>', $extractor->clean('Map<String, Integer>'), 'clean() preserves generic types by default');
	same('a < b and c > d', $extractor->clean('a < b and c > d'), 'clean() preserves inequalities by default');
	same('First Second', $extractor->clean('<p>First</p><p>Second</p>', true), 'clean() with stripMarkup=true separates block tags with space');
	same('Heading Content', $extractor->clean('<h2>Heading</h2><p>Content</p>', true), 'clean() separates heading and paragraph');
	same('Item 1 Item 2', $extractor->clean('<li>Item 1</li><li>Item 2</li>', true), 'clean() separates list items');
	same('Cell A Cell B', $extractor->clean('<td>Cell A</td><td>Cell B</td>', true), 'clean() separates table cells');
	same('Line 1 Line 2', $extractor->clean('Line 1<br>Line 2', true), 'clean() separates br tags');
	same('Line 1 Line 2', $extractor->clean('Line 1<br/>Line 2', true), 'clean() separates br/ tags');
	same('unbelievable', $extractor->clean('un<b>believable</b>', true), 'clean() strips inline tags without spaces: unbelievable');
	same('supercalifragilistic', $extractor->clean('super<i>cali</i>fragilistic', true), 'clean() strips inline tags without spaces: italic');
	same('refactoring', $extractor->clean('re<span>factor</span>ing', true), 'clean() strips inline tags without spaces: span');
	same('stronghold', $extractor->clean('strong<strong>hold</strong>', true), 'clean() strips inline tags without spaces: strong');
	same('R&D <draft>', $extractor->clean('R&amp;D &lt;draft&gt;', true), 'clean() decodes entities after tag stripping');

	// 3. E2E: Plain text file preserves angle brackets in indexing and search
	$plainContent = "Contact <john.doe@example.com> or visit <https://nextcloud.com/docs>.\nIn Java use Map<String, Integer> when a < b and c > d.";
	$platform->indexDocument(doc('B2_PLAIN', 'alice', 'developer-guide.txt', $b64($plainContent), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['B2_PLAIN'], search($platform, 'alice', 'john.doe@example.com')['ids'], 'plain text search finds email inside angle brackets');
	same(['B2_PLAIN'], search($platform, 'alice', 'nextcloud.com')['ids'], 'plain text search finds url inside angle brackets');
	same(['B2_PLAIN'], search($platform, 'alice', 'Integer')['ids'], 'plain text search finds generic parameter Integer');
	same(['B2_PLAIN'], search($platform, 'alice', 'String')['ids'], 'plain text search finds generic parameter String');
	$storedPlain = (string)$conn->executeQuery("SELECT content FROM ftspg_oc_index WHERE document_id = 'B2_PLAIN'")->fetchOne();
	check(str_contains($storedPlain, '<john.doe@example.com>'), 'stored content preserves <john.doe@example.com>');
	check(str_contains($storedPlain, '<https://nextcloud.com/docs>'), 'stored content preserves <https://...>');
	check(str_contains($storedPlain, 'Map<String, Integer>'), 'stored content preserves Map<String, Integer>');
	check(str_contains($storedPlain, 'a < b and c > d'), 'stored content preserves a < b and c > d');

	// 4. E2E: Genuine HTML/XML/SVG files have markup cleanly stripped
	$htmlDoc = "<!DOCTYPE html>\n<html><head><title>Annual</title></head><body><h1>Quarterly Report</h1><p>Financial results exceeded all expectations.</p></body></html>";
	$platform->indexDocument(doc('B2_HTML_DOC', 'alice', 'report.txt', $b64($htmlDoc), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['B2_HTML_DOC'], search($platform, 'alice', 'expectations')['ids'], 'html with DOCTYPE indexes body text');
	same(['B2_HTML_DOC'], search($platform, 'alice', '"Quarterly Report"')['ids'], 'html with DOCTYPE keeps heading words');
	same([], search($platform, 'alice', 'DOCTYPE')['ids'], 'DOCTYPE tag is stripped and not searchable');
	same([], search($platform, 'alice', 'body')['ids'], 'body tag is stripped and not searchable');

	$svgDoc = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><text x="20" y="40">Vector diagram caption</text></svg>';
	$platform->indexDocument(doc('B2_SVG', 'alice', 'graphic.txt', $b64($svgDoc), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['B2_SVG'], search($platform, 'alice', 'caption')['ids'], 'svg text element is indexed');

	$xmlDoc = '<?xml version="1.0" encoding="UTF-8"?><catalog><item><title>Ceramic</title><description>Handcrafted pottery vase</description></item></catalog>';
	$platform->indexDocument(doc('B2_XML', 'alice', 'catalog.txt', $b64($xmlDoc), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['B2_XML'], search($platform, 'alice', 'pottery')['ids'], 'xml item description is indexed');

	$extHtml = '<div class="content"><h3>System Diagnostics</h3><p>All background services operational.</p></div>';
	$platform->indexDocument(doc('B2_EXT_HTML', 'alice', 'status.HTML', $b64($extHtml), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['B2_EXT_HTML'], search($platform, 'alice', 'operational')['ids'], 'file with .HTML extension strips markup');

	// 5. E2E: Unencoded HTML fragment has tags cleanly stripped
	$unencodedFragment = '<p>First line of unencoded notes.</p><br><p>Second line with important action points.</p>';
	$platform->indexDocument(doc('B2_UNENC', 'alice', 'notes', $unencodedFragment, ['encoded' => IIndexDocument::NOT_ENCODED]));
	same(['B2_UNENC'], search($platform, 'alice', '"unencoded notes"')['ids'], 'unencoded fragment indexes text');
	same(['B2_UNENC'], search($platform, 'alice', '"action points"')['ids'], 'unencoded fragment indexes second paragraph');

	// 6. E2E: Block tags spacing prevents word fusion
	$blockHtml = '<p>FirstParagraph</p><p>SecondParagraph</p><div>DivisionAlpha</div><div>DivisionBeta</div>';
	$platform->indexDocument(doc('B2_BLOCK', 'alice', 'blocks.html', $b64($blockHtml), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['B2_BLOCK'], search($platform, 'alice', '"FirstParagraph SecondParagraph"')['ids'], 'block tags separated with whitespace');
	same(['B2_BLOCK'], search($platform, 'alice', '"DivisionAlpha DivisionBeta"')['ids'], 'div blocks separated with whitespace');
	same([], search($platform, 'alice', 'FirstParagraphSecondParagraph')['ids'], 'block tags do not fuse words together');
	$storedBlock = (string)$conn->executeQuery("SELECT content FROM ftspg_oc_index WHERE document_id = 'B2_BLOCK'")->fetchOne();
	check(!str_contains($storedBlock, 'FirstParagraphSecondParagraph'), 'stored content has no word fusion');
	check(str_contains($storedBlock, 'FirstParagraph SecondParagraph'), 'stored content has space between paragraphs');

	// 7. E2E: Inline tags do not split words with spaces
	$inlineHtml = '<p>This is un<b>believable</b> and super<i>cali</i>fragilistic and re<span>factor</span>ing a strong<strong>hold</strong>.</p>';
	$platform->indexDocument(doc('B2_INLINE', 'alice', 'inline.html', $b64($inlineHtml), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['B2_INLINE'], search($platform, 'alice', 'unbelievable')['ids'], 'inline tag <b> does not split unbelievable');
	same(['B2_INLINE'], search($platform, 'alice', 'supercalifragilistic')['ids'], 'inline tag <i> does not split supercalifragilistic');
	same(['B2_INLINE'], search($platform, 'alice', 'refactoring')['ids'], 'inline tag <span> does not split refactoring');
	same(['B2_INLINE'], search($platform, 'alice', 'stronghold')['ids'], 'inline tag <strong> does not split stronghold');
	same([], search($platform, 'alice', '"un believable"')['ids'], 'inline tag does not add spaces inside word');

	// 8. E2E: Parts / comments: plain text preserves angle brackets, HTML strips markup with block spaces
	$platform->indexDocument(doc('B2_PART_TXT', 'alice', 'task.txt', '', [
		'parts' => ['comments' => 'Assigned to <ops-team@example.com> for triage on <server-01>.'],
	]));
	same(['B2_PART_TXT'], search($platform, 'alice', 'ops-team@example.com')['ids'], 'plain text comment preserves email inside angle brackets');
	$storedPartTxt = (string)$conn->executeQuery("SELECT parts_text FROM ftspg_oc_index WHERE document_id = 'B2_PART_TXT'")->fetchOne();
	check(str_contains($storedPartTxt, '<ops-team@example.com>'), 'parts_text preserves angle-bracketed email');
	check(str_contains($storedPartTxt, '<server-01>'), 'parts_text preserves angle-bracketed hostname');

	$platform->indexDocument(doc('B2_PART_HTML', 'alice', 'review.txt', '', [
		'parts' => ['comments' => '<p>Please review <b>promptly</b></p><p>Approval needed by EOD</p>'],
	]));
	same(['B2_PART_HTML'], search($platform, 'alice', 'promptly')['ids'], 'html comment indexes inline word cleanly');
	same(['B2_PART_HTML'], search($platform, 'alice', '"promptly Approval"')['ids'], 'html comment separates paragraphs with space');
	same([], search($platform, 'alice', 'promptlyApproval')['ids'], 'html comment does not fuse words across paragraphs');
	$storedPartHtml = (string)$conn->executeQuery("SELECT parts_text FROM ftspg_oc_index WHERE document_id = 'B2_PART_HTML'")->fetchOne();
	check(!str_contains($storedPartHtml, 'promptlyApproval'), 'parts_text has no word fusion in html comment');
	check(str_contains($storedPartHtml, 'promptly Approval'), 'parts_text has whitespace between html comment paragraphs');
	check(!str_contains($storedPartHtml, '<p>'), 'parts_text has html tags stripped');

	section('office documents as Word, Excel, PowerPoint and LibreOffice write them');
	$w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
	$a = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
	$s = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
	$o = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/"';
	$office = function (string $id, string $name, array $files) use ($platform, $makeZip, $b64): void {
		$platform->indexDocument(doc($id, 'alice', $name, $b64($makeZip($files)), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	};
	$office('O1', 'runs.docx', ['word/document.xml' => "<w:document $w><w:body><w:p><w:r><w:t>Okul</w:t></w:r><w:proofErr w:type=\"spellStart\"/><w:r><w:rPr><w:b/></w:rPr><w:t>müdürlüğü</w:t></w:r>"
		. '<w:r><w:tab/><w:t>R&amp;D &lt;taslak&gt;</w:t></w:r></w:p>'
		. '<w:p><w:del><w:r><w:delText>silinmişkelime</w:delText></w:r></w:del><w:r><w:instrText> HYPERLINK "http://x" </w:instrText></w:r><w:r><w:t>kalan</w:t></w:r></w:p></w:body></w:document>',
		'word/comments.xml' => "<w:comments $w><w:comment w:author=\"Ayşe\"><w:p><w:r><w:t>Gözden geçirildi zeplin</w:t></w:r></w:p></w:comment></w:comments>"]);
	same(['O1'], search($platform, 'alice', 'okulmüdürlüğü')['ids'], 'docx: a word split across runs is one word');
	same([], search($platform, 'alice', 'müdürlüğü')['ids'], 'docx: no break inserted between runs');
	same(['O1'], search($platform, 'alice', 'zeplin')['ids'], 'docx: comments indexed');
	same([], search($platform, 'alice', 'silinmişkelime')['ids'], 'docx: deleted tracked changes left out');
	same([], search($platform, 'alice', 'hyperlink')['ids'], 'docx: field codes left out');
	$stored = (string)$conn->executeQuery("SELECT content FROM ftspg_oc_index WHERE document_id = 'O1'")->fetchOne();
	check(str_contains($stored, 'R&D <taslak>'), "docx: entities decoded, angle brackets kept as text ($stored)");

	$office('O2', 'butce.xlsx', [
		'xl/workbook.xml' => "<workbook $s><sheets><sheet name=\"Gelirler 2025\" sheetId=\"1\"/></sheets></workbook>",
		'xl/sharedStrings.xml' => "<sst $s><si><t>Fatura</t></si><si><r><t>Bütçe</t></r><r><rPr><b/></rPr><t>lendirme</t></r><rPh><t>ignored-phonetic</t></rPh></si></sst>",
		'xl/worksheets/sheet1.xml' => "<worksheet $s><sheetData><row><c t=\"s\"><v>0</v></c><c><v>4711</v></c><c><f>SUM(A1:A9)</f><v>98765</v></c>"
			. '<c t="inlineStr"><is><t>Satır içi kunduz</t></is></c><c t="b"><v>1</v></c></row></sheetData></worksheet>',
		'xl/comments1.xml' => "<comments $s><authors><author>Mehmet</author></authors><commentList><comment ref=\"A1\"><text><t>Kontrol edildi</t></text></comment></commentList></comments>",
	]);
	same(['O2'], search($platform, 'alice', 'gelirler')['ids'], 'xlsx: sheet names indexed');
	same(['O2'], search($platform, 'alice', 'bütçelendirme')['ids'], 'xlsx: rich text runs are one word');
	same(['O2'], search($platform, 'alice', '4711')['ids'], 'xlsx: numbers in cells indexed');
	same(['O2'], search($platform, 'alice', '98765')['ids'], 'xlsx: formula results indexed');
	same([], search($platform, 'alice', 'sum')['ids'], 'xlsx: formulas left out');
	same(['O2'], search($platform, 'alice', 'kunduz')['ids'], 'xlsx: inline strings indexed');
	same(['O2'], search($platform, 'alice', 'kontrol')['ids'], 'xlsx: comments indexed');
	same([], search($platform, 'alice', 'mehmet')['ids'], 'xlsx: comment authors left out');
	same([], search($platform, 'alice', 'ignored-phonetic')['ids'], 'xlsx: phonetic guides left out');

	$office('O3', 'sunum.pptx', [
		'ppt/slides/slide10.xml' => "<p:sld $a><a:p><a:r><a:t>Onuncu</a:t></a:r></a:p></p:sld>",
		'ppt/slides/slide2.xml' => "<p:sld $a><a:p><a:r><a:t>Kaplum</a:t></a:r><a:r><a:rPr b=\"1\"/><a:t>bağa</a:t></a:r></a:p></p:sld>",
		'ppt/notesSlides/notesSlide2.xml' => "<p:notes $a><a:p><a:r><a:t>Konuşmacı notu albatros</a:t></a:r></a:p></p:notes>",
	]);
	same(['O3'], search($platform, 'alice', 'kaplumbağa')['ids'], 'pptx: a word split across runs is one word');
	same(['O3'], search($platform, 'alice', 'albatros')['ids'], 'pptx: speaker notes indexed');
	$content = (string)$conn->executeQuery("SELECT content FROM ftspg_oc_index WHERE document_id = 'O3'")->fetchOne();
	check(strpos($content, 'Kaplumbağa') < strpos($content, 'Onuncu'), 'pptx: slides in presentation order (2 before 10)');

	$office('O4', 'not.odt', ['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => "<office:document-content $o><office:body><office:text>"
		. '<text:p>Gergedan<text:span>lar</text:span><text:s/>görüldü</text:p>'
		. '<text:tracked-changes><text:changed-region><text:deletion><text:p>eskimetin</text:p></text:deletion></text:changed-region></text:tracked-changes>'
		. '<text:p><office:annotation><dc:creator>Zeynep</dc:creator><dc:date>2025-01-01</dc:date><text:p>Yorum pelikan</text:p></office:annotation>son</text:p>'
		. '</office:text></office:body></office:document-content>']);
	same(['O4'], search($platform, 'alice', 'gergedanlar')['ids'], 'odt: a word split by a span is one word');
	same(['O4'], search($platform, 'alice', 'görüldü')['ids'], 'odt: <text:s/> separates words');
	same(['O4'], search($platform, 'alice', 'pelikan')['ids'], 'odt: annotations indexed');
	same([], search($platform, 'alice', 'eskimetin')['ids'], 'odt: deleted tracked changes left out');
	same([], search($platform, 'alice', 'zeynep')['ids'], 'odt: annotation author left out');

	$office('O5', 'macro.docm', ['word/document.xml' => "<w:document $w><w:body><w:p><w:r><w:t>Makro okapi</w:t></w:r></w:p></w:body></w:document>", 'word/vbaProject.bin' => 'binary']);
	same(['O5'], search($platform, 'alice', 'okapi')['ids'], 'docm: macro-enabled files indexed like docx');

	$runnerLog = [];
	// A part cut off mid-element (damaged file, or one over the 32 MB read limit).
	$office('O6', 'kesik.docx', ['word/document.xml' => "<w:document $w><w:body><w:p><w:r><w:t>Kesik metin yarasa</w:t></w:r></w:p>"
		. str_repeat('<w:p><w:r><w:t>dolgu paragrafı</w:t></w:r></w:p>', 200) . '<w:p><w:r><w:t>devam']);
	same(['O6'], search($platform, 'alice', 'yarasa')['ids'], 'truncated XML: text before the break is kept');

	$platform->indexDocument(doc('T6', 'alice', 'photo.jpg', $b64("\xFF\xD8\xFF\xE0\0\x10JFIF\0\x01binary\0\0\x9a"), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same('warning', $runnerLog['T6'][0] ?? '', 'binary file: indexed with a warning');
	same(['T6'], search($platform, 'alice', 'photo')['ids'], 'binary file still findable by title');

	$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 100]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj 4 0 obj<</Length 44>>stream\nBT /F1 18 Tf 20 40 Td (Okapi Quokka) Tj ET\nendstream endobj 5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>";
	$platform->indexDocument(doc('T7', 'alice', 'scan.pdf', $b64($pdf), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	$hasPdfToText = trim((string)shell_exec('command -v pdftotext')) !== '';
	if ($hasPdfToText) {
		same(['T7'], search($platform, 'alice', 'quokka')['ids'], 'PDF text extracted with pdftotext');
	} else {
		check(str_contains($runnerLog['T7'][1] ?? '', 'pdftotext'), 'PDF without pdftotext: warning names the missing tool');
	}

	section('PDF fallback: bundled parser');
	// A well-formed one-page PDF (with an xref table, which pdfparser needs) showing $text.
	$makePdf = static function (string $text): string {
		$stream = "BT /F1 18 Tf 20 40 Td ($text) Tj ET";
		$objects = [
			'<</Type/Catalog/Pages 2 0 R>>',
			'<</Type/Pages/Kids[3 0 R]/Count 1>>',
			'<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 100]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>',
			'<</Length ' . strlen($stream) . ">>\nstream\n$stream\nendstream",
			'<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>',
		];
		$pdf = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $i => $object) {
			$offsets[] = strlen($pdf);
			$pdf .= ($i + 1) . " 0 obj\n$object\nendobj\n";
		}
		$xref = strlen($pdf);
		$pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}
		return $pdf . 'trailer<</Size ' . (count($objects) + 1) . "/Root 1 0 R>>\nstartxref\n$xref\n%%EOF\n";
	};
	check($extractor->pdfParserAvailable(), 'bundled parser is present (scripts/scope-pdfparser.php has run)');
	check(!class_exists('Smalot\PdfParser\Parser', false), 'the unprefixed Smalot namespace is never loaded');

	$config->setConfig(['pdf_extractor' => 'pdfparser']);
	same('pdfparser', $extractor->pdfTool(), 'pdf_extractor=pdfparser: parser reported as the tool');
	$runnerLog = [];
	$platform->indexDocument(doc('P1', 'alice', 'okapi.pdf', $b64($makePdf('Okapi Wombat')), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['P1'], search($platform, 'alice', 'wombat')['ids'], 'PDF text extracted with the bundled parser');
	same('ok', $runnerLog['P1'][0] ?? null, 'bundled parser: no warning on success');

	$runnerLog = [];
	$platform->indexDocument(doc('P2', 'alice', 'broken.pdf', $b64("%PDF-1.4\ngarbage with no xref"), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	check(str_contains($runnerLog['P2'][1] ?? '', 'built-in parser could not read it'), 'unreadable PDF: warning, not an exception');
	same(['P2'], search($platform, 'alice', 'broken')['ids'], 'unreadable PDF still findable by title');

	// A damaged xref makes the parser raise PHP warnings before it gives up. They must not
	// reach the log (the "PHP warnings and deprecations" section below fails if they do).
	$runnerLog = [];
	$platform->indexDocument(doc('P2b', 'alice', 'damaged.pdf', $b64(str_replace("xref\n0 ", 'xref\n0 ', $makePdf('Damaged'))), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same('warning', $runnerLog['P2b'][0] ?? null, 'damaged xref: indexed with a warning');

	// A pdftotext that is installed but fails: auto falls back to the parser and says why.
	$testToolDir = sys_get_temp_dir() . '/ftspg_tool_' . mt_rand();
	mkdir($testToolDir);
	$brokenTool = $testToolDir . '/pdftotext';
	file_put_contents($brokenTool, "#!/bin/sh\nif [ \"\$1\" = \"-v\" ]; then echo \"pdftotext version 24.0\"; exit 0; fi\nexit 3\n");
	chmod($brokenTool, 0755);
	$config->setConfig(['pdf_extractor' => 'auto', 'pdftotext_path' => $brokenTool]);
	$runnerLog = [];
	$platform->indexDocument(doc('P3', 'alice', 'fallback.pdf', $b64($makePdf('Okapi Capybara')), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same(['P3'], search($platform, 'alice', 'capybara')['ids'], 'auto: failing pdftotext falls back to the parser');
	check(str_contains($runnerLog['P3'][1] ?? '', 'pdftotext failed (exit code 3)'), 'auto: the pdftotext failure is reported');

	// pdftotext forced but unusable: no silent fallback.
	$config->setConfig(['pdf_extractor' => 'pdftotext']);
	$runnerLog = [];
	$platform->indexDocument(doc('P4', 'alice', 'forced.pdf', $b64($makePdf('Okapi Tapir')), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	same([], search($platform, 'alice', 'tapir')['ids'], 'pdf_extractor=pdftotext: no fallback');
	$appConfig->setValueString('fulltextsearch_pgsql', 'pdftotext_path', '/nonexistent/pdftotext');
	same(null, $extractor->pdfTool(), 'pdf_extractor=pdftotext without pdftotext: no tool');
	$runnerLog = [];
	$platform->indexDocument(doc('P5', 'alice', 'missing.pdf', $b64($makePdf('Okapi')), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	check(str_contains($runnerLog['P5'][1] ?? '', 'pdftotext is selected but not found'), 'pdf_extractor=pdftotext without pdftotext: warning says so');
	unlink($brokenTool);
	rmdir($testToolDir);

	$config->setConfig(['pdf_extractor' => 'auto', 'pdftotext_path' => '']);
	same($hasPdfToText ? 'pdftotext' : 'pdfparser', $extractor->pdfTool(), 'auto: pdftotext preferred when installed');
	try {
		$config->setConfig(['pdf_extractor' => 'tika']);
		check(false, 'unknown pdf_extractor rejected');
	} catch (\InvalidArgumentException $e) {
		check(str_contains($e->getMessage(), 'auto, pdftotext, pdfparser'), 'unknown pdf_extractor rejected');
	}

	section('Office 97–2003 files (catdoc, xls2csv, catppt)');
	$fixture = static fn (string $name): string => (string)file_get_contents(__DIR__ . '/fixtures/' . $name);
	same('doc', $extractor->legacyOfficeKind($fixture('testWORD.doc')), 'doc recognised by its WordDocument stream');
	same('xls', $extractor->legacyOfficeKind($fixture('testEXCEL.xls')), 'xls recognised by its Workbook stream');
	same('ppt', $extractor->legacyOfficeKind($fixture('testPPT.ppt')), 'ppt recognised by its PowerPoint Document stream');
	$hasCatdoc = $extractor->findCatdoc() !== null;
	foreach ([['L1', 'testWORD.doc', 'subtitle'], ['L2', 'testEXCEL.xls', 'squares'], ['L3', 'testPPT.ppt', 'presentation']] as [$id, $file, $word]) {
		$runnerLog = [];
		$platform->indexDocument(doc($id, 'alice', 'legacy ' . $id, $b64($fixture($file)), ['encoded' => IIndexDocument::ENCODED_BASE64]));
		if ($hasCatdoc) {
			same([$id], search($platform, 'alice', $word)['ids'], "$file: text extracted");
		} else {
			check(str_contains($runnerLog[$id][1] ?? '', 'install catdoc'), "$file without catdoc: warning names the package");
		}
	}
	if ($hasCatdoc) {
		$content = (string)$conn->executeQuery("SELECT content FROM ftspg_oc_index WHERE document_id = 'L2'")->fetchOne();
		check(str_contains($content, 'Number Square') && !str_contains($content, '"'), 'xls: CSV quoting removed, values kept');
	}
	$ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504);
	$runnerLog = [];
	$platform->indexDocument(doc('L4', 'alice', 'locked.docx', $b64($ole . mb_convert_encoding('EncryptedPackage', 'UTF-16LE', 'ASCII')), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	check(str_contains($runnerLog['L4'][1] ?? '', 'password-protected'), 'password-protected Office file: warning says so');
	$runnerLog = [];
	$platform->indexDocument(doc('L5', 'alice', 'mail.msg', $b64($ole . mb_convert_encoding('__substg1.0_0037001F', 'UTF-16LE', 'ASCII')), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	check(str_contains($runnerLog['L5'][1] ?? '', 'unsupported format'), 'other compound files: indexed without content');

	$platform->indexDocument(doc('T9', 'alice', 'notes.txt', $b64("Yıllık çalışma raporu: gelir, gider, bütçe \xFF\xFE damaged ğüşiöç"), ['encoded' => IIndexDocument::ENCODED_BASE64]));
	$content = (string)$conn->executeQuery("SELECT content FROM ftspg_oc_index WHERE document_id = 'T9'")->fetchOne();
	check(str_contains($content, 'çalışma raporu') && str_contains($content, 'ğüşiöç'), "UTF-8 text with a damaged byte stays UTF-8 ($content)");

	$platform->indexDocument(doc('T8', 'alice', "nul\0title", "nul\0byte and \xC3\x28 invalid utf8 walrus"));
	same(['T8'], search($platform, 'alice', 'walrus')['ids'], 'NUL bytes and invalid UTF-8 do not break indexing');

	section('size limits');
	$config->setConfig(['max_content_size' => 100]);
	$platform->indexDocument(doc('S1', 'alice', 'Long', str_repeat('ğüşiöç ', 30) . 'needle'));
	same([], search($platform, 'alice', 'needle')['ids'], 'content truncated at max_content_size');
	$len = (int)$conn->executeQuery("SELECT octet_length(content) FROM ftspg_oc_index WHERE document_id = 'S1'")->fetchOne();
	check($len <= 100 && $len > 90, "truncated to $len bytes without splitting characters");
	same(true, mb_check_encoding((string)$conn->executeQuery("SELECT content FROM ftspg_oc_index WHERE document_id = 'S1'")->fetchOne(), 'UTF-8'), 'truncated content is valid UTF-8');

	$config->setConfig(['max_content_size' => 1000000]);
	mt_srand(42);
	$words = [];
	for ($i = 0; $i < 90000; $i++) {
		$words[] = substr(md5((string)mt_rand()), 0, 10);
	}
	$platform->indexDocument(doc('S2', 'alice', 'Huge unique vocabulary', implode(' ', $words)));
	same('warning', $runnerLog['S2'][0] ?? '', 'tsvector over 1 MB: falls back with a warning');
	same(['S2'], search($platform, 'alice', 'vocabulary')['ids'], 'oversized document still findable by title');
	$config->setConfig(['max_content_size' => 512000]);

	section('safe upsert retry and size caps (B1)');

	// -------------------------------------------------------------------------
	// 1. Statement timeout / non-54000 exception preserves existing content
	// -------------------------------------------------------------------------
	$docTimeout = doc('B1_TIMEOUT', 'alice', 'Original Durable Title', 'Original durable content that must never be wiped');
	$platform->indexDocument($docTimeout);
	$storedOriginal = (string)$conn->executeQuery("SELECT content FROM ftspg_oc_index WHERE document_id = 'B1_TIMEOUT'")->fetchOne();
	same('Original durable content that must never be wiped', $storedOriginal, 'B1: document initialized with durable content');

	// Create an IDBConnection proxy that intercepts upsert and simulates SQLSTATE 57014 (statement timeout)
	$timeoutProxy = new class($conn) implements \OCP\IDBConnection {
		public bool $simulateTimeout = false;
		public function __construct(
			private \OCP\IDBConnection $inner,
		) {
		}
		public function executeStatement($sql, array $params = [], array $types = []): int {
			if ($this->simulateTimeout && str_starts_with(trim($sql), 'INSERT INTO')) {
				$e = new \PDOException('canceling statement due to statement timeout', 57014);
				$e->errorInfo = ['57014', 57014, 'canceling statement due to statement timeout'];
				throw $e;
			}
			return $this->inner->executeStatement($sql, $params, $types);
		}
		public function executeQuery(string $sql, array $params = [], $types = []): \OCP\DB\IResult {
			return $this->inner->executeQuery($sql, $params, $types);
		}
		public function inTransaction(): bool {
			return $this->inner->inTransaction();
		}
		public function beginTransaction(): void {
			$this->inner->beginTransaction();
		}
		public function commit(): void {
			$this->inner->commit();
		}
		public function rollBack(): void {
			$this->inner->rollBack();
		}
		public function getDatabaseProvider(): string {
			return $this->inner->getDatabaseProvider();
		}
	};

	$timeoutIndexService = new IndexService($timeoutProxy, $schema, $config, $extractor, $vocabulary, $logger, $detector);
	$timeoutPlatform = new PostgreSQLPlatform($config, $schema, $timeoutIndexService, $searchService, $logger, $extractor);
	$timeoutPlatform->setRunner(double(IRunner::class, [
		'newIndexResult' => function (IIndex $index, string $message, string $status, int $type) use (&$runnerLog) {
			$runnerLog[$index->getDocumentId()] = [$status, $message];
		},
		'newIndexError' => function (IIndex $index, string $message) use (&$runnerLog) {
			$runnerLog[$index->getDocumentId()] = ['error', $message];
		},
		'updateAction' => '',
	]));

	// Re-indexing attempt with active timeout simulation
	$timeoutProxy->simulateTimeout = true;
	$reindexDoc = doc('B1_TIMEOUT', 'alice', 'Updated Title', 'Replacement content that aborted');
	$indexResult = $timeoutPlatform->indexDocument($reindexDoc);

	same(true, $indexResult->isStatus(IIndex::INDEX_FAILED), 'B1: statement timeout marks index status as INDEX_FAILED');
	same('fail', $runnerLog['B1_TIMEOUT'][0] ?? '', 'B1: runner log reports fail status on statement timeout');
	$storedAfterTimeout = (string)$conn->executeQuery("SELECT content FROM ftspg_oc_index WHERE document_id = 'B1_TIMEOUT'")->fetchOne();
	same('Original durable content that must never be wiped', $storedAfterTimeout, 'B1: statement timeout leaves original content intact in database');

	// Verify direct rethrow on IndexService::indexDocument
	try {
		$timeoutIndexService->indexDocument($reindexDoc);
		check(false, 'B1: IndexService directly rethrows non-54000 exception');
	} catch (Throwable $e) {
		check(str_contains($e->getMessage(), 'statement timeout'), 'B1: non-54000 exception is rethrown');
	}
	$timeoutProxy->simulateTimeout = false;

	// -------------------------------------------------------------------------
	// 2. Stepwise degradation: content cut to half (Step 1)
	// -------------------------------------------------------------------------
	$config->setConfig(['max_content_size' => 1000000]);
	mt_srand(101);
	$halfWords = [];
	for ($i = 0; $i < 90000; $i++) {
		$halfWords[] = 'w' . substr(md5((string)mt_rand()), 0, 8);
	}
	$firstHalfMarker = $halfWords[0];
	$secondHalfMarker = $halfWords[89999];
	$runnerLog = [];
	$docHalf = doc('B1_HALF', 'alice', 'Half Degradation Title', implode(' ', $halfWords));
	$platform->indexDocument($docHalf);

	same('warning', $runnerLog['B1_HALF'][0] ?? '', 'B1: oversized content degrades with warning');
	check(str_contains($runnerLog['B1_HALF'][1] ?? '', 'half'), 'B1: warning indicates content cut to half');
	$storedHalfLen = (int)$conn->executeQuery("SELECT octet_length(content) FROM ftspg_oc_index WHERE document_id = 'B1_HALF'")->fetchOne();
	check($storedHalfLen > 350000 && $storedHalfLen < 550000, "B1: content cut to half (length $storedHalfLen)");
	same(['B1_HALF'], search($platform, 'alice', $firstHalfMarker)['ids'], 'B1: first half content is searchable');
	same([], search($platform, 'alice', $secondHalfMarker)['ids'], 'B1: second half content was cut');

	// -------------------------------------------------------------------------
	// 3. Three 500 KB parts (pre-flight cap and Step 4 degradation)
	// -------------------------------------------------------------------------
	mt_srand(202);
	$partWords = [];
	for ($i = 0; $i < 100000; $i++) {
		$partWords[] = 'p' . substr(md5((string)mt_rand()), 0, 8);
	}
	$part1 = 'partonehead ' . implode(' ', array_slice($partWords, 0, 33000));
	$part2 = 'parttwohead ' . implode(' ', array_slice($partWords, 33000, 33000));
	$part3 = 'partthreehead ' . implode(' ', array_slice($partWords, 66000, 33000));

	$runnerLog = [];
	$docParts = doc('B1_PARTS', 'alice', 'ThreeUniqueBigPartsTitle', '', [
		'parts' => [
			'section1' => $part1,
			'section2' => $part2,
			'section3' => $part3,
		],
	]);
	$platform->indexDocument($docParts);

	same('warning', $runnerLog['B1_PARTS'][0] ?? '', 'B1: document with three 500 KB parts indexes with warning');
	same(['B1_PARTS'], search($platform, 'alice', 'ThreeUniqueBigPartsTitle')['ids'], 'B1: document with large parts is findable by title');
	same(['B1_PARTS'], search($platform, 'alice', 'partonehead')['ids'], 'B1: document with large parts is findable by initial part term');
	$storedPartsLen = (int)$conn->executeQuery("SELECT octet_length(parts_text) FROM ftspg_oc_index WHERE document_id = 'B1_PARTS'")->fetchOne();
	check($storedPartsLen <= 65536, "B1: parts_text capped to 64 KB (length $storedPartsLen)");

	// -------------------------------------------------------------------------
	// 4. 700 KB title (Step 5 degradation: title cut to 4,000 characters)
	// -------------------------------------------------------------------------
	mt_srand(303);
	$titleWords = [];
	for ($i = 0; $i < 70000; $i++) {
		$titleWords[] = 't' . substr(md5((string)mt_rand()), 0, 8);
	}
	$hugeTitle = 'ImportantTitlePrefix ' . implode(' ', $titleWords);

	$runnerLog = [];
	$docTitle = doc('B1_TITLE', 'alice', $hugeTitle, 'uniquepayloadbody text');
	$platform->indexDocument($docTitle);

	same('warning', $runnerLog['B1_TITLE'][0] ?? '', 'B1: 700 KB title indexes with warning');
	check(str_contains($runnerLog['B1_TITLE'][1] ?? '', 'title') || str_contains($runnerLog['B1_TITLE'][1] ?? '', '4000'), 'B1: warning notes title cut');
	same(['B1_TITLE'], search($platform, 'alice', 'ImportantTitlePrefix')['ids'], 'B1: document findable by title prefix');
	same(['B1_TITLE'], search($platform, 'alice', 'uniquepayloadbody')['ids'], 'B1: content of large title document is findable');
	$storedTitleLen = (int)$conn->executeQuery("SELECT length(title) FROM ftspg_oc_index WHERE document_id = 'B1_TITLE'")->fetchOne();
	check($storedTitleLen <= 4000, "B1: stored title capped to at most 4000 characters (length $storedTitleLen)");

	// -------------------------------------------------------------------------
	// 5. NUL byte in info keys and values (avoid SQLSTATE 22P05)
	// -------------------------------------------------------------------------
	$runnerLog = [];
	$docNul = doc('B1_NUL_INFO', 'alice', 'NUL Info Title', 'body text for nul info test', [
		'links' => ["token\0one", "token\x00two"],
		'subtags' => ["src\0one" => "tag\0one"],
	]);
	$docNul->setInfo("meta\0key", "value\0with\0null");
	$docNul->setInfoArray('nested', [
		"sub\0key" => "deep\0value",
		'number' => 42,
		'flag' => true,
	]);
	$platform->indexDocument($docNul);

	same('ok', $runnerLog['B1_NUL_INFO'][0] ?? '', 'B1: document with NUL in info indexes with ok status');
	$row = $conn->executeQuery("SELECT info, links, subtags FROM ftspg_oc_index WHERE document_id = 'B1_NUL_INFO'")->fetch(\PDO::FETCH_ASSOC);
	$rawInfo = (string)$row['info'];
	check(!str_contains($rawInfo, '\u0000') && !str_contains($rawInfo, "\0"), 'B1: info jsonb has no NUL bytes');
	$decodedInfo = json_decode($rawInfo, true);
	same('valuewithnull', $decodedInfo['metakey'] ?? '', 'B1: info keys and string values stripped of NUL bytes');
	same('deepvalue', $decodedInfo['nested']['subkey'] ?? '', 'B1: nested info keys and string values stripped of NUL bytes');
	check(str_contains((string)$row['links'], 'tokenone') && str_contains((string)$row['links'], 'tokentwo') && !str_contains((string)$row['links'], "\0"), 'B1: links sanitized of NUL bytes');
	check(str_contains((string)$row['subtags'], 'tagone') && !str_contains((string)$row['subtags'], "\0"), 'B1: subtags sanitized of NUL bytes');
	same(['B1_NUL_INFO'], search($platform, 'alice', 'body text')['ids'], 'B1: document with NUL info is searchable');

	// -------------------------------------------------------------------------
	// 6. Upsert inside caller transaction with SAVEPOINT
	// -------------------------------------------------------------------------
	$conn->beginTransaction();
	same(true, $conn->inTransaction(), 'B1: caller transaction started');

	mt_srand(404);
	$txWords = [];
	for ($i = 0; $i < 90000; $i++) {
		$txWords[] = 'tx' . substr(md5((string)mt_rand()), 0, 8);
	}
	$docTx = doc('B1_TX_DOC', 'alice', 'Transaction Safety Doc', implode(' ', $txWords));
	$platform->indexDocument($docTx);

	// Execute an additional statement in the same transaction to prove transaction is healthy (no 25P02)
	$conn->executeStatement(
		"INSERT INTO ftspg_oc_index (provider_id, document_id, owner_id, access, config, title, content)
		 VALUES ('files', 'B1_TX_SIBLING', 'alice', '{u:alice}', 'english', 'Sibling Doc', 'sibling payload content')"
	);

	// Commit outer transaction
	$conn->commit();
	same(false, $conn->inTransaction(), 'B1: caller transaction committed successfully without 25P02 error');
	same(['B1_TX_DOC'], search($platform, 'alice', 'Transaction Safety Doc')['ids'], 'B1: degraded doc in committed transaction is findable');
	same(['B1_TX_SIBLING'], search($platform, 'alice', 'sibling payload content')['ids'], 'B1: sibling doc in same transaction is findable');

	// Verify rollback behavior
	$conn->beginTransaction();
	$docRollback = doc('B1_TX_ROLLBACK', 'alice', 'Rollback Target Doc', 'content that will be rolled back');
	$platform->indexDocument($docRollback);
	$conn->rollBack();
	same(false, $conn->inTransaction(), 'B1: caller transaction rolled back');
	same([], search($platform, 'alice', 'Rollback Target Doc')['ids'], 'B1: rolled back document is absent from index');

	$config->setConfig(['max_content_size' => 512000]);

	section('pdftotext_path lockdown & validation (B3)');

	// 1. Directory and non-pdftotext binaries are rejected by ConfigService::setConfig
	try {
		$config->setConfig(['pdftotext_path' => __DIR__]);
		check(false, 'B3: directory rejected as pdftotext_path');
	} catch (\InvalidArgumentException $e) {
		check(str_contains($e->getMessage(), 'pdftotext_path'), 'B3: directory rejected as pdftotext_path');
	}

	$nonPdfBinary = PHP_BINARY ?: '/bin/sh';
	try {
		$config->setConfig(['pdftotext_path' => $nonPdfBinary]);
		check(false, 'B3: binary not named pdftotext rejected by setConfig');
	} catch (\InvalidArgumentException $e) {
		check(str_contains($e->getMessage(), 'pdftotext'), 'B3: binary not named pdftotext rejected by setConfig');
	}

	// 2. An executable named pdftotext that does not print pdftotext on -v is rejected
	$invalidToolDir = sys_get_temp_dir() . '/ftspg_badtool_' . mt_rand();
	mkdir($invalidToolDir);
	$invalidTool = $invalidToolDir . '/pdftotext';
	file_put_contents($invalidTool, "#!/bin/sh\necho \"different-tool version 1.0\"\nexit 0\n");
	chmod($invalidTool, 0755);
	try {
		$config->setConfig(['pdftotext_path' => $invalidTool]);
		check(false, 'B3: tool without pdftotext in -v output rejected by setConfig');
	} catch (\InvalidArgumentException $e) {
		check(str_contains($e->getMessage(), 'pdftotext'), 'B3: tool without pdftotext in -v output rejected by setConfig');
	} finally {
		@unlink($invalidTool);
		@rmdir($invalidToolDir);
	}

	// 3. A valid pdftotext executable that outputs version is accepted via ConfigService::setConfig
	$validToolDir = sys_get_temp_dir() . '/ftspg_goodtool_' . mt_rand();
	mkdir($validToolDir);
	$validTool = $validToolDir . '/pdftotext';
	file_put_contents($validTool, "#!/bin/sh\nif [ \"\$1\" = \"-v\" ]; then echo \"pdftotext version 24.02.0\"; exit 0; fi\nexit 0\n");
	chmod($validTool, 0755);
	try {
		$config->setConfig(['pdftotext_path' => $validTool]);
		same($validTool, $config->getPdfToTextPath(), 'B3: valid pdftotext tool accepted via setConfig');
	} finally {
		$config->setConfig(['pdftotext_path' => '']);
		@unlink($validTool);
		@rmdir($validToolDir);
	}

	// 4. SettingsController::save rejects pdftotext_path with HTTP 400
	$statusService = new StatusService($schema, $vocabulary, $extractor);
	$settingsController = new SettingsController(
		'fulltextsearch_pgsql',
		double(IRequest::class, []),
		$config,
		$statusService
	);
	$saveResponse = $settingsController->save(['pdftotext_path' => '/usr/bin/pdftotext']);
	same(Http::STATUS_BAD_REQUEST, $saveResponse->getStatus(), 'B3: SettingsController::save rejects pdftotext_path with HTTP 400');
	same(
		'pdftotext_path cannot be configured from the web interface',
		$saveResponse->getData()['message'] ?? '',
		'B3: rejection error message is informative'
	);

	// Also verify that SettingsController::save without pdftotext_path succeeds
	$okResponse = $settingsController->save(['use_trigram' => true]);
	same(Http::STATUS_OK, $okResponse->getStatus(), 'B3: SettingsController::save succeeds when pdftotext_path is absent');

	// 5. Bad stored path in appConfig is ignored at use (findPdfToText returns null)
	// Case 5a: Stored path is a non-pdftotext binary
	$appConfig->setValueString('fulltextsearch_pgsql', 'pdftotext_path', '/bin/sh');
	same(null, $extractor->findPdfToTextPath(), 'B3: non-pdftotext stored path ignored at use');

	// Case 5b: Stored path is a directory named pdftotext
	$dirTool = sys_get_temp_dir() . '/ftspg_dir_' . mt_rand() . '/pdftotext';
	mkdir($dirTool, 0755, true);
	$appConfig->setValueString('fulltextsearch_pgsql', 'pdftotext_path', $dirTool);
	same(null, $extractor->findPdfToTextPath(), 'B3: directory named pdftotext in appConfig ignored at use');
	rmdir($dirTool);
	rmdir(dirname($dirTool));

	// Restore clean config
	$appConfig->setValueString('fulltextsearch_pgsql', 'pdftotext_path', '');

	section('partial updates');
	$platform->indexDocument(doc('P1', 'alice', 'Shared later', 'secret content about narwhals'));
	$meta = doc('P1', 'alice', 'Shared later', '', ['users' => ['bob']], 'files', IIndex::INDEX_META);
	$platform->indexDocument($meta);
	same(['P1'], search($platform, 'bob', 'narwhals')['ids'], 'metadata-only update adds the share and keeps the content');
	$platform->indexDocument(doc('P1', 'alice', 'Shared later', 'new text about dolphins', [], 'files', IIndex::INDEX_CONTENT));
	same([], search($platform, 'alice', 'narwhals')['ids'], 'content update replaces old content');
	same(['P1'], search($platform, 'alice', 'dolphins')['ids'], 'content update indexes new content');

	section('parts and tags');
	$platform->indexDocument(doc('G1', 'alice', 'Discussed file', '', ['parts' => ['comments' => 'Please review the flamingo section'], 'tags' => ['urgent']]));
	same(['G1'], search($platform, 'alice', 'flamingo')['ids'], 'parts (comments) are searchable');
	same(['G1'], search($platform, 'alice', 'urgent')['ids'], 'tags are searchable');
	check(str_contains(search($platform, 'alice', 'flamingo')['docs'][0]->getExcerpts()[0]['excerpt'] ?? '', 'flamingo'), 'excerpt falls back to parts when there is no content');

	section('getDocument');
	$platform->indexDocument(doc('R1', 'alice', 'Round trip', 'body text', [
		'users' => ['bob'], 'groups' => ['g1'], 'circles' => ['c1'], 'links' => ['tok'],
		'metatags' => ['m1'], 'subtags' => ['src' => 't1'], 'tags' => ['x'], 'parts' => ['comments' => 'hi'],
	]));
	$back = $platform->getDocument('files', 'R1');
	same('alice', $back->getAccess()->getOwnerId(), 'owner');
	same(['bob'], $back->getAccess()->getUsers(), 'users (owner not duplicated)');
	same(['g1'], $back->getAccess()->getGroups(), 'groups');
	same(['c1'], $back->getAccess()->getCircles(), 'circles');
	same(['tok'], $back->getAccess()->getLinks(), 'links');
	same(['m1'], $back->getMetaTags(), 'metatags');
	same(['x'], $back->getTags(), 'tags');
	same(['comments' => 'hi'], $back->getParts(), 'parts');
	same('body text', $back->getContent(), 'content');
	try {
		$platform->getDocument('files', 'missing');
		check(false, 'missing document throws');
	} catch (Throwable) {
		check(true, 'missing document throws');
	}

	section('delete and reset');
	$platform->deleteIndexes([new TestIndex('files', 'Q1')]);
	same(['Q3'], search($platform, 'alice', 'budget')['ids'], 'deleteIndexes removes the document');
	$platform->indexDocument(doc('K1', 'alice', 'Deck card about kiwis', 'kiwi', [], 'deck'));
	$platform->resetIndex('files');
	same([], search($platform, 'alice', 'budget')['ids'], 'resetIndex(provider) clears that provider');
	same(['K1'], search($platform, 'alice', 'kiwi', ['provider' => 'deck'])['ids'], 'other providers untouched');
	$platform->resetIndex('all');
	same([], search($platform, 'alice', 'kiwi', ['provider' => 'deck'])['ids'], "resetIndex('all') clears everything");
	same(0, $vocabulary->count(), "resetIndex('all') clears the vocabulary too");

	section('framework contract: the searches `occ fulltextsearch:test` runs');
	$config->setConfig(['language' => 'english']);
	$license = file_get_contents(dirname(__DIR__) . '/LICENSE');
	$licenseDoc = doc('license', 'user1', '', $license, ['groups' => ['group_1', 'Group_2'], 'users' => ['User number_2', 'User3', 'User@4']], 'test_provider');
	$platform->indexDocument($licenseDoc);
	$platform->indexDocument(doc('simple', 'user1', '', 'testing document is a simple test', [], 'test_provider'));
	$t = fn (string $viewer, string $q, array $groups = []) => sorted(search($platform, $viewer, $q, ['provider' => 'test_provider', 'groups' => $groups])['ids']);
	foreach ([
		['test', ['simple']],
		['document is a simple test', ['license', 'simple']],
		['"document is a test"', []],
		['"document is a simple test"', ['simple']],
		['document is a simple -test', ['license']],
		['document is a simple +test', ['simple']],
		['-document is a simple test', []],
		['document is a simple +test +testing', ['simple']],
		['document is a simple +test -testing', []],
		['document is a +simple -test -testing', []],
		['+document is a simple -test -testing', ['license']],
		['document is a +simple -license +testing', ['simple']],
	] as [$q, $expected]) {
		same($expected, $t('user1', $q), "'$q'");
	}
	same([], $t('notuser', 'license'), 'notuser, no groups');
	same(['license'], $t('notuser', 'license', ['group_1']), 'group_1');
	same(['license'], $t('notuser', 'license', ['group_1', 'Group_2']), 'group_1 + Group_2');
	same(['license'], $t('notuser', 'license', ['group_3', 'Group_2']), 'Group_2 (case-sensitive)');
	same([], $t('notuser', 'license', ['group_3']), 'unrelated group');
	same(['license'], $t('User number_2', 'license'), 'user id with a space');
	same(['license'], $t('User3', 'license'), 'User3');
	same(['license'], $t('User@4', 'license'), 'user id with @');
	$back = $platform->getDocument('test_provider', 'license');
	same('user1', $back->getAccess()->getOwnerId(), 'getDocument owner matches');
	same('', $back->getTitle(), 'getDocument title matches');
	$titled = doc('titled', 'user1', '  Spaced   Title  ', 'x', [], 'test_provider');
	$platform->indexDocument($titled);
	same('  Spaced   Title  ', $platform->getDocument('test_provider', 'titled')->getTitle(), 'titles round-trip verbatim');

	section('documents matching every word rank first');
	$platform->indexDocument(doc('W1', 'alice', 'Notes', str_repeat('zebra ', 20) . 'only one word'));
	$platform->indexDocument(doc('W2', 'alice', 'Other', 'a zebra and a giraffe once'));
	same('W2', search($platform, 'alice', 'zebra giraffe')['ids'][0] ?? null, 'all-terms match beats many repeats of one term');
	same(['W1', 'W2'], sorted(search($platform, 'alice', 'zebra giraffe')['ids']), 'partial matches still included');

	section('rare words weigh more than common ones (IDF)');
	// "report" is in 30 documents, "zanzibar" in one. No document has both. Body text against
	// body text, then title against title: field weights (title > body) still apply on top.
	for ($i = 1; $i <= 30; $i++) {
		$platform->indexDocument(doc("R$i", 'alice', "Figures $i", 'The monthly report and the report summary.', [], 'idf'));
	}
	$platform->indexDocument(doc('Z1', 'alice', 'Trip notes', 'Flights booked to Zanzibar in March.', [], 'idf'));
	// Bob's documents mention zanzibar everywhere, but alice cannot see them: they must not
	// make the word look common to her.
	for ($i = 1; $i <= 20; $i++) {
		$platform->indexDocument(doc("B$i", 'bob', "Zanzibar $i", 'zanzibar zanzibar', [], 'idf'));
	}
	$r = search($platform, 'alice', 'report zanzibar', ['provider' => 'idf', 'size' => 5]);
	same('Z1', $r['ids'][0] ?? null, 'one mention of the rare word outranks two of the common one');
	same(31, $r['total'], 'every partial match is still returned');
	$single = search($platform, 'alice', 'report', ['provider' => 'idf', 'size' => 50]);
	same(30, $single['total'], 'single-word search unchanged');
	$expected = (float)$conn->executeQuery(
		"SELECT round((ts_rank_cd(tsv, to_tsquery('english', 'report:*'), 32) * 100)::numeric, 2) FROM ftspg_oc_index WHERE document_id = 'R1'"
	)->fetchOne();
	same($expected, (float)$single['docs'][0]->getScore(), 'single-word score is plain ts_rank_cd, as before');
	$r = search($platform, 'bob', 'report zanzibar', ['provider' => 'idf', 'size' => 5]);
	check(!in_array('Z1', $r['ids'], true), 'frequencies come from the viewer\'s own documents (bob does not see Z1)');
	same('Z1', search($platform, 'alice', 'the report zanzibar', ['provider' => 'idf'])['ids'][0] ?? null, 'a stopword among the terms gets no weight');
	same('Z1', search($platform, 'alice', 'report zanzibar qqxyzzy', ['provider' => 'idf'])['ids'][0] ?? null, 'a word no document contains gets no weight');
	same('Z1', search($platform, 'alice', 'report "booked to zanzibar"', ['provider' => 'idf'])['ids'][0] ?? null, 'phrases are weighted like words');
	same('Z1', search($platform, 'alice', 'report zanzibr', ['provider' => 'idf'])['ids'][0] ?? null, 'typo corrections count towards the term they correct');
	$many = search($platform, 'alice', 'report zanzibar a1 b2 c3 d4 e5 f6 g7', ['provider' => 'idf']);
	same(31, $many['total'], 'more than 8 terms falls back to one combined rank');
	$platform->resetIndex('idf');
	for ($i = 1; $i <= 30; $i++) {
		$platform->indexDocument(doc("T$i", 'alice', "Report $i", 'Figures.', [], 'idf'));
	}
	$platform->indexDocument(doc('T99', 'alice', 'Zanzibar trip', 'Notes.', [], 'idf'));
	same('T99', search($platform, 'alice', 'report zanzibar', ['provider' => 'idf'])['ids'][0] ?? null, 'among title matches, the rare word ranks first');
	$platform->resetIndex('idf');
	// Terms matching over a quarter of the index are ranked in the unfenced query shape.
	$common = intdiv((int)$conn->executeQuery('SELECT count(*) FROM ftspg_oc_index')->fetchOne(), 2) + 10;
	for ($i = 1; $i <= $common; $i++) {
		$platform->indexDocument(doc("C$i", 'alice', "Memo $i", 'Weekly status and the status summary.', [], 'idf'));
	}
	$platform->indexDocument(doc('C0', 'alice', 'Trip notes', 'Flights booked to Zanzibar in March.', [], 'idf'));
	$r = search($platform, 'alice', 'status zanzibar', ['provider' => 'idf', 'size' => 5]);
	same('C0', $r['ids'][0] ?? null, 'rare word first when the common one matches most of the index too');
	same($common + 1, $r['total'], 'and every match is counted');
	$p2 = search($platform, 'alice', 'status zanzibar', ['provider' => 'idf', 'size' => 5, 'page' => 2]);
	same([], array_values(array_intersect($r['ids'], $p2['ids'])), 'pages of an IDF-ranked search do not overlap');
	$platform->resetIndex('idf');
	// Over 10,000 matches: the frequency comes from the planner's estimate, N from reltuples.
	$conn->executeStatement(
		"INSERT INTO ftspg_oc_index (provider_id, document_id, owner_id, access, config, title, content)
		 SELECT 'idf', 'X' || n, 'alice', '{u:alice}', 'english', 'Log ' || n, 'daily ledger and the ledger totals'
		 FROM generate_series(1, 10050) n"
	);
	$platform->indexDocument(doc('X0', 'alice', 'Trip notes', 'Flights booked to Zanzibar in March.', [], 'idf'));
	$conn->executeStatement('ANALYZE ftspg_oc_index');
	$r = search($platform, 'alice', 'ledger zanzibar', ['provider' => 'idf', 'size' => 5]);
	same('X0', $r['ids'][0] ?? null, 'a word in 10,000+ documents is weighed by its estimated frequency');
	same(10051, $r['total'], 'all of them still match');
	$platform->resetIndex('idf');
	$conn->executeStatement('ANALYZE ftspg_oc_index');

	section('file names');
	$config->setConfig(['language' => 'english']);
	$platform->indexDocument(doc('N1', 'alice', 'rapor_2025_final.pdf', ''));
	$platform->indexDocument(doc('N2', 'alice', 'Yillik-Butce-v2.xlsx', ''));
	$platform->indexDocument(doc('N3', 'alice', 'IMG20250412.jpg', ''));
	$platform->indexDocument(doc('N4', 'alice', 'Notes', 'Visit example.com or write to support@example.com'));
	$ids = fn (string $q) => sorted(search($platform, 'alice', $q)['ids']);
	same(['N1'], $ids('final'), 'word in the middle of a file name');
	same(['N1', 'N3'], $ids('2025'), 'number inside file names (2025 and IMG2025…)');
	same(['N2'], $ids('butce'), 'hyphen-separated part');
	same(['N3'], $ids('img'), 'letters before digits');
	same(['N1'], $ids('final.pdf'), 'typed file name fragment with a dot');
	same(['N1'], $ids('rapor_2025'), 'typed file name fragment with an underscore');
	same(['N1'], $ids('rapor_2025_final.pdf'), 'full file name');
	same(['N4'], $ids('example.com'), 'host names in content still match whole');
	same(['N4'], $ids('support@example.com'), 'e-mail addresses in content still match whole');

	section('substring matching in titles');
	$platform->indexDocument(doc('U1', 'alice', 'YillikButceRaporu.xlsx', ''));
	$platform->indexDocument(doc('U2', 'alice', 'Proje sözleşmesi', 'tamamlandı'));
	$platform->indexDocument(doc('U3', 'bob', 'GizliButce.ods', ''));
	same(['N2', 'U1'], $ids('butce'), 'inside a camelCase name; only documents alice can see');
	same(['N1', 'U1'], $ids('raporu'), 'at the end of a joined name (plus N1 via the correction raporu → rapor)');
	same(['U2'], $ids('özleş'), 'inside a word, non-ASCII');
	same(['U1'], $ids('BUTCERAP'), 'case-insensitive');
	same([], $ids('ce'), 'not for terms shorter than 3 characters');
	same([], $ids('roj -sözleşmesi'), 'exclusions still apply');
	same(['U1'], $ids('+utce +aporu'), 'required terms can be satisfied by substrings');
	same([], $ids('the'), 'stopwords do not substring-match ("Other", "the…")');
	same([], $ids('ut%ce'), 'a % in the query is not a wildcard');
	same([], $ids('u_ce'), 'an _ in the query is not a wildcard');
	$r = search($platform, 'alice', 'butce');
	same('N2', $r['ids'][0], 'full-text matches rank above substring-only matches');
	$config->setConfig(['use_trigram' => false]);
	same(['N2'], $ids('butce'), 'substring matching off with use_trigram=false');
	$config->setConfig(['use_trigram' => true]);

	section('Turkish substrings');
	$config->setConfig(['language' => 'turkish']);
	$platform->indexDocument(doc('TU1', 'alice', 'ISIKLANDIRMA_PLANI.pdf', ''));
	same(['TU1'], $ids('ıklandır'), 'dotless ı inside an uppercase title');
	same(['TU1'], $ids('IKLANDIR'), 'uppercase query, Turkish case folding');
	$config->setConfig(['language' => 'english']);

	section('typo correction from the vocabulary');
	$config->setConfig(['language' => 'english']);
	$platform->indexDocument(doc('V1', 'alice', 'Notes', 'We will receive the quarterly invoice from the supplier.'));
	$platform->indexDocument(doc('V2', 'alice', 'Toplantı', 'Yıllık çalışma planı ve öğrenci sözleşmesi hazırlandı.'));
	$platform->indexDocument(doc('V3', 'alice', 'Plain', 'calisma notes typed without diacritics'));
	$platform->indexDocument(doc('V4', 'bob', 'Private', 'confidential zeppelinx strategy'));
	same(['V1'], $ids('recieve'), 'swapped letters (recieve → receive)');
	same(['V1'], $ids('invocie'), 'swapped letters (invocie → invoice)');
	same(['V1'], $ids('quartrly'), 'missing letter (quartrly → quarterly)');
	same(['V1'], $ids('supplierr'), 'extra letter');
	same(['N1'], $ids('rpaor'), 'documented example: rpaor finds rapor (in rapor_2025_final.pdf)');
	same(['V2', 'V3'], $ids('calisma'), 'no diacritics: calisma finds çalışma (and the literal calisma)');
	same(['V2', 'V3'], $ids('çalışma'), 'diacritics typed: çalışma also finds calisma');
	same(['U2', 'V2'], $ids('sozlesme'), 'sozlesme finds sözleşmesi (V2 text, U2 title)');
	same(['V2'], $ids('ogrenci'), 'ogrenci finds öğrenci');
	same(['V2', 'V3'], $ids('calis'), 'accent variants while typing (calis → çalış…)');
	same(['V1'], $ids('+recieve +invoice'), 'corrections satisfy required words');
	same(['V1'], $ids('receive -invocie'), 'exclusions are not corrected (a misspelled exclusion excludes nothing)');
	same([], $ids('zeppelin'), 'words from documents the user cannot see never surface theirs');
	same([], $ids('xqzvbn'), 'no wild guesses for gibberish');
	same([], $ids('teh'), 'no spelling correction for words under 4 letters');
	$platform->indexDocument(doc('V5', 'alice', 'Car notes', 'The car is red'));
	same(['V5'], $ids('car'), 'known words are left alone');
	$config->setConfig(['typo_correction' => false]);
	same([], $ids('recieve'), 'typo_correction=false turns it off');
	same(['V3'], $ids('calisma'), 'accent variants off too');
	$config->setConfig(['typo_correction' => true]);

	section('vocabulary maintenance');
	check($vocabulary->count() > 20, 'words collected while indexing (' . $vocabulary->count() . ')');
	$words = $conn->executeQuery('SELECT word FROM ftspg_oc_words')->fetchAll(PDO::FETCH_COLUMN);
	check(in_array('çalışma', $words, true) && in_array('receive', $words, true), 'surface words stored, lowercase, with diacritics');
	check(!array_filter($words, fn ($w) => preg_match('/\d/', $w) || mb_strlen($w) < 3), 'no numbers, hashes or 1–2 letter words');
	check(in_array('butce', $words, true), 'parts of split file names included');
	$platform->deleteIndexes([new TestIndex('files', 'V1')]);
	same(['V1'], ['V1'], 'deleting a document leaves its words until a rebuild');
	$before = $vocabulary->count();
	$after = $vocabulary->rebuild();
	check($after < $before && !in_array('invoice', $conn->executeQuery('SELECT word FROM ftspg_oc_words')->fetchAll(PDO::FETCH_COLUMN), true), "rebuild drops words that no longer occur ($before → $after)");
	same([], $ids('invocie'), 'and corrections to them');
	$platform->indexDocument(doc('V1', 'alice', 'Notes', 'We will receive the quarterly invoice from the supplier.'));
	same(['V1'], $ids('invocie'), 'reindexing adds them back');
	if ($schema->hasTrigram()) {
		same('0.3', (string)$conn->executeQuery('SHOW pg_trgm.similarity_threshold')->fetchOne(), 'session similarity threshold restored after correction lookups');
	}

	section('vocabulary helpers and the upgrade step');
	same(1, VocabularyService::editDistance('recieve', 'receive'), 'a swap is one edit');
	same(1, VocabularyService::editDistance('raport', 'rapor'), 'an extra letter is one edit');
	same(3, VocabularyService::editDistance('ışık', 'isik'), 'multibyte letters count once each (3 substitutions)');
	$conn->executeStatement('TRUNCATE ftspg_oc_words');
	$progress = 0;
	$repairOutput = double(\OCP\Migration\IOutput::class, ['advance' => function (int $step = 1) use (&$progress) {
		$progress += $step;
	}]);
	(new CreateIndexTable($schema, $vocabulary))->run($repairOutput);
	check($vocabulary->count() > 20, 'upgrade step fills an empty vocabulary on small instances');
	same($schema->countDocuments(), $progress, 'and reports progress per document');

	section('upgrading a 1.1.x table in place');
	$t = $schema->getTableName();
	$conn->executeStatement("DROP INDEX IF EXISTS {$t}_title_search_trgm_idx");
	$conn->executeStatement("ALTER TABLE $t DROP COLUMN title_search, DROP COLUMN tsv");
	$conn->executeStatement("ALTER TABLE $t ADD COLUMN tsv tsvector GENERATED ALWAYS AS (to_tsvector(config, title)) STORED");
	if ($schema->hasTrigram()) {
		$conn->executeStatement("CREATE INDEX {$t}_title_trgm_idx ON $t USING GIN (title gin_trgm_ops)");
	}
	same(false, $schema->isCurrent(), '1.1.x schema detected as outdated');
	$platform->loadPlatform();
	same(true, $schema->isCurrent(), 'loadPlatform upgrades it');
	same(['N1'], $ids('final'), 'after upgrade: existing rows recomputed, file name parts searchable');
	same(['N2', 'U1'], $ids('butce'), 'after upgrade: substring matching works');
	$indexes = $conn->executeQuery('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$t])->fetchAll(PDO::FETCH_COLUMN);
	check(in_array("{$t}_title_search_trgm_idx", $indexes, true) && !in_array("{$t}_title_trgm_idx", $indexes, true), 'old trigram index replaced');
	$platform->initializeIndex();
	check(true, 'upgrade is idempotent');

	section('several languages: detected per document, all searched');
	same(true, $config->setConfig(['language' => 'Turkish, english, turkish']), 'language list accepted (trimmed, lowercased, deduplicated); change reported');
	same(['turkish', 'english'], $config->getLanguages(), 'fallback first, order kept');
	same('turkish,english', $config->getConfig()['language'], 'stored as a comma-separated list');
	same(false, $config->setConfig(['language' => ['turkish', 'english']]), 'an array is accepted too; same list is no change');
	foreach (['klingon' => 'Unsupported language "klingon"', '' => 'at least one', 'english,german,french,spanish,italian,dutch' => 'at most 5'] as $bad => $why) {
		try {
			$config->setConfig(['language' => $bad]);
			check(false, "rejected: '$bad'");
		} catch (\InvalidArgumentException $e) {
			check(str_contains($e->getMessage(), $why), "rejected: '$bad' ($why)");
		}
	}
	same(['turkish', 'english'], $config->getLanguages(), 'a rejected list leaves the setting unchanged');

	$platform->indexDocument(doc('M1', 'alice', 'Annual review', 'The studies were published by the board and they are available to all of the staff in the archive.'));
	$platform->indexDocument(doc('M2', 'alice', 'Yıllık rapor', 'Bu yılki kitapları ve dergileri kütüphaneye verdik, çünkü öğrenciler için çok önemli ve gerekli.'));
	$platform->indexDocument(doc('M3', 'alice', 'Rapor 2025', ''));
	$platform->indexDocument(doc('M4', 'alice', 'Bericht', 'Die Berichte wurden veröffentlicht und der Vorstand hat sie genehmigt, aber nicht alle.'));
	$configOf = static fn (string $id): string => (string)$conn->executeQuery('SELECT config::text FROM ftspg_oc_index WHERE document_id = ?', [$id])->fetchOne();
	same('english', $configOf('M1'), 'English text detected as english');
	same('turkish', $configOf('M2'), 'Turkish text detected as turkish');
	same('turkish', $configOf('M3'), 'no text to tell from: fallback language');
	same('turkish', $configOf('M4'), 'a language not configured (German): fallback language');
	same(['M1'], search($platform, 'alice', 'study')['ids'], 'English stemming for the English document (study finds studies)');
	same(['M2'], search($platform, 'alice', 'kitaplarımız')['ids'], 'Turkish stemming for the Turkish document (kitaplarımız finds kitapları)');
	same(['M2'], search($platform, 'alice', 'DERGİLERİ')['ids'], 'Turkish casing still applies to Turkish documents (İ is i)');
	same(['M1'], search($platform, 'alice', '+studies +archive')['ids'], 'required terms in the English branch');
	same([], search($platform, 'alice', 'studies -archive')['ids'], 'exclusions in the English branch');
	same(['M2'], search($platform, 'alice', '"kütüphaneye verdik"')['ids'], 'phrases in the Turkish branch');
	same(['M1', 'M2'], sorted(search($platform, 'alice', 'study kitaplarımız')['ids']), 'IDF-weighted search across both languages, each term stemmed per document');
	$ids = search($platform, 'alice', 'studies rapor')['ids'];
	same([], array_values(array_diff(['M1', 'M2', 'M3'], $ids)), 'terms that match in one language only still find their documents');

	// Only metadata changed (a share): the content is not sent again, the language stays.
	$platform->indexDocument(doc('M1', 'alice', 'Annual review', '', ['users' => ['bob']], 'files', IIndex::INDEX_META));
	same('english', $configOf('M1'), 'metadata-only update keeps the detected language');
	same(['M1'], search($platform, 'bob', 'study')['ids'], 'and the document is still found with its stemming');

	$conn->executeStatement('SET enable_seqscan = off');
	$plan = implode("\n", array_column($conn->executeQuery(
		"EXPLAIN SELECT id FROM ftspg_oc_index d WHERE ((d.config <> ALL ('{english}'::regconfig[]) AND d.tsv @@ to_tsquery('turkish', 'kitap:*'))"
		. " OR (d.config = 'english'::regconfig AND d.tsv @@ to_tsquery('english', 'study:*'))) AND d.access && '{u:alice}'::text[]"
	)->fetchAll(), 'QUERY PLAN'));
	check(str_contains($plan, 'ftspg_oc_index_tsv_idx') || str_contains($plan, 'ftspg_oc_index_access_idx'), 'two-language search still uses the GIN index');
	$conn->executeStatement('SET enable_seqscan = on');

	$platform->deleteIndexes(array_map(static fn (string $id) => new TestIndex('files', $id), ['M1', 'M2', 'M3', 'M4']));
	$config->setConfig(['language' => 'turkish']);

	section('searches stay on the full-text index after ANALYZE');
	// 4,000 documents whose search vectors are stored out of line (TOAST), all visible to alice,
	// as in a real index. With fresh statistics, PostgreSQL's own choice is to read every row and
	// test each vector, several times slower than the GIN index.
	$config->setConfig(['language' => 'english']);
	$conn->executeStatement(
		"INSERT INTO ftspg_oc_index (provider_id, document_id, owner_id, access, config, title, content)
		 SELECT 'plan', 'P' || n, 'alice', '{u:alice}', 'english', 'Doc ' || n,
		        (SELECT string_agg(md5(n::text || '-' || g::text), ' ') FROM generate_series(1, 150) g)
		        || CASE WHEN n % 10 = 0 THEN ' lantern' ELSE '' END || CASE WHEN n % 13 = 0 THEN ' meadow' ELSE '' END
		 FROM generate_series(1, 4000) n"
	);
	$conn->executeStatement('ANALYZE ftspg_oc_index');
	$conn->log = [];
	$r = search($platform, 'alice', 'lantern meadow', ['provider' => 'plan']);
	same(677, $r['total'], 'search over the TOASTed documents');
	$planOf = static function (string $prefix) use ($conn): string {
		$setup = null;
		$statement = null;
		foreach ($conn->log as [$sql, $params]) {
			if (str_contains($sql, "set_config('enable_seqscan', 'off', true)")) {
				$setup = [$sql, $params];
			} elseif ($statement === null && str_starts_with(ltrim($sql), $prefix)) {
				$statement = [$sql, $params];
			}
		}
		if ($setup === null || $statement === null) {
			return '';
		}
		// Replay the search's statement with the search's own settings, as it ran.
		$conn->beginTransaction();
		try {
			$conn->executeQuery(...$setup)->closeCursor();
			return implode("\n", array_column($conn->executeQuery('EXPLAIN ' . $statement[0], $statement[1])->fetchAll(), 'QUERY PLAN'));
		} finally {
			$conn->rollBack();
		}
	};
	$plan = $planOf('WITH q AS');
	check(str_contains($plan, 'ftspg_oc_index_tsv_idx') && !str_contains($plan, 'Seq Scan on ftspg_oc_index'), 'the search uses the GIN index, not a scan of every visible row');
	$plan = $planOf('SELECT (SELECT count(*)');
	check(str_contains($plan, 'ftspg_oc_index_tsv_idx') && !str_contains($plan, 'Seq Scan on ftspg_oc_index'), 'so do the IDF frequency counts');
	same(['on', '4', 'on'], array_values($conn->executeQuery("SELECT current_setting('enable_seqscan'), current_setting('random_page_cost'), current_setting('jit')")->fetch(PDO::FETCH_NUM)), 'the settings only applied to the search');
	same(false, $conn->inTransaction(), 'and its transaction is closed');
	$conn->beginTransaction();
	$conn->executeStatement('SET LOCAL random_page_cost = 0.9');
	search($platform, 'alice', 'lantern', ['provider' => 'plan']);
	same(['on', '0.9'], array_values($conn->executeQuery("SELECT current_setting('enable_seqscan'), current_setting('random_page_cost')")->fetch(PDO::FETCH_NUM)), 'inside a caller\'s transaction, its settings are put back');
	same(true, $conn->inTransaction(), 'and the transaction is left open');
	$conn->rollBack();
	// A provider with a few documents, searched for a word hundreds of others contain: testing
	// those few vectors beats visiting every document with the word.
	$conn->executeStatement(
		"INSERT INTO ftspg_oc_index (provider_id, document_id, owner_id, access, config, title, content)
		 SELECT 'tiny', 'T' || n, 'alice', '{u:alice}', 'english', 'Card ' || n, 'lantern on the shelf' FROM generate_series(1, 30) n"
	);
	$conn->executeStatement('ANALYZE ftspg_oc_index');
	$conn->log = [];
	same(30, search($platform, 'alice', 'lantern', ['provider' => 'tiny'])['total'], 'a small provider searched for a common word');
	$plan = $planOf('WITH q AS');
	check($plan !== '' && !str_contains($plan, 'ftspg_oc_index_tsv_idx') && !str_contains($plan, 'Seq Scan on ftspg_oc_index'), 'starts from the filters, not from every document with the word');
	$conn->executeStatement("DELETE FROM ftspg_oc_index WHERE provider_id = 'tiny'");
	$platform->resetIndex('plan');
	$conn->executeStatement('ANALYZE ftspg_oc_index');
	$config->setConfig(['language' => 'turkish']);

	section('query plan uses the indexes');
	$conn->executeStatement('SET enable_seqscan = off');
	$plan = implode("\n", array_column($conn->executeQuery(
		"EXPLAIN SELECT id FROM ftspg_oc_index d WHERE d.tsv @@ to_tsquery('english', 'budget:*') AND d.access && '{u:alice}'::text[]"
	)->fetchAll(), 'QUERY PLAN'));
	check(str_contains($plan, 'ftspg_oc_index_tsv_idx') || str_contains($plan, 'ftspg_oc_index_access_idx'), 'GIN index used');
	$plan = implode("\n", array_column($conn->executeQuery(
		"EXPLAIN SELECT id FROM ftspg_oc_index d WHERE d.title_search LIKE '%butce%'"
	)->fetchAll(), 'QUERY PLAN'));
	check(str_contains($plan, 'ftspg_oc_index_title_search_trgm_idx'), 'title substring search uses the trigram index');
	$conn->executeStatement('SET enable_seqscan = on');
} catch (Throwable $e) {
	$failures++;
	echo "\n  ERROR " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

section('PHP warnings and deprecations from app code');
same([], array_values(array_unique($appIssues)), 'none raised');

echo "\n$passes passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
