<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Exception\InvalidInputException;

/**
 * A small correction of a MusicXML score (ROADMAP, guideline 4): compares the
 * document the browser sends with the stored one, element by element, and
 * applies the differences to the stored document. Only these differences are
 * allowed:
 *
 * - `note/pitch/step`, `note/pitch/octave`, `note/pitch/alter` (also added or
 *   removed),
 * - `note/accidental` (added, changed or removed; a few attributes),
 * - the text of `note/lyric/text`.
 *
 * Everything else must be equal; white space between elements, comments and
 * processing instructions do not count. The result is the stored document
 * with only the allowed changes applied: the received text itself is never
 * stored, so whatever else it contains cannot reach the new file. Durations,
 * notes and measures cannot change; the measure lengths are compared once
 * more on their own.
 *
 * Pure functions on DOMDocument, no server needed.
 *
 * @psalm-type Change = array{measure: string, part: string, staff: string, voice: string, kind: string, verse: ?string, old: string, new: string}
 */
final class MusicXmlCorrection {
	public const STEPS = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
	public const ACCIDENTALS = ['sharp', 'natural', 'flat', 'double-sharp', 'sharp-sharp', 'flat-flat', 'natural-sharp', 'natural-flat'];
	/** Attributes an accidental may get or lose, with yes/no */
	public const ACCIDENTAL_ATTRIBUTES = ['cautionary', 'editorial', 'parentheses', 'bracket'];
	public const MAX_SYLLABLE = 200;

	/** Elements of a note that come after <accidental> (order of the MusicXML schema) */
	private const AFTER_ACCIDENTAL = ['time-modification', 'stem', 'notehead', 'notehead-text', 'staff', 'beam', 'notations', 'lyric', 'play', 'listen'];
	private const MAX_DEPTH = 64;

	/**
	 * Applies the allowed differences of $newXml to $oldXml.
	 *
	 * @return array{xml: string, changes: list<Change>}
	 * @throws InvalidInputException when the documents differ in anything else, or not at all
	 */
	public static function apply(string $oldXml, string $newXml): array {
		$old = self::load($oldXml);
		$new = self::load($newXml);
		$oldRoot = $old->documentElement;
		$newRoot = $new->documentElement;
		if ($oldRoot === null || $newRoot === null) {
			throw new InvalidInputException('This file is not a MusicXML score.');
		}
		self::checkMeasureLengths($oldRoot, $newRoot);
		$changes = [];
		self::compare($oldRoot, $newRoot, $changes, self::partNames($oldRoot), 0);
		if ($changes === []) {
			throw new InvalidInputException('The correction contains no change.');
		}
		$xml = $old->saveXML();
		if ($xml === false) {
			throw new \RuntimeException('The corrected score could not be written');
		}
		return ['xml' => $xml, 'changes' => $changes];
	}

	/**
	 * A pitch as text: step, alteration (#, ##, b, bb, other values as +0.5)
	 * and octave, e.g. "F#4".
	 */
	public static function pitchText(string $step, ?string $alter, string $octave): string {
		$value = $alter === null || trim($alter) === '' ? 0.0 : (float)$alter;
		$sign = match (true) {
			$value === 0.0 => '',
			$value === 1.0 => '#',
			$value === 2.0 => '##',
			$value === -1.0 => 'b',
			$value === -2.0 => 'bb',
			default => sprintf('%+g', $value),
		};
		return $step . $sign . $octave;
	}

	/**
	 * @throws InvalidInputException
	 */
	private static function load(string $xml): \DOMDocument {
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
			|| !in_array($root->nodeName, ['score-partwise', 'score-timewise'], true)
			|| ($doctype !== null && trim((string)$doctype->internalSubset) !== '')) {
			throw new InvalidInputException('This file is not a MusicXML score.');
		}
		return $document;
	}

	/**
	 * Names of the parts by id, from the part list.
	 *
	 * @return array<string, string>
	 */
	private static function partNames(\DOMElement $root): array {
		$names = [];
		foreach (self::nodes($root->getElementsByTagName('score-part')) as $part) {
			if (!$part instanceof \DOMElement) {
				continue;
			}
			$name = '';
			foreach (self::nodes($part->childNodes) as $child) {
				if ($child instanceof \DOMElement && $child->nodeName === 'part-name') {
					$name = trim($child->textContent);
					break;
				}
			}
			$names[$part->getAttribute('id')] = $name;
		}
		return $names;
	}

	/**
	 * @param list<Change> $changes
	 * @param array<string, string> $names part names by id
	 * @throws InvalidInputException
	 */
	private static function compare(\DOMElement $old, \DOMElement $new, array &$changes, array $names, int $depth): void {
		if ($depth > self::MAX_DEPTH || $old->nodeName !== $new->nodeName) {
			throw self::changed($old);
		}
		self::sameAttributes($old, $new);
		if ($old->nodeName === 'note') {
			self::compareNote($old, $new, $changes, $names, $depth);
			return;
		}
		self::compareChildren($old, $new, $changes, $names, $depth);
	}

	/**
	 * @param list<Change> $changes
	 * @param array<string, string> $names
	 * @param list<string> $skip names of child elements compared by the caller
	 * @throws InvalidInputException
	 */
	private static function compareChildren(\DOMElement $old, \DOMElement $new, array &$changes, array $names, int $depth, array $skip = []): void {
		$a = self::significant($old, $skip);
		$b = self::significant($new, $skip);
		if (count($a) !== count($b)) {
			throw self::changed($old);
		}
		foreach ($a as $i => $x) {
			$y = $b[$i];
			if ($x instanceof \DOMElement && $y instanceof \DOMElement) {
				self::compare($x, $y, $changes, $names, $depth + 1);
			} elseif (!is_string($x) || !is_string($y) || $x !== $y) {
				throw self::changed($old);
			}
		}
	}

	/**
	 * @param list<Change> $changes
	 * @param array<string, string> $names
	 * @throws InvalidInputException
	 */
	private static function compareNote(\DOMElement $old, \DOMElement $new, array &$changes, array $names, int $depth): void {
		$a = self::significant($old, ['accidental']);
		$b = self::significant($new, ['accidental']);
		if (count($a) !== count($b)) {
			throw self::changed($old);
		}
		foreach ($a as $i => $x) {
			$y = $b[$i];
			if (!$x instanceof \DOMElement || !$y instanceof \DOMElement || $x->nodeName !== $y->nodeName) {
				throw self::changed($old);
			}
			if ($x->nodeName === 'pitch') {
				self::comparePitch($x, $y, $old, $changes, $names);
			} elseif ($x->nodeName === 'lyric') {
				self::compareLyric($x, $y, $old, $changes, $names);
			} else {
				self::compare($x, $y, $changes, $names, $depth + 1);
			}
		}
		self::compareAccidental($old, $new, $changes, $names);
	}

	/**
	 * @param list<Change> $changes
	 * @param array<string, string> $names
	 * @throws InvalidInputException
	 */
	private static function comparePitch(\DOMElement $old, \DOMElement $new, \DOMElement $note, array &$changes, array $names): void {
		self::sameAttributes($old, $new);
		$a = self::significant($old, ['alter']);
		$b = self::significant($new, ['alter']);
		if (count($a) !== count($b)) {
			throw self::changed($note);
		}
		$step = $octave = null;
		$before = ['step' => '', 'octave' => ''];
		$after = ['step' => '', 'octave' => ''];
		foreach ($a as $i => $x) {
			$y = $b[$i];
			if (!$x instanceof \DOMElement || !$y instanceof \DOMElement || $x->nodeName !== $y->nodeName) {
				throw self::changed($note);
			}
			if ($x->nodeName !== 'step' && $x->nodeName !== 'octave') {
				self::compare($x, $y, $changes, $names, 0);
				continue;
			}
			self::sameAttributes($x, $y);
			$value = self::plainText($y, $note);
			$valid = $x->nodeName === 'step' ? in_array($value, self::STEPS, true) : preg_match('/^[0-9]$/', $value) === 1;
			$before[$x->nodeName] = trim($x->textContent);
			$after[$x->nodeName] = $value;
			if ($value === $x->textContent) {
				continue;
			}
			if (!$valid) {
				throw self::changed($note);
			}
			if ($x->nodeName === 'step') {
				$step = [$x, $value];
			} else {
				$octave = [$x, $value];
			}
		}

		$oldAlter = self::children($old, 'alter');
		$newAlter = self::children($new, 'alter');
		if (count($oldAlter) > 1 || count($newAlter) > 1) {
			throw self::changed($note);
		}
		$oldAlterElement = $oldAlter[0] ?? null;
		$newAlterElement = $newAlter[0] ?? null;
		$alterBefore = $oldAlterElement === null ? null : trim($oldAlterElement->textContent);
		$alterAfter = null;
		if ($newAlterElement !== null) {
			$alterAfter = self::plainText($newAlterElement, $note);
			$value = (float)$alterAfter;
			if (preg_match('/^-?\d+(\.\d+)?$/', $alterAfter) !== 1 || $value < -2.0 || $value > 2.0) {
				throw self::changed($note);
			}
			if ($oldAlterElement !== null) {
				self::sameAttributes($oldAlterElement, $newAlterElement);
			} elseif (self::attributes($newAlterElement) !== []) {
				throw self::changed($note);
			}
		}
		$alterChanged = ($oldAlterElement === null) !== ($newAlterElement === null)
			|| ($oldAlterElement !== null && $oldAlterElement->textContent !== $alterAfter);
		if ($step === null && $octave === null && !$alterChanged) {
			return;
		}

		if ($step !== null) {
			$step[0]->textContent = $step[1];
		}
		if ($octave !== null) {
			$octave[0]->textContent = $octave[1];
		}
		if ($alterChanged) {
			if ($alterAfter === null) {
				if ($oldAlterElement !== null) {
					self::remove($oldAlterElement);
				}
			} elseif ($oldAlterElement !== null) {
				$oldAlterElement->textContent = $alterAfter;
			} else {
				$element = self::document($old)->createElement('alter');
				$element->appendChild(self::document($old)->createTextNode($alterAfter));
				self::insert($old, $element, self::children($old, 'octave')[0] ?? null);
			}
		}
		$changes[] = self::change($note, $names, 'pitch', null,
			self::pitchText($before['step'], $alterBefore, $before['octave']),
			self::pitchText($after['step'], $alterAfter, $after['octave']));
	}

	/**
	 * @param list<Change> $changes
	 * @param array<string, string> $names
	 * @throws InvalidInputException
	 */
	private static function compareAccidental(\DOMElement $old, \DOMElement $new, array &$changes, array $names): void {
		$a = self::children($old, 'accidental');
		$b = self::children($new, 'accidental');
		if (count($a) > 1 || count($b) > 1) {
			throw self::changed($old);
		}
		$before = isset($a[0]) ? trim($a[0]->textContent) : '';
		if (!isset($b[0])) {
			if (isset($a[0])) {
				self::remove($a[0]);
				$changes[] = self::change($old, $names, 'accidental', null, $before, '');
			}
			return;
		}
		$after = self::plainText($b[0], $old);
		$attributes = self::attributes($b[0]);
		$oldAttributes = isset($a[0]) ? self::attributes($a[0]) : [];
		// Attributes other than the allowed ones must stay exactly as they are.
		$fixed = fn (array $list): array => array_diff_key($list, array_flip(self::ACCIDENTAL_ATTRIBUTES));
		if ($fixed($attributes) !== $fixed($oldAttributes)) {
			throw self::changed($old);
		}
		foreach (array_intersect_key($attributes, array_flip(self::ACCIDENTAL_ATTRIBUTES)) as $value) {
			if ($value !== 'yes' && $value !== 'no') {
				throw self::changed($old);
			}
		}
		$sameText = isset($a[0]) && $a[0]->textContent === $after;
		ksort($attributes);
		ksort($oldAttributes);
		if ($sameText && $attributes === $oldAttributes) {
			return;
		}
		if (!$sameText && !in_array($after, self::ACCIDENTALS, true)) {
			throw self::changed($old);
		}
		if (isset($a[0])) {
			$element = $a[0];
			if (!$sameText) {
				$element->textContent = $after;
			}
			foreach (self::ACCIDENTAL_ATTRIBUTES as $name) {
				if (isset($attributes[$name])) {
					$element->setAttribute($name, $attributes[$name]);
				} elseif ($element->hasAttribute($name)) {
					$element->removeAttribute($name);
				}
			}
		} else {
			$element = self::document($old)->createElement('accidental');
			$element->appendChild(self::document($old)->createTextNode($after));
			foreach ($attributes as $name => $value) {
				$element->setAttribute($name, $value);
			}
			$reference = null;
			foreach (self::nodes($old->childNodes) as $child) {
				if ($child instanceof \DOMElement && in_array($child->nodeName, self::AFTER_ACCIDENTAL, true)) {
					$reference = $child;
					break;
				}
			}
			self::insert($old, $element, $reference);
		}
		$changes[] = self::change($old, $names, 'accidental', null, $before, $after);
	}

	/**
	 * @param list<Change> $changes
	 * @param array<string, string> $names
	 * @throws InvalidInputException
	 */
	private static function compareLyric(\DOMElement $old, \DOMElement $new, \DOMElement $note, array &$changes, array $names): void {
		self::sameAttributes($old, $new);
		$a = self::significant($old);
		$b = self::significant($new);
		if (count($a) !== count($b)) {
			throw self::changed($note);
		}
		foreach ($a as $i => $x) {
			$y = $b[$i];
			if (!$x instanceof \DOMElement || !$y instanceof \DOMElement || $x->nodeName !== $y->nodeName) {
				throw self::changed($note);
			}
			if ($x->nodeName !== 'text') {
				self::compare($x, $y, $changes, $names, 0);
				continue;
			}
			self::sameAttributes($x, $y);
			$after = self::plainText($y, $note);
			if ($after === $x->textContent) {
				continue;
			}
			if ($after === '' || mb_strlen($after) > self::MAX_SYLLABLE || preg_match('/[\x00-\x1F\x7F]/u', $after) !== 0) {
				throw self::changed($note);
			}
			$before = $x->textContent;
			$x->textContent = $after;
			$verse = $old->getAttribute('number');
			$changes[] = self::change($note, $names, 'lyric', $verse === '' ? '1' : $verse, $before, $after);
		}
	}

	/**
	 * Lengths of all measures of every part (in divisions) must stay the same:
	 * a second check besides the element comparison, which already refuses
	 * any other duration.
	 *
	 * @throws InvalidInputException
	 */
	private static function checkMeasureLengths(\DOMElement $old, \DOMElement $new): void {
		$a = self::measureLengths($old);
		$b = self::measureLengths($new);
		if (count($a) !== count($b)) {
			throw new InvalidInputException('This correction changes more than pitches, accidentals and syllables (measure %s).', ['–']);
		}
		foreach ($a as $i => [$number, $length]) {
			if ($b[$i][1] !== $length) {
				throw new InvalidInputException('The length of measure %s has changed.', [$number]);
			}
		}
	}

	/**
	 * Printed number and length of every measure of every part, in document
	 * order (partwise: part by part; timewise: measure by measure).
	 *
	 * @return list<array{0: string, 1: float}>
	 */
	private static function measureLengths(\DOMElement $root): array {
		$result = [];
		foreach (self::nodes($root->childNodes) as $outer) {
			if (!$outer instanceof \DOMElement || !in_array($outer->nodeName, ['part', 'measure'], true)) {
				continue;
			}
			foreach (self::nodes($outer->childNodes) as $inner) {
				if (!$inner instanceof \DOMElement || !in_array($inner->nodeName, ['part', 'measure'], true)) {
					continue;
				}
				$measure = $outer->nodeName === 'measure' ? $outer : $inner;
				$result[] = [$measure->getAttribute('number'), self::length($inner)];
			}
		}
		return $result;
	}

	/**
	 * Length of a measure: the furthest position reached by notes, backups
	 * and forwards (chord notes and grace notes do not move on).
	 */
	private static function length(\DOMElement $content): float {
		$position = 0.0;
		$furthest = 0.0;
		foreach (self::nodes($content->childNodes) as $child) {
			if (!$child instanceof \DOMElement) {
				continue;
			}
			$duration = 0.0;
			foreach (self::nodes($child->childNodes) as $part) {
				if ($part instanceof \DOMElement && $part->nodeName === 'duration') {
					$duration = (float)trim($part->textContent);
				}
			}
			if ($child->nodeName === 'note') {
				if (self::children($child, 'chord') !== [] || self::children($child, 'grace') !== []) {
					continue;
				}
				$position += $duration;
			} elseif ($child->nodeName === 'forward') {
				$position += $duration;
			} elseif ($child->nodeName === 'backup') {
				$position -= $duration;
			}
			$furthest = max($furthest, $position);
		}
		return $furthest;
	}

	/**
	 * Child elements and text that count: white space between elements,
	 * comments and processing instructions do not. Adjacent text and CDATA
	 * are joined.
	 *
	 * @param list<string> $skip names of child elements to leave out
	 * @return list<\DOMElement|string>
	 */
	private static function significant(\DOMElement $element, array $skip = []): array {
		$items = [];
		$text = null;
		foreach (self::nodes($element->childNodes) as $child) {
			if ($child instanceof \DOMText) {
				// DOMCdataSection is a DOMText, too
				$text = ($text ?? '') . $child->data;
				continue;
			}
			if ($text !== null) {
				if (trim($text) !== '') {
					$items[] = $text;
				}
				$text = null;
			}
			if ($child instanceof \DOMElement && !in_array($child->nodeName, $skip, true)) {
				$items[] = $child;
			}
		}
		if ($text !== null && trim($text) !== '') {
			$items[] = $text;
		}
		return $items;
	}

	/**
	 * @return list<\DOMElement>
	 */
	private static function children(\DOMElement $element, string $name): array {
		$result = [];
		foreach (self::nodes($element->childNodes) as $child) {
			if ($child instanceof \DOMElement && $child->nodeName === $name) {
				$result[] = $child;
			}
		}
		return $result;
	}

	/**
	 * The text of an element that may only hold text.
	 *
	 * @throws InvalidInputException
	 */
	private static function plainText(\DOMElement $element, \DOMElement $context): string {
		foreach (self::nodes($element->childNodes) as $child) {
			if (!$child instanceof \DOMText) {
				throw self::changed($context);
			}
		}
		return $element->textContent;
	}

	/**
	 * @return array<string, string>
	 */
	private static function attributes(\DOMElement $element): array {
		$result = [];
		$attributes = $element->attributes;
		if ($attributes === null) {
			return [];
		}
		for ($i = 0; $i < $attributes->length; $i++) {
			$attribute = $attributes->item($i);
			if ($attribute instanceof \DOMAttr) {
				$result[$attribute->nodeName] = $attribute->value;
			}
		}
		return $result;
	}

	/**
	 * The nodes of a node list, typed for the analysis.
	 *
	 * @return list<\DOMNode>
	 */
	private static function nodes(\DOMNodeList $list): array {
		$nodes = [];
		for ($i = 0; $i < $list->length; $i++) {
			$node = $list->item($i);
			if ($node instanceof \DOMNode) {
				$nodes[] = $node;
			}
		}
		return $nodes;
	}

	/**
	 * @throws InvalidInputException
	 */
	private static function sameAttributes(\DOMElement $old, \DOMElement $new): void {
		$a = self::attributes($old);
		$b = self::attributes($new);
		ksort($a);
		ksort($b);
		if ($a !== $b) {
			throw self::changed($old);
		}
	}

	private static function document(\DOMElement $element): \DOMDocument {
		// An element always belongs to a document.
		return $element->ownerDocument;
	}

	/**
	 * Inserts an element before a reference (or at the end), keeping the
	 * indentation of the file.
	 */
	private static function insert(\DOMElement $parent, \DOMElement $element, ?\DOMElement $reference): void {
		if ($reference !== null) {
			// … <indent><element><indent><reference>
			$indent = $reference->previousSibling;
			$parent->insertBefore($element, $reference);
			if (self::isBlank($indent)) {
				$parent->insertBefore($indent->cloneNode(), $reference);
			}
			return;
		}
		// At the end: <indent><last element><indent><element><closing white space>
		$last = $parent->lastChild;
		$closing = self::isBlank($last) ? $last : null;
		$previous = $closing === null ? $last : $closing->previousSibling;
		$indent = $previous?->previousSibling;
		$parent->insertBefore($element, $closing);
		if ($previous instanceof \DOMElement && self::isBlank($indent)) {
			$parent->insertBefore($indent->cloneNode(), $element);
		}
	}

	/**
	 * Whether a node is white space only (between elements).
	 *
	 * @psalm-assert-if-true \DOMText $node
	 */
	private static function isBlank(?\DOMNode $node): bool {
		return $node instanceof \DOMText && !$node instanceof \DOMCdataSection && trim($node->data) === '';
	}

	/**
	 * Removes an element and the white space before it.
	 */
	private static function remove(\DOMElement $element): void {
		$parent = $element->parentNode;
		if ($parent === null) {
			return;
		}
		$before = $element->previousSibling;
		if (self::isBlank($before)) {
			$parent->removeChild($before);
		}
		$parent->removeChild($element);
	}

	/**
	 * @param array<string, string> $names
	 * @return Change
	 */
	private static function change(\DOMElement $note, array $names, string $kind, ?string $verse, string $old, string $new): array {
		$part = self::ancestor($note, 'part');
		$staff = self::children($note, 'staff')[0] ?? null;
		$voice = self::children($note, 'voice')[0] ?? null;
		$partId = $part?->getAttribute('id') ?? '';
		return [
			'measure' => self::ancestor($note, 'measure')?->getAttribute('number') ?? '',
			'part' => $names[$partId] ?? $partId,
			'staff' => $staff === null ? '1' : trim($staff->textContent),
			'voice' => $voice === null ? '1' : trim($voice->textContent),
			'kind' => $kind,
			'verse' => $verse,
			'old' => $old,
			'new' => $new,
		];
	}

	private static function ancestor(\DOMNode $node, string $name): ?\DOMElement {
		for ($current = $node->parentNode; $current !== null; $current = $current->parentNode) {
			if ($current instanceof \DOMElement && $current->nodeName === $name) {
				return $current;
			}
		}
		return null;
	}

	private static function changed(\DOMElement $element): InvalidInputException {
		$measure = $element->nodeName === 'measure' ? $element : self::ancestor($element, 'measure');
		$number = $measure?->getAttribute('number') ?? '';
		return new InvalidInputException('This correction changes more than pitches, accidentals and syllables (measure %s).',
			[$number === '' ? '–' : $number]);
	}
}
