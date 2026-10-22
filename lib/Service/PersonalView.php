<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\Config\IUserConfig;

/**
 * The personal view of an account: areas of the navigation and cards of the
 * start page it does not want to see (ROADMAP, guideline 12). Stored as a
 * user setting on the server, so that it follows the account to every
 * device. It only hides; what the account may do does not change.
 *
 * Entries are the names the UI uses (views of the navigation; card keys of
 * GET /api/due, `concerts` and `tasks` for the lists of the start page). The
 * server checks their form and number; unknown names hide nothing.
 */
class PersonalView {
	public const KEY = 'personal_view';
	public const MAX_ENTRIES = 50;
	private const NAME = '/^[a-z][A-Za-z0-9_]{0,39}$/';

	public function __construct(
		private IUserConfig $userConfig,
	) {
	}

	/**
	 * @return array{navigation: list<string>, cards: list<string>}
	 */
	public function get(string $uid): array {
		try {
			$value = $this->userConfig->getValueArray($uid, Application::APP_ID, self::KEY);
		} catch (\Throwable) {
			// A value of another type (e.g. set by hand) hides nothing.
			$value = [];
		}
		return [
			'navigation' => self::valid($value['navigation'] ?? []),
			'cards' => self::valid($value['cards'] ?? []),
		];
	}

	/**
	 * Replaces the personal view of an account.
	 *
	 * @param array<array-key, mixed> $navigation names of hidden navigation areas (request data)
	 * @param array<array-key, mixed> $cards names of hidden cards of the start page (request data)
	 * @return array{navigation: list<string>, cards: list<string>}
	 * @throws InvalidInputException
	 */
	public function set(string $uid, array $navigation, array $cards): array {
		$view = ['navigation' => self::names($navigation, 'navigation'), 'cards' => self::names($cards, 'cards')];
		if ($view['navigation'] === [] && $view['cards'] === []) {
			$this->userConfig->deleteUserConfig($uid, Application::APP_ID, self::KEY);
		} else {
			$this->userConfig->setValueArray($uid, Application::APP_ID, self::KEY, $view);
		}
		return $view;
	}

	/**
	 * Names from the request: a list of distinct names of the expected form.
	 *
	 * @param array<array-key, mixed> $value
	 * @return list<string>
	 * @throws InvalidInputException
	 */
	private static function names(array $value, string $field): array {
		if (count($value) > self::MAX_ENTRIES) {
			throw new InvalidInputException('Too many entries at once (at most %s).', [(string)self::MAX_ENTRIES]);
		}
		$names = [];
		foreach (Input::stringList($value, $field) as $name) {
			if (preg_match(self::NAME, $name) !== 1) {
				throw new InvalidInputException('"%s": value not allowed.', [$field]);
			}
			$names[$name] = true;
		}
		return array_keys($names);
	}

	/**
	 * The valid names of a stored list; anything else is dropped.
	 *
	 * @return list<string>
	 */
	private static function valid(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}
		$names = [];
		foreach (array_filter($value, 'is_string') as $name) {
			if (preg_match(self::NAME, $name) === 1) {
				$names[$name] = true;
			}
		}
		return array_slice(array_map('strval', array_keys($names)), 0, self::MAX_ENTRIES);
	}
}
