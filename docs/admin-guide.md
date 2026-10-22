# Administration guide

This guide is for Nextcloud administrators who install and set up the
Notenarchiv app, and for the music librarians who take it over afterwards.
Daily work with the app is described in the [user guide](user-guide.md).

**Contents**

1. [Installation](#1-installation)
2. [Roles and groups](#2-roles-and-groups)
3. [Archive folder](#3-archive-folder)
4. [First import](#4-first-import)
5. [Backups and restore](#5-backups-and-restore)
6. [Notifications](#6-notifications)
7. [Dashboard widget and calendar](#7-dashboard-widget-and-calendar)
8. [Security settings of the instance](#8-security-settings-of-the-instance)
9. [Managed hosting](#9-managed-hosting)
10. [Data protection](#10-data-protection)
11. [Accessibility](#11-accessibility)
12. [Modules and presets](#12-modules-and-presets)
13. [Agents and the API](#13-agents-and-the-api)

## 1. Installation

### Requirements

- Nextcloud 33–35 and PHP 8.2 or newer.
- MySQL or MariaDB as Nextcloud's database. The app is tested only with
  these; Nextcloud refuses to install it on PostgreSQL, SQLite or Oracle.
- Recommended: the Notifications app and a working background job runner
  (system cron). Without cron, weekly backups, deadline reminders and checksum
  checks run only when the instance is used ("AJAX" mode) and may be late.
- For the calendar features: the **dav** app, which is part of Nextcloud.
  The Calendar app is only needed to look at the calendars.

### From the App Store

> [!NOTE]
> The app is not yet published in the Nextcloud App Store. Once it is, this
> is the only way on managed instances without shell access.

Apps → search "Notenarchiv" → **Download and enable**.

### From a release archive

1. Download `notenarchiv-<version>.tar.gz` from the
   [releases of the repository](https://github.com/thoscut/choircoresmanager/releases).
2. Unpack it into the `apps` folder of your Nextcloud, so that the app lies
   in `apps/notenarchiv`.
3. Enable it under Apps, or run `occ app:enable notenarchiv`.

### From the source code

The folder must be named after the app id, `notenarchiv`. Building needs
Node.js 24 and npm 11:

```sh
cd /path/to/nextcloud/apps
git clone https://github.com/thoscut/choircoresmanager.git notenarchiv
cd notenarchiv
npm ci
npm run build
occ app:enable notenarchiv
```

The app needs no Composer packages at runtime; `composer install` only
installs the development tools.

## 2. Roles and groups

Permissions are bound to **groups**, never to persons: a change of office is a
change of group membership.

1. Create the groups under Users, for example "Music librarians",
   "Conductor", "Board".
2. Open the app → **Settings** at the bottom of the navigation (admins only)
   → **Roles and groups**.
3. Choose the groups for each role and **Save roles**.

![The settings of the app: the archive folder with the button to choose it, and the roles, each with a short description and the groups assigned to it, for example "Music librarians" for the catalog role.](screenshots/settings-roles.png)

| Role (German interface) | Typical holder | May |
|---|---|---|
| Catalog (music librarian) – *Bestand (Archivverwaltung)* | the person who keeps the catalog | edit works, versions and rights; confirm rights; licenses and releases; imports, file sync, exports, backups and restore; accept proposals; close tasks; new accessions, persons, merges; everything operations may do with copies |
| Operations (music librarian) – *Betrieb (Archivverwaltung)* | the person who hands out the binders | status, kind and counts of copies; inventory |
| Conductor – *Chorleitung* | conductor and deputy | choose the valid version; key, scoring, language, duration and review of a transcription; answer questions; plan concerts and song lists |
| Proposals (tools) – *Vorschläge (Werkzeuge)* | transcription rounds, scripts | only propose changes, which a music librarian accepts; ask questions |
| Read only (board, auditors) – *Lesen (Vorstand, Revision)* | board, auditors | read everything including the audit log |

Concerts and song lists are planned by the conductor and the catalog role
together. Practice files are made by the catalog role and the conductor; only
these two may view internal preservation copies, and nobody plays them.

> [!IMPORTANT]
> Accounts without any role do not see the app; the server refuses every
> request. Admins without a role read the catalog, the lists and the
> programmes and change the settings. The proposals inbox and internal
> preservation copies stay closed to them, and they write catalog data only
> if they also hold a role. To do that, add yourself to a group with the role
> you need.

**Repertoire** in the same settings sets when a work counts as current
repertoire (sung within the last 3 years by default) and when as dormant
(within 10 years); earlier performances make it "earlier".

On self-hosted instances the roles can also be set with
`occ notenarchiv:setup <account> --role librarian=<group>,<group> --role conductor=<group> …`
(roles: `librarian`, `operations`, `conductor`, `contributor`, `reader`).

## 3. Archive folder

The archive folder holds the score files, for example one subfolder per
binder.

1. **Settings** → **Archive folder** → **Choose folder …**.
2. Choose the folder and **Save archive folder**.

It should be a group folder or a share that every role holder can open in
their own Nextcloud. The app reads files through the account of the person
who uses it, so everybody only sees files they may see anyway. Background
jobs (weekly backup, checksums) read through the account that chose the
folder. The app never changes, moves or deletes files; it only writes into
its own subfolder `_notenarchiv-app`, for exports, snapshots and backups.
The one exception is a small correction of a MusicXML in the score view
(module "Small corrections"): the app saves it as a **new file**
next to the old one, with the Nextcloud permissions of the person who
corrects (for example `5-01_Title (korrigiert 2026-10-22).mxl`); the old file
stays unchanged. Without write permission in that folder the app says so and
saves nothing.

File names start with the shelf mark: `5-01_Title_Composer.pdf` belongs to
copy 5-1 (leading zeros do not matter). Folders whose name starts with `_` or
`.` are skipped. A file whose name does not fit can be linked to a copy by
hand; such a link stays. A file with a shelf mark whose name ends with
`bericht.json` (for example `5-01_Title - Bericht.json`) is the report of a
transcription: the file sync counts it as a report, not as a score file, and
reads it into review points (module "Reviewing transcriptions").

With shell access: `occ notenarchiv:setup <account> <folder>` (the folder
relative to the account's files).

## 4. First import

An existing catalog is imported from a CSV file (UTF-8, separated by
semicolons) with the columns of the original catalog. Upload it to
Nextcloud first; an import file may be at most 20 MB.

1. **Archive management** (bottom of the navigation, music librarians) →
   **Import catalog** → **Choose CSV file …**. The app runs a **dry run**
   right away; nothing is stored yet.

   ![The report of a repeated dry run: "Dry run – nothing has been changed yet", 23 rows, no new or changed copies, 7 with MusicXML, 1 internal preservation copy, the distribution of rights status, repertoire and publication, and one warning.](screenshots/import-report.png)

   The picture shows the catalog file of the demo archive imported a second
   time: all 23 rows are unchanged, and the warning says that a value set
   in the app is kept.

2. Check the report: rows, new and changed copies, copies with MusicXML,
   internal preservation copies, the distribution of rights status,
   repertoire and publication, and warnings.
3. **Import now**. Before an import into a catalog that already holds data,
   the app stores a snapshot of the previous state in `_notenarchiv-app`.
4. **File sync** → **Run file sync** links the files of the archive folder to
   the copies.

   ![The report of the file sync: files with a shelf mark in the name, files linked to a copy, files by type, one file that could not be assigned and one copy without a file.](screenshots/file-sync-report.png)

   The report lists files whose shelf mark is not in the catalog, copies
   without a file and the skipped folders. Files are only read.
5. Optionally import, in the same dialog, **performances and song lists**
   (`.xlsx` or `.csv`; unclear lines can be assigned afterwards) and the
   **persons** (CSV research list of composers, lyricists and arrangers).
   Both start with a dry run as well.

> [!IMPORTANT]
> An import never takes the decisions that belong to people: it does not
> confirm rights and does not choose a valid version. Only restoring an
> extended export into an empty catalog takes over the decisions recorded in
> it.

A repeated import (while the CSV is still the leading source) matches copies
by shelf mark, updates changed values and keeps decisions taken in the app.
Copies missing from the CSV stay in the catalog; nothing is deleted.

To try the app first, [tools/demo](../tools/demo/README.md) builds the
invented archive of a fictitious choir with step-by-step instructions.

## 5. Backups and restore

Nextcloud is no backup: synchronisation also replicates deletions. The app
therefore writes, every week, into `_notenarchiv-app/`:

- `…_catalog_weekly.csv`: the catalog in the legacy column layout plus all
  fields, readable in any spreadsheet program
- `…_catalog_weekly.ods`: the same plus the audit log and the register of
  persons as further sheets
- `…_backup_weekly.json`: the **complete** backup of all tables (works,
  versions, copies, files, licenses, proposals, tasks, links, concerts and
  song lists with their programme lines, persons, contributions, merges,
  transcription reports and review points, audit log) for a lossless restore

![The backup sections of the archive management: storing a complete backup now, downloading the complete backup as JSON, and restoring a backup into an empty catalog.](screenshots/backup-restore.png)

In **Archive management**, **Store a complete backup now** writes the same
three files at once, for example before larger changes, and **Download
complete backup (JSON)** saves the backup on your computer.

> [!WARNING]
> The backups hold the complete catalog including the audit log (who changed
> what), tasks and the register of persons. Share the archive folder only with
> people who may read all of this, the role holders, and not for example with
> all singers.

The files in `_notenarchiv-app` are part of the archive folder and are
covered by your regular file backup of that folder. Keep yearly snapshots
that are never overwritten. Open the CSV files in a spreadsheet program
through its import dialog (all columns as text) rather than by double-click,
or use the ODS file: the catalog CSV keeps every cell exactly as stored, so a
cell starting with `=` could be read as a formula; the ODS file stores every
cell as text. See also [SECURITY.md](../SECURITY.md).

### Restore

A backup can only be restored into an **empty** catalog, for example after
reinstalling the app. Nothing existing is overwritten.

1. **Archive management** → **Complete backup and restore** → **Restore from
   a backup …** (admins find the same in **Settings**).
2. Choose a `…_backup_….json` file.
3. Confirm that it is the right and most recent backup and **Restore now**.

In the web interface a backup file may be at most 64 MB. On self-hosted
instances `occ notenarchiv:restore <file> [<account>]` reads the file from the
server's file system without that limit, and
`occ notenarchiv:export --full` writes a complete backup to standard output.
Every value of the backup is checked against the database schema before
anything is written; a refused restore changes nothing.

Without the app, the CSV and ODS files still contain everything needed to
continue working on paper or in a spreadsheet. If a weekly backup fails,
music librarians and admins get a notification.

## 6. Notifications

The app notifies the members of the groups of a role through Nextcloud's
notifications. A notification is withdrawn when the matter is resolved.

![The Nextcloud notifications menu with notifications of the music archive, for example a proposal waiting in the inbox.](screenshots/notifications.png)

| Notification | To | When |
|---|---|---|
| Proposals waiting in the inbox | catalog role | while open proposals exist (one notification with the count) |
| Version to take out of the singers' portal | catalog role | daily check: in the portal, but no longer released |
| License ending or ended | catalog role | daily check: ends within 30 days or has ended |
| New question or task | the addressed role | when it is created |
| Answer to apply | catalog role | when a task is answered |
| Concert deadline | conductor and catalog role | 10, 8 and 6 weeks before a planned concert |
| Confirm what was sung | conductor | after the date of the concert |
| Protection term ended | catalog role | daily check: marked protected, but public domain by the life dates |
| File changed without modification | catalog role | when the checksum job finds a deviation |
| Weekly backup failed | catalog role and admins | when the weekly backup fails |
| New review points | the role responsible: conductor (musical points) and/or catalog role (material, questions without a measure) | a transcription report brings review points; one notification per report and role, withdrawn when none of them is open any more |
| Correction received | catalog role (not the person who made it) | a correction was saved in the app, or a new round of a transcription closed review points |

Notifications never go to singers and contain no personal data. The deadline
notifications of a cancelled concert are withdrawn. Every user chooses in
their personal settings whether notifications also arrive by email and how
often (for example weekly).

## 7. Dashboard widget and calendar

### Dashboard widget

The dashboard widget **Music archive – due** shows the same as the start page
of the app: concert deadlines, the start page cards and the tasks for the
user's roles, each with a link into the app. Nextcloud offers it only to
accounts with an archive role; admins without a role do not get it. It
contains concert titles and dates, numbers and shelf marks with titles, never
the text of a question or a person's name.

### Calendar

Concerts can be added to the users' own Nextcloud calendars and downloaded as
calendar files (iCalendar). This uses the calendars of the **dav** app;
accounts without a writable calendar can still download the files.

- New entries are created through Nextcloud's calendar interface for apps.
  That interface cannot change existing entries, so an entry that is already
  in a calendar is updated by the user's browser with a normal CalDAV request
  in the user's own session (the same as the Calendar app does). Proxies or
  web application firewalls that block `PUT` requests to `/remote.php/dav/`
  prevent these updates; the app then reports the entries it could not save.
- Times of a concert refer to the Nextcloud time zone of the account that
  entered them (personal settings). Check that the planners' time zones are
  right.

> [!NOTE]
> There is deliberately **no public subscription link** (webcal feed with a
> token): it would be a new way to the concert data without logging in, and
> it is a decision for the choir (who may see concerts, how links are
> revoked).

## 8. Security settings of the instance

The app works with Nextcloud's defaults; nothing has to be relaxed:

- The app page allows WebAssembly (`'wasm-unsafe-eval'` in its content
  security policy) for the bundled notation engine; no other exception.
- The app makes no requests to other servers: notation, fonts and sounds are
  bundled.
- Imports, exports, backups, statistics, calendar files and the file sync are
  rate limited per user. Guessing excerpt links is slowed down by Nextcloud's
  brute-force protection (Administration → Security shows blocked addresses;
  behind a reverse proxy, set `trusted_proxies` so that Nextcloud sees the
  real client addresses).

[SECURITY.md](../SECURITY.md) describes the design and how to report a
vulnerability.

## 9. Managed hosting

On instances without shell access everything is done in the web interface:
installation from the App Store, settings, imports and restore. The `occ`
commands are a convenience for self-hosted instances:

| Command | Does |
|---|---|
| `occ notenarchiv:setup <account> [<folder>] [--role role=groups]` | sets the archive folder and the role groups |
| `occ notenarchiv:import <account> <file> [--execute]` | imports the catalog CSV; a dry run unless `--execute` is given |
| `occ notenarchiv:export [--extended] [--full]` | writes the catalog CSV, all fields, or the complete backup (JSON) to standard output |
| `occ notenarchiv:file-sync <account>` | runs the file sync |
| `occ notenarchiv:restore <file> [<account>]` | restores a complete backup into an empty catalog |
| `occ notenarchiv:modules list\|enable\|disable\|preset` | shows and switches the modules (section 12) |

## 10. Data protection

The app stores catalog data and the names of the function holders who changed
it (audit log, as a snapshot of the display name). The register of persons
holds names, life dates and roles of composers, arrangers, lyricists and
other persons involved; for living persons this is personal data from public
sources (prints, publisher catalogues) kept for the rights assessment.
Transcription reports are kept as received (as evidence, with their
checksum); their rights section may name the persons involved in a work.
Review points record who decided them and when.

The app stores no data about singers. Excerpt links count calls without
recording who opened them. The statistics contain no personal data of choir
members and no account names; calendar entries contain no notes and no
persons; the dashboard widget shows no question texts and no names. Record
the processing in your register of processing activities (GDPR Art. 30).

## 11. Accessibility

The interface targets WCAG 2.2 AA: keyboard operation, screen reader labels,
sufficient contrast in the light and the dark theme, information never by
colour alone, zoom to 200 %, reduced motion respected. The score viewer offers
a text description of the excerpt, announces the current measure during
playback and offers playback and text references as alternatives to the
notation. The statistics show every figure as a number in a table; the bars
are only an addition.

## 12. Modules and presets

Not every choir needs every function. Everything outside the core is a
**module** that admins switch on and off in the app under **Settings →
Modules**. The core is always on: the catalog (works, versions, copies,
fields, search, status), the start page, the file sync with the archive
folder, roles, the audit log, CSV import and export, backup and restore, and
the settings.

Rules:

- **Off means off on the server, too.** The routes of a switched-off module
  answer 404 ("This function is switched off."); background jobs,
  notifications, start page cards and the dashboard widget leave it out, and
  the navigation, sidebar tabs and buttons disappear. Links to it (e.g. from
  older notifications) open the start page with a note.
- **Nothing is deleted.** The data of a module stay in the catalog, the CSV
  and the backups. Switching the module on again shows everything again.
- **Switching off never makes anything less safe.** The rights checks for the
  score view, playback, practice audio files and internal preservation copies
  apply whatever is switched on. Without the module "Rights confirmation and
  licenses" nothing can be confirmed or licensed, so nothing more is played or
  released than before.
- **Dependencies.** Some modules need others (e.g. playback needs the score
  view, the calendar needs the concerts). Switching a module on also switches
  on what it needs. Switching off a module that others need first lists them;
  their own switches stay as they are, they stop working and come back with
  the module. Each switch shows what it needs and why it is not working.

Every change of a switch is recorded in the audit log (object type
`settings`).

### Presets

| Preset | Switches on |
|---|---|
| **Simple** | the core, the score view with playback and the notifications (of the core only the one about a failed weekly backup is left) |
| **Standard** | everything except practice audio files, the print station, the proposals inbox, checksums and statistics |
| **Complete** | everything except the interface for agents |

A preset sets every switch at once; the settings ask first and list what
changes.

**New installations** start with the preset **Standard**. It is chosen for
small choirs with one volunteer music librarian: catalog, rights, scores,
questions, concerts and the care of the stock are what such a choir uses in
its first year. Left off, and easy to switch on when needed:

- *Practice audio files*: the files leave the app and are passed on; making
  them is a decision of the choir about rights.
- *Print station*: only for choirs that run one.
- *Proposals inbox*: only useful with tools or transcription rounds (role
  "Proposals (tools)").
- *Checksums*: the job reads all archive files again and again in the
  background; that is for large archives that must document their integrity.
  The weekly backup is the safety net of every installation.
- *Statistics and annual report*: figures for the board of a larger archive.

*Reviewing transcriptions* and *small corrections in the app* are on in
**Standard**: they only show something where a transcription report or a
MusicXML exists, and they spare the conductor the detour over questions and
files. **Simple** leaves them off: it keeps to reading the catalog and the
scores. The corrections need only the score view, not the review, so that a
choir without transcription reports can still correct a wrong note.

**Existing installations** keep every function: an update from a version
before 0.1.27 stores all switches as on. The switches are stored on every
installation and update, so a later change of the defaults never switches
anything at an existing installation; a module added by a later version gets
its default. The **interface for agents** starts off everywhere and is
part of no preset: no preset switches it on or off, not even "Complete". It
is an access path for tools rather than a function for people, so the
administration switches it on on purpose (see section 13), and choosing a
preset later neither opens nor closes it.

### The modules

| Module | Needs | Standard | Switched off |
|---|---|---|---|
| Rights confirmation and licenses | – | on | No confirming, no licenses to add or change, no list of ending licenses, no license reminders. Existing licenses and confirmations still count for playback and releases. |
| Valid version | – | on | The conductor cannot choose or clear the valid version; no card "no valid version chosen". A chosen version stays recorded. |
| Register of persons | – | on | No register, no contributions, no import of a research list. |
| Protection terms | Register of persons | on | No protection proposal, no resubmission list, no notification about ended protection terms. |
| Releases | Rights confirmation and licenses | on | The three releases below are inactive. |
| Singers' portal | Releases | on | No portal list, no adding to or removing from the portal, no cards and notification; song lists without the portal column. |
| Print station | Releases | off | No print station list. |
| Publication | Releases | on | The publication status is not shown. |
| Score view | – | on | No score view; the server does not hand out the MusicXML. |
| Playback | Score view | on | No playback. |
| Practice audio files | Playback | off | The server refuses every export. |
| Excerpt links | Score view | on | No new links, and existing links do not open (they are kept and expire as before). |
| Reviewing transcriptions | Score view | on | The file sync still recognises transcription reports, but does not read them; no review points, no correction orders, no review in the score view, no filter "open review points". Reports, points and decisions are kept. |
| Small corrections | Score view | on | No corrections in the score view; files corrected before stay as they are. |
| Questions and tasks | – | on | No questions and tasks, no task cards and notifications. Existing tasks are kept. |
| Proposals inbox | – | off | No inbox; tools cannot propose. Open proposals are kept. |
| Concerts and song lists | – | on | No concerts and song lists, no deadlines on the start page, in the dashboard or as notifications. |
| Import of performance lists | Concerts and song lists | on | No import, no list of unclear programme lines. |
| Repertoire figures | Concerts and song lists | on | The catalog shows the repertoire values of the imported catalog; no thresholds in the settings. |
| Concerts in the calendar | Concerts and song lists | on | No calendar files and entries. Entries in calendars stay there. |
| New accessions and shelf labels | – | on | No accession assistant and no labels. The import still creates copies. |
| Merging works | – | on | No merge proposals, no merging and no undo. Merges done stay. |
| Inventory | – | on | No inventory lists. Recorded counts stay in the catalog. |
| Checksums | – | off | The hourly job does nothing; no checksum list, no notification. |
| Statistics and annual report | – | off | No statistics, no annual report. |
| Dashboard widget | – | on | Nextcloud does not offer the widget. |
| Notifications | – | on | The app sends no notifications; notifications sent before disappear when they are shown. |
| One switch per occasion | Notifications and the module of the occasion | on | That notification is not sent; the daily checks withdraw it. Occasions: proposals waiting, remove from the singers' portal, license ends soon, license has ended, weekly backup failed, new question or task, question answered, concert deadlines, confirm what was sung, protection term ended, file changed unnoticed, new review points, correction received. |
| Interface for agents | – | off (in no preset) | The API for agents (`/ocs/v2.php/apps/notenarchiv/api/v1/…`) answers 404; the MCP server reports the interface as switched off. Nothing else changes. |

### Command line

On instances with shell access the same is possible with `occ`; the command
resolves the dependencies and reports them:

```sh
occ notenarchiv:modules list            # table: switch, active, needs (--json for scripts)
occ notenarchiv:modules enable calendar # also switches on "concerts" if needed
occ notenarchiv:modules disable statistics practice_audio
occ notenarchiv:modules preset standard # simple | standard | complete
```

Module keys are listed in `docs/api.md` ("Modules and the personal view").

### Personal view

Independently of the modules, every person can hide navigation areas and
start page cards they do not need (**Customize view** in the navigation). It
is stored on the server for the account and changes no permission; hidden
areas still open through links and notifications.

## 13. Agents and the API

Tools and assistants ("agents") can work with the archive through a fixed,
versioned interface: the **API for agents** (OCS, version 1, described in
[api-v1.md](api-v1.md) and in `openapi.json`) and an **MCP server** for
clients such as Claude Desktop or Claude Code ([tools/mcp](../tools/mcp/README.md)).
An agent can read the catalog, a copy with its rights, the due list,
concerts and statistics, build text references, ask questions, answer
questions addressed to its role and propose changes.

**Agents never decide.** The interface has no way to confirm a rights status,
to add or change licenses, to choose the valid version, to accept proposals,
to close tasks, to delete or restore anything, to make backups or to change
settings. People do that in the app, as before. A proposal of an agent lands
in the proposals inbox; a question goes to the role it is addressed to.

Setting up:

1. Switch on the module **Interface for agents** (Settings → Modules, or
   `occ notenarchiv:modules enable agent_api`). For proposals, the
   **Proposals inbox** must be on as well; for questions, **Questions and
   tasks**.
2. Create an account that only the agent uses and a group for it, and give
   the group the role **Proposals (tools)** (reads, proposes, asks) or
   **Read only**. Do not give agents the roles of the catalog or operations
   librarian or the conductor.
3. Create an app password for that account (Personal settings → Security,
   logged in as the agent's account) and give it to the agent's MCP
   configuration only. Revoke it when the agent is no longer used.

What the server enforces: every request is checked against the roles of
the agent's account and the modules, as in the app; every route is limited
per account; internal preservation copies stay hidden from roles that may
not view them; answers contain no account names. Proposals and questions
of the agent appear in the audit log under its account.

To stop all agents at once, switch the module off: the interface answers
404 immediately. Deleting an app password or the account ends the access of
one agent.

Note: Nextcloud lets every client with an app password also call the
internal interface of the app's user interface (with the same role checks).
The module does not open more than the roles allow; it offers a stable,
documented contract that stays the same across updates of the app.
