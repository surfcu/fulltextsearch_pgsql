# Türkçe Kullanım Kılavuzu

*English summary: setup and Turkish-specific behaviour (stemming, dotted/dotless I) for this platform. The main [README](../README.md) is in English.*

Bu uygulama, Nextcloud'un Full Text Search altyapısı için PostgreSQL'in yerleşik tam metin aramasını kullanır. Nextcloud zaten PostgreSQL üzerinde çalışıyorsa ayrıca bir arama sunucusu (Elasticsearch vb.) kurmanız gerekmez.

## Kurulum

Gereksinimler: Nextcloud 29–35, PostgreSQL 12 veya üstü (Nextcloud'un kendi veritabanı), **Full Text Search** ve **Full Text Search - Files** uygulamaları.

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/surfcu/fulltextsearch_pgsql.git
sudo -u www-data php /var/www/nextcloud/occ app:enable fulltextsearch_pgsql

occ fulltextsearch:configure '{"search_platform":"OCA\\FullTextSearch_PgSql\\Platform\\PostgreSQLPlatform"}'
occ fulltextsearch_pgsql:configure '{"language":"turkish"}'
occ fulltextsearch:test
occ fulltextsearch:index
```

PDF içeriklerinin de aranabilmesi için `poppler-utils` paketini kurun:

```bash
sudo apt install poppler-utils
```

Yazım hatalarına toleranslı arama için `pg_trgm` eklentisi gerekir. PostgreSQL 13 ve sonrasında, veritabanının sahibi Nextcloud kullanıcısıysa uygulama bunu kendisi oluşturur. Değilse bir süper kullanıcı şunu çalıştırabilir:

```bash
sudo -u postgres psql nextcloud -c "CREATE EXTENSION pg_trgm;"
```

## Türkçeye özgü davranışlar

### Kök bulma (stemming)

`turkish` yapılandırması, PostgreSQL'in Snowball tabanlı Türkçe kök bulucusunu kullanır. Ekler kök bulunurken atılır:

| Aranan | Bulunanlar |
|---|---|
| `çalışma` | çalışma, çalışmalar, çalışmalarımız |
| `rapor` | rapor, raporu, raporlar, raporlarda |
| `ev` | ev, evler, evde, evimiz |

Kelimeler ayrıca **önek** olarak da eşleşir, yani yazarken sonuçlar gelir: `rap` yazınca *rapor* ve *raporlar* bulunur.

### Dosya adları ve kelime içi arama

Dosya adları kelimelere ayrılarak dizinlenir: `final`, `2025` veya `final.pdf` aramaları *rapor_2025_final.pdf* dosyasını bulur. `pg_trgm` kuruluysa, en az üç harfli bir kelime başlığın herhangi bir yerinde de eşleşir: `butce` aramasıyla *YillikButceRaporu.xlsx* bulunur. Bu eşleşmeler normal kelime eşleşmelerinin altında sıralanır ve noktalı/noktasız I kuralına uyar (`IKLANDIR` aramasıyla *ISIKLANDIRMA_PLANI.pdf* bulunur).

### Noktalı ve noktasız I

PostgreSQL küçük harfe çevirirken veritabanının yerel ayarını (locale) kullanır. Bu ayar neredeyse hiçbir zaman `tr_TR` değildir, bu yüzden normalde `I` harfi `ı` yerine `i` olur. Sonuç olarak `ISPARTA` aramada `ısparta` ile eşleşmez, büyük harfle yazılmış metinler kaçar.

Uygulama, dil `turkish` olduğunda hem dizinlenen metinde hem de aramada `I → ı` ve `İ → i` dönüşümünü önceden yapar. Bu sayede veritabanının yerel ayarı ne olursa olsun:

| Metin | Arama | Sonuç |
|---|---|---|
| ISPARTA GÜLLERİ | `ısparta`, `Isparta` | ✓ |
| IŞIK | `ışık` | ✓ |
| İstanbul | `istanbul`, `İSTANBUL` | ✓ |
| ÇALIŞMALARIMIZ | `çalışma` | ✓ |

### Eski kodlamalı dosyalar

Windows-1254 (Türkçe) kodlamasıyla kaydedilmiş eski metin dosyaları otomatik olarak UTF-8'e çevrilir; `ö`, `ç`, `ş`, `ğ`, `ı` gibi harfler bozulmadan aranabilir.

## Arama sözdizimi

| Yazılan | Anlamı |
|---|---|
| `bütçe rapor` | En az biri geçen belgeler; ikisini birden içerenler önce gelir |
| `+bütçe +rapor` | İkisi de geçmeli |
| `bütçe -taslak` | "taslak" geçenler hariç |
| `"yıllık rapor"` | Tam ifade |
| `+"yıllık rapor" -2023` | Birleştirilebilir |

Hiçbir sonuç yoksa başlıklarda benzerlik araması yapılır; örneğin `rapr` yazınca (eksik harf) başlığında *rapor* geçen belgeler bulunur.

## Ayarlar

```bash
occ fulltextsearch_pgsql:configure                  # mevcut ayarlar
occ fulltextsearch_pgsql:configure --languages      # sunucunun desteklediği diller
occ fulltextsearch_pgsql:configure '{"language":"turkish"}'
```

Dili değiştirdikten sonra mevcut belgelerin yeni dille işlenmesi için dizini yeniden oluşturun:

```bash
occ fulltextsearch:reset && occ fulltextsearch:index
```

Türkçe ve İngilizce belgeler karışıksa, kök bulma yapmayan `simple` yapılandırması bir seçenektir: ekli hâller (ör. *raporlar*) yalnızca önek eşleşmesiyle bulunur, ama hiçbir dilin kurallarını yanlış uygulamaz.

## Sorun giderme

**Büyük harfli Türkçe kelimeler bulunmuyor.** Dilin `turkish` olduğundan emin olun ve dizini yeniden oluşturun. Bu düzeltme yalnızca `turkish` yapılandırmasıyla dizinlenmiş belgelerde geçerlidir.

**PDF'lerin içeriği aranmıyor.** `poppler-utils` kurulu mu kontrol edin. Taranmış (görüntü) PDF'lerde metin yoktur; bunlar yalnızca adlarıyla bulunur.

**Yazım hatası toleransı çalışmıyor.** `occ fulltextsearch:check` çıktısında `"pg_trgm": false` görünüyorsa eklentiyi oluşturun ve ardından `occ fulltextsearch:test` çalıştırın.
