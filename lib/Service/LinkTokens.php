<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ExcerptLink;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

/**
 * The tokens of excerpt links (milestone 0.11). The table holds the hash of
 * a token, by which the link is found, and the token encrypted with the
 * secret of the instance, so that the creator and the catalog librarian can
 * copy the link again. A copy of the table alone opens no link.
 */
class LinkTokens {
	/** Length of a token in an address */
	public const LENGTH = 32;

	public function __construct(
		private ICrypto $crypto,
		private ISecureRandom $random,
	) {
	}

	/**
	 * Gives a new link a new token; the link remembers it for the answer to
	 * its creator (ExcerptLink::issuedToken).
	 */
	public function issue(ExcerptLink $link): void {
		$token = $this->random->generate(self::LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
		$link->setToken(ExcerptLink::hashToken($token));
		$link->setTokenSecret($this->crypto->encrypt($token));
		$link->issue($token);
	}

	/**
	 * The token of a link; null when it is gone (restored from a backup) or
	 * cannot be read with the secret of this instance.
	 */
	public function reveal(ExcerptLink $link): ?string {
		$issued = $link->issuedToken();
		if ($issued !== null) {
			return $issued;
		}
		$secret = $link->getTokenSecret();
		if ($secret === null || $secret === '') {
			return null;
		}
		try {
			$token = $this->crypto->decrypt($secret);
		} catch (\Exception) {
			return null;
		}
		return ExcerptLink::hashToken($token) === $link->getToken() ? $token : null;
	}
}
