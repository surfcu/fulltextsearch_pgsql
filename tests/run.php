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
use OCA\FullTextSearch_PgSql\Migration\DropIndexTable;
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
	$timedOut = false;
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
		'setTimedOut' => function (bool $t) use (&$timedOut) {
			$timedOut = $t;
		},
		'setTime' => function (int $t) {
		},
		'setMaxScore' => function (float $s) {
		},
	]);
	$access = new DocumentAccess();
	$access->setViewerId($viewer);
	$access->setGroups($opts['groups'] ?? []);
	$access->setCircles($opts['circles'] ?? []);
	$platform->searchRequest($result, $access);
	return ['ids' => array_map(fn ($d) => $d->getId(), $docs), 'total' => $total, 'docs' => $docs, 'timedOut' => $timedOut];
}

function sorted(array $a): array {
	sort($a);
	return $a;
}

// ---------------------------------------------------------------- tests
try {
	section('schema');
	$conn->executeStatement('DROP TABLE IF EXISTS ftspg_oc_index, ftspg_oc_words');
	$conn->executeStatement('DROP TABLE IF EXISTS oc_fts_pgsql_index');
	same(false, $schema->tableExists(), 'index table missing before loadPlatform');
	$platform->loadPlatform();
	same(true, $schema->tableExists(), 'loadPlatform creates missing index table');
	same(true, $schema->isCurrent(), 'loadPlatform creates current schema when table is missing');
	$conn->statements = [];
	$platform->loadPlatform();
	$loadDdl = array_values(array_filter(
		$conn->statements,
		static fn (string $sql): bool => preg_match('/\b(CREATE TABLE|ALTER TABLE|CREATE INDEX|COMMENT ON)\b/i', $sql) === 1
	));
	same([], $loadDdl, 'loadPlatform issues no DDL when schema is already current');
	$conn->executeStatement('DROP TABLE IF EXISTS ftspg_oc_index, ftspg_oc_words');
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
	same(['Q1', 'Q3'], sorted(search($platform, 'alice', "bud\0get")['ids']), 'NUL byte inside search word is stripped');
	same(['Q1', 'Q3'], sorted(search($platform, 'alice', "+bud\0get")['ids']), 'NUL byte inside required search word is stripped');
	same([], search($platform, 'alice', "foo\0bar")['ids'], 'NUL byte inside unmatched search word does not throw 42601');
	same([], search($platform, 'alice', "+foo\0bar")['ids'], 'NUL byte inside required unmatched word does not throw 42601');
	same(['Q1', 'Q3'], sorted(search($platform, 'alice', "budget -foo\0bar")['ids']), 'NUL byte inside excluded word does not throw 42601');
	same(['Q1'], search($platform, 'alice', "\"bud\0get review\"")['ids'], 'NUL byte inside phrase is stripped');
	same([], search($platform, 'alice', "foo\x01bar")['ids'], 'A4: C0 control char inside unmatched search word does not throw');
	same([], search($platform, 'alice', "foo\x7fbar")['ids'], 'A4: DEL control char inside unmatched search word does not throw');
	same(['Q1', 'Q3'], sorted(search($platform, 'alice', "bud\x07get")['ids']), 'A4: BEL control char inside search word is stripped');
	same(['Q1', 'Q3'], sorted(search($platform, 'alice', "\"bud\x07get\"")['ids']), 'A4: BEL control char inside single-word phrase is stripped');
	same(['Q1'], search($platform, 'alice', "\"bud\x07get review\"")['ids'], 'A4: BEL control char inside multi-word phrase is stripped');
	$qbCtrl = new TsQueryBuilder();
	same(['foobar'], $qbCtrl->searchWords("foo\0bar", 'english'), 'A4: tokenize strips NUL inside word');
	same(['foobar'], $qbCtrl->searchWords("foo\x01bar", 'english'), 'A4: tokenize strips C0 control char inside word');
	same(['foobar'], $qbCtrl->searchWords("foo\x7fbar", 'english'), 'A4: tokenize strips DEL control char inside word');
	same(['budget'], $qbCtrl->searchWords("bud\x07get", 'english'), 'A4: tokenize strips BEL inside word');
	same(['foo', 'bar'], $qbCtrl->searchWords("foo\tbar", 'english'), 'A4: tab still separates tokens');
	same(['foo', 'bar'], $qbCtrl->searchWords("foo\nbar", 'english'), 'A4: newline still separates tokens');
	same(['foo', 'bar'], $qbCtrl->searchWords("foo\rbar", 'english'), 'A4: carriage return still separates tokens');
	same(['Q1', 'Q3'], search($platform, 'alice', "budget\treview")['ids'], 'A4: tab-separated search matches both words');
	same(['Q1', 'Q3'], search($platform, 'alice', "budget\nreview")['ids'], 'A4: newline-separated search matches both words');
	same(['Q1', 'Q3'], search($platform, 'alice', "budget\rreview")['ids'], 'A4: CR-separated search matches both words');
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
		'nul-byte',
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

	section('title fallback respects exclusions, required terms and phrases (A2)');
	$platform->indexDocument(doc('A2_DRAFT', 'alice', 'budgets_draft.txt', '', [], 'a2'));
	$platform->indexDocument(doc('A2_PLAN', 'alice', 'marketing_plan.txt', '', [], 'a2'));
	$a2 = ['provider' => 'a2'];
	same(['A2_DRAFT'], search($platform, 'alice', 'budget', $a2)['ids'], 'A2 baseline: plain word finds document');
	same([], search($platform, 'alice', 'budget -draft', $a2)['ids'], 'A2: title fallback suppressed when exclusion is present');
	same([], search($platform, 'alice', '+budget -draft', $a2)['ids'], 'A2: title fallback suppressed when required and excluded terms are present');
	same([], search($platform, 'alice', '"draft budgets"', $a2)['ids'], 'A2: title fallback suppressed for phrase query');
	$config->setConfig(['typo_correction' => false]);
	same([], search($platform, 'alice', '+budgt +draft', $a2)['ids'], 'A2: title fallback suppressed when required terms (+) are present');
	same([], search($platform, 'alice', '+"draft budgets"', $a2)['ids'], 'A2: title fallback suppressed for required phrase query');
	same(['A2_PLAN'], search($platform, 'alice', 'markting OR plann', $a2)['ids'], 'A2: plain-word fallback works with OR');
	same(['A2_PLAN'], search($platform, 'alice', 'markting AND plann', $a2)['ids'], 'A2: plain-word fallback works with AND');
	$config->setConfig(['typo_correction' => true]);
	$platform->resetIndex('a2');

	$qb = new TsQueryBuilder();
	foreach (['budget', 'markting OR plann', 'markting AND plann', 'e-mail', 'budget -'] as $plainQuery) {
		same(true, $qb->build($plainQuery, 'english')?->plainWordsOnly, "A2 unit: plainWordsOnly is true for '$plainQuery'");
	}
	foreach ([
		'budget -draft',
		'+budget -draft',
		'+budget',
		'"budgets draft"',
		'+"draft budgets"',
		'budget -"draft"',
		'budget -OR',
		'budget +AND',
		'budget "OR"',
	] as $constrainedQuery) {
		same(false, $qb->build($constrainedQuery, 'english')?->plainWordsOnly, "A2 unit: plainWordsOnly is false for '$constrainedQuery'");
	}

	section('stopwords in multi-word searches (A5)');
	$platform->indexDocument(doc('A5_BOTH', 'alice', 'Summary', 'The quarterly budget for 2025 is ready.', [], 'a5'));
	$platform->indexDocument(doc('A5_BUDGET_ONLY', 'alice', 'Budget notes', 'budget budget budget budget budget', [], 'a5'));
	$platform->indexDocument(doc('A5_2025_ONLY', 'alice', 'Year review', 'Overview for 2025.', [], 'a5'));
	$platform->indexDocument(doc('A5_TO_BE', 'alice', 'Notes', 'Notes about to be reviewed.', [], 'a5'));
	$platform->indexDocument(doc('A5_STOP_ONLY', 'alice', 'The of and', 'for to be', [], 'a5'));
	$platform->indexDocument(doc('A5_UNRELATED', 'alice', 'Recipes', 'Simple soup recipe with carrots.', [], 'a5'));
	$a5 = ['provider' => 'a5'];
	$a5Rank = search($platform, 'alice', 'budget for 2025', $a5);
	same('A5_BOTH', $a5Rank['ids'][0] ?? '', 'A5: budget for 2025 ranks the document containing both non-stopword terms first');
	same(['A5_2025_ONLY', 'A5_BOTH', 'A5_BUDGET_ONLY'], sorted($a5Rank['ids']), 'A5: budget for 2025 returns partial matches after full match');
	same(['A5_BOTH', 'A5_BUDGET_ONLY'], sorted(search($platform, 'alice', '+the +budget', $a5)['ids']), 'A5: +the +budget ignores required stopword');
	same(['A5_BOTH'], search($platform, 'alice', '+budget +for +2025', $a5)['ids'], 'A5: +budget +for +2025 finds document matching all non-stopword terms');
	$a5AllStop = search($platform, 'alice', '+the +of', $a5);
	same([], $a5AllStop['ids'], 'A5: +the +of returns nothing');
	same(0, $a5AllStop['total'], 'A5: +the +of has total 0');
	$a5StopPhrase = search($platform, 'alice', '+"to be"', $a5);
	same([], $a5StopPhrase['ids'], 'A5: +"to be" returns nothing');
	same(0, $a5StopPhrase['total'], 'A5: +"to be" has total 0');
	same(['A5_BOTH', 'A5_BUDGET_ONLY'], sorted(search($platform, 'alice', '+"to be" +budget', $a5)['ids']), 'A5: +"to be" +budget ignores stopword phrase');
	$a5PhraseOpt = search($platform, 'alice', '"to be" budget 2025', $a5);
	same('A5_BOTH', $a5PhraseOpt['ids'][0] ?? '', 'A5: "to be" budget 2025 ranks document with both content words first');
	same(['A5_BOTH', 'A5_BUDGET_ONLY'], sorted(search($platform, 'alice', '+the budget', $a5)['ids']), 'A5: +the budget falls back to optional content word');
	same(['A5_BOTH'], search($platform, 'alice', '"budget for 2025"', $a5)['ids'], 'A5: "budget for 2025" matches phrase containing stopword');
	same(['A5_BOTH', 'A5_BUDGET_ONLY'], sorted(search($platform, 'alice', '+the +budget', $a5 + ['limit' => ['content']])['ids']), 'A5: +the +budget limited to content');
	same(['A5_BUDGET_ONLY'], search($platform, 'alice', '+the +budget', $a5 + ['limit' => ['title']])['ids'], 'A5: +the +budget limited to title');
	same(['A5_BOTH', 'A5_BUDGET_ONLY'], sorted(search($platform, 'alice', '+"to be" +budget', $a5 + ['limit' => ['content']])['ids']), 'A5: +"to be" +budget limited to content');
	same([], search($platform, 'alice', '+"to be" +summary', $a5 + ['limit' => ['content']])['ids'], 'A5: +"to be" +summary limited to content excludes title-only hit');

	// Accented homographs of stopwords (e.g. "thé" -> "the", "çan" -> "can") must not
	// turn stopwords into required non-stopword terms when typo_correction is enabled.
	$platform->indexDocument(doc('A5_ACCENT', 'bob', 'Cafe', 'Freshly brewed thé and çan.', [], 'a5'));
	same(['A5_BOTH', 'A5_BUDGET_ONLY'], sorted(search($platform, 'alice', '+the +budget', $a5)['ids']), 'A5: +the +budget ignores stopword with accented homograph in vocabulary');
	same(['A5_BOTH', 'A5_BUDGET_ONLY'], sorted(search($platform, 'alice', '+can +budget', $a5)['ids']), 'A5: +can +budget ignores stopword with accented homograph in vocabulary');
	same([], search($platform, 'alice', '+the +of', $a5)['ids'], 'A5: +the +of returns nothing with accented homograph in vocabulary');
	$a5AccentOpt = search($platform, 'alice', 'budget +the 2025', $a5);
	same('A5_BOTH', $a5AccentOpt['ids'][0] ?? '', 'A5: budget +the 2025 ranks A5_BOTH first with accented homograph in vocabulary');
	same(['A5_2025_ONLY', 'A5_BOTH', 'A5_BUDGET_ONLY'], sorted($a5AccentOpt['ids']), 'A5: budget +the 2025 returns expected matches with accented homograph in vocabulary');
	$a5AccentReq = search($platform, 'alice', '+budget +the 2025', $a5);
	same('A5_BOTH', $a5AccentReq['ids'][0] ?? '', 'A5: +budget +the 2025 ranks A5_BOTH first with accented homograph in vocabulary');
	same(['A5_BOTH', 'A5_BUDGET_ONLY'], sorted($a5AccentReq['ids']), 'A5: +budget +the 2025 returns expected matches with accented homograph in vocabulary');
	same(['A5_BOTH', 'A5_BUDGET_ONLY'], sorted(search($platform, 'alice', '+"to be" +budget', $a5)['ids']), 'A5: +"to be" +budget works with accented homograph in vocabulary');
	same(['A5_ACCENT'], search($platform, 'bob', 'thé', $a5)['ids'], 'A5: literal accented word matching stopword still matches itself');
	same(['A5_ACCENT'], search($platform, 'bob', 'çan', $a5)['ids'], 'A5: literal accented word çan still matches itself');

	$platform->resetIndex('a5');
	$config->setConfig(['language' => 'turkish,english']);
	$platform->indexDocument(doc('A5_EN', 'alice', 'English report', 'The budget for the year was approved by the board and they are all in the office.', [], 'a5'));
	$platform->indexDocument(doc('A5_TR', 'alice', 'Türkçe rapor', 'Bu yıl için bütçe ve budget verileri ile tüm raporlar burada.', [], 'a5'));
	same(['A5_EN'], search($platform, 'alice', '+the +budget', $a5)['ids'], 'A5 multi-language: +the +budget skips English stopword in English branch');
	same(['A5_TR'], search($platform, 'alice', '+ve +budget', $a5)['ids'], 'A5 multi-language: +ve +budget skips Turkish stopword in Turkish branch');
	$config->setConfig(['language' => 'english']);
	$platform->resetIndex('a5');

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
	$startT = microtime(true);
	same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => '(a)\1.*']]]])['ids'], 'backreference regex filter returns nothing');
	same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => '(?<g>a)\k<g>.*']]]])['ids'], 'named backreference \\k regex filter returns nothing');
	same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => '(a)\g1.*']]]])['ids'], 'backreference \\g regex filter returns nothing');
	check(microtime(true) - $startT < 1.0, 'backreference regex filter returns in under a second');
	same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => 'a{300}.*']]]])['ids'], 'repetition quantifier exceeding 255 returns nothing without SQL error');
	same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => 'a{1,300}.*']]]])['ids'], 'repetition range quantifier exceeding 255 returns nothing without SQL error');
	foreach ([
		'quantified lookahead *' => '(?=a)*.*',
		'quantified lookahead +' => '(?=a)+.*',
		'quantified lookahead ?' => '(?=a)?.*',
		'quantified lookahead {1,2}' => '(?=a){1,2}.*',
		'quantified negative lookahead *' => '(?!b)*.*',
		'quantified negative lookahead +' => '(?!b)+.*',
		'quantified negative lookahead ?' => '(?!b)?.*',
		'quantified negative lookahead {1,2}' => '(?!b){1,2}.*',
		'quantified nested lookahead' => '(?=(a|b))+.*',
		'PCRE control verb UTF8' => '(*UTF8).*',
		'PCRE control verb ANYCRLF' => '(*ANYCRLF).*',
		'PCRE control verb FAIL' => '(*FAIL).*',
		'stacked repetitions (a{255}){255}' => '(a{255}){255}',
		'stacked repetitions (?:a{255}){255}' => '(?:a{255}){255}',
		'stacked repetitions (a{200}){200}' => '(a{200}){200}',
		'PCRE \\p{L}' => '\p{L}.*',
		'PCRE \\P{N}' => '\P{N}.*',
		'PCRE \\x{0}' => '\x{0}.*',
		'PCRE \\x{41}' => '\x{41}.*',
		'PCRE \\o{101}' => '\o{101}.*',
		'PCRE \\C' => '\C.*',
		'PCRE \\N{U+0041}' => '\N{U+0041}.*',
		'PCRE \\p{L} in class' => '[\p{L}].*',
		'PCRE \\P{N} in class' => '[\P{N}].*',
		'PCRE \\x{0} in class' => '[\x{0}].*',
		'PCRE \\x{41} in class' => '[\x{41}].*',
		'PCRE \\o{101} in class' => '[\o{101}].*',
		'PCRE \\C in class' => '[\C].*',
		'PCRE \\N{U+0041} in class' => '[\N{U+0041}].*',
	] as $label => $badRegex) {
		same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => $badRegex]]]])['ids'], "unportable regex rejected without SQL error ($label)");
	}
	same(['E1', 'E2'], sorted(search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => '(?=.*plan).*\.pdf']]]])['ids']), 'unquantified lookahead regex remains portable');
	same(['E1', 'E2'], sorted(search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => '[[:alpha:]]+\s+\d+\x2e(?:pdf|PDF)']]]])['ids']), 'POSIX class and \\xHH escape remain portable');
	// Fallback catch for SQLSTATE 2201B in SearchService::searchRequest()
	$regexErrProxy = new class($conn) implements \OCP\IDBConnection {
		public function __construct(
			private \OCP\IDBConnection $inner,
		) {
		}
		public function executeStatement($sql, array $params = [], array $types = []): int {
			return $this->inner->executeStatement($sql, $params, $types);
		}
		public function executeQuery(string $sql, array $params = [], $types = []): \OCP\DB\IResult {
			if (str_contains($sql, 'WITH q AS') || str_contains($sql, 'EXPLAIN (FORMAT JSON)')) {
				$e = new \PDOException('SQLSTATE[2201B]: Invalid regular expression: regular expression is too complex', 2201);
				$e->errorInfo = ['2201B', 7, 'invalid regular expression: regular expression is too complex'];
				throw $e;
			}
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
	$regexErrSearch = new SearchService($regexErrProxy, $schema, $config, new TsQueryBuilder(), $vocabulary);
	$regexErrPlatform = new PostgreSQLPlatform($config, $schema, $indexService, $regexErrSearch, $logger, $extractor);
	$regexErrRes = search($regexErrPlatform, 'alice', 'harbour', $f);
	same(0, $regexErrRes['total'], 'SQLSTATE 2201B in searchRequest returns total 0 instead of throwing');
	same(false, $regexErrRes['timedOut'], 'SQLSTATE 2201B does not set timedOut');
	same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => "plan\0pdf.*"]]]])['ids'], 'pattern with NUL byte is safely skipped');
	same([], search($platform, 'alice', 'harbour', $f + ['regex' => [[['title' => "plan\xff\xfepdf.*"]]]])['ids'], 'pattern with invalid UTF-8 is safely skipped');
	same([], search($platform, 'alice', 'harbour', $f + ['wildcard' => [[['title' => "plan\0*"]]]])['ids'], 'wildcard with NUL byte is safely skipped');
	same([], search($platform, 'alice', 'harbour', $f + ['wildcard' => [[['title' => "plan\xff\xfe*"]]]])['ids'], 'wildcard with invalid UTF-8 is safely skipped');
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
	$platform->indexDocument(doc('X_PART_SHORT', 'alice', 'Short note', 'Daily meeting summary without the keyword.', ['parts' => ['comments' => 'Please review the platypus attachment before Friday.']], 'exc'));
	$rPartShort = search($platform, 'alice', 'platypus', ['provider' => 'exc']);
	same(['X_PART_SHORT'], $rPartShort['ids'], 'A4: comment match on doc with short content is found');
	$partShortExcerpt = implode(' … ', array_column($rPartShort['docs'][0]?->getExcerpts() ?? [], 'excerpt'));
	check(str_contains($partShortExcerpt, 'platypus attachment'), 'A4: excerpt shows matching comment when short content is present: ' . $partShortExcerpt);

	$platform->indexDocument(doc('X_PART_LONG', 'alice', 'Long report', $filler . 'Closing remarks without the keyword.', ['parts' => ['comments' => 'Confirmed capybara figures in appendix three.']], 'exc'));
	$rPartLong = search($platform, 'alice', 'capybara', ['provider' => 'exc']);
	same(['X_PART_LONG'], $rPartLong['ids'], 'A4: comment match on doc with >10 KB content is found');
	$partLongExcerpt = implode(' … ', array_column($rPartLong['docs'][0]?->getExcerpts() ?? [], 'excerpt'));
	check(str_contains($partLongExcerpt, 'capybara figures'), 'A4: excerpt shows matching comment after >10 KB content: ' . $partLongExcerpt);

	$platform->indexDocument(doc('X_PART_BOUNDARY', 'alice', 'Boundary report', $filler . 'tailword', ['parts' => ['comments' => 'quokka confirmed in final review.']], 'exc'));
	$rPartBoundary = search($platform, 'alice', 'quokka', ['provider' => 'exc']);
	same(['X_PART_BOUNDARY'], $rPartBoundary['ids'], 'A4: boundary comment match on doc with >10 KB content without trailing space is found');
	$partBoundaryExcerpt = implode(' … ', array_column($rPartBoundary['docs'][0]?->getExcerpts() ?? [], 'excerpt'));
	check(str_contains($partBoundaryExcerpt, 'quokka confirmed'), 'A4: excerpt shows first word of parts_text after >10 KB content without trailing space: ' . $partBoundaryExcerpt);

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
	same(['B1_NUL_INFO'], search($platform, 'alice', '"NUL Info Title"')['ids'], 'B1: document with NUL info is searchable');

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
	same(['B1_TX_DOC'], search($platform, 'alice', '"Transaction Safety Doc"')['ids'], 'B1: degraded doc in committed transaction is findable');
	same(['B1_TX_SIBLING'], search($platform, 'alice', '"sibling payload content"')['ids'], 'B1: sibling doc in same transaction is findable');

	// Verify rollback behavior
	$conn->beginTransaction();
	$docRollback = doc('B1_TX_ROLLBACK', 'alice', 'Rollback Target Doc', 'content that will be rolled back');
	$platform->indexDocument($docRollback);
	$conn->rollBack();
	same(false, $conn->inTransaction(), 'B1: caller transaction rolled back');
	same([], search($platform, 'alice', '"Rollback Target Doc"')['ids'], 'B1: rolled back document is absent from index');

	// -------------------------------------------------------------------------
	// 7. Partial updates (INDEX_META / INDEX_CONTENT) under 54000 preserve parts
	// -------------------------------------------------------------------------
	$platform->indexDocument(doc('B1_PARTIAL_PARTS', 'alice', 'Initial Partial Title', 'initial durable content', [
		'parts' => ['comments' => 'precious comment text'],
	]));
	$storedPartsBefore = (string)$conn->executeQuery("SELECT parts_text FROM ftspg_oc_index WHERE document_id = 'B1_PARTIAL_PARTS'")->fetchOne();
	same('precious comment text', $storedPartsBefore, 'B1: partial update baseline parts_text stored');

	// INDEX_META update with oversized title triggering SQLSTATE 54000 -> Step 5
	mt_srand(505);
	$metaTitleWords = [];
	for ($i = 0; $i < 70000; $i++) {
		$metaTitleWords[] = 'm' . substr(md5((string)mt_rand()), 0, 8);
	}
	$hugeMetaTitle = 'HugeMetaTitle ' . implode(' ', $metaTitleWords);
	$runnerLog = [];
	$platform->indexDocument(doc('B1_PARTIAL_PARTS', 'alice', $hugeMetaTitle, '', ['users' => ['bob']], 'files', IIndex::INDEX_META));
	same('warning', $runnerLog['B1_PARTIAL_PARTS'][0] ?? '', 'B1: INDEX_META under 54000 degrades with warning');
	$partialMetaRow = $conn->executeQuery("SELECT parts::text AS parts, parts_text, content FROM ftspg_oc_index WHERE document_id = 'B1_PARTIAL_PARTS'")->fetch(\PDO::FETCH_ASSOC);
	same(['comments' => 'precious comment text'], json_decode((string)$partialMetaRow['parts'], true), 'B1: INDEX_META under 54000 preserves parts jsonb');
	same('precious comment text', (string)$partialMetaRow['parts_text'], 'B1: INDEX_META under 54000 preserves parts_text');
	same('initial durable content', (string)$partialMetaRow['content'], 'B1: INDEX_META under 54000 preserves content');
	same(['B1_PARTIAL_PARTS'], search($platform, 'bob', 'precious')['ids'], 'B1: INDEX_META share grants access and existing parts remain searchable');

	// INDEX_CONTENT update with oversized content + title triggering SQLSTATE 54000 -> Step 5/5b
	$runnerLog = [];
	$platform->indexDocument(doc('B1_PARTIAL_PARTS', 'alice', $hugeMetaTitle, 'fresh content payload', [], 'files', IIndex::INDEX_CONTENT));
	same('warning', $runnerLog['B1_PARTIAL_PARTS'][0] ?? '', 'B1: INDEX_CONTENT under 54000 degrades with warning');
	$partialContentRow = $conn->executeQuery("SELECT parts::text AS parts, parts_text, content FROM ftspg_oc_index WHERE document_id = 'B1_PARTIAL_PARTS'")->fetch(\PDO::FETCH_ASSOC);
	same(['comments' => 'precious comment text'], json_decode((string)$partialContentRow['parts'], true), 'B1: INDEX_CONTENT under 54000 preserves parts jsonb');
	same('precious comment text', (string)$partialContentRow['parts_text'], 'B1: INDEX_CONTENT under 54000 preserves parts_text');
	same('fresh content payload', (string)$partialContentRow['content'], 'B1: INDEX_CONTENT under 54000 updates content');

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

	section('validate settings when read & locale diagnostics (B4)');

	// 1. Stored invalid language with valid fallback: klingon,english -> ['english']
	$appConfig->setValueString('fulltextsearch_pgsql', 'language', 'klingon,english');
	same(['english'], $config->getLanguages(), 'B4: stored klingon,english drops klingon and returns [english]');
	same('english', $config->getLanguage(), 'B4: fallback language is english');

	$docInvalidLang = doc('B4_LANG1', 'alice', 'Invalid Language Doc', 'content indexed with dropped klingon');
	$platform->indexDocument($docInvalidLang);
	$storedConfig1 = (string)$conn->executeQuery("SELECT config::text FROM ftspg_oc_index WHERE document_id = 'B4_LANG1'")->fetchOne();
	same('english', $storedConfig1, 'B4: document indexed under english regconfig');
	same(['B4_LANG1'], search($platform, 'alice', '"Invalid Language Doc"')['ids'], 'B4: document with dropped language is searchable');

	// 2. Stored all-invalid language: klingon -> fallback to ['simple']
	$appConfig->setValueString('fulltextsearch_pgsql', 'language', 'klingon');
	same(['simple'], $config->getLanguages(), 'B4: stored klingon alone falls back to [simple]');
	same('simple', $config->getLanguage(), 'B4: fallback language is simple when all configured are invalid');

	$docSimpleLang = doc('B4_LANG2', 'alice', 'Simple Fallback Doc', 'content indexed under simple fallback config');
	$platform->indexDocument($docSimpleLang);
	$storedConfig2 = (string)$conn->executeQuery("SELECT config::text FROM ftspg_oc_index WHERE document_id = 'B4_LANG2'")->fetchOne();
	same('simple', $storedConfig2, 'B4: document indexed under simple regconfig');
	same(['B4_LANG2'], search($platform, 'alice', '"Simple Fallback Doc"')['ids'], 'B4: document with simple fallback is searchable');

	// 3. Stored max_results clamped to [1, 10000]
	$appConfig->setValueInt('fulltextsearch_pgsql', 'max_results', 50000);
	same(10000, $config->getMaxResults(), 'B4: stored max_results 50000 clamped to 10000');
	$appConfig->setValueInt('fulltextsearch_pgsql', 'max_results', 0);
	same(1, $config->getMaxResults(), 'B4: stored max_results 0 clamped to 1');
	$appConfig->setValueInt('fulltextsearch_pgsql', 'max_results', -50);
	same(1, $config->getMaxResults(), 'B4: stored max_results -50 clamped to 1');
	$appConfig->setValueInt('fulltextsearch_pgsql', 'max_results', 500);
	same(500, $config->getMaxResults(), 'B4: valid max_results 500 preserved');
	$appConfig->setValueInt('fulltextsearch_pgsql', 'max_results', 100);

	// 4. Database locale diagnostics (SELECT lower('Ç') = 'ç')
	same(true, $schema->hasLocaleCaseFolding(), 'B4: PostgreSQL hasLocaleCaseFolding returns true for UTF-8 test DB');
	$statusB4 = $statusService->getStatus();
	same(true, $statusB4['locale_folding'] ?? null, 'B4: StatusService exposes locale_folding');
	$platformConfig = $platform->getConfiguration();
	same(true, $platformConfig['locale_folding'] ?? null, 'B4: PostgreSQLPlatform::getConfiguration exposes locale_folding');
	same('ok', $platformConfig['database_locale'] ?? null, 'B4: PostgreSQLPlatform::getConfiguration exposes database_locale ok');

	// Diagnostic reports disabled message when locale folding is false
	$mockSchemaNoFolding = new class($conn, new ArrayConfig(['dbtableprefix' => 'oc_']), $logger) extends SchemaService {
		public function hasLocaleCaseFolding(): bool {
			return false;
		}
	};
	$mockStatusNoFolding = new StatusService($mockSchemaNoFolding, $vocabulary, $extractor);
	same(false, $mockStatusNoFolding->getStatus()['locale_folding'], 'B4: StatusService reports false when locale folding disabled');

	$mockPlatformNoFolding = new PostgreSQLPlatform($config, $mockSchemaNoFolding, $indexService, $searchService, $logger, $extractor);
	$mockPlatformConfig = $mockPlatformNoFolding->getConfiguration();
	same(false, $mockPlatformConfig['locale_folding'], 'B4: Platform reports false locale_folding');
	check(str_contains($mockPlatformConfig['database_locale'], 'disabled'), 'B4: Platform reports disabled message in database_locale');

	// 5. Language changed precision (order-independent comparison for extras)
	$config->setConfig(['language' => 'english,turkish,french']);
	$changedSameExtrasDiffOrder = $config->setConfig(['language' => 'english,french,turkish']);
	same(false, $changedSameExtrasDiffOrder, 'B4: reordering extra languages does not falsely mark languageChanged');

	$changedNewExtras = $config->setConfig(['language' => 'english,turkish,german']);
	same(true, $changedNewExtras, 'B4: changing extra languages set marks languageChanged');

	$changedPrimary = $config->setConfig(['language' => 'turkish,english,german']);
	same(true, $changedPrimary, 'B4: changing primary language marks languageChanged');

	// Restore clean language config
	$config->setConfig(['language' => 'english']);

	section('partial updates');
	$platform->indexDocument(doc('P1', 'alice', 'Shared later', 'secret content about narwhals'));
	$meta = doc('P1', 'alice', 'Shared later', '', ['users' => ['bob']], 'files', IIndex::INDEX_META);
	$platform->indexDocument($meta);
	same(['P1'], search($platform, 'bob', 'narwhals')['ids'], 'metadata-only update adds the share and keeps the content');
	$platform->indexDocument(doc('P1', 'alice', 'Shared later', 'new text about dolphins', [], 'files', IIndex::INDEX_CONTENT));
	same([], search($platform, 'alice', 'narwhals')['ids'], 'content update replaces old content');
	same(['P1'], search($platform, 'alice', 'dolphins')['ids'], 'content update indexes new content');
	$platform->indexDocument(doc('P1', 'alice', 'Shared later', '', ['users' => ['carol']], 'files', IIndex::INDEX_OK));
	same(['P1'], search($platform, 'carol', 'dolphins')['ids'], 'INDEX_OK refresh updates access and keeps stored content');

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
	same('Z1', search($platform, 'alice', 'report zanzibr', ['provider' => 'idf', 'size' => 50])['ids'][0] ?? null, 'typo corrections count towards the term they correct');
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

	section('viewer-scoped typo correction (A3)');
	$a3 = ['provider' => 'a3'];
	$a3Qb = new TsQueryBuilder();
	[$emptyTsSql, $emptyTsParams] = $a3Qb->wordTsQuery('', 'english');
	same(0, (int)$conn->executeQuery("SELECT numnode($emptyTsSql)", $emptyTsParams)->fetchOne(), 'A3: wordTsQuery empty string has numnode 0');
	[$punctTsSql, $punctTsParams] = $a3Qb->wordTsQuery('---', 'english');
	same(0, (int)$conn->executeQuery("SELECT numnode($punctTsSql)", $punctTsParams)->fetchOne(), 'A3: wordTsQuery punctuation-only has numnode 0');
	[$trTsSql, $trTsParams] = $a3Qb->wordTsQuery('İZMİR', 'turkish');
	same(['to_tsquery(CAST(? AS regconfig), ?)', ['turkish', "'iZMiR':*"]], [$trTsSql, $trTsParams], 'A3: wordTsQuery maps Turkish İ -> i');
	same("'izmir':*", (string)$conn->executeQuery("SELECT ($trTsSql)::text", $trTsParams)->fetchOne(), 'A3: wordTsQuery Turkish İ compiles cleanly in PostgreSQL');
	[$trDotlessSql, $trDotlessParams] = $a3Qb->wordTsQuery('ISIK', 'turkish');
	same(['to_tsquery(CAST(? AS regconfig), ?)', ['turkish', "'ıSıK':*"]], [$trDotlessSql, $trDotlessParams], 'A3: wordTsQuery maps Turkish I -> ı');
	same("'ısık':*", (string)$conn->executeQuery("SELECT ($trDotlessSql)::text", $trDotlessParams)->fetchOne(), 'A3: wordTsQuery Turkish I compiles cleanly in PostgreSQL');
	[$wTsSql, $wTsParams] = $a3Qb->wordTsQuery('budget', 'english', 'A');
	same(['to_tsquery(CAST(? AS regconfig), ?)', ['english', "'budget':*A"]], [$wTsSql, $wTsParams], 'A3: wordTsQuery applies optional weight labels');
	[$simpleExactSql, $simpleExactParams] = $a3Qb->wordTsQuery('receive', 'simple', null, false);
	same(['to_tsquery(CAST(? AS regconfig), ?)', ['simple', "'receive'"]], [$simpleExactSql, $simpleExactParams], 'A3: wordTsQuery simple exact omits prefix wildcard');
	[$simplePrefixSql, $simplePrefixParams] = $a3Qb->wordTsQuery('receive', 'simple', null, true);
	same(['to_tsquery(CAST(? AS regconfig), ?)', ['simple', "'receive':*"]], [$simplePrefixSql, $simplePrefixParams], 'A3: wordTsQuery simple prefix keeps :*');

	$platform->indexDocument(doc('A3_B_RECV', 'bob', 'Parcel', 'We will receive the parcel tomorrow.', [], 'a3'));
	$platform->indexDocument(doc('A3_B_ACC', 'bob', 'Rapor', 'Yeni çözümleme raporu hazırlandı.', [], 'a3'));
	$platform->indexDocument(doc('A3_B_SPELL_ALPHA', 'bob', 'Protocol', 'Secret kryptonw protocol.', [], 'a3'));
	$platform->indexDocument(doc('A3_B_SPELL_DIST', 'bob', 'Blueprint', 'Secret zephyrkl blueprint.', [], 'a3'));
	$platform->indexDocument(doc('A3_B_STOP_DECOYS', 'bob', 'Decoys', 'wish chat form hate budget', [], 'a3'));
	$platform->indexDocument(doc('A3_B_STOP_REAL', 'bob', 'Real', 'Notes with details that came from friends who have budget.', [], 'a3'));

	// 1. Word-existence invariance: Alice privately indexing receivex/zeppeliny must not alter Bob's results.
	$a3BeforeRecv = search($platform, 'bob', 'receivex', $a3);
	$a3BeforeAbsent = search($platform, 'bob', 'zeppeliny', $a3);
	same(['A3_B_RECV'], $a3BeforeRecv['ids'], 'A3: Bob finds receive via receivex before Alice indexes receivex');
	same([], $a3BeforeAbsent['ids'], 'A3: Bob gets no results for zeppeliny before Alice indexes zeppeliny');

	$platform->indexDocument(doc('A3_A_RECVX', 'alice', 'Private', 'Private receivex draft and zeppeliny memo.', [], 'a3'));
	$a3AfterRecv = search($platform, 'bob', 'receivex', $a3);
	$a3AfterAbsent = search($platform, 'bob', 'zeppeliny', $a3);
	same($a3BeforeRecv['ids'], $a3AfterRecv['ids'], 'A3: word-existence invariance keeps Bob results for receivex identical');
	same($a3BeforeRecv['total'], $a3AfterRecv['total'], 'A3: word-existence invariance keeps Bob total for receivex identical');
	same($a3BeforeAbsent['ids'], $a3AfterAbsent['ids'], 'A3: word-existence invariance keeps Bob absent-word results identical');

	// 2. Crowding-out invariance (accent): 3+ and >12 shorter private variants must not displace Bob's variant.
	$platform->indexDocument(doc('A3_A_ACC3', 'alice', 'Accents 3', 'çözüma çözüme çözümü', [], 'a3'));
	same(['A3_B_ACC'], search($platform, 'bob', 'cozum', $a3)['ids'], 'A3: 3 shorter private accent variants do not crowd out Bob çözümleme');
	$platform->indexDocument(doc('A3_A_ACC15', 'alice', 'Accents 15', 'çözümb çözümc çözümd çözümf çözümg çözümh çözümi çözümj çözümüş çözümüşleri çözümk çözümm çözümn çözümo', [], 'a3'));
	same(['A3_B_ACC'], search($platform, 'bob', 'cozum', $a3)['ids'], 'A3: >12 shorter private accent variants do not crowd out Bob çözümleme');

	// 3. Crowding-out invariance (spelling): alphabetical & closer/20+ private variants must not displace Bob's correction.
	$platform->indexDocument(doc('A3_A_SPELL_ALPHA', 'alice', 'Alpha', 'kryptona kryptonb', [], 'a3'));
	same(['A3_B_SPELL_ALPHA'], search($platform, 'bob', 'kryptonx', $a3)['ids'], 'A3: alphabetically earlier private variants do not crowd out Bob kryptonw');
	$platform->indexDocument(doc('A3_A_SPELL_DIST2', 'alice', 'Dist 2', 'zephyrb zephyrc', [], 'a3'));
	same(['A3_B_SPELL_DIST'], search($platform, 'bob', 'zephyrx', $a3)['ids'], 'A3: closer 1-edit private variants do not crowd out Bob 2-edit zephyrkl');
	$zephyrMore = [];
	foreach (range('a', 'z') as $ch) {
		if (!in_array($ch, ['b', 'c', 'e', 'k', 's', 'x'], true)) {
			$zephyrMore[] = 'zephyr' . $ch;
		}
	}
	$platform->indexDocument(doc('A3_A_SPELL_DIST25', 'alice', 'Dist 25', implode(' ', $zephyrMore), [], 'a3'));
	same(['A3_B_SPELL_DIST'], search($platform, 'bob', 'zephyrx', $a3)['ids'], 'A3: >20 closer 1-edit private variants do not crowd out Bob 2-edit zephyrkl');

	// 4. 4+ letter stopwords must not trigger bogus spelling corrections in english or turkish,english.
	foreach (['with', 'that', 'from', 'have'] as $stopword) {
		same([], search($platform, 'bob', $stopword, $a3)['ids'], "A3: stopword '$stopword' does not trigger spelling correction in english");
	}
	$config->setConfig(['language' => 'turkish']);
	$platform->indexDocument(doc('A3_B_STOP_TR', 'bob', 'TR Decoys', 'wish chat form hate', [], 'a3'));
	$config->setConfig(['language' => 'turkish,english']);
	foreach (['with', 'that', 'from', 'have'] as $stopword) {
		same([], search($platform, 'bob', $stopword, $a3)['ids'], "A3: stopword '$stopword' does not trigger spelling correction in turkish,english");
	}
	$config->setConfig(['language' => 'english']);

	// 5. Contingent < size gating: spelling waits for < size uncorrected hits; accent variants stay unconditional.
	$platform->indexDocument(doc('A3_G_1', 'bob', 'Gate 1', 'gatekeeper alpha', [], 'a3'));
	$platform->indexDocument(doc('A3_G_2', 'bob', 'Gate 2', 'gatekeeper beta', [], 'a3'));
	$gatePageFull = search($platform, 'bob', 'gatekeeper kryptonx', $a3 + ['size' => 2]);
	same(2, $gatePageFull['total'], 'A3: spelling correction skipped when uncorrected first page reaches size');
	$gatePagePartial = search($platform, 'bob', 'gatekeeper kryptonx', $a3 + ['size' => 5]);
	same(3, $gatePagePartial['total'], 'A3: spelling correction runs when uncorrected first page has < size hits');
	$gateAccentFull = search($platform, 'bob', 'gatekeeper cozum', $a3 + ['size' => 2]);
	same(3, $gateAccentFull['total'], 'A3: accent variants remain active even when uncorrected hits reach size');
	$platform->resetIndex('a3');

	// 6. Stemmed-candidate, prefix-candidate, and typed-word prefix oracles must remain invariant.
	$savedOracleWords = $conn->executeQuery("SELECT word FROM ftspg_oc_words WHERE word LIKE 'receiv%' OR word LIKE 'zephyr%'")->fetchAll(PDO::FETCH_COLUMN);
	$conn->executeStatement("DELETE FROM ftspg_oc_words WHERE word LIKE 'receiv%' OR word LIKE 'zephyr%'");
	$platform->indexDocument(doc('A3_B_RECVING', 'bob', 'Shipments', 'We are receiving shipments today.', [], 'a3'));
	same([], search($platform, 'bob', 'receivex', $a3)['ids'], 'A3: Bob gets no match for receivex on receiving before Alice indexes receive');
	$platform->indexDocument(doc('A3_A_RECV_SECRET', 'alice', 'Secret', 'We will receive the secret merger.', [], 'a3'));
	same([], search($platform, 'bob', 'receivex', $a3)['ids'], 'A3: stemmed-candidate oracle does not leak Alice receive via Bob receiving');

	$platform->indexDocument(doc('A3_B_ZKL', 'bob', 'Bait', 'zephyrklmno', [], 'a3'));
	same([], search($platform, 'bob', 'zephyrx', $a3)['ids'], 'A3: Bob gets no match for zephyrx on zephyrklmno before Alice indexes zephyre');
	$platform->indexDocument(doc('A3_A_ZEPHYRE', 'alice', 'Secret Z', 'zephyre', [], 'a3'));
	same([], search($platform, 'bob', 'zephyrx', $a3)['ids'], 'A3: prefix-candidate oracle does not leak Alice zephyre via Bob zephyrklmno');

	$platform->resetIndex('a3');
	$conn->executeStatement("DELETE FROM ftspg_oc_words WHERE word LIKE 'receiv%'");
	$platform->indexDocument(doc('A3_B_RECVING2', 'bob', 'Shipments', 'We are receiving shipments', [], 'a3'));
	$platform->indexDocument(doc('A3_B_DECEIVE', 'bob', 'Clients', 'Do not deceive clients', [], 'a3'));
	$beforeTyped = search($platform, 'bob', 'receive', $a3);
	check(in_array('A3_B_DECEIVE', $beforeTyped['ids'], true), 'A3: typed-word prefix baseline includes deceive correction before Alice indexes receivexyz');
	$platform->indexDocument(doc('A3_A_RECVXYZ', 'alice', 'Secret R', 'receivexyz', [], 'a3'));
	$afterTyped = search($platform, 'bob', 'receive', $a3);
	same($beforeTyped['ids'], $afterTyped['ids'], 'A3: typed-word prefix oracle keeps Bob ids for receive identical');
	same($beforeTyped['total'], $afterTyped['total'], 'A3: typed-word prefix oracle keeps Bob total for receive identical');
	$platform->resetIndex('a3');
	// 7. limitFields stem isolation, Turkish I/İ surface verification, and 32+ duplicate tokens.
	$conn->executeStatement("DELETE FROM ftspg_oc_words WHERE word LIKE 'receiv%'");
	$platform->indexDocument(doc('A3_B_LIM', 'bob', 'Receive in title', 'We are receiving shipments.', [], 'a3'));
	$platform->indexDocument(doc('A3_A_LIM', 'alice', 'Private', 'We will receive secret.', [], 'a3'));
	same([], search($platform, 'bob', 'receivex', $a3 + ['limit' => ['content']])['ids'], 'A3: limitFields=[content] does not leak Alice content receive via Bob content receiving or title receive');
	same(['A3_B_LIM'], search($platform, 'bob', 'receivex', $a3 + ['limit' => ['title']])['ids'], 'A3: limitFields=[title] still finds Bob own title receive');

	$platform->resetIndex('a3');
	$conn->executeStatement("DELETE FROM ftspg_oc_words WHERE word IN ('ısık', 'isik', 'ısıklar')");
	$config->setConfig(['language' => 'turkish']);
	$platform->indexDocument(doc('A3_TR_B_ISIKLAR', 'bob', 'Rapor', 'ISIKLAR YANDI', [], 'a3'));
	same([], search($platform, 'bob', 'ISIKX', $a3)['ids'], 'A3: Turkish ISIKX does not match ISIKLAR before Alice indexes ISIK');
	$platform->indexDocument(doc('A3_TR_A_ISIK', 'alice', 'Gizli', 'ISIK', [], 'a3'));
	same([], search($platform, 'bob', 'ISIKX', $a3)['ids'], 'A3: Turkish surface verification prevents Alice ISIK leak via Bob ISIKLAR');
	$platform->indexDocument(doc('A3_TR_B_ISIK', 'bob', 'Acik', 'ISIK', [], 'a3'));
	same(['A3_TR_B_ISIK', 'A3_TR_B_ISIKLAR'], sorted(search($platform, 'bob', 'ISIKX', $a3)['ids']), 'A3: Turkish ISIKX finds Bob own uppercase ISIK via surface I->ı');
	$config->setConfig(['language' => 'english']);

	same([], search($platform, 'bob', str_repeat('qqmissing ', 32) . 'kryptonx', $a3)['ids'], 'A3: 32+ duplicate missing tokens execute cleanly');
	$platform->resetIndex('a3');

	// 8. Non-alpha 'simple' tokens (host, file, email, numword, >40 chars) must not leak via typed-word prefix probe.
	$nonAlphaCases = [
		'host content' => ['content' => 'See receive.pdf here.', 'limit' => []],
		'host tags' => ['tags' => ['receive.pdf'], 'limit' => ['tags']],
		'host parts' => ['parts' => ['comments' => 'See receive.pdf'], 'limit' => ['parts']],
		'file content' => ['content' => 'Open receive/draft today.', 'limit' => ['content']],
		'numword content' => ['content' => 'Ticket receive123 closed.', 'limit' => ['content']],
		'email content' => ['content' => 'Mail receive@example.com now.', 'limit' => ['content']],
		'>40-char content' => ['content' => 'Long receivereceivereceivereceivereceivereceive token.', 'limit' => ['content']],
	];
	foreach ($nonAlphaCases as $label => $case) {
		$platform->resetIndex('a3');
		$conn->executeStatement("DELETE FROM ftspg_oc_words WHERE word LIKE 'receiv%'");
		$platform->indexDocument(doc('A3_B_COMP', 'bob', 'Neutral Doc', $case['content'] ?? 'Neutral body', [
			'tags' => $case['tags'] ?? [],
			'parts' => $case['parts'] ?? [],
		], 'a3'));
		$platform->indexDocument(doc('A3_B_DEC', 'bob', 'Clients', 'Do not deceive clients', [
			'tags' => ['deceive'],
			'parts' => ['comments' => 'Do not deceive clients'],
		], 'a3'));
		$opts = $a3 + ($case['limit'] !== [] ? ['limit' => $case['limit']] : []);
		$before2b = search($platform, 'bob', 'receive', $opts);
		check(in_array('A3_B_DEC', $before2b['ids'], true), "A3: 2b ($label) baseline includes deceive before Alice");
		$platform->indexDocument(doc('A3_A_RECVXYZ_2B', 'alice', 'Private', 'receivexyz', [], 'a3'));
		$after2b = search($platform, 'bob', 'receive', $opts);
		same($before2b['ids'], $after2b['ids'], "A3: 2b ($label) keeps Bob ids identical");
		same($before2b['total'], $after2b['total'], "A3: 2b ($label) keeps Bob total identical");
	}
	$platform->resetIndex('a3');
	foreach ($savedOracleWords as $savedWord) {
		$conn->executeStatement('INSERT INTO ftspg_oc_words (word) VALUES (?) ON CONFLICT (word) DO NOTHING', [(string)$savedWord]);
	}

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
	$repairInfos = [];
	$repairWarnings = [];
	$repairOutput = double(\OCP\Migration\IOutput::class, [
		'advance' => function (int $step = 1) use (&$progress) {
			$progress += $step;
		},
		'info' => function (string $message) use (&$repairInfos) {
			$repairInfos[] = $message;
		},
		'warning' => function (string $message) use (&$repairWarnings) {
			$repairWarnings[] = $message;
		},
	]);
	(new CreateIndexTable($schema, $vocabulary, $config))->run($repairOutput);
	check($vocabulary->count() > 20, 'upgrade step fills an empty vocabulary on small instances');
	same($schema->countDocuments(), $progress, 'and reports progress per document');
	same(false, stripos(implode("\n", array_merge($repairInfos, $repairWarnings)), 'lock') !== false, 'repair step omits lock notice when schema is already current');

	section('upgrading a 1.1.x table in place');
	$t = $schema->getTableName();
	$conn->executeStatement("DROP INDEX IF EXISTS {$t}_title_search_trgm_idx");
	$conn->executeStatement("ALTER TABLE $t DROP COLUMN title_search, DROP COLUMN tsv");
	$conn->executeStatement("ALTER TABLE $t ALTER COLUMN config TYPE regconfig USING config::regconfig");
	$conn->executeStatement("ALTER TABLE $t ADD COLUMN tsv tsvector GENERATED ALWAYS AS (to_tsvector(config, title)) STORED");
	if ($schema->hasTrigram()) {
		$conn->executeStatement("CREATE INDEX {$t}_title_trgm_idx ON $t USING GIN (title gin_trgm_ops)");
	}
	same(false, $schema->isCurrent(), '1.1.x schema detected as outdated');
	$expectedRowCount = $schema->countDocuments();
	check($expectedRowCount > 0, 'existing rows present before upgrade');
	$loadThrew = false;
	$loadError = '';
	try {
		$platform->loadPlatform();
	} catch (\RuntimeException $e) {
		$loadThrew = true;
		$loadError = $e->getMessage();
	}
	same(true, $loadThrew, 'loadPlatform refuses to upgrade an existing outdated table');
	check(str_contains($loadError, 'occ upgrade') && str_contains($loadError, 'occ maintenance:repair'), 'loadPlatform error directs admin to occ upgrade / occ maintenance:repair');
	same(false, $schema->isCurrent(), 'loadPlatform leaves outdated schema untouched');
	$colsAfterLoad = $conn->executeQuery(
		'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?',
		[$t]
	)->fetchAll(PDO::FETCH_COLUMN);
	same(false, in_array('title_search', $colsAfterLoad, true), 'loadPlatform leaves 1.1.x columns unchanged');
	if ($schema->hasTrigram()) {
		$idxAfterLoad = $conn->executeQuery('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$t])->fetchAll(PDO::FETCH_COLUMN);
		same(true, in_array("{$t}_title_trgm_idx", $idxAfterLoad, true), 'loadPlatform leaves 1.1.x trigram index unchanged');
	}
	$upgradeInfos = [];
	$upgradeWarnings = [];
	$upgradeOutput = double(\OCP\Migration\IOutput::class, [
		'info' => function (string $message) use (&$upgradeInfos) {
			$upgradeInfos[] = $message;
		},
		'warning' => function (string $message) use (&$upgradeWarnings) {
			$upgradeWarnings[] = $message;
		},
	]);
	(new CreateIndexTable($schema, $vocabulary, $config))->run($upgradeOutput);
	same(true, $schema->isCurrent(), 'CreateIndexTable upgrades the 1.1.x table');
	$upgradeNotice = implode("\n", array_merge($upgradeInfos, $upgradeWarnings));
	check(str_contains($upgradeNotice, (string)$expectedRowCount), 'repair step prints document row count when upgrading outdated table');
	check(stripos($upgradeNotice, 'lock') !== false, 'repair step prints index-table lock notice when upgrading outdated table');
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
		"EXPLAIN SELECT id FROM ftspg_oc_index d WHERE ((d.config <> ALL ('{english}'::text[]) AND d.tsv @@ to_tsquery('turkish', 'kitap:*'))"
		. " OR (d.config = 'english' AND d.tsv @@ to_tsquery('english', 'study:*'))) AND d.access && '{u:alice}'::text[]"
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

	$conn->log = [];
	$a5Plan1 = search($platform, 'alice', '+the +lantern', ['provider' => 'plan']);
	same(400, $a5Plan1['total'], 'A5 planner: +the +lantern finds all 400 lantern docs');
	$plan = $planOf('WITH q AS');
	check(str_contains($plan, 'ftspg_oc_index_tsv_idx') && !str_contains($plan, 'Seq Scan on ftspg_oc_index'), 'A5 planner: +the +lantern uses the GIN index');

	$conn->log = [];
	$a5Plan2 = search($platform, 'alice', 'lantern for meadow', ['provider' => 'plan']);
	same(677, $a5Plan2['total'], 'A5 planner: lantern for meadow finds all 677 docs');
	$plan = $planOf('WITH q AS');
	check(str_contains($plan, 'ftspg_oc_index_tsv_idx') && !str_contains($plan, 'Seq Scan on ftspg_oc_index'), 'A5 planner: lantern for meadow uses the GIN index');

	$conn->log = [];
	$a5Plan3 = search($platform, 'alice', '+lantern +for +meadow', ['provider' => 'plan']);
	same(30, $a5Plan3['total'], 'A5 planner: +lantern +for +meadow finds all 30 docs with both words');
	$plan = $planOf('WITH q AS');
	check(str_contains($plan, 'ftspg_oc_index_tsv_idx') && !str_contains($plan, 'Seq Scan on ftspg_oc_index'), 'A5 planner: +lantern +for +meadow uses the GIN index');

	$conn->executeStatement("INSERT INTO ftspg_oc_words (word) VALUES ('thé') ON CONFLICT (word) DO NOTHING");
	$conn->log = [];
	$a5Plan4 = search($platform, 'alice', '+the +lantern', ['provider' => 'plan']);
	same(400, $a5Plan4['total'], 'A5 planner: +the +lantern finds all 400 lantern docs with accented stopword homograph in vocabulary');
	$plan = $planOf('WITH q AS');
	check(str_contains($plan, 'ftspg_oc_index_tsv_idx') && !str_contains($plan, 'Seq Scan on ftspg_oc_index'), 'A5 planner: +the +lantern uses the GIN index with accented stopword homograph in vocabulary');
	$conn->executeStatement("DELETE FROM ftspg_oc_words WHERE word = 'thé'");
	same(['on', '4', 'on'], array_values($conn->executeQuery("SELECT current_setting('enable_seqscan'), current_setting('random_page_cost'), current_setting('jit')")->fetch(PDO::FETCH_NUM)), 'the settings only applied to the search');
	same(false, $conn->inTransaction(), 'and its transaction is closed');
	$conn->beginTransaction();
	$conn->executeStatement('SET LOCAL random_page_cost = 0.9');
	$conn->executeStatement('SET LOCAL pg_trgm.similarity_threshold = 0.55');
	search($platform, 'alice', 'lantern', ['provider' => 'plan']);
	same(['on', '0.9', '0.55'], array_values($conn->executeQuery("SELECT current_setting('enable_seqscan'), current_setting('random_page_cost'), current_setting('pg_trgm.similarity_threshold')")->fetch(PDO::FETCH_NUM)), 'inside a caller\'s transaction, its settings including pg_trgm.similarity_threshold are put back');
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

	section('search statement timeout and cancellation (A1)');
	same(10, $config->getSearchTimeout(), 'ConfigService::getSearchTimeout() default is 10');
	$config->setConfig(['search_timeout' => 5]);
	same(5, $config->getSearchTimeout(), 'ConfigService::getSearchTimeout() returns configured value');
	same(5, $config->getConfig()['search_timeout'], 'getConfig() includes search_timeout');
	try {
		$config->setConfig(['search_timeout' => -1]);
		check(false, 'negative search_timeout rejected');
	} catch (\InvalidArgumentException) {
		check(true, 'negative search_timeout rejected');
	}
	try {
		$config->setConfig(['search_timeout' => 301]);
		check(false, 'search_timeout > 300 rejected');
	} catch (\InvalidArgumentException) {
		check(true, 'search_timeout > 300 rejected');
	}
	$appConfig->setValueInt('fulltextsearch_pgsql', 'search_timeout', -10);
	same(0, $config->getSearchTimeout(), 'getSearchTimeout() clamps negative value to 0');
	$appConfig->setValueInt('fulltextsearch_pgsql', 'search_timeout', 999);
	same(300, $config->getSearchTimeout(), 'getSearchTimeout() clamps excessive value to 300');

	// Fast search within timeout completes and timedOut is false
	$config->setConfig(['search_timeout' => 10]);
	$fastResult = search($platform, 'alice', 'budget');
	same(false, $fastResult['timedOut'], 'fast search within timeout completes and timedOut is false');

	// Search inside active caller transaction does not override caller statement_timeout
	$conn->beginTransaction();
	$conn->executeStatement("SET LOCAL statement_timeout = '42s'");
	$callerSearch = search($platform, 'alice', 'budget');
	same('42s', (string)$conn->executeQuery('SHOW statement_timeout')->fetchOne(), 'search inside active caller transaction does not override caller statement_timeout');
	$conn->rollBack();

	// Exclusive table lock with search_timeout = 1 causes query cancellation (SQLSTATE 57014)
	$conn2 = new PDO($dsn);
	$conn2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$config->setConfig(['search_timeout' => 1]);
	$conn2->beginTransaction();
	$conn2->exec('LOCK TABLE ftspg_oc_index IN ACCESS EXCLUSIVE MODE');
	$timedSearch = search($platform, 'alice', 'budget');
	$conn2->rollBack();
	same(true, $timedSearch['timedOut'], 'exclusive table lock with search_timeout = 1 causes query cancellation (timedOut is true)');
	same(0, $timedSearch['total'], 'timed out search has total 0');
	same([], $timedSearch['ids'], 'timed out search returns empty ids');

	// Statement timeout during spelling correction returns timed-out result without transaction-aborted RESET errors
	$config->setConfig(['search_timeout' => 1, 'typo_correction' => true]);
	$conn2->beginTransaction();
	$conn2->exec('LOCK TABLE ftspg_oc_words IN ACCESS EXCLUSIVE MODE');
	$timedCorrection = search($platform, 'alice', 'budgett');
	$conn2->rollBack();
	same(true, $timedCorrection['timedOut'], 'statement timeout during spelling correction returns timed out result');
	same(0, $timedCorrection['total'], 'spelling correction timeout has total 0');

	// Restore normal timeout
	$config->setConfig(['search_timeout' => 10]);

	section('search robustness: planner tx safety, past-end paging, multi-page title fallback (A4)');
	$config->setConfig(['language' => 'english', 'typo_correction' => true, 'search_timeout' => 10]);

	// 1. withSearchPlanning() failure during settings read or set rolls back own transaction
	$plannerFailState = new class {
		public string $mode = '';
	};
	$plannerFailProxy = new class($conn, $plannerFailState) implements \OCP\IDBConnection {
		public function __construct(
			private \OCP\IDBConnection $inner,
			private object $state,
		) {
		}
		public function executeStatement($sql, array $params = [], array $types = []): int {
			return $this->inner->executeStatement($sql, $params, $types);
		}
		public function executeQuery(string $sql, array $params = [], $types = []): \OCP\DB\IResult {
			if ($this->state->mode === 'read' && str_contains($sql, "current_setting('enable_seqscan')")) {
				throw new \RuntimeException('injected planner settings read failure');
			}
			if ($this->state->mode === 'set' && str_contains($sql, "set_config('enable_seqscan', 'off', true)")) {
				throw new \RuntimeException('injected planner settings set failure');
			}
			if ($this->state->mode === 'set_timeout' && str_contains($sql, "set_config('enable_seqscan', 'off', true)")) {
				$e = new \PDOException('SQLSTATE[57014]: Query canceled: canceling statement due to statement timeout', 57014);
				$e->errorInfo = ['57014', 7, 'canceling statement due to statement timeout'];
				throw $e;
			}
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
	$plannerFailSearch = new SearchService($plannerFailProxy, $schema, $config, new TsQueryBuilder(), $vocabulary);
	$plannerFailPlatform = new PostgreSQLPlatform($config, $schema, $indexService, $plannerFailSearch, $logger, $extractor);

	foreach (['read', 'set'] as $failMode) {
		$plannerFailState->mode = $failMode;
		$threw = false;
		$txAfterFail = true;
		try {
			search($plannerFailPlatform, 'alice', 'budget');
		} catch (\RuntimeException) {
			$threw = true;
		} finally {
			$plannerFailState->mode = '';
			$txAfterFail = $conn->inTransaction();
			if ($conn->inTransaction()) {
				$conn->rollBack();
			}
		}
		check($threw, "A4: withSearchPlanning() '$failMode' failure propagates exception");
		same(false, $txAfterFail, "A4: withSearchPlanning() '$failMode' failure rolls back own transaction");
	}

	$plannerFailState->mode = 'set_timeout';
	$txAfterTimeoutSet = true;
	try {
		$setTimeoutRes = search($plannerFailPlatform, 'alice', 'budget');
	} finally {
		$plannerFailState->mode = '';
		$txAfterTimeoutSet = $conn->inTransaction();
		if ($conn->inTransaction()) {
			$conn->rollBack();
		}
	}
	same(true, $setTimeoutRes['timedOut'], 'A4: SQLSTATE 57014 on set_config reports timedOut');
	same(0, $setTimeoutRes['total'], 'A4: SQLSTATE 57014 on set_config reports total 0');
	same(false, $txAfterTimeoutSet, 'A4: SQLSTATE 57014 on set_config rolls back own transaction');
	same(1, (int)$conn->executeQuery('SELECT 1')->fetchOne(), 'A4: connection remains usable after withSearchPlanning() rollback');

	$conn->beginTransaction();
	$plannerFailState->mode = 'read';
	$callerReadThrew = false;
	$txAfterCallerFail = false;
	try {
		search($plannerFailPlatform, 'alice', 'budget');
	} catch (\RuntimeException) {
		$callerReadThrew = true;
	} finally {
		$plannerFailState->mode = '';
		$txAfterCallerFail = $conn->inTransaction();
		if ($conn->inTransaction()) {
			$conn->rollBack();
		}
	}
	check($callerReadThrew, 'A4: withSearchPlanning() read failure inside caller tx propagates exception');
	same(true, $txAfterCallerFail, 'A4: withSearchPlanning() read failure inside caller tx leaves caller tx open');

	// 2. Past-end page of exact search preserves total
	$platform->indexDocument(doc('A4_EXACT1', 'alice', 'Albatross report', 'albatross migration', [], 'a4'));
	$a4PastEnd = search($platform, 'alice', 'albatross', ['provider' => 'a4', 'size' => 2, 'page' => 5]);
	same([], $a4PastEnd['ids'], 'A4: past-end page of 1-result exact search returns empty ids');
	same(1, $a4PastEnd['total'], 'A4: past-end page of 1-result exact search preserves total 1');
	$a4PastEndZero = search($platform, 'alice', 'nonexistentxyz', ['provider' => 'a4', 'size' => 2, 'page' => 5]);
	same([], $a4PastEndZero['ids'], 'A4: past-end page of 0-result search returns empty ids');
	same(0, $a4PastEndZero['total'], 'A4: past-end page of 0-result search reports total 0');

	// 3. Multi-page title fallback (including past-end and A2/limit guards)
	$platform->indexDocument(doc('A4_TF1', 'alice', 'qxzflamengoalpha', 'unrelated alpha body', ['mtime' => 1000], 'a4'));
	$platform->indexDocument(doc('A4_TF2', 'alice', 'qxzflamengobeta', 'unrelated beta body', ['mtime' => 2000], 'a4'));
	$tfP1 = search($platform, 'alice', 'qxzflamenga', ['provider' => 'a4', 'size' => 1, 'page' => 1]);
	$tfP2 = search($platform, 'alice', 'qxzflamenga', ['provider' => 'a4', 'size' => 1, 'page' => 2]);
	$tfP5 = search($platform, 'alice', 'qxzflamenga', ['provider' => 'a4', 'size' => 1, 'page' => 5]);
	same(1, count($tfP1['ids']), 'A4: title fallback page 1 returns 1 result');
	same(2, $tfP1['total'], 'A4: title fallback page 1 reports total 2');
	same(1, count($tfP2['ids']), 'A4: title fallback page 2 returns 1 result');
	same(2, $tfP2['total'], 'A4: title fallback page 2 reports total 2');
	same(['A4_TF1', 'A4_TF2'], sorted([$tfP1['ids'][0] ?? '', $tfP2['ids'][0] ?? '']), 'A4: title fallback page 1 and page 2 cover both results without overlap');
	same([], $tfP5['ids'], 'A4: title fallback past-end page returns empty ids');
	same(2, $tfP5['total'], 'A4: title fallback past-end page preserves total 2');
	same(0, search($platform, 'alice', 'qxzflamenga -alpha', ['provider' => 'a4', 'size' => 1, 'page' => 2])['total'], 'A4: title fallback on page 2 respects A2 exclusion plainWordsOnly');
	same(0, search($platform, 'alice', '+qxzflamenga', ['provider' => 'a4', 'size' => 1, 'page' => 2])['total'], 'A4: title fallback on page 2 respects A2 required plainWordsOnly');
	same(0, search($platform, 'alice', '"qxzflamenga"', ['provider' => 'a4', 'size' => 1, 'page' => 2])['total'], 'A4: title fallback on page 2 respects A2 phrase plainWordsOnly');
	same(0, search($platform, 'alice', 'qxzflamenga', ['provider' => 'a4', 'size' => 1, 'page' => 2, 'limit' => ['content']])['total'], 'A4: title fallback on page 2 respects limit=content ($titlesSearched)');
	$platform->resetIndex('a4');
	$config->setConfig(['language' => 'turkish']);

	section('extraction and vocabulary limits (B5)');
	$b5ToolDir = sys_get_temp_dir() . '/ftspg_b5_tool_' . mt_rand();
	mkdir($b5ToolDir);
	$b5HangDir = $b5ToolDir . '/hang';
	$b5StderrDir = $b5ToolDir . '/stderr';
	mkdir($b5HangDir);
	mkdir($b5StderrDir);
	$b5HangTool = $b5HangDir . '/pdftotext';
	$b5StderrTool = $b5StderrDir . '/pdftotext';
	try {
		$config->setConfig(['language' => 'english', 'typo_correction' => true]);

		// B5.1a: tool ignoring SIGTERM after hitting output limit
		file_put_contents($b5HangTool, "#!/bin/sh\nif [ \"\$1\" = \"-v\" ]; then echo \"pdftotext version 24.0\"; exit 0; fi\ntrap '' TERM\nprintf 'hangmarker '\nhead -c 2500 /dev/zero | tr '\\0' 'a'\nexec sleep 5\n");
		chmod($b5HangTool, 0755);
		$config->setConfig(['max_content_size' => 1000, 'pdf_extractor' => 'pdftotext', 'pdftotext_path' => $b5HangTool]);
		$runnerLog = [];
		$t0 = microtime(true);
		$platform->indexDocument(doc('B5_TOOL_HANG', 'alice', 'Hang.pdf', $b64("%PDF-1.4\nfake"), ['encoded' => IIndexDocument::ENCODED_BASE64]));
		$elapsed = microtime(true) - $t0;
		check($elapsed < 2.0, sprintf('B5.1a: output-capped tool ignoring SIGTERM terminated quickly (%.2fs)', $elapsed));
		same('ok', $runnerLog['B5_TOOL_HANG'][0] ?? '', 'B5.1a: output-capped tool is not flagged as timed out');
		same(['B5_TOOL_HANG'], search($platform, 'alice', 'hangmarker')['ids'], 'B5.1a: output before limit is indexed');

		// B5.1b: >128 KB stderr flood before stdout
		file_put_contents($b5StderrTool, "#!/bin/sh\nif [ \"\$1\" = \"-v\" ]; then echo \"pdftotext version 24.0\"; exit 0; fi\nhead -c 140000 /dev/zero | tr '\\0' 'e' >&2\necho \"betaword stderr flood recovered\"\nexit 0\n");
		chmod($b5StderrTool, 0755);
		$config->setConfig(['max_content_size' => 512000, 'pdf_extractor' => 'pdftotext', 'pdftotext_path' => $b5StderrTool]);
		$runnerLog = [];
		$t0 = microtime(true);
		$platform->indexDocument(doc('B5_STDERR_FLOOD', 'alice', 'Stderr.pdf', $b64("%PDF-1.4\nfake"), ['encoded' => IIndexDocument::ENCODED_BASE64]));
		$elapsed = microtime(true) - $t0;
		check($elapsed < 2.0, sprintf('B5.1b: stderr flood (>128 KB) drained without deadlock (%.2fs)', $elapsed));
		same('ok', $runnerLog['B5_STDERR_FLOOD'][0] ?? '', 'B5.1b: stderr flood succeeds without warning');
		same(['B5_STDERR_FLOOD'], search($platform, 'alice', 'betaword')['ids'], 'B5.1b: stdout after stderr flood is indexed');

		$config->setConfig(['max_content_size' => 512000, 'pdf_extractor' => 'auto', 'pdftotext_path' => '']);

		// B5.2a: 3,000-sheet xlsx hitting 2,000-entry cap
		$files = ['xl/workbook.xml' => "<workbook $s><sheets><sheet name=\"BigBook\" sheetId=\"1\"/></sheets></workbook>"];
		for ($i = 1; $i <= 3000; $i++) {
			$files["xl/worksheets/sheet{$i}.xml"] = "<worksheet $s><sheetData><row><c t=\"inlineStr\"><is><t>sheettoken{$i}marker</t></is></c></row></sheetData></worksheet>";
		}
		$runnerLog = [];
		$office('B5_XLSX_ENTRIES', 'sheets3000.xlsx', $files);
		unset($files);
		same('warning', $runnerLog['B5_XLSX_ENTRIES'][0] ?? '', 'B5.2a: 3000-sheet xlsx emits partial-index warning');
		check(str_contains($runnerLog['B5_XLSX_ENTRIES'][1] ?? '', 'partially indexed'), 'B5.2a: warning mentions partially indexed');
		same(['B5_XLSX_ENTRIES'], search($platform, 'alice', 'sheettoken1marker')['ids'], 'B5.2a: sheet 1 is indexed');
		same(['B5_XLSX_ENTRIES'], search($platform, 'alice', 'sheettoken1999marker')['ids'], 'B5.2a: sheet 1999 (2000th entry) is indexed');
		same([], search($platform, 'alice', 'sheettoken2000marker')['ids'], 'B5.2a: sheet 2000 (2001st entry) is skipped');
		same([], search($platform, 'alice', 'sheettoken3000marker')['ids'], 'B5.2a: sheet 3000 is skipped');

		// B5.2b: >128 MB uncompressed XML docx hitting 128 MB total cap
		$conn->log = [];
		$conn->statements = [];
		$b5ZipDir = $b5ToolDir . '/docx';
		mkdir($b5ZipDir);
		$chunk = '<w:delText>' . str_repeat('a', 100000) . '</w:delText>';
		$writeEntry = static function (string $path, string $head, string $tail) use ($chunk): void {
			$fh = fopen($path, 'wb');
			if ($fh === false) {
				throw new \RuntimeException("Cannot open $path");
			}
			fwrite($fh, $head);
			for ($i = 0; $i < 315; $i++) {
				fwrite($fh, $chunk);
			}
			fwrite($fh, $tail);
			fclose($fh);
		};
		$entryPaths = [
			'word/document.xml' => [$b5ZipDir . '/document.xml', "<w:document $w><w:body><w:p><w:r><w:t>docentryonemarker</w:t></w:r></w:p>", '</w:body></w:document>'],
			'word/header1.xml' => [$b5ZipDir . '/header1.xml', "<w:hdr $w><w:p><w:r><w:t>headeronemarker</w:t></w:r></w:p>", '</w:hdr>'],
			'word/header2.xml' => [$b5ZipDir . '/header2.xml', "<w:hdr $w><w:p><w:r><w:t>headertwomarker</w:t></w:r></w:p>", '</w:hdr>'],
			'word/header3.xml' => [$b5ZipDir . '/header3.xml', "<w:hdr $w><w:p><w:r><w:t>headerthreemarker</w:t></w:r></w:p>", '</w:hdr>'],
			'word/header4.xml' => [$b5ZipDir . '/header4.xml', "<w:hdr $w>", '<w:p><w:r><w:t>headerfourtailmarker</w:t></w:r></w:p></w:hdr>'],
		];
		$tmpDocx = $b5ZipDir . '/huge.docx';
		$zip = new ZipArchive();
		$zip->open($tmpDocx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach ($entryPaths as $entryName => [$filePath, $head, $tail]) {
			$writeEntry($filePath, $head, $tail);
			$zip->addFile($filePath, $entryName);
		}
		$zip->addFromString('word/header5.xml', "<w:hdr $w><w:p><w:r><w:t>headerfivemarker</w:t></w:r></w:p></w:hdr>");
		$zip->close();
		$docxZip = (string)file_get_contents($tmpDocx);
		foreach ($entryPaths as [$filePath]) {
			@unlink($filePath);
		}
		@unlink($tmpDocx);
		@rmdir($b5ZipDir);
		unset($chunk, $entryPaths);
		$runnerLog = [];
		$platform->indexDocument(doc('B5_DOCX_BYTES', 'alice', 'huge.docx', $b64($docxZip), ['encoded' => IIndexDocument::ENCODED_BASE64]));
		unset($docxZip);
		same('warning', $runnerLog['B5_DOCX_BYTES'][0] ?? '', 'B5.2b: >128 MB xml docx emits partial-index warning');
		check(str_contains($runnerLog['B5_DOCX_BYTES'][1] ?? '', 'partially indexed'), 'B5.2b: warning mentions partially indexed');
		same(['B5_DOCX_BYTES'], search($platform, 'alice', 'docentryonemarker')['ids'], 'B5.2b: first entry under 128 MB is indexed');
		same(['B5_DOCX_BYTES'], search($platform, 'alice', 'headerthreemarker')['ids'], 'B5.2b: 4th entry under 128 MB is indexed');
		same([], search($platform, 'alice', 'headerfourtailmarker')['ids'], 'B5.2b: entry tail past 128 MB cap is not indexed');
		same([], search($platform, 'alice', 'headerfivemarker')['ids'], 'B5.2b: 6th entry after 128 MB cap is skipped');

		// B5.3a: ODF mid-sentence annotation & note without word fusion
		$office('B5_ODF_NOTES', 'notes.odt', [
			'mimetype' => 'application/vnd.oasis.opendocument.text',
			'content.xml' => "<office:document-content $o><office:body><office:text>"
				. '<text:p>odfkitap<text:span>lık</text:span> odfkalem<office:annotation><dc:creator>ZeynepNotator</dc:creator><dc:date>1999-12-31</dc:date><text:p>odfyorum odfmartı</text:p></office:annotation>odfdefter</text:p>'
				. '<text:p>odfelma<text:note text:note-class="footnote"><text:note-citation>odfcite</text:note-citation><text:note-body><text:p>Dipnot odfkumru</text:p></text:note-body></text:note>odfarmut</text:p>'
				. '</office:text></office:body></office:document-content>',
		]);
		same(['B5_ODF_NOTES'], search($platform, 'alice', 'odfkitaplık')['ids'], 'B5.3a: <text:span> still leaves words joined');
		same(['B5_ODF_NOTES'], search($platform, 'alice', 'odfkalem')['ids'], 'B5.3a: word before mid-sentence annotation is searchable');
		same(['B5_ODF_NOTES'], search($platform, 'alice', 'odfyorum')['ids'], 'B5.3a: first word inside annotation is separately searchable');
		same(['B5_ODF_NOTES'], search($platform, 'alice', 'odfmartı')['ids'], 'B5.3a: annotation body is searchable');
		same(['B5_ODF_NOTES'], search($platform, 'alice', 'odfdefter')['ids'], 'B5.3a: word after mid-sentence annotation is searchable');
		same([], search($platform, 'alice', 'odfkalemodfyorum')['ids'], 'B5.3a: word before annotation does not fuse with annotation text');
		same(['B5_ODF_NOTES'], search($platform, 'alice', 'odfelma')['ids'], 'B5.3a: word before mid-sentence note is searchable');
		same(['B5_ODF_NOTES'], search($platform, 'alice', 'odfcite')['ids'], 'B5.3a: note citation is separately searchable');
		same(['B5_ODF_NOTES'], search($platform, 'alice', 'odfkumru')['ids'], 'B5.3a: note body is searchable');
		same(['B5_ODF_NOTES'], search($platform, 'alice', 'odfarmut')['ids'], 'B5.3a: word after mid-sentence note is searchable');
		same([], search($platform, 'alice', 'odfelmaodfcite')['ids'], 'B5.3a: word before note does not fuse with note citation');
		same([], search($platform, 'alice', 'zeynepnotator')['ids'], 'B5.3a: annotation dc:creator is skipped');
		same([], search($platform, 'alice', '1999')['ids'], 'B5.3a: annotation dc:date is skipped');
		$odfNotesContent = (string)$conn->executeQuery("SELECT content FROM ftspg_oc_index WHERE document_id = 'B5_ODF_NOTES'")->fetchOne();
		check(str_contains($odfNotesContent, 'odfkitaplık'), 'B5.3a: stored content preserves span joining');
		check(str_contains($odfNotesContent, 'odfkalem odfyorum odfmartı odfdefter'), 'B5.3a: stored content separates annotation cleanly');
		check(str_contains($odfNotesContent, 'odfelma odfcite Dipnot odfkumru odfarmut'), 'B5.3a: stored content separates note cleanly');

		// B5.3b: ODF styles.xml headers/footers without style metadata leak, ordered after content.xml
		$office('B5_ODF_STYLES', 'headers.odt', [
			'mimetype' => 'application/vnd.oasis.opendocument.text',
			'content.xml' => "<office:document-content $o><office:body><office:text><text:p>OdfBodyMetni</text:p></office:text></office:body></office:document-content>",
			'styles.xml' => "<office:document-styles $o xmlns:style=\"urn:oasis:names:tc:opendocument:xmlns:style:1.0\">"
				. '<office:font-face-decls><style:font-face style:name="Sans"/></office:font-face-decls>'
				. '<office:styles><style:style style:name="Header"><text:p>StyleLeakShouldNotIndex</text:p></style:style></office:styles>'
				. '<office:automatic-styles><style:page-layout style:name="pm1"><text:p>AutoStyleLeakShouldNotIndex</text:p></style:page-layout></office:automatic-styles>'
				. '<office:master-styles><style:master-page style:name="Standard">'
				. '<style:header><text:p>Üstbilgi odfheadflamingo</text:p></style:header>'
				. '<style:header-left><text:p>Solüstbilgi odfleftibis</text:p></style:header-left>'
				. '<style:header-first><text:p>İlküstbilgi odffirstpelikan</text:p></style:header-first>'
				. '<style:footer><text:p>Altbilgi odffootturna</text:p></style:footer>'
				. '<style:footer-left><text:p>Solaltbilgi odffootleftleylek</text:p></style:footer-left>'
				. '<style:footer-first><text:p>İlkaltbilgi odffootfirstbalıkçıl</text:p></style:footer-first>'
				. '</style:master-page></office:master-styles>'
				. '</office:document-styles>',
		]);
		same(['B5_ODF_STYLES'], search($platform, 'alice', 'odfheadflamingo')['ids'], 'B5.3b: styles.xml header indexed');
		same(['B5_ODF_STYLES'], search($platform, 'alice', 'odfleftibis')['ids'], 'B5.3b: styles.xml header-left indexed');
		same(['B5_ODF_STYLES'], search($platform, 'alice', 'odffirstpelikan')['ids'], 'B5.3b: styles.xml header-first indexed');
		same(['B5_ODF_STYLES'], search($platform, 'alice', 'odffootturna')['ids'], 'B5.3b: styles.xml footer indexed');
		same(['B5_ODF_STYLES'], search($platform, 'alice', 'odffootleftleylek')['ids'], 'B5.3b: styles.xml footer-left indexed');
		same(['B5_ODF_STYLES'], search($platform, 'alice', 'odffootfirstbalıkçıl')['ids'], 'B5.3b: styles.xml footer-first indexed');
		same([], search($platform, 'alice', 'styleleakshouldnotindex')['ids'], 'B5.3b: office:styles metadata not indexed');
		same([], search($platform, 'alice', 'autostyleleakshouldnotindex')['ids'], 'B5.3b: office:automatic-styles metadata not indexed');
		$odfStylesContent = (string)$conn->executeQuery("SELECT content FROM ftspg_oc_index WHERE document_id = 'B5_ODF_STYLES'")->fetchOne();
		$bodyPos = strpos($odfStylesContent, 'OdfBodyMetni');
		$hdrPos = strpos($odfStylesContent, 'Üstbilgi odfheadflamingo');
		check($bodyPos !== false && $hdrPos !== false && $bodyPos < $hdrPos, 'B5.3b: content.xml precedes styles.xml headers/footers');

		// B5.4: metadata-only INDEX_META update restricts vocabulary additions to title and tags_text
		$platform->indexDocument(doc('B5_META_VOCAB', 'alice', 'InitialMetaTitle', 'storedcontentquokka bodytext', [
			'parts' => ['comments' => 'storedpartsquoll commenttext'],
			'tags' => ['initialtagquail'],
		]));
		$wordsBeforeMeta = $conn->executeQuery('SELECT word FROM ftspg_oc_words')->fetchAll(PDO::FETCH_COLUMN);
		check(in_array('storedcontentquokka', $wordsBeforeMeta, true), 'B5.4: initial full index adds content word to vocabulary');
		check(in_array('storedpartsquoll', $wordsBeforeMeta, true), 'B5.4: initial full index adds parts word to vocabulary');

		$conn->executeStatement("DELETE FROM ftspg_oc_words WHERE word IN ('storedcontentquokka', 'storedpartsquoll')");
		$platform->indexDocument(doc('B5_META_VOCAB', 'alice', 'RenamedMetaTitle SplitAlpha123SplitBeta.txt', '', [
			'tags' => ['renamedtaglemur'],
		], 'files', IIndex::INDEX_META));
		$wordsAfterMeta = $conn->executeQuery('SELECT word FROM ftspg_oc_words')->fetchAll(PDO::FETCH_COLUMN);
		check(in_array('renamedmetatitle', $wordsAfterMeta, true), 'B5.4: INDEX_META adds new title word to vocabulary');
		check(in_array('splitalpha', $wordsAfterMeta, true) && in_array('splitbeta', $wordsAfterMeta, true), 'B5.4: INDEX_META adds split title words to vocabulary');
		check(in_array('renamedtaglemur', $wordsAfterMeta, true), 'B5.4: INDEX_META adds new tag word to vocabulary');
		check(!in_array('storedcontentquokka', $wordsAfterMeta, true), 'B5.4: INDEX_META does not re-add stored content words');
		check(!in_array('storedpartsquoll', $wordsAfterMeta, true), 'B5.4: INDEX_META does not re-add stored parts words');

		// B5.5: CreateIndexTable::fillVocabulary() skips when typo_correction = false
		$conn->executeStatement('TRUNCATE ftspg_oc_words');
		$config->setConfig(['typo_correction' => false]);
		$progressOff = 0;
		$repairOutputOff = double(\OCP\Migration\IOutput::class, ['advance' => function (int $step = 1) use (&$progressOff) {
			$progressOff += $step;
		}]);
		(new CreateIndexTable($schema, $vocabulary, $config))->run($repairOutputOff);
		same(0, $vocabulary->count(), 'B5.5: upgrade step leaves vocabulary empty when typo_correction is false');
		same(0, $progressOff, 'B5.5: upgrade step reports 0 progress when typo_correction is false');

		$config->setConfig(['typo_correction' => true]);
		$progressOn = 0;
		$repairOutputOn = double(\OCP\Migration\IOutput::class, ['advance' => function (int $step = 1) use (&$progressOn) {
			$progressOn += $step;
		}]);
		(new CreateIndexTable($schema, $vocabulary, $config))->run($repairOutputOn);
		check($vocabulary->count() > 20, 'B5.5: upgrade step populates vocabulary once typo_correction is re-enabled');
		same($schema->countDocuments(), $progressOn, 'B5.5: upgrade step reports progress per document when enabled');
	} finally {
		$config->setConfig(['max_content_size' => 512000, 'pdf_extractor' => 'auto', 'pdftotext_path' => '', 'typo_correction' => true, 'language' => 'turkish']);
		foreach (glob($b5ToolDir . '/docx/*') ?: [] as $tmpFile) {
			@unlink($tmpFile);
		}
		@rmdir($b5ToolDir . '/docx');
		@unlink($b5HangTool);
		@unlink($b5StderrTool);
		@rmdir($b5HangDir);
		@rmdir($b5StderrDir);
		@rmdir($b5ToolDir);
	}

	section('atomic, serialized schema rebuild (S1)');
	$s1DropTables = static function () use ($conn): void {
		if ($conn->inTransaction()) {
			$conn->rollBack();
		}
		$conn->executeStatement('DROP TABLE IF EXISTS ftspg_s1fresh_index, ftspg_s1fresh_words, ftspg_s1conc_index, ftspg_s1conc_words, ftspg_s1race_index, ftspg_s1race_words, ftspg_s1test_index, ftspg_s1test_words, ftspg_s1notrgm_index, ftspg_s1notrgm_words');
		$conn->executeStatement('DROP FUNCTION IF EXISTS ftspg_s1fresh_regconfig(text), ftspg_s1conc_regconfig(text), ftspg_s1race_regconfig(text), ftspg_s1test_regconfig(text), ftspg_s1notrgm_regconfig(text)');
	};
	$s1DropTables();

	try {
		// 0. CreateIndexTable on missing table omits rebuild/lock notice
		$freshSysConfig = new ArrayConfig(['dbtableprefix' => 's1fresh_']);
		$freshSchema = new SchemaService($conn, $freshSysConfig, $logger);
		$freshConfig = new ConfigService(new ArrayAppConfig(), $freshSchema);
		$freshVocab = new VocabularyService($conn, $freshSchema);
		$freshInfos = [];
		$freshWarnings = [];
		$freshOutput = double(\OCP\Migration\IOutput::class, [
			'info' => function (string $message) use (&$freshInfos) {
				$freshInfos[] = $message;
			},
			'warning' => function (string $message) use (&$freshWarnings) {
				$freshWarnings[] = $message;
			},
		]);
		(new CreateIndexTable($freshSchema, $freshVocab, $freshConfig))->run($freshOutput);
		same(true, $freshSchema->isCurrent(), 'S1 fresh install: CreateIndexTable creates current schema');
		same(false, stripos(implode("\n", array_merge($freshInfos, $freshWarnings)), 'lock') !== false, 'S1 fresh install: repair step omits rebuild/lock notice');

		// Helper for concurrent ensureSchema() via proc_open + commit() proxy + pg_locks polling
		$runConcurrentEnsure = static function (string $prefix) use ($conn, $dsn, $logger): array {
			$childScript = '<?php' . "\n"
				. 'declare(strict_types=1);' . "\n"
				. 'require ' . var_export(__DIR__ . '/bootstrap.php', true) . ';' . "\n"
				. '$c = new \OCA\FullTextSearch_PgSql\Tests\PdoConnection(new \PDO(' . var_export($dsn, true) . '), ' . var_export($prefix, true) . ');' . "\n"
				. '$s = new \OCA\FullTextSearch_PgSql\Service\SchemaService($c, new \OCA\FullTextSearch_PgSql\Tests\ArrayConfig([\'dbtableprefix\' => ' . var_export($prefix, true) . ']), new \OCA\FullTextSearch_PgSql\Tests\MemoryLogger());' . "\n"
				. '$c->statements = [];' . "\n"
				. '$s->ensureSchema();' . "\n"
				. 'echo json_encode([\'statements\' => $c->statements, \'current\' => $s->isCurrent()]);' . "\n";

			$state = new class {
				public bool $committed = false;
				public bool $sawAdvisoryWait = false;
				/** @var list<string> */
				public array $parentSqls = [];
				public string $dsn = '';
				public string $childScript = '';
				/** @var resource|null */
				public $proc = null;
				/** @var array<int, resource> */
				public array $pipes = [];
				public ?int $exitCode = null;
			};
			$state->dsn = $dsn;
			$state->childScript = $childScript;

			$proxy = new class($conn, $state) implements \OCP\IDBConnection {
				public function __construct(
					private \OCP\IDBConnection $inner,
					private object $state,
				) {
				}
				public function executeStatement($sql, array $params = [], array $types = []): int {
					$this->state->parentSqls[] = (string)$sql;
					return $this->inner->executeStatement($sql, $params, $types);
				}
				public function executeQuery(string $sql, array $params = [], $types = []): \OCP\DB\IResult {
					$this->state->parentSqls[] = $sql;
					return $this->inner->executeQuery($sql, $params, $types);
				}
				public function inTransaction(): bool {
					return $this->inner->inTransaction();
				}
				public function beginTransaction(): void {
					$this->inner->beginTransaction();
				}
				public function commit(): void {
					if (!$this->state->committed) {
						$this->state->committed = true;
						$pipes = [];
						$proc = proc_open(
							[PHP_BINARY],
							[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
							$pipes
						);
						if (!is_resource($proc)) {
							$this->inner->commit();
							throw new \RuntimeException('Failed to spawn child PHP process');
						}
						fwrite($pipes[0], $this->state->childScript);
						fclose($pipes[0]);
						$this->state->proc = $proc;
						$this->state->pipes = $pipes;
						try {
							$monitorPdo = new PDO($this->state->dsn);
							$deadline = microtime(true) + 5.0;
							while (microtime(true) < $deadline) {
								$waitCount = (int)$monitorPdo->query(
									"SELECT count(*) FROM pg_locks WHERE pid <> pg_backend_pid() AND locktype = 'advisory' AND NOT granted"
								)->fetchColumn();
								if ($waitCount > 0) {
									$this->state->sawAdvisoryWait = true;
									break;
								}
								$status = proc_get_status($proc);
								if (!$status['running']) {
									if ($this->state->exitCode === null) {
										$this->state->exitCode = $status['exitcode'];
									}
									break;
								}
								usleep(10000);
							}
						} finally {
							$this->inner->commit();
						}
						return;
					}
					$this->inner->commit();
				}
				public function rollBack(): void {
					$this->inner->rollBack();
				}
				public function getDatabaseProvider(): string {
					return $this->inner->getDatabaseProvider();
				}
			};

			$parentSchema = new SchemaService($proxy, new ArrayConfig(['dbtableprefix' => $prefix]), $logger);
			try {
				$parentSchema->ensureSchema();
			} finally {
				$childStdout = '';
				$childStderr = '';
				$childExit = -1;
				if (is_resource($state->proc)) {
					stream_set_blocking($state->pipes[1], false);
					stream_set_blocking($state->pipes[2], false);
					$readDeadline = microtime(true) + 5.0;
					while (microtime(true) < $readDeadline) {
						$childStdout .= stream_get_contents($state->pipes[1]) ?: '';
						$childStderr .= stream_get_contents($state->pipes[2]) ?: '';
						$status = proc_get_status($state->proc);
						if (!$status['running']) {
							if ($state->exitCode === null) {
								$state->exitCode = $status['exitcode'];
							}
							if (feof($state->pipes[1]) && feof($state->pipes[2])) {
								break;
							}
						}
						usleep(10000);
					}
					if ($state->exitCode === null) {
						$status = proc_get_status($state->proc);
						if ($status['running']) {
							proc_terminate($state->proc);
						} else {
							$state->exitCode = $status['exitcode'];
						}
					}
					$childStdout .= stream_get_contents($state->pipes[1]) ?: '';
					$childStderr .= stream_get_contents($state->pipes[2]) ?: '';
					fclose($state->pipes[1]);
					fclose($state->pipes[2]);
					$closeCode = proc_close($state->proc);
					$childExit = $state->exitCode ?? $closeCode;
				}
			}

			return [
				'sawAdvisoryWait' => $state->sawAdvisoryWait,
				'parentSqls' => $state->parentSqls,
				'childExit' => $childExit,
				'childJson' => json_decode($childStdout, true) ?: [],
				'childStderr' => $childStderr,
			];
		};

		// 1. Concurrent ensureSchema() on outdated Schema v2 table executes rebuild once
		$concSchema = new SchemaService($conn, new ArrayConfig(['dbtableprefix' => 's1conc_']), $logger);
		$concSchema->ensureSchema();
		$conn->executeStatement(
			"INSERT INTO ftspg_s1conc_index (provider_id, document_id, config, title, content) VALUES ('files', 'S1C1', 'simple', 'S1 Conc', 'concurrent test')"
		);
		$conn->executeStatement("COMMENT ON COLUMN ftspg_s1conc_index.tsv IS 'ftspg schema 1'");
		same(false, $concSchema->isCurrent(), 'S1 concurrency: downgraded schema detected as outdated');

		$concRun = $runConcurrentEnsure('s1conc_');
		same(true, $concRun['sawAdvisoryWait'], 'S1 concurrency (outdated): second caller blocks on pg_advisory_xact_lock');
		same(0, $concRun['childExit'], 'S1 concurrency (outdated): second caller exits cleanly' . ($concRun['childStderr'] !== '' ? ' (' . trim($concRun['childStderr']) . ')' : ''));
		same(true, $concRun['childJson']['current'] ?? false, 'S1 concurrency (outdated): second caller observes current schema');
		$parentRebuilt = count(array_filter(
			$concRun['parentSqls'],
			static fn (string $sql): bool => str_contains($sql, 'ALTER TABLE') || str_contains($sql, 'COMMENT ON COLUMN')
		)) > 0;
		$childDdl = array_values(array_filter(
			$concRun['childJson']['statements'] ?? [],
			static fn (string $sql): bool => preg_match('/\b(ALTER TABLE|DROP INDEX|COMMENT ON COLUMN|CREATE INDEX)\b/i', $sql) === 1
		));
		same(true, $parentRebuilt, 'S1 concurrency (outdated): parent performed rebuild');
		same([], $childDdl, 'S1 concurrency (outdated): second caller executed zero rebuild DDL');
		same(true, $concSchema->isCurrent(), 'S1 concurrency (outdated): schema is current');
		same(1, $concSchema->countDocuments(), 'S1 concurrency (outdated): existing row preserved');

		// 2. Concurrent ensureSchema() on missing table serializes CREATE TABLE IF NOT EXISTS
		$raceSchema = new SchemaService($conn, new ArrayConfig(['dbtableprefix' => 's1race_']), $logger);
		$raceRun = $runConcurrentEnsure('s1race_');
		same(true, $raceRun['sawAdvisoryWait'], 'S1 concurrency (missing): second caller blocks on pg_advisory_xact_lock');
		same(0, $raceRun['childExit'], 'S1 concurrency (missing): second caller exits cleanly' . ($raceRun['childStderr'] !== '' ? ' (' . trim($raceRun['childStderr']) . ')' : ''));
		same(true, $raceRun['childJson']['current'] ?? false, 'S1 concurrency (missing): second caller observes current schema');
		$raceChildDdl = array_values(array_filter(
			$raceRun['childJson']['statements'] ?? [],
			static fn (string $sql): bool => preg_match('/\b(CREATE TABLE|ALTER TABLE|DROP INDEX|COMMENT ON COLUMN|CREATE INDEX)\b/i', $sql) === 1
		));
		same([], $raceChildDdl, 'S1 concurrency (missing): second caller skipped CREATE TABLE/DDL');
		same(true, $raceSchema->tableExists() && $raceSchema->isCurrent(), 'S1 concurrency (missing): table created and current');

		// 3. Forced mid-rebuild failure leaves Schema v2 columns, indexes, marker, and rows intact
		$s1SysConfig = new ArrayConfig(['dbtableprefix' => 's1test_']);
		$s1Schema = new SchemaService($conn, $s1SysConfig, $logger);
		$s1Config = new ConfigService(new ArrayAppConfig(), $s1Schema);
		$s1Extractor = new ContentExtractor($s1Config);
		$s1Vocab = new VocabularyService($conn, $s1Schema);
		$s1Detector = new LanguageDetector($conn, $s1Config, $logger);
		$s1Index = new IndexService($conn, $s1Schema, $s1Config, $s1Extractor, $s1Vocab, $logger, $s1Detector);
		$s1Search = new SearchService($conn, $s1Schema, $s1Config, new TsQueryBuilder(), $s1Vocab);
		$s1Platform = new PostgreSQLPlatform($s1Config, $s1Schema, $s1Index, $s1Search, $logger, $s1Extractor);
		$s1Platform->initializeIndex();
		$s1Platform->indexDocument(doc('S1_KEEP', 'alice', 'S1 Atomic Keep', 'atomic rollback verification'));

		$makeFailProxy = static function (string $failTrigger) use ($conn): \OCP\IDBConnection {
			return new class($conn, $failTrigger) implements \OCP\IDBConnection {
				public function __construct(
					private \OCP\IDBConnection $inner,
					private string $failTrigger,
				) {
				}
				public function executeStatement($sql, array $params = [], array $types = []): int {
					if (str_contains((string)$sql, $this->failTrigger)) {
						throw new \RuntimeException('injected failure at ' . $this->failTrigger);
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
		};

		foreach (['ADD COLUMN tsv', 'COMMENT ON COLUMN'] as $failTrigger) {
			$conn->executeStatement("COMMENT ON COLUMN ftspg_s1test_index.tsv IS 'ftspg schema 1'");
			$failSchema = new SchemaService($makeFailProxy($failTrigger), $s1SysConfig, $logger);
			$threw = false;
			try {
				$failSchema->ensureSchema();
			} catch (\RuntimeException) {
				$threw = true;
			}
			same(true, $threw, "S1 rollback ($failTrigger): exception propagates");
			same(false, $conn->inTransaction(), "S1 rollback ($failTrigger): no leaked open transaction");
			same(1, (int)$conn->executeQuery('SELECT 1')->fetchOne(), "S1 rollback ($failTrigger): connection remains usable");
			$cols = $conn->executeQuery(
				"SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'ftspg_s1test_index'"
			)->fetchAll(PDO::FETCH_COLUMN);
			check(in_array('tsv', $cols, true) && in_array('title_search', $cols, true), "S1 rollback ($failTrigger): Schema v2 columns remain intact");
			$idxs = $conn->executeQuery("SELECT indexname FROM pg_indexes WHERE tablename = 'ftspg_s1test_index'")->fetchAll(PDO::FETCH_COLUMN);
			check(in_array('ftspg_s1test_index_tsv_idx', $idxs, true), "S1 rollback ($failTrigger): tsv index remains intact");
			if ($s1Schema->hasTrigram()) {
				check(in_array('ftspg_s1test_index_title_search_trgm_idx', $idxs, true), "S1 rollback ($failTrigger): trigram index remains intact");
			}
			$marker = $conn->executeQuery(
				"SELECT col_description(to_regclass('ftspg_s1test_index'), attnum) FROM pg_attribute WHERE attrelid = to_regclass('ftspg_s1test_index') AND attname = 'tsv' AND NOT attisdropped"
			)->fetchOne();
			same('ftspg schema 1', $marker, "S1 rollback ($failTrigger): marker preserved");
			same(['S1_KEEP'], search($s1Platform, 'alice', 'atomic')['ids'], "S1 rollback ($failTrigger): existing row intact and searchable");
		}

		$s1Schema->ensureSchema();
		same(true, $s1Schema->isCurrent(), 'S1: subsequent normal ensureSchema succeeds after prior rollback');
		same(['S1_KEEP'], search($s1Platform, 'alice', 'atomic')['ids'], 'S1: existing row remains searchable after recovery');

		// 4. ensureSchema() inside an already-open caller transaction (success + savepoint rollback)
		$conn->executeStatement("COMMENT ON COLUMN ftspg_s1test_index.tsv IS 'ftspg schema 1'");
		$conn->beginTransaction();
		$conn->executeStatement("UPDATE ftspg_s1test_index SET title = 'Outer Tx Title' WHERE document_id = 'S1_KEEP'");
		$s1Schema->ensureSchema();
		same(true, $conn->inTransaction(), 'S1 caller tx: ensureSchema leaves outer transaction open on success');
		same(true, $s1Schema->isCurrent(), 'S1 caller tx: schema upgraded inside caller transaction');
		$conn->commit();
		same('Outer Tx Title', (string)$conn->executeQuery("SELECT title FROM ftspg_s1test_index WHERE document_id = 'S1_KEEP'")->fetchOne(), 'S1 caller tx: outer DML committed');

		$conn->executeStatement("COMMENT ON COLUMN ftspg_s1test_index.tsv IS 'ftspg schema 1'");
		$conn->beginTransaction();
		$conn->executeStatement("UPDATE ftspg_s1test_index SET title = 'Outer Tx Pending' WHERE document_id = 'S1_KEEP'");
		$txFailSchema = new SchemaService($makeFailProxy('ADD COLUMN tsv'), $s1SysConfig, $logger);
		$txThrew = false;
		try {
			$txFailSchema->ensureSchema();
		} catch (\RuntimeException) {
			$txThrew = true;
		}
		same(true, $txThrew, 'S1 caller tx rollback: exception propagates');
		same(true, $conn->inTransaction(), 'S1 caller tx rollback: outer transaction remains open');
		same(
			'Outer Tx Pending',
			(string)$conn->executeQuery("SELECT title FROM ftspg_s1test_index WHERE document_id = 'S1_KEEP'")->fetchOne(),
			'S1 caller tx rollback: outer transaction is un-aborted (no 25P02) and DML is intact'
		);
		$txCols = $conn->executeQuery(
			"SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'ftspg_s1test_index'"
		)->fetchAll(PDO::FETCH_COLUMN);
		check(in_array('tsv', $txCols, true) && in_array('title_search', $txCols, true), 'S1 caller tx rollback: Schema v2 columns remain intact');
		$txMarker = $conn->executeQuery(
			"SELECT col_description(to_regclass('ftspg_s1test_index'), attnum) FROM pg_attribute WHERE attrelid = to_regclass('ftspg_s1test_index') AND attname = 'tsv' AND NOT attisdropped"
		)->fetchOne();
		same('ftspg schema 1', $txMarker, 'S1 caller tx rollback: marker preserved');
		$conn->rollBack();
		same('Outer Tx Title', (string)$conn->executeQuery("SELECT title FROM ftspg_s1test_index WHERE document_id = 'S1_KEEP'")->fetchOne(), 'S1 caller tx rollback: outer rollback succeeds cleanly');

		// 5. CREATE EXTENSION pg_trgm failure (42501) stays outside the rebuild transaction
		$noTrgmState = new class {
			public ?bool $createExtInTx = null;
		};
		$noTrgmProxy = new class($conn, $noTrgmState) implements \OCP\IDBConnection {
			public function __construct(
				private \OCP\IDBConnection $inner,
				private object $state,
			) {
			}
			public function executeStatement($sql, array $params = [], array $types = []): int {
				if (str_contains((string)$sql, 'CREATE EXTENSION')) {
					$this->state->createExtInTx = $this->inner->inTransaction();
					$e = new \PDOException('SQLSTATE[42501]: Insufficient privilege: permission denied to create extension "pg_trgm"', 42501);
					$e->errorInfo = ['42501', 7, 'permission denied to create extension "pg_trgm"'];
					throw $e;
				}
				return $this->inner->executeStatement($sql, $params, $types);
			}
			public function executeQuery(string $sql, array $params = [], $types = []): \OCP\DB\IResult {
				if (str_contains($sql, 'pg_extension')) {
					return new class implements \OCP\DB\IResult {
						public function closeCursor(): bool {
							return true;
						}
						public function fetch(int $fetchMode = \PDO::FETCH_ASSOC) {
							return false;
						}
						public function fetchAll(int $fetchMode = \PDO::FETCH_ASSOC): array {
							return [];
						}
						public function fetchOne() {
							return false;
						}
					};
				}
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
		$noTrgmSchema = new SchemaService($noTrgmProxy, new ArrayConfig(['dbtableprefix' => 's1notrgm_']), $logger);
		$noTrgmSchema->ensureSchema();
		same(false, $noTrgmState->createExtInTx, 'S1 pg_trgm isolation: CREATE EXTENSION attempted outside transaction');
		same(false, $conn->inTransaction(), 'S1 pg_trgm isolation: no open transaction leaked');
		same(true, $noTrgmSchema->tableExists() && $noTrgmSchema->isCurrent(), 'S1 pg_trgm isolation: schema rebuild committed despite 42501');

		// 6. Recovery of missing words table/index when marker is already current does not rewrite tsv
		$s1Schema->ensureSchema();
		same(true, $s1Schema->isCurrent(), 'S1 recovery: schema marker is current before dropping words table');
		$conn->executeStatement('DROP TABLE IF EXISTS ftspg_s1test_words');
		$conn->statements = [];
		$s1Schema->ensureSchema();
		same(true, (bool)$conn->executeQuery("SELECT to_regclass('ftspg_s1test_words') IS NOT NULL")->fetchOne(), 'S1 recovery: missing words table recreated when schema marker is current');
		$rewriteDdl = array_values(array_filter(
			$conn->statements,
			static fn (string $sql): bool => preg_match('/\b(ALTER TABLE|COMMENT ON)\b/i', $sql) === 1
		));
		same([], $rewriteDdl, 'S1 recovery: words table/index recovery does not drop or rebuild tsv');
	} finally {
		$s1DropTables();
	}

	section('config as text and schema version 3 (S2)');
	$s2Cleanup = static function () use ($conn, $platform): void {
		if ($conn->inTransaction()) {
			$conn->rollBack();
		}
		$conn->executeStatement('DROP TABLE IF EXISTS ftspg_s2fresh_index, ftspg_s2fresh_words, ftspg_s2up_index, ftspg_s2up_words, ftspg_s2drop_index, ftspg_s2drop_words');
		$conn->executeStatement('DROP FUNCTION IF EXISTS ftspg_s2fresh_regconfig(text), ftspg_s2up_regconfig(text), ftspg_s2drop_regconfig(text)');
		$conn->executeStatement("DELETE FROM ftspg_oc_index WHERE provider_id = 's2ml' OR length(provider_id) > 64");
		$platform->resetIndex('s2ml');
	};
	$s2Cleanup();

	try {
		// 1. Schema v3 marker on ftspg_oc_index.tsv
		$s2Marker = $conn->executeQuery(
			"SELECT col_description(to_regclass('ftspg_oc_index'), attnum) FROM pg_attribute WHERE attrelid = to_regclass('ftspg_oc_index') AND attname = 'tsv' AND NOT attisdropped"
		)->fetchOne();
		same('ftspg schema 3', $s2Marker, 'S2: tsv column comment carries ftspg schema 3 marker');

		// 2. Columns are text (zero reg* columns) and tsv uses ftspg_oc_regconfig(config)
		$s2ColRows = $conn->executeQuery(
			"SELECT attname, format_type(atttypid, atttypmod) AS typ FROM pg_attribute WHERE attrelid = to_regclass('ftspg_oc_index') AND attnum > 0 AND NOT attisdropped"
		)->fetchAll();
		$s2ColTypes = [];
		$s2RegCols = [];
		foreach ($s2ColRows as $row) {
			$colName = (string)$row['attname'];
			$colType = (string)$row['typ'];
			$s2ColTypes[$colName] = $colType;
			if (str_starts_with($colType, 'reg')) {
				$s2RegCols[] = $colName . ':' . $colType;
			}
		}
		foreach (['config', 'provider_id', 'document_id', 'owner_id', 'source', 'hash'] as $col) {
			same('text', $s2ColTypes[$col] ?? null, "S2: ftspg_oc_index.$col is text");
		}
		same([], $s2RegCols, 'S2: ftspg_oc_index has zero reg* columns');
		$s2TsvExpr = (string)$conn->executeQuery(
			"SELECT pg_get_expr(d.adbin, d.adrelid) FROM pg_attrdef d JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum WHERE d.adrelid = to_regclass('ftspg_oc_index') AND a.attname = 'tsv' AND NOT a.attisdropped"
		)->fetchOne();
		check(str_contains($s2TsvExpr, 'ftspg_oc_regconfig(config)'), 'S2: generated tsv expression uses ftspg_oc_regconfig(config)');

		// 3. ftspg_oc_regconfig(text) wrapper metadata and evaluation
		$s2FnRow = $conn->executeQuery(
			"SELECT p.provolatile, p.proparallel, p.prorettype::regtype::text AS rettype, p.prosrc FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND p.proname = 'ftspg_oc_regconfig' AND pg_get_function_identity_arguments(p.oid) = 'text'"
		)->fetch();
		check(is_array($s2FnRow), 'S2: ftspg_oc_regconfig(text) exists in current schema');
		same('i', is_array($s2FnRow) ? (string)$s2FnRow['provolatile'] : null, 'S2: ftspg_oc_regconfig(text) is IMMUTABLE');
		same('s', is_array($s2FnRow) ? (string)$s2FnRow['proparallel'] : null, 'S2: ftspg_oc_regconfig(text) is PARALLEL SAFE');
		same('regconfig', is_array($s2FnRow) ? (string)$s2FnRow['rettype'] : null, 'S2: ftspg_oc_regconfig(text) returns regconfig');
		check(is_array($s2FnRow) && str_contains((string)$s2FnRow['prosrc'], 'pg_catalog.regconfig'), 'S2: ftspg_oc_regconfig(text) body qualifies pg_catalog.regconfig');
		try {
			same('english', (string)$conn->executeQuery("SELECT ftspg_oc_regconfig('english')::text")->fetchOne(), 'S2: ftspg_oc_regconfig evaluates english');
			same('turkish', (string)$conn->executeQuery("SELECT ftspg_oc_regconfig('turkish')::text")->fetchOne(), 'S2: ftspg_oc_regconfig evaluates turkish');
		} catch (Throwable $e) {
			check(false, 'S2: ftspg_oc_regconfig evaluation failed: ' . $e->getMessage());
		}

		// 4a. Fresh install creates Schema v3 directly
		$s2FreshSchema = new SchemaService($conn, new ArrayConfig(['dbtableprefix' => 's2fresh_']), $logger);
		$s2FreshSchema->dropSchema();
		$s2FreshSchema->ensureSchema();
		same(true, $s2FreshSchema->isCurrent(), 'S2 fresh install: ensureSchema creates current schema');
		$s2FreshMarker = $conn->executeQuery(
			"SELECT col_description(to_regclass('ftspg_s2fresh_index'), attnum) FROM pg_attribute WHERE attrelid = to_regclass('ftspg_s2fresh_index') AND attname = 'tsv' AND NOT attisdropped"
		)->fetchOne();
		same('ftspg schema 3', $s2FreshMarker, 'S2 fresh install: marker is ftspg schema 3');
		$s2FreshColTypes = [];
		foreach ($conn->executeQuery("SELECT attname, format_type(atttypid, atttypmod) AS typ FROM pg_attribute WHERE attrelid = to_regclass('ftspg_s2fresh_index') AND attnum > 0 AND NOT attisdropped")->fetchAll() as $row) {
			$s2FreshColTypes[(string)$row['attname']] = (string)$row['typ'];
		}
		foreach (['config', 'provider_id', 'document_id', 'owner_id', 'source', 'hash'] as $col) {
			same('text', $s2FreshColTypes[$col] ?? null, "S2 fresh install: $col is text");
		}
		$s2FreshFn = $conn->executeQuery("SELECT to_regprocedure('ftspg_s2fresh_regconfig(text)') IS NOT NULL")->fetchOne();
		same(true, (bool)$s2FreshFn, 'S2 fresh install: ftspg_s2fresh_regconfig(text) created');

		// 4b. Upgrading an explicit Schema v2 table (varchar + regconfig + 'ftspg schema 2') preserves rows & config and searches cleanly
		$s2UpSysConfig = new ArrayConfig(['dbtableprefix' => 's2up_']);
		$s2UpSchema = new SchemaService($conn, $s2UpSysConfig, $logger);
		$s2UpConfig = new ConfigService(new ArrayAppConfig(), $s2UpSchema);
		$s2UpConfig->setConfig(['language' => 'turkish,english']);
		$s2UpVocab = new VocabularyService($conn, $s2UpSchema);
		$s2UpIndex = new IndexService($conn, $s2UpSchema, $s2UpConfig, new ContentExtractor($s2UpConfig), $s2UpVocab, $logger, new LanguageDetector($conn, $s2UpConfig, $logger));
		$s2UpSearch = new SearchService($conn, $s2UpSchema, $s2UpConfig, new TsQueryBuilder(), $s2UpVocab);
		$s2UpPlatform = new PostgreSQLPlatform($s2UpConfig, $s2UpSchema, $s2UpIndex, $s2UpSearch, $logger, new ContentExtractor($s2UpConfig));
		$conn->executeStatement(<<<'SQL'
			CREATE TABLE ftspg_s2up_index (
				id            bigserial    PRIMARY KEY,
				provider_id   varchar(64)  NOT NULL,
				document_id   varchar(254) NOT NULL,
				owner_id      varchar(64)  NOT NULL DEFAULT '',
				access        text[]       NOT NULL DEFAULT '{}',
				links         text[]       NOT NULL DEFAULT '{}',
				tags          text[]       NOT NULL DEFAULT '{}',
				metatags      text[]       NOT NULL DEFAULT '{}',
				subtags       text[]       NOT NULL DEFAULT '{}',
				source        varchar(64)  NOT NULL DEFAULT '',
				hash          varchar(128) NOT NULL DEFAULT '',
				modified_time bigint       NOT NULL DEFAULT 0,
				indexed_at    bigint       NOT NULL DEFAULT 0,
				config        regconfig    NOT NULL,
				title         text         NOT NULL DEFAULT '',
				tags_text     text         NOT NULL DEFAULT '',
				parts         jsonb        NOT NULL DEFAULT '{}',
				parts_text    text         NOT NULL DEFAULT '',
				content       text         NOT NULL DEFAULT '',
				info          jsonb        NOT NULL DEFAULT '{}',
				tsv           tsvector     GENERATED ALWAYS AS (
					setweight(to_tsvector(config, CASE WHEN config = 'turkish'::regconfig THEN translate(title, 'Iİ', 'ıi') ELSE title END), 'A')
					|| setweight(to_tsvector(config, CASE WHEN config = 'turkish'::regconfig THEN translate(content, 'Iİ', 'ıi') ELSE content END), 'D')
				) STORED,
				title_search  text         GENERATED ALWAYS AS (lower(CASE WHEN config = 'turkish'::regconfig THEN translate(title, 'Iİ', 'ıi') ELSE title END)) STORED,
				CONSTRAINT ftspg_s2up_index_doc_uniq UNIQUE (provider_id, document_id)
			)
			SQL);
		$conn->executeStatement("COMMENT ON COLUMN ftspg_s2up_index.tsv IS 'ftspg schema 2'");
		$conn->executeStatement('CREATE INDEX ftspg_s2up_index_tsv_idx ON ftspg_s2up_index USING GIN (tsv)');
		$conn->executeStatement('CREATE INDEX ftspg_s2up_index_access_idx ON ftspg_s2up_index USING GIN (access)');
		$conn->executeStatement('CREATE INDEX ftspg_s2up_index_metatags_idx ON ftspg_s2up_index USING GIN (metatags)');
		$conn->executeStatement('CREATE INDEX ftspg_s2up_index_subtags_idx ON ftspg_s2up_index USING GIN (subtags)');
		$conn->executeStatement(sprintf(
			'CREATE TABLE ftspg_s2up_words (word text COLLATE "C" PRIMARY KEY, folded text COLLATE "C" GENERATED ALWAYS AS (translate(word, \'%s\', \'%s\')) STORED)',
			\OCA\FullTextSearch_PgSql\Tools\Fold::FROM,
			\OCA\FullTextSearch_PgSql\Tools\Fold::TO
		));
		$conn->executeStatement('CREATE INDEX ftspg_s2up_words_folded_idx ON ftspg_s2up_words (folded)');
		if ($s2UpSchema->hasTrigram()) {
			$conn->executeStatement('CREATE INDEX ftspg_s2up_index_title_search_trgm_idx ON ftspg_s2up_index USING GIN (title_search gin_trgm_ops)');
			$conn->executeStatement('CREATE INDEX ftspg_s2up_words_folded_trgm_idx ON ftspg_s2up_words USING GIN (folded gin_trgm_ops)');
		}
		$conn->executeStatement(
			'INSERT INTO ftspg_s2up_index (provider_id, document_id, owner_id, access, config, title, content) VALUES '
			. "('files', 'S2U_EN', 'alice', '{u:alice}', 'english'::regconfig, 'Annual budget', 'The studies were published in the archive.'), "
			. "('files', 'S2U_TR', 'alice', '{u:alice}', 'turkish'::regconfig, 'Yıllık rapor', 'Bu yılki kitapları kütüphaneye verdik.')"
		);
		$s2UpSchema->ensureSchema();
		$s2UpMarker = $conn->executeQuery(
			"SELECT col_description(to_regclass('ftspg_s2up_index'), attnum) FROM pg_attribute WHERE attrelid = to_regclass('ftspg_s2up_index') AND attname = 'tsv' AND NOT attisdropped"
		)->fetchOne();
		same('ftspg schema 3', $s2UpMarker, 'S2 upgrade from v2: marker updated to ftspg schema 3');
		$s2UpColTypes = [];
		foreach ($conn->executeQuery("SELECT attname, format_type(atttypid, atttypmod) AS typ FROM pg_attribute WHERE attrelid = to_regclass('ftspg_s2up_index') AND attnum > 0 AND NOT attisdropped")->fetchAll() as $row) {
			$s2UpColTypes[(string)$row['attname']] = (string)$row['typ'];
		}
		foreach (['config', 'provider_id', 'document_id', 'owner_id', 'source', 'hash'] as $col) {
			same('text', $s2UpColTypes[$col] ?? null, "S2 upgrade from v2: $col migrated to text");
		}
		same(true, (bool)$conn->executeQuery("SELECT to_regprocedure('ftspg_s2up_regconfig(text)') IS NOT NULL")->fetchOne(), 'S2 upgrade from v2: ftspg_s2up_regconfig(text) created');
		same(2, $s2UpSchema->countDocuments(), 'S2 upgrade from v2: row count preserved');
		same('english', (string)$conn->executeQuery("SELECT config::text FROM ftspg_s2up_index WHERE document_id = 'S2U_EN'")->fetchOne(), 'S2 upgrade from v2: english config preserved');
		same('turkish', (string)$conn->executeQuery("SELECT config::text FROM ftspg_s2up_index WHERE document_id = 'S2U_TR'")->fetchOne(), 'S2 upgrade from v2: turkish config preserved');
		try {
			same(['S2U_EN'], search($s2UpPlatform, 'alice', 'study')['ids'], 'S2 upgrade from v2: English search works after upgrade');
			same(['S2U_TR'], search($s2UpPlatform, 'alice', 'kitaplarımız')['ids'], 'S2 upgrade from v2: Turkish search works after upgrade');
		} catch (Throwable $e) {
			check(false, 'S2 upgrade from v2 search failed: ' . $e->getMessage());
		}

		// 5. Uninstall (DropIndexTable / dropSchema) drops ftspg_<prefix>regconfig(text) cleanly
		$s2DropSchema = new SchemaService($conn, new ArrayConfig(['dbtableprefix' => 's2drop_']), $logger);
		$s2DropSchema->ensureSchema();
		if (!(bool)$conn->executeQuery("SELECT to_regprocedure('ftspg_s2drop_regconfig(text)') IS NOT NULL")->fetchOne()) {
			// On S1 baseline, create the function so DropIndexTable/dropSchema removal is independently verified.
			$conn->executeStatement('CREATE OR REPLACE FUNCTION ftspg_s2drop_regconfig(text) RETURNS pg_catalog.regconfig LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$SELECT $1::pg_catalog.regconfig$$');
		}
		$dropOutput = double(\OCP\Migration\IOutput::class, [
			'info' => function (string $message) {
			},
			'warning' => function (string $message) {
			},
		]);
		(new DropIndexTable($s2DropSchema))->run($dropOutput);
		$s2DropSchema->dropSchema();
		same(false, (bool)$conn->executeQuery("SELECT to_regclass('ftspg_s2drop_index') IS NOT NULL")->fetchOne(), 'S2 uninstall: index table dropped');
		same(false, (bool)$conn->executeQuery("SELECT to_regclass('ftspg_s2drop_words') IS NOT NULL")->fetchOne(), 'S2 uninstall: words table dropped');
		same(0, (int)$conn->executeQuery("SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND p.proname = 'ftspg_s2drop_regconfig'")->fetchOne(), 'S2 uninstall: ftspg_s2drop_regconfig(text) dropped');

		// 6. varchar caps lifted (>254 document_id, >64 provider_id/owner_id/source, >128 hash with content === '', plus >254 document_id with content)
		$longProvider = str_repeat('p', 80);
		$longDocId = str_repeat('d', 300);
		$longOwner = str_repeat('o', 80);
		$longSource = str_repeat('s', 80);
		$longHash = str_repeat('h', 160);
		$longDoc = doc($longDocId, $longOwner, 'LongVarcharTitle', '', ['parts' => ['comments' => 'longvarcharneedle']], $longProvider);
		$longDoc->setSource($longSource);
		$longDoc->setHash($longHash);
		$longDocContent = doc($longDocId . '_c', $longOwner, 'LongVarcharContentTitle', 'longvarcharcontentneedle indexed body text', [], $longProvider);
		try {
			$res1 = $platform->indexDocument($longDoc);
			$res2 = $platform->indexDocument($longDocContent);
			same(true, $res1->isStatus(IIndex::INDEX_DONE), 'S2 varchar caps: indexed doc with >254 doc_id, >64 provider/owner/source, >128 hash');
			same(true, $res2->isStatus(IIndex::INDEX_DONE), 'S2 varchar caps: indexed doc with >254 doc_id and non-empty content');
			same([$longDocId], search($platform, $longOwner, 'longvarcharneedle', ['provider' => $longProvider])['ids'], 'S2 varchar caps: search finds >254 document_id by parts');
			same([$longDocId . '_c'], search($platform, $longOwner, 'longvarcharcontentneedle', ['provider' => $longProvider])['ids'], 'S2 varchar caps: search finds >254 document_id by content');
			$fetchedLong = $platform->getDocument($longProvider, $longDocId);
			same($longProvider, $fetchedLong->getProviderId(), 'S2 varchar caps: >64 provider_id round-trips');
			same($longDocId, $fetchedLong->getId(), 'S2 varchar caps: >254 document_id round-trips');
			same($longOwner, $fetchedLong->getAccess()->getOwnerId(), 'S2 varchar caps: >64 owner_id round-trips');
			same($longSource, $fetchedLong->getSource(), 'S2 varchar caps: >64 source round-trips');
			same($longHash, $fetchedLong->getHash(), 'S2 varchar caps: >128 hash round-trips');
		} catch (Throwable $e) {
			check(false, 'S2 varchar caps lifted failed: ' . $e->getMessage());
		}

		// 7. Multi-language (turkish,english) + fallback 'french' row exercising languageConditions(), headlineSql(), countDocumentsByLanguage(), VocabularyService::rebuild()
		$config->setConfig(['language' => 'turkish,english', 'typo_correction' => true]);
		try {
			$platform->indexDocument(doc('S2ML_TR', 'alice', 'ISPARTA GÜLLERİ', 'İSTANBUL ofisinde ISPARTA çalışma ve projeler arttı.', [], 's2ml'));
			$platform->indexDocument(doc('S2ML_EN', 'alice', 'Annual study', 'The studies were published in the archive.', [], 's2ml'));
			$conn->executeStatement(
				"INSERT INTO ftspg_oc_index (provider_id, document_id, owner_id, access, config, title, content) VALUES ('s2ml', 'S2ML_FR', 'alice', '{u:alice}', 'french', 'Rapport', 'kitapları okuyalım')"
			);
			$langCounts = $schema->countDocumentsByLanguage();
			check(($langCounts['turkish'] ?? 0) >= 1 && ($langCounts['english'] ?? 0) >= 1 && ($langCounts['french'] ?? 0) >= 1, 'S2 multi-lang: countDocumentsByLanguage reports turkish, english, and french');

			$trRes = search($platform, 'alice', 'ısparta', ['provider' => 's2ml']);
			same(['S2ML_TR'], $trRes['ids'], 'S2 multi-lang: Turkish search finds S2ML_TR');
			$trExcerpt = isset($trRes['docs'][0]) ? implode(' ', array_column($trRes['docs'][0]->getExcerpts() ?? [], 'excerpt')) : '';
			check(str_contains($trExcerpt, 'ISPARTA'), 'S2 multi-lang: Turkish headlineSql preserves uppercase I/İ casing in excerpt');

			$enRes = search($platform, 'alice', 'study', ['provider' => 's2ml']);
			same(['S2ML_EN'], $enRes['ids'], 'S2 multi-lang: English search finds S2ML_EN');
			$enExcerpt = isset($enRes['docs'][0]) ? implode(' ', array_column($enRes['docs'][0]->getExcerpts() ?? [], 'excerpt')) : '';
			check($enExcerpt !== '' && str_contains($enExcerpt, 'studies'), 'S2 multi-lang: English headlineSql produces excerpt');

			same(['S2ML_FR'], search($platform, 'alice', 'kitaplarımız', ['provider' => 's2ml'])['ids'], 'S2 multi-lang: fallback french row matched by primary turkish branch (<> ALL text[])');

			$vocabTotal = $vocabulary->rebuild();
			check($vocabTotal > 0, 'S2 multi-lang: VocabularyService::rebuild() succeeds');
			same(['S2ML_TR'], search($platform, 'alice', 'calisma', ['provider' => 's2ml'])['ids'], 'S2 multi-lang: Turkish accent variant calisma finds çalışma');
		} catch (Throwable $e) {
			check(false, 'S2 multi-lang check failed: ' . $e->getMessage());
		} finally {
			$config->setConfig(['language' => 'turkish']);
		}
	} finally {
		$s2Cleanup();
	}

	section('1.4.2/1.5.x to schema v3 upgrade fixture (S3)');
	$s3UpCleanup = static function () use ($conn): void {
		if ($conn->inTransaction()) {
			$conn->rollBack();
		}
		$conn->executeStatement('DROP TABLE IF EXISTS ftspg_s3up_index, ftspg_s3up_words');
		$conn->executeStatement('DROP FUNCTION IF EXISTS ftspg_s3up_regconfig(text)');
	};
	$s3UpCleanup();

	try {
		$s3UpSysConfig = new ArrayConfig(['dbtableprefix' => 's3up_']);
		$s3UpSchema = new SchemaService($conn, $s3UpSysConfig, $logger);
		$s3UpConfig = new ConfigService(new ArrayAppConfig(), $s3UpSchema);
		$s3UpConfig->setConfig(['language' => 'turkish,english', 'typo_correction' => true]);
		$s3UpVocab = new VocabularyService($conn, $s3UpSchema);
		$s3UpIndex = new IndexService($conn, $s3UpSchema, $s3UpConfig, new ContentExtractor($s3UpConfig), $s3UpVocab, $logger, new LanguageDetector($conn, $s3UpConfig, $logger));
		$s3UpSearch = new SearchService($conn, $s3UpSchema, $s3UpConfig, new TsQueryBuilder(), $s3UpVocab);
		$s3UpPlatform = new PostgreSQLPlatform($s3UpConfig, $s3UpSchema, $s3UpIndex, $s3UpSearch, $logger, new ContentExtractor($s3UpConfig));

		$v2Norm = static fn (string $c): string => "CASE WHEN config = 'turkish'::regconfig THEN translate($c, 'Iİ', 'ıi') ELSE $c END";
		$v2Title = $v2Norm('title');
		$v2SplitTitle = $s3UpSchema->splitWords($v2Title);
		$v2Tags = $v2Norm('tags_text');
		$v2Parts = $v2Norm('parts_text');
		$v2Content = $v2Norm('content');

		$conn->executeStatement(<<<SQL
			CREATE TABLE ftspg_s3up_index (
				id            bigserial    PRIMARY KEY,
				provider_id   varchar(64)  NOT NULL,
				document_id   varchar(254) NOT NULL,
				owner_id      varchar(64)  NOT NULL DEFAULT '',
				access        text[]       NOT NULL DEFAULT '{}',
				links         text[]       NOT NULL DEFAULT '{}',
				tags          text[]       NOT NULL DEFAULT '{}',
				metatags      text[]       NOT NULL DEFAULT '{}',
				subtags       text[]       NOT NULL DEFAULT '{}',
				source        varchar(64)  NOT NULL DEFAULT '',
				hash          varchar(128) NOT NULL DEFAULT '',
				modified_time bigint       NOT NULL DEFAULT 0,
				indexed_at    bigint       NOT NULL DEFAULT 0,
				config        regconfig    NOT NULL,
				title         text         NOT NULL DEFAULT '',
				tags_text     text         NOT NULL DEFAULT '',
				parts         jsonb        NOT NULL DEFAULT '{}',
				parts_text    text         NOT NULL DEFAULT '',
				content       text         NOT NULL DEFAULT '',
				info          jsonb        NOT NULL DEFAULT '{}',
				tsv           tsvector     GENERATED ALWAYS AS (
					setweight(to_tsvector(config, $v2Title), 'A')
					|| setweight(to_tsvector(config, $v2SplitTitle), 'A')
					|| setweight(to_tsvector(config, $v2Tags), 'B')
					|| setweight(to_tsvector(config, $v2Parts), 'C')
					|| setweight(to_tsvector(config, $v2Content), 'D')
				) STORED,
				title_search  text         GENERATED ALWAYS AS (lower($v2Title)) STORED,
				CONSTRAINT ftspg_s3up_index_doc_uniq UNIQUE (provider_id, document_id)
			)
			SQL);
		$conn->executeStatement("COMMENT ON COLUMN ftspg_s3up_index.tsv IS 'ftspg schema 2'");
		$conn->executeStatement('CREATE INDEX ftspg_s3up_index_tsv_idx ON ftspg_s3up_index USING GIN (tsv)');
		$conn->executeStatement('CREATE INDEX ftspg_s3up_index_access_idx ON ftspg_s3up_index USING GIN (access)');
		$conn->executeStatement('CREATE INDEX ftspg_s3up_index_metatags_idx ON ftspg_s3up_index USING GIN (metatags)');
		$conn->executeStatement('CREATE INDEX ftspg_s3up_index_subtags_idx ON ftspg_s3up_index USING GIN (subtags)');
		$conn->executeStatement(sprintf(
			'CREATE TABLE ftspg_s3up_words (word text COLLATE "C" PRIMARY KEY, folded text COLLATE "C" GENERATED ALWAYS AS (translate(word, \'%s\', \'%s\')) STORED)',
			\OCA\FullTextSearch_PgSql\Tools\Fold::FROM,
			\OCA\FullTextSearch_PgSql\Tools\Fold::TO
		));
		$conn->executeStatement('CREATE INDEX ftspg_s3up_words_folded_idx ON ftspg_s3up_words (folded)');
		if ($s3UpSchema->hasTrigram()) {
			$conn->executeStatement('CREATE INDEX ftspg_s3up_index_title_search_trgm_idx ON ftspg_s3up_index USING GIN (title_search gin_trgm_ops)');
			$conn->executeStatement('CREATE INDEX ftspg_s3up_words_folded_trgm_idx ON ftspg_s3up_words USING GIN (folded gin_trgm_ops)');
		}

		$conn->executeStatement(
			'INSERT INTO ftspg_s3up_index (provider_id, document_id, owner_id, access, source, hash, config, title, tags_text, parts_text, content) VALUES '
			. "('files', 'S3UP_EN1', 'alice', '{u:alice}', 'local', 'hash_en_1', 'english'::regconfig, 'Quarterly budget review', 'finance', 'annual_comment', 'The studies were archived cleanly.'), "
			. "('files', 'S3UP_EN2', 'bob', '{u:bob}', 'external', 'hash_en_2', 'english'::regconfig, 'Private notes', '', '', 'Bob confidential report.'), "
			. "('files', 'S3UP_TR1', 'alice', '{u:alice}', 'local', 'hash_tr_1', 'turkish'::regconfig, 'ISPARTA GÜLLERİ', 'İSTANBUL', 'proje_notu', 'Yeni çalışma kapsamında kitapları inceledik.'), "
			. "('files', 'S3UP_TR2', 'alice', '{u:alice}', 'share', 'hash_tr_2', 'turkish'::regconfig, 'Yıllık toplantı', '', '', 'Veri tabanı planlaması tamamlandı.')"
		);
		$conn->executeStatement(
			'INSERT INTO ftspg_s3up_words (word) VALUES '
			. "('quarterly'), ('budget'), ('studies'), ('archived'), ('çalışma'), ('ısparta'), ('istanbul'), ('kitapları'), ('planlaması')"
		);

		same(false, $s3UpSchema->isCurrent(), 'S3 upgrade fixture: pre-upgrade isCurrent() is false');
		$loadRefused = false;
		try {
			$s3UpPlatform->loadPlatform();
		} catch (\RuntimeException) {
			$loadRefused = true;
		}
		same(true, $loadRefused, 'S3 upgrade fixture: loadPlatform() refuses stale v2 schema');
		same(
			'regconfig',
			(string)$conn->executeQuery("SELECT format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = to_regclass('ftspg_s3up_index') AND attname = 'config' AND NOT attisdropped")->fetchOne(),
			'S3 upgrade fixture: loadPlatform() leaves config as regconfig'
		);

		$s3UpSchema->ensureSchema();
		same(true, $s3UpSchema->isCurrent(), 'S3 upgrade fixture: ensureSchema() brings schema to current');
		same(
			'ftspg schema 3',
			$conn->executeQuery("SELECT col_description(to_regclass('ftspg_s3up_index'), attnum) FROM pg_attribute WHERE attrelid = to_regclass('ftspg_s3up_index') AND attname = 'tsv' AND NOT attisdropped")->fetchOne(),
			'S3 upgrade fixture: tsv comment updated to ftspg schema 3'
		);
		$s3UpFnRow = $conn->executeQuery(
			"SELECT p.provolatile, p.proparallel FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND p.proname = 'ftspg_s3up_regconfig' AND pg_get_function_identity_arguments(p.oid) = 'text'"
		)->fetch();
		same('i', is_array($s3UpFnRow) ? (string)$s3UpFnRow['provolatile'] : null, 'S3 upgrade fixture: ftspg_s3up_regconfig(text) is IMMUTABLE');
		same('s', is_array($s3UpFnRow) ? (string)$s3UpFnRow['proparallel'] : null, 'S3 upgrade fixture: ftspg_s3up_regconfig(text) is PARALLEL SAFE');

		$s3UpColTypes = [];
		$s3UpRegCols = [];
		foreach ($conn->executeQuery("SELECT attname, format_type(atttypid, atttypmod) AS typ FROM pg_attribute WHERE attrelid = to_regclass('ftspg_s3up_index') AND attnum > 0 AND NOT attisdropped")->fetchAll() as $row) {
			$colName = (string)$row['attname'];
			$colType = (string)$row['typ'];
			$s3UpColTypes[$colName] = $colType;
			if (str_starts_with($colType, 'reg')) {
				$s3UpRegCols[] = $colName . ':' . $colType;
			}
		}
		foreach (['config', 'provider_id', 'document_id', 'owner_id', 'source', 'hash'] as $col) {
			same('text', $s3UpColTypes[$col] ?? null, "S3 upgrade fixture: $col migrated to text");
		}
		same([], $s3UpRegCols, 'S3 upgrade fixture: zero reg* columns after upgrade');

		same(4, $s3UpSchema->countDocuments(), 'S3 upgrade fixture: index row count preserved');
		same(9, $s3UpVocab->count(), 'S3 upgrade fixture: vocabulary row count preserved');
		$s3UpEnRow = $conn->executeQuery("SELECT config, owner_id, source, hash FROM ftspg_s3up_index WHERE document_id = 'S3UP_EN1'")->fetch();
		same(['config' => 'english', 'owner_id' => 'alice', 'source' => 'local', 'hash' => 'hash_en_1'], $s3UpEnRow, 'S3 upgrade fixture: English metadata and config preserved');
		$s3UpTrRow = $conn->executeQuery("SELECT config, owner_id, source, hash FROM ftspg_s3up_index WHERE document_id = 'S3UP_TR1'")->fetch();
		same(['config' => 'turkish', 'owner_id' => 'alice', 'source' => 'local', 'hash' => 'hash_tr_1'], $s3UpTrRow, 'S3 upgrade fixture: Turkish metadata and config preserved');

		same(['S3UP_EN1'], search($s3UpPlatform, 'alice', 'study')['ids'], 'S3 upgrade fixture: English stemming search works');
		same(['S3UP_TR1'], search($s3UpPlatform, 'alice', 'kitaplarımız')['ids'], 'S3 upgrade fixture: Turkish stemming search works');
		same(['S3UP_TR1'], search($s3UpPlatform, 'alice', 'ısparta')['ids'], 'S3 upgrade fixture: Turkish lowercase I folding search works');
		same(['S3UP_TR1'], search($s3UpPlatform, 'alice', 'Isparta')['ids'], 'S3 upgrade fixture: Turkish uppercase I folding search works');
		same(['S3UP_TR1'], search($s3UpPlatform, 'alice', 'istanbul')['ids'], 'S3 upgrade fixture: Turkish tags_text İ folding search works');
		same(['S3UP_TR1'], search($s3UpPlatform, 'alice', 'calisma')['ids'], 'S3 upgrade fixture: preserved vocabulary accent variant search works');
		if ($s3UpSchema->hasTrigram()) {
			same(['S3UP_EN1'], search($s3UpPlatform, 'alice', 'quartrly')['ids'], 'S3 upgrade fixture: preserved vocabulary typo correction works');
		}

		$s3LongProvider = str_repeat('p', 80);
		$s3LongDocId = str_repeat('d', 300);
		$s3LongRes = $s3UpPlatform->indexDocument(doc($s3LongDocId, 'alice', 'PostUpgradeWideTitle', 'postupgradewideneedle content', [], $s3LongProvider));
		same(true, $s3LongRes->isStatus(IIndex::INDEX_DONE), 'S3 upgrade fixture: post-upgrade >254 doc_id and >64 provider_id insert succeeds');
		same([$s3LongDocId], search($s3UpPlatform, 'alice', 'postupgradewideneedle', ['provider' => $s3LongProvider])['ids'], 'S3 upgrade fixture: post-upgrade wide-id document is searchable');
	} finally {
		$s3UpCleanup();
	}

	section('pg_catalog reg* column assertion (S3)');
	$ocTableCount = (int)$conn->executeQuery(
		"SELECT count(*)
		 FROM pg_catalog.pg_class c
		 JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
		 WHERE c.relkind IN ('r', 'p')
		   AND n.nspname NOT LIKE 'pg\_%' ESCAPE '\'
		   AND n.nspname <> 'information_schema'
		   AND c.relname IN ('ftspg_oc_index', 'ftspg_oc_words')"
	)->fetchOne();
	check($ocTableCount >= 2, 'S3 catalog: ftspg_oc_index and ftspg_oc_words exist in pg_catalog');
	$regColumns = $conn->executeQuery(
		"SELECT n.nspname || '.' || c.relname || '.' || a.attname || ':' || pg_catalog.format_type(a.atttypid, a.atttypmod) AS col
		 FROM pg_catalog.pg_attribute a
		 JOIN pg_catalog.pg_class c ON c.oid = a.attrelid
		 JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
		 JOIN pg_catalog.pg_type t ON t.oid = a.atttypid
		 LEFT JOIN pg_catalog.pg_type elem ON elem.oid = t.typelem AND t.typcategory = 'A'
		 WHERE c.relkind IN ('r', 'p', 'm')
		   AND n.nspname NOT LIKE 'pg\_%' ESCAPE '\'
		   AND n.nspname <> 'information_schema'
		   AND c.relname LIKE 'ftspg\_%' ESCAPE '\'
		   AND a.attnum > 0
		   AND NOT a.attisdropped
		   AND (t.typname LIKE 'reg%' OR elem.typname LIKE 'reg%')
		 ORDER BY 1"
	)->fetchAll(\PDO::FETCH_COLUMN);
	same([], $regColumns, 'S3 catalog: zero reg* or _reg* columns on any ftspg_* table');

	section('pg_dump / pg_restore under empty search_path (S3)');
	$dsnBody = str_starts_with($dsn, 'pgsql:') ? substr($dsn, 6) : $dsn;
	$rawPairs = str_contains($dsnBody, ';')
		? explode(';', $dsnBody)
		: (preg_split('/\s+/', trim($dsnBody)) ?: []);
	$dsnParts = [];
	foreach ($rawPairs as $pair) {
		$pair = trim($pair);
		if ($pair === '' || !str_contains($pair, '=')) {
			continue;
		}
		[$k, $v] = explode('=', $pair, 2);
		$dsnParts[strtolower(trim($k))] = trim($v);
	}
	$pgEnv = getenv();
	if (!is_array($pgEnv)) {
		$pgEnv = [];
	}
	unset($pgEnv['PGDATABASE']);
	if (isset($dsnParts['host']) && $dsnParts['host'] !== '') {
		$pgEnv['PGHOST'] = $dsnParts['host'];
	}
	if (isset($dsnParts['port']) && $dsnParts['port'] !== '') {
		$pgEnv['PGPORT'] = $dsnParts['port'];
	}
	if (isset($dsnParts['user']) && $dsnParts['user'] !== '') {
		$pgEnv['PGUSER'] = $dsnParts['user'];
	}
	if (isset($dsnParts['password'])) {
		$pgEnv['PGPASSWORD'] = $dsnParts['password'];
	}
	if (isset($dsnParts['sslmode']) && $dsnParts['sslmode'] !== '') {
		$pgEnv['PGSSLMODE'] = $dsnParts['sslmode'];
	}
	$sourceDb = $dsnParts['dbname'] ?? ($dsnParts['user'] ?? 'postgres');
	$restoreDb = 'ftspg_restore_test_' . getmypid();
	$qRestoreDb = '"' . str_replace('"', '""', $restoreDb) . '"';
	$restoreParts = $dsnParts;
	$restoreParts['dbname'] = $restoreDb;
	$restoreDsn = 'pgsql:' . implode(';', array_map(
		static fn (string $k, string $v): string => "$k=$v",
		array_keys($restoreParts),
		array_values($restoreParts)
	));
	$dumpFile = sys_get_temp_dir() . '/ftspg_s3_dump_' . getmypid() . '_' . mt_rand() . '.dump';

	$runPgTool = static function (array $cmd, array $env): array {
		$proc = proc_open($cmd, [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		], $pipes, null, $env);
		if (!is_resource($proc)) {
			throw new \RuntimeException('Failed to spawn ' . $cmd[0]);
		}
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		return [$code, is_string($stdout) ? $stdout : '', is_string($stderr) ? $stderr : ''];
	};

	$restorePdo = null;
	$restoreConn = null;
	$restoreSchema = null;
	$restoreConfig = null;
	$restoreVocab = null;
	$restoreDetector = null;
	$restoreIndex = null;
	$restoreSearch = null;
	$restorePlatform = null;

	try {
		[$dumpVerCode, $dumpVerOut, $dumpVerErr] = $runPgTool(['pg_dump', '--version'], $pgEnv);
		same(0, $dumpVerCode, 'S3 pg_dump --version exits 0: ' . trim($dumpVerOut . ' ' . $dumpVerErr));
		[$restoreVerCode, $restoreVerOut, $restoreVerErr] = $runPgTool(['pg_restore', '--version'], $pgEnv);
		same(0, $restoreVerCode, 'S3 pg_restore --version exits 0: ' . trim($restoreVerOut . ' ' . $restoreVerErr));

		$config->setConfig(['language' => 'turkish,english', 'typo_correction' => true]);
		$platform->indexDocument(doc('S3DUMP_EN', 'alice', 'Annual budget archive', 'The quarterly studies were published in the archive.', [], 's3dump'));
		$platform->indexDocument(doc('S3DUMP_TR', 'alice', 'ISPARTA GÜLLERİ', 'İSTANBUL ofisinde ISPARTA çalışma ve kitapları inceledik.', [], 's3dump'));
		$platform->indexDocument(doc('S3DUMP_IDF', 'alice', 'Budget summary', 'budget common word and rarexylophone needle', [], 's3dump'));
		$sourceCount = $schema->countDocuments();
		check($sourceCount >= 3, 'S3 dump/restore: source database populated');

		[$dumpCode, , $dumpErr] = $runPgTool(['pg_dump', '-w', '-Fc', '-f', $dumpFile, $sourceDb], $pgEnv);
		same(0, $dumpCode, 'S3 pg_dump exits 0' . ($dumpErr === '' ? '' : ': ' . trim($dumpErr)));
		same('', trim($dumpErr), 'S3 pg_dump produces empty stderr');
		check(is_file($dumpFile) && filesize($dumpFile) > 0, 'S3 pg_dump produces non-empty archive');

		[$listCode, $listOut, $listErr] = $runPgTool(['pg_restore', '-l', $dumpFile], $pgEnv);
		same(0, $listCode, 'S3 pg_restore -l exits 0' . ($listErr === '' ? '' : ': ' . trim($listErr)));
		$fnLine = null;
		$tblLine = null;
		foreach (explode("\n", $listOut) as $lineIdx => $line) {
			if ($fnLine === null && preg_match('/\bFUNCTION\b.*\bftspg_oc_regconfig\b/', $line) === 1) {
				$fnLine = $lineIdx;
			}
			if ($tblLine === null && preg_match('/\bTABLE\b.*\bftspg_oc_index\b/', $line) === 1) {
				$tblLine = $lineIdx;
			}
		}
		check($fnLine !== null && $tblLine !== null && $fnLine < $tblLine, 'S3 pg_restore -l places FUNCTION ftspg_oc_regconfig before TABLE ftspg_oc_index');

		$conn->executeStatement("DROP DATABASE IF EXISTS $qRestoreDb WITH (FORCE)");
		$conn->executeStatement("CREATE DATABASE $qRestoreDb TEMPLATE template0");
		[$restoreCode, , $restoreErr] = $runPgTool(['pg_restore', '-w', '-e', '--no-owner', '--no-acl', '-d', $restoreDb, $dumpFile], $pgEnv);
		same(0, $restoreCode, 'S3 pg_restore exits 0' . ($restoreErr === '' ? '' : ': ' . trim($restoreErr)));
		same('', trim($restoreErr), 'S3 pg_restore produces empty stderr');

		$restorePdo = new PDO($restoreDsn);
		$restorePdo->exec("SET search_path = ''");
		$spVal = (string)$restorePdo->query("SELECT pg_catalog.current_setting('search_path')")->fetchColumn();
		check(in_array($spVal, ['', '""'], true), 'S3 empty search_path: current_setting is empty');
		same(true, (bool)$restorePdo->query('SELECT pg_catalog.current_schema() IS NULL')->fetchColumn(), 'S3 empty search_path: current_schema() is NULL');

		$restoreConn = new PdoConnection($restorePdo);
		$restoreSchema = new SchemaService($restoreConn, new ArrayConfig(['dbtableprefix' => 'oc_']), $logger);
		$restoreConfig = new ConfigService(new ArrayAppConfig(), $restoreSchema);
		$restoreConfig->setConfig(['language' => 'turkish,english', 'typo_correction' => true]);
		$restoreVocab = new VocabularyService($restoreConn, $restoreSchema);
		$restoreDetector = new LanguageDetector($restoreConn, $restoreConfig, $logger);
		$restoreExtractor = new ContentExtractor($restoreConfig);
		$restoreIndex = new IndexService($restoreConn, $restoreSchema, $restoreConfig, $restoreExtractor, $restoreVocab, $logger, $restoreDetector);
		$restoreSearch = new SearchService($restoreConn, $restoreSchema, $restoreConfig, new TsQueryBuilder(), $restoreVocab);
		$restorePlatform = new PostgreSQLPlatform($restoreConfig, $restoreSchema, $restoreIndex, $restoreSearch, $logger, $restoreExtractor);

		same(true, $restoreSchema->isCurrent(), 'S3 empty search_path: SchemaService::isCurrent() returns true');
		same($sourceCount, $restoreSchema->countDocuments(), 'S3 empty search_path: SchemaService::countDocuments() matches source');
		same(['S3DUMP_EN'], search($restorePlatform, 'alice', 'study', ['provider' => 's3dump'])['ids'], 'S3 empty search_path: English search works');

		$trDumpRes = search($restorePlatform, 'alice', 'ısparta', ['provider' => 's3dump']);
		same(['S3DUMP_TR'], $trDumpRes['ids'], 'S3 empty search_path: Turkish I/İ search works');
		$trDumpExcerpt = isset($trDumpRes['docs'][0]) ? implode(' ', array_column($trDumpRes['docs'][0]->getExcerpts() ?? [], 'excerpt')) : '';
		check(str_contains($trDumpExcerpt, 'ISPARTA'), 'S3 empty search_path: Turkish ts_headline excerpt preserves casing');

		same(['S3DUMP_IDF', 'S3DUMP_EN'], search($restorePlatform, 'alice', 'budget rarexylophone', ['provider' => 's3dump'])['ids'], 'S3 empty search_path: multi-word IDF search works');
		same(['S3DUMP_TR'], search($restorePlatform, 'alice', 'calisma', ['provider' => 's3dump'])['ids'], 'S3 empty search_path: vocabulary accent variant works');

		if ($restoreSchema->hasTrigram()) {
			same(['S3DUMP_EN'], search($restorePlatform, 'alice', 'quartrly', ['provider' => 's3dump'])['ids'], 'S3 empty search_path: vocabulary typo correction (pg_trgm similarity/%) works');
			$restoreConfig->setConfig(['language' => 'turkish,english', 'typo_correction' => false]);
			same(['S3DUMP_EN'], search($restorePlatform, 'alice', 'annuall', ['provider' => 's3dump'])['ids'], 'S3 empty search_path: title similarity fallback (pg_trgm word_similarity/<%) works');
			$restoreConfig->setConfig(['language' => 'turkish,english', 'typo_correction' => true]);
		}

		$emptySpInsert = $restorePlatform->indexDocument(doc('S3DUMP_EMPTY_SP', 'alice', 'Empty path insert', 'verification payload under empty search path', [], 's3dump'));
		same(true, $emptySpInsert->isStatus(IIndex::INDEX_DONE), 'S3 empty search_path: IndexService::upsert() succeeds');
		same(['S3DUMP_EMPTY_SP'], search($restorePlatform, 'alice', 'verification', ['provider' => 's3dump'])['ids'], 'S3 empty search_path: newly indexed doc is searchable');
		same('Empty path insert', $restorePlatform->getDocument('s3dump', 'S3DUMP_EMPTY_SP')->getTitle(), 'S3 empty search_path: getDocument() round-trips');
	} finally {
		$restorePlatform = null;
		$restoreSearch = null;
		$restoreIndex = null;
		$restoreDetector = null;
		$restoreVocab = null;
		$restoreConfig = null;
		$restoreSchema = null;
		$restoreConn = null;
		$restorePdo = null;
		$conn->executeStatement("DROP DATABASE IF EXISTS $qRestoreDb WITH (FORCE)");
		if (is_file($dumpFile)) {
			@unlink($dumpFile);
		}
		$platform->resetIndex('s3dump');
		$config->setConfig(['language' => 'turkish']);
	}
} catch (Throwable $e) {
	$failures++;
	echo "\n  ERROR " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

section('PHP warnings and deprecations from app code');
same([], array_values(array_unique($appIssues)), 'none raised');

echo "\n$passes passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
