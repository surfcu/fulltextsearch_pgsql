<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCA\FullTextSearch_PgSql\Tools\PgArray;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

class IndexService {

	private const MAX_TAGS_BYTES = 65536;
	private const STEP4_PARTS_BYTES = 65536;
	private const STEP5_TAGS_BYTES = 16384;
	private const STEP5_TITLE_CHARS = 4000;

	public function __construct(
		private IDBConnection $db,
		private SchemaService $schemaService,
		private ConfigService $configService,
		private ContentExtractor $extractor,
		private VocabularyService $vocabulary,
		private LoggerInterface $logger,
		private LanguageDetector $languageDetector,
	) {
	}

	/**
	 * Insert or update a document.
	 *
	 * When the provider only flagged metadata as changed (e.g. a share was added), the
	 * stored content and parts are kept instead of being overwritten with nothing.
	 *
	 * @return list<string> warnings worth reporting to the index runner
	 * @throws Throwable when the document could not be stored at all
	 */
	public function indexDocument(IIndexDocument $document): array {
		$index = $document->getIndex();
		$updateAll = !$index->isStatus(IIndex::INDEX_OK)
			&& !$index->isStatus(IIndex::INDEX_META)
			&& !$index->isStatus(IIndex::INDEX_CONTENT)
			&& !$index->isStatus(IIndex::INDEX_PARTS);
		$updateContent = $updateAll || $index->isStatus(IIndex::INDEX_CONTENT);
		$updateParts = $updateAll || $index->isStatus(IIndex::INDEX_PARTS);

		$warnings = [];
		$content = '';
		if ($updateContent) {
			$extracted = $this->extractor->extract($document);
			$content = $extracted->text;
			if ($extracted->warning !== null) {
				$warnings[] = $extracted->warning;
			}
		}

		// The language follows the content: when only metadata changed, the stored one stays.
		$language = $updateContent
			? $this->languageDetector->detect($content, implode(' ', $this->strings($document->getParts())), $document->getTitle())
			: $this->configService->getLanguage();

		$extractor = $this->extractor;
		$access = $document->getAccess();

		$tags = array_map([$extractor, 'sanitize'], $this->strings($document->getTags()));
		$metaTags = array_map([$extractor, 'sanitize'], $this->strings($document->getMetaTags()));
		$subtags = array_map([$extractor, 'sanitize'], $this->strings($document->getSubTags(true)));
		$links = array_map([$extractor, 'sanitize'], $this->strings($access->getLinks()));

		$parts = [];
		foreach ($document->getParts() as $name => $value) {
			$val = (string)$value;
			$strip = $extractor->looksLikeMarkup($val, '', true);
			$parts[(string)$name] = $extractor->clean($val, $strip);
		}

		$partsText = implode(' ', $parts);
		$maxContentSize = $this->configService->getMaxContentSize();
		if ($maxContentSize > 0 && strlen($partsText) > $maxContentSize) {
			$partsText = mb_strcut($partsText, 0, $maxContentSize);
		}

		$tagsText = $extractor->clean(implode(' ', array_merge($tags, $metaTags)));
		if (strlen($tagsText) > self::MAX_TAGS_BYTES) {
			$tagsText = mb_strcut($tagsText, 0, self::MAX_TAGS_BYTES);
		}

		$title = $extractor->sanitize($document->getTitle());
		$cleanInfo = $this->cleanInfoMap($document->getInfoAll());
		$partsJson = json_encode((object)$parts, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
		$infoJson = json_encode((object)$cleanInfo, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

		$savepoint0 = 'ftspg_upsert_0';
		$this->createSavepoint($savepoint0);

		try {
			$this->upsert(
				$document,
				$language,
				$content,
				$updateContent,
				$partsText,
				$partsJson,
				$updateParts,
				$tagsText,
				$title,
				$infoJson,
				$tags,
				$metaTags,
				$subtags,
				$links,
			);
			$this->releaseSavepoint($savepoint0);
		} catch (Throwable $e) {
			$this->rollbackSavepoint($savepoint0);
			if (!$this->isProgramLimitExceeded($e)) {
				throw $e;
			}

			$this->logger->warning('Indexing document exceeded tsvector limit (54000), starting degradation ladder', [
				'provider' => $document->getProviderId(),
				'document' => $document->getId(),
				'exception' => $e,
			]);

			$lastException = $e;
			$degraded = false;
			$curContent = $content;
			$curUpdateContent = $updateContent;
			$curPartsText = $partsText;
			$curPartsJson = $partsJson;
			$curUpdateParts = $updateParts;
			$curTagsText = $tagsText;
			$curTitle = $title;

			for ($step = 1; $step <= 5; $step++) {
				$stepWarnings = [];

				switch ($step) {
					case 1:
						if ($content === '') {
							continue 2;
						}
						$curContent = mb_strcut($content, 0, (int)(strlen($content) / 2));
						$curUpdateContent = true;
						$stepWarnings[] = 'indexed with content cut to half: ' . $lastException->getMessage();
						break;

					case 2:
						if ($content === '') {
							continue 2;
						}
						$curContent = mb_strcut($content, 0, (int)(strlen($content) / 4));
						$curUpdateContent = true;
						$stepWarnings[] = 'indexed with content cut to a quarter: ' . $lastException->getMessage();
						break;

					case 3:
						if ($content === '') {
							continue 2;
						}
						$curContent = '';
						$curUpdateContent = true;
						$stepWarnings[] = 'indexed without content: ' . $lastException->getMessage();
						break;

					case 4:
						if (!$updateParts) {
							continue 2;
						}
						if ($content !== '') {
							$curContent = '';
							$curUpdateContent = true;
							$stepWarnings[] = 'indexed without content: ' . $lastException->getMessage();
						}
						$curPartsText = mb_strcut($partsText, 0, self::STEP4_PARTS_BYTES);
						$curPartsJson = $this->truncatePartsJson($parts, self::STEP4_PARTS_BYTES);
						$curUpdateParts = true;
						$stepWarnings[] = 'indexed with parts cut to 64 KB: ' . $lastException->getMessage();
						break;

					case 5:
						if ($updateParts) {
							$curPartsText = mb_strcut($partsText, 0, self::STEP4_PARTS_BYTES);
							$curPartsJson = $this->truncatePartsJson($parts, self::STEP4_PARTS_BYTES);
						}
						$curUpdateParts = $updateParts;
						$curTagsText = mb_strcut($tagsText, 0, self::STEP5_TAGS_BYTES);
						$curTitle = mb_substr($title, 0, self::STEP5_TITLE_CHARS);
						$curContent = $content;
						$curUpdateContent = $updateContent;
						$stepWarnings[] = 'indexed with tags cut to 16 KB and title cut to 4000 characters: ' . $lastException->getMessage();
						break;
				}

				$savepointStep = 'ftspg_upsert_' . $step;
				$this->createSavepoint($savepointStep);

				try {
					$this->upsert(
						$document,
						$language,
						$curContent,
						$curUpdateContent,
						$curPartsText,
						$curPartsJson,
						$curUpdateParts,
						$curTagsText,
						$curTitle,
						$infoJson,
						$tags,
						$metaTags,
						$subtags,
						$links,
					);
					$this->releaseSavepoint($savepointStep);
					foreach ($stepWarnings as $w) {
						$warnings[] = $w;
					}
					$degraded = true;
					break;
				} catch (Throwable $stepEx) {
					$this->rollbackSavepoint($savepointStep);
					if (!$this->isProgramLimitExceeded($stepEx)) {
						throw $stepEx;
					}
					if ($step === 5 && $curContent !== '') {
						$curContent = '';
						$curUpdateContent = true;
						$stepWarnings = [
							'indexed without content: ' . $stepEx->getMessage(),
							'indexed with tags cut to 16 KB and title cut to 4000 characters: ' . $stepEx->getMessage(),
						];
						$savepoint5b = 'ftspg_upsert_5b';
						$this->createSavepoint($savepoint5b);
						try {
							$this->upsert(
								$document,
								$language,
								$curContent,
								$curUpdateContent,
								$curPartsText,
								$curPartsJson,
								$curUpdateParts,
								$curTagsText,
								$curTitle,
								$infoJson,
								$tags,
								$metaTags,
								$subtags,
								$links,
							);
							$this->releaseSavepoint($savepoint5b);
							foreach ($stepWarnings as $w) {
								$warnings[] = $w;
							}
							$degraded = true;
							break;
						} catch (Throwable $step5bEx) {
							$this->rollbackSavepoint($savepoint5b);
							if (!$this->isProgramLimitExceeded($step5bEx)) {
								throw $step5bEx;
							}
							$lastException = $step5bEx;
						}
					} else {
						$lastException = $stepEx;
					}
				}
			}

			if (!$degraded) {
				$this->logger->warning('Indexing document failed after all degradation attempts', [
					'provider' => $document->getProviderId(),
					'document' => $document->getId(),
					'exception' => $lastException,
				]);
				throw $lastException;
			}
		}

		if ($this->configService->useTypoCorrection()) {
			try {
				$this->vocabulary->addDocument($document->getProviderId(), $document->getId(), $updateContent, $updateParts);
			} catch (Throwable $e) {
				// Typo correction is a nicety; never fail indexing over it.
				$this->logger->warning('Could not update the typo correction vocabulary', ['exception' => $e]);
			}
		}

		return $warnings;
	}

	public function deleteDocument(string $providerId, string $documentId): void {
		$this->db->executeStatement(
			'DELETE FROM ' . $this->schemaService->getTableName() . ' WHERE provider_id = ? AND document_id = ?',
			[$providerId, $documentId]
		);
	}

	public function resetProvider(string $providerId): void {
		if ($providerId === 'all') {
			$this->schemaService->truncate();
			return;
		}
		$this->db->executeStatement(
			'DELETE FROM ' . $this->schemaService->getTableName() . ' WHERE provider_id = ?',
			[$providerId]
		);
	}

	/**
	 * @param list<string> $tags
	 * @param list<string> $metaTags
	 * @param list<string> $subtags
	 * @param list<string> $links
	 */
	private function upsert(
		IIndexDocument $document,
		string $language,
		string $content,
		bool $updateContent,
		string $partsText,
		string $partsJson,
		bool $updateParts,
		string $tagsText,
		string $title,
		string $infoJson,
		array $tags,
		array $metaTags,
		array $subtags,
		array $links,
	): void {
		$access = $document->getAccess();
		$t = $this->schemaService->getTableName();
		$this->db->executeStatement(<<<SQL
			INSERT INTO $t AS d (
				provider_id, document_id, owner_id, access, links, tags, metatags, subtags,
				source, hash, modified_time, indexed_at, config,
				title, tags_text, parts, parts_text, content, info
			) VALUES (
				?, ?, ?, CAST(? AS text[]), CAST(? AS text[]), CAST(? AS text[]), CAST(? AS text[]), CAST(? AS text[]),
				?, ?, ?, ?, ?,
				?, ?, CAST(? AS jsonb), ?, ?, CAST(? AS jsonb)
			)
			ON CONFLICT (provider_id, document_id) DO UPDATE SET
				owner_id = EXCLUDED.owner_id,
				access = EXCLUDED.access,
				links = EXCLUDED.links,
				tags = EXCLUDED.tags,
				metatags = EXCLUDED.metatags,
				subtags = EXCLUDED.subtags,
				source = EXCLUDED.source,
				hash = EXCLUDED.hash,
				modified_time = EXCLUDED.modified_time,
				indexed_at = EXCLUDED.indexed_at,
				config = CASE WHEN CAST(? AS boolean) THEN EXCLUDED.config ELSE d.config END,
				title = EXCLUDED.title,
				tags_text = EXCLUDED.tags_text,
				info = EXCLUDED.info,
				parts = CASE WHEN CAST(? AS boolean) THEN EXCLUDED.parts ELSE d.parts END,
				parts_text = CASE WHEN CAST(? AS boolean) THEN EXCLUDED.parts_text ELSE d.parts_text END,
				content = CASE WHEN CAST(? AS boolean) THEN EXCLUDED.content ELSE d.content END
			SQL,
			[
				$document->getProviderId(),
				$document->getId(),
				$access->getOwnerId(),
				PgArray::toLiteral(AccessTokens::forDocument($access)),
				PgArray::toLiteral($links),
				PgArray::toLiteral($tags),
				PgArray::toLiteral($metaTags),
				PgArray::toLiteral($subtags),
				$document->getSource(),
				$document->getHash(),
				(string)$document->getModifiedTime(),
				(string)time(),
				$language,
				$title,
				$tagsText,
				$partsJson,
				$partsText,
				$content,
				$infoJson,
				$updateContent ? 'true' : 'false',
				$updateParts ? 'true' : 'false',
				$updateParts ? 'true' : 'false',
				$updateContent ? 'true' : 'false',
			]
		);
	}

	private function createSavepoint(string $savepoint): void {
		if ($this->db->inTransaction()) {
			$this->db->executeStatement("SAVEPOINT $savepoint");
		}
	}

	private function rollbackSavepoint(string $savepoint): void {
		if ($this->db->inTransaction()) {
			try {
				$this->db->executeStatement("ROLLBACK TO SAVEPOINT $savepoint");
			} catch (Throwable) {
			}
		}
	}

	private function releaseSavepoint(string $savepoint): void {
		if ($this->db->inTransaction()) {
			try {
				$this->db->executeStatement("RELEASE SAVEPOINT $savepoint");
			} catch (Throwable) {
			}
		}
	}

	/**
	 * Detect whether an exception represents PostgreSQL SQLSTATE 54000
	 * (ERRCODE_PROGRAM_LIMIT_EXCEEDED, e.g. "string is too long for tsvector").
	 */
	private function isProgramLimitExceeded(Throwable $e): bool {
		for ($curr = $e; $curr !== null; $curr = $curr->getPrevious()) {
			if (method_exists($curr, 'getSQLState') && $curr->getSQLState() === '54000') {
				return true;
			}
			if ((string)$curr->getCode() === '54000') {
				return true;
			}
			if ($curr instanceof \PDOException && isset($curr->errorInfo[0]) && (string)$curr->errorInfo[0] === '54000') {
				return true;
			}
			$msg = $curr->getMessage();
			if (stripos($msg, 'string is too long for tsvector') !== false) {
				return true;
			}
			if (preg_match('/(?:SQLSTATE\[54000\]|\bSQLSTATE\s+54000\b|program_limit_exceeded)/i', $msg) === 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recursively strip NUL bytes (\0) from info map keys and string values
	 * to prevent PostgreSQL SQLSTATE 22P05 when casting to jsonb.
	 *
	 * @param array<mixed, mixed> $info
	 * @return array<mixed, mixed>
	 */
	private function cleanInfoMap(array $info): array {
		$clean = [];
		foreach ($info as $key => $value) {
			$cleanKey = is_string($key) ? str_replace("\0", '', $key) : $key;
			if (is_array($value)) {
				$clean[$cleanKey] = $this->cleanInfoMap($value);
			} elseif (is_string($value)) {
				$clean[$cleanKey] = str_replace("\0", '', $value);
			} else {
				$clean[$cleanKey] = $value;
			}
		}
		return $clean;
	}

	/**
	 * Truncate parts array values to ensure jsonb representation fits within maxBytes.
	 *
	 * @param array<string, string> $parts
	 */
	private function truncatePartsJson(array $parts, int $maxBytes): string {
		$truncated = [];
		$remaining = $maxBytes;
		foreach ($parts as $name => $value) {
			if ($remaining <= 0) {
				break;
			}
			$cut = mb_strcut($value, 0, $remaining);
			$truncated[$name] = $cut;
			$remaining -= strlen($cut);
		}
		return json_encode((object)$truncated, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	/** @return list<string> */
	private function strings(array $values): array {
		$out = [];
		foreach ($values as $value) {
			if (is_scalar($value) && (string)$value !== '') {
				$out[] = (string)$value;
			}
		}
		return array_values(array_unique($out));
	}
}
