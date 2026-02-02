# Türkçe Dil Desteği / Turkish Language Support

Full Text Search - PostgreSQL, PostgreSQL'in yerel tam metin arama özelliklerini kullanarak **Türkçe dilinde arama** desteği sağlar.

This app provides **full Turkish language support** for text search using PostgreSQL's native capabilities.

## Kurulum / Installation

### Türkçe Dil Yapılandırması / Turkish Language Configuration

```bash
# Set Turkish as the search language
sudo -u www-data php /path/to/nextcloud/occ config:app:set fulltextsearch_pgsql language --value=turkish
```

### Doğrulama / Verification

```bash
# Check current language setting
sudo -u www-data php /path/to/nextcloud/occ config:app:get fulltextsearch_pgsql language
```

Output should be: `turkish`

## Türkçe Özellikleri / Turkish Features

PostgreSQL'in Türkçe metin arama desteği şunları içerir:

PostgreSQL's Turkish text search support includes:

### 1. Türkçe Dilbilgisi / Turkish Stemming

PostgreSQL automatically handles Turkish word stemming:

**Examples:**
- "kitaplar" → "kitap"
- "çalışıyorum" → "çalış"
- "gidiyorlar" → "git"

### 2. Türk Dili Stopwords / Turkish Stop Words

Common Turkish words are automatically filtered:

- ve, veya, ile, için
- bu, şu, o
- bir, bir
- ne, nasıl, neden

### 3. Özel Karakterler / Special Characters

Türkçe özel karakterleri destekler:
- ç, Ç
- ğ, Ğ
- ı, İ
- ö, Ö
- ş, Ş
- ü, Ü

## Kullanım Örnekleri / Usage Examples

### Belgeleri İndeksleme / Indexing Documents

```bash
# Index all content with Turkish language support
sudo -u www-data php /path/to/nextcloud/occ fulltextsearch:index
```

### Arama Yapma / Searching

After indexing, searches will use Turkish text search configuration:

**Web Interface:**
1. Nextcloud'a giriş yapın / Log in to Nextcloud
2. Üst kısımdaki arama çubuğunu kullanın / Use the search bar at the top
3. Türkçe kelimeler arayın / Search in Turkish

**Command Line:**
```bash
sudo -u www-data php /path/to/nextcloud/occ fulltextsearch:search "kitap"
sudo -u www-data php /path/to/nextcloud/occ fulltextsearch:search "çalışma"
```

## Arama Örnekleri / Search Examples

### Basit Arama / Simple Search
```
Query: "kitap"
Matches: kitap, kitaplar, kitabı, kitaba, kitaptan
```

### Birden Fazla Kelime / Multiple Words
```
Query: "türkçe belge"
Matches documents containing both "türkçe" AND "belge"
```

### Benzerlik Araması / Fuzzy Search

Trigram özelliği ile yazım hatalarını tolere eder:
With trigram enabled, tolerates typos:

```
Query: "ktiap" (typo)
Still matches: "kitap"
```

Enable trigram for better fuzzy matching:
```bash
sudo -u www-data php /path/to/nextcloud/occ config:app:set fulltextsearch_pgsql use_trigram --value=true
```

## PostgreSQL Türkçe Yapılandırması / PostgreSQL Turkish Configuration

### Test Turkish Configuration

PostgreSQL'de Türkçe yapılandırmayı test edin:

Test the Turkish configuration in PostgreSQL:

```sql
-- Connect to database
sudo -u postgres psql nextcloud

-- Test Turkish stemming
SELECT to_tsvector('turkish', 'Kitapları okuyorum');
-- Result: 'kitap':1 'oku':2

-- Test search
SELECT to_tsvector('turkish', 'Çalışıyorum') @@ to_tsquery('turkish', 'çalış');
-- Result: true
```

### Custom Turkish Dictionary (Advanced)

For advanced users who need custom Turkish word handling:

```sql
-- Create custom dictionary (optional)
CREATE TEXT SEARCH DICTIONARY turkish_custom (
    TEMPLATE = snowball,
    Language = turkish,
    StopWords = turkish
);

-- Create custom configuration (optional)
CREATE TEXT SEARCH CONFIGURATION turkish_custom (COPY = turkish);
```

## Performans İpuçları / Performance Tips

### 1. Minimum Kelime Uzunluğu / Minimum Word Length

Türkçe için önerilen minimum kelime uzunluğu: 2-3 harf

Recommended minimum word length for Turkish: 2-3 characters

```bash
sudo -u www-data php /path/to/nextcloud/occ config:app:set fulltextsearch_pgsql min_word_length --value=2
```

### 2. Index Bakımı / Index Maintenance

Düzenli bakım için:
For regular maintenance:

```bash
# Weekly maintenance
sudo -u postgres psql nextcloud -c "ANALYZE fts_pgsql_index;"
```

### 3. Bellek Optimizasyonu / Memory Optimization

PostgreSQL yapılandırması için (`postgresql.conf`):

For PostgreSQL configuration:

```conf
# Increase shared buffers
shared_buffers = 2GB

# Work memory for Turkish text processing
work_mem = 64MB
```

## Sorun Giderme / Troubleshooting

### Türkçe Karakterler Düzgün Gösterilmiyor
### Turkish Characters Not Displaying Properly

Ensure database encoding is UTF-8:

```sql
-- Check encoding
SELECT current_setting('server_encoding');
-- Should return: UTF8

-- Check locale
SELECT current_setting('lc_collate');
-- Should include: tr_TR.UTF-8 or similar
```

### Arama Sonuç Vermiyor
### Search Returns No Results

1. Verify language is set to Turkish:
```bash
php occ config:app:get fulltextsearch_pgsql language
```

2. Re-index with Turkish configuration:
```bash
php occ fulltextsearch:reset
php occ config:app:set fulltextsearch_pgsql language --value=turkish
php occ fulltextsearch:index
```

3. Test search in database:
```sql
SELECT * FROM fts_pgsql_index 
WHERE content_tsv @@ to_tsquery('turkish', 'test');
```

### Performans Sorunları
### Performance Issues

For large Turkish document collections:

```bash
# Analyze Turkish text search performance
sudo -u postgres psql nextcloud -c "
EXPLAIN ANALYZE
SELECT * FROM fts_pgsql_index
WHERE content_tsv @@ to_tsquery('turkish', 'kitap:*')
LIMIT 10;
"
```

## Örnek Türkçe Belgeler / Sample Turkish Documents

Test için örnek belgeler:

Sample documents for testing:

```sql
-- Insert test documents
INSERT INTO fts_pgsql_index (provider_id, document_id, user_id, content, title, indexed_at)
VALUES 
    ('test', 'tr1', 'admin', 'Türkçe tam metin arama özellikleri', 'Test Belgesi 1', EXTRACT(EPOCH FROM NOW())),
    ('test', 'tr2', 'admin', 'PostgreSQL Türkçe dil desteği çok güçlü', 'Test Belgesi 2', EXTRACT(EPOCH FROM NOW()));

-- Update ts_vector
UPDATE fts_pgsql_index 
SET content_tsv = to_tsvector('turkish', content)
WHERE provider_id = 'test';

-- Search
SELECT title, ts_rank(content_tsv, to_tsquery('turkish', 'türkçe')) as rank
FROM fts_pgsql_index
WHERE content_tsv @@ to_tsquery('turkish', 'türkçe')
ORDER BY rank DESC;
```

## Kaynaklar / Resources

### PostgreSQL Turkish Documentation
- Official: https://www.postgresql.org/docs/current/textsearch-dictionaries.html
- Turkish Snowball Stemmer: https://snowballstem.org/algorithms/turkish/stemmer.html

### Nextcloud Turkish Community
- Forum: https://help.nextcloud.com
- Turkish Users: Search for "Türkçe" in forums

## Sık Sorulan Sorular / FAQ

**S: Türkçe karakterler arama yapılırken dikkate alınır mı?**
**Q: Are Turkish characters considered during search?**

A: Evet! PostgreSQL Türkçe yapılandırması ç, ğ, ı, ö, ş, ü karakterlerini tam olarak destekler.

Yes! PostgreSQL's Turkish configuration fully supports ç, ğ, ı, ö, ş, ü characters.

---

**S: Hem Türkçe hem İngilizce belgelerde arama yapabilir miyim?**
**Q: Can I search both Turkish and English documents?**

A: Evet, ancak bir seferde tek bir dil yapılandırması kullanılır. Çok dilli arama için gelecek sürümlerde destek eklenecektir.

Yes, but one language configuration is used at a time. Multi-language search support will be added in future versions.

---

**S: Trigram özelliği Türkçe için önerilir mi?**
**Q: Is trigram recommended for Turkish?**

A: Evet! Türkçe'de yazım hatalarını tolere etmek için trigram özelliğini etkinleştirin.

Yes! Enable trigram to tolerate typos in Turkish.

---

## Destek / Support

Türkçe destek için / For Turkish language support:

- GitHub Issues: Report language-specific issues
- Community Forum: Ask in Nextcloud forums
- Documentation: See main README.md

## Katkıda Bulunma / Contributing

Türkçe dil desteğini geliştirmek için katkılarınızı bekliyoruz!

We welcome contributions to improve Turkish language support!

- Custom stopwords list
- Better stemming rules
- Turkish-specific optimizations

See `docs/DEVELOPMENT.md` for contribution guidelines.

---

**Başarılar! / Good luck with Turkish full-text search!** 🇹🇷
