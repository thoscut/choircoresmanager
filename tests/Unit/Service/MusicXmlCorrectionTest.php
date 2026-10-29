<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\MusicXmlCorrection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A small correction may change pitches, accidentals and syllables and
 * nothing else; the result is the stored document with only these changes.
 */
#[CoversClass(MusicXmlCorrection::class)]
final class MusicXmlCorrectionTest extends TestCase {
	/** Two measures, Tenor I/II on one staff with lyrics, and a bass; a DOCTYPE without internal subset */
	private const SCORE = <<<'XML'
		<?xml version="1.0" encoding="UTF-8" standalone="no"?>
		<!DOCTYPE score-partwise PUBLIC "-//Recordare//DTD MusicXML 4.0 Partwise//EN" "http://www.musicxml.org/dtds/partwise.dtd">
		<score-partwise version="4.0">
		  <!-- made for a test -->
		  <part-list>
		    <score-part id="P1"><part-name>Tenor I/II</part-name></score-part>
		    <score-part id="P2"><part-name>Bass</part-name></score-part>
		  </part-list>
		  <part id="P1">
		    <measure number="1">
		      <attributes><divisions>2</divisions><time><beats>2</beats><beat-type>4</beat-type></time></attributes>
		      <note>
		        <pitch>
		          <step>E</step>
		          <octave>4</octave>
		        </pitch>
		        <duration>2</duration>
		        <voice>1</voice>
		        <type>quarter</type>
		        <stem>up</stem>
		        <lyric number="1"><syllabic>begin</syllabic><text>Lie</text></lyric>
		        <lyric number="2"><text>Sang</text></lyric>
		      </note>
		      <note>
		        <pitch>
		          <step>F</step>
		          <alter>1</alter>
		          <octave>4</octave>
		        </pitch>
		        <duration>2</duration>
		        <voice>1</voice>
		        <type>quarter</type>
		        <accidental>sharp</accidental>
		        <stem>up</stem>
		        <lyric number="1"><syllabic>end</syllabic><text>de</text></lyric>
		      </note>
		      <backup><duration>4</duration></backup>
		      <note>
		        <pitch>
		          <step>C</step>
		          <octave>4</octave>
		        </pitch>
		        <duration>4</duration>
		        <voice>2</voice>
		        <type>half</type>
		      </note>
		    </measure>
		    <measure number="2">
		      <note>
		        <rest/>
		        <duration>4</duration>
		        <voice>1</voice>
		        <type>half</type>
		      </note>
		    </measure>
		  </part>
		  <part id="P2">
		    <measure number="1">
		      <attributes><divisions>2</divisions></attributes>
		      <note>
		        <pitch>
		          <step>C</step>
		          <octave>3</octave>
		        </pitch>
		        <duration>4</duration>
		        <voice>1</voice>
		        <type>half</type>
		      </note>
		    </measure>
		    <measure number="2">
		      <note>
		        <pitch><step>G</step><octave>2</octave></pitch>
		        <duration>4</duration>
		        <voice>1</voice>
		        <type>half</type>
		      </note>
		    </measure>
		  </part>
		</score-partwise>
		XML;

	/**
	 * The score as a browser sends it back: parsed, changed with $change,
	 * serialized without the XML declaration and with other white space.
	 *
	 * @param callable(\DOMXPath): void $change
	 */
	private static function changed(callable $change): string {
		$document = new \DOMDocument();
		$document->preserveWhiteSpace = false;
		$document->loadXML(self::SCORE);
		$change(new \DOMXPath($document));
		$root = $document->documentElement;
		self::assertNotNull($root);
		$doctype = $document->doctype;
		return ($doctype !== null ? $document->saveXML($doctype) . "\n" : '') . $document->saveXML($root);
	}

	private static function node(\DOMXPath $xpath, string $query): \DOMElement {
		$node = $xpath->query($query)?->item(0);
		self::assertInstanceOf(\DOMElement::class, $node, $query);
		return $node;
	}

	public function testAPitchChange(): void {
		$result = MusicXmlCorrection::apply(self::SCORE, self::changed(function (\DOMXPath $x): void {
			self::node($x, '//part[@id="P2"]/measure[@number="1"]/note/pitch/step')->textContent = 'D';
		}));
		self::assertSame([['measure' => '1', 'part' => 'Bass', 'staff' => '1', 'voice' => '1', 'kind' => 'pitch', 'verse' => null, 'old' => 'C3', 'new' => 'D3']],
			$result['changes']);
		self::assertStringContainsString("<step>D</step>\n          <octave>3</octave>", $result['xml']);
		self::assertStringContainsString('<!-- made for a test -->', $result['xml'], 'the stored document is kept');
		self::assertStringContainsString('<?xml version="1.0" encoding="UTF-8" standalone="no"?>', $result['xml']);
		self::assertStringContainsString('<!DOCTYPE score-partwise PUBLIC', $result['xml']);
		self::assertSame(1, substr_count($result['xml'], '<step>D</step>'));
	}

	public function testAlterationsAndAccidentals(): void {
		$result = MusicXmlCorrection::apply(self::SCORE, self::changed(function (\DOMXPath $x): void {
			// E4 → Eb4 with a visible flat
			$pitch = self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[1]/pitch');
			$alter = $pitch->ownerDocument?->createElement('alter', '-1');
			self::assertNotNull($alter);
			$pitch->insertBefore($alter, self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[1]/pitch/octave'));
			$note = self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[1]');
			$accidental = $note->ownerDocument?->createElement('accidental', 'flat');
			self::assertNotNull($accidental);
			$accidental->setAttribute('cautionary', 'yes');
			$note->appendChild($accidental);
			// F#4 → F4 without accidental
			$second = self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[2]/pitch/alter');
			$second->parentNode?->removeChild($second);
			$sharp = self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[2]/accidental');
			$sharp->parentNode?->removeChild($sharp);
		}));
		self::assertSame(['pitch E4 Eb4', 'accidental  flat', 'pitch F#4 F4', 'accidental sharp '],
			array_map(fn (array $c): string => $c['kind'] . ' ' . $c['old'] . ' ' . $c['new'], $result['changes']));
		self::assertSame('Tenor I/II', $result['changes'][0]['part']);
		$document = new \DOMDocument();
		$document->loadXML($result['xml']);
		$x = new \DOMXPath($document);
		$first = self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[1]');
		self::assertSame(['pitch', 'duration', 'voice', 'type', 'accidental', 'stem', 'lyric', 'lyric'], self::childNames($first),
			'the accidental is placed where the schema wants it');
		self::assertSame(['step', 'alter', 'octave'], self::childNames(self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[1]/pitch')));
		self::assertSame('yes', self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[1]/accidental')->getAttribute('cautionary'));
		self::assertSame(['step', 'octave'], self::childNames(self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[2]/pitch')));
		self::assertSame(['pitch', 'duration', 'voice', 'type', 'stem', 'lyric'], self::childNames(self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[2]')));
	}

	public function testASyllable(): void {
		$result = MusicXmlCorrection::apply(self::SCORE, self::changed(function (\DOMXPath $x): void {
			self::node($x, '//lyric[@number="2"]/text')->textContent = 'Klang';
		}));
		self::assertSame([['measure' => '1', 'part' => 'Tenor I/II', 'staff' => '1', 'voice' => '1', 'kind' => 'lyric', 'verse' => '2', 'old' => 'Sang', 'new' => 'Klang']],
			$result['changes']);
		self::assertStringContainsString('<text>Klang</text>', $result['xml']);
	}

	/**
	 * @return iterable<string, array{0: callable(\DOMXPath): void, 1: string}>
	 */
	public static function refused(): iterable {
		$generic = 'This correction changes more than pitches, accidentals and syllables (measure %s).';
		yield 'a longer note' => [function (\DOMXPath $x): void {
			self::node($x, '//part[@id="P2"]/measure[@number="1"]/note/duration')->textContent = '8';
		}, 'The length of measure %s has changed.'];
		yield 'another note value with the same duration' => [function (\DOMXPath $x): void {
			self::node($x, '//part[@id="P2"]/measure[@number="1"]/note/type')->textContent = 'quarter';
		}, $generic];
		yield 'a note added' => [function (\DOMXPath $x): void {
			$note = self::node($x, '//part[@id="P2"]/measure[@number="2"]/note');
			$copy = $note->cloneNode(true);
			$chord = $note->ownerDocument?->createElement('chord');
			self::assertInstanceOf(\DOMElement::class, $copy);
			self::assertNotNull($chord);
			$copy->insertBefore($chord, $copy->firstChild);
			$note->parentNode?->appendChild($copy);
		}, $generic];
		yield 'a note removed (the measure keeps its length)' => [function (\DOMXPath $x): void {
			$note = self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[3]');
			$note->parentNode?->removeChild($note);
		}, $generic];
		yield 'a rest made shorter' => [function (\DOMXPath $x): void {
			self::node($x, '//part[@id="P1"]/measure[@number="2"]/note/duration')->textContent = '2';
		}, 'The length of measure %s has changed.'];
		yield 'a measure removed' => [function (\DOMXPath $x): void {
			foreach (['P1', 'P2'] as $part) {
				$measure = self::node($x, '//part[@id="' . $part . '"]/measure[@number="2"]');
				$measure->parentNode?->removeChild($measure);
			}
		}, $generic];
		yield 'a measure renumbered' => [function (\DOMXPath $x): void {
			self::node($x, '//part[@id="P2"]/measure[@number="2"]')->setAttribute('number', '3');
		}, $generic];
		yield 'an attribute of a note' => [function (\DOMXPath $x): void {
			self::node($x, '//part[@id="P2"]/measure[@number="1"]/note')->setAttribute('print-object', 'no');
		}, $generic];
		yield 'a stem direction' => [function (\DOMXPath $x): void {
			self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[1]/stem')->textContent = 'down';
		}, $generic];
		yield 'a part name' => [function (\DOMXPath $x): void {
			self::node($x, '//score-part[@id="P2"]/part-name')->textContent = 'Bariton';
		}, $generic];
		yield 'a step that is no step' => [function (\DOMXPath $x): void {
			self::node($x, '//part[@id="P2"]/measure[@number="1"]/note/pitch/step')->textContent = 'H';
		}, $generic];
		yield 'an octave out of range' => [function (\DOMXPath $x): void {
			self::node($x, '//part[@id="P2"]/measure[@number="1"]/note/pitch/octave')->textContent = '12';
		}, $generic];
		yield 'an alteration out of range' => [function (\DOMXPath $x): void {
			self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[2]/pitch/alter')->textContent = '3';
		}, $generic];
		yield 'an unknown accidental' => [function (\DOMXPath $x): void {
			self::node($x, '//part[@id="P1"]/measure[@number="1"]/note[2]/accidental')->textContent = 'slash-quarter-sharp<x>';
		}, $generic];
		yield 'markup in a syllable' => [function (\DOMXPath $x): void {
			$text = self::node($x, '//lyric[@number="2"]/text');
			$extra = $text->ownerDocument?->createElement('b', 'x');
			self::assertNotNull($extra);
			$text->appendChild($extra);
		}, $generic];
		yield 'a line break in a syllable' => [function (\DOMXPath $x): void {
			self::node($x, '//lyric[@number="2"]/text')->textContent = "Sang\nund Klang";
		}, $generic];
		yield 'a lyric syllabic' => [function (\DOMXPath $x): void {
			self::node($x, '//lyric[@number="1"]/syllabic')->textContent = 'single';
		}, $generic];
		yield 'a pitch on a rest' => [function (\DOMXPath $x): void {
			$rest = self::node($x, '//part[@id="P1"]/measure[@number="2"]/note/rest');
			$pitch = $rest->ownerDocument?->createElement('pitch');
			self::assertNotNull($pitch);
			$rest->parentNode?->replaceChild($pitch, $rest);
		}, $generic];
		yield 'nothing changed' => [function (\DOMXPath $x): void {
		}, 'The correction contains no change.'];
	}

	/**
	 * @param callable(\DOMXPath): void $change
	 */
	#[DataProvider('refused')]
	public function testRefused(callable $change, string $message): void {
		try {
			MusicXmlCorrection::apply(self::SCORE, self::changed($change));
			self::fail('refused: ' . $message);
		} catch (InvalidInputException $e) {
			self::assertSame($message, $e->getMessage());
		}
	}

	public function testOnlyMusicXmlWithoutAnInternalSubsetIsRead(): void {
		$evil = str_replace('"http://www.musicxml.org/dtds/partwise.dtd">', '"x" [<!ENTITY e "x">]>', self::SCORE);
		foreach ([$evil, '<mei/>', '', 'no xml'] as $content) {
			try {
				MusicXmlCorrection::apply(self::SCORE, $content);
				self::fail('refused');
			} catch (InvalidInputException $e) {
				self::assertSame('This file is not a MusicXML score.', $e->getMessage());
			}
		}
	}

	public function testPitchText(): void {
		self::assertSame('F#4', MusicXmlCorrection::pitchText('F', '1', '4'));
		self::assertSame('Bbb3', MusicXmlCorrection::pitchText('B', '-2', '3'));
		self::assertSame('C4', MusicXmlCorrection::pitchText('C', null, '4'));
		self::assertSame('C+0.5' . '4', MusicXmlCorrection::pitchText('C', '0.5', '4'));
	}

	/**
	 * @return list<string>
	 */
	private static function childNames(\DOMElement $element): array {
		$names = [];
		foreach ($element->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				$names[] = $child->nodeName;
			}
		}
		return $names;
	}
}
