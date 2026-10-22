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
