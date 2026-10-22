<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Attribute\RequiresModule;
use OCA\Notenarchiv\Db\TranscriptionReport;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\UserFacingException;
use OCA\Notenarchiv\Service\CorrectionService;
use OCA\Notenarchiv\Service\ReviewService;
use OCA\Notenarchiv\Service\ScanService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Reviewing transcriptions and small corrections (milestone 0.6, see
 * docs/api.md): reports, review points and their decisions, correction
 * orders, corrections saved as new files, and the scan beside the score.
 * The services check roles and modules themselves; the attributes switch the
 * routes off with their module.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class ReviewController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private ReviewService $review,
		private CorrectionService $corrections,
		private ScanService $scans,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresModule('transcription_review')]
	#[FrontpageRoute(verb: 'GET', url: '/api/versions/{id}/review', requirements: ['id' => '\d+'])]
	public function review(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$data = $this->review->forVersion($id, $uid);
			$data['reports'] = array_map(fn (TranscriptionReport $report): array => $this->reportData($report), $data['reports']);
			return $data;
		});
	}

	/**
	 * A report of a transcription for a copy of the version: the JSON text
	 * (`content`) or the object (`report`).
	 *
	 * @param array<array-key, mixed>|null $report
	 */
	#[NoAdminRequired]
	#[RequiresModule('transcription_review')]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/versions/{id}/transcription-reports', requirements: ['id' => '\d+'])]
	public function ingest(int $id, ?string $content = null, ?array $report = null): JSONResponse {
		return $this->run(function (string $uid) use ($id, $content, $report) {
			if (($content === null) === ($report === null)) {
				throw new InvalidInputException('Please send the report either as text or as an object.');
			}
			$raw = $content ?? json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			$result = $this->review->ingest($id, $raw, $uid);
			return ['report' => $this->reportData($result['report']), 'warnings' => $this->translateWarnings($result['warnings'])] + $result;
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('transcription_review')]
	#[FrontpageRoute(verb: 'POST', url: '/api/review-points/{id}/decision', requirements: ['id' => '\d+'])]
	public function decide(int $id, string $status = '', ?string $correction = null, ?string $reason = null, ?string $addressee = null): JSONResponse {
		return $this->run(fn (string $uid) => $this->review->decide($id, $status, $correction, $reason, $addressee, $uid));
	}

	#[NoAdminRequired]
	#[RequiresModule('transcription_review')]
	#[FrontpageRoute(verb: 'GET', url: '/api/review/orders')]
	public function orders(): JSONResponse {
		return $this->run(fn (string $uid) => $this->review->orders($uid));
	}

	/**
	 * @param list<mixed> $pointIds
	 */
	#[NoAdminRequired]
	#[RequiresModule('corrections')]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/versions/{id}/corrections', requirements: ['id' => '\d+'])]
	public function correct(int $id, int $fileId = 0, string $content = '', array $pointIds = [], ?string $reason = null): JSONResponse {
		return $this->run(fn (string $uid) => $this->corrections->correct($id, $fileId, $content, $pointIds, $reason, $uid));
	}

	/**
	 * A scan (PDF) of a copy for the frame beside the score or a second
	 * window. Opened by the browser itself, therefore without CSRF token;
	 * it only reads.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresModule('score_viewer')]
	#[UserRateLimit(limit: 120, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/files/{id}/scan', requirements: ['id' => '\d+'])]
	public function scan(int $id): Response {
		if ($this->userId === null) {
			return new JSONResponse(['error' => $this->l->t('Not logged in.')], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$scan = $this->scans->scan($id, $this->userId);
		} catch (UserFacingException $e) {
			$status = $e instanceof AccessDeniedException ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
			return new JSONResponse(['error' => $this->translate($e->getMessage(), $e->getParameters())], $status);
		}
		// Shown by the browser's own PDF viewer, in a frame of the app page or
		// a window of its own: inline, never sniffed as something else, and a
		// policy that allows the PDF viewer only (object-src for the viewer
		// plugin) and framing by the app itself. The file name is fixed (ASCII).
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

	/**
	 * A report row with the warnings of its summary translated.
	 *
	 * @return array<string, mixed>
	 */
	private function reportData(TranscriptionReport $report): array {
		$summary = $report->summaryData();
		$summary['warnings'] = array_map(fn (array $warning): string => $this->translate($warning[0], $warning[1]),
			$report->summaryWarnings());
		return ['summary' => $summary] + $report->jsonSerialize();
	}
}
