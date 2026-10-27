<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Attribute\RequiresModule;
use OCA\Notenarchiv\Db\ReviewPoint;
use OCA\Notenarchiv\Db\TranscriptionReport;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\ResponseDefinitions;
use OCA\Notenarchiv\Service\ApiV1\Page;
use OCA\Notenarchiv\Service\ApiV1\ReviewView;
use OCA\Notenarchiv\Service\ApiV1\Values;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\ReviewService;
use OCA\Notenarchiv\Service\RightsPolicy;
use OCA\Notenarchiv\Service\Roles;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Review of transcriptions (API v1, see docs/api-v1.md): read the review of
 * a copy and the correction orders, hand in the report of a transcription
 * (format bericht/1). Deciding review points and saving corrections stay
 * with people in the app: the conductor and the catalog librarian decide,
 * the tool chain delivers a corrected MusicXML as a new file through the
 * file sync. Internal preservation copies are left out for roles that may
 * not view them, as in the other routes.
 *
 * @psalm-import-type NotenarchivReview from ResponseDefinitions
 * @psalm-import-type NotenarchivCorrectionOrderPage from ResponseDefinitions
 * @psalm-import-type NotenarchivReportResult from ResponseDefinitions
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::AGENT_API)]
#[RequiresModule(ReviewService::MODULE)]
class ApiV1ReviewController extends OCSController {
	use RunsOcsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private ReviewService $review,
		private CatalogService $catalog,
		private RightsPolicy $policy,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Review of the transcription of a copy
	 *
	 * The reports of transcriptions read for the copy, newest first and
	 * without the received text: the first one with "superseded_by" null
	 * gives the current review state (origin, review, reasoning). Then the
	 * number of review points per status and the review points themselves
	 * in pages, in the order they were created, each with its place in the
	 * score, the responsible role and its status. Deciding them stays with
	 * the conductor and the catalog librarian in the app.
	 *
	 * @param int $id ID of the copy
	 * @param 'open'|'confirmed'|'correct'|'corrected'|'question'|'dismissed'|null $status Only review points with this status
	 * @param 'conductor'|'librarian'|null $role Only review points of this responsible role
	 * @param int<1, 100> $limit Review points per page
	 * @param string|null $cursor The "next_cursor" of the page before; omit it for the first page
	 * @return DataResponse<Http::STATUS_OK, NotenarchivReview, array{}>
	 * @throws OCSBadRequestException The status, the role, the limit or the cursor is not valid
	 * @throws OCSForbiddenException The account has no role, or the copy is an internal preservation copy it may not view
	 * @throws OCSNotFoundException The copy does not exist, or the review of transcriptions or the interface for agents is switched off
	 *
	 * 200: Review of the copy returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/copies/{id}/review', requirements: ['id' => '\d+'])]
	public function review(int $id, ?string $status = null, ?string $role = null, int $limit = 25, ?string $cursor = null): DataResponse {
		return new DataResponse($this->ocs(function (string $uid) use ($id, $status, $role, $limit, $cursor): array {
			$this->roles->requireAccess($uid);
			$status = ReviewView::status($status);
			$role = ReviewView::role($role);
			['copy' => $copy, 'version' => $version] = $this->catalog->copyWithContext($id);
			$denial = $this->policy->viewDenial($version, $uid, $copy);
			if ($denial !== null) {
				throw new AccessDeniedException($denial);
			}
			$review = $this->review->forVersion($version->getId(), $uid);
			$reports = array_values(array_filter($review['reports'], fn (TranscriptionReport $r): bool => $r->getCopyId() === $copy->getId()));
			$points = array_values(array_filter($review['points'], fn (ReviewPoint $p): bool => $p->getCopyId() === $copy->getId()));
			$chosen = array_values(array_filter($points, fn (ReviewPoint $p): bool => ($status === null || $p->getStatus() === $status)
				&& ($role === null || $p->getRole() === $role)));
			$page = Page::of($chosen, fn (ReviewPoint $p): array => [$p->getId()], $cursor, $limit);
			return [
				'copy_id' => $copy->getId(),
				'shelfmark' => $copy->getShelfmark(),
				'version_id' => $version->getId(),
				'counts' => ReviewView::counts($points),
				'reports' => array_map(fn (TranscriptionReport $r): array => ReviewView::report($r, $uid, $this->translate(...)), $reports),
				'items' => array_map(fn (ReviewPoint $p): array => ReviewView::point($p, $uid), $page['items']),
				'total' => $page['total'],
				'next_cursor' => $page['next_cursor'],
			];
		}));
	}

	/**
	 * List correction orders
	 *
	 * The review points decided as "correct": what the tool chain has to
	 * change in the MusicXML ("correction"), oldest first, with copy, report
	 * base name, the MusicXML file and its checksum, and the place in the
	 * score. The tool chain delivers the corrected MusicXML as a new file
	 * through the file sync and closes an order with its next report (the
	 * order "id" in "erledigt"). Orders of internal preservation copies are
	 * left out for roles that may not view them.
	 *
	 * @param int|null $copy_id Only the orders of this copy
	 * @param int<1, 100> $limit Orders per page
	 * @param string|null $cursor The "next_cursor" of the page before; omit it for the first page
	 * @return DataResponse<Http::STATUS_OK, NotenarchivCorrectionOrderPage, array{}>
	 * @throws OCSBadRequestException The limit or the cursor is not valid
	 * @throws OCSForbiddenException The account has no role in the music archive
	 * @throws OCSNotFoundException The review of transcriptions or the interface for agents is switched off
	 *
	 * 200: Page of correction orders returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/correction_orders')]
	public function orders(?int $copy_id = null, int $limit = 25, ?string $cursor = null): DataResponse {
		return new DataResponse($this->ocs(function (string $uid) use ($copy_id, $limit, $cursor): array {
			$this->roles->requireAccess($uid);
			$preservation = $this->policy->mayViewPreservationCopies($uid);
			$rows = array_values(array_filter($this->review->orders($uid), fn (array $row): bool => ($preservation || ($row['preservation_copy'] ?? false) !== true)
				&& ($copy_id === null || Values::intOrNull($row['copy_id'] ?? null) === $copy_id)));
			$page = Page::of($rows, fn (array $row): array => [Values::int($row['id'] ?? null)], $cursor, $limit);
			return ['items' => array_map(fn (array $row): array => ReviewView::order($row, $uid), $page['items'])] + $page;
		}));
	}

	/**
	 * Hand in the report of a transcription
	 *
	 * The report (format bericht/1, as written by the transcription, e.g.
	 * bericht.json) of a transcription of this copy; its "signatur" must be
	 * the shelf mark of the copy. The app checks it strictly, keeps it
	 * unchanged as evidence and reads it once: uncertain readings, omitted
	 * marks, material findings and questions become review points for the
	 * conductor or the catalog librarian, rights and catalog fields become
	 * proposals; the catalog itself never changes. The same report again
	 * creates nothing (200, "unchanged": true). Allowed for the roles
	 * contributor and librarian.
	 *
	 * @param int $id ID of the copy the report is about
	 * @param string|null $content The report as JSON text (the content of bericht.json, at most 1 MB); send this or "report"
	 * @param array<string, mixed>|null $report The report as a JSON object, instead of "content"
	 * @return DataResponse<Http::STATUS_CREATED, NotenarchivReportResult, array{}>|DataResponse<Http::STATUS_OK, NotenarchivReportResult, array{}>
	 * @throws OCSBadRequestException The report is not valid, names another shelf mark, or comes both or neither as text and object
	 * @throws OCSForbiddenException The roles of the account may not hand in reports, or the copy is an internal preservation copy it may not view
	 * @throws OCSNotFoundException The copy does not exist, or the review of transcriptions or the interface for agents is switched off
	 *
	 * 201: Report read; review points and proposals created
	 * 200: The same report was read for this copy before; nothing created
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/copies/{id}/transcription_reports', requirements: ['id' => '\d+'])]
	public function submitReport(int $id, ?string $content = null, ?array $report = null): DataResponse {
		$result = $this->ocs(function (string $uid) use ($id, $content, $report): array {
			$this->roles->requireAccess($uid);
			if (($content === null) === ($report === null)) {
				throw new InvalidInputException('Please send the report either as text or as an object.');
			}
			['copy' => $copy, 'version' => $version] = $this->catalog->copyWithContext($id);
			$denial = $this->policy->viewDenial($version, $uid, $copy);
			if ($denial !== null) {
				throw new AccessDeniedException($denial);
			}
			$raw = $content ?? json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			return ReviewView::result($this->review->ingestForCopy($id, $raw, $uid), $uid, $this->translate(...));
		});
		if ($result['unchanged']) {
			return new DataResponse($result, Http::STATUS_OK);
		}
		return new DataResponse($result, Http::STATUS_CREATED);
	}
}
