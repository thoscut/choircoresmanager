<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\UserFacingException;
use OCA\Cantoria\Service\ConcertCalendarService;
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
 * Concerts in the calendar: calendar files (iCalendar) to download and
 * entries in the account's own Nextcloud calendars (see docs/api.md).
 *
 * @psalm-suppress UnusedClass
 */
#[RequiresModule('concerts')]
#[RequiresModule('calendar')]
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class CalendarController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private ConcertCalendarService $calendar,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Calendar file of one concert (plain download link, changes nothing).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/events/{id}/ics', requirements: ['id' => '\d+'])]
	public function concertFile(int $id, int $deadlines = 0): Response {
		return $this->download(fn (string $uid): array => $this->calendar->concertFile($id, $deadlines === 1, $uid));
	}

	/**
	 * Calendar file of all upcoming concerts (plain download link, changes
	 * nothing).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/events/upcoming/ics')]
	public function upcomingFile(int $deadlines = 0): Response {
		return $this->download(fn (string $uid): array => $this->calendar->upcomingFile($deadlines === 1, $uid));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/calendars')]
	public function calendars(): JSONResponse {
		return $this->run(fn (string $uid): array => $this->calendar->writableCalendars($uid));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/events/{id}/calendar', requirements: ['id' => '\d+'])]
	public function addConcert(int $id, string $calendar = '', bool $deadlines = false): JSONResponse {
		return $this->run(fn (string $uid): array => $this->calendar->add($id, $calendar, $deadlines, $uid));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/events/upcoming/calendar')]
	public function addUpcoming(string $calendar = '', bool $deadlines = false): JSONResponse {
		return $this->run(fn (string $uid): array => $this->calendar->add(null, $calendar, $deadlines, $uid));
	}

	/**
	 * @param callable(string): array{name: string, content: string} $file
	 */
	private function download(callable $file): Response {
		if ($this->userId === null) {
			return new JSONResponse(['error' => $this->l->t('Not logged in.')], Http::STATUS_UNAUTHORIZED);
		}
		try {
			['name' => $name, 'content' => $content] = $file($this->userId);
		} catch (UserFacingException $e) {
			$status = $e instanceof AccessDeniedException ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
			return new JSONResponse(['error' => $this->translate($e->getMessage(), $e->getParameters())], $status);
		}
		$response = new DataDownloadResponse($content, $name, 'text/calendar; charset=utf-8');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}
}
