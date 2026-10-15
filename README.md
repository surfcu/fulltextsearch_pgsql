# Full Text Search - PostgreSQL

A search platform for Nextcloud's [Full Text Search](https://github.com/nextcloud/fulltextsearch) that uses PostgreSQL's built-in full-text search instead of Elasticsearch. If Nextcloud already runs on PostgreSQL, there is nothing else to install or operate.

## Features

- **Ranked results with excerpts.** Title matches outrank tags, which outrank comments and other document parts, which outrank body text. Excerpts come from `ts_headline`.
- **Search-as-you-type.** Words match as prefixes: `rap` finds *rapor*, *raporlar*.
- **File names are split into words.** `final`, `2025` or `final.pdf` all find *rapor_2025_final.pdf*; `img` finds *IMG20250412.jpg*. PostgreSQL would otherwise treat each file name as a single word.
- **Substring matching in titles.** A word of three or more letters also matches anywhere inside a title: `butce` finds *YillikButceRaporu.xlsx*. These matches rank below normal word matches. Needs `pg_trgm`.
- **Query syntax** (the Full Text Search framework's standard, same as the Elasticsearch platform): plain words are optional and any of them can match, `+word` is required, `-word` is excluded, `"exact phrase"` works with or without `+`/`-`. Documents containing every word are always listed before partial matches. Queries made only of exclusions or stopwords return nothing rather than everything.
- **Diacritics optional.** `calisma` finds *çalışma*, `sozlesme` finds *sözleşme*, and the other way round, from a vocabulary of every indexed word.
- **Typo correction.** Misspelled words are corrected against the same vocabulary, including swapped letters: `recieve` finds *receive*, `rpaor` finds *rapor*. The original word is still searched too, so nothing that matched before is lost. Needs `pg_trgm`. If nothing matches at all, titles are also searched by similarity.
- **Any language your PostgreSQL ships**, including Turkish, with correct dotted/dotless I handling (see below).
- **Access control.** Users only see documents they own or that are shared with them, their groups, or their teams (circles).
- **Text extraction** for plain text (UTF-8, UTF-16, legacy Windows-1254), OpenDocument and Office Open XML files (odt/ods/odp, docx/xlsx/pptx), and PDFs: with `pdftotext` when it is installed, with a bundled pure-PHP parser otherwise.
- **Partial updates.** When only metadata changes (a new share, a rename), the stored text is kept instead of being re-extracted.

## Requirements

- Nextcloud 29–35 running on **PostgreSQL 12 or newer**
- The **Full Text Search** app plus at least one content provider, e.g. **Full Text Search - Files**
- The PHP version your Nextcloud release requires (8.1+ for Nextcloud 29, 8.3+ for 35), with `zip` for office documents
- Recommended: `poppler-utils` for faster, more robust PDF text (`apt install poppler-utils`). Without it, a bundled parser ([smalot/pdfparser](https://github.com/smalot/pdfparser)) reads PDFs in PHP.
- Optional: the `pg_trgm` extension for typo tolerance. Since PostgreSQL 13 it is a trusted extension and the app creates it automatically when the Nextcloud database user owns the database. Otherwise a superuser can run `CREATE EXTENSION pg_trgm;` in the Nextcloud database.

## Installation

From the App Store (once the first release is published there): **Apps → Search → Full Text Search - PostgreSQL**, or

```bash
sudo -u www-data php /var/www/nextcloud/occ app:install fulltextsearch_pgsql
```

From source:

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/surfcu/fulltextsearch_pgsql.git
sudo -u www-data php /var/www/nextcloud/occ app:enable fulltextsearch_pgsql
```

No `composer install` is needed: the app has no runtime dependencies.

For typo correction and title substrings, the Nextcloud database user should own the database, as in [Nextcloud's PostgreSQL setup](https://docs.nextcloud.com/server/latest/admin_manual/configuration_database/linux_database_configuration.html). The app can then enable `pg_trgm` itself on PostgreSQL 13+.

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

Administrators can change every setting in **Administration settings → Full text search**, under *PostgreSQL search platform*. The panel also shows whether `pg_trgm` and `pdftotext` are available, which tool reads PDFs, and how many documents are indexed. The same settings are available from the command line:

```bash
occ fulltextsearch_pgsql:configure                 # show current settings
occ fulltextsearch_pgsql:configure --languages     # languages this PostgreSQL server supports
occ fulltextsearch_pgsql:configure '{"language":"turkish","max_content_size":800000}'
```

| Setting | Default | Meaning |
|---|---|---|
| `language` | `english` | PostgreSQL text search configuration used for stemming and stopwords. Must be one listed by `--languages`. Use `simple` for no stemming (mixed-language content). |
| `use_trigram` | `true` | Substring matching in titles, spelling correction, and similarity matching on titles when a search has no results. Ignored if `pg_trgm` is unavailable. |
| `typo_correction` | `true` | Accent variants and spelling corrections from the vocabulary of indexed words. Accent variants work without `pg_trgm`. |
| `max_results` | `100` | Upper limit for the page size a client may request. |
| `max_content_size` | `512000` | Bytes of extracted text indexed per document. Larger documents are truncated. PostgreSQL rejects search vectors over 1 MB; if a document still exceeds that it is indexed by title and metadata only, with a warning. |
| `pdftotext_path` | *(empty)* | Path to `pdftotext`. Empty means look it up on `PATH`. |
| `pdf_extractor` | `auto` | `auto`: `pdftotext` when found, otherwise the bundled parser (also used when `pdftotext` fails to run). `pdftotext`: never fall back. `pdfparser`: always use the bundled parser. |

The configure command and the admin panel validate every value before saving any. `occ config:app:set` works too but does not validate.

### Typo correction vocabulary

Words are collected as documents are indexed. Indexes built before version 1.3.0 have no vocabulary yet: on upgrade it is filled automatically for up to 10,000 documents; for larger indexes, or to drop words from deleted documents, run:

```bash
occ fulltextsearch_pgsql:vocabulary            # show the number of words
occ fulltextsearch_pgsql:vocabulary --rebuild  # rebuild from the index (about 1.5 ms per document)
```

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
| PDF | `pdftotext` if installed (60 s timeout per file), otherwise the bundled parser (files up to 64 MB, and only when `memory_limit` leaves about 12× the file size free) |
| Scanned PDFs, images, other binaries | Title, tags and comments only; logged as a warning |

Which files are sent for indexing at all (size limits, external storage, etc.) is decided by the Files provider's own settings.

## Limitations

- Ranking is good but simpler than Elasticsearch's BM25; there are no synonyms or per-field boosting knobs.
- One language per instance. Documents keep the language they were indexed with until they are reindexed.
- No OCR, and no extraction for legacy `.doc`/`.xls`/`.ppt` binaries.
- Excerpts are generated from at most `max_content_size` bytes of each document.
- Corrections are applied silently: the Full Text Search interface has no place for a "did you mean …" hint.
- Only words of three letters or more get accent variants, and four or more get spelling corrections. Spelling correction only kicks in for words that appear nowhere in the index.
- Everything lives in your Nextcloud database, so index size counts toward its storage and backups (roughly 1–2× the extracted text).

## How it works

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). In short: one table, `ftspg_<prefix>index` (e.g. `ftspg_oc_index`), with a generated weighted `tsvector` column, GIN indexes on it and on an access-token array, and a trigram index on a lowercased copy of the title. The table is deliberately named outside Nextcloud's table prefix so Nextcloud's Doctrine schema tooling never sees its PostgreSQL-specific column types.

Coming from Elasticsearch? See [docs/MIGRATION.md](docs/MIGRATION.md).

## Troubleshooting

**`occ fulltextsearch:test` fails.** Check `nextcloud.log`. The platform requires Nextcloud's own database to be PostgreSQL; it cannot use a separate PostgreSQL server.

**No substring matching or spelling correction.** `occ fulltextsearch_pgsql:configure` shows the setting; `occ fulltextsearch:check` shows `"pg_trgm": false` if the extension is missing. Create it as a superuser, then run `occ fulltextsearch:test`, which adds the trigram index.

**PDFs have no content.** The indexing warning says why. `occ fulltextsearch:check` shows `pdf_tool`, the tool PDFs currently go through. The bundled parser skips large files, files it cannot parse and encrypted ones; installing `poppler-utils` handles more of them. Scanned PDFs contain images, not text.

**Slow searches on very large instances.** Run `ANALYZE ftspg_oc_index;` after the initial index and make sure `shared_buffers` and `work_mem` are sized for your data. Very short prefixes (one or two letters) match many words and are inherently slower.

## Development

```bash
composer install          # nextcloud/ocp stubs and Psalm; also builds lib/Vendor (see below)
composer psalm            # static analysis against the real Nextcloud interfaces
FTSPG_TEST_DSN="pgsql:host=localhost;dbname=fts_test;user=postgres;password=postgres" composer test
```

The integration tests run against a real, disposable PostgreSQL database. CI runs Psalm against the Nextcloud 30, 34 and 35 APIs, and the integration tests on PHP 8.1 through 8.5 with PostgreSQL 13 and 17.

`tests/live/run.sh` goes further: CI installs real Nextcloud 30, 34 and 35 servers on PostgreSQL with Full Text Search and the Files provider, runs the framework's own `occ fulltextsearch:test`, then indexes and searches real text, Office and PDF files. It covers both a database the Nextcloud user owns (`pg_trgm` enabled by the app) and one it doesn't (trigram features off).

The bundled PDF parser lives in `lib/Vendor`, generated by `scripts/scope-pdfparser.php` from `vendor/smalot/pdfparser` with its namespace rewritten to `OCA\FullTextSearch_PgSql\Vendor\Smalot\PdfParser`. That keeps it separate from any other app's copy and lets Nextcloud autoload it from `lib/` without Composer at runtime. `composer install` and `make appstore` regenerate it; it is not committed. An installation from a git checkout without `composer install` still works, with no PDF fallback.

Releases are built and published to the Nextcloud App Store from GitHub; see [docs/RELEASING.md](docs/RELEASING.md). To check against a specific Nextcloud release, install its API stubs first: `composer require --dev nextcloud/ocp:dev-stable35`.

## License

AGPL-3.0-or-later
