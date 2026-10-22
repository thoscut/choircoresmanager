# Security policy

## Supported versions

The app is in its 0.x series and no release has been published yet. Security
fixes go into the `main` branch and into the next release; once releases are
published, only the latest release receives fixes. Please update to it
before you report an issue.

| Version | Security fixes |
|---|---|
| `main` | yes |
| latest 0.x release | yes |
| older releases | no |

## Reporting a vulnerability

Please report security issues **privately** through
[GitHub Security Advisories](https://github.com/thoscut/choircoresmanager/security/advisories/new)
("Report a vulnerability" in the Security tab of the repository), not in
public issues, pull requests or discussions.

Please include:

- the affected version of the app and of Nextcloud,
- what an attacker can do and which role or account they need,
- steps to reproduce, ideally with the public demo data from `tools/demo`
  instead of real data.

You will receive an answer within a week. Fixes are released as soon as
possible and mentioned in the changelog.

## Design notes

### Access

- Every API request checks the caller's role on the server; the user
  interface only hides what the server would refuse anyway. There are no
  public pages: every route needs a logged-in Nextcloud account.
- Only instance settings (role groups, archive folder, repertoire
  thresholds) are restricted to admins by Nextcloud itself. Admins write
  catalog data only if they also hold a role; restoring a backup into an
  empty catalog is the one exception (after a reinstall).
- Each field has its write roles in one registry (`lib/Service/Fields.php`);
  unknown fields are refused, read-only fields (decisions such as a rights
  confirmation, the valid version or the portal release) can only be set
  through their own workflow, never through a generic update. Requests that
  create tasks, links or accessions also refuse unknown fields.
- Write requests require Nextcloud's CSRF token. Only plain GET downloads
  skip it (app page, CSV export, full backup, song order, the CSV files of the
  statistics and the annual report, the calendar files of concerts); they do
  not change state. Adding concerts to a calendar keeps the check.
- A unit test (`tests/Unit/Controller/ControllerSecurityTest`) checks these
  route attributes, so that a new route cannot silently become public, skip
  the CSRF check or lose its admin restriction.

### Files

- Files are read through the requesting user's own view of Nextcloud; the app
  never grants access to files a user could not open otherwise. Background
  jobs read through the account that configured the archive folder and only
  write into `_notenarchiv-app/` inside it.
- Score files reach the browser only if they are linked to a copy, pass the
  rights policy (internal preservation copies: never played, viewed only by
  catalog librarian and conductor) and are real MusicXML: plain XML with the
  root `score-partwise` or `score-timewise`, or an `.mxl` archive in which
  every file the viewer could pick is such a document. Document type
  declarations with an internal subset are refused, because they let parsers
  disagree about a document. The response is an attachment with
  `X-Content-Type-Options: nosniff` and Nextcloud's strict policy for data
  responses (`default-src 'none'`), so it is never rendered on the Nextcloud
  origin.
- Uploaded files for imports are limited to 20 MB, backups for a restore to
  64 MB, score files to 32 MB; the content is read with that limit, not only
  checked against the size in the file cache.
- Spreadsheets (`.xlsx`) are parsed without document type declarations
  (no entities, no external loading, no network) and with limits against ZIP
  bombs: at most 10,000 entries, 16 MB unpacked per part, 48 MB in total,
  16,384 columns and one million cells.

### Backups

- A restore only writes the app's own tables, only columns that exist in the
  installed schema (column names from the file are never used as SQL
  identifiers unchecked), and checks every value against the type, length and
  nullability of its column. It runs in one transaction; the check that the
  catalog is empty is part of that transaction. JSON nesting is limited.
- The complete backup (download and the weekly file) contains the whole
  catalog including the audit log, tasks and the register of persons. Every
  role holder may download it, as they may read all of it in the app anyway.
  Share the archive folder (which holds the weekly backups in
  `_notenarchiv-app/`) only with people who may read this.

### Exports and formula injection

- CSV files meant only for people (song order, snapshot of the register of
  persons) prefix cells starting with `=`, `+`, `-`, `@`, tab or carriage
  return with an apostrophe, so that spreadsheet programs do not evaluate
  them.
- The catalog CSV (legacy and extended format) keeps every cell unchanged on
  purpose: it must survive an import followed by an export byte for byte, and
  the extended format is a backup that is imported again. Its cells come from
  the catalog, which only librarians write. Open it in a spreadsheet program
  with the import dialog (columns as text, formulas not evaluated) or use the
  ODS file instead: the ODS export writes every cell as a text cell and is
  safe by construction.

### Links, limits and errors

- Excerpt links carry no rights of their own: rights, expiry and revocation
  are checked on every call; calls are counted without recording who made
  them. Tokens are 32 alphanumeric characters from Nextcloud's secure random
  generator. Unknown tokens count as failed attempts for Nextcloud's
  brute-force protection.
- Expensive endpoints (imports, file sync, exports, backups, restore, practice
  audio, statistics, calendar files and entries) are rate limited per user;
  Nextcloud answers with HTTP 429 when a limit is reached.
- Clients only see translated messages meant for users; internal errors are
  answered with a generic message and logged by Nextcloud. Notifications
  contain no personal data and no internal error texts; their links only lead
  into the app.

### Browser

- The app page allows WebAssembly compilation (`'wasm-unsafe-eval'`) for the
  bundled notation engine and nothing else beyond Nextcloud's default policy:
  no JavaScript `eval`, no external sources.
- No external requests: notation, fonts and sounds are bundled.
- The audit log is append-only; there is no API to change or delete entries.
