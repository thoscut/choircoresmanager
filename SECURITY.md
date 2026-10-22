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
  interface only hides what the server would refuse anyway. Every route
  needs a logged-in Nextcloud account, except the two public pages of the
  sharing modules (a public link and the public repertoire page, see below),
  which are off unless switched on.
- Only instance settings (role groups, archive folder, modules, repertoire
  thresholds, the access check of the archive folder, the target folder of
  the print station and the retention period of the folder book) are restricted by
  Nextcloud itself: to admins and to the groups the administration has given
  the page "Administration → Music archive" under Administration privileges
  (`#[AuthorizedAdminSetting]`, checked again by the app). That gives no
  role, and the settings give nothing to Nextcloud's generic app config API.
  Admins write catalog data only if they also hold a role; restoring a
  backup into an empty catalog is the one exception (after a reinstall). It
  stays with admins and the catalog role and cannot be delegated.
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
  the CSRF check or lose its restriction to the settings; for the API for agents also
  its modules and rate limit; for the sharing modules the list of public
  routes and the list of routes a choir member reaches.

### Sharing beyond the archive roles (milestone 0.7)

Four modules open parts of the archive to people without an archive role:
links for choir members (`member_links`), their practice audio
(`member_practice_audio`), public links (`public_links`) and the public
repertoire page (`public_repertoire`). They are off on new and updated
installations and part of no preset, not even "complete"; only their own
switch turns them on. Switched off, their routes answer 404 and the
navigation leaves them out.

**The role "Choir members" (`member`).**

- It is no archive role: `Roles::rolesOf()` and `Roles::hasAccess()` leave
  it out, so every route that checks access (`requireAccess`,
  `requireRole`, the rights policy) refuses a choir member as it refuses any
  account without a role. The navigation entry and the app page appear for
  choir members only while `member_links` is on, and show only "Shared with
  me".
- A choir member reaches exactly the app page and the two routes of
  `MemberController` (the measures of a link, permission for a practice
  file); the unit test keeps that list and checks that no other controller
  serves choir members. An account with an archive role always gets the
  archive.
- They see title and composer of the work, the measures, voice, verse,
  tempo, note and expiry of each link; no shelf marks, no account names, no
  catalog. Every link for choir members is visible to every choir member,
  and so is its note.

**Only the excerpt leaves the server.**

- Choir members and the public never get the score file. The server reads
  it through the account that configured the archive folder, only inside
  that folder (as the background jobs do), checks that it is real MusicXML
  as for the score viewer, unpacks `.mxl`, cuts the measures of the link
  (`MusicXmlExcerpt`, parsed without entity expansion or network access,
  document type declaration dropped) and sends only them.
- For choir members the excerpt is an attachment with `nosniff` and the
  strict policy of data responses; on a public page it is part of the page
  itself, so that there is no second public route.

**Rights on every call.** A link carries no rights of its own. When it is
made and on every call the server checks:

- for choir members the release for the digital provision to members (the
  singers' portal: confirmed public domain or a valid license for the
  portal);
- for the public the release for publication (publication status "free",
  set by the catalog librarian) and confirmed public domain or a valid
  license with the use "online publication";
- never an internal preservation copy;
- playback and practice audio as in the archive (public domain or a license
  for practice audio; practice audio files additionally a confirmed status
  or that license). Every practice file of a choir member is recorded in the
  audit log with account, role `member`, measures, voice, mode and format.

A version that loses its release disappears from "Shared with me" and its
public links stop working at once.

**The public pages.**

- Exactly two routes are public (`#[PublicPage]`), each the only route of
  its own controller: `GET /apps/notenarchiv/p/{token}` and
  `GET /apps/notenarchiv/repertoire`. Both are plain GET pages, limited per
  address (60 in 10 minutes) and per account, protected by Nextcloud's
  brute-force protection and need their module; the unit test checks this.
- Tokens are 32 alphanumeric characters from Nextcloud's secure random
  generator. An unknown, malformed, expired or revoked token, a version no
  longer released and a switched-off module all get the same 404 page
  without a reason, and each counts as a failed attempt.
- A public link shows no account names, no note, no download and no
  practice audio; the page is marked `noindex` for search engines and has
  the policy of the app page (WebAssembly for the bundled notation engine,
  no JavaScript `eval`, no external sources, no frames).
- The repertoire page has no script of its own and Nextcloud's default
  policy. It shows title, composer and lyricist with their life dates,
  scoring and links to CPDL and IMSLP: only https links to `cpdl.org` and
  `imslp.org`, checked when they are saved and again when the page is
  rendered; nothing is loaded from there. Works are listed only while a
  version is confirmed public domain, checked on every call. No shelf marks,
  no copies, no accounts.

**What remains.**

- Whatever reaches a browser can be saved: the measures of an excerpt are
  in the page or the response. Legally an excerpt counts as much as the
  whole sheet, which is why only released versions are shared.
- Links can be passed on. They expire after at most 183 days and can be
  revoked at any time by their creator or the catalog librarian; calls are
  counted without recording who made them.
- The time a 404 takes may tell whether the module is on; which works are
  public is no secret.
- A note on a link for choir members is read by all choir members: it is
  no place for internal remarks.

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
- The mirror folder for the print station (module `print_mirror`, part of
  no preset) is the one place outside the archive folder where the app
  writes. An administrator chooses the target folder; the server refuses the
  archive folder and every folder inside it (also reached through shares),
  checks this again before every sync and writes as the account that chose
  the folder. It copies only the PDF scans of the versions on the list of
  the print station (the same rule as the list, never internal preservation
  copies). It replaces or removes only files listed in its manifest whose
  SHA-256 is still the one it wrote; every other file in the folder stays.
  Every write and removal is in the audit log. Whoever can open the target
  folder can read the scans: it must be shared only with the print station.
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
- The rights notice of a MusicXML file (`<identification><rights>`) is read
  by the file sync with the same size limits, without loading anything and
  without expanding entities; documents with an internal DTD subset are not
  read. The notice is shown as unchecked text (Vue escapes it) and never sets
  a rights status; at most it becomes a proposal.
- Revisions of a version (module `version_states`) are records with a
  checksum; the app copies no file for them. The file sync hashes score
  files through the syncing account, as the checksum job does. Comparing two
  revisions reads both files through the existing score route with the
  requesting user's own access and the rights policy, and parses them in
  the browser with the score view's own XML parser; nothing new reaches the
  browser. Only the catalog librarian sets the current revision (rate
  limited, audited); the API for agents only reads revisions, without
  account names.
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
  catalog including the audit log, tasks, the register of persons and the
  folder book. The roles that read all of it in the app (catalog,
  operations, conductor, read only) and admins may download it; the role
  "Proposals (tools)" may not, as it does not read the folder book. Share the
  archive folder (which holds the weekly backups in `_notenarchiv-app/`)
  only with people who may read this.

### Folder book and personal data

- The folder book (module `folder_book`) is the only part of the app that
  stores personal data of choir members: the name or the Nextcloud account
  of the person who has a choir folder, and notes about the loan. Only the
  catalog and operations roles write it, conductor and read only read it;
  every route checks the role on the server. Contributors, admins without a
  role and any other role (for example choir members) get 403 and do not
  see its entries in the audit log.
- After a retention period set by the administration (default 12 months
  after the return) the daily background job removes name, account and
  notes of the loan, in the loan and in its own entries of the audit log,
  also while the module is switched off. This is the only change the app
  ever makes to the audit log; it notes the removal without the name.
- Backups made before still contain the names; the administration guide
  asks to keep them no longer than the retention period.
  catalog including the audit log, tasks and the register of persons. Every
  role holder may download it, as they may read all of it in the app anyway.
  Share the archive folder (which holds the weekly backups in
  `_notenarchiv-app/`) only with people who may read this.
- Annual snapshots (module `annual_snapshot`) hold the same complete backup
  plus a list of checksums and paths of the archive files, in
  `_notenarchiv-app/annual-snapshots/`. Only the catalog librarian makes one
  (rate limited, the job as the account that set the archive folder); the
  app never overwrites or deletes them. A comparison reads them through the
  requesting account's own view of the archive folder, so it grants no
  access to files the account could not open otherwise.

### Search of Nextcloud

- The search provider (module `unified_search`) answers only accounts with a
  role of the catalog, named one by one, and admins; a role added later
  finds nothing until it is added on purpose. Internal preservation copies
  are left out for roles that may not view them, as in the API for agents.
- Results come in pages of at most 50; each account may search 300 times in
  10 minutes. Results contain shelf mark, title, composer, arranger text and
  status, no account names and no file contents.

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

- Excerpt links (of every circle) carry no rights of their own: rights,
  expiry and revocation are checked on every call; calls are counted without recording who made
  them. Tokens are 32 alphanumeric characters from Nextcloud's secure random
  generator. Unknown tokens count as failed attempts for Nextcloud's
  brute-force protection.
- Expensive endpoints (imports, file sync, exports, backups, restore, practice
  audio, statistics, calendar files and entries, the preview and sync of the
  print station) and the writing routes of the folder book are rate limited
  per user;
  Nextcloud answers with HTTP 429 when a limit is reached.
- Clients only see translated messages meant for users; internal errors are
  answered with a generic message and logged by Nextcloud. Notifications
  contain no personal data and no internal error texts; their links only lead
  into the app.

### Browser

- The app page, the page "Shared with me" and the page of a public link
  allow WebAssembly compilation (`'wasm-unsafe-eval'`) for the bundled
  notation engine and nothing else beyond Nextcloud's default policy: no
  JavaScript `eval`, no external sources.
- No external requests: notation, fonts and sounds are bundled.
- The audit log is append-only; there is no API to change or delete entries.
  The only change is the removal of personal data of the folder book after
  its retention period, done by the background job, never through a route.
