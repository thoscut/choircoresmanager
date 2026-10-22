# Releasing

How a version of the app gets into the Nextcloud app store. The app store
only accepts signed releases from a public repository.

## Once

1. **Public repository** with the app's code (the app store certificate
   request links to it):
   [github.com/thoscut/choircoresmanager](https://github.com/thoscut/choircoresmanager).
   `appinfo/info.xml` names it in `<website>`, `<bugs>` (issue tracker,
   required; the packaging script refuses to build without it),
   `<repository>` and `<documentation>`. The `<screenshot>` entries point to
   `docs/screenshots/` on the `main` branch, so they only show once that
   branch holds this app.

   The repository started as a fork of the Orchestra Scores Manager (another
   app). Before the first push of this app, keep the old state on a branch
   (e.g. `orchestrascoresmanager`) and close or retarget its open pull
   requests; the first push then replaces `main`. The fork relation stays
   visible on GitHub until GitHub support detaches the fork.
2. **Key and certificate request.** Create a private key and a certificate
   signing request with the app id as common name, and request the certificate
   with a pull request to
   [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests):

   ```sh
   openssl req -nodes -newkey rsa:4096 -keyout notenarchiv.key -out notenarchiv.csr -subj "/CN=notenarchiv"
   ```

   Keep `notenarchiv.key` secret and outside the repository. Nextcloud
   returns the signed certificate (`notenarchiv.crt`).
3. **Register the app** on [apps.nextcloud.com](https://apps.nextcloud.com)
   (account → register app) with the certificate and the signature of the app
   id:

   ```sh
   echo -n "notenarchiv" | openssl dgst -sha512 -sign notenarchiv.key | openssl base64
   ```

## Every release

1. Raise `<version>` in `appinfo/info.xml` (semantic versioning; a schema
   change or a new route always needs a new version, otherwise Nextcloud does
   not run the migration or reload the routes), and the same version in
   `package.json` and `package-lock.json`; `tools/release/package.sh` stops
   when `info.xml` and `package.json` differ. Check the supported Nextcloud
   range in `<dependencies>`.
2. Move the entries under `[Unreleased]` in `CHANGELOG.md` to the new version
   with its date.
3. Run the checks: `composer test:unit`, `composer psalm`,
   `composer cs:check`, `npm run lint`, `npm run stylelint`, `npm test`,
   `npm run typecheck`, `npm run l10n` (fails on
   missing translations), and the integration tests in `tests/Integration`
   against the oldest and the newest supported Nextcloud. The CI workflow
   (`.github/workflows/ci.yml`) runs all of them on every push and pull
   request, the integration tests with the demo data on fresh Nextcloud 33
   and 35 instances (`tools/ci/integration-demo.sh`, see
   [tests/README.md](../tests/README.md)); release only from a commit whose
   CI run is green. When the supported range in `<dependencies>` changes,
   change the Nextcloud versions of the `integration` job as well.
4. Tag the version in the repository.
5. Build and sign on a machine with Node.js, PHP and a Nextcloud instance
   (for `occ integrity:sign-app`):

   ```sh
   tools/release/package.sh --sign /path/notenarchiv.key /path/notenarchiv.crt /path/to/nextcloud/occ
   ```

   The script builds the frontend and the translations, copies only the
   runtime files, refuses to package internal or development files, writes
   the code signature (`appinfo/signature.json`) into the archive and prints
   the archive signature.
6. Attach `build/release/notenarchiv-<version>.tar.gz` to the release in the
   repository (the app store downloads it from a public HTTPS URL) and upload
   that URL with the printed signature on apps.nextcloud.com (account → upload
   app release). Mark pre-releases as nightly or with a pre-release version
   such as `1.0.0-rc.1`.
7. After the app store has published the release, update a test instance
   from the app store and check the update path (migrations, background
   jobs, settings kept).

Any change to a file after signing invalidates the code signature; build and
sign again instead of editing the archive.
