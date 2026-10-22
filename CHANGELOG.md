<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog

All notable changes to this app are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the app uses
[semantic versioning](https://semver.org/).

## [Unreleased]

## [0.6.0] - 2026-10-22

Milestones 0.1 to 0.6 of the roadmap; not yet published in the app store.
Developed and tested against Nextcloud 33.0.9 and 35.0.2 with MariaDB; the
app requires MySQL or MariaDB. The internal builds before this version were
numbered 0.1.x.

### Added

- Catalog with works, versions and copies (shelf marks), field registry with
  per-field write roles, audit log of every change, status instead of deletion.
- Import of an existing catalog from CSV (dry run, report, snapshot, repeated
  imports that keep decisions taken in the app) and byte-exact export.
- File sync of the archive folder by shelf mark; files are only read.
- Roles bound to groups: catalog and operations librarian, conductor,
  contributor (proposals), read only; admins configure.
- Rights per version: recommendation kept, confirmation with reason and
  evidence, licenses with separate uses, three separate releases (singers'
  portal, print station, publication), preservation copies never shared or
  played, valid version chosen by the conductor only.
- Proposals inbox for tools and transcription rounds.
- Score viewer: MusicXML rendered with Verovio (bundled), printed bar ranges,
  voice focus also within a staff, playback with emphasised voice, own
  repeat/ending/D.C. handling, keyboard operation, live bar announcements and a
  text description of the excerpt.
- Questions and tasks with a location in the score, internal excerpt links
  (expiring, revocable, rights checked on every call, anonymous call count),
  text references, start page "due".
- Concerts and song lists with rights warnings and deadlines, confirmation of
  what was sung, derived repertoire figures, song order export, import of
  performance records and song lists from CSV or Excel.
- Maintaining the catalog: accession assistant with shelf mark suggestion and
  printable labels, register of persons with contributions per version and a
  protection-term suggestion with its reasoning, merging works and copies with
  undo, inventory lists per binder with counts, background checksums of the
  archive files with mismatch warnings.
- Statistics for board, archive management and conductor (`?view=statistics`):
  stock per binder, new copies per year, rights and releases, licenses,
  concerts and pieces per year, most performed works and composers,
  repertoire classes, works not sung for a long time, open tasks by age,
  checksums, protection terms ending; a printable annual report (A4, also in
  large print) and CSV downloads of both. No personal data of choir members.
- Licenses ending within 30 days or already ended, with the copies concerned;
  merges show who merged or undid them and what was moved.
- Notifications to role holders (never to singers).
- Weekly backup into the archive folder (CSV, ODS with audit log, complete JSON
  backup of all tables) and manual backups; restore of the complete backup
  into an empty catalog (UI and `occ notenarchiv:restore`), `occ
  notenarchiv:export --full`; daily checks for licenses, portal releases,
  concert deadlines and ended protection terms.
- File sync re-links files whose id changed (e.g. after a restore or a move
  back) by their path; manual links are kept.
- German translation in informal and formal address, inclusive wording.
- Demo archive of a fictitious choir (`tools/demo`, CC0) for trying out the
  app, screenshots and end-to-end tests.
- Unit tests (PHPUnit), integration tests against real Nextcloud instances,
  static analysis (Psalm), coding standard, CI workflow.
- CI on GitHub Actions also runs the integration tests with the demo data
  against fresh Nextcloud 33 and 35 instances with MariaDB.
- Practice files per voice from the score view (librarian and conductor): WAV
  rendered in the browser with the playback's synthesizer and playing order,
  or MIDI with one track per voice; one file or a ZIP with every voice plus a
  tutti. The server allows each export only for confirmed public domain or
  versions licensed for practice audio, never for preservation copies, and
  records it in the audit log (`POST /api/versions/{id}/practice-audio`).
- Dashboard widget "Music archive – due" for accounts with an archive role:
  the start page's concert deadlines, cards and tasks with links into the app
  (new start parameter `?due=` opens a card with its filters).
- Concerts in the calendar: optional start and end time of a concert,
  calendar files (iCalendar, RFC 5545) of a concert and of all upcoming
  concerts, "Add to my calendar" into a writable Nextcloud calendar of the
  account (adding again updates the entries instead of duplicating them),
  the preparation deadlines optionally as separate all-day entries. No public
  subscription link.
- Concerts can be cancelled and reopened; the start page lists concerts with
  a deadline now.
- Modules and presets (milestone 0.5.1): every function outside the core
  (catalog, files, roles, audit log, import, export, backup, settings) is a
  module that admins switch off in Settings → Modules or with
  `occ notenarchiv:modules list|enable|disable|preset`, grouped, with
  descriptions and dependencies (switching on also switches on what a module
  needs; switching off names what stops working). A switched-off module is
  off on the server, too: its routes answer 404 ("This function is switched
  off."), background jobs, notifications, start page cards and the dashboard
  widget leave it out. Data are never deleted and no rights check is relaxed.
  Notifications can be switched off per occasion. Presets "Simple",
  "Standard" (new installations) and "Complete"; installations updated from
  before 0.1.27 keep every function. The interface for agents starts off
  and is part of no preset.
- Interface for agents (milestone 0.6, first part): a stable, versioned API
  (OCS, `/ocs/v2.php/apps/notenarchiv/api/v1/…`, `docs/api-v1.md`) with its
  OpenAPI description `openapi.json` (generated with
  nextcloud/openapi-extractor, checked in CI, part of the release archive).
  Agents read the catalog (search with filters and cursor pages, a copy with
  version, work and rights), the due list of their roles, questions and
  tasks, concerts with their programmes and statistics, build text
  references, create and answer questions and tasks, and propose changes
  and read their own proposals. Nothing decides: there are no routes to
  confirm rights, change licenses, set the valid version, accept proposals,
  delete, restore, back up or change settings. Every route needs the module
  "Interface for agents" (`agent_api`, off, in no preset; "Complete" leaves
  it alone) and the module of its function, a role, and is rate limited; a
  switched-off route answers 404 with OCS meta. Answers use snake_case,
  ISO dates and contain no account names; internal preservation copies stay
  hidden from roles that may not view them.
- MCP server for agents (`tools/mcp`, TypeScript, official MCP SDK 1.32.1,
  stdio, own package not in the release archive) with one tool per use case
  (`search_catalog`, `get_copy`, `list_due`, `list_tasks`, `create_task`,
  `answer_task`, `propose_change`, `list_my_proposals`, `text_reference`,
  `list_concerts`, `get_concert`, `statistics`, `modules`), MCP annotations,
  a read-only mode and no secret in any output. CI runs an agent without a
  language model against the demo data (`tools/ci/integration-demo.sh`,
  step 7, PHP's built-in web server).
- A proposal request is checked as a whole before anything is stored: one
  field that cannot be proposed refuses the whole request.
- Personal view: every account hides navigation areas and start page cards
  it does not need ("Customize view", stored on the server); permissions do
  not change.
- Reviewing transcriptions (milestone 0.6, module `transcription_review`):
  the report of a transcription (`bericht.json`, format `bericht/1`) comes
  in through the file sync (`… - Bericht.json` next to the score, counted as
  a report, not as a score file) or `POST /api/versions/{id}/transcription-reports`.
  It is checked strictly (size, nesting, types, shelf mark and base name),
  kept unchanged as evidence with its checksum, and read only once.
  Uncertain readings, omitted marks, material findings and questions become
  review points with measures and voice, for the conductor (musical) or the
  catalog librarian (material); rights and catalog fields of the report go
  to the proposals inbox, the catalog never changes by itself. A new round
  closes the points it no longer names when the MusicXML has changed. In
  the score view a review panel lists the points; choosing one shows its
  measures with the voice highlighted and plays the passage (where playback
  is allowed). Decisions: reading is right, correction order for the tool
  chain (`GET /api/review/orders`), question (becomes a task with the
  location), dismissed; status instead of deletion, with decider, time and
  reason. Open review points per copy in the catalog with a filter;
  notifications "new review points" and "correction received".
- Small corrections in the app (module `corrections`): pitch, accidental and
  syllables of a measure range and voice, with a preview; the server
  compares old and new MusicXML element by element and saves only these
  changes as a new file next to the old one (`… (korrigiert 2026-10-22).mxl`)
  with the permissions of the person who corrects; the old file stays
  unchanged, the change list goes into the audit log
  (`POST /api/versions/{id}/corrections`).
- Scan beside the score: a linked PDF scan opens next to the score or in a
  second window at a page, in the browser's own PDF viewer
  (`GET /api/files/{id}/scan`).
- Demo data: two invented transcription reports (1-1, 2-1) with review
  points of every kind; integration test `review_test.php`.
- Review of transcriptions in the interface for agents (milestone 0.6
  complete): `GET /api/v1/copies/{id}/review` (reports without the received
  text, counts per status, review points with place, role and status in
  cursor pages), `GET /api/v1/correction_orders` (cursor pages) and
  `POST /api/v1/copies/{id}/transcription_reports` (201; 200 for the same
  report again; roles contributor and librarian, 30 per 10 minutes; the
  report must name the shelf mark of that copy), each needing `agent_api`
  and `transcription_review`; `GET /api/v1/modules` tells
  `submit_transcription_reports`. No route decides a review point or saves
  a correction; answers name no accounts (`own` instead of `created_by`,
  `decided_by`), internal preservation copies stay hidden from roles that
  may not view them. MCP tools `get_review`, `list_correction_orders` and
  `submit_transcription_report` (not offered in read-only mode). The CI
  agent reads the review of 1-1, hands in a new round of its demo report,
  and is refused when it tries to decide a point. The correction orders of
  the internal API name `preservation_copy`.
- Documentation with screenshots of the demo data: README, user and
  administration guides, German quick start, contributing guide, issue and
  pull request templates; a script recreates the screenshots
  (`tools/screenshots`).
- Licensing after the REUSE specification 3.3, as in Nextcloud's own apps:
  every file names its copyright and license in an SPDX header or in
  `REUSE.toml`, the license texts are in `LICENSES/` (also in the release
  archive), and CI checks it with `reuse lint`.
- Opening from the Files app (module "Opening from the Files app", on in
  Standard and Complete): files of the archive folder get the action "Open
  in music archive". A click on MusicXML opens the score view of its copy
  (unless another app opens MusicXML through Nextcloud's viewer), scans,
  MuseScore and audio files open their copy from the actions menu; PDFs keep
  Nextcloud's viewer. The small script (`@nextcloud/files` 4, Nextcloud 33
  to 35) is only loaded for accounts with access; the server answers which
  copy a file belongs to only for files the account can see
  (`GET /api/nextcloud-files/{fileId}/copy`, otherwise 404) and the score
  view checks the rights as always. New start parameter `?copy={id}&score=1`
  opens the score view of a copy.

### Fixed

Found in an end-to-end acceptance test in Nextcloud 33 (all roles, axe-core,
keyboard only, 320 px, 200 % zoom, text spacing, 2,000 copies):

- Every view has its own page title; Nextcloud showed "[object Object]".
- Nextcloud's own styles for tables and definition lists no longer squeeze
  the app: tables wrap and fit the content area, labels stay next to their
  values.
- The focus stays where the user works: after saving or cancelling a form,
  after the file picker, after imports, restores, merges, counts, answers and
  programme actions, and below sticky headers when tabbing backwards.
- Escape closes dialogs and the score view also while a form field has the
  focus.
- Field errors are marked as invalid and linked to their inputs; the first
  field with an error gets the focus.
- Valid navigation markup, accessible names of the start page cards that
  contain their visible text, no second banner landmark in the score view.
- The inbox from a link without a matching role shows the start page instead
  of an error; repertoire details no longer show "%n" for song lists.
- Large catalogs render in steps: clearing a search over 2,000 copies blocks
  the page for about 0.1 s instead of 0.7 s.
- The server refuses persons without a name (was an internal error) and
  planned programmes without a title.

Found in the end-to-end acceptance test of 0.1.20:

- Own app icon (folder with an eighth note) instead of a generic cloud.
- The focus voice in the score view no longer stands out by its color alone:
  bolder note heads and stems, the other voices with their marks in a lighter
  gray (still 4.5:1); the lyrics of its staff stay dark.
- Start page cards use singular and plural ("1 Proposal in the inbox").
- Buttons in the rights section name the action ("Take out of the singers'
  portal", "Set as no longer valid for the choir", "When in doubt: set to
  open"), not the resulting state.
- A link with an unknown copy (`?copy=`) says that the copy does not exist or
  is no longer available, and the parameter leaves the URL.
- Admins without a role: the inbox stays hidden (the server refuses it), and
  the note and the administration guide say exactly what they may read.
- `npm run stylelint` works (Nextcloud's shared configuration) and runs in CI;
  `package.json` has the app version again and the release script stops when
  it differs from `appinfo/info.xml`.
- `appinfo/info.xml` is valid against the app store schema again: the repair
  step after migrations (`post-migration`) comes before the one at
  installation (`install`), in the order the schema requires.

Found when checking the prints in Firefox 158 and the icon at small sizes:

- The printed inventory list fits the width of A4 with all its columns;
  Firefox cut off the last two, Chromium squeezed the note column.
- The app icon is drawn on a 16 px grid: folder and eighth note stay legible
  at 16 px.
- The label sheet asks to print without the browser's headers and footers,
  which Firefox adds by default.

### Security

- Every route checks the caller's role on the server; a unit test guards the
  route attributes (no public pages, CSRF skipped only for plain downloads).
- Score files are delivered only if they are real MusicXML, as attachments
  with `nosniff`; the rendered notation is sanitised before it is shown.
- Limits against oversized uploads, ZIP bombs and huge spreadsheets; no
  document type declarations in parsed XML.
- Restore checks every value against its column and runs entirely in one
  transaction.
- Brute-force protection for excerpt links, rate limits for expensive
  endpoints, formula injection neutralised in CSV files meant for people.
- The app page allows WebAssembly for the bundled notation engine and nothing
  else beyond Nextcloud's default policy.
- Vulnerabilities are reported privately through GitHub Security Advisories;
  `SECURITY.md` names the supported versions.
