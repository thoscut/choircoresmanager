<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Support;

use OCP\Security\ICrypto;

/**
 * Encryption of the instance in memory: reversible, recognizable and
 * refusing what it did not encrypt, like Nextcloud's.
 */
final class FakeCrypto implements ICrypto {
	private const PREFIX = 'encrypted:';

	#[\Override]
	public function calculateHMAC(string $message, string $password = ''): string {
		return hash_hmac('sha256', $message, $password);
	}

	#[\Override]
	public function encrypt(string $plaintext, string $password = ''): string {
		return self::PREFIX . strrev($plaintext);
	}

	#[\Override]
	public function decrypt(string $authenticatedCiphertext, string $password = ''): string {
		if (!str_starts_with($authenticatedCiphertext, self::PREFIX)) {
			throw new \Exception('HMAC does not match.');
		}
		return strrev(substr($authenticatedCiphertext, strlen(self::PREFIX)));
	}
}
