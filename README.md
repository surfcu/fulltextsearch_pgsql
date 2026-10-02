# Full Text Search - PostgreSQL

A search platform for Nextcloud's [Full Text Search](https://github.com/nextcloud/fulltextsearch) that uses PostgreSQL's built-in full-text search instead of Elasticsearch. If Nextcloud already runs on PostgreSQL, there is nothing else to install or operate.

## Features

- **Ranked results with excerpts.** Title matches outrank tags, which outrank comments and other document parts, which outrank body text. Excerpts come from `ts_headline`.
- **Search-as-you-type.** Words match as prefixes: `rap` finds *rapor*, *raporlar*.
- **Query syntax** (the Full Text Search framework's standard, same as the Elasticsearch platform): plain words are optional and any of them can match, `+word` is required, `-word` is excluded, `"exact phrase"` works with or without `+`/`-`. Documents containing every word are always listed before partial matches. Queries made only of exclusions or stopwords return nothing rather than everything.
- **Typo tolerance.** When nothing matches, titles are searched by trigram similarity (`markting` finds *Marketing plan*). Needs the `pg_trgm` extension.
- **Any language your PostgreSQL ships**, including Turkish, with correct dotted/dotless I handling (see below).
- **Access control.** Users only see documents they own or that are shared with them, their groups, or their teams (circles).
- **Text extraction** for plain text (UTF-8, UTF-16, legacy Windows-1254), OpenDocument and Office Open XML files (odt/ods/odp, docx/xlsx/pptx), and PDFs when `pdftotext` is installed.
- **Partial updates.** When only metadata changes (a new share, a rename), the stored text is kept instead of being re-extracted.

## Requirements

- Nextcloud 29–32 running on **PostgreSQL 12 or newer**
- The **Full Text Search** app plus at least one content provider, e.g. **Full Text Search - Files**
- PHP 8.1+ with `zip` (for office documents)
- Optional: `poppler-utils` for PDF text (`apt install poppler-utils`)
- Optional: the `pg_trgm` extension for typo tolerance. Since PostgreSQL 13 it is a trusted extension and the app creates it automatically when the Nextcloud database user owns the database. Otherwise a superuser can run `CREATE EXTENSION pg_trgm;` in the Nextcloud database.

## Installation

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/surfcu/fulltextsearch_pgsql.git
sudo -u www-data php /var/www/nextcloud/occ app:enable fulltextsearch_pgsql
```

No `composer install` is needed: the app has no runtime dependencies.

Then choose it as the platform and build the index:

```bash
occ fulltextsearch:configure '{"search_platform":"OCA\\FullTextSearch_PgSql\\Platform\\PostgreSQLPlatform"}'
occ fulltextsearch_pgsql:configure '{"language":"english"}'   # or turkish, german, simple, ...
occ fulltextsearch:test
occ fulltextsearch:index
```

The platform can also be picked in **Administration settings → Full text search**.

Keep the index current with the live indexer (`occ fulltextsearch:live`, e.g. as a systemd service) or the framework's cron job.

## Configuration

```bash
occ fulltextsearch_pgsql:configure                 # show current settings
occ fulltextsearch_pgsql:configure --languages     # languages this PostgreSQL server supports
occ fulltextsearch_pgsql:configure '{"language":"turkish","max_content_size":800000}'
```

| Setting | Default | Meaning |
|---|---|---|
| `language` | `english` | PostgreSQL text search configuration used for stemming and stopwords. Must be one listed by `--languages`. Use `simple` for no stemming (mixed-language content). |
| `use_trigram` | `true` | Typo-tolerant title matching when a search has no exact results. Ignored if `pg_trgm` is unavailable. |
| `max_results` | `100` | Upper limit for the page size a client may request. |
| `max_content_size` | `512000` | Bytes of extracted text indexed per document. Larger documents are truncated. PostgreSQL rejects search vectors over 1 MB; if a document still exceeds that it is indexed by title and metadata only, with a warning. |
| `pdftotext_path` | *(empty)* | Path to `pdftotext`. Empty means look it up on `PATH`. |

The configure command validates values. `occ config:app:set` works too but does not.

**After changing `language`, rebuild the index** so existing documents are processed with it:

```bash
occ fulltextsearch:reset && occ fulltextsearch:index
```

## Turkish

PostgreSQL lowercases text using the database's locale, which is rarely `tr_TR`. Under any other locale `I` becomes `i` instead of `ı`, so `ISPARTA` would never match `ısparta` and uppercase words would miss their lowercase forms. When `language` is `turkish`, the app maps `I→ı` and `İ→i` before stemming, both when indexing and when searching, so it works regardless of the database locale.

A Turkish-language guide is in [docs/TURKISH.md](docs/TURKISH.md).

## What gets indexed from files

| Content | How |
|---|---|
| Text files (txt, md, csv, code, …) | Decoded directly; UTF-8, UTF-16 with BOM, or Windows-1254 |
| OpenDocument (odt, ods, odp) | `content.xml` |
| Office Open XML (docx, xlsx, pptx) | Document body, headers/footers/notes, shared strings, slides and notes |
| PDF | `pdftotext`, if installed (60 s timeout per file) |
| Scanned PDFs, images, other binaries | Title, tags and comments only; logged as a warning |

Which files are sent for indexing at all (size limits, external storage, etc.) is decided by the Files provider's own settings.

## Limitations

- Ranking is good but simpler than Elasticsearch's BM25; there are no synonyms or per-field boosting knobs.
- One language per instance. Documents keep the language they were indexed with until they are reindexed.
- No OCR, and no extraction for legacy `.doc`/`.xls`/`.ppt` binaries.
- Excerpts are generated from at most `max_content_size` bytes of each document.
- Everything lives in your Nextcloud database, so index size counts toward its storage and backups (roughly 1–2× the extracted text).

## How it works

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). In short: one table, `ftspg_<prefix>index` (e.g. `ftspg_oc_index`), with a generated weighted `tsvector` column and GIN indexes on it and on an access-token array. The table is deliberately named outside Nextcloud's table prefix so Nextcloud's Doctrine schema tooling never sees its PostgreSQL-specific column types.

Coming from Elasticsearch? See [docs/MIGRATION.md](docs/MIGRATION.md).

## Troubleshooting

**`occ fulltextsearch:test` fails.** Check `nextcloud.log`. The platform requires Nextcloud's own database to be PostgreSQL; it cannot use a separate PostgreSQL server.

**No typo tolerance.** `occ fulltextsearch_pgsql:configure` shows the setting; `occ fulltextsearch:check` shows `"pg_trgm": false` if the extension is missing. Create it as a superuser, then run `occ fulltextsearch:test`, which adds the trigram index.

**PDFs have no content.** Install `poppler-utils`, or set `pdftotext_path`. Scanned PDFs contain images, not text.

**Slow searches on very large instances.** Run `ANALYZE ftspg_oc_index;` after the initial index and make sure `shared_buffers` and `work_mem` are sized for your data. Very short prefixes (one or two letters) match many words and are inherently slower.

## Development

```bash
composer install          # nextcloud/ocp stubs and Psalm
composer psalm            # static analysis against the real Nextcloud interfaces
FTSPG_TEST_DSN="pgsql:host=localhost;dbname=fts_test;user=postgres;password=postgres" composer test
```

The integration tests run against a real, disposable PostgreSQL database. CI runs them on PHP 8.1/PostgreSQL 13 and PHP 8.3/PostgreSQL 17.

## License

AGPL-3.0-or-later
