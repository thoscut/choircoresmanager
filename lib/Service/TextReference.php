<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCP\IL10N;

/**
 * Text reference to a place in a score, e.g.
 * "5-1 · Freude, schöner Götterfunken · p. 2 · m. 17–24 · Tenor II · note".
 * It contains no notation and may always be passed on (ROADMAP, milestone
 * 0.3). The same rules as the UI (src/references.ts): empty parts are left
 * out, white space is collapsed, one measure is named once.
 *
 * @psalm-type Place = array{page: ?int, measure_from: ?string, measure_to: ?string, voice: ?string, verse: ?int, note: ?string}
 */
final class TextReference {
	public const SEPARATOR = ' · ';
	/** Printed measure numbers as in the score, e.g. "0" (upbeat), "17", "34X" */
	public const MEASURE_PATTERN = '/^[0-9A-Za-z.]{1,16}$/';
	private const MAX_VOICE = 100;
	private const MAX_NOTE = 500;

	/**
	 * Checks the parts of a place given by a client.
	 *
	 * @return Place
	 * @throws InvalidInputException
	 */
	public static function place(?int $page, ?string $measureFrom, ?string $measureTo, ?string $voice, ?int $verse, ?string $note): array {
		foreach (['Page' => $page, 'Verse' => $verse] as $label => $number) {
			if ($number !== null && $number < 1) {
				throw new InvalidInputException('"%s" must be a whole number.', [$label]);
			}
		}
		$measures = [];
		foreach ([$measureFrom, $measureTo] as $measure) {
			$measure = $measure === null ? '' : trim($measure);
			if ($measure !== '' && preg_match(self::MEASURE_PATTERN, $measure) !== 1) {
				throw new InvalidInputException('Measure numbers may only contain digits, letters and dots.');
			}
			$measures[] = $measure === '' ? null : $measure;
		}
		if ($voice !== null && mb_strlen($voice) > self::MAX_VOICE) {
			throw new InvalidInputException('"%s" is too long.', ['Voice']);
		}
		if ($note !== null && mb_strlen($note) > self::MAX_NOTE) {
			throw new InvalidInputException('"%s" is too long.', ['Note']);
		}
		return ['page' => $page, 'measure_from' => $measures[0], 'measure_to' => $measures[1], 'voice' => $voice, 'verse' => $verse, 'note' => $note];
	}

	/**
	 * The complete text reference in the language of $l.
	 *
	 * @param Place $place
	 */
	public static function text(string $shelfmark, ?string $title, array $place, IL10N $l): string {
		$parts = [self::clean($shelfmark), self::clean($title), self::placeText($place, $l), self::clean($place['note'])];
		return implode(self::SEPARATOR, array_values(array_filter($parts, fn (string $part): bool => $part !== '')));
	}

	/**
	 * Where in the score: page, measures, voice, verse.
	 *
	 * @param Place $place
	 */
	public static function placeText(array $place, IL10N $l): string {
		$parts = [];
		if ($place['page'] !== null) {
			$parts[] = $l->t('p. %s', [(string)$place['page']]);
		}
		$first = self::clean($place['measure_from']);
		$last = self::clean($place['measure_to']);
		if ($first !== '' || $last !== '') {
			$parts[] = $first === '' || $last === '' || $first === $last
				? $l->t('m. %s', [$first !== '' ? $first : $last])
				: $l->t('m. %1$s–%2$s', [$first, $last]);
		}
		$voice = self::clean($place['voice']);
		if ($voice !== '') {
			$parts[] = $voice;
		}
		if ($place['verse'] !== null) {
			$parts[] = $l->t('verse %s', [(string)$place['verse']]);
		}
		return implode(self::SEPARATOR, $parts);
	}

	private static function clean(?string $text): string {
		return trim((string)preg_replace('/\s+/u', ' ', $text ?? ''));
	}
}
