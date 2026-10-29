<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\Files\File;

/**
 * The answer with a PDF scan, for the browser's own PDF viewer: beside the
 * score in the archive (ReviewController::scan) and for links for choir
 * members and public links (MemberController::scan, PublicLinkController::scan).
 *
 * Inline, never sniffed as something else, a fixed ASCII file name, not
 * cached, and a policy that allows the PDF viewer only (object-src for the
 * viewer plugin) and framing by the app itself. Whoever sees the scan in a
 * browser can also save it; the headers do not and cannot prevent that.
 */
final class ScanResponse {
	public static function inline(File $scan): FileDisplayResponse {
		$response = new FileDisplayResponse($scan, Http::STATUS_OK, ['Content-Type' => 'application/pdf']);
		$response->addHeader('Content-Disposition', 'inline; filename="scan.pdf"');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$policy = new EmptyContentSecurityPolicy();
		$policy->addAllowedObjectDomain('\'self\'');
		$policy->addAllowedFrameAncestorDomain('\'self\'');
		$response->setContentSecurityPolicy($policy);
		$response->cacheFor(0, false, false);
		return $response;
	}
}
