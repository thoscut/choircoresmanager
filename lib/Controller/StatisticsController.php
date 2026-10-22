<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\UserFacingException;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\StatisticsCsv;
use OCA\Notenarchiv\Service\StatisticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Statistics and the annual report (see docs/api.md). Every archive role may
 * read them; they contain no personal data of choir members and no account
 * names. Each call aggregates the whole archive, so the calls are limited per
 * user.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class StatisticsController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private StatisticsService $statistics,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/statistics')]
	public function overview(?int $from = null, ?int $to = null): JSONResponse {
		return $this->run(function (string $uid) use ($from, $to) {
			$this->roles->requireAccess($uid);
			return $this->statistics->overview($from, $to, $uid);
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/statistics/report')]
	public function report(?int $year = null): JSONResponse {
		return $this->run(function (string $uid) use ($year) {
			$this->roles->requireAccess($uid);
			return $this->statistics->report($year, $uid);
		});
	}

	/**
	 * The tables of the statistics as CSV (plain download link).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/statistics/csv')]
	public function csv(?int $from = null, ?int $to = null): Response {
		return $this->download(function (string $uid) use ($from, $to): array {
			$data = $this->statistics->overview($from, $to, $uid);
			return [StatisticsCsv::overview($data, $this->l),
				'statistics-' . $data['period']['from'] . '-' . $data['period']['to'] . '.csv'];
		});
	}

	/**
	 * The tables of the annual report as CSV (plain download link).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/statistics/report/csv')]
	public function reportCsv(?int $year = null): Response {
		return $this->download(function (string $uid) use ($year): array {
			$data = $this->statistics->report($year, $uid);
			return [StatisticsCsv::report($data, $this->l), 'annual-report-' . $data['year'] . '.csv'];
		});
	}

	/**
	 * A CSV download for a user with access, or the error as JSON.
	 *
	 * @param callable(string): array{0: string, 1: string} $build CSV and file name
	 */
	private function download(callable $build): Response {
		if ($this->userId === null || !$this->roles->hasAccess($this->userId)) {
			return new JSONResponse(['error' => $this->l->t('You have no access to the music archive.')], Http::STATUS_FORBIDDEN);
		}
		try {
			[$csv, $name] = $build($this->userId);
		} catch (UserFacingException $e) {
			$status = $e instanceof AccessDeniedException ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
			return new JSONResponse(['error' => $this->translate($e->getMessage(), $e->getParameters())], $status);
		}
		$response = new DataDownloadResponse($csv, $name, 'text/csv; charset=utf-8');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}
}
