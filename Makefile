# Builds the archive uploaded to the Nextcloud App Store.
#   make appstore     -> build/artifacts/appstore/fulltextsearch_pgsql.tar.gz
#   make sign KEY=~/.nextcloud/certificates/fulltextsearch_pgsql.key
#                     -> prints the signature to paste into the App Store upload form

app_name := fulltextsearch_pgsql
build_dir := build
artifact := $(build_dir)/artifacts/appstore/$(app_name).tar.gz
# Only what Nextcloud needs at runtime. Tests, CI, dev tooling and vendor/ stay out:
# Nextcloud autoloads lib/ from the namespace in appinfo/info.xml. The bundled PDF parser
# is in lib/Vendor, namespaced by scripts/scope-pdfparser.php (see the `scope` target).
release_files := appinfo lib templates js css img docs LICENSE README.md CHANGELOG.md

.PHONY: appstore scope sign clean

# Rebuilds lib/Vendor from vendor/smalot/pdfparser, installing it first if needed.
scope:
	@test -d vendor/smalot/pdfparser || composer install --no-dev --no-interaction --no-progress --no-scripts
	php scripts/scope-pdfparser.php

appstore: clean scope
	mkdir -p $(build_dir)/source/$(app_name) $(dir $(artifact))
	cp -r $(release_files) $(build_dir)/source/$(app_name)/
	@test -f $(build_dir)/source/$(app_name)/lib/Vendor/Smalot/PdfParser/Parser.php \
		|| { echo "lib/Vendor is missing: the archive would ship without the PDF fallback"; exit 1; }
	tar -czf $(artifact) -C $(build_dir)/source --sort=name --owner=0 --group=0 --numeric-owner \
		--mtime="$$(git log -1 --format=%cI 2>/dev/null || date -u +%FT%TZ)" $(app_name)
	@echo "Built $(artifact)"

sign: appstore
	@test -n "$(KEY)" || { echo "Usage: make sign KEY=/path/to/$(app_name).key"; exit 1; }
	@openssl dgst -sha512 -sign "$(KEY)" $(artifact) | openssl base64 -A; echo

clean:
	rm -rf $(build_dir)
