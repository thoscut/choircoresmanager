<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Notification;

use OCA\Notenarchiv\Notification\Notifier;
use OCA\Notenarchiv\Service\NotificationService;
use OCA\Notenarchiv\Tests\Unit\Support\ModuleSwitches;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Notifications carry no internal error texts and link only into the app;
 * those of a switched-off occasion are withdrawn when shown.
 */
#[CoversClass(Notifier::class)]
final class NotifierTest extends TestCase {
	use ModuleSwitches;

	private string $parsedSubject = '';
	private string $link = '';
	/** @var list<string> modules switched off */
	private array $off = [];

	/**
	 * @param array<string, mixed> $parameters
	 */
	private function prepare(string $subject, array $parameters): void {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $p = []): string => vsprintf('[' . $text . ']', $p));
		$l->method('n')->willReturnCallback(fn (string $one, string $many, int $count, array $p = []): string
			=> vsprintf('[' . str_replace('%n', (string)$count, $count === 1 ? $one : $many) . ']', $p));
		$factory = $this->createStub(IFactory::class);
		$factory->method('get')->willReturn($l);
		$url = $this->createStub(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/apps/notenarchiv/');
		$notification = $this->createStub(INotification::class);
		$notification->method('getApp')->willReturn('notenarchiv');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('setParsedSubject')->willReturnCallback(function (string $text) use ($notification): INotification {
			$this->parsedSubject = $text;
			return $notification;
		});
		$notification->method('setLink')->willReturnCallback(function (string $link) use ($notification): INotification {
			$this->link = $link;
			return $notification;
		});
		$notification->method('setIcon')->willReturnSelf();
		(new Notifier($factory, $url, $this->modulesWithout($this->off)))->prepare($notification, 'de');
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function exportFailures(): iterable {
		$generic = '[The automatic export of the music archive failed: [Internal error. Details are in the Nextcloud log.]]';
		yield 'internal error' => [[], $generic];
		yield 'raw message of an older notification' => [['message' => 'SQLSTATE[42S02]: Base table not found: oc_notarch_works'], $generic];
		yield 'message for users' => [['error' => 'No write permission for exports in the archive folder.', 'errorParameters' => '[]'],
			'[The automatic export of the music archive failed: [No write permission for exports in the archive folder.]]'];
		yield 'message with parameter' => [['error' => 'The archive folder contains a file named %s.', 'errorParameters' => '["_notenarchiv-app"]'],
			'[The automatic export of the music archive failed: [The archive folder contains a file named _notenarchiv-app.]]'];
		yield 'parameters missing' => [['error' => 'The archive folder contains a file named %s.'], $generic];
		yield 'parameters damaged' => [['error' => 'The archive folder contains a file named %s.', 'errorParameters' => '{'], $generic];
	}

	/**
	 * @param array<string, mixed> $parameters
	 */
	#[DataProvider('exportFailures')]
	public function testExportFailureShowsNoInternalDetails(array $parameters, string $expected): void {
		$this->prepare(NotificationService::EXPORT_FAILED, $parameters);
		self::assertSame($expected, $this->parsedSubject);
	}

	public function testASwitchedOffOccasionIsWithdrawn(): void {
		$this->off = ['notify_task_new'];
		$this->prepare(NotificationService::TASK_ANSWERED, ['shelfmark' => '5-1', 'title' => 'Ave']);
		self::assertStringContainsString('has been answered', $this->parsedSubject, 'other occasions are still shown');
		$this->expectException(AlreadyProcessedException::class);
		$this->prepare(NotificationService::TASK_NEW, ['shelfmark' => '5-1', 'title' => 'Ave']);
	}

	public function testANotificationOfASwitchedOffModuleIsWithdrawn(): void {
		$this->off = ['concerts'];
		$this->expectException(AlreadyProcessedException::class);
		$this->prepare(NotificationService::CONCERT_DEADLINE, ['eventId' => 3, 'title' => 'Advent', 'date' => '2026-12-06', 'weeks' => 8]);
	}

	public function testNoNotificationIsShownWithoutTheSwitchNotifications(): void {
		$this->off = ['notifications'];
		$this->expectException(AlreadyProcessedException::class);
		$this->prepare(NotificationService::EXPORT_FAILED, []);
	}

	public function testNewReviewPointsOpenTheReview(): void {
		$this->prepare(NotificationService::REVIEW_POINTS, ['count' => 3, 'shelfmark' => '5-1', 'title' => 'Ave', 'copyId' => 35]);
		self::assertSame('[5-1 Ave: 3 new review points of the transcription for your role]', $this->parsedSubject);
		self::assertSame('https://cloud.example.org/apps/notenarchiv/?copy=35&review=1', $this->link);
	}

	public function testACorrectionArrived(): void {
		$this->prepare(NotificationService::CORRECTION_RECEIVED, ['kind' => 'app', 'shelfmark' => '5-1', 'title' => 'Ave', 'copyId' => '35"><x']);
		self::assertSame('[5-1 Ave: a corrected MusicXML file was saved in the app.]', $this->parsedSubject);
		self::assertSame('https://cloud.example.org/apps/notenarchiv/?copy=35&review=1', $this->link);
		$this->prepare(NotificationService::CORRECTION_RECEIVED, ['kind' => 'report', 'shelfmark' => '5-1', 'title' => 'Ave', 'copyId' => 35]);
		self::assertSame('[5-1 Ave: a new transcription has done review points.]', $this->parsedSubject);
	}

	public function testReviewPointsOfASwitchedOffReviewAreWithdrawn(): void {
		$this->off = ['transcription_review'];
		$this->expectException(AlreadyProcessedException::class);
		$this->prepare(NotificationService::REVIEW_POINTS, ['count' => 1, 'shelfmark' => '5-1', 'title' => 'Ave', 'copyId' => 35]);
	}

	public function testLinksStayInsideTheApp(): void {
		$this->prepare(NotificationService::TASK_NEW, ['copyId' => '35 onclick=x', 'taskId' => 'javascript:alert(1)', 'shelfmark' => '5-1', 'title' => 'Ave']);
		self::assertSame('https://cloud.example.org/apps/notenarchiv/?copy=35&task=0', $this->link);
	}
}
