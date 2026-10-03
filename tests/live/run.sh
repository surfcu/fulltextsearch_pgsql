#!/usr/bin/env bash
# End-to-end test inside a real Nextcloud: installs on PostgreSQL, enables Full Text Search,
# the Files provider and this platform, runs the framework's own `occ fulltextsearch:test`,
# then indexes real files and searches them through occ.
#
# Run from the Nextcloud server directory, with this app in apps/fulltextsearch_pgsql and
# nextcloud/fulltextsearch + nextcloud/files_fulltextsearch in apps/.
#
# Environment:
#   PGHOST       PostgreSQL host (default 127.0.0.1); superuser postgres/postgres for checks
#   DB_USER      user Nextcloud connects as (default postgres; installer creates its own user)
#   DB_PASS      its password (default postgres)
#   EXPECT_TRGM  1 = pg_trgm must end up available, 0 = must not; unset = either
set -euo pipefail

export PGHOST="${PGHOST:-127.0.0.1}" PGUSER=postgres PGPASSWORD=postgres

occ() { php occ "$@"; }
step() { printf '\n== %s\n' "$*"; }
failures=0

step "Install Nextcloud on PostgreSQL"
occ maintenance:install --database=pgsql --database-host="$PGHOST" \
	--database-name=nextcloud --database-user="${DB_USER:-postgres}" --database-pass="${DB_PASS:-postgres}" \
	--admin-user=admin --admin-pass=admin
occ config:system:set debug --value=true --type=boolean

step "Enable apps"
occ app:enable fulltextsearch files_fulltextsearch fulltextsearch_pgsql
occ app:list --enabled | grep -E 'fulltextsearch'
occ fulltextsearch:configure '{"search_platform":"OCA\\FullTextSearch_PgSql\\Platform\\PostgreSQLPlatform"}'
occ fulltextsearch_pgsql:configure '{"language":"turkish"}'

step "Framework test: occ fulltextsearch:test"
occ fulltextsearch:test

step "Platform status: occ fulltextsearch:check"
check_out=$(occ fulltextsearch:check)
echo "$check_out"
trgm=0
grep -q '"pg_trgm": true' <<< "$check_out" && trgm=1
echo "pg_trgm available: $trgm (expected: ${EXPECT_TRGM:-either})"
if [ -n "${EXPECT_TRGM:-}" ] && [ "$EXPECT_TRGM" != "$trgm" ]; then
	echo "FAIL  pg_trgm availability"
	failures=$((failures + 1))
fi

step "Create files"
files=data/admin/files
python3 - "$files" <<'PY'
import sys, zipfile, os
d = sys.argv[1]
def write(name, data, mode="w"):
    with open(os.path.join(d, name), mode, **({"encoding": "utf-8"} if mode == "w" else {})) as f:
        f.write(data)
write("Toplantı Notları.txt", "Yıllık çalışma planı ve sözleşme taslağı. ISPARTA ofisi toplantısı.\n")
write("YillikButceRaporu.txt", "Gelir ve gider tablosu.\n")
with zipfile.ZipFile(os.path.join(d, "rapor_2025_final.docx"), "w") as z:
    z.writestr("[Content_Types].xml", '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>')
    z.writestr("word/document.xml", "<w:document><w:body><w:p><w:r><w:t>Quarterly hippopotamus census</w:t></w:r></w:p></w:body></w:document>")
pdf = (b"%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj "
       b"3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 100]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj "
       b"4 0 obj<</Length 44>>stream\nBT /F1 18 Tf 20 40 Td (Okapi Quokka) Tj ET\nendstream endobj "
       b"5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>")
write("invoice.pdf", pdf, "wb")
PY
ls -la "$files"
occ files:scan admin

OC_PASS='Correct-Horse-Battery-Staple-42' occ user:add --password-from-env --display-name=Bob bob

step "Index"
# The indexer redraws a full-screen status panel; keep it out of the log.
if ! occ fulltextsearch:index --no-readline > /tmp/ftspg-index.log 2>&1; then
	echo "FAIL  occ fulltextsearch:index"
	tr -d '\033' < /tmp/ftspg-index.log | tail -n 40
	exit 1
fi
grep -aE 'Result:|Error:|Status:' /tmp/ftspg-index.log | tail -n 3
occ fulltextsearch_pgsql:vocabulary

# expect USER QUERY FILE-OR-EMPTY
# Newer Full Text Search versions print JSON with titles; older ones (Nextcloud 30) print
# " - <file id> score:…", so file ids are mapped back to names from the file cache.
expect() {
	local user=$1 query=$2 want=$3 out ids
	out=$(occ fulltextsearch:search "$user" "$query" --output=json)
	ids=$(psql -d nextcloud -tAc "SELECT fileid || '=' || name FROM oc_filecache WHERE path LIKE 'files/%'")
	if python3 - "$want" "$out" "$ids" <<'PY'
import json, re, sys
want, raw, ids = sys.argv[1], sys.argv[2], sys.argv[3]
names = dict(line.split("=", 1) for line in ids.splitlines() if "=" in line)
try:
    titles = [str(d.get("title")) for docs in json.loads(raw).values() for d in docs]
except ValueError:
    titles = [names.get(i, "#" + i) for i in re.findall(r"^ - (\S+) score", raw, re.M)]
print(f"   {len(titles)} result(s): " + ", ".join(titles))
ok = any(want in t for t in titles) if want else titles == []
sys.exit(0 if ok else 1)
PY
	then
		echo "ok    $user: '$query' -> ${want:-nothing}"
	else
		echo "FAIL  $user: '$query' -> expected ${want:-nothing}"
		echo "$out"
		failures=$((failures + 1))
	fi
}

# Checks for features that need pg_trgm: run when it is available, else expect no result.
expect_trgm() {
	if [ "$trgm" = 1 ]; then
		expect "$@"
	else
		echo "      (pg_trgm unavailable: '$2' must find nothing)"
		expect "$1" "$2" ''
	fi
}

step "Search real files"
expect admin 'çalışma'      'Toplantı Notları.txt'   # Turkish stemming
expect admin 'calisma'      'Toplantı Notları.txt'   # without diacritics
expect admin 'ısparta'      'Toplantı Notları.txt'   # dotless I from uppercase text
expect admin 'toplantı'     'Toplantı Notları.txt'   # file name
expect admin 'hippopotamus' 'rapor_2025_final.docx'  # docx content
expect_trgm admin 'hipopotamus' 'rapor_2025_final.docx'  # typo correction
expect admin 'final'        'rapor_2025_final.docx'  # file name part
expect admin 'quokka'       'invoice.pdf'            # PDF via pdftotext
expect_trgm admin 'butce'     'YillikButceRaporu.txt'  # substring in a title
expect bob   'hippopotamus' ''                       # not shared with bob

step "Upgrade path: repair step is idempotent"
occ maintenance:repair >/dev/null
expect admin 'hippopotamus' 'rapor_2025_final.docx'

step "Nextcloud log (warnings and errors from this app)"
if grep -h 'fulltextsearch_pgsql\|FullTextSearch_PgSql' data/nextcloud.log 2>/dev/null | grep -E '"level":[3-4]'; then
	echo "FAIL  errors logged"
	failures=$((failures + 1))
else
	echo "ok    none"
fi

echo
if [ "$failures" -ne 0 ]; then
	echo "$failures check(s) failed"
	exit 1
fi
echo "all live checks passed"
