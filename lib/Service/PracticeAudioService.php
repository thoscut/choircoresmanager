<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Gatekeeper for practice audio files. The score view renders WAV or MIDI
 * files in the browser; before it does, it asks here. The server checks the
 * rights policy (see RightsPolicy::practiceAudioDenial) and records the
 * export in the audit log: measures, voice, mode and format. The files
 * themselves never pass through the server. The librarian or conductor
 * uploads them to the choir's practice system; with the module
 * member_practice_audio (milestone 0.7) choir members also make files of
 * one voice of an excerpt shared with them (authorizeForMember).
 */
class PracticeAudioService {
	public const ACTION = 'practice_audio';
	public const FORMATS = ['wav', 'midi'];
	/** all equal, focus voice louder, focus voice only (as in the score view) */
	public const MODES = ['all', 'focus', 'solo'];
	/** Tempo in percent of the score's tempo, as the score view offers it */
	public const TEMPO_MIN = 25;
	public const TEMPO_MAX = 200;
	/** Fields a request may contain */
	private const FIELDS = ['copy_id', 'measure_from', 'measure_to', 'voice', 'mode', 'format', 'all_voices', 'tempo', 'repeats'];
	/**
	 * Fields a request of a choir member may contain: the copy comes from the
	 * link, and so do the measures of an excerpt; for a whole piece the
	 * request names the measures of the file
	 */
	private const MEMBER_FIELDS = ['voice', 'mode', 'format', 'tempo', 'repeats', 'measure_from', 'measure_to'];

	public function __construct(
		private WorkVersionMapper $versions,
		private CopyMapper $copies,
		private RightsPolicy $policy,
		private AuditLog $audit,
		private Roles $roles,
		private SharedLinkService $shared,
		private RightsService $rights,
	) {
	}

	/**
	 * Allows one export (one file, or one ZIP with a file per voice) and
	 * records it, or refuses it with the reason.
	 *
	 * @param array<array-key, mixed> $data request data, see docs/api.md
	 * @return array{ok: true}
	 * @throws AccessDeniedException when the rights policy refuses the export
	 * @throws InvalidInputException for unknown versions or invalid request data
	 */
	public function authorize(int $versionId, array $data, string $uid): array {
		$this->roles->requireAccess($uid);
		try {
			$version = $this->versions->find($versionId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		$denial = $this->policy->practiceAudioDenial($version, $uid);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		$request = self::request($data);
		try {
			$copy = $this->copies->find($request['copy_id']);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if ($copy->getVersionId() !== $versionId) {
			throw new InvalidInputException('The copy does not belong to this version.');
		}
		$this->record($versionId, $copy->getShelfmark(), $request, $uid);
		return ['ok' => true];
	}

	/**
	 * Allows a choir member one file of one voice of an excerpt or a whole
	 * piece shared with the choir members, and records it like every export:
	 * the copy is that of the link, and so are the measures of an excerpt,
	 * whatever the request says; for a whole piece the request names the
	 * measures of the file (all of them, or the part chosen in the view).
	 *
	 * @param array<array-key, mixed> $data voice, mode, format, tempo, repeats, measure_from, measure_to (see docs/api.md)
	 * @return array{ok: true}
	 * @throws AccessDeniedException when the account is no choir member or the rights refuse the export
	 * @throws InvalidInputException for an unknown, revoked or expired link or invalid request data
	 */
	public function authorizeForMember(string $token, array $data, string $uid): array {
		['link' => $link, 'copy' => $copy, 'version' => $version] = $this->shared->memberLink($token, $uid);
		$denial = $this->policy->memberPracticeAudioDenial($version, $this->rights->today($uid));
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		$values = Input::values($data);
		foreach (array_keys($values) as $field) {
			if (!in_array($field, self::MEMBER_FIELDS, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
		}
		// An excerpt: its measures, whatever was asked. The whole piece: the
		// measures of the file, as the view sends them.
		$from = $link->getMeasureFrom();
		$to = $link->getMeasureTo();
		$fixed = ['copy_id' => $copy->getId(), 'all_voices' => false]
			+ ($from === null || $to === null ? [] : ['measure_from' => $from, 'measure_to' => $to]);
		$request = self::request($fixed + $values);
		$this->record($version->getId(), $copy->getShelfmark(), $request, $uid);
		return ['ok' => true];
	}

	/**
	 * Records an allowed export in the audit log.
	 *
	 * @param array{copy_id: int, measure_from: string, measure_to: string, voice: ?string, mode: string, format: string, all_voices: bool, tempo: int, repeats: bool} $request
	 */
	private function record(int $versionId, string $shelfmark, array $request, string $uid): void {
		$this->audit->record($this->audit->newOperation(), $uid, Fields::VERSION, $versionId, self::ACTION, null, null, [
			'copy' => $shelfmark,
			'measures' => $request['measure_from'] . '-' . $request['measure_to'],
			'voice' => $request['all_voices'] ? '*' : $request['voice'],
			'mode' => $request['mode'],
			'format' => $request['format'],
			'tempo' => $request['tempo'],
			'repeats' => $request['repeats'],
		]);
	}

	/**
	 * Checks the request data.
	 *
	 * @param array<array-key, mixed> $data
	 * @return array{copy_id: int, measure_from: string, measure_to: string, voice: ?string, mode: string, format: string, all_voices: bool, tempo: int, repeats: bool}
	 * @throws InvalidInputException
	 */
	public static function request(array $data): array {
		$values = Input::values($data);
		foreach (array_keys($values) as $field) {
			if (!in_array($field, self::FIELDS, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
		}
		$copyId = filter_var($values['copy_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($copyId === false) {
			throw new InvalidInputException('"%s": value not allowed.', ['Copy']);
		}
		$measures = [];
		foreach (['measure_from', 'measure_to'] as $field) {
			$value = $values[$field] ?? null;
			if (!is_string($value) && !is_int($value)) {
				throw new InvalidInputException('Please give the first and the last measure.');
			}
			if (preg_match('/^[0-9A-Za-z.]{1,16}$/', (string)$value) !== 1) {
				throw new InvalidInputException('Measure numbers may only contain digits, letters and dots.');
			}
			$measures[$field] = (string)$value;
		}
		$voice = Input::text($values['voice'] ?? null, 'Voice');
		if ($voice !== null) {
			$voice = trim($voice);
			if (mb_strlen($voice) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $voice) === 1) {
				throw new InvalidInputException('"%s": value not allowed.', ['Voice']);
			}
		}
		$mode = $values['mode'] ?? 'all';
		if (!in_array($mode, self::MODES, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Mode']);
		}
		$format = $values['format'] ?? null;
		if (!in_array($format, self::FORMATS, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Format']);
		}
		$allVoices = Input::bool($values['all_voices'] ?? false, 'All voices');
		if ($allVoices && $mode === 'all') {
			throw new InvalidInputException('A file for every voice needs the mode "focus voice louder" or "focus voice only".');
		}
		if (!$allVoices && $mode !== 'all' && $voice === null) {
			throw new InvalidInputException('Please choose the voice to emphasise.');
		}
		$tempo = filter_var($values['tempo'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::TEMPO_MIN, 'max_range' => self::TEMPO_MAX]]);
		if ($tempo === false) {
			throw new InvalidInputException('"%s": value not allowed.', ['Tempo']);
		}
		return [
			'copy_id' => $copyId,
			'measure_from' => $measures['measure_from'],
			'measure_to' => $measures['measure_to'],
			// all voices equal: no focus voice, whatever was sent
			'voice' => $allVoices || $mode === 'all' ? null : $voice,
			'mode' => $mode,
			'format' => $format,
			'all_voices' => $allVoices,
			'tempo' => $tempo,
			'repeats' => Input::bool($values['repeats'] ?? true, 'Repeats'),
		];
	}
}
