<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Utility;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDownloadResponse;

class ResponseHelper {
	/**
	 * Build a MusicXML download response that browsers never interpret as a document.
	 *
	 * @param array{content: string, mimeType: string, fileName: string} $file
	 * @return DataDownloadResponse<Http::STATUS_OK, string, array{}>
	 */
	public static function musicXmlDownload(array $file): DataDownloadResponse {
		$response = new DataDownloadResponse($file['content'], $file['fileName'], $file['mimeType']);
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->cacheFor(0);
		return $response;
	}

	/**
	 * Content security policy for pages embedding the MusicXML player.
	 *
	 * The notation renderer is a WebAssembly module and the audio clock runs in a blob worker.
	 */
	public static function playerContentSecurityPolicy(): ContentSecurityPolicy {
		$csp = new ContentSecurityPolicy();
		$csp->allowEvalWasm(true);
		$csp->addAllowedWorkerSrcDomain('blob:');
		return $csp;
	}
}
