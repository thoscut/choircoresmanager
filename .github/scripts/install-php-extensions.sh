#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Installs the PHP extensions that composer.json requires and the official
# php:*-cli images lack (intl, zip), so that Composer, Psalm and the tests see
# the same PHP as a Nextcloud server. Needs libicu-dev and libzip-dev.
set -eu

docker-php-ext-install -j"$(nproc)" intl zip >/dev/null
for extension in dom intl mbstring xmlreader zip; do
	php -m | grep -qix "$extension" || { echo "PHP extension $extension is missing" >&2; exit 1; }
done
