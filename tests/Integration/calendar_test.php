<?php

declare(strict_types=1);

/**
 * Integration test of concerts in the calendar and of the dashboard widget
 * against a real Nextcloud instance with the dav app (calendars).
 *
 * Usage (as the web server user):
 *   php tests/Integration/calendar_test.php <nextcloud-root> <uid>
 *
 * <uid> must hold the role "librarian" (plans concerts). The test creates a
 * calendar of its own for <uid>, adds a concert with its deadlines through
 * Nextcloud's calendar interface (OCP\Calendar\IManager, ICreateFromString),
 * adds it again after a change and after the cancellation (updates, no
 * duplicates) and checks the calendar files with Nextcloud's iCalendar
 * parser. Everything happens inside a database transaction that is rolled
 * back; the calendar is also deleted explicitly before.
 */

require __DIR__ . '/harness.php';
[$root, $uid] = IntegrationTest::start($argv, ['nextcloud-root', 'uid']);

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Notenarchiv\Dashboard\DueWidget;
use OCA\Notenarchiv\Service\ConcertCalendarService;
use OCA\Notenarchiv\Service\DueService;
use OCA\Notenarchiv\Service\EventService;
use OCA\Notenarchiv\Service\RightsService;
use OCP\IDBConnection;
use OCP\Server;
use Sabre\VObject\Reader;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

/**
 * Problems Nextcloud's iCalendar library finds in a calendar file: errors
 * (level 3) and warnings (level 2); repaired trifles (level 1) are fine.
 *
 * @return list<string>
 */
$problems = function (string $ics): array {
	$calendar = Reader::read($ics);
	$messages = [];
	foreach ($calendar->validate() as $message) {
		if ($message['level'] >= 2) {
			$messages[] = $message['level'] . ': ' . $message['message'];
		}
	}
	return $messages;
};

/**
 * Whether a calendar file keeps the form of RFC 5545: CRLF only, no line
 * longer than 75 octets, no character split by folding.
 */
$rfcForm = function (string $ics): bool {
	if (preg_match('/(?<!\r)\n/', $ics) === 1 || !str_ends_with($ics, "\r\n")) {
		return false;
	}
	foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
		if (strlen($line) > 75 || !mb_check_encoding($line, 'UTF-8')) {
			return false;
		}
	}
	return true;
};

$db = Server::get(IDBConnection::class);
$backend = Server::get(CalDavBackend::class);
$events = Server::get(EventService::class);
$calendar = Server::get(ConcertCalendarService::class);
$today = Server::get(RightsService::class)->today($uid);
$principal = 'principals/users/' . $uid;
$calendarUri = 'notenarchiv-test-' . bin2hex(random_bytes(4));
$calendarId = null;

$db->beginTransaction();
try {
	// A calendar of the test account, created for this test only
	$calendarId = (int)$backend->createCalendar($principal, $calendarUri, ['{DAV:}displayname' => 'Notenarchiv Test Ä']);
	$offered = array_column($calendar->writableCalendars($uid), 'name', 'uri');
	$check('the new calendar is offered', ($offered[$calendarUri] ?? null) === 'Notenarchiv Test Ä', json_encode($offered));
	$check('an account without a role gets no calendars',
		$throws(fn () => $calendar->writableCalendars('notenarchiv-no-such-account')) !== null);

	// A concert in 50 days (deadline stage "8 weeks"), with a time
	$date = (new DateTimeImmutable($today))->modify('+50 days')->format('Y-m-d');
	$concert = $events->create('concert', ['title' => 'Prüfkonzert; Händel, Bach', 'event_date' => $date,
		'start_time' => '19:30', 'end_time' => '21:00', 'location' => 'St. Marien'], $uid);
	$check('the time zone of the account is recorded', $concert->getTimeZone() !== null, (string)$concert->getTimeZone());
	$check('an end before the start is refused',
		$throws(fn () => $events->update($concert->getId(), ['end_time' => '18:00'], $uid)) === 'The end time must be after the start time.');
	$check('a time needs the HH:MM form',
		$throws(fn () => $events->update($concert->getId(), ['start_time' => '7 pm'], $uid)) === '"Start time" needs a valid time (HH:MM).');

	// Calendar file
	$file = $calendar->concertFile($concert->getId(), true, $uid);
	$check('calendar file in RFC 5545 form', $rfcForm($file['content']));
	$check('calendar file valid for Nextcloud\'s iCalendar parser', $problems($file['content']) === [], implode('; ', $problems($file['content'])));
	$check('calendar file has the concert with time zone and three deadlines',
		substr_count($file['content'], "\r\nBEGIN:VEVENT\r\n") === 4 && str_contains($file['content'], "\r\nBEGIN:VTIMEZONE\r\n")
		&& preg_match('/\r\nDTSTART;TZID=[^:]+:' . str_replace('-', '', $date) . 'T193000\r\n/', $file['content']) === 1);
	$upcoming = $calendar->upcomingFile(false, $uid);
	$check('upcoming concerts contain the concert', str_contains($upcoming['content'], 'notenarchiv-concert-' . $concert->getId() . '-')
		&& $problems($upcoming['content']) === [], implode('; ', $problems($upcoming['content'])));

	// Add to the calendar: the concert and its three deadlines
	$first = $calendar->add($concert->getId(), $calendarUri, true, $uid);
	$check('concert and deadlines added', $first['created'] === 4 && $first['updates'] === [] && $first['failed'] === 0, json_encode($first));
	$objects = $backend->getCalendarObjects($calendarId);
	$check('four calendar objects', count($objects) === 4, (string)count($objects));
	$main = null;
	foreach ($objects as $object) {
		$data = $backend->getCalendarObject($calendarId, $object['uri']);
		if (is_array($data) && str_contains((string)$data['calendardata'], 'T193000')) {
			$main = $data;
		}
	}
	$check('the concert entry is stored with its time', $main !== null);

	// Changed and added again: four updates, no new object
	$events->update($concert->getId(), ['location' => 'Dom zu Lübeck'], $uid);
	$again = $calendar->add($concert->getId(), $calendarUri, true, $uid);
	$check('adding again creates nothing', $again['created'] === 0 && count($again['updates']) === 4, json_encode(['created' => $again['created'], 'updates' => count($again['updates'])]));
	$check('still four calendar objects', count($backend->getCalendarObjects($calendarId)) === 4);
	$update = $again['updates'][0] ?? null;
	$check('an update names the CalDAV path of the existing object',
		$update !== null && str_starts_with($update['path'], 'calendars/' . rawurlencode($uid) . '/' . rawurlencode($calendarUri) . '/'), (string)($update['path'] ?? ''));
	$check('an update carries the new content', $update !== null && str_contains($update['ics'], 'LOCATION:Dom zu Lübeck')
		&& $problems($update['ics']) === []);

	// What the browser does with an update (CalDAV PUT), here directly in the backend
	if ($update !== null) {
		$objectUri = rawurldecode(basename($update['path']));
		$backend->updateCalendarObject($calendarId, $objectUri, $update['ics']);
		$stored = $backend->getCalendarObject($calendarId, $objectUri);
		$check('the update is stored under the same object', is_array($stored) && str_contains((string)$stored['calendardata'], 'Dom zu Lübeck'));
	}

	// Cancelled: adding again updates the entries to "cancelled"
	$events->cancel($concert->getId(), $uid);
	$cancelled = $calendar->add($concert->getId(), $calendarUri, true, $uid);
	$cancelledIcs = array_column($cancelled['updates'], 'ics');
	$check('a cancelled concert is updated, not added', $cancelled['created'] === 0 && count($cancelledIcs) === 4
		&& count(array_filter($cancelledIcs, fn (string $ics): bool => str_contains($ics, "\r\nSTATUS:CANCELLED\r\n"))) === 4);
	$check('a cancelled concert cannot be changed', $throws(fn () => $events->update($concert->getId(), ['title' => 'x'], $uid)) !== null);

	// A cancelled concert that is in no calendar is not added
	$other = $events->create('concert', ['title' => 'Abgesagt', 'event_date' => $date], $uid);
	$events->cancel($other->getId(), $uid);
	$skipped = $calendar->add($other->getId(), $calendarUri, false, $uid);
	$check('a cancelled concert is not added', $skipped['created'] === 0 && $skipped['skipped'] === 1, json_encode($skipped));

	// Reopened: start page and dashboard widget show the deadline again
	$events->reopen($concert->getId(), $uid);
	$due = Server::get(DueService::class)->forUser($uid)['concerts'];
	$check('the start page lists the concert with its deadline',
		in_array(['id' => $concert->getId(), 'stage' => '8'], array_map(fn (array $c): array => ['id' => $c['id'], 'stage' => $c['stage']], $due), true), json_encode($due));
	$links = array_map(fn ($item): string => $item->getLink(), Server::get(DueWidget::class)->getItemsV2($uid, null, 50)->getItems());
	$check('the dashboard widget links to the concert',
		count(array_filter($links, fn (string $link): bool => str_ends_with($link, '?event=' . $concert->getId()))) === 1, implode(' ', $links));
} catch (\Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	if ($calendarId !== null) {
		try {
			$backend->deleteCalendar($calendarId, true);
		} catch (\Throwable $e) {
			IntegrationTest::unexpected($e);
		}
	}
	$db->rollBack();
}

IntegrationTest::finish();
