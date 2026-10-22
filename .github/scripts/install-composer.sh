#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Installs Composer into /usr/local/bin after checking the SHA-384 checksum of
# the installer against the published signature (getcomposer.org/download).
set -eu

expected="$(curl -fsSL https://composer.github.io/installer.sig)"
curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
actual="$(php -r 'echo hash_file("sha384", "/tmp/composer-setup.php");')"

if [ "$expected" != "$actual" ]; then
	echo "Composer installer checksum mismatch (expected $expected, got $actual)" >&2
	rm -f /tmp/composer-setup.php
	exit 1
fi

php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
rm -f /tmp/composer-setup.php
composer --version
