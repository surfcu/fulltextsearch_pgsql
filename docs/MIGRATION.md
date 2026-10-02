# Moving from Elasticsearch

Switching platforms means rebuilding the index; nothing is copied from Elasticsearch.

## Before you start

- Nextcloud's own database must be PostgreSQL 12+.
- Install `poppler-utils` if you want PDF content indexed. Elasticsearch extracted it with its ingest-attachment plugin; this platform uses `pdftotext`.
- Expect the index to take roughly 1–2× the size of the extracted text in your database, and plan backups accordingly.

## Steps

```bash
# 1. Stop the live indexer if you run it (systemd unit, screen session, …)

# 2. Install and select the PostgreSQL platform
occ app:enable fulltextsearch_pgsql
occ fulltextsearch_pgsql:configure '{"language":"english"}'
occ fulltextsearch:configure '{"search_platform":"OCA\\FullTextSearch_PgSql\\Platform\\PostgreSQLPlatform"}'
occ fulltextsearch:test

# 3. Rebuild
occ fulltextsearch:reset
occ fulltextsearch:index

# 4. Restart the live indexer
```

Searching keeps working on the Elasticsearch index until you switch the platform in step 2. After that, results fill in as `fulltextsearch:index` progresses.

## Differences you may notice

| | Elasticsearch | PostgreSQL platform |
|---|---|---|
| Query syntax | `word`, `+word`, `-word`, `"phrase"` | Same |
| Partial words | Depends on analyzer | Words always match as prefixes |
| Ranking | BM25 | `ts_rank_cd` with title > tags > parts > content weights; all-word matches first |
| Typos | Fuzzy queries | Trigram similarity on titles, when nothing matches exactly |
| Languages | Analyzer per index | One PostgreSQL text search configuration per instance |
| File formats | Anything Apache Tika reads | Text, ODF, OOXML, PDF (via `pdftotext`) |
| Scaling | Cluster | Shares your database server |

Legacy binary Office formats (`.doc`, `.xls`, `.ppt`) and OCR are not supported; those files are still found by name, tags and comments.

## Going back

Select the Elasticsearch platform again and reindex. To free the space used by this platform, disable and remove the app; uninstalling drops its table.
