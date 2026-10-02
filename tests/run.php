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
use OCA\FullTextSearch_PgSql\Platform\PostgreSQLPlatform;
use OCA\FullTextSearch_PgSql\Service\ConfigService;
use OCA\FullTextSearch_PgSql\Service\ContentExtractor;
use OCA\FullTextSearch_PgSql\Service\IndexService;
use OCA\FullTextSearch_PgSql\Service\SchemaService;
use OCA\FullTextSearch_PgSql\Service\SearchService;
use OCA\FullTextSearch_PgSql\Service\TsQueryBuilder;
use OCP\FullTextSearch\IFullTextSearchProvider;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchResult;
use PDO;
use Throwable;
use ZipArchive;

require __DIR__ . '/bootstrap.php';

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
$indexService = new IndexService($conn, $schema, $config, $extractor, $logger);
$searchService = new SearchService($conn, $schema, $config, new TsQueryBuilder());
$platform = new PostgreSQLPlatform($config, $schema, $indexService, $searchService, $logger);

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
	]);
	$provider = double(IFullTextSearchProvider::class, ['getId' => $opts['provider'] ?? 'files']);
	$result = double(ISearchResult::class, [
		'getRequest' => $request,
		'getProvider' => $provider,
		'addDocument' => function (IIndexDocument $d) use (&$docs) { $docs[] = $d; },
		'setTotal' => function (int $t) use (&$total) { $total = $t; },
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
	foreach (["'); DROP TABLE ftspg_oc_index; --", "a & | ! :* <-> (", '"', '\\', "\0", "\xff\xfe broken utf8"] as $nasty) {
		search($platform, 'alice', $nasty);
	}
	check($schema->tableExists(), 'hostile queries do not error or inject');
	same(['Q1', 'Q3'], search($platform, 'alice', '(budget), review!')['ids'], 'edge punctuation ignored; doc with both words first');
	same(['Q1'], search($platform, 'alice', '+budget +review')['ids'], '+ makes words required');
	same(['Q1'], search($platform, 'alice', '+budg +revi')['ids'], 'required words still match as prefixes');
	same(['Q3'], search($platform, 'alice', '+budget -review')['ids'], 'required plus exclusion');
	same(['Q1', 'Q3'], sorted(search($platform, 'alice', 'budget -revi')['ids']), 'exclusions are exact words, not prefixes');

	section('typo tolerance');
	same(['Q2'], search($platform, 'alice', 'markting plann')['ids'], 'typo falls back to title similarity');
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
	$p1 = search($platform, 'alice', 'invoice', ['size' => 2, 'page' => 1]);
	$p2 = search($platform, 'alice', 'invoice', ['size' => 2, 'page' => 2]);
	same(3, $p1['total'], 'total counts all matches');
	same(2, count($p1['ids']), 'page 1 has page-size results');
	same(1, count($p2['ids']), 'page 2 has the remainder');
	same([], array_values(array_intersect($p1['ids'], $p2['ids'])), 'pages do not overlap');

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
		'word/document.xml' => '<w:document><w:body><w:p><w:r><w:t>Quarterly</w:t></w:r><w:r><w:t>hippopotamus</w:t></w:r></w:p><w:p><w:t>census</w:t></w:p></w:body></w:document>',
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

	section('query plan uses the indexes');
	$conn->executeStatement('SET enable_seqscan = off');
	$plan = implode("\n", array_column($conn->executeQuery(
		"EXPLAIN SELECT id FROM ftspg_oc_index d WHERE d.tsv @@ to_tsquery('english', 'budget:*') AND d.access && '{u:alice}'::text[]"
	)->fetchAll(), 'QUERY PLAN'));
	check(str_contains($plan, 'ftspg_oc_index_tsv_idx') || str_contains($plan, 'ftspg_oc_index_access_idx'), 'GIN index used');
	$conn->executeStatement('SET enable_seqscan = on');
} catch (Throwable $e) {
	$failures++;
	echo "\n  ERROR " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

echo "\n$passes passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
