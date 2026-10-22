# Internal API (version 0.1)

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
(Vorstand, Revision). Admins may read and change settings; they may only write
catalog data if they also hold a role. Without a role they get 403 for the
proposals (`GET /api/proposals`) and cannot view preservation copies; the UI
hides the inbox for them.

## Page

`GET /` returns the app page with initial state `meta` (same as `GET /api/meta`).

## Meta

`GET /api/meta`

```json
{
  "uid": "thomas",
  "admin": true,
  "roles": ["librarian"],
  "roleLabels": { "librarian": "Catalog (music librarian)", "...": "..." },
  "fields": { "work": { "title": { "type": "text", "label": "Title", "write": ["librarian"] } }, "version": {}, "copy": {} },
  "archive": { "id": 123, "path": "/Notenarchiv", "user": "admin" }
}
```

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
access to the file in Nextcloud; otherwise 403 with `{ "error" }`. Fetch it
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
`audio`, `image`, `other`. Open a file in Nextcloud via
`generateUrl('/f/{file_id}')`.

Audit actions: `created`, `changed`, `import`, `restore`, `not_found`,
`file_sync`, `practice_audio` (see "Practice audio files").

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

## Settings (admins only)

`GET /api/settings`

```json
{ "roles": { "librarian": ["Notenwart"], "operations": [], "conductor": [], "contributor": [], "reader": ["Vorstand"] },
  "archive": { "id": 123, "path": "/Notenarchiv", "user": "admin" } }
```

`PUT /api/settings` `{ "roles": { "librarian": ["…"] }, "archiveFolderId": 123 }`;
both parts are optional. The response is the same as for `GET`.

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
`self_print`, `print_station`, `practice_audio`, `recording`, `publication`
(keys and English labels in `meta.licenseUses`).

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
  `librarian` accepts them.

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
and `links` (active internal excerpt links of the copy).

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

**Excerpt link** (internal circle: logged-in holders of an archive role)

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
| `GET /api/due` | – | any role; returns `{ cards: [{ key, count, label }], tasks: [...] }` |
| `GET /api/tasks?closed=0\|1` | – | any role |
| `POST /api/copies/{id}/tasks` | `{ task: { kind, addressee, question, page?, measure_from?, measure_to?, voice?, verse?, category?, origin?, due? } }` | librarian, conductor, operations, contributor |
| `POST /api/tasks/{id}/answer` | `{ answer }` | the addressed role |
| `POST /api/tasks/{id}/close` | `{ status: "applied"\|"discarded", note? }` | librarian (operations for checks addressed to operations) |
| `POST /api/copies/{id}/links` | `{ link: { measure_from?, measure_to?, voice?, verse?, tempo?, note?, task_id?, expires? } }` | librarian, conductor, operations |
| `GET /api/links` | – | any role: all active links (board/auditors can review them) |
| `GET /api/links/{token}` | – | any role (rights checked on every call) |
| `POST /api/links/{id}/revoke` | – | creator or librarian |

Start page card keys: `tasks_open`, `tasks_answered`, `ambiguous_items`,
`proposals`, `portal_remove`, `portal_add`, `confirm_current`, `rule1_current`,
`licenses_expiring`, `valid_missing`. The `label` is translated, always in the
plural; the UI shows its own label in singular or plural for the keys it knows
(`src/dueLabels.ts`) and the server's label for new keys.

Notifications: `task_new` (to the addressed role, link `?copy={id}&task={id}`)
and `task_answered` (to the librarian).

**Text reference** (no server call): the UI builds a plain text such as
`5-1 · Freude, schöner Götterfunken · p. 2 · m. 17–24 · Tenor II · note` that can
be copied anywhere; it contains no notation and is always allowed.

## Practice audio files

The score view makes practice files per voice in the browser: WAV (16 bit,
44.1 kHz, mono or stereo) rendered with the playback's own synthesizer and
playing order, or a Standard MIDI File (format 1: a track with tempo, time
and key signature, then one track per voice with its name; the mode is in
the channel volume of each track). "Every voice" gives a ZIP archive (stored
entries) with one file per voice plus a tutti. File names:
`<shelf mark>_<title>_<voice>_T<from>-<to>.<ext>`, ASCII only. The files never
pass through the server, and the app gives nothing to choir members.

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
{ "id": 3, "kind": "concert", "title": "Advent concert", "event_date": "2026-12-06", "location": "…",
  "period": null, "status": "draft|fixed|sung", "is_public": true, "board_agreed": false,
  "imported": false, "source": null, "notes": null, "created": 1761150000, "modified": 1761150000 }
```

Song lists use `period` (e.g. `2025/1`, `2018-Advent`) and are no proof of
performance. Imported events (`imported: true`) and sung concerts are history:
they cannot be changed. A planned event needs a `title`: creating one without
it, or changing it to an empty title, is refused with 400
`Please enter a title.`.

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
| `POST /api/events` | `{ kind, event: { title, event_date, location, period, is_public, board_agreed, notes } }` | conductor, librarian |
| `PATCH /api/events/{id}` | `{ event: { …changed fields } }` | conductor, librarian |
| `POST /api/events/{id}/items` | `{ copyId }` | conductor, librarian |
| `PATCH /api/event-items/{id}` | `{ item: { duration?, note?, encore?, struck? } }` | conductor, librarian |
| `DELETE /api/event-items/{id}` | – | conductor, librarian (not for sung/imported) |
| `POST /api/events/{id}/order` | `{ order: [itemId, …] }` | conductor, librarian |
| `POST /api/events/{id}/fix` | – | conductor, librarian |
| `POST /api/events/{id}/sung` | `{ struck: [itemId, …] }` | conductor, librarian; only on or after the date |
| `GET /api/event-items/ambiguous` | – | any role |
| `POST /api/event-items/{id}/resolve` | `{ copyId }` or `{ copyId: null }` (= not in the catalog) | conductor, librarian |
| `GET /api/events/{id}/song-order` | – | download CSV (position, shelf mark, title, composer, arranger/lyrics, publisher, duration); cells starting with `=`, `+`, `-`, `@`, tab or carriage return get a leading `'` so that spreadsheet programs do not evaluate them |
| `GET /api/repertoire` | – | `{ thresholds: { current, dormant }, works: { workId: { performances, first, last, class, songLists } } }` |
| `PUT /api/repertoire/thresholds` | `{ current, dormant }` (years) | admin |
| `POST /api/performances/import` | `{ fileId, dryRun, ownChoir? }` | librarian; `.xlsx` or `.csv` |

Performance import report: `{ dryRun, source, sha256, snapshot, events,
songLists, items, certain, ambiguous, notInCatalog, counted, ownChoir,
unknownShelfmarks, sheets }`. A repeated import replaces the previously
imported events of the same kind; planned events are never touched.

Start page card: `ambiguous_items`. Notifications: `concert_deadline` (10, 8
and 6 weeks before, to conductor and librarian; link `?event={id}`) and
`concert_confirm` (after the date, to the conductor). A weekly summary instead
of single notifications is Nextcloud's own setting (personal settings →
notifications → email).

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
