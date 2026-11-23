# Docker and Nextcloud AIO

This guide covers running `fulltextsearch_pgsql` in containerized Nextcloud setups, including the official Docker images and Nextcloud All-in-One (AIO). See [README.md](../README.md) for general features, configuration, and query syntax, and [TURKISH.md](TURKISH.md) for Turkish-specific setup notes.

## How extraction tools work in containers

Optional extraction tools — `pdftotext` (`poppler-utils`) for faster PDF parsing, and `catdoc` / `catppt` / `xls2csv` (`catdoc`) for Office 97–2003 files (`.doc`, `.xls`, `.ppt`) — are executed by PHP inside the **Nextcloud container**. PHP looks for them on `PATH` and in `/usr/local/bin`, `/usr/bin`, `/bin`, and `/opt/homebrew/bin`.

Installing `poppler-utils` or `catdoc` on the **Docker host** does not make them available to Nextcloud:

- Host binaries (such as `/usr/bin/catdoc` on a Debian/Ubuntu host, linked against glibc) are outside the container's filesystem and invisible to PHP (and incompatible with Alpine/musl containers).
- When `catdoc` is missing inside the container, `occ fulltextsearch:check` reports `"catdoc": "none"`, and the admin panel shows `"not found: these files are indexed by name only"`.

Run `occ` commands inside your Nextcloud container:

```bash
# Standard Docker
docker exec -u www-data <nextcloud-container> php occ ...

# Nextcloud AIO (drop -it in cron jobs or non-interactive scripts)
docker exec --user www-data -it nextcloud-aio-nextcloud php occ ...
```

**Database prerequisites:** Your PostgreSQL container must use a UTF-8 database locale (`LC_CTYPE` other than `C`/`POSIX`) for non-ASCII case folding and accent matching. When the Nextcloud database user owns the database (the default in Nextcloud AIO), `fulltextsearch_pgsql` creates the `pg_trgm` extension automatically on PostgreSQL 13+. Otherwise, enable it once as a PostgreSQL superuser:

```bash
docker exec -u postgres <postgres-container> psql -d <nextcloud-db> -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
```

## Standard Docker (official Nextcloud images)

Build a small derived image so extraction tools survive container recreations and image upgrades.

### Debian-based images (`nextcloud:stable`, `nextcloud:31-apache`, `nextcloud:31-fpm`)

Both `poppler-utils` and `catdoc` are available in Debian's repositories:

```dockerfile
FROM nextcloud:stable

RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils catdoc \
    && rm -rf /var/lib/apt/lists/*
```

If you use a `nextcloud:*-fpm` image with a separate nginx reverse proxy container, build and attach this image to the **PHP-FPM container** (and any background cron container running PHP), not the nginx container.

For archives with legacy non-Unicode Turkish Office 97–2003 files, set `source_charset=cp1254` in `/etc/catdocrc` (see the `catdocrc` example below).

### Alpine-based images (`nextcloud:stable-alpine`, `nextcloud:...-fpm-alpine`)

`poppler-utils` is packaged in Alpine (`apk add --no-cache poppler-utils`), whereas `catdoc` is **not** shipped in Alpine's `main`, `community`, or `edge/testing` repositories. Use a two-stage `Dockerfile` to compile static musl `catdoc` 0.95 with Debian 12's security and stability patches without keeping compilers in the final image:

```dockerfile
FROM nextcloud:stable-alpine AS catdoc-builder

RUN apk add --no-cache build-base curl xz patch \
    && mkdir -p /tmp/catdoc-build && cd /tmp/catdoc-build \
    && curl -fsSLO https://deb.debian.org/debian/pool/main/c/catdoc/catdoc_0.95.orig.tar.gz \
    && curl -fsSLO https://deb.debian.org/debian/pool/main/c/catdoc/catdoc_0.95-6~deb12u1.debian.tar.xz \
    && tar -xzf catdoc_0.95.orig.tar.gz --strip-components=1 \
    && tar -xJf catdoc_0.95-6~deb12u1.debian.tar.xz \
    && while IFS= read -r p; do \
        case "$p" in ""|\#*) continue ;; esac; \
        patch -p1 < "debian/patches/$p"; \
    done < debian/patches/series \
    && ./configure --prefix=/usr/local --mandir=/usr/local/share/man --disable-wordview \
        CC="gcc -static" \
        CFLAGS="-O2 -std=gnu11 -Wno-error=implicit-function-declaration -Wno-error=incompatible-pointer-types -Wno-error=int-conversion" \
    && make \
    && make install \
    && mkdir -p /usr/local/etc \
    && printf '%s\n' \
        'charset_path=/usr/local/share/catdoc' \
        'map_path=/usr/local/share/catdoc' \
        'source_charset=cp1252' \
        'target_charset=utf-8' \
        "unknown_char='?'" \
        > /usr/local/etc/catdocrc

FROM nextcloud:stable-alpine

RUN apk add --no-cache poppler-utils

COPY --from=catdoc-builder /usr/local/bin/catdoc /usr/local/bin/catppt /usr/local/bin/xls2csv /usr/local/bin/
COPY --from=catdoc-builder /usr/local/share/catdoc /usr/local/share/catdoc
COPY --from=catdoc-builder /usr/local/etc/catdocrc /usr/local/etc/catdocrc
```

> **Note:** Check `https://deb.debian.org/debian/pool/main/c/catdoc/` if Debian updates `catdoc_0.95-6~deb12u1.debian.tar.xz` to a newer patch revision, and set `source_charset=cp1254` in `/usr/local/etc/catdocrc` for legacy non-Unicode Turkish documents.

## Nextcloud AIO (All-in-One)

You do **not** need AIO's optional Elasticsearch-based Fulltextsearch community container: `fulltextsearch_pgsql` indexes directly into `nextcloud-aio-database`.

Install **Full Text Search**, **Full Text Search - Files**, and **Full Text Search - PostgreSQL** from Nextcloud's App Store (or via `docker exec --user www-data nextcloud-aio-nextcloud php occ app:install fulltextsearch_pgsql`), then configure the platform and language as described in [README.md](../README.md#installation).

### PDF extraction (`poppler-utils`)

Pass `poppler-utils` in `NEXTCLOUD_ADDITIONAL_APKS` on `nextcloud-aio-mastercontainer`, keeping AIO's default `imagemagick` in the space-separated list so previews continue to work:

```yaml
environment:
  - NEXTCLOUD_ADDITIONAL_APKS=imagemagick poppler-utils
```

Stop and start containers from the AIO interface after changing `NEXTCLOUD_ADDITIONAL_APKS`. AIO reinstalls these packages automatically on every container update.

### Office 97–2003 files (`catdoc` on Alpine)

Nextcloud AIO's `nextcloud-aio-nextcloud` container runs on Alpine Linux, which introduces three constraints for `catdoc`:

1. Alpine (including v3.24 `main`, `community`, and `edge/testing`) has no `catdoc` package.
2. AIO's `/start.sh` invokes `apk add --no-cache "$app"` without `--allow-untrusted`, so unsigned custom `.apk` packages cannot be installed via `NEXTCLOUD_ADDITIONAL_APKS`.
3. Binaries copied directly into `/usr/local/bin` inside `nextcloud-aio-nextcloud` are lost whenever `nextcloud-aio-mastercontainer` recreates the container during AIO updates.

To solve all three without leaving compilers inside the live Nextcloud container, build static-pie musl binaries in an ephemeral container from AIO's own image, stage them on the host, and sync them into `nextcloud-aio-nextcloud` automatically if missing.

#### Step 1: Build static-pie musl binaries in an ephemeral container

Run on the Docker host (using the already-pulled `ghcr.io/nextcloud-releases/aio-nextcloud:latest` image):

```sh
mkdir -p /opt/nextcloud-aio/catdoc-alpine/bin \
  /opt/nextcloud-aio/catdoc-alpine/etc \
  /opt/nextcloud-aio/catdoc-alpine/share/catdoc

docker run --rm -v /opt/nextcloud-aio/catdoc-alpine:/out \
  ghcr.io/nextcloud-releases/aio-nextcloud:latest sh -ec '
    apk add --no-cache build-base curl xz patch
    mkdir -p /tmp/catdoc-build && cd /tmp/catdoc-build
    curl -fsSLO https://deb.debian.org/debian/pool/main/c/catdoc/catdoc_0.95.orig.tar.gz
    curl -fsSLO https://deb.debian.org/debian/pool/main/c/catdoc/catdoc_0.95-6~deb12u1.debian.tar.xz
    tar -xzf catdoc_0.95.orig.tar.gz --strip-components=1
    tar -xJf catdoc_0.95-6~deb12u1.debian.tar.xz
    while IFS= read -r p; do
      case "$p" in ""|\#*) continue ;; esac
      patch -p1 < "debian/patches/$p"
    done < debian/patches/series
    ./configure --prefix=/usr/local --mandir=/usr/local/share/man --disable-wordview \
      CC="gcc -static" \
      CFLAGS="-O2 -std=gnu11 -Wno-error=implicit-function-declaration -Wno-error=incompatible-pointer-types -Wno-error=int-conversion"
    make
    make install
    cp /usr/local/bin/catdoc /usr/local/bin/catppt /usr/local/bin/xls2csv /out/bin/
    cp -a /usr/local/share/catdoc/. /out/share/catdoc/
  '
```

- **Debian patches (`catdoc_0.95-6~deb12u1.debian.tar.xz`):** Applies Debian 12's 9 security and stability patches, including CVE-2017-11110 and buffer-overflow guards. If Debian publishes a newer patch revision under `pool/main/c/catdoc/`, update the `.debian.tar.xz` filename.
- **Why `CC="gcc -static"`:** `catdoc`'s `Makefile.in` drops `LDFLAGS` during linking; passing `-static` in `CC` produces static-pie binaries (`catdoc`, `catppt`, `xls2csv`) with zero runtime `.so` dependencies so they survive future Alpine upgrades.
- **Why `-std=gnu11 -Wno-error=...`:** Avoids GCC 15 C23/strict-prototype build failures on Alpine 3.24+.
- **Charset tables:** Copies all 50 charset maps into `/opt/nextcloud-aio/catdoc-alpine/share/catdoc/`.

#### Step 2: Create `/opt/nextcloud-aio/catdoc-alpine/etc/catdocrc`

```ini
charset_path=/usr/local/share/catdoc
map_path=/usr/local/share/catdoc
source_charset=cp1252
target_charset=utf-8
unknown_char='?'
```

**Turkish archives:** When `language` includes `turkish` or your archive contains legacy non-Unicode Turkish Word 6/95 `.doc` or BIFF5 `.xls` files, change `source_charset=cp1252` to:

```ini
source_charset=cp1254
```

This decodes `ğ`, `ş`, `ı`, `İ`, `ö`, `ü`, and `ç` cleanly alongside UTF-16LE Office 97–2003 documents.

#### Step 3: Persist across AIO container updates

Create `/opt/nextcloud-aio/install-catdoc-into-nc.sh` on the host and make it executable (`chmod 0755`):

```bash
#!/usr/bin/env bash
set -euo pipefail

CONTAINER="nextcloud-aio-nextcloud"
SRC="/opt/nextcloud-aio/catdoc-alpine"

# Exit quietly when the container is stopped (e.g. during AIO backup or update windows).
if [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null || true)" != "true" ]; then
    exit 0
fi

if docker exec "$CONTAINER" sh -ec '
    test -x /usr/local/bin/catdoc \
    && test -x /usr/local/bin/catppt \
    && test -x /usr/local/bin/xls2csv \
    && test -f /usr/local/share/catdoc/cp1254.txt \
    && test -f /usr/local/etc/catdocrc
' 2>/dev/null; then
    exit 0
fi

docker exec "$CONTAINER" mkdir -p /usr/local/bin /usr/local/share/catdoc /usr/local/etc
docker cp "$SRC/bin/catdoc" "$CONTAINER:/usr/local/bin/catdoc"
docker cp "$SRC/bin/catppt" "$CONTAINER:/usr/local/bin/catppt"
docker cp "$SRC/bin/xls2csv" "$CONTAINER:/usr/local/bin/xls2csv"
docker cp "$SRC/share/catdoc/." "$CONTAINER:/usr/local/share/catdoc/"
docker cp "$SRC/etc/catdocrc" "$CONTAINER:/usr/local/etc/catdocrc"
docker exec "$CONTAINER" sh -ec '
    chmod 0755 /usr/local/bin/catdoc /usr/local/bin/catppt /usr/local/bin/xls2csv
    chmod 0644 /usr/local/etc/catdocrc /usr/local/share/catdoc/*
'
```

Schedule it every 10 minutes via `/etc/cron.d/nc-aio-catdoc` (`chown root:root /etc/cron.d/nc-aio-catdoc && chmod 0644 /etc/cron.d/nc-aio-catdoc`):

```cron
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

*/10 * * * * root /opt/nextcloud-aio/install-catdoc-into-nc.sh
```

Run the script once right away and verify inside the container:

```bash
sudo /opt/nextcloud-aio/install-catdoc-into-nc.sh
docker exec --user www-data nextcloud-aio-nextcloud php occ fulltextsearch:check
```

Confirm `"catdoc": "/usr/local/bin/catdoc"` (and `"pdftotext": "/usr/bin/pdftotext"` if `poppler-utils` is installed).

## Selective re-indexing

If `.doc`, `.xls`, or `.ppt` files were already indexed before `catdoc` was installed, their rows in `oc_fulltextsearch_index` already have `status = 1` (`IIndex::INDEX_OK`) with empty `content`. A plain `occ fulltextsearch:index` skips them.

Instead of resetting and rebuilding the entire index with `occ fulltextsearch:reset && occ fulltextsearch:index` (which remains fine for small instances), you can queue only empty legacy Office rows (`status = 28` = `IIndex::INDEX_FULL`):

1. Stop `occ fulltextsearch:live` if it is running.
2. Update the queued rows in PostgreSQL (adjust `oc_` / `ftspg_oc_index` if your Nextcloud `dbtableprefix` differs):

   ```bash
   # Nextcloud AIO example (for standard Docker, target your postgres container and db user)
   docker exec -i nextcloud-aio-database sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"' <<'SQL'
   UPDATE oc_fulltextsearch_index i
   SET status = 28, indexed = 0, err = 0, message = '[]'
   FROM ftspg_oc_index f
   WHERE f.provider_id = i.provider_id
     AND f.document_id = i.document_id
     AND f.provider_id = 'files'
     AND f.title ~* '\.(doc|xls|ppt)$'
     AND length(coalesce(f.content, '')) = 0;
   SQL
   ```

   > **Important:** Always keep `message = '[]'` (a valid JSON array string), never `''` or `NULL`. Upstream `fulltextsearch`'s `IndexesService::parseIndexesSelectSql()` decodes `message` as JSON; an empty string throws a `TypeError` (`setErrors(): Argument #1 ($messages) must be of type array, null given`) and leaves a stuck run tick in `oc_fulltextsearch_ticks` (clearable with `occ fulltextsearch:stop`).

3. Run the indexer (`--no-readline` avoids interactive prompts in scripts/containers):

   ```bash
   docker exec --user www-data nextcloud-aio-nextcloud php occ fulltextsearch:index --no-readline
   ```

   Already-indexed documents (`status = 1`) are skipped while the queued `.doc`, `.xls`, and `.ppt` files are extracted.

### Spot-checking a single document

Running `occ fulltextsearch:document:index <userId> files <docId>` alone on an existing `status = 1` row still leaves content empty, because upstream `fulltextsearch`'s `DocumentIndex.php` loads the existing row and calls `$provider->updateDocument($index)` before `INDEX_FULL` is set, causing `FilesService::updateContentFromFile` to skip reading the file. Mark the document for full indexing first:

```bash
occ fulltextsearch:document:status files <docId> --value=INDEX
occ fulltextsearch:document:index <userId> files <docId>
```

## Keeping the index current in Docker

- **Background cron (default in AIO):** Nextcloud's built-in cron runs periodic incremental indexing automatically.
- **Live indexing (`occ fulltextsearch:live`):** For near-real-time updates, run `occ fulltextsearch:live` from a dedicated cron/worker container or a host `systemd` service wrapping `docker exec -u www-data <nextcloud-container> php occ fulltextsearch:live`.
