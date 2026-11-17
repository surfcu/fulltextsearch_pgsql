# Moving from Elasticsearch

Switching platforms means rebuilding the index; nothing is copied from Elasticsearch.

## Before you start

- Nextcloud's own database must be PostgreSQL 13+ (with a UTF-8 locale for non-ASCII case folding and accent matching).
- A bundled pure-PHP parser ([smalot/pdfparser](https://github.com/smalot/pdfparser)) indexes PDFs out of the box up to 64 MB; installing `poppler-utils` (`pdftotext`) is recommended for faster extraction and larger or complex PDFs. Install `catdoc` if you want Office 97–2003 (`.doc`, `.xls`, `.ppt`) content indexed.
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
| Ranking | BM25 | `ts_rank_cd` with title > tags > parts > content weights, BM25 inverse-document-frequency (rarity) weighting across visible documents on multi-word searches, and all-word matches first |
| Typos | Fuzzy queries | Viewer-scoped accent variants and vocabulary spelling corrections (`pg_trgm`), plus trigram title similarity when nothing matches |
| Languages | Analyzer per index | Up to 5 PostgreSQL text search configurations, auto-detected per document |
| File formats | Anything Apache Tika reads | Text, ODF, OOXML, Office 97–2003 (with `catdoc`), PDF (`pdftotext` or bundled `smalot/pdfparser`) |
| Scaling | Cluster | Shares your database server |

Office 97–2003 formats (`.doc`, `.xls`, `.ppt`) require `catdoc`; OCR for scanned images and PDFs is not supported. Files without extractable text are still found by name, tags and comments.

## Going back

Select the Elasticsearch platform again and reindex. To free the space used by this platform, disable and remove the app; uninstalling drops its table.
