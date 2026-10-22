# Changelog

All notable changes to this app are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the app uses
[semantic versioning](https://semver.org/).

## [Unreleased]

First public release candidate. Developed and tested against Nextcloud 33.0.9
and 35.0.2 with MariaDB; the app requires MySQL or MariaDB.

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
