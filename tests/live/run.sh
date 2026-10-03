#!/usr/bin/env bash
# End-to-end test inside a real Nextcloud: installs on PostgreSQL, enables Full Text Search,
# the Files provider and this platform, runs the framework's own `occ fulltextsearch:test`,
# then indexes real files and searches them through occ.
#
# Run from the Nextcloud server directory, with this app in apps/fulltextsearch_pgsql and
# nextcloud/fulltextsearch + nextcloud/files_fulltextsearch in apps/. Needs PostgreSQL at
# $PGHOST (default 127.0.0.1) with user/password postgres/postgres.
set -euo pipefail

occ() { php occ "$@"; }
step() { printf '\n== %s\n' "$*"; }
failures=0

step "Install Nextcloud on PostgreSQL"
occ maintenance:install --database=pgsql --database-host="${PGHOST:-127.0.0.1}" \
	--database-name=nextcloud --database-user=postgres --database-pass=postgres \
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
occ fulltextsearch:check

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
occ fulltextsearch:index --no-readline
occ fulltextsearch_pgsql:vocabulary

# expect USER QUERY FILE-OR-EMPTY
expect() {
	local user=$1 query=$2 want=$3 out
	out=$(occ fulltextsearch:search "$user" "$query" --output=json)
	if python3 - "$want" "$out" <<'PY'
import json, sys
want, raw = sys.argv[1], sys.argv[2]
docs = [d for docs in json.loads(raw).values() for d in docs]
text = json.dumps(docs, ensure_ascii=False)
ok = (want in text) if want else (docs == [])
print(f"   {len(docs)} result(s): " + ", ".join(str(d.get("title")) for d in docs))
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

step "Search real files"
expect admin 'çalışma'      'Toplantı Notları.txt'   # Turkish stemming
expect admin 'calisma'      'Toplantı Notları.txt'   # without diacritics
expect admin 'ısparta'      'Toplantı Notları.txt'   # dotless I from uppercase text
expect admin 'toplantı'     'Toplantı Notları.txt'   # file name
expect admin 'hippopotamus' 'rapor_2025_final.docx'  # docx content
expect admin 'hipopotamus'  'rapor_2025_final.docx'  # typo correction
expect admin 'final'        'rapor_2025_final.docx'  # file name part
expect admin 'quokka'       'invoice.pdf'            # PDF via pdftotext
expect admin 'butce'        'YillikButceRaporu.txt'  # substring in a title
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
