<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service\ApiV1;

use OCA\Cantoria\Db\ReviewPoint;
use OCA\Cantoria\Db\TranscriptionReport;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\Roles;

/**
 * Reports of transcriptions, review points and correction orders in the
 * API for agents (v1). Account names are left out (`decided_by`,
 * `decided_by_name`, `created_by`); `own` tells whether the calling account
 * handed in the report or the point came from its report. The received
 * text of a report (`raw`) and the internal key of a point never leave the
 * server.
 *
 * @psalm-import-type Warning from \OCA\Cantoria\Service\ImportService
 * @psalm-import-type CantoriaTranscriptionReport from \OCA\Cantoria\ResponseDefinitions
 * @psalm-import-type CantoriaReviewPoint from \OCA\Cantoria\ResponseDefinitions
 * @psalm-import-type CantoriaReviewCounts from \OCA\Cantoria\ResponseDefinitions
 * @psalm-import-type CantoriaCorrectionOrder from \OCA\Cantoria\ResponseDefinitions
 * @psalm-import-type CantoriaReportResult from \OCA\Cantoria\ResponseDefinitions
 * @psalm-type Translate = callable(string, list<string|int>): string
 */
final class ReviewView {
	/** Roles a review point can belong to */
	public const ROLES = [Roles::CONDUCTOR, Roles::LIBRARIAN];

	/**
	 * @throws InvalidInputException for an unknown status
	 */
	public static function status(?string $status): ?string {
		if ($status !== null && !in_array($status, ReviewPoint::STATUSES, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Status']);
		}
		return $status;
	}

	/**
	 * @throws InvalidInputException for a role no point belongs to
	 */
	public static function role(?string $role): ?string {
		if ($role !== null && !in_array($role, self::ROLES, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Role']);
		}
		return $role;
	}

	/**
	 * @param Translate $translate translates a message with its parameters
	 * @return CantoriaTranscriptionReport
	 */
	public static function report(TranscriptionReport $report, string $uid, callable $translate): array {
		$summary = $report->summaryData();
		return [
			'id' => $report->getId(),
			'copy_id' => $report->getCopyId(),
			'version_id' => $report->getVersionId(),
			'source' => $report->getSource(),
			'sha256' => $report->getSha256(),
			'report_file_id' => $report->getReportFileId(),
			'round' => $report->getRound(),
			'report_date' => $report->getReportDate(),
			'title' => isset($summary['title']) && is_string($summary['title']) ? $summary['title'] : null,
			'basename' => $report->getBasename(),
			'method' => $report->getMethod(),
			'musicxml_file_id' => $report->getMusicxmlId(),
			'musicxml_sha256' => $report->getMusicxmlSha256(),
			'origin' => $report->getOrigin(),
			'review' => $report->getReview(),
			'reasoning' => $report->getReasoning(),
			'rights_class' => $report->getRightsClass(),
			'warnings' => self::translated($report->summaryWarnings(), $translate),
			'superseded_by' => $report->getSupersededBy(),
			'created_at' => Values::time($report->getCreated()) ?? '',
			'own' => $report->getCreatedBy() === $uid,
		];
	}

	/**
	 * @return CantoriaReviewPoint
	 */
	public static function point(ReviewPoint $point, string $uid): array {
		return [
			'id' => $point->getId(),
			'copy_id' => $point->getCopyId(),
			'version_id' => $point->getVersionId(),
			'report_id' => $point->getReportId(),
			'file_id' => $point->getFileId(),
			'file_sha256' => $point->getFileSha256(),
			'category' => $point->getCategory(),
			'measures' => $point->getMeasures(),
			'measure_from' => $point->getMeasureFrom(),
			'measure_to' => $point->getMeasureTo(),
			'voice' => $point->getVoice(),
			'verse' => $point->getVerse(),
			'page' => $point->getPage(),
			'text' => $point->getText(),
			'role' => $point->getRole(),
			'status' => $point->getStatus(),
			'correction' => $point->getCorrection(),
			'decision_reason' => $point->getDecisionReason(),
			'task_id' => $point->getTaskId(),
			'resolved_report_id' => $point->getResolvedReportId(),
			'created_at' => Values::time($point->getCreated()) ?? '',
			'decided_at' => Values::time($point->getDecided()),
			'own' => $point->getCreatedBy() === $uid,
		];
	}

	/**
	 * Number of points per status, every status named.
	 *
	 * @param list<ReviewPoint> $points
	 * @return CantoriaReviewCounts
	 */
	public static function counts(array $points): array {
		$counts = ['open' => 0, 'confirmed' => 0, 'correct' => 0, 'corrected' => 0, 'question' => 0, 'dismissed' => 0];
		foreach ($points as $point) {
			$status = $point->getStatus();
			if (array_key_exists($status, $counts)) {
				$counts[$status]++;
			}
		}
		return $counts;
	}

	/**
	 * @param array<string, mixed> $row an order as ReviewService::orders() lists it (point columns plus context)
	 * @return CantoriaCorrectionOrder
	 */
	public static function order(array $row, string $uid): array {
		return [
			'id' => Values::int($row['id'] ?? null),
			'copy_id' => Values::int($row['copy_id'] ?? null),
			'version_id' => Values::int($row['version_id'] ?? null),
			'shelfmark' => Values::stringOrNull($row['shelfmark'] ?? null),
			'title' => Values::stringOrNull($row['title'] ?? null),
			'report_id' => Values::intOrNull($row['report_id'] ?? null),
			'basename' => Values::stringOrNull($row['basename'] ?? null),
			'file_id' => Values::intOrNull($row['file_id'] ?? null),
			'file_name' => Values::stringOrNull($row['file_name'] ?? null),
			'file_sha256' => Values::stringOrNull($row['file_sha256'] ?? null),
			'category' => Values::string($row['category'] ?? null),
			'measures' => Values::stringOrNull($row['measures'] ?? null),
			'measure_from' => Values::stringOrNull($row['measure_from'] ?? null),
			'measure_to' => Values::stringOrNull($row['measure_to'] ?? null),
			'voice' => Values::stringOrNull($row['voice'] ?? null),
			'verse' => Values::intOrNull($row['verse'] ?? null),
			'page' => Values::intOrNull($row['page'] ?? null),
			'text' => Values::stringOrNull($row['text'] ?? null),
			'role' => Values::string($row['role'] ?? null),
			'correction' => Values::stringOrNull($row['correction'] ?? null),
			'decision_reason' => Values::stringOrNull($row['decision_reason'] ?? null),
			'decided_at' => Values::time($row['decided'] ?? null),
			'own' => ($row['created_by'] ?? null) === $uid,
		];
	}

	/**
	 * The answer to a report handed in.
	 *
	 * @param array{report: TranscriptionReport, unchanged: bool, points: array{created: int, kept: int, closed: int, open: int},
	 *     proposals: int, proposalsSkipped: bool, warnings: list<Warning>} $result as ReviewService::ingestForCopy() returns it
	 * @param Translate $translate
	 * @return CantoriaReportResult
	 */
	public static function result(array $result, string $uid, callable $translate): array {
		return [
			'report' => self::report($result['report'], $uid, $translate),
			'unchanged' => $result['unchanged'],
			'points' => $result['points'],
			'proposals' => $result['proposals'],
			'proposals_skipped' => $result['proposalsSkipped'],
			'warnings' => self::translated($result['warnings'], $translate),
		];
	}

	/**
	 * @param list<Warning> $warnings
	 * @param Translate $translate
	 * @return list<string>
	 */
	private static function translated(array $warnings, callable $translate): array {
		return array_map(fn (array $warning): string => $translate($warning[0], $warning[1]), $warnings);
	}
}
