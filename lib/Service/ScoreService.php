<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Exception\NotSharedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\ITempManager;

/**
 * Provides the MusicXML score of a copy for the score viewer.
 *
 * The file is read through the requesting user's own view of Nextcloud, so
 * the user must have access to the file there as well; the rights policy is
 * checked on top of that.
 */
class ScoreService {
	public const MIME_TYPES = [
		'mxl' => 'application/vnd.recordare.musicxml',
		'musicxml' => 'application/vnd.recordare.musicxml+xml',
		'xml' => 'application/xml',
	];

	/** Largest score file sent to the viewer, also per file inside an .mxl (bytes) */
	public const MAX_BYTES = 32 * 1024 * 1024;
	private const MAX_ZIP_ENTRIES = 1000;

	public function __construct(
		private ArchiveFileMapper $files,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private RightsPolicy $policy,
		private IRootFolder $rootFolder,
		private ITempManager $tempManager,
		private Modules $modules,
		private ArchiveAccess $archiveAccess,
	) {
	}

	/**
	 * The score to show for a copy, with the permissions of the user.
	 *
	 * `exportReason` explains why practice audio files cannot be made while
	 * playback is allowed (null otherwise; `reason` covers the rest).
	 * Playback and practice audio of a switched-off module are not allowed;
	 * the rights policy applies in any case.
	 *
	 * @param list<ArchiveFile> $files files of the copy
	 * @return array{fileId: int, fileName: string, canView: bool, canPlay: bool, reason: ?string, canExportAudio: bool, exportReason: ?string}|null
	 */
	public function describe(WorkVersion $version, array $files, string $uid, ?Copy $copy = null): ?array {
		$file = self::pick($files);
		if ($file === null) {
			return null;
		}
		$viewDenial = $this->policy->viewDenial($version, $uid, $copy);
		$playDenial = $this->policy->playDenial($version, $uid, $copy)
			?? ($this->modules->isEnabled('playback') ? null : Modules::PLAYBACK_OFF);
		$exportDenial = $playDenial ?? ($this->policy->practiceAudioDenial($version, $uid, $copy)
			?? ($this->modules->isEnabled('practice_audio') ? null : Modules::PRACTICE_AUDIO_OFF));
		return [
			'fileId' => $file->getId(),
			'fileName' => $file->getName(),
			'canView' => $viewDenial === null,
			'canPlay' => $playDenial === null,
			'reason' => $viewDenial ?? $playDenial,
			'canExportAudio' => $exportDenial === null,
			'exportReason' => $playDenial === null ? $exportDenial : null,
		];
	}

	/**
	 * Content of a score file, after checking access and rights.
	 *
	 * @return array{content: string, mime: string, name: string, type: string} `type` is a key of MIME_TYPES
	 */
	public function content(int $archiveFileId, string $uid): array {
		try {
			$file = $this->files->find($archiveFileId);
			$copyId = $file->getCopyId();
			if ($copyId === null) {
				throw new DoesNotExistException('The file belongs to no copy');
			}
			$copy = $this->copies->find($copyId);
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		$type = (string)$file->getType();
		if ($file->getRole() !== 'musicxml' || !isset(self::MIME_TYPES[$type])) {
			throw new InvalidInputException('This file is not a MusicXML score.');
		}
		$denial = $this->policy->viewDenial($version, $uid, $copy);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($file->getFileId());
		if (!$node instanceof File) {
			if (!$this->archiveAccess->canOpen($uid)) {
				// The usual case: the archive folder is not shared with the account.
				throw new NotSharedException('The file lies in a folder that is not shared with your account. Please ask the music librarians to share the archive folder with you or your group.');
			}
			throw new AccessDeniedException('The file is not accessible for this account.');
		}
		$content = UserFiles::content($node, self::MAX_BYTES, new AccessDeniedException('The file is not accessible for this account.'));
		if (!$this->isScore($content)) {
			throw new InvalidInputException('This file is not a MusicXML score.');
		}
		return [
			'content' => $content,
			'mime' => self::MIME_TYPES[$type],
			'name' => $file->getName(),
			'type' => $type,
		];
	}

	/**
	 * Whether the content is a MusicXML score the way the viewer reads it:
	 * plain XML (UTF-8 or UTF-16) whose root element is score-partwise or
	 * score-timewise, or a compressed archive (.mxl) in which every file the
	 * viewer could pick as the score is such a document.
	 *
	 * The viewer renders the score as SVG inside the page. This check is the
	 * server's line of defence against files that only pretend to be
	 * MusicXML: a document type declaration with an internal subset is never
	 * needed for MusicXML and lets parsers disagree about a document (one
	 * reads MusicXML, the renderer reads another notation format whose
	 * embedded SVG would reach the page), so it is refused.
	 */
	public function isScore(string $content): bool {
		if (!str_starts_with($content, "PK\x03\x04")) {
			return self::isScoreXml($content);
		}
		$zip = BoundedZip::open($this->tempManager, $content, self::MAX_ZIP_ENTRIES, self::MAX_BYTES, 2 * self::MAX_BYTES,
			UserFiles::tooLarge(self::MAX_BYTES), new InvalidInputException('This file is not a MusicXML score.'));
		if ($zip === null) {
			return false;
		}
		try {
			$names = $zip->names();
			if (count($names) !== count(array_unique($names))) {
				return false;
			}
			// The viewer takes a root file named in the container, or else the
			// first XML file outside META-INF: all of them must be scores. Every
			// root file named in the container must exist under that name, so
			// that a name read differently here than in the browser cannot hide
			// a file from this check.
			$rootFiles = [];
			$container = $zip->part('META-INF/container.xml');
			if ($container !== null && preg_match_all('/full-path\s*=\s*(["\'])(.*?)\1/s', $container, $matches) > 0) {
				foreach ($matches[2] as $path) {
					$rootFiles[] = html_entity_decode($path, ENT_QUOTES | ENT_XML1, 'UTF-8');
				}
			}
			$xmlFiles = array_filter($names, fn (string $name): bool => !str_starts_with($name, 'META-INF/')
				&& preg_match('/\.(musicxml|xml)$/i', $name) === 1);
			$scores = 0;
			foreach (array_unique([...$rootFiles, ...$xmlFiles]) as $name) {
				$data = $zip->part($name);
				if ($data === null) {
					return false;
				}
				// Further root files may be e.g. a PDF; the viewer cannot read those as a score anyway.
				if (!self::looksLikeXml($data)) {
					continue;
				}
				if (!self::isScoreXml($data)) {
					return false;
				}
				$scores++;
			}
			return $scores > 0;
		} finally {
			$zip->close();
		}
	}

	/**
	 * The MusicXML text of a score file the way the viewer reads it: the file
	 * itself, or in a compressed archive (.mxl) the root file named in
	 * META-INF/container.xml (a MusicXML one first), otherwise the first
	 * .musicxml or .xml file outside META-INF. Check the content with
	 * isScore() first.
	 *
	 * @return array{xml: string, entry: ?string} `entry`: name of the root file inside an archive
	 * @throws InvalidInputException
	 */
	public function scoreText(string $content): array {
		if (!str_starts_with($content, "PK\x03\x04")) {
			return ['xml' => $content, 'entry' => null];
		}
		$notScore = new InvalidInputException('This file is not a MusicXML score.');
		$zip = BoundedZip::open($this->tempManager, $content, self::MAX_ZIP_ENTRIES, self::MAX_BYTES, 2 * self::MAX_BYTES,
			UserFiles::tooLarge(self::MAX_BYTES), $notScore);
		if ($zip === null) {
			throw $notScore;
		}
		try {
			$names = $zip->names();
			$container = $zip->part('META-INF/container.xml');
			$entry = $container === null ? null : self::rootFile($container);
			if ($entry === null || !in_array($entry, $names, true)) {
				$entry = null;
				foreach ($names as $name) {
					if (!str_starts_with($name, 'META-INF/') && preg_match('/\.(musicxml|xml)$/i', $name) === 1) {
						$entry = $name;
						break;
					}
				}
			}
			$xml = $entry === null ? null : $zip->part($entry);
			if ($entry === null || $xml === null) {
				throw $notScore;
			}
			return ['xml' => $xml, 'entry' => $entry];
		} finally {
			$zip->close();
		}
	}

	/**
	 * The root file of an archive as the viewer chooses it: the first one
	 * whose media type is empty or names MusicXML, otherwise the first one.
	 */
	private static function rootFile(string $container): ?string {
		if (preg_match_all('/<rootfile\b([^>]*)>/i', $container, $tags) < 1) {
			return null;
		}
		$attribute = function (string $attributes, string $name): ?string {
			if (preg_match('/\b' . $name . '\s*=\s*(["\'])(.*?)\1/s', $attributes, $m) !== 1) {
				return null;
			}
			return html_entity_decode($m[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
		};
		$first = null;
		foreach ($tags[1] as $attributes) {
			$path = $attribute($attributes, 'full-path');
			if ($path === null) {
				continue;
			}
			$first ??= $path;
			$type = $attribute($attributes, 'media-type') ?? '';
			if ($type === '' || str_contains($type, 'musicxml')) {
				return $path;
			}
		}
		return $first;
	}

	private static function looksLikeXml(string $data): bool {
		if (str_starts_with($data, "\xFF\xFE") || str_starts_with($data, "\xFE\xFF")) {
			return true;
		}
		if (str_starts_with($data, "\xEF\xBB\xBF")) {
			$data = substr($data, 3);
		}
		return str_starts_with(ltrim($data), '<');
	}

	private static function isScoreXml(string $xml): bool {
		if ($xml === '') {
			return false;
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try {
			// No LIBXML_NOENT and no DTD loading: entities are not expanded and
			// nothing external is fetched.
			$loaded = $document->loadXML($xml, LIBXML_NONET);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		if (!$loaded) {
			return false;
		}
		$doctype = $document->doctype;
		if ($doctype !== null && trim((string)$doctype->internalSubset) !== '') {
			return false;
		}
		$root = $document->documentElement;
		return $root !== null && $root->namespaceURI === null && in_array($root->nodeName, ['score-partwise', 'score-timewise'], true);
	}

	/**
	 * The score file to show among files: prefers the file marked as valid
	 * MusicXML, then compressed MusicXML.
	 *
	 * @param list<ArchiveFile> $files
	 */
	public static function pick(array $files): ?ArchiveFile {
		$candidates = array_values(array_filter($files,
			fn (ArchiveFile $f): bool => $f->getRole() === 'musicxml' && $f->getFound() !== false && isset(self::MIME_TYPES[(string)$f->getType()])));
		usort($candidates, fn (ArchiveFile $a, ArchiveFile $b): int => [$b->getMusicxmlValid() === true, $b->getType() === 'mxl', $a->getName()]
			<=> [$a->getMusicxmlValid() === true, $a->getType() === 'mxl', $b->getName()]);
		return $candidates[0] ?? null;
	}
}
