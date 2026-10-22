<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Exception\InvalidInputException;

/**
 * Cuts a MusicXML score (partwise) to a range of printed measures, so that
 * choir members and the public receive only the measures of a link, never
 * the whole file (milestone 0.7).
 *
 * - The range is found in the first part by the printed measure numbers, as
 *   in the score view (src/score/range.ts): "07" equals "7"; the first
 *   measure is the first one with its number, the last measure the first one
 *   with its number from there on. Every part keeps the measures at the same
 *   positions.
 * - The first kept measure of each part gets the attributes valid there
 *   (divisions, key, time, staves, part symbol, instruments, clef, staff
 *   details, transposition) and the last tempo marking before it, unless it
 *   has its own.
 * - Repeats, endings and jumps that lead out of the excerpt are removed, so
 *   that the playing order stays inside it. The targets follow the
 *   conventions of the playback (src/score/playOrder.ts): a backward repeat
 *   goes back to the last forward repeat, else to the measure after the
 *   previous backward repeat or bracket group, else to the beginning.
 *   - A backward repeat whose target lies before the excerpt is removed; a
 *     forward repeat whose backward repeat lies behind the excerpt (or is
 *     missing) as well. Where a kept backward repeat would go back to
 *     another measure in the excerpt than in the whole score (its section
 *     started after a removed repeat), a forward repeat is added there.
 *   - The endings of a bracket group are removed when the group starts
 *     before the excerpt, one of its endings in the excerpt reaches beyond
 *     it, or the backward repeat of one of its endings was removed.
 *   - D.C. is removed unless the excerpt starts with the first measure, D.S.
 *     unless its segno lies in the excerpt before it, "To Coda" unless its
 *     coda lies in the excerpt after it; a direction that carries such a
 *     jump goes with it (its text belongs to the jump).
 * - Everything outside the measures stays: title, credits, rights notice,
 *   part list. The document type declaration is dropped; the result is
 *   UTF-8.
 *
 * Pure functions on DOMDocument, no server needed.
 *
 * @psalm-type Marks = array{forward: list<\DOMElement>, backward: list<\DOMElement>, endingStart: bool, endingStop: bool, endings: list<\DOMElement>, sounds: list<\DOMElement>, segno: ?string, coda: ?string}
 * @psalm-type Span = array{start: int, end: int}
 */
final class MusicXmlExcerpt {
	/** Children of <attributes> that stay valid until changed */
	private const CARRIED = ['divisions', 'key', 'time', 'staves', 'part-symbol', 'instruments', 'clef', 'staff-details', 'transpose'];
	/** Carried elements that apply to every staff when they have no `number` */
	private const ALL_STAVES = ['key', 'time', 'transpose'];
	/** Children of <attributes> in the order of the MusicXML schema */
	private const ATTRIBUTE_ORDER = ['footnote', 'level', 'divisions', 'key', 'time', 'staves', 'part-symbol', 'instruments', 'clef',
		'staff-details', 'transpose', 'for-part', 'directive', 'measure-style'];
	/** Jumps of a <sound> */
	private const JUMPS = ['dacapo', 'dalsegno', 'tocoda'];
	/** Bar styles that belong to a repeat sign */
	private const REPEAT_STYLES = ['heavy-light', 'light-heavy'];

	/**
	 * The score with only the measures from–to (printed numbers).
	 *
	 * @return string MusicXML (UTF-8)
	 * @throws InvalidInputException when the text is no partwise MusicXML score or a measure is missing
	 */
	public static function cut(string $xml, string $from, string $to): string {
		[$document, $root] = self::load($xml);
		$parts = self::children($root, 'part');
		[$start, $end] = self::range($parts === [] ? [] : self::children($parts[0], 'measure'), trim($from), trim($to));
		foreach ($parts as $part) {
			$measures = self::children($part, 'measure');
			$last = min($end, count($measures) - 1);
			if ($start <= $last) {
				self::removeWaysOut($document, $measures, $start, $last);
				$attributes = self::carryAttributes($document, $measures, $start);
				self::carryTempo($document, $measures, $start, $attributes);
			}
			foreach ($measures as $index => $measure) {
				if ($index < $start || $index > $last) {
					$part->removeChild($measure);
				}
			}
		}
		if ($document->doctype !== null) {
			$document->removeChild($document->doctype);
		}
		$document->encoding = 'UTF-8';
		$result = $document->saveXML();
		if ($result === false) {
			throw new \RuntimeException('The excerpt could not be written');
		}
		return $result;
	}

	/**
	 * Whether a printed measure number is the one asked for ("07" = "7").
	 */
	private static function sameNumber(string $printed, string $wanted): bool {
		$a = trim($printed);
		$b = trim($wanted);
		if ($a === $b) {
			return true;
		}
		return preg_match('/^\d+$/', $a) === 1 && preg_match('/^\d+$/', $b) === 1 && ltrim($a, '0') === ltrim($b, '0');
	}

	/**
	 * @return array{0: \DOMDocument, 1: \DOMElement}
	 * @throws InvalidInputException
	 */
	private static function load(string $xml): array {
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try {
			// No LIBXML_NOENT and no DTD loading: entities are not expanded and
			// nothing external is fetched.
			$loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_BIGLINES);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		$root = $document->documentElement;
		$doctype = $document->doctype;
		if (!$loaded || $root === null || $root->namespaceURI !== null
			|| ($doctype !== null && trim((string)$doctype->internalSubset) !== '')) {
			throw new InvalidInputException('This file is not a MusicXML score.');
		}
		if ($root->nodeName === 'score-timewise') {
			throw new InvalidInputException('Links for choir members and public links need a score in the partwise MusicXML format.');
		}
		if ($root->nodeName !== 'score-partwise') {
			throw new InvalidInputException('This file is not a MusicXML score.');
		}
		return [$document, $root];
	}

	/**
	 * Indices of the first and the last measure, as the score view resolves
	 * a range (src/score/range.ts).
	 *
	 * @param list<\DOMElement> $measures measures of the first part
	 * @return array{0: int, 1: int}
	 * @throws InvalidInputException
	 */
	private static function range(array $measures, string $from, string $to): array {
		$start = self::find($measures, $from, 0);
		if ($start === -1) {
			throw new InvalidInputException('There is no measure %s in this score.', [$from]);
		}
		$end = self::find($measures, $to, $start);
		if ($end === -1) {
			if (self::find($measures, $to, 0) === -1) {
				throw new InvalidInputException('There is no measure %s in this score.', [$to]);
			}
			throw new InvalidInputException('Measure %s does not come after measure %s.', [$to, $from]);
		}
		return [$start, $end];
	}

	/**
	 * @param list<\DOMElement> $measures
	 */
	private static function find(array $measures, string $number, int $from): int {
		for ($i = $from; $i < count($measures); $i++) {
			if (self::sameNumber($measures[$i]->getAttribute('number'), $number)) {
				return $i;
			}
		}
		return -1;
	}

	// ---- repeats, endings and jumps ---------------------------------------------

	/**
	 * Removes what leads out of the measures start–end of one part.
	 *
	 * @param list<\DOMElement> $measures all measures of the part
	 */
	private static function removeWaysOut(\DOMDocument $document, array $measures, int $start, int $end): void {
		$marks = array_map(self::marks(...), $measures);
		$groups = self::endingGroups($marks);
		$targets = self::repeatTargets($marks, $groups);
		/** @var list<\DOMElement> $touched barlines that lost a child */
		$touched = [];

		$removedBackward = [];
		foreach ($targets as $index => $target) {
			if ($index >= $start && $index <= $end && $target < $start) {
				self::removeRepeats($marks[$index]['backward'], $touched);
				$removedBackward[$index] = true;
			}
		}
		for ($i = $start; $i <= $end; $i++) {
			if ($marks[$i]['forward'] === []) {
				continue;
			}
			$partner = array_search($i, $targets, true);
			if ($partner === false || $partner > $end) {
				self::removeRepeats($marks[$i]['forward'], $touched);
			}
		}

		foreach ($groups as $group) {
			$first = $group[0]['start'];
			$last = $group[count($group) - 1]['end'];
			if ($first > $end || $last < $start) {
				continue;
			}
			$remove = $first < $start;
			foreach ($group as $span) {
				if ($span['start'] <= $end && ($span['end'] > $end || isset($removedBackward[$span['end']]))) {
					$remove = true;
				}
			}
			if ($remove) {
				for ($i = max($first, $start); $i <= min($last, $end); $i++) {
					foreach ($marks[$i]['endings'] as $ending) {
						self::detach($ending, $touched);
					}
				}
			}
		}

		for ($i = $start; $i <= $end; $i++) {
			foreach ($marks[$i]['sounds'] as $sound) {
				$out = [];
				foreach (self::JUMPS as $jump) {
					if (!$sound->hasAttribute($jump)) {
						continue;
					}
					$target = match ($jump) {
						'dacapo' => $sound->getAttribute('dacapo') === 'yes' ? 0 : null,
						'dalsegno' => self::findSegno($marks, $i, $sound->getAttribute('dalsegno')),
						default => self::findCoda($marks, $i, $sound->getAttribute('tocoda')),
					};
					if ($target !== null && ($target === -1 || $target < $start || $target > $end)) {
						$out[] = $jump;
					}
				}
				if ($out !== []) {
					self::removeJumps($sound, $out);
				}
			}
		}

		self::keepRepeatTargets($document, $measures, $start, $end, $targets, $removedBackward, $touched);

		foreach ($touched as $barline) {
			if ($barline->parentNode !== null && self::children($barline) === []) {
				$barline->parentNode->removeChild($barline);
			}
		}
	}

	/**
	 * Adds a forward repeat where a kept backward repeat would otherwise go
	 * back to another measure in the excerpt than in the whole score.
	 *
	 * @param list<\DOMElement> $measures all measures of the part
	 * @param array<int, int> $targets backward repeat => target in the whole score
	 * @param array<int, true> $removedBackward
	 * @param list<\DOMElement> $touched
	 */
	private static function keepRepeatTargets(\DOMDocument $document, array $measures, int $start, int $end, array $targets,
		array $removedBackward, array &$touched): void {
		$kept = array_slice($measures, $start, $end - $start + 1);
		foreach ($targets as $index => $target) {
			if ($index < $start || $index > $end || isset($removedBackward[$index])) {
				continue;
			}
			$marks = array_map(self::marks(...), $kept);
			$now = self::repeatTargets($marks, self::endingGroups($marks))[$index - $start] ?? null;
			if ($now === null || $now + $start === $target) {
				continue;
			}
			$measure = $measures[$target];
			$barline = null;
			foreach (self::children($measure, 'barline') as $candidate) {
				if ($candidate->getAttribute('location') === 'left') {
					$barline = $candidate;
					break;
				}
			}
			if ($barline === null) {
				$barline = self::element($document, 'barline');
				$barline->setAttribute('location', 'left');
				$measure->insertBefore($barline, $measure->firstChild);
			}
			if (self::children($barline, 'bar-style') === []) {
				$style = self::element($document, 'bar-style', 'heavy-light');
				$barline->insertBefore($style, $barline->firstChild);
			}
			$repeat = self::element($document, 'repeat');
			$repeat->setAttribute('direction', 'forward');
			$barline->appendChild($repeat);
			$touched[] = $barline;
		}
	}

	/**
	 * Repeat, ending and jump marks of one measure.
	 *
	 * @return Marks
	 */
	private static function marks(\DOMElement $measure): array {
		$marks = ['forward' => [], 'backward' => [], 'endingStart' => false, 'endingStop' => false, 'endings' => [], 'sounds' => [],
			'segno' => null, 'coda' => null];
		foreach (self::children($measure, 'barline') as $barline) {
			foreach (self::children($barline, 'repeat') as $repeat) {
				$direction = $repeat->getAttribute('direction');
				if ($direction === 'forward') {
					$marks['forward'][] = $repeat;
				} elseif ($direction === 'backward') {
					$marks['backward'][] = $repeat;
				}
			}
			foreach (self::children($barline, 'ending') as $ending) {
				$type = $ending->getAttribute('type');
				if ($type === 'start') {
					$marks['endingStart'] = true;
				} elseif ($type === 'stop' || $type === 'discontinue') {
					$marks['endingStop'] = true;
				}
				$marks['endings'][] = $ending;
			}
		}
		foreach (self::elements($measure->getElementsByTagName('sound')) as $sound) {
			$marks['sounds'][] = $sound;
			if ($sound->hasAttribute('segno')) {
				$marks['segno'] = $sound->getAttribute('segno');
			}
			if ($sound->hasAttribute('coda')) {
				$marks['coda'] = $sound->getAttribute('coda');
			}
		}
		return $marks;
	}

	/**
	 * Endings (start to stop) and bracket groups of consecutive endings, as
	 * the playback reads them.
	 *
	 * @param list<Marks> $marks
	 * @return list<non-empty-list<Span>>
	 */
	private static function endingGroups(array $marks): array {
		$count = count($marks);
		$groups = [];
		$group = [];
		$previousEnd = -2;
		for ($i = 0; $i < $count; $i++) {
			if (!$marks[$i]['endingStart']) {
				continue;
			}
			$end = $i;
			while ($end < $count - 1 && !$marks[$end]['endingStop'] && !$marks[$end + 1]['endingStart']) {
				$end++;
			}
			$span = ['start' => $i, 'end' => $end];
			if ($group !== [] && $previousEnd === $i - 1) {
				$group[] = $span;
			} else {
				if ($group !== []) {
					$groups[] = $group;
				}
				$group = [$span];
			}
			$previousEnd = $end;
			$i = $end;
		}
		if ($group !== []) {
			$groups[] = $group;
		}
		return $groups;
	}

	/**
	 * Where each backward repeat goes back to: the last forward repeat, else
	 * the measure after the previous backward repeat or bracket group, else
	 * the beginning.
	 *
	 * @param list<Marks> $marks
	 * @param list<non-empty-list<Span>> $groups
	 * @return array<int, int> index of the backward repeat => index of its target, ascending
	 */
	private static function repeatTargets(array $marks, array $groups): array {
		$groupEnds = [];
		foreach ($groups as $group) {
			$groupEnds[$group[count($group) - 1]['end']] = true;
		}
		$targets = [];
		$section = 0;
		foreach ($marks as $i => $measure) {
			if ($i > 0 && isset($groupEnds[$i - 1])) {
				$section = $i;
			}
			if ($measure['forward'] !== []) {
				$section = $i;
			}
			if ($measure['backward'] !== []) {
				$targets[$i] = $section;
				$section = $i + 1;
			}
		}
		return $targets;
	}

	/**
	 * The segno a D.S. goes back to: the one with its name, else the first
	 * one up to the jump; -1 when there is none.
	 *
	 * @param list<Marks> $marks
	 */
	private static function findSegno(array $marks, int $before, string $name): int {
		$fallback = -1;
		for ($i = 0; $i <= $before; $i++) {
			$segno = $marks[$i]['segno'];
			if ($segno === null) {
				continue;
			}
			if ($name !== '' && $segno === $name) {
				return $i;
			}
			if ($fallback === -1) {
				$fallback = $i;
			}
		}
		return $fallback;
	}

	/**
	 * The coda a "To Coda" jumps to: the next one with its name, else the
	 * next one; -1 when there is none.
	 *
	 * @param list<Marks> $marks
	 */
	private static function findCoda(array $marks, int $after, string $name): int {
		$fallback = -1;
		for ($i = $after + 1; $i < count($marks); $i++) {
			$coda = $marks[$i]['coda'];
			if ($coda === null) {
				continue;
			}
			if ($coda === $name) {
				return $i;
			}
			if ($fallback === -1) {
				$fallback = $i;
			}
		}
		return $fallback;
	}

	/**
	 * @param list<\DOMElement> $repeats
	 * @param list<\DOMElement> $touched
	 */
	private static function removeRepeats(array $repeats, array &$touched): void {
		foreach ($repeats as $repeat) {
			$barline = $repeat->parentNode;
			self::detach($repeat, $touched);
			if ($barline instanceof \DOMElement) {
				foreach (self::children($barline, 'bar-style') as $style) {
					if (in_array(trim($style->textContent), self::REPEAT_STYLES, true)) {
						$barline->removeChild($style);
					}
				}
			}
		}
	}

	/**
	 * Removes a child of a barline and remembers the barline.
	 *
	 * @param list<\DOMElement> $touched
	 */
	private static function detach(\DOMElement $element, array &$touched): void {
		$barline = $element->parentNode;
		if ($barline instanceof \DOMElement) {
			$barline->removeChild($element);
			$touched[] = $barline;
		}
	}

	/**
	 * Removes jumps: with their direction, or else the attributes (and the
	 * sound when nothing is left of it).
	 *
	 * @param non-empty-list<string> $jumps attribute names
	 */
	private static function removeJumps(\DOMElement $sound, array $jumps): void {
		$parent = $sound->parentNode;
		if ($parent instanceof \DOMElement && $parent->nodeName === 'direction') {
			$parent->parentNode?->removeChild($parent);
			return;
		}
		foreach ($jumps as $jump) {
			$sound->removeAttribute($jump);
		}
		if ($parent !== null && !$sound->hasAttributes() && !$sound->hasChildNodes()) {
			$parent->removeChild($sound);
		}
	}

	// ---- attributes and tempo ---------------------------------------------------

	/**
	 * Gives the first kept measure the attributes valid there.
	 *
	 * @param list<\DOMElement> $measures all measures of the part
	 * @return \DOMElement|null the leading <attributes> of the first kept measure
	 */
	private static function carryAttributes(\DOMDocument $document, array $measures, int $start): ?\DOMElement {
		/** @var array<string, \DOMElement> $valid */
		$valid = [];
		for ($i = 0; $i < $start; $i++) {
			foreach (self::children($measures[$i], 'attributes') as $attributes) {
				foreach (self::children($attributes) as $child) {
					$name = $child->nodeName;
					if (!in_array($name, self::CARRIED, true)) {
						continue;
					}
					if (in_array($name, self::ALL_STAVES, true) && $child->getAttribute('number') === '') {
						// Without a number it applies to every staff again
						foreach (array_keys($valid) as $key) {
							if (str_starts_with($key, $name . '#')) {
								unset($valid[$key]);
							}
						}
					}
					$valid[self::attributeKey($child)] = $child;
				}
			}
		}
		$measure = $measures[$start];
		$own = self::leadingAttributes($measure);
		if ($valid === []) {
			return $own;
		}
		$present = [];
		if ($own !== null) {
			foreach (self::children($own) as $child) {
				$present[self::attributeKey($child)] = true;
			}
		} else {
			$own = self::element($document, 'attributes');
			$measure->insertBefore($own, $measure->firstChild);
		}
		foreach ($valid as $key => $element) {
			$name = $element->nodeName;
			if (isset($present[$key]) || (in_array($name, self::ALL_STAVES, true) && isset($present[$name . '#']))) {
				continue;
			}
			$clone = $element->cloneNode(true);
			if ($clone instanceof \DOMElement) {
				$own->appendChild($clone);
			}
		}
		self::sortAttributes($own);
		return $own;
	}

	/**
	 * Name and staff of an attribute: `clef#1`, `key#` (every staff), …
	 */
	private static function attributeKey(\DOMElement $element): string {
		$number = trim($element->getAttribute('number'));
		if ($number === '' && !in_array($element->nodeName, self::ALL_STAVES, true)) {
			// clef, staff details: staff 1; the others occur once
			$number = in_array($element->nodeName, ['clef', 'staff-details'], true) ? '1' : '';
		}
		return $element->nodeName . '#' . $number;
	}

	/**
	 * The <attributes> of a measure before its first note, if any.
	 */
	private static function leadingAttributes(\DOMElement $measure): ?\DOMElement {
		foreach (self::children($measure) as $child) {
			if (in_array($child->nodeName, ['note', 'backup', 'forward'], true)) {
				return null;
			}
			if ($child->nodeName === 'attributes') {
				return $child;
			}
		}
		return null;
	}

	/**
	 * Children of <attributes> in schema order.
	 */
	private static function sortAttributes(\DOMElement $attributes): void {
		$children = self::children($attributes);
		$sorted = $children;
		// usort is stable since PHP 8.0
		usort($sorted, fn (\DOMElement $a, \DOMElement $b): int => self::rank($a) <=> self::rank($b));
		if ($sorted !== $children) {
			foreach ($sorted as $child) {
				$attributes->appendChild($child);
			}
		}
	}

	/**
	 * Position of a child of <attributes>: schema order, then the one for
	 * every staff, then by staff.
	 *
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function rank(\DOMElement $element): array {
		$position = array_search($element->nodeName, self::ATTRIBUTE_ORDER, true);
		$number = $element->getAttribute('number');
		return [$position === false ? count(self::ATTRIBUTE_ORDER) : $position, $number === '' ? 0 : 1, (int)$number];
	}

	/**
	 * Gives the first kept measure the last tempo marking before it, unless
	 * it has one of its own before its first note.
	 *
	 * @param list<\DOMElement> $measures all measures of the part
	 */
	private static function carryTempo(\DOMDocument $document, array $measures, int $start, ?\DOMElement $attributes): void {
		$last = null;
		for ($i = 0; $i < $start; $i++) {
			foreach (self::children($measures[$i]) as $child) {
				if (self::isTempo($child)) {
					$last = $child;
				}
			}
		}
		if ($last === null) {
			return;
		}
		$measure = $measures[$start];
		foreach (self::children($measure) as $child) {
			if ($child->nodeName === 'note') {
				break;
			}
			if (self::isTempo($child)) {
				return;
			}
		}
		$clone = $last->cloneNode(true);
		if (!$clone instanceof \DOMElement) {
			return;
		}
		foreach (self::children($clone, 'offset') as $offset) {
			$clone->removeChild($offset);
		}
		if ($clone->nodeName === 'sound') {
			$clone = self::tempoSound($document, $clone);
		} else {
			// Only the playback tempo stays of a sound; no jumps, no other settings
			foreach (self::elements($clone->getElementsByTagName('sound')) as $sound) {
				if ($sound->hasAttribute('tempo')) {
					$sound->parentNode?->replaceChild(self::tempoSound($document, $sound), $sound);
				} else {
					$sound->parentNode?->removeChild($sound);
				}
			}
		}
		$after = $attributes !== null && $attributes->parentNode === $measure ? $attributes->nextSibling : $measure->firstChild;
		$measure->insertBefore($clone, $after);
	}

	/**
	 * A new <sound> with only the tempo of the given one.
	 */
	private static function tempoSound(\DOMDocument $document, \DOMElement $sound): \DOMElement {
		$tempo = self::element($document, 'sound');
		$tempo->setAttribute('tempo', $sound->getAttribute('tempo'));
		return $tempo;
	}

	/**
	 * Whether a child of a measure gives the tempo: a direction with a
	 * metronome mark or a playback tempo, or a sound with a tempo.
	 */
	private static function isTempo(\DOMElement $element): bool {
		if ($element->nodeName === 'sound') {
			return $element->hasAttribute('tempo');
		}
		if ($element->nodeName !== 'direction') {
			return false;
		}
		if ($element->getElementsByTagName('metronome')->length > 0) {
			return true;
		}
		foreach (self::elements($element->getElementsByTagName('sound')) as $sound) {
			if ($sound->hasAttribute('tempo')) {
				return true;
			}
		}
		return false;
	}

	// ---- DOM helpers ------------------------------------------------------------

	private static function element(\DOMDocument $document, string $name, string $text = ''): \DOMElement {
		$element = $document->createElement($name);
		if ($element === false) {
			throw new \RuntimeException('The excerpt could not be written');
		}
		if ($text !== '') {
			$element->appendChild($document->createTextNode($text));
		}
		return $element;
	}

	/**
	 * Element children, optionally of one name.
	 *
	 * @return list<\DOMElement>
	 */
	private static function children(\DOMElement $parent, ?string $name = null): array {
		$children = [];
		foreach (self::elements($parent->childNodes) as $child) {
			if ($name === null || $child->nodeName === $name) {
				$children[] = $child;
			}
		}
		return $children;
	}

	/**
	 * The elements of a node list, as a list that stays the same while the
	 * document changes.
	 *
	 * @return list<\DOMElement>
	 */
	private static function elements(\DOMNodeList $list): array {
		$elements = [];
		for ($i = 0; $i < $list->length; $i++) {
			$node = $list->item($i);
			if ($node instanceof \DOMElement) {
				$elements[] = $node;
			}
		}
		return $elements;
	}
}
