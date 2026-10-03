# Publishing to the Nextcloud App Store

## One-time setup

Do these on your own computer. The private key is the app's identity on the App Store: anyone with it can publish releases as this app, so never commit it or paste it anywhere but the GitHub secret below.

### 1. Create a signing key and certificate request

```bash
mkdir -p ~/.nextcloud/certificates && cd ~/.nextcloud/certificates
openssl req -nodes -newkey rsa:4096 -keyout fulltextsearch_pgsql.key -out fulltextsearch_pgsql.csr -subj "/CN=fulltextsearch_pgsql"
```

Back up `fulltextsearch_pgsql.key` somewhere safe. If it is lost, the certificate has to be revoked and reissued.

### 2. Ask Nextcloud to sign it

Open a pull request against [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests) adding `fulltextsearch_pgsql/fulltextsearch_pgsql.csr` with the contents of your `.csr` file. Mention the repository (https://github.com/surfcu/fulltextsearch_pgsql) in the description. Once a maintainer merges it, the signed certificate appears in that repository as `fulltextsearch_pgsql/fulltextsearch_pgsql.crt`. Save it next to your key as `~/.nextcloud/certificates/fulltextsearch_pgsql.crt`.

### 3. Register the app

Sign in at [apps.nextcloud.com](https://apps.nextcloud.com) (a GitHub login works), then create the app ID signature:

```bash
echo -n "fulltextsearch_pgsql" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/fulltextsearch_pgsql.key | openssl base64
```

Go to [Register a new app](https://apps.nextcloud.com/developer/apps/new) and paste the certificate (`.crt` contents) and that signature.

### 4. Let GitHub publish releases

Create an API token at [apps.nextcloud.com/account/token](https://apps.nextcloud.com/account/token). Then in GitHub, **Settings → Secrets and variables → Actions → New repository secret**, add:

| Secret | Value |
|---|---|
| `APP_PRIVATE_KEY` | the full contents of `fulltextsearch_pgsql.key`, including the `BEGIN`/`END` lines |
| `APPSTORE_TOKEN` | the API token |

## Every release

1. Set the new version in `appinfo/info.xml` and add a matching `## [x.y.z]` section to `CHANGELOG.md`. The App Store shows that section as the release notes.
2. Commit and push to `main`.
3. On GitHub, **Releases → Draft a new release**, create the tag `vx.y.z` (for example `v1.3.0`), and publish it. Mark it as a pre-release to upload it to the App Store as a nightly.

The [Release workflow](../.github/workflows/release.yml) then:

- runs the full test suite, including the live Nextcloud tests,
- checks the tag matches `info.xml` and the changelog,
- builds `fulltextsearch_pgsql.tar.gz` (`make appstore`) and attaches it to the GitHub release,
- signs it and uploads it to the App Store.

If a step fails, nothing reaches the App Store. Fix the problem, then delete and recreate the release.

## Uploading by hand

If you prefer not to give GitHub the key:

```bash
make sign KEY=~/.nextcloud/certificates/fulltextsearch_pgsql.key
```

This builds `build/artifacts/appstore/fulltextsearch_pgsql.tar.gz` and prints its signature. Attach the archive to a GitHub release, then on [Upload app release](https://apps.nextcloud.com/developer/apps/releases/new) enter the archive's download URL and the signature.

## Before the first release

- Supported Nextcloud versions come from `<nextcloud min-version max-version>` in `appinfo/info.xml`. Raise `max-version` only after the live tests pass on the new release (add it to the matrix in `.github/workflows/live.yml`).
- Screenshots are optional. To add one, put a PNG under 2 MiB in the repository and reference its `https://raw.githubusercontent.com/...` URL in a `<screenshot>` element after `<repository>` in `info.xml`.
