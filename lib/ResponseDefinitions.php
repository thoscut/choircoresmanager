<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv;

/**
 * Types of the API for agents, version 1 (OCS, `/ocs/v2.php/apps/notenarchiv/api/v1/…`,
 * see docs/api-v1.md). The OpenAPI description (openapi.json) is generated
 * from them and from the controllers ApiV1*Controller. The class itself is
 * never used; the controllers import its types.
 *
 * Rules of version 1: field names are snake_case; dates are YYYY-MM-DD,
 * points in time ISO 8601 in UTC; a value that is not known is null, never
 * left out. Fields are only ever added; nothing is renamed, removed or given
 * another type within v1.
 *
 * @psalm-type NotenarchivFieldValues = array<string, string|int|bool|null>
 *
 * @psalm-type NotenarchivApiStatus = array{
 *     api_version: int,
 *     roles: list<string>,
 *     modules: array<string, bool>,
 *     permissions: array{
 *         create_tasks: bool,
 *         answer_tasks_for: list<string>,
 *         propose: bool,
 *         view_preservation_copies: bool,
 *         submit_transcription_reports: bool,
 *     },
 *     proposable_fields: array<string, list<string>>,
 *     task_options: array{
 *         kinds: list<string>,
 *         addressees: list<string>,
 *         categories: list<string>,
 *     },
 * }
 *
 * @psalm-type NotenarchivCatalogRow = array{
 *     id: int,
 *     shelfmark: string,
 *     binder: int,
 *     number: int,
 *     status: string,
 *     work_id: ?int,
 *     version_id: ?int,
 *     title: ?string,
 *     composer: ?string,
 *     work_no: ?string,
 *     arranger_text: ?string,
 *     rights_status: ?string,
 *     rights_confirmed: bool,
 *     preservation_copy: bool,
 *     valid_for_choir: bool,
 *     repertoire_class: ?string,
 *     performances: ?int,
 *     last_performed_on: ?string,
 *     has_musicxml: bool,
 *     files: int,
 * }
 *
 * @psalm-type NotenarchivCatalogPage = array{
 *     items: list<NotenarchivCatalogRow>,
 *     total: int,
 *     next_cursor: ?string,
 * }
 *
 * @psalm-type NotenarchivRights = array{
 *     status: ?string,
 *     confirmed: bool,
 *     confirmed_on: ?string,
 *     preservation_copy: bool,
 *     valid_for_choir: bool,
 *     valid_since: ?string,
 *     publication_status: ?string,
 *     portal: array{allowed: bool, reason: ?string, on_portal: bool},
 *     print_station: array{allowed: bool, reason: ?string},
 * }
 *
 * @psalm-type NotenarchivScore = array{
 *     file_id: int,
 *     file_name: string,
 *     can_view: bool,
 *     can_play: bool,
 *     reason: ?string,
 * }
 *
 * @psalm-type NotenarchivFile = array{
 *     id: int,
 *     name: string,
 *     path: string,
 *     type: ?string,
 *     role: ?string,
 *     found: bool,
 * }
 *
 * @psalm-type NotenarchivCopyRef = array{
 *     id: int,
 *     shelfmark: string,
 *     version_id: int,
 *     status: string,
 * }
 *
 * @psalm-type NotenarchivTask = array{
 *     id: int,
 *     kind: string,
 *     status: string,
 *     copy_id: int,
 *     shelfmark: ?string,
 *     title: ?string,
 *     addressee: string,
 *     category: ?string,
 *     page: ?int,
 *     measure_from: ?string,
 *     measure_to: ?string,
 *     voice: ?string,
 *     verse: ?int,
 *     origin: ?string,
 *     question: ?string,
 *     answer: ?string,
 *     due: ?string,
 *     closing_note: ?string,
 *     created_at: string,
 *     answered_at: ?string,
 *     closed_at: ?string,
 *     own: bool,
 * }
 *
 * @psalm-type NotenarchivTaskPage = array{
 *     items: list<NotenarchivTask>,
 *     total: int,
 *     next_cursor: ?string,
 * }
 *
 * @psalm-type NotenarchivProposal = array{
 *     id: int,
 *     level: string,
 *     object_id: int,
 *     field: string,
 *     value: string|int|float|bool|null,
 *     reason: ?string,
 *     source: ?string,
 *     status: string,
 *     decision_reason: ?string,
 *     created_at: string,
 *     decided_at: ?string,
 *     own: bool,
 * }
 *
 * @psalm-type NotenarchivProposalPage = array{
 *     items: list<NotenarchivProposal>,
 *     total: int,
 *     next_cursor: ?string,
 * }
 *
 * @psalm-type NotenarchivCopy = array{
 *     id: int,
 *     shelfmark: string,
 *     binder: int,
 *     number: int,
 *     status: string,
 *     work_id: int,
 *     version_id: int,
 *     title: ?string,
 *     composer: ?string,
 *     fields: array{
 *         work: NotenarchivFieldValues,
 *         version: NotenarchivFieldValues,
 *         copy: NotenarchivFieldValues,
 *     },
 *     rights: NotenarchivRights,
 *     score: ?NotenarchivScore,
 *     files: list<NotenarchivFile>,
 *     other_copies: list<NotenarchivCopyRef>,
 *     tasks: list<NotenarchivTask>,
 *     open_proposals: list<NotenarchivProposal>,
 * }
 *
 * @psalm-type NotenarchivTextReference = array{
 *     text: string,
 *     shelfmark: string,
 *     title: ?string,
 * }
 *
 * @psalm-type NotenarchivDue = array{
 *     cards: list<array{key: string, count: int, label: string}>,
 *     concerts: list<array{id: int, title: string, date: string, stage: string, label: string}>,
 * }
 *
 * @psalm-type NotenarchivConcertRow = array{
 *     id: int,
 *     kind: string,
 *     title: ?string,
 *     date: ?string,
 *     start_time: ?string,
 *     end_time: ?string,
 *     time_zone: ?string,
 *     location: ?string,
 *     status: string,
 *     imported: bool,
 *     pieces: int,
 * }
 *
 * @psalm-type NotenarchivConcertPage = array{
 *     items: list<NotenarchivConcertRow>,
 *     total: int,
 *     next_cursor: ?string,
 * }
 *
 * @psalm-type NotenarchivProgrammeItem = array{
 *     id: int,
 *     position: int,
 *     copy_id: ?int,
 *     version_id: ?int,
 *     work_id: ?int,
 *     shelfmark: ?string,
 *     title: ?string,
 *     composer: ?string,
 *     arranger_text: ?string,
 *     duration: ?int,
 *     encore: bool,
 *     struck: bool,
 *     assignment: string,
 *     note: ?string,
 *     warnings: list<string>,
 * }
 *
 * @psalm-type NotenarchivConcert = array{
 *     id: int,
 *     kind: string,
 *     title: ?string,
 *     date: ?string,
 *     start_time: ?string,
 *     end_time: ?string,
 *     time_zone: ?string,
 *     location: ?string,
 *     status: string,
 *     imported: bool,
 *     is_public: bool,
 *     notes: ?string,
 *     items: list<NotenarchivProgrammeItem>,
 *     total_duration: int,
 *     missing_durations: int,
 * }
 *
 * @psalm-type NotenarchivStatistics = array{
 *     period: array{from: int, to: int},
 *     today: string,
 *     stock: array{
 *         works: int,
 *         versions: int,
 *         copies: int,
 *         active: int,
 *         active_with_file: int,
 *         active_with_musicxml: int,
 *         preservation_versions: int,
 *         preservation_copies: int,
 *         status: array<string, int>,
 *     },
 *     rights: array{
 *         status: list<array{status: string, confirmed: int, unconfirmed: int, total: int}>,
 *         valid_versions: array{works: int, with_valid: int, without_valid: int, current_without_valid: int},
 *         licenses: array{total: int, active: int, ending: int, ended: int, future: int},
 *         portal: array<string, int>,
 *         print_station: array<string, int>,
 *         publication: array<string, int>,
 *     },
 *     repertoire: array{
 *         classes: array<string, int>,
 *         years: list<array{year: int, concerts: int, planned: int, pieces: int, works: int}>,
 *         top_works: list<array{work_id: int, title: ?string, composer: ?string, performances: int, last: string}>,
 *         top_composers: list<array{composer: string, performances: int, works: int}>,
 *     },
 *     maintenance: array{
 *         tasks: list<array{addressee: string, open: int, answered: int}>,
 *         proposals: array{open: int, older_than_30_days: int},
 *     },
 * }
 *
 * @psalm-type NotenarchivTranscriptionReport = array{
 *     id: int,
 *     copy_id: int,
 *     version_id: int,
 *     source: string,
 *     sha256: string,
 *     report_file_id: ?int,
 *     round: ?string,
 *     report_date: ?string,
 *     title: ?string,
 *     basename: string,
 *     method: ?string,
 *     musicxml_file_id: ?int,
 *     musicxml_sha256: ?string,
 *     origin: ?string,
 *     review: ?string,
 *     reasoning: ?string,
 *     rights_class: ?string,
 *     warnings: list<string>,
 *     superseded_by: ?int,
 *     created_at: string,
 *     own: bool,
 * }
 *
 * @psalm-type NotenarchivReviewPoint = array{
 *     id: int,
 *     copy_id: int,
 *     version_id: int,
 *     report_id: ?int,
 *     file_id: ?int,
 *     file_sha256: ?string,
 *     category: string,
 *     measures: ?string,
 *     measure_from: ?string,
 *     measure_to: ?string,
 *     voice: ?string,
 *     verse: ?int,
 *     page: ?int,
 *     text: ?string,
 *     role: string,
 *     status: string,
 *     correction: ?string,
 *     decision_reason: ?string,
 *     task_id: ?int,
 *     resolved_report_id: ?int,
 *     created_at: string,
 *     decided_at: ?string,
 *     own: bool,
 * }
 *
 * @psalm-type NotenarchivReviewCounts = array{
 *     open: int,
 *     confirmed: int,
 *     correct: int,
 *     corrected: int,
 *     question: int,
 *     dismissed: int,
 * }
 *
 * @psalm-type NotenarchivReview = array{
 *     copy_id: int,
 *     shelfmark: string,
 *     version_id: int,
 *     counts: NotenarchivReviewCounts,
 *     reports: list<NotenarchivTranscriptionReport>,
 *     items: list<NotenarchivReviewPoint>,
 *     total: int,
 *     next_cursor: ?string,
 * }
 *
 * @psalm-type NotenarchivCorrectionOrder = array{
 *     id: int,
 *     copy_id: int,
 *     version_id: int,
 *     shelfmark: ?string,
 *     title: ?string,
 *     report_id: ?int,
 *     basename: ?string,
 *     file_id: ?int,
 *     file_name: ?string,
 *     file_sha256: ?string,
 *     category: string,
 *     measures: ?string,
 *     measure_from: ?string,
 *     measure_to: ?string,
 *     voice: ?string,
 *     verse: ?int,
 *     page: ?int,
 *     text: ?string,
 *     role: string,
 *     correction: ?string,
 *     decision_reason: ?string,
 *     decided_at: ?string,
 *     own: bool,
 * }
 *
 * @psalm-type NotenarchivCorrectionOrderPage = array{
 *     items: list<NotenarchivCorrectionOrder>,
 *     total: int,
 *     next_cursor: ?string,
 * }
 *
 * @psalm-type NotenarchivReportResult = array{
 *     report: NotenarchivTranscriptionReport,
 *     unchanged: bool,
 *     points: array{created: int, kept: int, closed: int, open: int},
 *     proposals: int,
 *     proposals_skipped: bool,
 *     warnings: list<string>,
 * }
 *
 * @psalm-type NotenarchivRevisionChange = array{
 *     measure: string,
 *     part: string,
 *     kind: string,
 *     verse: ?string,
 *     old: string,
 *     new: string,
 * }
 *
 * @psalm-type NotenarchivRevision = array{
 *     id: int,
 *     number: int,
 *     source: string,
 *     copy_id: ?int,
 *     file_id: int,
 *     file_name: string,
 *     sha256: ?string,
 *     measures: ?string,
 *     voices: ?string,
 *     note: ?string,
 *     changes: list<NotenarchivRevisionChange>,
 *     current: bool,
 *     valid_since: ?string,
 *     created_at: string,
 *     own: bool,
 * }
 *
 * @psalm-type NotenarchivRevisions = array{
 *     copy_id: int,
 *     shelfmark: string,
 *     version_id: int,
 *     current_number: ?int,
 *     items: list<NotenarchivRevision>,
 * }
 *
 * @psalm-suppress UnusedClass
 */
class ResponseDefinitions {
}
