# Türkçe Kullanım Kılavuzu

*English summary: setup and Turkish-specific behaviour (stemming, dotted/dotless I) for this platform. The main [README](../README.md) is in English.*

Bu uygulama, Nextcloud'un Full Text Search altyapısı için PostgreSQL'in yerleşik tam metin aramasını kullanır. Nextcloud zaten PostgreSQL üzerinde çalışıyorsa ayrıca bir arama sunucusu (Elasticsearch vb.) kurmanız gerekmez.

## Kurulum

Gereksinimler: Nextcloud 30–35, PostgreSQL 13 veya üstü (Nextcloud'un kendi veritabanı; `Ç`, `Ş`, `Ğ`, `Ö`, `Ü` gibi ASCII dışı harflerin küçük harfe çevrilmesi için `C`/`POSIX` dışında UTF-8 tabanlı bir `LC_CTYPE` yerel ayarı gerekir), **Full Text Search** ve **Full Text Search - Files** uygulamaları.

Nextcloud Uygulama Mağazası'ndan (**Uygulamalar → Arama → Full Text Search - PostgreSQL**):

```bash
sudo -u www-data php /var/www/nextcloud/occ app:install fulltextsearch_pgsql
```

Kaynaktan kurulum:

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/surfcu/fulltextsearch_pgsql.git
cd fulltextsearch_pgsql && composer install --no-dev --ignore-platform-req=php && cd ..   # yerleşik PDF okuyucu için
sudo apt install poppler-utils catdoc   # isteğe bağlı: daha hızlı PDF ve eski .doc/.xls/.ppt dosyaları
sudo -u www-data php /var/www/nextcloud/occ app:enable fulltextsearch_pgsql
```

Ardından arama platformunu seçip dizini oluşturun:

```bash
occ fulltextsearch:configure '{"search_platform":"OCA\\FullTextSearch_PgSql\\Platform\\PostgreSQLPlatform"}'
occ fulltextsearch_pgsql:configure '{"language":"turkish"}'
occ fulltextsearch:test
occ fulltextsearch:index
```

## Birden fazla dil

Belgeleriniz hem Türkçe hem İngilizce ise iki dili birden ayarlayın; ilk yazılan ana dildir:

```bash
occ fulltextsearch_pgsql:configure '{"language":"turkish,english"}'
occ fulltextsearch:reset && occ fulltextsearch:index
```

Her belge, içeriğindeki *ve*, *için*, *the*, *which* gibi sık kelimelere bakılarak kendi dilinde dizinlenir ve her arama iki dilde birden yapılır. Metni olmayan ya da çok kısa belgeler (yalnızca dosya adı) ana dilde dizinlenir. Aynı ayar **Yönetim ayarları → Tam metin arama** sayfasından da yapılabilir.

Eski Office dosyaları (`.doc`, `.xls`, `.ppt`) için sunucuya `catdoc` paketini kurun: `apt install catdoc`.

Daha hızlı ve kapsamlı PDF metin çıkarımı için `poppler-utils` paketini kurmanız önerilir (kurulu değilse yerleşik PHP ayrıştırıcısı kullanılır):

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

### Birden çok kelimeyle arama: nadir kelimeler daha ağır basar

Birden çok kelimeyle aranınca her kelime, göremediğiniz belgeler hariç kaç belgede geçtiğine göre ağırlıklandırılır (BM25'in ters belge sıklığı). `fatura kırklareli` aramasında *Kırklareli* geçen birkaç belge, yalnızca *fatura* geçen yüzlerce belgenin önüne çıkar. Tüm kelimeleri içeren belgeler yine en üstte listelenir; tek kelimelik aramaların sıralaması değişmez.

### Noktalı ve noktasız I

PostgreSQL küçük harfe çevirirken veritabanının yerel ayarını (locale) kullanır. Bu ayar neredeyse hiçbir zaman `tr_TR` değildir, bu yüzden normalde `I` harfi `ı` yerine `i` olur. Sonuç olarak `ISPARTA` aramada `ısparta` ile eşleşmez, büyük harfle yazılmış metinler kaçar.

Uygulama, dil `turkish` olduğunda hem dizinlenen metinde hem de aramada `I → ı` ve `İ → i` dönüşümünü önceden yapar. Bu sayede `I` ve `İ` harfleri veritabanının yerel ayarından bağımsız çalışır; diğer büyük Türkçe harfler (`Ç`, `Ş`, `Ğ`, `Ö`, `Ü`) ise küçük harfe çevrilmek için veritabanının UTF-8 `LC_CTYPE` yerel ayarına ihtiyaç duyar (yönetim paneli ve `occ fulltextsearch:check` bu desteği gösterir):

| Metin | Arama | Sonuç |
|---|---|---|
| ISPARTA GÜLLERİ | `ısparta`, `Isparta` | ✓ |
| IŞIK | `ışık` | ✓ |
| İstanbul | `istanbul`, `İSTANBUL` | ✓ |
| ÇALIŞMALARIMIZ | `çalışma` | ✓ |

Sonuçlardaki alıntılar da aynı kurala uyar: `ışık` aramasında alıntı *IŞIK* kelimesinin geçtiği yerden alınır ve metin yazıldığı gibi gösterilir.

### Türkçe karakterler olmadan arama ve yazım hataları

Dizindeki tüm kelimelerden bir kelime listesi tutulur. Bu sayede:

| Aranan | Bulunan |
|---|---|
| `calisma` | çalışma (ve tersi: `çalışma` aramasıyla *calisma* yazılmış metinler de bulunur) |
| `sozlesme` | sözleşme, sözleşmesi |
| `ogrenci` | öğrenci |
| `calis` | çalış… (yazarken de çalışır) |
| `rpaor` | rapor (yer değiştirmiş harfler, `pg_trgm` gerekir) |

Yazdığınız kelime yine de aranır; düzeltmeler yalnızca erişiminiz olan belgelerde geçen kelimeler arasından seçilir ve yazım düzeltmesi ilk sayfada, düzeltmesiz sonuç sayısı sayfa boyutunun altındayken devreye girer. Bu özellik `typo_correction` ayarıyla kapatılabilir. 1.3.0'dan önce oluşturulmuş büyük dizinlerde kelime listesini bir kez oluşturun:

```bash
occ fulltextsearch_pgsql:vocabulary --rebuild
```

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

Yalnızca düz kelimelerden oluşan (`+`, `-` veya tırnak içi tam ifade içermeyen) aramalarda hiçbir sonuç çıkmazsa başlıklarda benzerlik araması yapılır; örneğin `rapr` yazınca (eksik harf) başlığında *rapor* geçen belgeler bulunur.

## Ayarlar

```bash
occ fulltextsearch_pgsql:configure                  # mevcut ayarlar
occ fulltextsearch_pgsql:configure --languages      # sunucunun desteklediği diller
occ fulltextsearch_pgsql:configure '{"language":"turkish","search_timeout":10}'
```

Başlıca ek ayarlar: `search_timeout` (sorgu zaman aşımı, varsayılan `10` sn, `0`–`300`; `0` kapatır) ve `pdftotext_path` (`pdftotext` yolu; yalnızca `occ` üzerinden değiştirilebilir, yönetim panelinde salt okunur gösterilir).

Dili değiştirdikten sonra mevcut belgelerin yeni dille işlenmesi için dizini yeniden oluşturun:

```bash
occ fulltextsearch:reset && occ fulltextsearch:index
```

Türkçe ve İngilizce belgeler karışıksa, kök bulma yapmayan `simple` yapılandırması bir seçenektir: ekli hâller (ör. *raporlar*) yalnızca önek eşleşmesiyle bulunur, ama hiçbir dilin kurallarını yanlış uygulamaz.

## PostgreSQL sürüm yükseltme

1.6.0 sürümünden (Şema Sürümü 3) itibaren dizin tablosu `config` sütununu `regconfig` yerine `text` olarak saklar; bu nedenle `pg_upgrade` büyük PostgreSQL sürüm geçişlerinde tabloları silmeden ve dizini yeniden oluşturmadan doğrudan çalışır.

## Sorun giderme

**Büyük harfli Türkçe kelimeler bulunmuyor.** Dilin `turkish` olduğundan emin olun, `occ fulltextsearch:check` çıktısında `"locale_folding": true` göründüğünü doğrulayın ve dizini yeniden oluşturun. `I` ve `İ` dışındaki büyük harfler (`Ç`, `Ş`, `Ğ`, `Ö`, `Ü`) veritabanının UTF-8 `LC_CTYPE` yerel ayarını kullanır.

**PDF'lerin içeriği aranmıyor.** `poppler-utils` kurulu mu kontrol edin. Taranmış (görüntü) PDF'lerde metin yoktur; bunlar yalnızca adlarıyla bulunur.

**Yazım hatası toleransı çalışmıyor.** `occ fulltextsearch:check` çıktısında `"pg_trgm": false` görünüyorsa eklentiyi oluşturun ve ardından `occ fulltextsearch:test` çalıştırın.
