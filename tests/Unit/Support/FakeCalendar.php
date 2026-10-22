<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Support;

use OCP\Calendar\Exceptions\CalendarException;
use OCP\Calendar\ICalendarIsEnabled;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Constants;

/**
 * A calendar of an account as Nextcloud's calendar manager returns it: it
 * keeps the created objects in memory, finds them by UID like the CalDAV
 * backend and refuses a second object with the same UID.
 */
final class FakeCalendar implements ICreateFromString, ICalendarIsWritable, ICalendarIsEnabled {
	/** @var array<string, string> object name => ICS */
	public array $objects = [];
	public bool $failOnCreate = false;

	public function __construct(
		private string $uri,
		private ?string $name = null,
		private bool $writable = true,
		private bool $enabled = true,
		private bool $deleted = false,
	) {
	}

	#[\Override]
	public function getKey(): string {
		return 'key-' . $this->uri;
	}

	#[\Override]
	public function getUri(): string {
		return $this->uri;
	}

	#[\Override]
	public function getDisplayName(): ?string {
		return $this->name;
	}

	#[\Override]
	public function getDisplayColor(): ?string {
		return '#0082c9';
	}

	#[\Override]
	public function search(string $pattern, array $searchProperties = [], array $options = [], ?int $limit = null, ?int $offset = null): array {
		$hits = [];
		foreach ($this->objects as $name => $ics) {
			if (isset($options['uid']) && preg_match('/\r\nUID:' . preg_quote((string)$options['uid'], '/') . '\r\n/', $ics) === 1) {
				$hits[] = ['id' => count($hits) + 1, 'type' => 'VEVENT', 'uid' => $options['uid'], 'uri' => $name, 'objects' => []];
			}
		}
		return array_slice($hits, 0, $limit);
	}

	#[\Override]
	public function getPermissions(): int {
		return $this->writable ? Constants::PERMISSION_ALL : Constants::PERMISSION_READ;
	}

	#[\Override]
	public function isDeleted(): bool {
		return $this->deleted;
	}

	#[\Override]
	public function isWritable(): bool {
		return $this->writable;
	}

	#[\Override]
	public function isEnabled(): bool {
		return $this->enabled;
	}

	#[\Override]
	public function createFromString(string $name, string $calendarData): void {
		if ($this->failOnCreate) {
			throw new CalendarException('Could not create new calendar event: test');
		}
		if (!str_ends_with($name, '.ics') || isset($this->objects[$name])) {
			throw new CalendarException('Could not create new calendar event: object exists');
		}
		preg_match('/\r\nUID:([^\r]+)\r\n/', $calendarData, $uid);
		if ($this->search('', [], ['uid' => $uid[1] ?? ''], 1) !== []) {
			throw new CalendarException('Could not create new calendar event: UID exists');
		}
		$this->objects[$name] = $calendarData;
	}

	#[\Override]
	public function createFromStringMinimal(string $name, string $calendarData): void {
		$this->createFromString($name, $calendarData);
	}
}
