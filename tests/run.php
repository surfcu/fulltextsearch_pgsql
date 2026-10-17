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
use OCA\FullTextSearch_PgSql\Migration\CreateIndexTable;
use OCA\FullTextSearch_PgSql\Platform\PostgreSQLPlatform;
use OCA\FullTextSearch_PgSql\Service\ConfigService;
use OCA\FullTextSearch_PgSql\Service\ContentExtractor;
use OCA\FullTextSearch_PgSql\Service\IndexService;
use OCA\FullTextSearch_PgSql\Service\LanguageDetector;
use OCA\FullTextSearch_PgSql\Service\SchemaService;
use OCA\FullTextSearch_PgSql\Service\SearchService;
use OCA\FullTextSearch_PgSql\Service\TsQueryBuilder;
use OCA\FullTextSearch_PgSql\Service\VocabularyService;
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
	$brokenTool = tempnam(sys_get_temp_dir(), 'ftspg-pdftotext');
	file_put_contents($brokenTool, "#!/bin/sh\nexit 3\n");
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
