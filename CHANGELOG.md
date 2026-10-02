# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-10-03

### Changed
- Supports Nextcloud 29–35. The full-text search interfaces and the server classes this app uses are unchanged through Nextcloud 35; checked with Psalm against the Nextcloud 34 and 35 APIs and with the framework's own test searches.
- CI covers Nextcloud 30/34/35 APIs and PHP 8.1–8.5, and the test suite now fails on PHP deprecation notices from the app.

## [1.1.0] - 2026-10-03

A rewrite. Version 1.0.0 did not implement Nextcloud's `IFullTextSearchPlatform` interface and could not be loaded by the Full Text Search framework, so there is no index data to migrate. After upgrading, run `occ fulltextsearch:index`.

### Fixed
- Implements the real `OCP\FullTextSearch\IFullTextSearchPlatform` interface (`setRunner`, `searchRequest`, `getDocument`, `deleteIndexes(array)`, …) and registers it with `<platform>` in `info.xml`.
- **Access control**: documents were visible to every user when their access data was empty, and shares were ignored. Visibility now follows ownership and shares to users, groups and teams, using the viewer identity the framework provides.
- File content arrives base64-encoded from the Files provider and was indexed as base64 text. It is now decoded and extracted.
- Raw SQL used table names without Nextcloud's prefix.
- The index table no longer uses Doctrine: PostgreSQL-specific column types on a prefixed table would break schema introspection, and with it every app's migrations. The table is created with raw SQL as `ftspg_<prefix>index`; the 1.0.0 table is dropped if present.
- Non-ASCII letters were stripped from queries (`\w` without the `u` flag), breaking Turkish and other languages.
- Uppercase Turkish text did not match lowercase searches because PostgreSQL lowercases `I` as `i` unless the database locale is `tr_TR`.
- Hyphens, apostrophes and stopword-only queries produced tsquery syntax errors.
- Excerpts could cut multibyte characters in half.
- Inserts were delete-then-insert without a transaction; they are now a single upsert.

### Added
- Query syntax matching the framework contract: optional words, `+required`, `-excluded`, `"phrases"`; words match as prefixes; documents containing every word rank first. Passes the searches in `occ fulltextsearch:test`.
- Weighted ranking (title > tags > parts > content) via a generated `tsvector` column, and `ts_headline` excerpts.
- Typo-tolerant title matching with `pg_trgm` when there are no exact results.
- Text extraction for OpenDocument and Office Open XML files, PDFs (with `pdftotext`), UTF-16 and Windows-1254 text.
- Partial updates keep stored content when only metadata changed.
- `occ fulltextsearch_pgsql:configure` with validation; languages are checked against the server's `pg_ts_config`.
- `max_content_size` and `pdftotext_path` settings; oversized documents fall back to title-only indexing with a warning instead of failing.
- Integration tests against real PostgreSQL, Psalm against `nextcloud/ocp`, and GitHub Actions CI.
- Full AGPL-3.0 license text.

### Removed
- The settings controller and its routes, and the unused `min_word_length` setting.
- Documentation describing features that did not exist; docs were rewritten to match the code.

### Changed
- Requires Nextcloud 29 or newer and PHP 8.1+.

## [1.0.0] - 2025-02-02

- Initial release.
