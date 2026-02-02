# Full Text Search - PostgreSQL

A PostgreSQL-based search platform provider for Nextcloud's Full Text Search framework.

## Description

This app provides a PostgreSQL-based alternative to Elasticsearch for Nextcloud's Full Text Search framework. It uses PostgreSQL's native full-text search capabilities including:

- **ts_vector** and **ts_query** for efficient full-text indexing
- **GIN indexes** for fast search performance
- **pg_trgm** extension for fuzzy/similarity matching
- Multi-language support with PostgreSQL's text search dictionaries
- Relevance ranking with **ts_rank**

## Features

- ✅ No additional infrastructure required (uses your existing PostgreSQL database)
- ✅ Lower resource footprint compared to Elasticsearch
- ✅ Native full-text search with PostgreSQL
- ✅ Trigram similarity for fuzzy matching
- ✅ **Multi-language support including Turkish (Türkçe)** - 28 languages supported
- ✅ Relevance ranking
- ✅ Tag and metadata filtering
- ✅ Access control integration

## Requirements

- Nextcloud 25 or higher
- PostgreSQL 12 or higher (as your Nextcloud database)
- PHP 8.0 or higher
- Full Text Search app installed and enabled

## Installation

### 1. Install the app

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/yourusername/fulltextsearch_pgsql.git
cd fulltextsearch_pgsql
composer install --no-dev
```

### 2. Enable the app

```bash
sudo -u www-data php /path/to/nextcloud/occ app:enable fulltextsearch_pgsql
```

### 3. Enable PostgreSQL extensions

The app requires the `pg_trgm` extension for trigram similarity search. Connect to your PostgreSQL database and run:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
```

### 4. Configure Full Text Search

Set PostgreSQL as your search platform:

```bash
sudo -u www-data php /path/to/nextcloud/occ fulltextsearch:configure
```

When prompted, select `pgsql` as the search platform.

### 5. Index your content

Start indexing your content:

```bash
sudo -u www-data php /path/to/nextcloud/occ fulltextsearch:index
```

## Configuration

### Language Configuration

PostgreSQL supports multiple languages for text search. Set your language in the app configuration:

```bash
# For Turkish
sudo -u www-data php /path/to/nextcloud/occ config:app:set fulltextsearch_pgsql language --value=turkish

# For English (default)
sudo -u www-data php /path/to/nextcloud/occ config:app:set fulltextsearch_pgsql language --value=english
```

**Supported languages include:**
- `arabic` - العربية
- `armenian` - Հայերեն
- `basque` - Euskara
- `catalan` - Català
- `danish` - Dansk
- `dutch` - Nederlands
- `english` - English (default)
- `finnish` - Suomi
- `french` - Français
- `german` - Deutsch
- `greek` - Ελληνικά
- `hindi` - हिन्दी
- `hungarian` - Magyar
- `indonesian` - Bahasa Indonesia
- `irish` - Gaeilge
- `italian` - Italiano
- `lithuanian` - Lietuvių
- `nepali` - नेपाली
- `norwegian` - Norsk
- `portuguese` - Português
- `romanian` - Română
- `russian` - Русский
- `serbian` - Српски
- `spanish` - Español
- `swedish` - Svenska
- `tamil` - தமிழ்
- **`turkish` - Türkçe** ✨
- `yiddish` - ייִדיש

### Other Configuration Options

```bash
# Enable/disable trigram search (fuzzy matching)
sudo -u www-data php /path/to/nextcloud/occ config:app:set fulltextsearch_pgsql use_trigram --value=true

# Set minimum word length for indexing
sudo -u www-data php /path/to/nextcloud/occ config:app:set fulltextsearch_pgsql min_word_length --value=3

# Set maximum search results
sudo -u www-data php /path/to/nextcloud/occ config:app:set fulltextsearch_pgsql max_results --value=100
```

## How It Works

### Indexing

When documents are indexed:

1. Content is extracted from documents by content providers (Files, Bookmarks, etc.)
2. Text is processed and stored in the `fts_pgsql_index` table
3. PostgreSQL's `to_tsvector()` function creates a searchable text vector
4. GIN indexes are used for fast lookups

### Searching

When you perform a search:

1. Your query is converted to a PostgreSQL `ts_query`
2. The `@@` operator matches against indexed `ts_vector` columns
3. Results are ranked using `ts_rank()` for relevance
4. Trigram similarity provides fuzzy matching
5. Access controls ensure users only see their own content

## Performance Considerations

### Indexing Performance

- Initial indexing can take time for large document collections
- Use background jobs or run indexing during off-peak hours
- Consider partitioning the index table for very large instances

### Search Performance

- GIN indexes provide excellent search performance
- Most searches complete in milliseconds
- For very large instances (millions of documents), consider:
  - Increasing PostgreSQL's `shared_buffers`
  - Tuning `work_mem` for sorting operations
  - Using table partitioning

### Resource Usage

PostgreSQL FTS uses significantly less memory than Elasticsearch:
- No separate JVM required
- Indexes are stored efficiently in PostgreSQL
- Shared with your existing database resources

## Comparison with Elasticsearch

| Feature | PostgreSQL FTS | Elasticsearch |
|---------|---------------|---------------|
| Setup Complexity | Simple | Complex |
| Resource Usage | Low | High |
| Search Speed | Fast | Very Fast |
| Distributed Search | No | Yes |
| Fuzzy Search | Yes (trigram) | Yes |
| Relevance Ranking | Yes | Yes (more advanced) |
| Best For | Small-Medium instances | Large/distributed instances |

## Troubleshooting

### Extension not found

If you see errors about `pg_trgm` not being available:

```sql
-- Connect to your database and run:
CREATE EXTENSION pg_trgm;
```

### Slow search performance

1. Check that GIN indexes are created:
```sql
SELECT indexname FROM pg_indexes WHERE tablename = 'fts_pgsql_index';
```

2. Analyze the table:
```sql
ANALYZE fts_pgsql_index;
```

3. Check PostgreSQL configuration for full-text search optimization

### Database not PostgreSQL

This app requires PostgreSQL. If you're using MySQL/MariaDB, you'll need to use a different search platform provider.

## Development

### Running Tests

```bash
composer install
./vendor/bin/phpunit tests/
```

### Code Style

```bash
./vendor/bin/php-cs-fixer fix
```

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## License

AGPL-3.0-or-later

## Credits

Built for Nextcloud's Full Text Search framework.

## Support

- Issues: https://github.com/surfcu/fulltextsearch_pgsql/issues
- Nextcloud Community: https://help.nextcloud.com
