# Architecture

How the PostgreSQL platform plugs into Nextcloud Full Text Search, and why it is built the way it is.

## Where it sits

```
 Content providers                 Full Text Search app               This app
 (Files, Deck, Bookmarks…)  ──►   (queues, locking, occ,      ──►   PostgreSQLPlatform
  produce IIndexDocument           unified search UI)                implements IFullTextSearchPlatform
                                                                          │
                                                                          ▼
                                                              Nextcloud's own PostgreSQL DB
                                                              table ftspg_<prefix>index
```

The framework calls the platform through `OCP\FullTextSearch\IFullTextSearchPlatform`:

| Method | What happens here |
|---|---|
| `loadPlatform()` | Creates the table if it is missing. |
| `testPlatform()` | Checks the database is PostgreSQL and the schema is in place. |
| `initializeIndex()` | Creates table and indexes (idempotent). |
| `indexDocument()` | Extracts text, then upserts one row. Reports warnings to the runner. |
| `deleteIndexes()` | Deletes rows. |
| `resetIndex($provider)` | Deletes a provider's rows; `all` truncates the table. |
| `searchRequest()` | Runs a search for one provider and fills the `ISearchResult`. |
| `getDocument()` | Rebuilds an `IIndexDocument` from a row (used by `occ fulltextsearch:document:platform` and `occ fulltextsearch:test`). |

## Code layout

| Class | Role |
|---|---|
| `Platform\PostgreSQLPlatform` | The interface implementation; thin, delegates to services and maps results to index statuses. |
| `Service\SchemaService` | Creates/drops the table, detects `pg_trgm` and installed text search configurations. |
| `Service\IndexService` | Builds the row and runs the upsert. |
| `Service\ContentExtractor` | base64 decoding and text extraction (plain text, ODF, OOXML, PDF). |
| `Service\TsQueryBuilder` | Parses the user's query into parameterised tsquery expressions. |
| `Service\SearchService` | Search and `getDocument`. |
| `Service\AccessTokens` | Encodes document access and the viewer's identity as comparable tokens. |
| `Service\ConfigService` | Validated settings stored in app config. |
| `Command\Configure` | `occ fulltextsearch_pgsql:configure`. |
| `Migration\CreateIndexTable`, `DropIndexTable` | Repair steps run on install/upgrade and uninstall. |

## The table

```sql
CREATE TABLE ftspg_oc_index (
  id            bigserial PRIMARY KEY,
  provider_id   varchar(64)  NOT NULL,
  document_id   varchar(254) NOT NULL,
  owner_id      varchar(64)  NOT NULL DEFAULT '',
  access        text[]       NOT NULL DEFAULT '{}',   -- u:alice, g:sales, c:<circle>, u:__all
  links         text[]       NOT NULL DEFAULT '{}',
  tags, metatags, subtags     text[],
  source, hash, modified_time, indexed_at,
  config        regconfig    NOT NULL,                -- language the row was indexed with
  title, tags_text, parts (jsonb), parts_text, content, info (jsonb),
  tsv tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector(config, title),      'A')
     || setweight(to_tsvector(config, tags_text),  'B')
     || setweight(to_tsvector(config, parts_text), 'C')
     || setweight(to_tsvector(config, content),    'D')
  ) STORED,
  UNIQUE (provider_id, document_id)
);
-- GIN indexes on tsv, access, metatags, subtags; trigram GIN index on title when pg_trgm exists
```

(For Turkish rows each column is first passed through `translate(col, 'Iİ', 'ıi')`; see below.)

### Why raw SQL and an unprefixed table name

Nextcloud manages schemas through Doctrine, and Doctrine has no mapping for `tsvector`, `text[]` or `regconfig`. Nextcloud introspects **every** table that starts with its table prefix whenever any app runs a migration. A prefixed table with these columns would make all of those migrations fail with *"Unknown database type tsvector requested"*.

So the table is created with raw SQL by a repair step and named `ftspg_` + prefix + `index`, which Nextcloud's schema filter skips. The app also drops `oc_fts_pgsql_index`, the table version 1.0.0 tried to create, for the same reason.

### Why a generated column

The search vector is always consistent with the stored text, a single `INSERT … ON CONFLICT DO UPDATE` writes a document atomically, and weights come for free. Storing `config` per row keeps old rows valid if the language setting changes, until they are reindexed.

## Indexing

1. The framework hands over an `IIndexDocument` whose `IIndex` status says what changed.
2. If only metadata changed (`INDEX_META` without `INDEX_CONTENT`), the stored content and parts are kept: a new share does not wipe the text.
3. Otherwise `ContentExtractor` produces text:
   - not encoded → used as is (HTML tags stripped if present);
   - base64 → decoded, then by magic bytes: `%PDF-` → `pdftotext`; `PK\x03\x04` → zip, reading `content.xml` (ODF) or `word/*.xml`, `xl/sharedStrings.xml`, `ppt/slides/*.xml` (OOXML); text-like → UTF-8 / UTF-16 / Windows-1254; other binaries → no content plus a warning.
   - Result is valid UTF-8, has no NUL bytes, whitespace is collapsed, and it is cut to `max_content_size` bytes on a character boundary.
4. One upsert writes the row. If it fails while content is present (typically the 1 MB `tsvector` limit), it is retried without content so the document stays findable by title, and the runner gets a warning.

## Access control

Document access becomes an array of tokens: the owner and shared users as `u:<uid>`, groups as `g:<gid>`, circles as `c:<id>`, and `u:__all` for public documents. At search time the framework passes an `IDocumentAccess` with the viewer's id, groups and circles, which becomes the same kind of tokens. A row is visible when the arrays overlap:

```sql
WHERE d.access && '{u:alice,u:__all,g:sales,c:team1}'::text[]
```

That is a single GIN-indexed test. Share links are stored for `getDocument()` but never grant search access. An empty viewer id returns no results.

## Searching

`TsQueryBuilder` follows the framework's query contract (verified by `occ fulltextsearch:test`):

| Input | Meaning | tsquery |
|---|---|---|
| `word` | optional, prefix | `to_tsquery(cfg, '''word'':*')` |
| `+word` | required, prefix | same, ANDed |
| `-word` | excluded, exact | `!! to_tsquery(cfg, '''word''')` |
| `"a b"` / `+"a b"` / `-"a b"` | phrase | `phraseto_tsquery(cfg, 'a b')` |

User text is always a bound parameter and quoted as a tsquery literal, so neither SQL nor tsquery operators can be injected. Three expressions are built:

- **match**: required terms ANDed (or, if there are none, optional terms ORed), minus exclusions;
- **rank**: every positive term, for `ts_rank_cd`;
- **all**: every positive term ANDed. Rows matching it sort first, so precise matches are never buried under partial ones.

The query pages inside a subquery and computes `count(*) OVER ()` for the total; `ts_headline` then runs only on the returned page. Excerpts are plain text (no markup), split into fragments.

If page 1 has no results and `pg_trgm` is available, titles are searched with `word_similarity` (`<%`, GIN-indexed) for typo tolerance.

## Turkish dotted and dotless I

PostgreSQL lowercases with the database's `LC_CTYPE`. Unless that is `tr_TR`, `I` lowercases to `i`, which is wrong for Turkish (`ı`), so `ISPARTA` would index as `isparta` while users type `ısparta`, and uppercase text would miss its lowercase forms. For rows with the `turkish` configuration the generated column applies `translate(text, 'Iİ', 'ıi')` before `to_tsvector`, and `TsQueryBuilder` applies the same mapping to the query. Both sides agree regardless of the database locale.

## Tests

`tests/run.php` runs against a real PostgreSQL database: schema creation, access control, Turkish handling, query syntax including the framework's own test searches, extraction of every supported format, size limits, partial updates, `getDocument` round trips, deletes and resets, and that the GIN indexes are used. Psalm checks the code against the real `nextcloud/ocp` interfaces.
