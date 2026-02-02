# Türkçe Hızlı Başlangıç Kılavuzu
# Turkish Quick Start Guide

## Kurulum / Installation

```bash
# 1. Uygulamayı etkinleştirin / Enable the app
sudo -u www-data php occ app:enable fulltextsearch_pgsql

# 2. pg_trgm uzantısını etkinleştirin / Enable pg_trgm extension
sudo -u postgres psql nextcloud -c "CREATE EXTENSION pg_trgm;"

# 3. Türkçe dilini ayarlayın / Set Turkish language
sudo -u www-data php occ config:app:set fulltextsearch_pgsql language --value=turkish

# 4. Platforma yapılandırın / Configure platform
sudo -u www-data php occ fulltextsearch:configure
# 'pgsql' seçin / Select 'pgsql'

# 5. İçeriği indeksleyin / Index content
sudo -u www-data php occ fulltextsearch:index
```

## Arama Örnekleri / Search Examples

### Web Arayüzü / Web Interface
1. Nextcloud'a giriş yapın
2. Üstteki arama çubuğunu kullanın
3. Türkçe arama yapın: "belge", "dosya", "rapor"

### Komut Satırı / Command Line
```bash
# Basit arama / Simple search
php occ fulltextsearch:search "kitap"

# Birden fazla kelime / Multiple words
php occ fulltextsearch:search "türkçe belge"

# Sonuç sayısını kontrol et / Check result count
php occ fulltextsearch:status
```

## Türkçe Özellikler / Turkish Features

✅ **Kök Bulma / Stemming**: kitaplar → kitap  
✅ **Stopwords**: "ve", "veya", "ile" otomatik filtrelenir  
✅ **Özel Karakterler**: ç, ğ, ı, ö, ş, ü tam destek  
✅ **Bulanık Arama / Fuzzy**: Yazım hatalarını tolere eder  

## Test / Testing

```bash
# Platform testi / Test platform
php occ fulltextsearch:test

# Durum kontrolü / Check status
php occ fulltextsearch:status

# Yeniden indeksleme / Re-index
php occ fulltextsearch:reset
php occ fulltextsearch:index
```

## PostgreSQL Test

```sql
-- Türkçe yapılandırmayı test et
SELECT to_tsvector('turkish', 'Kitapları okuyorum');
-- Sonuç: 'kitap':1 'oku':2

-- Arama testi
SELECT to_tsvector('turkish', 'Çalışıyorum') @@ to_tsquery('turkish', 'çalış');
-- Sonuç: true
```

## Yaygın Komutlar / Common Commands

```bash
# Dil değiştir / Change language
php occ config:app:set fulltextsearch_pgsql language --value=turkish

# Dili kontrol et / Check language
php occ config:app:get fulltextsearch_pgsql language

# Trigram etkinleştir / Enable trigram
php occ config:app:set fulltextsearch_pgsql use_trigram --value=true

# Minimum kelime uzunluğu / Minimum word length
php occ config:app:set fulltextsearch_pgsql min_word_length --value=2

# Maksimum sonuç / Maximum results
php occ config:app:set fulltextsearch_pgsql max_results --value=100
```

## Otomatik İndeksleme / Automatic Indexing

```bash
# Cron ekle / Add to cron
crontab -e -u www-data

# Her 15 dakikada bir / Every 15 minutes
*/15 * * * * php /var/www/nextcloud/occ fulltextsearch:index
```

## Sorun Giderme / Troubleshooting

### Türkçe karakterler çalışmıyor
```sql
-- UTF-8 kodlamasını kontrol et
SELECT current_setting('server_encoding');
-- UTF8 olmalı
```

### Arama sonuç vermiyor
```bash
# 1. Dili kontrol et
php occ config:app:get fulltextsearch_pgsql language

# 2. Yeniden indeksle
php occ fulltextsearch:reset
php occ config:app:set fulltextsearch_pgsql language --value=turkish
php occ fulltextsearch:index

# 3. Test et
php occ fulltextsearch:search "test"
```

### Performans sorunları
```bash
# PostgreSQL bakımı
sudo -u postgres psql nextcloud -c "ANALYZE fts_pgsql_index;"
sudo -u postgres psql nextcloud -c "VACUUM fts_pgsql_index;"
```

## Önerilen Ayarlar / Recommended Settings

### Türkçe için en iyi yapılandırma
```bash
php occ config:app:set fulltextsearch_pgsql language --value=turkish
php occ config:app:set fulltextsearch_pgsql use_trigram --value=true
php occ config:app:set fulltextsearch_pgsql min_word_length --value=2
php occ config:app:set fulltextsearch_pgsql max_results --value=100
```

## Destek / Support

📖 **Detaylı Kılavuz**: `docs/TURKISH.md`  
📖 **Kurulum**: `docs/INSTALLATION.md`  
📖 **Mimari**: `docs/ARCHITECTURE.md`  
🌐 **Forum**: https://help.nextcloud.com  
🐛 **Sorunlar**: GitHub Issues  

## Faydalı Bağlantılar / Useful Links

- PostgreSQL Türkçe Dokümantasyon: https://www.postgresql.org/docs/current/textsearch.html
- Snowball Turkish Stemmer: https://snowballstem.org/algorithms/turkish/stemmer.html
- Nextcloud Forum: https://help.nextcloud.com

---

**Başarılar! / Good Luck!** 🇹🇷

Daha fazla bilgi için: `docs/TURKISH.md`  
For more information: `docs/TURKISH.md`
