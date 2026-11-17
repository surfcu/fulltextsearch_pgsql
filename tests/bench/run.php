<?php

declare(strict_types=1);

/**
 * Synthetic Full-Text Search Benchmark Harness (100,000 documents)
 *
 * Usage:
 *   php tests/bench/run.php [--docs=100000] [--runs=5] [--warmup=1] [--generate] [--format=markdown]
 *   .agents/bin/dev bench --output=baseline.json
 *   .agents/bin/dev bench --compare=baseline.json
 */

namespace OCA\FullTextSearch_PgSql\Bench;

use OC\FullTextSearch\Model\DocumentAccess;
use OCA\FullTextSearch_PgSql\Platform\PostgreSQLPlatform;
use OCA\FullTextSearch_PgSql\Service\ConfigService;
use OCA\FullTextSearch_PgSql\Service\ContentExtractor;
use OCA\FullTextSearch_PgSql\Service\IndexService;
use OCA\FullTextSearch_PgSql\Service\LanguageDetector;
use OCA\FullTextSearch_PgSql\Service\SchemaService;
use OCA\FullTextSearch_PgSql\Service\SearchService;
use OCA\FullTextSearch_PgSql\Service\TsQueryBuilder;
use OCA\FullTextSearch_PgSql\Service\VocabularyService;
use OCA\FullTextSearch_PgSql\Tests\ArrayAppConfig;
use OCA\FullTextSearch_PgSql\Tests\ArrayConfig;
use OCA\FullTextSearch_PgSql\Tests\MemoryLogger;
use OCA\FullTextSearch_PgSql\Tests\PdoConnection;
use OCP\FullTextSearch\IFullTextSearchProvider;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchResult;
use PDO;
use function OCA\FullTextSearch_PgSql\Tests\double;

require dirname(__DIR__) . '/bootstrap.php';

// Deterministic Pseudo-Random Generator (XorShift32)
class XorShift32 {
	private int $state;

	public function __construct(int $seed = 42) {
		$this->state = ($seed !== 0) ? ($seed & 0xFFFFFFFF) : 123456789;
	}

	public function nextInt(): int {
		$x = $this->state;
		$x ^= ($x << 13) & 0xFFFFFFFF;
		$x ^= ($x >> 17);
		$x ^= ($x << 5) & 0xFFFFFFFF;
		$this->state = $x & 0xFFFFFFFF;
		return $this->state;
	}

	public function nextFloat(): float {
		return ($this->nextInt() & 0x7FFFFFFF) / 2147483648.0;
	}
}

// Zipfian Distribution Generator
class ZipfGenerator {
	/** @var list<float> */
	private array $cdf = [];
	private int $vocabSize;

	public function __construct(int $vocabSize = 8000, float $s = 1.12) {
		$this->vocabSize = $vocabSize;
		$sum = 0.0;
		$weights = [];
		for ($i = 1; $i <= $vocabSize; $i++) {
			$w = 1.0 / pow($i, $s);
			$weights[] = $w;
			$sum += $w;
		}
		$acc = 0.0;
		foreach ($weights as $w) {
			$acc += $w / $sum;
			$this->cdf[] = $acc;
		}
		$this->cdf[$vocabSize - 1] = 1.0;
	}

	public function sample(float $rand): int {
		$low = 0;
		$high = $this->vocabSize - 1;
		while ($low < $high) {
			$mid = ($low + $high) >> 1;
			if ($rand < $this->cdf[$mid]) {
				$high = $mid;
			} else {
				$low = $mid + 1;
			}
		}
		return $low;
	}

	public static function wordForIndex(int $index): string {
		return 'w' . chr(97 + intdiv($index, 676)) . chr(97 + intdiv($index % 676, 26)) . chr(97 + ($index % 26));
	}
}

// CLI Option Parsing
$options = getopt('', [
	'docs::',
	'runs::',
	'warmup::',
	'seed::',
	'generate',
	'cleanup',
	'only::',
	'format::',
	'output::',
	'compare::',
	'check-budget::',
	'help',
]);

if (isset($options['help'])) {
	echo "Usage: php tests/bench/run.php [options]\n";
	echo "Options:\n";
	echo "  --docs=N            Target synthetic document count (default: 100000)\n";
	echo "  --runs=N            Number of timed iterations per scenario (default: 5)\n";
	echo "  --warmup=N          Number of untimed warmup runs (default: 1)\n";
	echo "  --seed=N            PRNG seed for deterministic dataset (default: 42)\n";
	echo "  --generate          Force dataset generation even if table already has rows\n";
	echo "  --cleanup           Drop benchmark dataset after running\n";
	echo "  --only=NAME         Run only specified scenario\n";
	echo "  --format=FMT        Output format: table, markdown (default), json\n";
	echo "  --output=FILE       Write output to file\n";
	echo "  --compare=FILE      Compare current run against baseline JSON\n";
	echo "  --check-budget=NAME Validate against budget (e.g. 'a3')\n";
	exit(0);
}

$targetDocs = isset($options['docs']) ? (int)$options['docs'] : (int)(getenv('FTSPG_BENCH_DOCS') ?: 100000);
$runs = isset($options['runs']) ? (int)$options['runs'] : (int)(getenv('FTSPG_BENCH_RUNS') ?: 5);
$warmup = isset($options['warmup']) ? (int)$options['warmup'] : 1;
$seed = isset($options['seed']) ? (int)$options['seed'] : (int)(getenv('FTSPG_BENCH_SEED') ?: 42);
$forceGenerate = isset($options['generate']);
$cleanup = isset($options['cleanup']);
$onlyScenario = is_string($options['only'] ?? null) ? $options['only'] : null;
$format = is_string($options['format'] ?? null) ? $options['format'] : 'markdown';
$outputFile = is_string($options['output'] ?? null) ? $options['output'] : null;
$compareFile = is_string($options['compare'] ?? null) ? $options['compare'] : null;
$checkBudget = is_string($options['check-budget'] ?? null) ? $options['check-budget'] : null;

$dsn = getenv('FTSPG_TEST_DSN') ?: 'pgsql:host=localhost;port=5432;dbname=fts_test;user=postgres;password=postgres';
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn = new PdoConnection($pdo);

// Initialize service wiring
$appConfig = new ArrayAppConfig();
$logger = new MemoryLogger();
$schema = new SchemaService($conn, new ArrayConfig(['dbtableprefix' => 'oc_']), $logger);
$config = new ConfigService($appConfig, $schema);
$config->setConfig(['language' => 'english']);
$extractor = new ContentExtractor($config);
$vocabulary = new VocabularyService($conn, $schema);
$detector = new LanguageDetector($conn, $config, $logger);
$indexService = new IndexService($conn, $schema, $config, $extractor, $vocabulary, $logger, $detector);
$searchService = new SearchService($conn, $schema, $config, new TsQueryBuilder(), $vocabulary);
$platform = new PostgreSQLPlatform($config, $schema, $indexService, $searchService, $logger, $extractor);

$schema->ensureSchema();
$tableName = $schema->getTableName();
$wordsTableName = $schema->getWordsTableName();

// Check existing document count
$stmt = $pdo->query("SELECT count(*) FROM $tableName WHERE provider_id = 'files' AND owner_id = 'bench_user'");
$currentDocs = (int)$stmt->fetchColumn();

if ($forceGenerate || $currentDocs < $targetDocs) {
	echo "Generating $targetDocs synthetic documents (seed: $seed)...\n";
	$tGenStart = hrtime(true);

	// Fast insertion: drop indexes temporarily
	$pdo->exec("DROP INDEX IF EXISTS {$tableName}_tsv_idx");
	$pdo->exec("DROP INDEX IF EXISTS {$tableName}_access_idx");
	$pdo->exec("DROP INDEX IF EXISTS {$tableName}_title_search_trgm_idx");
	$pdo->exec("TRUNCATE $tableName, $wordsTableName");

	$pdo->exec('SET synchronous_commit = off');
	$pdo->exec("SET maintenance_work_mem = '256MB'");

	$prng = new XorShift32($seed);
	$zipf = new ZipfGenerator(8000, 1.12);

	// Pre-generate vocabulary words array
	$vocab = [];
	for ($v = 0; $v < 8000; $v++) {
		$vocab[$v] = ZipfGenerator::wordForIndex($v);
	}

	$batchSize = 1000;
	$batches = (int)ceil($targetDocs / $batchSize);
	$docId = 1;

	for ($b = 0; $b < $batches; $b++) {
		$pdo->beginTransaction();
		$currentBatchSize = min($batchSize, $targetDocs - ($b * $batchSize));
		$placeholders = [];
		$params = [];

		for ($i = 0; $i < $currentBatchSize; $i++) {
			$wordCount = ($docId % 20 === 0) ? 8000 : 300;
			$words = [];
			for ($w = 0; $w < $wordCount; $w++) {
				$words[] = $vocab[$zipf->sample($prng->nextFloat())];
			}
			$content = implode(' ', $words);
			$title = "Document $docId {$words[0]} {$words[1]}";
			$providerId = 'files';
			$documentId = "bench_$docId";
			$ownerId = 'bench_user';
			$access = '{u:__all,u:bench_user}';

			$placeholders[] = "(?, ?, ?, ?::text[], 'english', ?, ?)";
			array_push($params, $providerId, $documentId, $ownerId, $access, $title, $content);
			$docId++;
		}

		$sql = "INSERT INTO $tableName (provider_id, document_id, owner_id, access, config, title, content) VALUES " . implode(', ', $placeholders);
		$insertStmt = $pdo->prepare($sql);
		$insertStmt->execute($params);
		$pdo->commit();

		if (($b + 1) % 10 === 0 || $b === $batches - 1) {
			echo '  Indexed ' . min($docId - 1, $targetDocs) . " / $targetDocs documents\n";
		}
	}

	ensureExtraDocs($pdo, $tableName, $wordsTableName, $targetDocs, $seed, false);

	echo "Rebuilding GIN indexes and vocabulary...\n";
	$pdo->exec("CREATE INDEX {$tableName}_tsv_idx ON $tableName USING GIN (tsv)");
	$pdo->exec("CREATE INDEX {$tableName}_access_idx ON $tableName USING GIN (access)");
	if ($schema->hasTrigram()) {
		$pdo->exec("CREATE INDEX {$tableName}_title_search_trgm_idx ON $tableName USING GIN (title_search gin_trgm_ops)");
	}

	// Populate words table
	$wordChunks = array_chunk($vocab, 1000);
	foreach ($wordChunks as $chunk) {
		$wValues = implode(', ', array_map(fn ($w) => "('$w')", $chunk));
		$pdo->exec("INSERT INTO $wordsTableName (word) VALUES $wValues ON CONFLICT DO NOTHING");
	}

	$pdo->exec("ANALYZE $tableName");
	$pdo->exec("ANALYZE $wordsTableName");

	$tGenSec = (hrtime(true) - $tGenStart) / 1e9;
	echo sprintf("Dataset generated in %.2f seconds.\n\n", $tGenSec);
} else {
	echo "Reusing existing dataset with $currentDocs documents.\n\n";
	ensureExtraDocs($pdo, $tableName, $wordsTableName, $targetDocs, $seed, true);
}

function ensureExtraDocs(
	PDO $pdo,
	string $tableName,
	string $wordsTableName,
	int $targetDocs,
	int $seed,
	bool $analyzeIfChanged,
): void {
	$inserted = false;

	$privCount = (int)$pdo->query("SELECT count(*) FROM $tableName WHERE provider_id = 'files' AND owner_id = 'other_user'")->fetchColumn();
	if ($privCount === 0) {
		$privDocs = min(5000, max(100, intdiv($targetDocs, 20)));
		echo "Seeding $privDocs private documents for A3 worst-case scenario...\n";
		$privContent = trim(str_repeat('privatetoken ', 10));
		$batchSize = 1000;
		$batches = (int)ceil($privDocs / $batchSize);
		$docNum = 1;
		for ($b = 0; $b < $batches; $b++) {
			$pdo->beginTransaction();
			$currentBatchSize = min($batchSize, $privDocs - ($b * $batchSize));
			$placeholders = [];
			$params = [];
			for ($i = 0; $i < $currentBatchSize; $i++) {
				$content = ($docNum === 1 ? 'privatetokem ' : '') . $privContent;
				$placeholders[] = "(?, ?, ?, ?::text[], 'english', ?, ?)";
				array_push($params, 'files', "bench_priv_$docNum", 'other_user', '{u:other_user}', "Private $docNum", $content);
				$docNum++;
			}
			$sql = "INSERT INTO $tableName (provider_id, document_id, owner_id, access, config, title, content) VALUES " . implode(', ', $placeholders);
			$pdo->prepare($sql)->execute($params);
			$pdo->commit();
		}
		$pdo->exec("INSERT INTO $wordsTableName (word) VALUES ('privatetoken'), ('privatetokem') ON CONFLICT (word) DO NOTHING");
		$inserted = true;
	}

	$a4Count = (int)$pdo->query("SELECT count(*) FROM $tableName WHERE provider_id = 'bench_a4'")->fetchColumn();
	if ($a4Count === 0) {
		echo "Seeding 20x ~1.1 MB documents for A4 excerpt scenario...\n";
		$a4Prng = new XorShift32($seed + 1);
		$fillerPool = [];
		for ($k = 0; $k < 256; $k++) {
			$fillerPool[] = 'ex' . chr(97 + intdiv($k, 26)) . chr(97 + ($k % 26)) . 'aa';
		}
		$a4Docs = 20;
		$batchSize = 5;
		$docNum = 1;
		for ($b = 0; $b < (int)ceil($a4Docs / $batchSize); $b++) {
			$pdo->beginTransaction();
			$currentBatchSize = min($batchSize, $a4Docs - ($b * $batchSize));
			$placeholders = [];
			$params = [];
			for ($i = 0; $i < $currentBatchSize; $i++) {
				$words = [];
				for ($w = 0; $w < 160000; $w++) {
					$words[] = ($w === 120000) ? 'excerptneedle' : $fillerPool[$a4Prng->nextInt() & 255];
				}
				$placeholders[] = "(?, ?, ?, ?::text[], 'english', ?, ?)";
				array_push($params, 'bench_a4', "bench_a4_$docNum", 'bench_user', '{u:__all,u:bench_user}', "Excerpt Benchmark Document $docNum", implode(' ', $words));
				$docNum++;
			}
			$sql = "INSERT INTO $tableName (provider_id, document_id, owner_id, access, config, title, content) VALUES " . implode(', ', $placeholders);
			$pdo->prepare($sql)->execute($params);
			$pdo->commit();
		}
		$pdo->exec("INSERT INTO $wordsTableName (word) VALUES ('excerptneedle') ON CONFLICT (word) DO NOTHING");
		$inserted = true;
	}

	if ($inserted && $analyzeIfChanged) {
		$pdo->exec("ANALYZE $tableName");
		$pdo->exec("ANALYZE $wordsTableName");
	}
}

// Helper search function
function runSearch(
	PostgreSQLPlatform $platform,
	string $query,
	string $providerId = 'files',
	string $viewerId = 'bench_user',
	int $size = 20,
): array {
	$docs = [];
	$total = 0;
	$request = double(ISearchRequest::class, [
		'getSearch' => $query,
		'getSize' => $size,
		'getPage' => 1,
		'getMetaTags' => [],
		'getSubTags' => [],
		'getOption' => fn (string $o, string $d = '') => $d,
		'getRegexFilters' => [],
		'getWildcardFilters' => [],
		'getLimitFields' => [],
	]);
	$provider = double(IFullTextSearchProvider::class, ['getId' => $providerId]);
	$result = double(ISearchResult::class, [
		'getRequest' => $request,
		'getProvider' => $provider,
		'addDocument' => function (IIndexDocument $d) use (&$docs) {
			$docs[] = $d;
		},
		'setTotal' => function (int $t) use (&$total) {
			$total = $t;
		},
		'setTime' => fn (int $t) => null,
		'setMaxScore' => fn (int $s) => null,
		'setTimedOut' => fn (bool $to) => null,
	]);
	$access = new DocumentAccess($viewerId);
	$access->setViewerId($viewerId);
	$platform->searchRequest($result, $access);
	return ['count' => count($docs), 'total' => $total];
}

// Scenarios based on docs/ARCHITECTURE.md lines 189-197
$scenarios = [
	[
		'id' => 'rare',
		'name' => 'one word (rare)',
		'query' => ZipfGenerator::wordForIndex(7499), // rank 7500 (~195 matches)
	],
	[
		'id' => 'frequent',
		'name' => 'one word (frequent)',
		'query' => ZipfGenerator::wordForIndex(14),   // rank 15 (~90,000 matches)
	],
	[
		'id' => 'two-word',
		'name' => 'two words, mid frequency',
		'query' => ZipfGenerator::wordForIndex(199) . ' ' . ZipfGenerator::wordForIndex(249), // ranks 200 & 250
	],
	[
		'id' => 'three-word',
		'name' => 'three words, mid frequency',
		'query' => ZipfGenerator::wordForIndex(249) . ' ' . ZipfGenerator::wordForIndex(299) . ' ' . ZipfGenerator::wordForIndex(349), // ranks 250, 300, 350
	],
	[
		'id' => 'common-multi',
		'name' => 'two or three words, one in 90% of documents',
		'query' => ZipfGenerator::wordForIndex(14) . ' ' . ZipfGenerator::wordForIndex(199), // rank 15 + rank 200
	],
	[
		'id' => 'a5-stopword',
		'name' => 'stopword multi-word (A5)',
		'query' => 'the ' . ZipfGenerator::wordForIndex(199) . ' for ' . ZipfGenerator::wordForIndex(249),
	],
	[
		'id' => 'a3-worst-case',
		'name' => 'absent private word (A3 worst case)',
		'query' => 'privatetoken',
	],
	[
		'id' => 'a4-excerpt',
		'name' => '20x large excerpts (A4)',
		'query' => 'excerptneedle',
		'provider' => 'bench_a4',
		'size' => 20,
	],
];

$results = [];

foreach ($scenarios as $sc) {
	if ($onlyScenario !== null && $sc['id'] !== $onlyScenario && $sc['name'] !== $onlyScenario) {
		continue;
	}

	$providerId = $sc['provider'] ?? 'files';
	$viewerId = $sc['viewer'] ?? 'bench_user';
	$size = $sc['size'] ?? 20;

	// Warmup
	$warmupRes = null;
	for ($w = 0; $w < $warmup; $w++) {
		$warmupRes = runSearch($platform, $sc['query'], $providerId, $viewerId, $size);
	}

	// Timed runs
	$times = [];
	$lastTotal = $warmupRes['total'] ?? 0;
	for ($r = 0; $r < $runs; $r++) {
		$t0 = hrtime(true);
		$res = runSearch($platform, $sc['query'], $providerId, $viewerId, $size);
		$t1 = hrtime(true);
		$times[] = ($t1 - $t0) / 1e6;
		$lastTotal = $res['total'];
	}

	sort($times);
	$median = $times[(int)floor(count($times) / 2)];
	$min = $times[0];
	$max = $times[count($times) - 1];

	$results[] = [
		'id' => $sc['id'],
		'name' => $sc['name'],
		'query' => $sc['query'],
		'matches' => $lastTotal,
		'median_ms' => round($median, 2),
		'min_ms' => round($min, 2),
		'max_ms' => round($max, 2),
		'runs_ms' => array_map(fn ($t) => round($t, 2), $times),
	];
}

// Compare mode
$baseline = null;
if ($compareFile !== null && file_exists($compareFile)) {
	$baseline = json_decode((string)file_get_contents($compareFile), true);
}

// Formatting
$outStr = '';
if ($format === 'json') {
	$payload = [
		'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
		'docs' => $targetDocs,
		'seed' => $seed,
		'runs' => $runs,
		'warmup' => $warmup,
		'results' => $results,
	];
	$outStr = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
	if ($baseline !== null) {
		$baseMap = [];
		foreach ($baseline['results'] ?? [] as $bRow) {
			$baseMap[$bRow['id']] = $bRow;
		}

		$outStr .= "| Search | Matches | Baseline | Candidate | Delta | % Change | Gate Status |\n";
		$outStr .= "|---|---|---|---|---|---|---|\n";

		foreach ($results as $r) {
			$b = $baseMap[$r['id']] ?? null;
			if ($b !== null) {
				$delta = $r['median_ms'] - $b['median_ms'];
				$pct = $b['median_ms'] > 0 ? ($delta / $b['median_ms']) * 100 : 0;
				$status = 'PASS';
				if ($pct > 10.0) {
					$status = 'REGRESSION (>10%)';
				}
				if ($checkBudget === 'a3') {
					if (
						$r['id'] === 'rare'
						&& ($delta > 2.0 || ($b['median_ms'] > 0 && ($r['median_ms'] / $b['median_ms']) > 2.0))
					) {
						$status = 'BREACH (>+2ms or >2x)';
					} elseif (
						$r['id'] === 'a3-worst-case'
						&& $b['median_ms'] > 0
						&& ($r['median_ms'] / $b['median_ms']) > 2.0
					) {
						$status = 'BREACH (>2x)';
					}
				}
				$sign = $delta >= 0 ? '+' : '';
				$outStr .= sprintf(
					"| %s | %s | %.2f ms | %.2f ms | %s%.2f ms | %s%.1f%% | %s |\n",
					$r['name'],
					number_format($r['matches']),
					$b['median_ms'],
					$r['median_ms'],
					$sign, $delta,
					$sign, $pct,
					$status
				);
			} else {
				$outStr .= sprintf("| %s | %s | - | %.2f ms | - | - | NEW |\n", $r['name'], number_format($r['matches']), $r['median_ms']);
			}
		}
	} else {
		$outStr .= "| Search | Matches | Median | Min | Max |\n";
		$outStr .= "|---|---|---|---|---|\n";
		foreach ($results as $r) {
			$outStr .= sprintf(
				"| %s | %s | %.2f ms | %.2f ms | %.2f ms |\n",
				$r['name'],
				number_format($r['matches']),
				$r['median_ms'],
				$r['min_ms'],
				$r['max_ms']
			);
		}
	}
}

echo $outStr;

if ($outputFile !== null) {
	file_put_contents($outputFile, $outStr);
	echo "Results written to $outputFile\n";
}

if ($cleanup) {
	echo "Cleaning up benchmark dataset...\n";
	$schema->truncate();
}
