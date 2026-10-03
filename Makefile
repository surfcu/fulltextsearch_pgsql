# Builds the archive uploaded to the Nextcloud App Store.
#   make appstore     -> build/artifacts/appstore/fulltextsearch_pgsql.tar.gz
#   make sign KEY=~/.nextcloud/certificates/fulltextsearch_pgsql.key
#                     -> prints the signature to paste into the App Store upload form

app_name := fulltextsearch_pgsql
build_dir := build
artifact := $(build_dir)/artifacts/appstore/$(app_name).tar.gz
# Only what Nextcloud needs at runtime. Tests, CI, dev tooling and vendor/ stay out:
# Nextcloud autoloads lib/ from the namespace in appinfo/info.xml.
release_files := appinfo lib img docs LICENSE README.md CHANGELOG.md

.PHONY: appstore sign clean

appstore: clean
	mkdir -p $(build_dir)/source/$(app_name) $(dir $(artifact))
	cp -r $(release_files) $(build_dir)/source/$(app_name)/
	tar -czf $(artifact) -C $(build_dir)/source --sort=name --owner=0 --group=0 --numeric-owner \
		--mtime="$$(git log -1 --format=%cI 2>/dev/null || date -u +%FT%TZ)" $(app_name)
	@echo "Built $(artifact)"

sign: appstore
	@test -n "$(KEY)" || { echo "Usage: make sign KEY=/path/to/$(app_name).key"; exit 1; }
	@openssl dgst -sha512 -sign "$(KEY)" $(artifact) | openssl base64 -A; echo

clean:
	rm -rf $(build_dir)
