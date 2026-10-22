<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
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
  the CSRF check or lose its admin restriction; for the API for agents also
  its modules and rate limit.

### Interface for agents

The API for agents (OCS, `/ocs/v2.php/apps/notenarchiv/api/v1/…`,
[docs/api-v1.md](docs/api-v1.md)) and the MCP server in `tools/mcp` follow
these rules:

- **Off unless switched on.** The module "Interface for agents"
  (`agent_api`) is off on new and updated installations and part of no
  preset; only an administrator switches it on. Switched off, every route
  answers 404 before the controller runs.
- **No right beyond the roles.** The routes call the same services as the
  user interface, with the same role checks; each route also needs the
  module of its function. An agent signs in with an app password of an
  account of its own; the documentation asks for the roles "contributor" or
  "reader" only.
- **Nothing decides.** There are no routes to confirm rights, change
  licenses, set the valid version, accept or reject proposals, close tasks,
  delete, restore, back up or change settings. Writing creates proposals
  (taken as a whole or not at all) and tasks, or answers a task addressed
  to an own role. A unit test lists every route of the API and checks its
  modules, role requirement, rate limit and CSRF handling.
- **CSRF.** The routes keep Nextcloud's CSRF check; an OCS request passes it
  only with the header `OCS-APIRequest: true` (or a Bearer token), which a
  foreign web page cannot send with the cookies of a logged-in browser. No
  route allows CORS.
- **Data minimisation.** Answers contain no account names (who confirmed or
  chose something) and no file contents. Internal preservation copies are
  left out of the search for roles that may not view them and refused
  (403) as a copy, text reference or target of a task.
- **Limits.** Every route is rate limited per account (30 to 300 requests
  in 10 minutes); internal errors are logged and answered with a generic
  message.
- **MCP server.** It runs locally over stdio (no listening port), needs
  https (plain http only for localhost), does not follow redirects, never
  logs the app password and replaces it in every output; a read-only mode
  offers no writing tools and refuses writing requests before they are
  sent. Catalog texts may contain instructions written to mislead an agent
  (prompt injection); what such an agent can do is limited by its role, and
  people decide on every proposal.

Note that Nextcloud lets any client with an app password and the header
`OCS-APIRequest: true` call the internal API of the user interface as well
(with the same role checks). The module therefore does not widen what an
account can do; it offers a stable, documented and limited contract.

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
