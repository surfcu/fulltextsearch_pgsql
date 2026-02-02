# Migration Guide: Elasticsearch to PostgreSQL

This guide helps you migrate from Full Text Search - Elasticsearch to Full Text Search - PostgreSQL.

## Before You Begin

### Prerequisites

- Nextcloud using PostgreSQL database
- Full Text Search framework installed
- Elasticsearch platform currently active
- Backup of your Nextcloud instance

### Decision Checklist

PostgreSQL FTS is a good choice if:
- ✅ You have < 1 million documents
- ✅ You want simpler infrastructure
- ✅ You want lower resource usage
- ✅ You're already using PostgreSQL
- ✅ Basic search features are sufficient

Consider keeping Elasticsearch if:
- ❌ You have > 10 million documents
- ❌ You need distributed search
- ❌ You require complex aggregations
- ❌ You have multiple data centers
- ❌ You need advanced analytics

## Migration Steps

### Step 1: Backup Everything

```bash
# Backup Nextcloud database
sudo -u postgres pg_dump nextcloud > nextcloud_backup.sql

# Backup Nextcloud data directory
sudo tar -czf nextcloud_data_backup.tar.gz /var/www/nextcloud/data

# Export current search index status
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:status > fts_status_backup.txt
```

### Step 2: Install PostgreSQL Platform

```bash
# Navigate to apps directory
cd /var/www/nextcloud/apps

# Clone the repository
sudo -u www-data git clone https://github.com/yourusername/fulltextsearch_pgsql.git

# Install dependencies
cd fulltextsearch_pgsql
sudo -u www-data composer install --no-dev

# Enable the app
sudo -u www-data php /var/www/nextcloud/occ app:enable fulltextsearch_pgsql
```

### Step 3: Enable PostgreSQL Extensions

```bash
sudo -u postgres psql nextcloud
```

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
\q
```

### Step 4: Stop Elasticsearch Indexing

```bash
# Stop any running indexing jobs
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:stop

# Verify no jobs are running
ps aux | grep fulltextsearch
```

### Step 5: Switch Platform

```bash
# Configure PostgreSQL as the search platform
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:configure
```

When prompted:
1. Select `pgsql` as the platform
2. Confirm the change
3. The old Elasticsearch index will remain but won't be used

### Step 6: Test the Platform

```bash
# Test PostgreSQL connection
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:test

# Check platform information
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:platform
```

Expected output:
```
Platform: PostgreSQL Full Text Search (pgsql)
Status: OK
Database: PostgreSQL
Extensions: pg_trgm (enabled)
```

### Step 7: Reindex Content

```bash
# Reset the old index (this won't delete Elasticsearch data)
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:reset

# Start fresh indexing with PostgreSQL
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:index
```

For large instances, run in background:

```bash
nohup sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:index > /tmp/fts-reindex.log 2>&1 &

# Monitor progress
tail -f /tmp/fts-reindex.log
```

### Step 8: Verify Migration

```bash
# Check index status
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:status

# Check document count
sudo -u postgres psql -d nextcloud -c "SELECT COUNT(*) FROM fts_pgsql_index;"
```

### Step 9: Test Search Functionality

1. Log in to Nextcloud
2. Use the search bar
3. Search for known content
4. Verify results are accurate
5. Test with different file types
6. Test with tags and filters

### Step 10: Optimize PostgreSQL

```bash
# Analyze the index table
sudo -u postgres psql -d nextcloud -c "ANALYZE fts_pgsql_index;"

# Check index usage
sudo -u postgres psql -d nextcloud -c "
    SELECT 
        indexname, 
        idx_scan, 
        pg_size_pretty(pg_relation_size(indexrelid)) 
    FROM pg_stat_user_indexes 
    WHERE tablename = 'fts_pgsql_index';
"
```

## Post-Migration Tasks

### Configure Automatic Indexing

Set up cron job for continuous indexing:

```bash
crontab -e -u www-data
```

Add:
```
*/15 * * * * php /var/www/nextcloud/occ fulltextsearch:index >/dev/null 2>&1
```

### Performance Tuning

See [OPTIMIZATION.md](OPTIMIZATION.md) for detailed tuning guide.

Quick settings for `postgresql.conf`:

```conf
# Increase shared buffers
shared_buffers = 2GB

# Increase work memory
work_mem = 64MB

# Increase maintenance work memory
maintenance_work_mem = 512MB

# Set effective cache size
effective_cache_size = 6GB
```

Restart PostgreSQL:
```bash
sudo systemctl restart postgresql
```

### Monitor Performance

Create monitoring script:

```bash
#!/bin/bash
# /usr/local/bin/monitor-fts.sh

echo "=== Full Text Search Status ==="
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:status

echo -e "\n=== PostgreSQL Index Stats ==="
sudo -u postgres psql -d nextcloud -c "
    SELECT 
        COUNT(*) as documents,
        pg_size_pretty(pg_total_relation_size('fts_pgsql_index')) as total_size
    FROM fts_pgsql_index;
"
```

Run weekly:
```bash
chmod +x /usr/local/bin/monitor-fts.sh
0 9 * * 1 /usr/local/bin/monitor-fts.sh | mail -s "FTS Status" admin@example.com
```

## Comparing Performance

### Before Migration (Elasticsearch)

Record baseline metrics:
```bash
# Document count
curl -X GET "localhost:9200/_cat/indices/nextcloud*?v"

# Index size
curl -X GET "localhost:9200/_cat/indices/nextcloud*?h=index,store.size"

# Search time (test query)
time curl -X GET "localhost:9200/nextcloud/_search?q=test"
```

### After Migration (PostgreSQL)

```bash
# Document count
sudo -u postgres psql -d nextcloud -c "SELECT COUNT(*) FROM fts_pgsql_index;"

# Index size
sudo -u postgres psql -d nextcloud -c "
    SELECT pg_size_pretty(pg_total_relation_size('fts_pgsql_index'));
"

# Search time (test query)
time sudo -u postgres psql -d nextcloud -c "
    SELECT * FROM fts_pgsql_index 
    WHERE content_tsv @@ to_tsquery('english', 'test:*') 
    LIMIT 10;
"
```

## Removing Elasticsearch (Optional)

### After Successful Migration

Once you've verified PostgreSQL is working well for at least a week:

#### 1. Disable Elasticsearch App

```bash
sudo -u www-data php /var/www/nextcloud/occ app:disable fulltextsearch_elasticsearch
```

#### 2. Remove Elasticsearch Data

```bash
# Delete Elasticsearch indices
curl -X DELETE "localhost:9200/nextcloud*"

# Verify deletion
curl -X GET "localhost:9200/_cat/indices?v"
```

#### 3. Stop Elasticsearch Service

```bash
sudo systemctl stop elasticsearch
sudo systemctl disable elasticsearch
```

#### 4. Uninstall Elasticsearch

**Ubuntu/Debian:**
```bash
sudo apt-get remove --purge elasticsearch
sudo rm -rf /etc/elasticsearch
sudo rm -rf /var/lib/elasticsearch
```

**RHEL/CentOS:**
```bash
sudo yum remove elasticsearch
sudo rm -rf /etc/elasticsearch
sudo rm -rf /var/lib/elasticsearch
```

#### 5. Remove Nextcloud App

```bash
rm -rf /var/www/nextcloud/apps/fulltextsearch_elasticsearch
```

## Troubleshooting Migration Issues

### Search Results Different from Elasticsearch

This is normal. PostgreSQL uses different ranking algorithms:

**Elasticsearch uses:**
- BM25 scoring
- Field boosting
- Fuzzy matching with edit distance

**PostgreSQL uses:**
- ts_rank() based on term frequency
- Trigram similarity
- Different normalization

**Solution:** Results are still relevant, just ranked differently. Users will adapt.

### Indexing is Slower

PostgreSQL indexing can be slower for very large datasets:

**Solutions:**
1. Index in chunks:
```bash
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:index --chunk=100
```

2. Increase PHP memory:
```ini
# /etc/php/8.x/cli/php.ini
memory_limit = 512M
```

3. Run during off-peak hours via cron

### Search is Slower than Expected

**Check indexes are created:**
```sql
SELECT indexname FROM pg_indexes WHERE tablename = 'fts_pgsql_index';
```

Should show:
- `fts_pgsql_content_tsv_idx` (GIN on ts_vector)
- `fts_pgsql_content_trgm_idx` (GIN on trigram)
- `fts_pgsql_provider_idx`
- `fts_pgsql_document_idx`

**Update statistics:**
```sql
ANALYZE fts_pgsql_index;
```

**Check query performance:**
```sql
EXPLAIN ANALYZE 
SELECT * FROM fts_pgsql_index 
WHERE content_tsv @@ to_tsquery('english', 'search:*');
```

### Missing Documents

**Compare counts:**
```bash
# Get count by provider
sudo -u postgres psql -d nextcloud -c "
    SELECT provider_id, COUNT(*) 
    FROM fts_pgsql_index 
    GROUP BY provider_id;
"
```

**Reindex specific provider:**
```bash
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:index files
```

### High Database Size

PostgreSQL indexes consume disk space:

**Check size breakdown:**
```sql
SELECT 
    'Table' as type,
    pg_size_pretty(pg_relation_size('fts_pgsql_index')) as size
UNION ALL
SELECT 
    'Indexes',
    pg_size_pretty(pg_indexes_size('fts_pgsql_index'))
UNION ALL
SELECT 
    'Total',
    pg_size_pretty(pg_total_relation_size('fts_pgsql_index'));
```

**Optimize:**
```sql
VACUUM FULL fts_pgsql_index;
REINDEX TABLE fts_pgsql_index;
```

## Rollback Plan

If you need to rollback to Elasticsearch:

### 1. Stop PostgreSQL Indexing

```bash
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:stop
```

### 2. Restart Elasticsearch

```bash
sudo systemctl start elasticsearch
sudo systemctl enable elasticsearch
```

### 3. Switch Back to Elasticsearch Platform

```bash
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:configure
# Select 'elasticsearch' when prompted
```

### 4. Reindex with Elasticsearch

```bash
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:reset
sudo -u www-data php /var/www/nextcloud/occ fulltextsearch:index
```

### 5. Disable PostgreSQL App (Optional)

```bash
sudo -u www-data php /var/www/nextcloud/occ app:disable fulltextsearch_pgsql
```

## Migration Checklist

- [ ] Backup database and data
- [ ] Install PostgreSQL platform app
- [ ] Enable pg_trgm extension
- [ ] Stop Elasticsearch indexing
- [ ] Switch to PostgreSQL platform
- [ ] Test platform connectivity
- [ ] Reindex all content
- [ ] Verify document count
- [ ] Test search functionality
- [ ] Optimize PostgreSQL settings
- [ ] Setup automatic indexing
- [ ] Monitor for one week
- [ ] Remove Elasticsearch (if satisfied)

## Support and Resources

- **Issues:** https://github.com/yourusername/fulltextsearch_pgsql/issues
- **Documentation:** See docs/ folder
- **Nextcloud Forum:** https://help.nextcloud.com
- **PostgreSQL Docs:** https://www.postgresql.org/docs/current/textsearch.html

## Frequently Asked Questions

**Q: Will I lose my search history?**
A: No, but you'll need to reindex. Search history isn't stored in the index.

**Q: How long does reindexing take?**
A: Depends on document count. ~10-20 documents per second on average hardware.

**Q: Can I run both platforms simultaneously?**
A: No, only one platform can be active at a time.

**Q: Is fuzzy search available?**
A: Yes, via pg_trgm extension for similarity matching.

**Q: Will my users notice any difference?**
A: Search results will be ranked slightly differently but remain relevant.

**Q: Can I migrate back to Elasticsearch later?**
A: Yes, follow the rollback plan above.

**Q: Do I need to upgrade PostgreSQL?**
A: PostgreSQL 12+ is required. 14+ is recommended for best performance.
