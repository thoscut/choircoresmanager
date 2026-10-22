# Administration guide

This guide is for Nextcloud administrators who install and set up the
Notenarchiv app, and for the music librarians who take it over afterwards.

## 1. Installation

- **Nextcloud with an app store:** Apps → search "Notenarchiv" → Download and
  enable. This is the only way on managed instances without shell access
  (e.g. hosted Nextcloud offerings).
- **Self-hosted with shell access:** the same, or place the release archive in
  `apps/notenarchiv` and run `occ app:enable notenarchiv`.

Requirements: Nextcloud 33–35, PHP 8.2+, MySQL or MariaDB as Nextcloud's
database (the app is tested only with these; Nextcloud refuses to install it
on PostgreSQL, SQLite or Oracle). Recommended: the Notifications app
and a working background job runner (system cron). Without cron, weekly
backups, deadline reminders and checksum checks run only when the instance is
used ("AJAX" mode) and may be late.

## 2. Set up roles

Permissions are bound to **groups**, never to persons: a change of office is a
change of group membership. Create the groups first (Users → groups), e.g.
"Music librarians", "Conductor", "Board".

Open the app → **Settings** (admins only) and assign groups to roles:

| Role | Typical holder | May |
|---|---|---|
| Catalog (music librarian) | the person who keeps the catalog | edit works, versions and rights; confirm rights; import; file sync; accept proposals; close tasks |
| Operations (music librarian) | the person who hands out binders | copy status, counts, inventory |
| Conductor | conductor and deputy | choose the valid version; answer questions; plan concerts and song lists |
| Proposals (tools) | transcription rounds, scripts | only propose changes; a librarian accepts them |
| Read only (board, auditors) | board, auditors | read everything including the audit log |

Accounts without any role do not see the app; the server refuses every
request. Admins without a role read the catalog, the lists and the programmes
and change the settings. They do not see the proposals inbox or internal
preservation copies, and they write catalog data only if they also hold a
role. To do that, add yourself to a group with the role you need.

## 3. Archive folder

Choose the folder that contains the score files (Settings → archive folder).
It should be a group folder the librarians can read; the app reads it through
the account of the person who uses it, so every user only sees files they may
see anyway. The app never changes, moves or deletes files. It creates one
sub folder, `_notenarchiv-app`, for exports, snapshots and backups.

File names should start with the shelf mark (`5-01_Title_Composer.pdf`;
leading zeros do not matter). Folders starting with `_` or `.` are skipped.

## 4. First import

1. Archive management → Import → choose the catalog CSV (UTF-8, `;`). The
   first run is always a **dry run** with a report; nothing is stored.
2. Check the report (rows, rights status distribution, warnings), then import.
3. Run **File sync**; it links the files to the shelf marks.
4. Optionally import performance records and song lists (CSV or Excel) and the
   register of persons.

The import never confirms rights and never chooses a valid version; those
decisions stay with people. A repeated import (while the CSV is still the
leading source) updates changed values, keeps decisions taken in the app and
stores a snapshot before it changes anything.

## 5. Backups and restore

Nextcloud is no backup: synchronisation also replicates deletions. The app
therefore writes, every week, into `_notenarchiv-app/`:

- `…_catalog_weekly.csv` — the catalog in the legacy column layout plus all
  fields; readable in any spreadsheet program
- `…_catalog_weekly.ods` — the same plus the audit log as a second sheet
- `…_backup_weekly.json` — the **complete** backup (all tables with their ids:
  works, versions, copies, files, licenses, proposals, tasks, links, events,
  persons, contributions, merges, audit log) for a lossless restore

These files are part of the archive folder and are therefore covered by your
regular file backup of that folder. They hold the complete catalog including
the audit log (who changed what), tasks and the register of persons: share
the archive folder only with people who may read all of this (the role
holders), not e.g. with all singers.

Open the CSV files in a spreadsheet program through its import dialog (all
columns as text) rather than by double-click, or use the ODS file: the
catalog CSV keeps every cell exactly as stored, so a cell starting with `=`
could be read as a formula. The ODS file stores every cell as text. Keep yearly snapshots that are never
overwritten, and compare checksums once a year (the app's checksum job warns
about files whose content changed although they were not modified).

**Restore** (into an empty catalog, e.g. after reinstalling): Archive
management → Restore → choose a `…_backup_….json`. Without the app, the CSV
and ODS files still contain everything needed to continue working on paper or
in a spreadsheet. In the web interface a backup file may be at most 64 MB
(imports: 20 MB); on self-hosted instances `occ notenarchiv:restore` has no
such limit. Every value of the backup is checked against the database schema
before anything is written; a refused restore changes nothing.

If backups fail, librarians and admins get a notification.

## 6. Notifications

Role holders are notified about: proposals waiting, versions to remove from
the singers' portal, licenses ending, questions for their role and answers to
apply, concert deadlines (10, 8 and 6 weeks before) and confirming what was
sung, ended protection terms, checksum mismatches and failed backups.
Notifications never go to singers and contain no personal data. Every user can
choose in their personal settings whether notifications also arrive by email
and how often (e.g. weekly).

The **dashboard widget** "Music archive – due" shows the same as the start page
of the app. Nextcloud offers it only to accounts with an archive role; admins
without a role do not get it. It contains concert titles and dates, numbers
and shelf marks with titles, never the text of a question or a person's name.

### Calendar

Concerts can be added to the users' own Nextcloud calendars and downloaded as
calendar files (iCalendar). This uses the calendars of the **dav** app (shipped
with Nextcloud); the Calendar app is only needed to look at them. Accounts
without a writable calendar can still download the files.

- New entries are created through Nextcloud's calendar interface for apps.
  That interface cannot change existing entries, so an entry that is already
  in a calendar is updated by the user's browser with a normal CalDAV request
  in the user's own session (the same as the Calendar app does). Proxies or
  web application firewalls that block `PUT` requests to `/remote.php/dav/`
  prevent these updates; the app then reports the entries it could not save.
- Times of a concert refer to the Nextcloud time zone of the account that
  entered them (personal settings). Check that the planners' time zones are
  right.
- There is deliberately **no public subscription link** (webcal feed with a
  token): it would be a new way to the concert data without logging in and is
  a decision for the choir (who may see concerts, how links are revoked).

## 7. Security settings of the instance

The app works with Nextcloud's defaults; nothing has to be relaxed:

- The app page allows WebAssembly (`'wasm-unsafe-eval'` in its content
  security policy) for the bundled notation engine; no other exception.
- Imports, exports, backups and the file sync are rate limited per user;
  guessing excerpt links is slowed down by Nextcloud's brute-force protection
  (Administration → Security shows blocked addresses; behind a reverse proxy,
  set `trusted_proxies` so that Nextcloud sees the real client addresses).

## 8. Managed hosting

On instances without shell access everything is done in the web interface:
installation from the app store, settings, imports, restore. The `occ`
commands (`notenarchiv:setup`, `:import`, `:export` with `--extended` or
`--full`, `:file-sync`, `:restore`) are a convenience for self-hosted
instances only.

## 9. Data protection

The app stores catalog data and the names of the function holders who changed
it (audit log, as a snapshot of the display name). The register of persons
holds names, life dates and roles of composers, arrangers, lyricists and
publishers; for living persons this is personal data from public sources
(prints, publisher catalogues) kept for the rights assessment, and should be
named in the register of processing activities. The app stores no data about
singers. Excerpt links count calls without recording who opened them. Record
the processing in your register of processing activities (GDPR Art. 30).

## 10. Accessibility

The interface targets WCAG 2.2 AA: keyboard operation, screen reader labels,
sufficient contrast in light and dark themes, information never by colour
alone, zoom to 200 %, reduced motion respected. The score viewer offers a text
description of the excerpt, announces the current bar during playback and
offers playback and text references as alternatives to the notation.

## 11. Modules and presets

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
| **Complete** | everything |

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

**Existing installations** keep every function: an update from a version
before 0.1.27 stores all switches as on. The switches are stored on every
installation and update, so a later change of the defaults never switches
anything at an existing installation; a module added by a later version gets
its default. The **interface for agents** (planned for milestone 0.6) starts
off everywhere and is not part of any preset.

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
| One switch per occasion | Notifications and the module of the occasion | on | That notification is not sent; the daily checks withdraw it. Occasions: proposals waiting, remove from the singers' portal, license ends soon, license has ended, weekly backup failed, new question or task, question answered, concert deadlines, confirm what was sung, protection term ended, file changed unnoticed. |

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
