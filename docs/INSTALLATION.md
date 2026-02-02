# Installation Guide

## Prerequisites

Before installing Full Text Search - PostgreSQL, ensure you have:

1. **Nextcloud 25 or higher** installed and running
2. **PostgreSQL 12 or higher** as your Nextcloud database
3. **PHP 8.0 or higher**
4. **Full Text Search app** installed from the Nextcloud App Store

## Step-by-Step Installation

### 1. Install Full Text Search Framework

If you haven't already, install the core Full Text Search app:

```bash
sudo -u www-data php /path/to/nextcloud/occ app:install fulltextsearch
sudo -u www-data php /path/to/nextcloud/occ app:enable fulltextsearch
```

### 2. Install Content Providers

Install at least one content provider (e.g., Files):

```bash
sudo -u www-data php /path/to/nextcloud/occ app:install fulltextsearch_files
sudo -u www-data php /path/to/nextcloud/occ app:enable fulltextsearch_files
```

### 3. Download and Install PostgreSQL Platform

```bash
cd /var/www/nextcloud/apps
sudo -u www-data git clone https://github.com/yourusername/fulltextsearch_pgsql.git
cd fulltextsearch_pgsql
sudo -u www-data composer install --no-dev
```

### 4. Enable the App

```bash
sudo -u www-data php /var/www/nextcloud/occ app:enable fulltextsearch_pgsql
```

### 5. Enable PostgreSQL Extensions

Connect to your PostgreSQL database and enable the required extension:

```bash
sudo -u postgres psql nextcloud
```

Then run:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
\q
```

### 6. Configure Full Text Search

Set PostgreSQL as your search platform:

```bash
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:configure
```

When prompted:
- Select `pgsql` as the platform
- Confirm the configuration

### 7. Test the Platform

Verify that the platform is working:

```bash
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:test
```

You should see a success message indicating PostgreSQL is properly configured.

### 8. Initial Indexing

Start the initial indexing of your content:

```bash
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:index
```

For a large document collection, this may take some time. You can run it in the background:

```bash
nohup sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:index > /tmp/fts-index.log 2>&1 &
```

## Post-Installation Configuration

### Language Configuration

Set the text search language to match your content:

```bash
sudo -u www-data php /var/www/nextcloud/occ config:app:set fulltextsearch_pgsql language --value=english
```

Available languages:
- `english` (default)
- `spanish`
- `french`
- `german`
- `italian`
- `portuguese`
- `russian`
- `dutch`
- `swedish`
- `norwegian`
- `danish`
- `finnish`

### Advanced Configuration

```bash
# Enable fuzzy/trigram search
sudo -u www-data php /var/www/nextcloud/occ config:app:set fulltextsearch_pgsql use_trigram --value=true

# Set minimum word length
sudo -u www-data php /var/www/nextcloud/occ config:app:set fulltextsearch_pgsql min_word_length --value=3

# Set maximum results per page
sudo -u www-data php /var/www/nextcloud/occ config:app:set fulltextsearch_pgsql max_results --value=100
```

### Automatic Indexing

Set up a cron job to keep your index up-to-date:

```bash
crontab -e -u www-data
```

Add this line to run indexing every 15 minutes:

```
*/15 * * * * php /var/www/nextcloud/occ fulltextsearch:index
```

Or use Nextcloud's built-in cron:

```bash
sudo -u www-data php /var/www/nextcloud/occ background:cron
```

## Verification

### Test Search

1. Log in to your Nextcloud instance
2. Use the search bar at the top
3. Search for content in your files
4. Results should appear from the PostgreSQL index

### Check Index Status

```bash
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:status
```

### View Platform Information

```bash
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:platform
```

## Troubleshooting

### Extension Not Found

If you get errors about `pg_trgm` not being available:

1. Check if the extension is installed:
```sql
SELECT * FROM pg_available_extensions WHERE name = 'pg_trgm';
```

2. If not available, install it (Ubuntu/Debian):
```bash
sudo apt-get install postgresql-contrib
```

3. Then create it in your database:
```sql
CREATE EXTENSION pg_trgm;
```

### Indexing Fails

Check the Nextcloud logs:

```bash
tail -f /var/www/nextcloud/data/nextcloud.log
```

Common issues:
- Insufficient PostgreSQL permissions
- Database connection problems
- Memory limits (increase PHP memory_limit)

### Slow Indexing

For large instances, consider:

1. Increasing PHP memory limit in php.ini:
```
memory_limit = 512M
```

2. Running indexing in chunks:
```bash
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:index --chunk=100
```

### Search Not Working

1. Verify the platform is selected:
```bash
sudo -u www-data php /var/www/nextcloud/occ config:app:get fulltextsearch search_platform
```

2. Check if documents are indexed:
```bash
sudo -u postgres psql -d nextcloud -c "SELECT COUNT(*) FROM fts_pgsql_index;"
```

3. Re-index if needed:
```bash
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:reset
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:index
```

## Uninstallation

To remove the app:

```bash
# Stop indexing
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:stop

# Switch to a different platform or disable FTS
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:configure

# Disable the app
sudo -u www-data php /var/www/nextcloud/occ app:disable fulltextsearch_pgsql

# Remove files
rm -rf /var/www/nextcloud/apps/fulltextsearch_pgsql
```

The database table `fts_pgsql_index` will remain. To remove it:

```sql
DROP TABLE IF EXISTS fts_pgsql_index CASCADE;
```

## Next Steps

- Configure additional content providers (Bookmarks, Talk, etc.)
- Optimize PostgreSQL for full-text search
- Set up monitoring for index health
- Review search performance metrics
