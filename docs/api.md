# Internal API (version 0.1)

This is the API of the app's own user interface; it changes with the app.
Tools and agents use the stable **API for agents** instead
([api-v1.md](api-v1.md), OCS, `/ocs/v2.php/apps/notenarchiv/api/v1/…`).

All paths are relative to `/apps/notenarchiv`. Responses are JSON. Errors are
returned as `{ "error": "message for the user" }` with status 400 (invalid),
403 (not permitted) or 404 (not found). Write requests need the Nextcloud CSRF
token (`requesttoken`), which `@nextcloud/axios` sends automatically.

Expensive endpoints are rate limited per user (see the table below). When a
limit is reached, Nextcloud answers with status **429** and a body without
`error`; the UI should show a generic "too many requests, please wait" text.
Request bodies are checked strictly: unknown fields are refused with 400
(`Unknown field: …`).

| Endpoint | Limit per user |
|---|---|
| `POST /api/import`, `POST /api/persons/import`, `POST /api/performances/import` | 30 in 10 minutes |
| `POST /api/file-sync` | 10 in 10 minutes |
| `GET /api/export` | 30 in 10 minutes |
| `POST /api/export/store`, `POST /api/backup` | 10 in 10 minutes |
| `GET /api/backup/full` | 10 in 10 minutes |
| `POST /api/backup/restore` | 5 in 10 minutes |
| `GET /api/events/{id}/song-order` | 60 in 10 minutes |
| `POST /api/versions/{id}/practice-audio` | 30 in 10 minutes |
| `POST /api/versions/{id}/transcription-reports`, `POST /api/versions/{id}/corrections` | 30 in 10 minutes |
| `GET /api/files/{id}/scan` | 120 in 10 minutes |
| `GET /api/statistics`, `GET /api/statistics/report` | 120 in 10 minutes |
| `GET /api/statistics/csv`, `GET /api/statistics/report/csv` | 30 in 10 minutes |
| `GET /api/events/{id}/ics`, `GET /api/events/upcoming/ics` | 60 in 10 minutes |
| `POST /api/events/{id}/calendar`, `POST /api/events/upcoming/calendar` | 30 in 10 minutes |
| `GET /api/nextcloud-files/{fileId}/copy` | 120 in 10 minutes |
| `GET /api/settings/access` | 20 in 10 minutes |
| `GET /api/member/links/{token}/score` | 120 in 10 minutes |
| `POST /api/member/links/{token}/practice-audio` | 30 in 10 minutes |
| `GET /p/{token}`, `GET /repertoire` (public pages) | 60 in 10 minutes per address, 120 per account |
| `POST /api/folder-book/folders`, `PUT /api/folder-book/folders/{id}`, `POST /api/folder-book/folders/{id}/issue`, `POST /api/folder-book/loans/{id}/return` | 300 in 10 minutes |
| `GET /api/print-mirror/preview` | 30 in 10 minutes |
| `POST /api/print-mirror/sync` | 10 in 10 minutes |

Files chosen by `fileId` for an import may be at most 20 MB, a backup for a
restore at most 64 MB; larger files are refused with 400
(`The file is too large (at most %s MB).`).

Field names are the keys from `lib/Service/Fields.php` (snake_case). User-facing
texts are English source strings translated via Nextcloud l10n (German first).

## Domain glossary

| Code | German UI term | Meaning |
|---|---|---|
| work | Werk | the composition itself |
| version | Fassung | a specific setting of a work (original, arrangement, …); rights live here |
| copy | Exemplar | a shelf mark (`shelfmark`, e.g. `5-1` = binder 5, number 1) |
| file | Datei | a file in the archive folder linked to a copy |
| binder | Archivmappe | numbered archive binder |
| preservation copy | Erhaltungskopie | internal copy of a protected work, never shared |
| valid version | „gilt für den Chor“ | the version the conductor has chosen |
| audit log | Protokoll | append-only change log |

Roles: `librarian` (Notenwart Bestand), `operations` (Notenwart Betrieb),
`conductor` (Chorleitung), `contributor` (Vorschläge/Werkzeuge), `reader`
(Vorstand, Revision). `member` (Chormitglieder, milestone 0.7) is no archive
role: it is given to groups like the others (`GET/PUT /api/settings`,
`occ notenarchiv:setup --role member=…`), but `meta.roles` never contains it
and every route of the archive answers a choir member as an account without a
role (403). Choir members only reach the routes of "Sharing beyond the
archive roles" below. Admins may read and change settings; they may only write
catalog data if they also hold a role. Without a role they get 403 for the
proposals (`GET /api/proposals`) and the folder book (`GET /api/folder-book`)
and cannot view preservation copies; the UI hides the inbox and the folder
book for them. The administration can give the settings (not a role) to
further groups, see "Settings" below.

## Page

`GET /` returns the app page with initial state `meta` (same as `GET /api/meta`).
For a choir member without an archive role it returns the page "Shared with
me" instead (template `member`, initial state `member`, see "Sharing beyond
the archive roles"), while `member_links` is active; otherwise 403.

## Meta

`GET /api/meta`

```json
{
  "uid": "thomas",
  "admin": true,
  "canChangeSettings": true,
  "roles": ["librarian"],
  "roleLabels": { "librarian": "Catalog (music librarian)", "...": "..." },
  "fields": { "work": { "title": { "type": "text", "label": "Title", "write": ["librarian"] } }, "version": {}, "copy": {} },
  "archive": { "id": 123, "path": "/Notenarchiv", "user": "admin" },
  "archiveAccessible": true,
  "modules": { "rights": true, "concerts": true, "statistics": false, "agent_api": false, "...": "..." },
  "personalView": { "navigation": ["statistics"], "cards": ["proposals"] }
}
```

`modules` tells which modules are active (see "Modules and the personal
view" at the end); `personalView` what the account has hidden.
`canChangeSettings` is true for admins and for members of the groups the
administration has given the settings page (see "Settings"); it gives no
role. `archiveAccessible` tells whether the account can open the archive
folder in its own Nextcloud (`null` without an archive folder): the app reads
files only with the account's permissions, so without access no score, scan
or practice file opens. The UI then says so.

Field types: `text`, `longtext`, `number`, `date` (YYYY-MM-DD), `choice`
(with `options`: `{ "key": "English label" }`), `boolean`. A field may be
edited if `write` contains one of the user's `roles`; an empty `write` means
read-only. Labels are English source strings; translate them in the UI with
`t('notenarchiv', label)`.

Choice keys:

- `version.rights_status`: `public_domain`, `protected`, `open`
- `version.kind`: `original`, `adaptation`, `arrangement`, `new_engraving`,
  `manuscript_copy`, `transposition`, `excerpt`
- `copy.status`: `active`, `retired`, `missing`, `lent`
- `copy.kind`: `original`, `photocopy`, `preservation_copy`, `licensed_print`,
  `manuscript`

## Catalog

`GET /api/entries` returns one row per copy:

```json
[{ "id": 1, "shelfmark": "5-1", "binder": 5, "number": 1, "status": "active",
   "title": "…", "composer": "…", "work_no": null, "arranger_text": "…",
   "rights_status": "public_domain", "rights_confirmed": null, "confirmed_at": null,
   "preservation_copy": false, "publication": "zu klären",
   "valid_since": null, "repertoire": "aktuell", "last_performed": "2025-12-06",
   "has_musicxml": true, "files": 9, "pdfs": 3 }]
```

`GET /api/entries/{id}` returns everything about one copy:

```json
{ "work": { "id": 1, "title": "…" },
  "version": { "id": 1, "work_id": 1, "rights_status": "…" },
  "copy": { "id": 1, "version_id": 1, "shelfmark": "5-1" },
  "files": [{ "id": 7, "copy_id": 1, "file_id": 4711, "path": "Mappe_5/5-01_….pdf", "name": "5-01_….pdf",
              "type": "pdf", "role": "score_pdf", "size": 123, "mtime": 0,
              "listed": true, "musicxml_valid": false, "found": true }],
  "siblings": [{ "id": 2, "shelfmark": "6-8", "version_id": 5, "status": "active" }],
  "audit": [{ "id": 1, "operation": "…", "time": 1761150000, "user_id": "…", "user_name": "…",
              "roles": "librarian", "object_type": "version", "object_id": 1, "action": "changed",
              "field": "rights_status", "old_value": "open", "new_value": "public_domain", "reason": null }] }
```

The entry also contains `score`: the MusicXML to show for this copy and what
the user may do with it, or `null` if there is no MusicXML:

```json
"score": { "fileId": 12, "fileName": "5-01_….mxl", "canView": true, "canPlay": true, "reason": null,
           "canExportAudio": false, "exportReason": "Public domain is not confirmed yet: …" }
```

`fileId` refers to `files[].id`. `reason` (translated) explains why viewing or
playback is not allowed: internal preservation copies may only be viewed by
the roles `librarian` and `conductor` and are never played; playback is only
offered for public domain versions and for versions with a valid license for
`practice_audio`. `canExportAudio` tells whether practice audio files may be
made (see "Practice audio files" below); `exportReason` (translated) explains
why not when playback is allowed, otherwise it is `null` and `reason` applies.

`GET /api/files/{id}/content` returns the raw score file (`.mxl` is a ZIP,
`.musicxml` is XML) after checking the rights policy and the user's own
access to the file in Nextcloud; otherwise 403 with `{ "error" }`. When the
account cannot open the archive folder at all, the 403 carries
`"reason": "not_shared"` and the message `The file lies in a folder that is
not shared with your account. Please ask the music librarians to share the
archive folder with you or your group.`; the UI adds a link to the access
check for those who may change the settings. Fetch it
with a script (`responseType: 'arraybuffer'`): the response is sent as an
attachment (`Content-Disposition: attachment; filename="score.<type>"`) with
`X-Content-Type-Options: nosniff`, so a browser never renders it as a page.
Only real MusicXML is delivered: plain XML (UTF-8 or UTF-16) whose root is
`score-partwise` or `score-timewise`, or an `.mxl` archive in which the root
file named in `META-INF/container.xml` and every other `.xml`/`.musicxml`
outside `META-INF/` is such a document. Other files (e.g. MEI or SVG named
`.xml`) and files with a document type declaration that has an internal
subset are refused with 400 `This file is not a MusicXML score.`; files above
32 MB (also unpacked) with 400 `The file is too large (at most %s MB).`. The
viewer should nevertheless tell its renderer to read MusicXML only.

File roles: `score_pdf`, `musicxml`, `musescore`, `analysis`, `source`,
`audio`, `image`, `transcription_report` (a `…bericht.json`, see
"Reviewing transcriptions"), `other`. Open a file in Nextcloud via
`generateUrl('/f/{file_id}')`.

Audit actions: `created`, `changed`, `import`, `restore`, `not_found`,
`file_sync`, `practice_audio` (see "Practice audio files"), `imported`
(object `review_report`), `decided` and `corrected` (object
`review_point`), `corrected` (object `file`, a correction in the app).

`PATCH /api/{level}/{id}` with `level` = `work` | `version` | `copy`:

```json
{ "fields": { "rights_status": "open" }, "reason": "optional" }
```

The response is `{ "ok": true }`; reload the detail afterwards. A status other
than `active` requires `status_reason` in the same request.

## Archive management (role librarian)

`POST /api/import` `{ "fileId": 4711, "dryRun": true }` returns a report:

```json
{ "dryRun": true, "source": "…csv", "sha256": "…", "operation": null, "restore": true,
  "snapshot": null, "rows": 25, "created": 25, "changed": 0, "unchanged": 0,
  "fieldsChanged": 0, "notInCsv": [], "withMusicxml": 23, "preservationCopies": 0,
  "distribution": { "rightsStatus": { "public_domain": 25 }, "repertoire": { "aktuell": 8 }, "publication": {} },
  "warnings": [] }
```

`POST /api/file-sync` returns a report:

```json
{ "files": 128, "assigned": 128, "new": 128, "changed": 0, "missing": 0, "ignored": 1,
  "unassignable": [], "byType": { "pdf": 40 }, "copiesWithoutFile": [], "skippedFolders": ["_notenarchiv-app"] }
```

`files` counts files named after a shelf mark; `ignored` counts other files
(instructions, lists). `unassignable` lists files whose shelf mark is not in
the catalog. Paths are relative to the archive folder without a leading slash.

`GET /api/export?extended=0|1` downloads the CSV (`0` = legacy catalog format,
`1` = all fields). `POST /api/export/store` returns
`{ "path": "_notenarchiv-app/20261022-…csv" }`. The catalog CSV keeps cells
exactly as stored (also cells starting with `=`, `+`, `-` or `@`), because it
must survive an import followed by an export byte for byte; see SECURITY.md.

Performance imports (`.xlsx`) are refused with 400
`The Excel workbook is too large to import.` above 10,000 archive entries,
16 MB unpacked per part, 48 MB unpacked in total, column XFD or one million
cells, and with `The Excel workbook is damaged.` when a part contains a
document type declaration.

**Complete backup.** `GET /api/backup/full` (any role, plain download without
CSRF token) returns all app tables as JSON
(`{ "format": "notenarchiv-backup", "formatVersion": 1, "appVersion", "created", "tables": { "notarch_works": [ … ] } }`).
`POST /api/backup/restore` `{ "fileId": 4711 }` (librarian or admin) restores
such a file into an empty catalog and returns
`{ "restored": { "notarch_works": 120, … } }`. Errors (400):
`A backup can only be restored into an empty catalog.`, `The file is not a
backup of the music archive.`, `The backup was made with a newer version of
the app. Please update the app first.` and
`The backup contains an invalid value in %1$s, row %2$s, column %3$s.`
(table, row number from 1, column). Only columns that exist in the installed
schema are written; others are left out.

`GET /api/audit?limit=200` returns the newest entries first.

## Settings

The settings are the page **Administration → Music archive** (settings
section `notenarchiv`, `OCA\Notenarchiv\Settings\Admin`, an
`IDelegatedSettings`). Admins may use them, and the members of the groups
the administration has given the page under **Administration privileges**;
that gives no role and no access to the catalog. The routes below, the module
settings and `PUT /api/repertoire/thresholds` carry
`#[AuthorizedAdminSetting(settings: Admin::class)]` (Nextcloud refuses
everybody else before the app, with its own 403) and check again in the app
(403 `Only administrators may change this.`). Restoring a backup
(`POST /api/backup/restore`) is **not** part of it: it stays with admins and
the role `librarian`. The settings give no app config to Nextcloud's generic
config API (`getAuthorizedAppConfig()` is empty).

`GET /api/settings`

```json
{ "roles": { "librarian": ["archive-team"], "operations": [], "conductor": [], "contributor": [], "reader": ["board"] },
  "groupNames": { "archive-team": "Archive team", "board": "Board" },
  "groupsMissing": [],
  "roleLabels": { "librarian": "Catalog (music librarian)", "...": "..." },
  "archive": { "id": 123, "path": "/Notenarchiv", "user": "admin" },
  "repertoire": { "current": 3, "dormant": 10 },
  "catalog": { "copies": 23, "files": 30 },
  "admin": true,
  "catalogEmpty": false }
```

`groupNames` are the display names of the groups in `roles` (the id for a
group that no longer exists); `groupsMissing` lists the groups in `roles`
that no longer exist. `roleLabels` are English source strings.
`repertoire` is `null` while the module `repertoire` is not active.
`catalog` counts the imported copies and the files found by the file sync;
with the archive folder, the groups of the role `librarian` and the access
check, the page derives the setup steps from it (nothing of it is stored).
`admin` tells whether the caller is an admin; only then does the page offer
the complete backup and restore, and only then is `catalogEmpty` (whether a
restore is possible: every table of the app is empty) a boolean, otherwise
`null`.

`PUT /api/settings` `{ "roles": { "librarian": ["…"] }, "archiveFolderId": 123 }`;
both parts are optional. The response is the same as for `GET`. The archive
folder is saved as seen by the caller, who becomes the archive user
(background jobs read through this account).

`GET /api/settings/groups?search=boa` returns at most 25 groups whose id or
display name matches, `[{ "id": "board", "displayName": "Board" }]`, for
choosing the groups of a role. (Nextcloud's own group list with display names
needs the administration privileges for sharing or accounts.)

`GET /api/settings/access` checks who can open the archive folder in their
own Nextcloud (`getUserFolder($uid)->getFirstNodeById($archiveFolderId)`
is a folder). It only reads: it shares nothing and changes nothing. Each
account is checked once, at most 500; disabled accounts and accounts that
have never logged in are not checked (Nextcloud would first set up the file
storage of such an account). Without an archive folder: 400
`No archive folder has been set yet.`

`GET /api/settings/access?folderId=456` checks a folder the caller has just
chosen as the new archive folder, before it is saved; `archive` then
describes that folder as the caller sees it. The caller must be able to open
it, otherwise 400 `The selected archive folder is not a folder or not
accessible.`

```json
{ "archive": { "id": 123, "path": "/Notenarchiv", "user": "admin" },
  "self": { "uid": "admin", "displayName": "Admin", "canOpen": true },
  "roles": [
    { "role": "librarian", "groups": [
      { "id": "archive-team", "displayName": "Archive team", "exists": true, "members": 3, "checked": 3,
        "canOpen": 2, "cannotOpen": [{ "uid": "demo-conductor", "displayName": "Demo Conductor" }], "notChecked": 0 } ] },
    { "role": "operations", "groups": [] } ],
  "adminsWithoutRole": { "members": 1, "checked": 1, "canOpen": 1, "cannotOpen": [], "notChecked": 0 },
  "checked": 4, "limit": 500, "complete": true }
```

`roles` lists every role in the order of `Roles::ALL` with its groups;
`exists: false` marks a group that was deleted after it was given the role.
`members` counts the accounts of a group, `checked` those checked,
`canOpen` those that can open the folder; `cannotOpen` names the others
(sorted by display name), `notChecked` counts the accounts left out
(disabled, never logged in, beyond the limit). `self` is the caller,
`adminsWithoutRole` the admins who hold no role (they read the catalog and
need access to the folder to open scores). `complete` is false when
accounts were left out because of the limit. `occ notenarchiv:setup` prints
the same check in short when an archive folder is set.

## Rights, releases and valid version (milestone 0.2)

The entry (`GET /api/entries/{id}`) additionally contains:

```json
"licenses": [{ "id": 3, "version_id": 1, "holder": "…", "uses": "binder,practice_audio", "quantity": 30,
               "valid_from": null, "valid_until": "2027-12-31", "evidence": "…", "notes": null }],
"releases": { "portal": "reason why not released, or null", "onPortal": false, "printStation": "… or null" },
"proposals": [{ "id": 7, "object_type": "version", "object_id": 1, "field": "key_signature",
                "new_value": "\"D major\"", "reason": "…", "source": "round 40", "status": "open", "created_by": "…", "created": 1761150000 }]
```

New version fields (see `meta.fields`): `publication_status`, `review_status`,
`rule1_status`, `rule1_count`, `rule1_checked`, `confirmed_by`,
`confirmed_by_name`, `confirmed_at`, `confirmed_reason`, `confirmed_evidence`,
`portal_removed_at`, `portal_removed_reason`, `valid_reason`, `key_signature`,
`scoring`, `language`, `duration`. License uses: `binder`, `portal`,
`self_print`, `print_station`, `practice_audio`, `recording`, `publication`,
`online_publication` (milestone 0.7: making an excerpt available online
through a public link; keys and English labels in `meta.licenseUses`).

Rules the UI should reflect (the server enforces them):

- Confirming needs status `public_domain` or `protected`, a reason and
  evidence; only `librarian`. Changing the rights status via PATCH removes the
  confirmation.
- "When in doubt: set to open" sets the status to `open` with a reason.
- A version may be added to the singers' portal only if `releases.portal` is
  `null` (confirmed public domain or a license with use `portal`); never for
  preservation copies.
- Only `conductor` sets or clears the valid version; one per work.
- Proposals (role `contributor` or `librarian`) never change data until a
  `librarian` accepts them. Only fields the `librarian` may write can be
  proposed; all fields of a request are checked first and stored together
  (one proposal each) or not at all.

Endpoints (all JSON, errors as `{ "error" }`):

| Method and path | Body | Role |
|---|---|---|
| `POST /api/versions/{id}/confirm` | `{ status, reason, evidence }` | librarian |
| `POST /api/versions/{id}/set-open` | `{ reason }` | librarian |
| `POST /api/versions/{id}/portal` | `{ since: "YYYY-MM-DD" }` | librarian |
| `POST /api/versions/{id}/portal-remove` | `{ reason }` | librarian |
| `POST /api/versions/{id}/valid` | `{ reason? }` | conductor |
| `POST /api/versions/{id}/valid-clear` | `{ reason? }` | conductor |
| `POST /api/versions/{id}/licenses` | `{ license: { holder, uses: [..], quantity, valid_from, valid_until, evidence, notes } }` | librarian |
| `PATCH /api/licenses/{id}` | `{ license: { …changed fields } }` | librarian |
| `GET /api/proposals` | – | librarian, contributor, reader |
| `POST /api/proposals` | `{ level, id, fields: {..}, reason?, source? }` | contributor, librarian |
| `POST /api/proposals/{id}/accept` | `{ reason? }` | librarian |
| `POST /api/proposals/{id}/reject` | `{ reason? }` | librarian |
| `GET /api/lists/portal?all=0\|1` | – | any role |
| `GET /api/lists/print-station?all=0\|1` | – | any role |
| `GET /api/licenses/ending?days=30` | – | any role |
| `POST /api/backup` | – | librarian |

Release list rows:

```json
{ "versionId": 1, "copies": [{ "id": 35, "shelfmark": "5-1" }], "title": "…", "composer": "…",
  "status": "remove|add|not_decided|ok|other_version_valid|blocked", "reason": "… or null",
  "portalSince": null, "validSince": null, "rightsStatus": "public_domain", "confirmed": false }
```

`all=1` also lists `blocked` and `other_version_valid` rows.
`POST /api/backup` returns `{ "paths": ["_notenarchiv-app/…csv", "…ods", "…json"] }`.

`GET /api/licenses/ending` lists licenses that end within `days` (1–366,
default 30, as the start page card and the daily reminder) or have ended,
the soonest first:

```json
[{ "licenseId": 4, "versionId": 1, "copies": [{ "id": 35, "shelfmark": "5-1" }], "title": "…", "composer": "…",
   "holder": "…", "uses": ["binder", "portal"], "validUntil": "2026-11-15", "ended": false }]
```

## Notifications

Sent to the members of the groups of a role (never to singers), withdrawn
when resolved. Links open the app with `?copy={copyId}` or
`?view=proposals`; the UI should honour these query parameters on start.

| Subject | To | When |
|---|---|---|
| `proposals_waiting` | librarian | open proposals exist (one notification with the count) |
| `portal_remove` | librarian | daily: version on the portal but no longer released |
| `license_expiring` / `license_expired` | librarian | daily: license ends within 30 days / has ended |
| `export_failed` | librarian and admins | weekly backup failed; parameters `error` (English source string meant for users) and `errorParameters` (JSON list of strings), both only when the cause is such a message; internal errors are only logged |

## Tasks, excerpt links and start page (milestone 0.3)

The entry additionally contains `tasks` (all tasks of the copy, newest first)
and `links` (active excerpt links of the copy, of every circle).

**Task**

```json
{ "id": 4, "kind": "question|check|report", "copy_id": 35, "page": 2, "measure_from": "62", "measure_to": "66",
  "voice": "Tenor II", "verse": null, "addressee": "conductor|librarian|operations|reader",
  "category": "reading|structure|origin|shelf|rights|other", "origin": "round 37",
  "question": "…", "answer": null, "status": "open|answered|applied|discarded", "due": null,
  "created_by": "…", "created": 1761150000, "answered_by": null, "answered_by_name": null, "answered": null,
  "closed_by": null, "closed": null, "closing_note": null }
```

Measure numbers are printed numbers as strings (`"0"` = upbeat, `"34X"`).
Lists (`/api/tasks`, `/api/due`) add `shelfmark`, `binder`, `number`, `title`,
`repertoire` and are sorted by binder and number, so that a printed list
follows the shelf.

**Excerpt link** (`circle`: `internal` for logged-in holders of an archive
role; `members` for the choir members and `public` for everyone, see
"Sharing beyond the archive roles")

```json
{ "id": 9, "token": "32 alphanumeric characters", "copy_id": 35, "file_id": 78, "file_etag": "…",
  "measure_from": "62", "measure_to": "66", "voice": "Tenor II", "verse": null, "tempo": 80,
  "note": "…", "task_id": 4, "circle": "internal", "created": 1761150000, "expires": "2027-04-23",
  "revoked": null, "hits": 3 }
```

Opening a link (`GET /api/links/{token}`) additionally returns `shelfmark`,
`createdByName` and `changed` (`true` when the MusicXML changed since the link
was created: show "the score has changed since"). The link URL in the UI is
`/apps/notenarchiv/?link={token}`. Expired, revoked or no longer permitted
links return 400/403 with a message. An unknown token (400
`This link does not exist.`) counts as a failed attempt for Nextcloud's
brute-force protection: repeated unknown tokens from one address are answered
more and more slowly and finally with 429.

Creating a task or a link refuses fields other than the ones listed below
(400 `Unknown field: %s`); `task_id` of a link must be a positive whole number.

| Method and path | Body | Role |
|---|---|---|
| `GET /api/due` | – | any role; returns `{ cards: [{ key, count, label }], tasks: [...], concerts: [{ id, title, date, stage, label }] }` |
| `GET /api/tasks?closed=0\|1` | – | any role |
| `POST /api/copies/{id}/tasks` | `{ task: { kind, addressee, question, page?, measure_from?, measure_to?, voice?, verse?, category?, origin?, due? } }` | librarian, conductor, operations, contributor |
| `POST /api/tasks/{id}/answer` | `{ answer }` | the addressed role |
| `POST /api/tasks/{id}/close` | `{ status: "applied"\|"discarded", note? }` | librarian (operations for checks addressed to operations) |
| `POST /api/copies/{id}/links` | `{ link: { measure_from?, measure_to?, voice?, verse?, tempo?, note?, task_id?, expires?, circle? } }` | librarian, conductor, operations |
| `GET /api/copies/{id}/link-options` | – | librarian, conductor, operations: what a link for choir members or the public would allow (milestone 0.7) |
| `GET /api/links` | – | any role: all active links of every circle (board/auditors can review them) |
| `GET /api/links/{token}` | – | any role (rights checked on every call) |
| `POST /api/links/{id}/revoke` | – | creator or librarian |

Start page card keys: `tasks_open`, `tasks_answered`, `ambiguous_items`,
`proposals`, `portal_remove`, `portal_add`, `confirm_current`, `rule1_current`,
`licenses_expiring`, `valid_missing`. The `label` is translated, always in the
plural; the UI shows its own label in singular or plural for the keys it knows
(`src/dueLabels.ts`) and the server's label for new keys.

`concerts` lists the concerts with a deadline now (conductor and librarian,
like the notifications; `confirm` only for the conductor), the earliest
first: `stage` is `10`, `8` or `6` (weeks before, the stage starts on that
day) or `confirm` (after the date, until confirmed as sung); `label` is the
translated task (`Fix the programme and the valid versions`, `Clarify the
rights of all pieces`, `Prepare the concert binders`, `Confirm what was
sung`). Planned concerts only: no imported, sung or cancelled ones.

**Start parameter `?due={cardKey}`** opens the view of a start page card with
its filters, as clicking the card does (used by the dashboard widget). Unknown
keys open the catalog; a card of a view the roles do not include opens the
start page.

**Dashboard widget** `notenarchiv-due` (Nextcloud dashboard, API widget
version 2, `OCA\Notenarchiv\Dashboard\DueWidget`): registered only for
accounts with an archive role (not for admins without one). Items in the order
of the start page: concerts (`title`, subtitle `date: label`, link
`?event={id}`), cards (`label`, subtitle number of entries, link
`?due={key}`), tasks (shelf mark and title, subtitle open/answered, link
`?copy={id}&task={id}`); at most the requested number (7 by default). Button
"more" opens the app. No personal data (no question texts, no names).

Notifications: `task_new` (to the addressed role, link `?copy={id}&task={id}`)
and `task_answered` (to the librarian).

**Text reference** (no server call): the UI builds a plain text such as
`5-1 · Freude, schöner Götterfunken · p. 2 · m. 17–24 · Tenor II · note` that can
be copied anywhere; it contains no notation and is always allowed. The API
for agents builds the same text on the server
(`GET /api/v1/copies/{id}/reference`, `lib/Service/TextReference.php`).

## Practice audio files

The score view makes practice files per voice in the browser: WAV (16 bit,
44.1 kHz, mono or stereo) rendered with the playback's own synthesizer and
playing order, or a Standard MIDI File (format 1: a track with tempo, time
and key signature, then one track per voice with its name; the mode is in
the channel volume of each track). "Every voice" gives a ZIP archive (stored
entries) with one file per voice plus a tutti. File names:
`<shelf mark>_<title>_<voice>_T<from>-<to>.<ext>`, ASCII only. The files never
pass through the server. Choir members make practice files of a shared
excerpt through their own route (module `member_practice_audio`, see
"Sharing beyond the archive roles").

Before every export the UI asks the server, which checks the rights and
records the export:

`POST /api/versions/{id}/practice-audio`

```json
{ "export": { "copy_id": 35, "measure_from": "0", "measure_to": "11", "voice": "Tenor 1",
              "mode": "focus", "format": "wav", "all_voices": false, "tempo": 80, "repeats": true } }
```

| Field | Meaning |
|---|---|
| `copy_id` | the copy shown; must belong to version `{id}` |
| `measure_from`, `measure_to` | printed numbers of the first and last measure (digits, letters, dots; at most 16 characters) |
| `voice` | name of the focus voice (at most 100 characters); `null` for a tutti or with `all_voices` |
| `mode` | `all` (all voices equal), `focus` (focus voice louder), `solo` (focus voice only); `focus` and `solo` need a voice |
| `format` | `wav` or `midi` |
| `all_voices` | one file per voice plus a tutti; needs `mode` `focus` or `solo` (default `false`) |
| `tempo` | percent of the score's tempo, 25–200 (default 100) |
| `repeats` | whether repeats are played (default `true`) |

The answer is `{ "ok": true }`. Without it the UI makes no file. Refusals:

- 403 with the reason (translated) when the rights policy refuses: the user
  needs the role `librarian` or `conductor`; playback must be allowed
  (`playDenial`: never for internal preservation copies, public domain or a
  valid license for `practice_audio`); a public domain version must
  additionally be confirmed, unless a license covers `practice_audio`.
  Reasons: `Practice audio files are made by the catalog librarian or the
  conductor.`, `Public domain is not confirmed yet: practice audio files need
  a confirmed rights status or a license for practice audio.`, or the
  playback reasons (`Internal preservation copy: no playback.`, `Protected
  version: playback needs a license for practice audio.`, …).
- 400 for invalid request data (`Unknown field: %s`, `"%s": value not
  allowed.`, `The copy does not belong to this version.`, …). The rights are
  checked first.

An allowed export is recorded in the audit log of the version: action
`practice_audio`, `field` `null`, `new_value` a JSON object such as
`{"copy":"2-2","measures":"0-11","voice":"Tenor 1","mode":"focus","format":"wav","tempo":80,"repeats":true}`
(`voice` is `"*"` for every voice, `null` for a tutti). Refused requests are not
recorded. A cancelled rendering in the browser does not remove the entry.

## Concerts, song lists and repertoire (milestone 0.4)

`GET /api/entries` rows additionally contain `repertoire_class`
(`current|dormant|earlier|none|null`), `repertoire_source` (`performances`
when derived from documented performances, `import` when taken from the
imported catalog), `performances` (number or `null`), `last_performed_on` and
`song_lists` (list of the names or periods of the song lists the work is on,
e.g. `["2018-Advent", "2025/1"]`; empty list when none).

**Event** (`kind`: `concert` or `song_list`)

```json
{ "id": 3, "kind": "concert", "title": "Advent concert", "event_date": "2026-12-06",
  "start_time": "19:30", "end_time": "21:15", "time_zone": "Europe/Berlin", "location": "…",
  "period": null, "status": "draft|fixed|sung|cancelled", "is_public": true, "board_agreed": false,
  "imported": false, "source": null, "notes": null, "created": 1761150000, "modified": 1761150000 }
```

Song lists use `period` (e.g. `2025/1`, `2018-Advent`) and are no proof of
performance. Imported events (`imported: true`) and sung concerts are history:
they cannot be changed. A planned event needs a `title`: creating one without
it, or changing it to an empty title, is refused with 400
`Please enter a title.`.

`start_time` and `end_time` (`HH:MM`, both optional, local time at the venue)
are for concerts only and need a full `event_date`; the end must be after the
start, an end needs a start (400 otherwise: `"%s" needs a valid time (HH:MM).`,
`The end time must be after the start time.`, `Please enter the start time,
too.`, `A time needs the date of the concert.`, `Only concerts have a time.`).
`time_zone` is read-only: the server records the Nextcloud time zone of the
account that enters or changes the time.

A planned concert (draft or fixed) can be **cancelled**: status `cancelled`,
it stays with its programme, deadline notifications are withdrawn, it cannot
be changed (400 `This concert is cancelled. Reopen it to change it.`) until it
is **reopened** (status `draft` again). Both are audited.

**Event item**

```json
{ "id": 7, "event_id": 3, "position": 1, "copy_id": 35, "version_id": 35, "work_id": 35,
  "title_text": null, "composer_text": null, "participation": null,
  "assignment": "certain|ambiguous|not_in_catalog", "candidates": "7-5 | 1-31",
  "state": null, "counts": false, "sung": null, "struck": false, "encore": false, "duration": 185, "note": null }
```

`GET /api/events/{id}` returns `{ event, items, totalDuration, missingDurations }`;
each item adds `shelfmark`, `title`, `composer`, `arranger_text`, `publisher`
and `warnings` (translated texts: preservation copy, protected without
license or counted originals, rights not clarified, no valid version / another
valid version).

| Method and path | Body | Role |
|---|---|---|
| `GET /api/events?kind=concert\|song_list` | – | any role (with `items` count) |
| `GET /api/events/{id}` | – | any role |
| `POST /api/events` | `{ kind, event: { title, event_date, start_time, end_time, location, period, is_public, board_agreed, notes } }` | conductor, librarian |
| `PATCH /api/events/{id}` | `{ event: { …changed fields } }` | conductor, librarian |
| `POST /api/events/{id}/items` | `{ copyId }` | conductor, librarian |
| `PATCH /api/event-items/{id}` | `{ item: { duration?, note?, encore?, struck? } }` | conductor, librarian |
| `DELETE /api/event-items/{id}` | – | conductor, librarian (not for sung/imported) |
| `POST /api/events/{id}/order` | `{ order: [itemId, …] }` | conductor, librarian |
| `POST /api/events/{id}/fix` | – | conductor, librarian |
| `POST /api/events/{id}/sung` | `{ struck: [itemId, …] }` | conductor, librarian; only on or after the date |
| `POST /api/events/{id}/cancel` | – | conductor, librarian; planned concerts (draft, fixed); returns the event |
| `POST /api/events/{id}/reopen` | – | conductor, librarian; cancelled concerts; returns the event (status `draft`) |
| `GET /api/event-items/ambiguous` | – | any role |
| `POST /api/event-items/{id}/resolve` | `{ copyId }` or `{ copyId: null }` (= not in the catalog) | conductor, librarian |
| `GET /api/events/{id}/song-order` | – | download CSV (position, shelf mark, title, composer, arranger/lyrics, publisher, duration); cells starting with `=`, `+`, `-`, `@`, tab or carriage return get a leading `'` so that spreadsheet programs do not evaluate them |
| `GET /api/repertoire` | – | `{ thresholds: { current, dormant }, works: { workId: { performances, first, last, class, songLists } } }` |
| `PUT /api/repertoire/thresholds` | `{ current, dormant }` (years) | settings (admins and the groups given the settings page); the current values are in `GET /api/settings` |
| `POST /api/performances/import` | `{ fileId, dryRun, ownChoir? }` | librarian; `.xlsx` or `.csv` |

Performance import report: `{ dryRun, source, sha256, snapshot, events,
songLists, items, certain, ambiguous, notInCatalog, counted, ownChoir,
unknownShelfmarks, sheets }`. A repeated import replaces the previously
imported events of the same kind; planned events are never touched.

Start page card: `ambiguous_items`. Notifications: `concert_deadline` (10, 8
and 6 weeks before, to conductor and librarian; link `?event={id}`) and
`concert_confirm` (after the date, to the conductor); both are withdrawn when
the concert is cancelled. A weekly summary instead of single notifications is
Nextcloud's own setting (personal settings → notifications → email).

### Concerts in the calendar

Calendar files (iCalendar, RFC 5545: CRLF, lines folded after 75 octets
without splitting a character, TEXT escaped) and entries in the account's own
Nextcloud calendars. Only concerts with a full date; "upcoming" means planned
in the app (not imported), from today on (in the account's time zone),
cancelled ones included.

Each concert is one entry with a stable UID
`notenarchiv-concert-{id}-{hash of the instance id}`: `SUMMARY` (title),
`LOCATION`, `DESCRIPTION` (status and a link to the app), `URL`
(`/apps/notenarchiv/?event={id}`), `STATUS` (`TENTATIVE` for drafts,
`CONFIRMED`, `CANCELLED`), `SEQUENCE` (grows with every change), `CREATED`,
`LAST-MODIFIED`, `DTSTAMP`. With `start_time` the times have `TZID` = the
recorded time zone (or the account's Nextcloud time zone) and a `VTIMEZONE`
definition, `TRANSP:OPAQUE`; without a time the entry is all-day
(`VALUE=DATE`, end = next day, `TRANSP:TRANSPARENT`). No notes, no persons, no
alarms, no `METHOD`.

With `deadlines` the three preparation deadlines come as separate all-day
entries (UID `notenarchiv-concert-{id}-deadline-{10|8|6}-{hash}`, summary
"{title}: {task}", `TRANSP:TRANSPARENT`, cancelled with the concert), not as
alarms: they stay visible in the calendar weeks ahead, work in every program
and do not repeat the app's notifications. Imported concerts get no deadlines.

| Method and path | Body | Role |
|---|---|---|
| `GET /api/events/{id}/ics?deadlines=0\|1` | – | any access; download `concert-{id}.ics` (`text/calendar`); 400 for song lists (`Only concerts can be added to a calendar.`) and concerts without a full date (`This concert has no date yet.`) |
| `GET /api/events/upcoming/ics?deadlines=0\|1` | – | any access; download `concerts.ics` with `X-WR-CALNAME`; 400 `There are no upcoming concerts.` |
| `GET /api/calendars` | – | any role; `[{ uri, name, color }]`: the account's enabled calendars that accept new entries (`OCP\Calendar\IManager`, `ICreateFromString`, writable) |
| `POST /api/events/{id}/calendar` | `{ calendar: uri, deadlines: bool }` | any role; adds the concert to the account's calendar |
| `POST /api/events/upcoming/calendar` | `{ calendar: uri, deadlines: bool }` | any role; adds all upcoming concerts |

The two downloads are plain links (no CSRF token), read only and rate
limited. Adding answers

```json
{ "calendar": "Personal", "concerts": 1, "created": 3, "skipped": 0, "failed": 0,
  "updates": [{ "calendar": "Choir", "path": "calendars/anna/choir/notenarchiv-concert-3-1a2b3c4d5e6f.ics", "ics": "BEGIN:VCALENDAR…" }] }
```

An entry whose UID is not in any writable calendar of the account is created
in the chosen one (object name `{uid}.ics`). An entry that is already in one
of them (the chosen first) is **not created again**: Nextcloud's calendar
interface for apps cannot change entries, so it is listed in `updates` with
its CalDAV path below `remote.php/dav` (segments URL-encoded) and the new
content; the UI writes it with `PUT {remote.php/dav}/{path}` (`Content-Type:
text/calendar; charset=utf-8`) in the user's session. Entries of a cancelled
concert that are in no calendar are not created (`skipped`); entries that
could not be created are counted in `failed` (logged). 400 `The selected
calendar does not exist or you cannot add entries to it.` for an unknown or
read-only calendar; 403 without a role.

There is deliberately no public subscription feed (webcal with a token).

## Maintaining the catalog (milestone 0.5)

Rows of `GET /api/entries` additionally contain `work_id` and `version_id`
(needed to merge). `meta` additionally contains `personFields` (field
registry of a person, same format as `fields`) and `contributionRoles`
(`{ key: "English label" }`).

### New accessions and shelf labels

Shelf mark numbers are never reused, not even those of retired copies, and
gaps are never filled: the next number is one above the highest number ever
used in the binder, by a copy (whatever its status) or by a file name in the
archive folder. New copies go only into the binders opened for accessions
(default: the highest existing binder).

`GET /api/accession/next?binder=10` (binder optional, any role):

```json
{ "proposal": { "binder": 10, "number": 75, "shelfmark": "10-75" },
  "binders": [{ "binder": 10, "highest": 74, "next": 75 }], "openBinders": [10] }
```

`POST /api/accession` (librarian) creates work, version and copy in one step:

```json
{ "copy": { "title": "…", "composer": "…", "title_source": "…", "accession_date": "2026-10-22", "origin": "Donation of …",
            "binder": 10, "number": 75, "arranger_text": "…", "publisher": "…", "rights_status": "open",
            "version_kind": "arrangement", "kind": "original", "pages": "4", "originals_count": 30, "copies_count": 0,
            "notes": "…" },
  "fileId": 4711 }
```

Required: `title`, `composer`, `title_source`, `accession_date` (not in the
future) and `origin`. `binder` and `number` default to the proposal; a
number at or below the highest used one is refused. `rights_status` defaults
to `open`. Accession date and origin are written into the notes of the
version ("Accession on … from: …", in the librarian's language). `fileId`
(optional) is the Nextcloud id of a file inside the archive folder; it is
linked by hand (see below). Response:
`{ "copyId": 81, "versionId": 81, "workId": 81, "shelfmark": "10-75", "label": { … } }`.

`PUT /api/accession/open-binders` `{ "binders": [10, 11] }` (librarian)
returns `{ "openBinders": [10, 11] }`; the change is recorded in the audit
log (object type `settings`).

`GET /api/labels?ids=81,82` (any role) returns the data for shelf labels; the
layout (e.g. Avery 3653: 2 × 7 labels of 105 × 42.3 mm on A4) is up to the UI:

```json
[{ "copyId": 81, "shelfmark": "10-75", "binder": 10, "number": 75, "label": "10 / 75",
   "title": "…", "composer": "…", "arranger_text": "…", "arrangers": ["names of persons with role arranger"] }]
```

**Manual file links.** `POST /api/copies/{id}/files` `{ "fileId": 4711 }`
(librarian) links a file of the archive folder to a copy and returns the file
row. Such a file has `linked_manually: true`; the file sync never moves it to
another copy, also when its name starts with another shelf mark or when it
lies in a skipped folder. `DELETE /api/files/{id}/link` (file row id,
librarian) removes a manual link; afterwards the file name decides again. The
file sync report additionally contains `linkedManually` (count).

File rows (`files[]` of the entry) additionally contain `linked_manually`,
`sha256`, `sha256_at` (Unix time of the last check) and `sha256_mismatch`.

### Persons, contributions and the protection proposal

**Person**

```json
{ "id": 51, "name": "Joseph von Eichendorff", "gnd": "118529692", "wikidata": "Q76476",
  "born": "1788", "born_precision": "year", "died": "1857-11-26", "died_precision": "exact",
  "life_dates_text": "1788-1857", "source": "…", "identification": "certain|probable|uncertain",
  "checked_by": "uid or null", "checked_by_name": "…", "checked_at": "2026-10-22", "notes": "…",
  "created": 1761150000, "modified": 1761150000 }
```

Precision (`born_precision`, `died_precision`): `exact` (value `YYYY-MM-DD`),
`year` (`YYYY`, negative before Christ), `circa` (approximate, year optional),
`living` (death only), `unknown`. A value without precision is read as `exact`
or `year`. `life_dates_text` keeps the dates as found. New persons start with
`identification: uncertain`. Changing name, GND, Wikidata, life dates or
identification removes `checked_by`/`checked_at`; only "mark as checked" sets
them. Lists (`GET /api/persons`) add `contributions` (count) and `blocking`
(why the person prevents a protection date, or `null`).

**Contribution** (person in a version)

```json
{ "id": 7, "version_id": 35, "person_id": 51, "role": "composer|lyricist|arranger|translator|text_editor|editor|unspecified",
  "text_for_composition": false, "created": 1761150000, "person": { … } }
```

`text_for_composition` documents § 65 (3) UrhG (text written for this
composition); it does not change the proposal. Contributions are recorded in
the audit log of the version (`contribution_added`, `contribution_changed`,
`contribution_removed`).

**Protection proposal** (display only, never changes the rights status;
German law, no legal advice): protection ends at the end of the 70th calendar
year after the death of the last of all persons involved. Unknown means
protected: without persons, or when any person is living, has no secured year
of death (`unknown`, `circa`) or is identified `uncertain`, the status is
`unknown` and there is no "public domain" proposal. Missing names never lead
to the term for anonymous works.

```json
"protection": { "status": "free|protected|unknown", "until": "1927-12-31", "freeSince": "1928-01-01",
  "reason": "ended", "parameters": ["Joseph von Eichendorff", 1857, 1927], "decisivePersonId": 51,
  "blocking": [{ "personId": 182, "name": "A. Example", "reason": "death_unknown", "reasonText": "…" }],
  "reasonText": "translated explanation", "notice": "translated: proposal only, no legal advice" }
```

Reason keys: `no_persons`, `living`, `death_unknown`, `death_circa`,
`identification_uncertain` (status `unknown`), `running` (`protected`),
`ended` (`free`). The entry (`GET /api/entries/{id}`) additionally contains
`contributions` (of the copy's version, with persons), `protection` and
`merges` (merges of this work or copy that can be undone).

| Method and path | Body | Role |
|---|---|---|
| `GET /api/persons?q=` | – | any role |
| `GET /api/persons/{id}` | – | any role; `{ person, contributions: [{ …, title, composer, workId, copies: [{ id, shelfmark, status }] }] }` |
| `POST /api/persons` | `{ person: { name, gnd?, wikidata?, born?, born_precision?, died?, died_precision?, life_dates_text?, source?, identification?, notes? } }` | librarian |
| `PATCH /api/persons/{id}` | `{ person: { …changed fields }, reason? }` | librarian |
| `POST /api/persons/{id}/checked` | – | librarian (sets `checked_by`, `checked_at`) |
| `DELETE /api/persons/{id}` | – | librarian; only without contributions |
| `GET /api/versions/{id}/contributions` | – | any role; `{ contributions, protection }` |
| `POST /api/versions/{id}/contributions` | `{ personId, role, textForComposition? }` | librarian |
| `PATCH /api/contributions/{id}` | `{ contribution: { role?, text_for_composition? } }` | librarian |
| `DELETE /api/contributions/{id}` | – | librarian |
| `GET /api/protection/review` | – | any role: resubmission list |
| `POST /api/persons/import` | `{ fileId, dryRun }` | librarian |

Resubmission rows (`GET /api/protection/review`), versions with copies only:

```json
[{ "versionId": 35, "kind": "ended|conflict|upcoming", "rightsStatus": "protected", "confirmed": false,
   "title": "…", "composer": "…", "workId": 35, "copies": [{ "id": 35, "shelfmark": "10-46", "status": "active" }],
   "protection": { … } }]
```

`ended`: marked protected, but by the proposal free (to check); `conflict`:
marked public domain, but the recorded persons do not allow a "public domain"
proposal; `upcoming`: protected with a known end. Sorted in this order, then
by date.

**Person import** reads a research list (CSV, `;`, UTF-8 with or without
BOM) with the columns `Name; Werke; Signaturen; Lebensdaten laut Verzeichnis;
Lebensdaten recherchiert; Schutz bis; Status; Quelle; Geprueft am`
(`Name` and `Signaturen` are required). Researched dates win; the other
columns are kept in the notes. Role words in names ("Tonsatz von …",
", op. 62") are removed. Identification is at most `probable`
(`uncertain` for "NICHT ERMITTELBAR" or without dates). Each shelf mark
becomes a contribution with role `unspecified`. A repeated import finds
persons by name, never changes checked persons, only fills empty values and
adds missing contributions. A dry run rolls back; a real import into a
non-empty register first stores
`_notenarchiv-app/…_persons_before-person-import.csv` (for people; cells that
start like a formula get a leading `'`). Report:

```json
{ "dryRun": true, "source": "…csv", "sha256": "…", "operation": null, "snapshot": null, "rows": 30,
  "created": 28, "existing": 2, "updated": 0, "skipped": 0, "contributions": 33, "contributionsExisting": 0,
  "precision": { "unknown": 2, "year": 26 }, "identification": { "probable": 26, "uncertain": 2 },
  "renamed": [{ "from": "Tonsatz von …", "to": "…" }], "unknownShelfmarks": [],
  "protectionDiffers": [{ "name": "…", "list": "31.12.1982", "computed": "31.12.1967" }], "warnings": ["translated"] }
```

The weekly backup (ODS) additionally contains the sheet `Persons` with the
contributions of each person.

### Merging works and moving copies

Proposals: `GET /api/merges/candidates` (any role) lists works (not merged,
with copies) that have the same title and composer surname:

```json
[{ "title": "Tischlied", "works": [{ "workId": 27, "work_no": null, "title": "…", "composer": "…",
   "copies": [{ "id": 27, "shelfmark": "1-23", "versionId": 27, "status": "active" }] }, { … }] }]
```

- **Merge works** `POST /api/merges/works` `{ sourceWorkId, targetWorkId, reason? }`
  (librarian): all versions of the source work move to the target work; the
  source work gets `merged_into` and disappears from lists (never deleted);
  the target work gets a work number `W-0001`, … if it has none. Refused when
  both works have a valid version (the conductor must clear one first).
- **Move a copy** `POST /api/merges/copies` `{ copyId, targetVersionId, reason? }`
  (librarian), e.g. a second copy of the same print. Returns
  `{ merge, emptyVersionId }`; `emptyVersionId` is the former version when it
  has no copies left (it is kept).
- **Undo** `POST /api/merges/{id}/undo` `{ reason? }` (librarian), as long as
  the moved data is unchanged; later merges must be undone first. A work
  number given by the merge stays.

Programme lines follow the moved versions and copies. A merge never changes a
rights status. Audit actions: `merged`, `merge_undone` (on work, version,
copy) and `created`/`undone` (object type `merge`).

`GET /api/merges?all=0|1` (any role; `all=1` includes undone merges):

```json
[{ "id": 3, "kind": "work|copy", "source_id": 40, "target_id": 27, "reason": "…", "created_by": "…",
   "created_by_name": "…", "created": 1761150000, "undone": null, "undone_by": null, "undone_by_name": null,
   "moved": { "versions": [40], "eventItems": [], "workNo": "W-0001" },
   "source": { "title": "…", "composer": "…", "work_no": null }, "target": { "title": "…", "composer": "…", "work_no": "W-0001" } }]
```

For `kind: copy`, `source_id` is the copy, `target_id` the version, `moved`
holds `fromVersion` and the programme lines, and `source` holds `shelfmark`
and `copyId`. The `merges` of an entry (`GET /api/entries/{id}`) have the
same form. Display names are the current names of the accounts (the account
id if the account no longer exists).

### Inventory

`GET /api/inventory?binder=5&all=0|1` (any role; without `binder` the lowest
binder; `all=1` also lists copies that are not active):

```json
{ "binder": 5, "binders": [{ "binder": 5, "active": 5, "total": 5, "oldestCount": "2026-01-15", "uncounted": 2 }],
  "rows": [{ "copyId": 35, "shelfmark": "5-1", "binder": 5, "number": 1, "status": "active", "status_reason": null,
             "kind": "original", "originals_count": 30, "copies_count": 0, "last_counted": "2026-01-15", "pages": "4",
             "title": "…", "composer": "…", "arranger_text": "…", "preservation_copy": false }] }
```

Rows are in shelf order, for an A4 list with columns to tick.
`POST /api/inventory/count` `{ counts: [{ copyId, originals?, copies? }], date? }`
(librarian, operations; `date` defaults to today, not in the future) sets
`last_counted` and, where given, `originals_count`/`copies_count` through the
catalog rules, all or nothing; returns `{ "recorded": 1 }`. At most 2,000
entries per request (400 `Too many entries at once (at most %s).`).

### Checksums

A background job (hourly, at most 100 files or 256 MB per run, round by
round) reads the archive files through the archive user and stores their
SHA-256. A changed size or modification time means an intended change: the
new checksum is stored (the file sync also drops the old checksum then). Same
size and time with a different checksum means silent corruption: the file
gets `sha256_mismatch: true`, the old checksum is kept as reference and the
librarian is notified once. Restoring the file clears the mark; otherwise the
librarian accepts the new content.

`GET /api/checksums` (any role):

```json
{ "files": 128, "hashed": 100, "pending": 28, "oldestCheck": 1761150000, "perRun": 100,
  "mismatches": [{ "id": 7, "copyId": 35, "shelfmark": "5-1", "title": "…", "path": "Mappe_5/…pdf", "name": "…",
                   "file_id": 4711, "sha256": "…", "sha256_at": 1761150000 }] }
```

`POST /api/files/{id}/checksum-accept` `{ reason? }` (file row id, librarian)
accepts the current content; the next run stores the new checksum.

### Notifications

| Subject | To | When |
|---|---|---|
| `protection_ended` | librarian | daily: a version is marked protected, but by the life dates of all persons involved it is public domain since `date` (task: check; nothing changes automatically). Link `?copy={id}`. Withdrawn when the status changes. |
| `checksum_mismatch` | librarian | a file changed without a visible modification. Parameters: shelf mark, title, file type (no file name). Link `?copy={id}` or `?view=checksums`. Withdrawn when the file is restored or the change is accepted. |

## Statistics and annual report

Read only, for every archive role (and admins); 403 without access. The
figures contain no personal data of choir members and no account names
(composers and other persons of the register are fine); internal
preservation copies are counted but never listed by title or shelf mark
(`omitted` counts them where a list would name them). Rights, stock and
maintenance figures are those of today; concerts, new copies and protection
terms follow the period. Years of timestamps (audit log) follow the time zone
of the user. The UI opens the view with `?view=statistics`.

`GET /api/statistics?from=YYYY&to=YYYY` — both optional: `to` defaults to the
current year, `from` to the ten years up to `to`. A period must lie between
1800 and 50 years after the current one, be at most 100 years long and not
end before it starts; otherwise 400
`Please choose a period between %1$s and %2$s of at most %3$s years, the first year not after the last.`

```json
{ "period": { "from": 2017, "to": 2026 }, "today": "2026-10-22", "range": { "first": 1987, "last": 2026 },
  "stock": { "works": 120, "versions": 130, "copies": 150,
    "status": { "active": 140, "retired": 5, "missing": 3, "lent": 2, "other": 0 },
    "binders": [{ "binder": 1, "active": 30, "retired": 1, "missing": 0, "lent": 0, "other": 0, "total": 31 }],
    "active": 140, "activeWithFile": 120, "activeWithMusicxml": 40, "preservationVersions": 2, "preservationCopies": 3,
    "additions": [{ "year": 2025, "accession": 4, "import": 0, "other": 0, "total": 4 }] },
  "rights": {
    "status": [{ "status": "public_domain|protected|open|none", "confirmed": 50, "unconfirmed": 60, "total": 110 }],
    "validVersions": { "works": 120, "withValid": 30, "withoutValid": 90, "withoutValidSeveral": 5, "currentWithoutValid": 3 },
    "licenses": { "total": 10, "active": 6, "ending": 1, "ended": 2, "future": 1, "endingDays": 30 },
    "portal": { "ok": 30, "remove": 1, "add": 4, "not_decided": 6, "other_version_valid": 2, "blocked": 87 },
    "printStation": { "ok": 0, "remove": 0, "add": 5, "not_decided": 6, "other_version_valid": 2, "blocked": 117 },
    "publication": { "free": 10, "blocked": 2, "internal": 3, "to_clarify": 20, "none": 95 } },
  "repertoire": {
    "years": [{ "year": 2025, "concerts": 3, "planned": 0, "pieces": 41, "works": 35 }], "undated": 0,
    "topWorks": [{ "workId": 1, "title": "…", "composer": "…", "performances": 5, "last": "2025-12-06" }],
    "topComposers": [{ "composer": "…", "performances": 12, "works": 4 }],
    "classes": { "current": 20, "dormant": 30, "earlier": 10, "none": 60 }, "sources": { "performances": 40, "import": 25 },
    "thresholds": { "current": 3, "dormant": 10 },
    "longNotSung": [{ "workId": 7, "title": "…", "composer": "…", "class": "dormant|earlier", "performances": 9,
                      "last": "2014-05-01", "copyId": 12, "shelfmark": "2-4" }], "longNotSungTotal": 31 },
  "maintenance": {
    "tasks": [{ "addressee": "conductor", "open": 3, "answered": 1, "days30": 2, "days90": 1, "days365": 0, "older": 1 }],
    "proposals": { "open": 4, "olderThan30Days": 1 },
    "checksums": { "files": 128, "hashed": 100, "pending": 28, "mismatches": 0 },
    "protection": { "years": [{ "year": 2026, "versions": 1 }], "total": 3, "omitted": 0,
      "rows": [{ "versionId": 35, "title": "…", "composer": "…", "until": "2026-12-31", "rightsStatus": "protected",
                 "confirmed": false, "copies": [{ "id": 35, "shelfmark": "10-46", "status": "active" }] }] } } }
```

- `stock.status`, the binder rows and `additions` add up to `copies`
  (`additions` over all years with data). `activeWithMusicxml` follows the
  rule of the catalog list (`has_musicxml`). `additions`: the year the audit
  log records the creation of a copy, by the accession assistant
  (`accession`) or the catalog import (`import`); copies without such an
  entry count with their creation date (`other`). One row per year of the
  period.
- `rights.status` (always the four keys in this order), `portal`,
  `printStation` and `publication` each add up to `versions`. `portal` and
  `printStation` are the states of the release lists (`GET /api/lists/…`
  with `all=1`); the print station knows no `ok` and `remove`. License groups
  do not overlap: `ended` (before today), `future` (not valid yet), `ending`
  (within `endingDays` days), `active` (the rest).
- `validVersions`: listed works (not merged) with and without a version
  chosen as valid; `withoutValidSeveral` of those have more than one
  version, `currentWithoutValid` belong to the current repertoire.
- `repertoire.years`: one row per year of the period. `concerts` are
  concerts confirmed as sung (also imported ones), `planned` the others,
  `pieces` the documented performances (programme lines that count),
  `works` the different works among them; `undated` counts sung concerts
  without a date. `topWorks` and `topComposers` (at most 10) count
  performance days in the period, the composer as recorded for the work.
  `classes` (adding up to `works`) as in the catalog list: from documented
  performances, otherwise from the imported catalog, otherwise `none`.
  `longNotSung` (at most 20 of `longNotSungTotal`): works of class
  `dormant` or `earlier` with an active copy that is no preservation copy,
  the most often sung first; `copyId` opens the copy.
- `maintenance.tasks`: one row per role a task can be addressed to; open
  and answered tasks by age in days since they were created. `protection`:
  versions with copies whose proposed protection (by the life dates, see
  milestone 0.5) ends in the period; `rows` at most 50, the soonest first.

`GET /api/statistics/report?year=YYYY` (default: the current year) returns
the annual report:

```json
{ "year": 2025, "today": "2026-10-22",
  "concerts": [{ "id": 3, "date": "2025-12-06", "title": "Advent concert", "location": "…", "status": "sung", "imported": false, "pieces": 12 }],
  "totals": { "year": 2025, "concerts": 3, "planned": 0, "pieces": 41, "works": 35 },
  "additions": { "year": 2025, "accession": 4, "import": 0, "other": 0, "total": 4 },
  "accessions": [{ "copyId": 81, "shelfmark": "10-75", "title": "…", "composer": "…", "date": "2025-03-01" }], "accessionsOmitted": 0,
  "licenses": { "validOn": "2025-12-31", "valid": 6, "omitted": 0,
    "started": [{ "licenseId": 4, "title": "…", "composer": "…", "holder": "…", "uses": ["binder"], "validFrom": "2025-02-01",
                  "validUntil": "2026-01-31", "shelfmarks": ["5-1"] }], "ended": [] },
  "openRights": { "open": 10, "none": 0, "publicDomainUnconfirmed": 60, "protectedUnconfirmed": 2, "protectionEnded": 1,
                  "conflicts": 0, "rightsTasks": 2 },
  "stock": { "works": 120, "versions": 130, "copies": 150, "active": 140 } }
```

`totals` equals the row of the year in `repertoire.years` of the statistics;
`pieces` of a sung concert are its documented performances, of a planned one
the lines not struck. `accessions` lists the copies of the accession
assistant recorded in the year. `licenses.valid` counts the licenses valid
on `validOn` (the last day of the year, today for the current year);
`started` (by `valid_from`, without it by the day it was recorded) and
`ended` (by `valid_until`) list those of the year. `openRights` and `stock`
are those of today; `protectionEnded` and `conflicts` match the kinds
`ended` and `conflict` of `GET /api/protection/review`.

`GET /api/statistics/csv?from=YYYY&to=YYYY` and
`GET /api/statistics/report/csv?year=YYYY` download the same tables as one
CSV file for people (UTF-8 with BOM, `;`, each table under its heading,
texts in the user's language, cells that start like a formula get a leading
`'`). Plain download links without CSRF token; errors as JSON
(`{ "error" }`, 400/403).

## Modules and the personal view (milestone 0.5.1)

Every function outside the core is a module that admins can switch off
(register: `lib/Service/Modules.php`). The core is always on: catalog
(works, versions, copies, fields, search, status), start page, file sync,
manual file links, roles, audit log, CSV import and export, backup and
restore, settings.

A module is **active** when its own switch is on and every module it
requires is active. Switching a module on also switches on what it requires;
switching it off leaves the switches of the modules that need it as they are
(they are inactive until it is on again). Switching off never deletes data
and never relaxes a check: the rights policy for viewing, playback, practice
audio and preservation copies applies whatever is switched on.

| Key | Requires | Default (preset "standard") |
|---|---|---|
| `rights` | – | on |
| `valid_version` | – | on |
| `persons` | – | on |
| `protection_terms` | `persons` | on |
| `releases` | `rights` | on |
| `portal` | `releases` | on |
| `print_station` | `releases` | off |
| `print_mirror` | `print_station` | off, and part of no preset (writes files into a target folder) |
| `publication` | `releases` | on |
| `score_viewer` | – | on |
| `playback` | `score_viewer` | on |
| `practice_audio` | `playback` | off |
| `excerpt_links` | `score_viewer` | on |
| `transcription_review` | `score_viewer` | on |
| `corrections` | `score_viewer` | on |
| `files_integration` | – | on |
| `tasks` | – | on |
| `proposals` | – | off |
| `concerts` | – | on |
| `performance_import` | `concerts` | on |
| `repertoire` | `concerts` | on |
| `calendar` | `concerts` | on |
| `accession` | – | on |
| `merge` | – | on |
| `inventory` | – | on |
| `folder_book` | – | off (on in "complete") |
| `checksums` | – | off |
| `statistics` | – | off |
| `dashboard_widget` | – | on |
| `notifications` | – | on |
| `notify_{subject}` | `notifications` and the module of the subject | on |
| `member_links` | `excerpt_links`, `playback`, `releases` | off, and part of no preset |
| `member_practice_audio` | `member_links`, `practice_audio` | off, and part of no preset |
| `public_links` | `excerpt_links`, `playback`, `publication` | off, and part of no preset |
| `public_repertoire` | `rights` | off, and part of no preset |
| `agent_api` | – | off, and part of no preset (the API for agents, [api-v1.md](api-v1.md)) |

`notify_{subject}` exists for every notification subject:
`notify_proposals_waiting` (needs `proposals`), `notify_portal_remove`
(`portal`), `notify_license_expiring` and `notify_license_expired`
(`rights`), `notify_export_failed`, `notify_task_new` and
`notify_task_answered` (`tasks`), `notify_concert_deadline` and
`notify_concert_confirm` (`concerts`), `notify_protection_ended`
(`protection_terms`), `notify_checksum_mismatch` (`checksums`),
`notify_review_points` (`transcription_review`),
`notify_correction_received` (`score_viewer`),
`notify_print_mirror_failed` (`print_mirror`).

`corrections` needs only `score_viewer`, not `transcription_review`: a
small correction is useful without transcription reports (e.g. after a
question from a singer). Points are only set to `corrected` while
`transcription_review` is active.

Presets: `simple` (core, `score_viewer`, `playback`, `notifications` with all
`notify_*` switches), `standard` (the column above), `complete` (everything
except `agent_api`, the four modules of the group `sharing` and
`print_mirror`). A preset sets every switch except these six
(`Modules::OUTSIDE_PRESETS`), which only their own switch changes.

### Switched-off routes

A route of a module that is not active answers **404** with
`{ "error": "This function is switched off." }` (translated), before any
other check of the app. The routes and their modules:

| Module | Routes |
|---|---|
| `rights` | `POST /api/versions/{id}/confirm`, `…/set-open`, `…/licenses`, `PATCH /api/licenses/{id}`, `GET /api/licenses/ending` |
| `valid_version` | `POST /api/versions/{id}/valid`, `…/valid-clear` |
| `portal` | `POST /api/versions/{id}/portal`, `…/portal-remove`, `GET /api/lists/portal` |
| `print_station` | `GET /api/lists/print-station` |
| `score_viewer` | `GET /api/files/{id}/content`, `GET /api/files/{id}/scan` |
| `transcription_review` | `GET /api/versions/{id}/review`, `POST /api/versions/{id}/transcription-reports`, `POST /api/review-points/{id}/decision`, `GET /api/review/orders` (and `score_viewer`) |
| `corrections` | `POST /api/versions/{id}/corrections` (and `score_viewer`) |
| `practice_audio` | `POST /api/versions/{id}/practice-audio` |
| `excerpt_links` | `POST /api/copies/{id}/links`, `GET /api/copies/{id}/link-options`, `GET /api/links`, `GET /api/links/{token}`, `POST /api/links/{id}/revoke` |
| `member_links` | `GET /api/member/links/{token}/score`; `POST /api/copies/{id}/links` with `circle: "members"`; the page "Shared with me" (`GET /` answers 403 for choir members) |
| `member_practice_audio` | `POST /api/member/links/{token}/practice-audio` (and `member_links`) |
| `public_links` | `GET /p/{token}` (the 404 page of the public pages instead of the JSON message); `POST /api/copies/{id}/links` with `circle: "public"` |
| `public_repertoire` | `GET /repertoire` (the 404 page of the public pages); setting `work.public_repertoire` to `true` |
| `files_integration` | `GET /api/nextcloud-files/{fileId}/copy` (and the script of the Files app is not loaded) |
| `tasks` | `GET /api/tasks`, `POST /api/copies/{id}/tasks`, `POST /api/tasks/{id}/answer`, `…/close` |
| `proposals` | `GET /api/proposals`, `POST /api/proposals`, `…/accept`, `…/reject` |
| `concerts` | `/api/events…`, `/api/event-items…` |
| `performance_import` | `POST /api/performances/import`, `GET /api/event-items/ambiguous`, `POST /api/event-items/{id}/resolve` (and `concerts`) |
| `repertoire` | `GET /api/repertoire`, `PUT /api/repertoire/thresholds` (and `concerts`) |
| `calendar` | `GET /api/events/{id}/ics`, `GET /api/events/upcoming/ics`, `GET /api/calendars`, `POST /api/events/{id}/calendar`, `POST /api/events/upcoming/calendar` (and `concerts`) |
| `persons` | `/api/persons…`, `/api/versions/{id}/contributions`, `/api/contributions/{id}` |
| `protection_terms` | `GET /api/protection/review` (and `persons`) |
| `accession` | `GET /api/accession/next`, `POST /api/accession`, `PUT /api/accession/open-binders`, `GET /api/labels` |
| `merge` | `/api/merges…` |
| `inventory` | `GET /api/inventory`, `POST /api/inventory/count` |
| `checksums` | `GET /api/checksums`, `POST /api/files/{id}/checksum-accept` |
| `folder_book` | `/api/folder-book…`, `GET`/`PUT /api/settings/folder-book` |
| `print_mirror` | `/api/print-mirror…`, `/api/settings/print-mirror…` (and `print_station`) |
| `statistics` | `/api/statistics…` |
| `agent_api` | every route of the API for agents, `/ocs/v2.php/apps/notenarchiv/api/v1/…` (404 with the message in the OCS meta); each also needs the module of its function |

`releases` and `publication` have no routes of their own: `releases` is the
switch of the three releases (it gates `portal`, `print_station` and
`publication`), `publication` only shows the publication status in the UI.
Fields of a switched-off module stay in the catalog, in `meta.fields`, in
the CSV and in the backup; the UI hides their maintenance.

What else follows the modules:

- `GET /api/entries/{id}`: `score` is `null` without `score_viewer`;
  `score.canPlay` is `false` with `reason` `Playback is switched off.`
  without `playback`; `score.canExportAudio` is `false` with
  `exportReason` `Practice audio files are switched off.` without
  `practice_audio` (when playback is allowed otherwise). `licenses` is `[]`
  without `rights`, `tasks` without `tasks`, `links` without
  `excerpt_links`, `proposals` without `proposals`, `contributions` without
  `persons`, `merges` without `merge`; `protection` is `null` without
  `protection_terms`. `GET /api/versions/{id}/contributions` returns
  `protection: null` without `protection_terms`.
- `GET /api/entries`: without `repertoire` the repertoire figures are those
  of the imported catalog (`repertoire_source: "import"`); without
  `concerts`, `song_lists` is empty, too; without `transcription_review`,
  `review_open` and `review_orders` are `null`.
- File sync: without `transcription_review` report files are registered and
  counted, but not read (`reports.switchedOff: true`).
- `GET /api/due`: only cards, tasks and concerts of active modules
  (`tasks_open`, `tasks_answered` and `tasks`: `tasks`; `ambiguous_items`:
  `performance_import`; `proposals`: `proposals`; `portal_remove`,
  `portal_add`: `portal`; `confirm_current`, `rule1_current`,
  `licenses_expiring`: `rights`; `valid_missing`: `valid_version`;
  `concerts`: `concerts`).
- Dashboard widget: only while `dashboard_widget` is on; its items are those
  of `GET /api/due` without the cards the account has hidden (personal view).
- Background jobs: the checksum job does nothing without `checksums`; the
  daily checks skip the parts whose notification is switched off and
  withdraw their notifications, and sync the print station only while
  `print_mirror` is active. The removal of personal data of the folder book
  after the retention period runs also without `folder_book`: switching off
  never makes anything less safe.
- Notifications: a notification whose `notify_{subject}` is not active is
  not sent; one sent before is withdrawn when it is shown (the Nextcloud
  notifications app then deletes it).
- Files app: without `files_integration` the script of the action "Open in
  music archive" is not loaded (see "Opening from the Files app" below);
  without `score_viewer` the route answers `score: false` and `?score=1`
  only opens the copy.

### Module settings (settings rights)

Like the other settings: admins and the groups given the settings page
(see "Settings").

`GET /api/settings/modules` (texts translated):

```json
{ "modules": [{ "key": "playback", "group": "scores", "label": "Playback", "description": "…",
                "requires": ["score_viewer"], "enabled": true, "active": false }],
  "groups": [{ "key": "scores", "label": "Score view" }],
  "presets": [{ "key": "simple", "label": "Simple", "description": "…", "modules": { "rights": false, "...": "..." } }],
  "core": ["Catalog: works, versions, copies, fields, search and status", "…"] }
```

`enabled` is the module's own switch, `active` whether it works (its switch
and everything it requires is on). `requires` lists the direct
requirements. Planned modules (none at the moment) are not listed.
`agent_api` is in the group `interfaces`, the four sharing modules in the
group `sharing` ("Sharing beyond the archive roles"); all five are missing
from the `modules` of every preset (no preset changes them).

`PUT /api/settings/modules` with either `{ "modules": { "concerts": false } }`
(one or more switches) or `{ "preset": "standard" }` returns the same plus

```json
"changes": { "enabled": ["calendar"], "alsoEnabled": ["concerts"], "disabled": [],
             "inactive": [], "activated": ["repertoire"] }
```

`enabled`/`disabled`: switches that changed as requested; `alsoEnabled`:
requirements switched on as well; `inactive`: modules whose switch stays on
but that no longer work (they need a module switched off now);
`activated`: modules that work again without being switched themselves.
Errors (400): `Unknown module: %s`, `Unknown preset: %s`, `This function is
not available yet: %s`, `"%1$s" needs "%2$s".` (a module to switch on needs
one to switch off in the same request), `Please choose a module or a
preset.` (none or both), `"%s" must be yes or no.`. Every changed switch is
recorded in the audit log (object type `settings`, field `module_{key}`,
`on`/`off`, reason `preset {name}` for a preset). No password confirmation,
like the other settings.

On the command line: `occ notenarchiv:modules list|enable|disable|preset`
(see the admin guide).

### Personal view

`PUT /api/personal-view` `{ "navigation": ["statistics"], "cards": ["proposals", "concerts"] }`
(any role) replaces what the account hides and returns it. Entries are the
names the UI uses: views of the navigation (the `ViewName` values of
`src/startParams.ts`, e.g. `tasks`, `statistics`, `songLists`) and cards of
the start page (keys of `GET /api/due`, plus `concerts` and `tasks` for the
two lists). Each list holds at most 50 distinct names of the form
`[a-z][A-Za-z0-9_]{0,39}`; other values are refused with 400 (`"%s": value
not allowed.`, `Too many entries at once (at most %s).`). Unknown names hide
nothing. Stored on the server as the user setting `personal_view` of the
app. It only hides: permissions stay the same.

## Reviewing transcriptions and corrections (milestone 0.6)

A transcription (scan → MusicXML, e.g. with the skill `noten-pdf-to-musicxml`)
ends with a report in the format `bericht/1` (`bericht.json`). The app reads
it into **review points** with a location in the score (measures, voice),
which the conductor and the catalog librarian decide in the score view.
Small corrections (pitch, accidental, syllable) are saved in the app as a
**new file** next to the old one; everything larger becomes a **correction
order** for the tool chain, whose next report closes the points.

Modules: `transcription_review` (requires `score_viewer`) for reports, review
points, decisions and orders; `corrections` (requires `score_viewer`) for the
small corrections in the app. See "Modules and the personal view" above.

Services for other callers (e.g. the agent interface): `ReviewService`
(`ingest`, `ingestForCopy`, `forVersion`, `decide`, `orders`),
`CorrectionService` (`correct`), `TranscriptionReportParser::parse` (pure,
no server needed), `MusicXmlCorrection::apply` (pure). They check roles and
modules themselves; the controllers `ReviewController` and
`ApiV1ReviewController` (API for agents, [api-v1.md](api-v1.md): review of
a copy, correction orders, reports; no decisions, no corrections) only map
requests to them.

### Reading a report

A report reaches the app in two ways:

1. **File sync.** A file in the archive folder whose name starts with a shelf
   mark and ends with `bericht.json` (case-insensitive, e.g.
   `5-01_Titel - Bericht.json`) is registered with the role
   `transcription_report`, not as a score file. While `transcription_review`
   is active, the sync reads every report file that is new, changed or not
   read yet, as the account that runs the sync. The sync report gets
   `reports`:

   ```json
   "reports": { "files": 2, "assigned": 2, "imported": 1, "unchanged": 1,
                "failed": [{ "path": "Mappe_5/5-01_X - Bericht.json", "error": "…translated…" }],
                "switchedOff": false }
   ```

   Report files are not counted in `files`, `assigned` and `byType` (score
   and scan files only); `new`, `changed` and `missing` count all file rows,
   reports included; an unassignable report is listed in `unassignable`.
   `switchedOff: true`: the module is off, the files are registered but not
   read. `imported` counts reports read with new content, `unchanged`
   report files not read again (or read with a known checksum).
2. **API.** `POST /api/versions/{id}/transcription-reports` (roles
   `librarian`, `contributor`; 30 per 10 minutes) with
   `{ "content": "<the JSON text of bericht.json>" }` or
   `{ "report": { …the object… } }`.

Both give (the API as response, the sync per file internally):

```json
{ "report": { "id": 4, "…": "report row, see below" }, "unchanged": false,
  "points": { "created": 5, "kept": 1, "closed": 2, "open": 6 },
  "proposals": 2, "proposalsSkipped": false,
  "warnings": ["…translated…"] }
```

`unchanged: true`: the same report (same SHA-256 of the received text) was
read for this copy before; nothing is created again. `kept`: points the
report names again that already exist (same category, measures, voice and
text). `closed`: points of earlier reports of this copy that the new report
no longer names and whose MusicXML has changed since (see "New round").
`open`: points of the copy with status `open` after reading.

**Checks.** The report must be a JSON object of at most 1 MB, nested at most
16 levels, with `"format": "bericht/1"`. `signatur` must be a shelf mark
(`5-1`, leading zeros allowed) of a copy of the version in the URL (sync: of
the copy the file belongs to). `basisname` (the name of the result files
without extension) must not contain `/`, `\` or control characters and must
not be `.` or `..`, at most 200 characters; if it starts with a shelf mark,
it must be the same. Every known field is type-checked: a wrong type of a
field the app uses is refused with 400, of the other fields it only gives a
warning. Unknown fields are ignored and named in a warning. The received text
is kept unchanged as evidence (`raw`, with its SHA-256) and never interpreted
beyond the known fields. Errors (400): `The report is not valid JSON.`,
`The report must be a JSON object.`, `The report is too large (at most %s
MB).`, `The report is nested too deeply.`, `The report has an unknown format
(expected bericht/1).`, `"%s" is missing in the report.`, `"%s" in the
report must be text.` (also `… must be a whole number.`, `… must be a
list.`, `… must be an object.`, `… must be yes or no.`; `%s` is the path of
the field, e.g. `unsicher[2].was`), `"%s" in the report is too long.`,
`"%s" in the report has too many entries.`, `The shelf mark in the report
is not valid.`, `The base name in the report is not a valid file name.`,
`The base name in the report belongs to another shelf mark.`, `The shelf
mark of the report does not belong to this version.` (API) or `The shelf
mark of the report does not match its file name.` (file sync), `Please send
the report either as text or as an object.`. Warnings (in `warnings`, the
report is read): unknown fields, a missing field the format requires, an
unknown `weg` or `einstufung`, a `datum` that is no date, a wrong type of a
field the app only shows (`vorlage`, `umfang`, `gegenprobe`, `geprueft`,
`aufwand`, `lernen`, `pruefpunkte`, `generator`, `scores`).

**What becomes what.**

| Report field | Becomes | Responsible role |
|---|---|---|
| `unsicher[]` `{takt, stimme, was}` | review point `uncertain` | conductor |
| `vortragszeichen.ausgelassen[]` (text; a leading `T. 23` is read as measures) | review point `omitted` | conductor |
| `material[]` | review point `material` | librarian |
| `rueckfragen[]` (text; `T. 23` anywhere is read as measures) | review point `question` | conductor with measures, otherwise librarian |
| `rechte.einstufung` (`gemeinfrei`/`geschützt`/`offen`) | proposal `version.rights_status` (only if different); reason: `wv.begruendung` and `rechte.beteiligte` | – |
| `wv.felder` `{ "<legacy column or level.field>": value }` | one proposal per field (only fields the librarian may write, only if different) | – |
| `wv.herkunft`, `wv.pruefstand`, `wv.begruendung` | shown with the version as the review state of the transcription (`origin`, `review`, `reasoning` of the report) | – |

Proposals go to the inbox with the source `Transcription <round or date>`
(`round` from the optional field `runde`, otherwise `datum` or the day of
reading) and only while the module `proposals` is active; otherwise
`proposalsSkipped: true` and a warning, and the values stay readable in the
report. The catalog itself never changes.

Measures are printed measure numbers as in the score: `"23"`, `"12–14"`
(also `12-14`, `T. 12–14`, `Takt 5`); `measure_from`/`measure_to` are filled
when they look like measure numbers (`[0-9A-Za-z.]{1,16}`), `measures` keeps
the text (at most 32 characters). `stimme` `alle`/`all`/`tutti` means no
voice.

**MusicXML of a report.** The MusicXML file of the copy whose name without
extension is `basisname` (the valid one or `.mxl` first, like the score
view). Its SHA-256 is stored with the report (`musicxml_sha256`) and with
every point created (`file_sha256`); points refer to it by `file_id`
(`files[].id`). Without such a file the points have no file and a warning
says so.

**New round.** A different report for the same copy supersedes the earlier
ones (`superseded_by`). Points of earlier reports with the status `open` or
`correct` that the new report does not name again are set to `corrected`
("done in a new version", `resolved_report_id`) when the MusicXML of the new
report differs from the file of the point (checksums); when the MusicXML is
unchanged they stay open and a warning says so. Everything is recorded in the
audit log (object types `review_report` and `review_point`).

**Proposed extension of `bericht/1`** (optional fields, read when present,
ignored otherwise; not part of the skill yet):

| Field | Meaning |
|---|---|
| `runde` | round of the transcription (text or number), for the source of the proposals |
| `datum` | day of the transcription (`YYYY-MM-DD`) |
| `unsicher[].seite`, `unsicher[].strophe` | page of the scan and verse of the point |
| `rueckfragen[]` as object `{ "frage": "…", "takt": "23", "stimme": "B", "an": "chorleitung" \| "archiv" }` | question with a location and an addressee |
| `erledigt` | list of review point ids (from `GET /api/review/orders`) or `{ "takt": "23", "stimme": "B" }` that the new file has done; they are closed like points no longer named (only when the MusicXML changed) |

### Review points

`GET /api/versions/{id}/review` (any role):

```json
{ "version_id": 3,
  "reports": [{ "id": 4, "version_id": 3, "copy_id": 5, "report_file_id": 17, "source": "file|api",
                "sha256": "…", "round": "39", "report_date": "2026-10-22", "basename": "5-01_Titel",
                "musicxml_id": 12, "musicxml_sha256": "…", "method": "neuaufbau",
                "origin": "…wv.herkunft…", "review": "…wv.pruefstand…", "reasoning": "…wv.begruendung…",
                "rights_class": "gemeinfrei|geschützt|offen|null",
                "summary": { "title": "…", "template": { "typ": "druck", "beschreibung": "…", "seiten": 2, "schreiber_oder_verlag": "…" },
                             "extent": { "takte": 32, "auftakt": true, "stimmen": 4, "systeme": 1, "strophen": 2 },
                             "persons": [{ "rolle": "…", "name": "…", "lebensdaten": "…", "beleg": "…" }],
                             "edition": "…", "rights": "…", "fields": { "Verlag / Bestellnummer": "…" },
                             "warnings": ["…translated…"] },
                "superseded_by": null, "created_by": "…", "created": 1761150000 }],
  "points": [{ "id": 12, "version_id": 3, "copy_id": 5, "report_id": 4, "file_id": 12, "file_sha256": "…",
               "category": "uncertain|omitted|material|question", "measures": "12–14",
               "measure_from": "12", "measure_to": "14", "voice": "B", "verse": null, "page": null,
               "text": "d or c", "point_key": "…", "role": "conductor|librarian",
               "status": "open|confirmed|correct|corrected|question|dismissed",
               "correction": null, "decided_by": null, "decided_by_name": null, "decided": null,
               "decision_reason": null, "task_id": null, "resolved_report_id": null,
               "created_by": "…", "created": 1761150000 }],
  "counts": { "open": 3, "question": 0, "correct": 1, "confirmed": 2, "corrected": 1, "dismissed": 0 },
  "may": { "decideMusical": true, "decideMaterial": true, "ingest": true, "correct": true } }
```

Reports newest first (without `raw`); points in the order they were created.
Any field of `summary` may be missing or `null`. `may` tells the UI what the
account may do (the server checks again): `decideMusical` (points of the
role `conductor`: librarian or conductor), `decideMaterial` (points of the
role `librarian`: librarian), `ingest` (librarian, contributor), `correct`
(module `corrections` active, librarian or conductor, not a preservation
copy).

`POST /api/review-points/{id}/decision`

```json
{ "status": "confirmed|correct|question|dismissed|open", "correction": "for correct",
  "reason": "optional, required for dismissed", "addressee": "conductor|librarian (question only)" }
```

returns the point. Statuses:

| Status | Meaning | Set by |
|---|---|---|
| `open` | not decided (also: reopen a decided point) | report; any decider |
| `confirmed` | the reading is right | decider |
| `correct` | correction order for the tool chain; `correction` (text, required) says what to change | decider |
| `corrected` | corrected in the app or done in a new version | the app only (correction, new round) |
| `question` | turned into a question (task) with the point's location; `task_id` links it | decider (needs the module `tasks`) |
| `dismissed` | not relevant; `reason` required | decider |

The catalog librarian may decide every point; the conductor decides the
points of the role `conductor` (musical points). Decider, time and reason are
stored; nothing is deleted. A `question` creates a task (kind `question`) for
`addressee` (default: the conductor when the librarian asks, otherwise the
librarian) with measures, voice, verse and page of the point; without the
module `tasks` the answer is 404. Errors (400): `"%s": value not allowed.`,
`Please describe the correction.`, `Please give a reason.`, `The review point
already has this status.`, `A review point is set to corrected only by a
correction or a new version.`; 403 `Only the catalog librarian decides this
review point.`.

`GET /api/review/orders` (any role, also `contributor`): the correction
orders (status `correct`) for the tool chain, oldest first:

```json
[{ "id": 12, "version_id": 3, "copy_id": 5, "shelfmark": "5-1", "title": "…", "basename": "5-01_Titel",
   "file_id": 12, "file_name": "5-01_Titel.mxl", "file_sha256": "…",
   "measures": "12–14", "measure_from": "12", "measure_to": "14", "voice": "B", "verse": null,
   "category": "uncertain", "text": "d or c", "correction": "d, as in the scan",
   "decided_by_name": "…", "decided": 1761150000 }]
```

Each order is the full point row (see above) plus `shelfmark`, `title`,
`basename` (of the report that named it), `file_name` (of the MusicXML
the point was made for) and `preservation_copy` (the version is an internal
preservation copy; the API for agents leaves such orders out for roles that
may not view them).

The tool chain closes an order with its next report for the same copy: by
naming the id in `erledigt`, by `{ "takt", "stimme" }` in `erledigt`, or by no
longer naming the point (in each case only when the MusicXML changed).

The catalog rows (`GET /api/entries`) get `review_open` (points with status
`open` or `question`) and `review_orders` (status `correct`) of the copy;
both are `null` while `transcription_review` is off.

### Small corrections in the app

`POST /api/versions/{id}/corrections` (module `corrections`; roles
`librarian`, `conductor`; 30 per 10 minutes):

```json
{ "fileId": 12, "content": "<the whole changed MusicXML as text>", "pointIds": [12, 13], "reason": "optional" }
```

`fileId` is the score file shown (`score.fileId`, `files[].id`) of a copy of
the version; `content` is plain MusicXML (also for an `.mxl`: the server
packs it). The server reads the old file with the account's own access,
checks it like the score view (only real MusicXML, at most 32 MB, no
document type declaration with an internal subset) and compares both
documents element by element. **Allowed** are only:

- `note/pitch/step` (A–G), `note/pitch/octave` (0–9), `note/pitch/alter`
  (−2 to 2; added, changed or removed),
- `note/accidental` (added, changed or removed; values `sharp`, `natural`,
  `flat`, `double-sharp`, `sharp-sharp`, `flat-flat`, `natural-sharp`,
  `natural-flat`; attributes `cautionary`, `editorial`, `parentheses`,
  `bracket` with `yes`/`no`),
- the text of `note/lyric/text` (a syllable, at most 200 characters, no
  line breaks or other control characters).

Everything else must be equal (white space between elements, comments and
processing instructions do not count): no other element, attribute or
text, no other note values, no notes added or removed, the same measures with
the same lengths. Where an added element stands in the received document
does not matter. The new file is the **old document with only these changes
applied**; the server never stores the text it received.

The new file is written with the account's Nextcloud permissions into the
folder of the old one, named `<old name without extension> (corrected
YYYY-MM-DD).<extension>` with the word in the account's language (German
`korrigiert`) and a number added if the name exists
(`(korrigiert 2026-10-22, 2)`); an earlier such suffix is replaced, not
repeated. An `.mxl` stays an `.mxl` (all other entries of the archive copied
unchanged). The old file is not changed. The new file becomes the MusicXML of
the copy (file flag `musicxml_valid`, version fields `musicxml_valid` and
`musicxml_date`); the old one stays linked as a file. The changes are recorded
in the audit log (object `file` of the new file, action `corrected`, field
`changes`, old → new per note); `pointIds` (points of this version with status
`open`, `correct` or `question`) are set to `corrected` (only while
`transcription_review` is active).

```json
{ "file": { "id": 31, "copy_id": 5, "name": "5-01_Titel (korrigiert 2026-10-22).mxl", "path": "Mappe_5/…", "…": "file row" },
  "changes": [{ "measure": "12", "part": "Bass", "staff": "1", "voice": "1", "kind": "pitch|accidental|lyric",
                "verse": null, "old": "C3", "new": "D3" }],
  "points": [12, 13] }
```

`old`/`new` of a pitch: step, `#`/`##`/`b`/`bb` (other alterations as
`+0.5`) and octave, e.g. `F#4`; of an accidental: its value or `''`; of a
syllable: the text. `verse`: the `number` of the lyric.

Errors (400): `This correction changes more than pitches, accidentals and
syllables (measure %s).`, `The length of measure %s has changed.`, `The
correction contains no change.`, `Please correct the score file shown in the
score view.`, `Internal preservation copies cannot be corrected in the app.`,
`This file is not a MusicXML score.`, `The file is too large (at most %s
MB).`, `You have no permission to save a new file in the folder of this
score.` (no write permission in Nextcloud).

### Scan beside the score

`GET /api/files/{id}/scan` (module `score_viewer`; any role that may view the
version's score; plain GET without CSRF token, 120 per 10 minutes) returns a
linked PDF (`files[].role` `score_pdf`, a `%PDF-` header within the first
kilobyte, as PDF readers accept it) with
`Content-Type: application/pdf`, `Content-Disposition: inline`, `nosniff` and
a content security policy that allows nothing but the browser's own PDF
viewer, so that the UI can show it in a frame beside the score or open it in
a second window at a page (`#page=3`). The app page allows frames from its
own origin only. Errors: 400 `This file is not a PDF scan.`, 403 as for the
score.

### Notifications

| Subject | To | When |
|---|---|---|
| `review_points` | the responsible role (conductor and/or librarian) | a report created review points for the role; one notification per report and role with the count (`count`, `shelfmark`, `title`, `copyId`). Link `?copy={id}&review=1` (opens the score view with the review). Withdrawn when no open point of the role is left in that report. |
| `correction_received` | librarian (not the person who made it) | a correction was saved in the app (`kind: app`) or a new report closed points because the MusicXML changed (`kind: report`). One per version, replaced by the next. Link `?copy={id}&review=1`. |

Switches: `notify_review_points` (requires `notifications` and
`transcription_review`), `notify_correction_received` (requires
`notifications` and `score_viewer`).
## Opening from the Files app (module `files_integration`)

Files of the archive folder get the action **Open in music archive** in the
Files app. The script (`js/notenarchiv-files.mjs`, built from
`src/files.ts`; no score viewer, no Vue, no store) is added by
`Listener\FilesScriptListener` on `OCA\Files\Event\LoadAdditionalScriptsEvent`
as an init script, only for accounts with access (a role, or an admin, who
may read), only while `files_integration` is active and only when the
account can see the archive folder. It registers two file actions with
`registerFileAction` of `@nextcloud/files` 4 (the file action registry of the
Files app in Nextcloud 33 to 35, `window._nc_files_scope.v4_0`):

| Action id | Default action | Shown for |
|---|---|---|
| `notenarchiv-open-score` | yes (a click opens it) | `.musicxml` and `.mxl` while `score_viewer` is active, unless the Nextcloud viewer opens the file's MIME type (`OCA.Viewer.mimetypes`) |
| `notenarchiv-open` | no (actions menu only) | the other extensions the file sync knows (`FileSyncService::ROLE_BY_TYPE`: PDF, MuseScore, audio, images, …) and MusicXML when the first action does not apply |

Both have order 20: after the Nextcloud viewer (0), whose click wins, and
before the download (30), the Files app's own default action. A PDF never
gets a default action of the app. Both only apply to one readable file of the
account's own files (`/files/{uid}`) below the archive folder.

Initial state `files` (only with the script):

```json
{ "archivePath": "/Notenarchiv", "extensions": ["pdf", "mxl", "musicxml", "xml", "mscz", "..."],
  "scoreExtensions": ["musicxml", "mxl"], "scoreView": true }
```

`archivePath` is the archive folder as the account sees it, relative to its
files (it differs from `meta.archive.path` when the folder is shared with the
account).

`GET /api/nextcloud-files/{fileId}/copy` (any role or admin; rate limited)
tells which copy a file belongs to. `fileId` is the **Nextcloud file id**
(`files[].file_id`), not the id of the archive file that the other
`/api/files/{id}/…` routes take.

```json
{ "copyId": 35, "shelfmark": "2-1", "role": "musicxml", "score": true }
```

`role` is the file role (see "Catalog"); `score` is `true` for a MusicXML
file the score viewer reads (`.mxl`, `.musicxml`, `.xml` with role
`musicxml`) while `score_viewer` is active. The file is looked up in the
account's own view of Nextcloud first: a file the account cannot see, a file
that is not in the archive and a file of the archive without a copy all get
the same **404** `{ "error": "This file is not listed in the music archive." }`,
so the route does not tell whether a file of another account is in the
archive. Without access: 403; module switched off: 404
`This function is switched off.`. The route keeps Nextcloud's CSRF check
(the script sends the request token); it is read-only.

The action then opens the app with `?copy={copyId}&score=1` (score view of
the copy, see `src/startParams.ts`) or `?copy={copyId}` (the copy in the
sidebar). The score view shows the score of the copy as in the app (`score`
of the entry: the MusicXML marked as valid first), which is the clicked file
unless the copy has several. It checks the rights itself as always
(preservation copies, playback). `?score=1` without `copy` is ignored, and without an
active `score_viewer` the app only opens the copy and says that the function
is switched off. After opening, the app writes `?copy={copyId}` into the URL.

## Sharing beyond the archive roles (milestone 0.7)

Four modules, all off and part of no preset: links for choir members
(`member_links`), their practice audio (`member_practice_audio`), public
links (`public_links`) and the public repertoire page (`public_repertoire`).
Choir members and the public never get a file of the archive: the server
reads the current score file of the copy (`score` of the entry) through the
account of the archive folder, only inside that folder, unpacks `.mxl` and
cuts the measures of the link (`lib/Service/MusicXmlExcerpt.php`). The
excerpt keeps title, credits, rights notice and part list; its first measure
gets the attributes (divisions, key, time, clef, transposition, staves) and
the tempo valid there; repeats, endings and jumps that lead out of the
excerpt are removed, so that the excerpt plays from its first to its last
measure.

### Making links for choir members and the public

`POST /api/copies/{id}/links` takes the field `circle`: `internal` (default),
`members` or `public`. For `members` and `public`:

- The module of the circle must be active, else 404 `This function is
  switched off.`.
- `measure_from` and `measure_to` are required and must exist in the score
  (400 `Please give the first and the last measure: choir members and the
  public only see these measures.`, `There is no measure %s in this score.`,
  `Measure %s does not come after measure %s.`); the copy needs a MusicXML
  file (400 `This copy has no MusicXML file. …`); only partwise MusicXML can
  be cut (400 `Links for choir members and public links need a score in the
  partwise MusicXML format.`).
- The rights (403 with the reason and the next step): `members` needs the
  release for the singers' portal (`RightsPolicy::memberViewDenial`:
  confirmed public domain or a license with use `portal`); `public` needs
  `publication_status` `free` and confirmed public domain or a license with
  use `online_publication` (`RightsPolicy::publicViewDenial`). Never an
  internal preservation copy.
- `public` takes no `note` (400 `A public link has no note, because everyone
  could read it. Please leave the note empty.`); `task_id` is only kept for
  `internal`.
- The audit log records the circle (`field` `circle`).

Expiry (at most 183 days), revocation and the call count are the same as for
internal links. The UI's URL of a link for choir members is
`/apps/notenarchiv/?link={token}` (as for internal links), of a public link
`/apps/notenarchiv/p/{token}`.

`GET /api/copies/{id}/link-options` (module `excerpt_links`; roles
librarian, conductor, operations; the account must be allowed to view the
version) tells the dialog what a link would allow, before it is made:

```json
{ "members": { "available": true, "allowed": true, "reason": null, "playback": true, "practiceAudio": true },
  "public": { "available": true, "allowed": false,
              "reason": "Not released for publication. The catalog librarian sets the publication status of the version to \"Released for publication\".",
              "playback": false, "practiceAudio": false } }
```

`available`: the module of the circle is active (else `reason` is `This kind
of link is switched off. An administrator can switch it on in the settings
of the music archive under "Modules".`). `allowed`: the rights allow the
link. `reason` (translated) says why it is not allowed, or else why the
recipients cannot play it. `playback`: the recipients can play it;
`practiceAudio`: choir members can make practice files (always `false` for
`public`).

### The page of the choir members

`GET /` for an account in a group of the role `member` without an archive
role (module `member_links`) returns the page "Shared with me" (template
`member`, script `js/notenarchiv-member.mjs`) with the initial state
`member`:

```json
{ "links": [{ "token": "…", "title": "Abendlied", "composer": "J. Rheinberger", "measure_from": "2", "measure_to": "3",
              "voice": "Alto", "verse": null, "tempo": null, "note": "Please practise until Thursday.",
              "expires": "2027-04-23", "canPlay": true, "canExportAudio": false, "reason": null }] }
```

Only active links of the circle `members` whose version is still released,
newest first; no shelf marks and no account names. `reason` (translated)
says why an excerpt cannot be played; `canExportAudio` needs the module
`member_practice_audio` and the rights for practice audio.

| Method and path | Body | Who |
|---|---|---|
| `GET /api/member/links/{token}/score` | – | choir members; module `member_links` |
| `POST /api/member/links/{token}/practice-audio` | `{ export: { voice, mode, format, tempo?, repeats? } }` | choir members; modules `member_links` and `member_practice_audio` |

`…/score` returns the measures of the link as MusicXML
(`application/vnd.recordare.musicxml+xml`, attachment `excerpt.musicxml`,
`nosniff`, the strict policy of data responses) and counts the call.
Errors: 403 `This page is only for choir members. …` (no choir member) or
`This excerpt is no longer shared with choir members.` (the release is
gone); 400 `This link has expired.`, `This link has been revoked.`, `This
link does not exist.` (unknown token or a link of another circle: counts as
a failed attempt for the brute-force protection); 404 `The score of this
excerpt is not available at the moment.` (no file, or the account of the
archive folder cannot read it).

`…/practice-audio` allows one file of one voice and records it like every
export (`practice_audio`, see "Practice audio files"; the entry has the role
`member`). Only `voice`, `mode` (`all`, `focus`, `solo`), `format` (`wav`,
`midi`), `tempo` (25–200) and `repeats` are accepted (400 `Unknown field:
%s` for others, also for `measure_from` or `copy_id`): copy and measures are
those of the link. 403 with the reason when the rights refuse it
(`RightsPolicy::memberPracticeAudioDenial`: the release for the singers'
portal, playback, and a confirmed status or a license for practice audio).
Answer `{ "ok": true }`.

### Public pages

The only routes without login (`#[PublicPage]`), each the only route of its
controller, plain GET pages in Nextcloud's public layout, limited per
address and per account and protected by the brute-force protection:

| Path | Module | Shows |
|---|---|---|
| `GET /p/{token}` | `public_links` | one excerpt to view and play (template `public`, script `js/notenarchiv-public.mjs`) |
| `GET /repertoire` | `public_repertoire` | the public repertoire page, rendered on the server without script |

`GET /p/{token}` carries everything in the initial state `excerpt`; there
is no further request, no download and no practice audio:

```json
{ "title": "Abendlied", "composer": "J. Rheinberger", "measureFrom": "9", "measureTo": "12",
  "voice": "Alto", "verse": null, "tempo": null, "canPlay": true, "xml": "<?xml …?><score-partwise …>…</score-partwise>" }
```

No note, no shelf mark, no account name. Policy: as the app page
(`'wasm-unsafe-eval'` only), no frames; `X-Robots-Tag: noindex, nofollow`.
An unknown, malformed, expired or revoked token, a link of another circle, a
version no longer released (`RightsPolicy::publicViewDenial`) and a
switched-off module all answer with the same 404 page of Nextcloud, without
a reason, marked as a failed attempt for the brute-force protection. Each
shown page counts a call.

`GET /repertoire` lists the works with `public_repertoire` set that have a
version confirmed public domain (no preservation copy), checked on every
call: title, composer as written, composer and lyricist from the register of
persons with life dates (while `persons` is active), scoring of those
versions, and the links in `cpdl_url` and `imslp_url` when they are https
links to `cpdl.org` or `imslp.org`. Nothing is loaded from there. A
switched-off module or an internal error gives the same 404 page.

New work fields (in `meta.fields`, maintained by the librarian; the UI shows
them while `public_repertoire` is active):

| Field | Type | Rule |
|---|---|---|
| `public_repertoire` | boolean | Setting it to `true` needs a version of the work confirmed public domain (400 `Only works with a version confirmed as public domain can be shown on the public repertoire page. …`) and the module (404). |
| `cpdl_url` | text (500) | An https link to `cpdl.org` or `www.cpdl.org` (400 `"%s" must be an https link to the page of the work on cpdl.org or imslp.org.`). |
| `imslp_url` | text (500) | An https link to `imslp.org` or `www.imslp.org`. |

Migration `Version000710Date20261025100000` adds these three columns to
`notarch_works`; links for choir members and public links use the existing
column `circle` of `notarch_links`.
## Folder book (module `folder_book`, milestone 0.7)

Which choir folder is out with whom since when. Read by `librarian`,
`operations`, `conductor` and `reader`; maintained by `librarian` and
`operations`. `contributor`, admins without a role and any other role (for
example choir members) get **403**; the audit entries of the folder book
(object types `folder` and `folder_loan`) are left out of `GET /api/audit`
for them, and they cannot download the complete backup
(`GET /api/backup/full`, 403), which contains the folder book. Folders and
loans are never deleted.

| Method and path | Body | Role |
|---|---|---|
| `GET /api/folder-book` | – | librarian, operations, conductor, reader |
| `POST /api/folder-book/folders` | `{ folder: { label, voice?, contents?, wear?, missing?, note? } }` | librarian, operations |
| `PUT /api/folder-book/folders/{id}` | `{ folder: { …changed fields, status?, statusReason? } }` | librarian, operations |
| `POST /api/folder-book/folders/{id}/issue` | `{ loan: { holderUid \| holderName, issuedOn?, note? } }` | librarian, operations |
| `POST /api/folder-book/loans/{id}/return` | `{ loan: { returnedOn?, wear, missing?, note? } }` | librarian, operations |
| `GET /api/settings/folder-book` | – | admins and the groups given the settings page |
| `PUT /api/settings/folder-book` | `{ retentionMonths }` | admins and the groups given the settings page |

`GET /api/folder-book`, folders in service first, in natural order of their
labels:

```json
{ "folders": [{ "id": 4, "label": "12", "voice": "Tenor I", "wear": "worn", "missing": "2-35, page 3", "note": null,
    "status": "active", "statusReason": null, "statusSince": null,
    "contents": [{ "copyId": 35, "count": 2, "shelfmark": "2-35", "title": "…", "outdated": "retired|other_version_valid|null" }],
    "outdated": true,
    "loan": { "id": 9, "holderName": "…", "holderAccount": false, "anonymized": false, "issuedOn": "2026-09-01", "issueNote": null,
              "returnedOn": null, "returnWear": null, "returnMissing": null, "returnNote": null },
    "history": [ "…all loans, newest first, same shape as loan…" ] }],
  "retentionMonths": 12, "canEdit": true }
```

- `contents` are copies of the catalog with a number (1–999, at most 200
  different copies); the same copy twice is added up. `outdated` is a hint
  only: `retired` when the copy was retired in the catalog,
  `other_version_valid` when another version of its work is valid for the
  choir. Nothing changes by itself.
- `wear`: `good`, `worn`, `damaged`. `status`: `active` or `retired`;
  retiring needs `statusReason` and a folder that is back; `statusSince` is
  set by the server. A label must be unique among the folders in service
  (case-insensitive).
- Issuing: exactly one of `holderUid` (an existing Nextcloud account; its
  display name is stored with the loan) and `holderName` (at most 255
  characters). `issuedOn` defaults to today and must not lie in the future.
  A folder out of service or out already is refused ("This folder is
  already out with someone. Take it back first."); the database refuses a
  second open loan as well (unique index on `open_folder_id`).
- Taking back: `returnedOn` defaults to today, must not lie in the future or
  before `issuedOn`; `wear` is required. `wear` and `missing` become those of
  the folder.
- After the retention period (`retentionMonths`, 1–120, default 12, months
  after `returnedOn`) the daily checks set `holderName`, `holderUid`,
  `issueNote` and `returnNote` of the loan to `null` and `anonymized` to the
  time; the old and new values of these fields in the loan's audit entries
  become `(anonymised)`, and an entry `anonymized` without user and without
  the name is added. This runs whatever the module switch says.
- Errors: 400 with a message that says what to do (e.g. `Please give the
  folder a number or name.`, `Unknown field: %s`), 403 for other roles, 404
  for an unknown folder or loan (`… Please reload the folder book.`).

The UI looks up accounts through Nextcloud's own search
(`/ocs/v2.php/core/autocomplete/get`, share type user), which follows the
instance's rules on who may find whom.

## Mirror folder for the print station (module `print_mirror`, milestone 0.7)

The PDF scans of the versions on the list of the print station
(`GET /api/lists/print-station`, rows `add` and `not_decided`: the same rule
as the list) are copied into a target folder that an administrator chooses.
Every write and every removal is in the audit log (object type
`print_mirror`, object id the copy, actions `mirror_copied`,
`mirror_replaced`, `mirror_removed`, `mirror_readme`, field `file`).

| Method and path | Body | Role |
|---|---|---|
| `GET /api/print-mirror` | – | librarian, admins |
| `GET /api/print-mirror/preview` | – | librarian, admins |
| `POST /api/print-mirror/sync` | – | librarian, admins |
| `GET /api/settings/print-mirror` | – | admins and the groups given the settings page |
| `GET /api/settings/print-mirror/check?folderId=` | – | admins and the groups given the settings page |
| `PUT /api/settings/print-mirror` | `{ folderId }` (0 removes the target folder) | admins and the groups given the settings page |

Status (`GET /api/print-mirror`, the same as `GET /api/settings/print-mirror`):

```json
{ "folder": { "id": 812, "path": "/Druckstation", "user": "admin" }, "started": false,
  "report": { "time": 1761150000, "dryRun": false, "writable": true,
    "copied": [{ "name": "2-35 Ave verum corpus.pdf", "shelfmark": "2-35", "reason": null }],
    "replaced": [], "removed": [],
    "skipped": [{ "name": "5-1 Locus iste.pdf", "shelfmark": "5-1", "reason": "foreign", "reasonText": "A file of this name …" }],
    "unchanged": 14, "files": 15 } }
```

The preview and the sync answer with such a report (`dryRun: true` for the
preview, which writes nothing). `started` turns `true` with the first sync
by hand; only then does the daily check sync on its own. Reasons of skipped
files: `foreign` (a file of that name the app did not write), `changed`
(changed in the target folder after the app wrote it), `not_accessible`,
`not_pdf`, `invalid_name`, `preservation`, `write_failed`; `reasonText` is
the translated text with what to do.

- File names: `<shelf mark> <title>.pdf` without `\ / : * ? " < > |` and
  control characters, at most 120 characters before `.pdf`; further scans of
  a copy get ` (2)`, ` (3)`. Plus `LIESMICH.txt` with the date and a note,
  in the language of the account of the target folder.
- Only files listed in the manifest (table `notarch_mirror_files`: name,
  file id, SHA-256 of what was written) are replaced or removed, and only
  while their content still has that checksum. Foreign files stay. The
  manifest belongs to the instance (file ids) and is not part of the
  complete backup.
- Checking and saving the target folder (400 with what to do): a folder of
  the administrator's files, neither the archive folder nor inside it (also
  through shares, as seen by the administrator and by the account of the
  archive folder); the administrator must read the archive folder and may
  create files in the target folder. Saving another folder forgets the
  manifest (the files stay in the old folder) and waits for a new first
  sync. The sync checks the folder again before writing.
- A failed daily sync notifies the catalog librarians and the
  administrators (`notify_print_mirror_failed`); the next sync that works
  withdraws it.
