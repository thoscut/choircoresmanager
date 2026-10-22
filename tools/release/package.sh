#!/usr/bin/env bash
# Builds the release archive for the Nextcloud app store:
#   tools/release/package.sh [--sign KEY CERT OCC]
#
# The archive contains only what the app needs at runtime (appinfo, lib,
# templates, img, js, css, l10n, LICENSE, README, CHANGELOG). Tests, sources,
# tooling (including tools/screenshots), the documentation with its
# screenshots (docs/), the GitHub templates (.github/), internal documents
# and dependencies for development stay out.
#
# With --sign (KEY = private key of the app store certificate request, CERT =
# the certificate issued by Nextcloud, OCC = path to `occ` of any Nextcloud
# instance), the staged app is code signed first (`occ integrity:sign-app`
# writes appinfo/signature.json into the archive), and the SHA-512 signature
# of the archive for the app store upload form is printed.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

APP_ID=notenarchiv
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
SIGN=0
if [ "${1:-}" = "--sign" ]; then
	SIGN=1
	KEY="${2:?path to the private key}"
	CERT="${3:?path to the certificate}"
	OCC="${4:?path to occ of a Nextcloud instance}"
fi

# appinfo/info.xml and package.json must name the same version.
PACKAGE_VERSION=$(sed -n 's/^  "version": "\(.*\)",$/\1/p' package.json)
if [ -z "$VERSION" ] || [ "$VERSION" != "$PACKAGE_VERSION" ]; then
	echo "version mismatch: appinfo/info.xml has '$VERSION', package.json has '$PACKAGE_VERSION'" >&2
	exit 1
fi

# The app store refuses releases whose info.xml has no issue tracker.
if ! grep -q '<bugs>' appinfo/info.xml; then
	echo "appinfo/info.xml has no <bugs> URL; the app store refuses such releases" >&2
	exit 1
fi

# It also refuses an info.xml that does not match its schema (e.g. elements
# in the wrong order). Needs xmllint and network access.
if command -v xmllint >/dev/null; then
	XSD=$(mktemp)
	curl -fsSL -o "$XSD" https://apps.nextcloud.com/schema/apps/info.xsd
	xmllint --noout --schema "$XSD" appinfo/info.xml || { rm -f "$XSD"; exit 1; }
	rm -f "$XSD"
else
	echo "warning: xmllint not found, appinfo/info.xml was not checked against the app store schema" >&2
fi

OUT="$ROOT/build/release"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

npm ci --no-audit --no-fund >/dev/null
rm -rf js css
npm run build >/dev/null
npm run l10n >/dev/null

mkdir -p "$STAGE/$APP_ID" "$OUT"
cp -r appinfo lib templates img js css l10n LICENSE README.md CHANGELOG.md "$STAGE/$APP_ID/"
# Source maps are not needed at runtime; the license files of bundled
# third-party code (*.license) stay.
find "$STAGE/$APP_ID/js" -name '*.map' -delete

# Refuse to package anything internal or development-only.
for forbidden in _intern node_modules tests src tools vendor-bin docs .github; do
	if [ -e "$STAGE/$APP_ID/$forbidden" ]; then
		echo "refusing to package $forbidden" >&2
		exit 1
	fi
done

if [ "$SIGN" = 1 ]; then
	php "$OCC" integrity:sign-app --privateKey="$KEY" --certificate="$CERT" --path="$STAGE/$APP_ID"
fi

ARCHIVE="$OUT/$APP_ID-$VERSION.tar.gz"
tar -C "$STAGE" -czf "$ARCHIVE" "$APP_ID"
echo "archive: $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"
sha256sum "$ARCHIVE"

if [ "$SIGN" = 1 ]; then
	echo "signature (paste into the app store upload form):"
	openssl dgst -sha512 -sign "$KEY" "$ARCHIVE" | openssl base64
fi
