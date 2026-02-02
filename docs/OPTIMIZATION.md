# PostgreSQL Optimization Guide

This guide provides recommendations for optimizing PostgreSQL for Full Text Search performance.

## Configuration Tuning

### Memory Settings

Edit your `postgresql.conf` file (usually in `/etc/postgresql/*/main/postgresql.conf`):

```conf
# Shared buffers - allocate 25% of system RAM
shared_buffers = 2GB

# Work memory for sorting/hashing operations
work_mem = 64MB

# Maintenance work memory for index creation
maintenance_work_mem = 512MB

# Effective cache size - 50-75% of system RAM
effective_cache_size = 6GB
```

### Full-Text Search Specific Settings

```conf
# GIN pending list size
gin_pending_list_limit = 16MB

# Default text search configuration
default_text_search_config = 'pg_catalog.english'
```

### Connection Settings

```conf
# Maximum connections
max_connections = 100

# Connection pooling (consider using PgBouncer for larger instances)
```

After making changes, restart PostgreSQL:

```bash
sudo systemctl restart postgresql
```

## Index Optimization

### Check Index Usage

```sql
-- Check index sizes
SELECT 
    schemaname,
    tablename,
    indexname,
    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
FROM pg_stat_user_indexes
WHERE tablename = 'fts_pgsql_index'
ORDER BY pg_relation_size(indexrelid) DESC;
```

### Analyze Index Performance

```sql
-- Get index statistics
SELECT * FROM pg_stat_user_indexes WHERE tablename = 'fts_pgsql_index';

-- Check if indexes are being used
SELECT 
    indexrelname,
    idx_scan,
    idx_tup_read,
    idx_tup_fetch
FROM pg_stat_user_indexes
WHERE tablename = 'fts_pgsql_index';
```

### Reindex for Better Performance

If indexes become fragmented over time:

```sql
-- Analyze the table first
ANALYZE fts_pgsql_index;

-- Reindex (do this during low-traffic periods)
REINDEX TABLE fts_pgsql_index;
```

## Query Optimization

### Search Query Performance

Enable timing to measure query performance:

```sql
\timing on

-- Test a search query
SELECT 
    provider_id,
    document_id,
    title,
    ts_rank(content_tsv, to_tsquery('english', 'search & terms')) as rank
FROM fts_pgsql_index
WHERE content_tsv @@ to_tsquery('english', 'search & terms')
ORDER BY rank DESC
LIMIT 50;
```

### Explain Query Plans

```sql
EXPLAIN ANALYZE
SELECT 
    provider_id,
    document_id,
    title,
    ts_rank(content_tsv, to_tsquery('english', 'search:*')) as rank
FROM fts_pgsql_index
WHERE content_tsv @@ to_tsquery('english', 'search:*')
ORDER BY rank DESC
LIMIT 50;
```

Look for:
- Index scans (good) vs. Sequential scans (bad for large tables)
- Execution time
- Rows returned vs. rows scanned

## Table Maintenance

### Regular Maintenance Schedule

Create a maintenance script:

```bash
#!/bin/bash
# /usr/local/bin/fts-maintenance.sh

# Analyze table statistics
psql -U nextcloud -d nextcloud -c "ANALYZE fts_pgsql_index;"

# Vacuum to reclaim space
psql -U nextcloud -d nextcloud -c "VACUUM ANALYZE fts_pgsql_index;"

echo "Maintenance completed at $(date)"
```

Add to crontab to run weekly:

```bash
0 2 * * 0 /usr/local/bin/fts-maintenance.sh >> /var/log/fts-maintenance.log 2>&1
```

### Monitor Table Bloat

```sql
-- Check table bloat
SELECT 
    schemaname,
    tablename,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS total_size,
    pg_size_pretty(pg_relation_size(schemaname||'.'||tablename)) AS table_size,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename) - pg_relation_size(schemaname||'.'||tablename)) AS indexes_size
FROM pg_tables
WHERE tablename = 'fts_pgsql_index';
```

### Auto-vacuum Configuration

Ensure auto-vacuum is properly configured:

```conf
# Enable autovacuum
autovacuum = on

# Autovacuum settings
autovacuum_vacuum_scale_factor = 0.1
autovacuum_analyze_scale_factor = 0.05
autovacuum_vacuum_cost_delay = 10ms
```

## Partitioning for Large Datasets

For very large document collections (millions of documents), consider table partitioning:

```sql
-- Create partitioned table (PostgreSQL 10+)
CREATE TABLE fts_pgsql_index_partitioned (
    id BIGSERIAL,
    provider_id VARCHAR(64) NOT NULL,
    document_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    access TEXT,
    content TEXT NOT NULL,
    title VARCHAR(255),
    tags TEXT,
    metatags TEXT,
    subtags TEXT,
    indexed_at INTEGER NOT NULL,
    content_tsv tsvector
) PARTITION BY HASH (provider_id);

-- Create partitions (adjust number based on size)
CREATE TABLE fts_pgsql_index_p0 PARTITION OF fts_pgsql_index_partitioned 
    FOR VALUES WITH (MODULUS 4, REMAINDER 0);
CREATE TABLE fts_pgsql_index_p1 PARTITION OF fts_pgsql_index_partitioned 
    FOR VALUES WITH (MODULUS 4, REMAINDER 1);
CREATE TABLE fts_pgsql_index_p2 PARTITION OF fts_pgsql_index_partitioned 
    FOR VALUES WITH (MODULUS 4, REMAINDER 2);
CREATE TABLE fts_pgsql_index_p3 PARTITION OF fts_pgsql_index_partitioned 
    FOR VALUES WITH (MODULUS 4, REMAINDER 3);

-- Create indexes on each partition
CREATE INDEX ON fts_pgsql_index_p0 USING GIN (content_tsv);
CREATE INDEX ON fts_pgsql_index_p1 USING GIN (content_tsv);
CREATE INDEX ON fts_pgsql_index_p2 USING GIN (content_tsv);
CREATE INDEX ON fts_pgsql_index_p3 USING GIN (content_tsv);
```

## Monitoring

### Key Metrics to Monitor

```sql
-- Index hit ratio (should be > 95%)
SELECT 
    sum(idx_blks_hit) / nullif(sum(idx_blks_hit + idx_blks_read), 0) * 100 
    AS index_hit_ratio
FROM pg_statio_user_indexes
WHERE schemaname = 'public';

-- Cache hit ratio (should be > 95%)
SELECT 
    sum(heap_blks_hit) / nullif(sum(heap_blks_hit + heap_blks_read), 0) * 100 
    AS cache_hit_ratio
FROM pg_statio_user_tables
WHERE schemaname = 'public';

-- Active connections
SELECT count(*) FROM pg_stat_activity;

-- Long-running queries
SELECT 
    pid,
    now() - query_start AS duration,
    query
FROM pg_stat_activity
WHERE state = 'active' AND query NOT LIKE '%pg_stat_activity%'
ORDER BY duration DESC;
```

### Set Up Monitoring Script

```bash
#!/bin/bash
# /usr/local/bin/fts-monitor.sh

LOGFILE="/var/log/fts-monitor.log"

echo "=== FTS Monitoring Report $(date) ===" >> $LOGFILE

# Index size
psql -U nextcloud -d nextcloud -t -c "
SELECT 
    'Index Size: ' || pg_size_pretty(pg_total_relation_size('fts_pgsql_index'))
" >> $LOGFILE

# Document count
psql -U nextcloud -d nextcloud -t -c "
SELECT 'Total Documents: ' || count(*) FROM fts_pgsql_index
" >> $LOGFILE

# Index hit ratio
psql -U nextcloud -d nextcloud -t -c "
SELECT 
    'Index Hit Ratio: ' || 
    round(sum(idx_blks_hit)::numeric / nullif(sum(idx_blks_hit + idx_blks_read), 0) * 100, 2) || '%'
FROM pg_statio_user_indexes
WHERE indexrelname LIKE 'fts_pgsql%'
" >> $LOGFILE

echo "" >> $LOGFILE
```

## Performance Benchmarks

### Simple Search Benchmark

```sql
-- Create test function
CREATE OR REPLACE FUNCTION benchmark_search(terms TEXT, iterations INT)
RETURNS TABLE(avg_time NUMERIC, min_time NUMERIC, max_time NUMERIC) AS $$
DECLARE
    start_time TIMESTAMP;
    end_time TIMESTAMP;
    times NUMERIC[];
    i INT;
BEGIN
    times := ARRAY[]::NUMERIC[];
    
    FOR i IN 1..iterations LOOP
        start_time := clock_timestamp();
        
        PERFORM * FROM fts_pgsql_index
        WHERE content_tsv @@ to_tsquery('english', terms)
        LIMIT 50;
        
        end_time := clock_timestamp();
        times := array_append(times, EXTRACT(MILLISECONDS FROM (end_time - start_time)));
    END LOOP;
    
    RETURN QUERY SELECT 
        round(avg(x), 2),
        round(min(x), 2),
        round(max(x), 2)
    FROM unnest(times) x;
END;
$$ LANGUAGE plpgsql;

-- Run benchmark
SELECT * FROM benchmark_search('search:* & term:*', 100);
```

## Troubleshooting Performance Issues

### Slow Searches

1. **Check if indexes are being used:**
```sql
EXPLAIN SELECT * FROM fts_pgsql_index 
WHERE content_tsv @@ to_tsquery('english', 'search:*');
```

2. **Verify statistics are up-to-date:**
```sql
SELECT last_analyze, last_autoanalyze 
FROM pg_stat_user_tables 
WHERE tablename = 'fts_pgsql_index';
```

3. **Check for bloat:**
```sql
SELECT 
    pg_size_pretty(pg_relation_size('fts_pgsql_index')) as table_size,
    pg_size_pretty(pg_total_relation_size('fts_pgsql_index')) as total_size;
```

### High Memory Usage

1. Reduce `work_mem` if queries consume too much memory
2. Use connection pooling (PgBouncer)
3. Limit concurrent indexing operations

### Disk I/O Issues

1. Move PostgreSQL data to faster storage (SSD)
2. Increase `shared_buffers`
3. Consider separate tablespace for indexes

## Best Practices

1. **Regular Maintenance**: Run VACUUM and ANALYZE weekly
2. **Monitor Growth**: Track table and index sizes
3. **Test Queries**: Use EXPLAIN ANALYZE for new search patterns
4. **Update Statistics**: Keep table statistics current
5. **Plan Capacity**: Monitor disk usage and plan for growth
6. **Backup Strategy**: Regular backups before major operations
7. **Upgrade PostgreSQL**: Keep PostgreSQL updated for performance improvements

## Resource Requirements

### Small Instance (< 10,000 documents)
- 2 GB RAM for PostgreSQL
- 10 GB disk space
- 2 CPU cores

### Medium Instance (10,000 - 100,000 documents)
- 4 GB RAM for PostgreSQL
- 50 GB disk space
- 4 CPU cores

### Large Instance (> 100,000 documents)
- 8+ GB RAM for PostgreSQL
- 100+ GB disk space
- 8+ CPU cores
- Consider partitioning

## Conclusion

Proper optimization can make PostgreSQL FTS perform excellently even with large document collections. Regular monitoring and maintenance are key to sustained performance.
