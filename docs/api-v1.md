# API for agents (version 1)

A fixed, versioned interface for agents and tools: scripts, transcription
rounds, assistants with a language model. It is an
[OCS API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-api-overview.html)
of Nextcloud under

```
/ocs/v2.php/apps/cantoria/api/v1/…
```

The machine-readable description is [`openapi.json`](../openapi.json)
(OpenAPI 3.0, generated from the code with
[nextcloud/openapi-extractor](https://github.com/nextcloud/openapi-extractor);
it is part of the release archive). The MCP server in
[`tools/mcp`](../tools/mcp/README.md) offers these routes as tools for agents.

The internal API of the user interface (`/apps/cantoria/api/…`,
[docs/api.md](api.md)) may change with every release; use this one.

## Principles

1. **The app proposes, people decide** (ROADMAP, guideline 1). Agents read,
   ask, propose and hand in reports of transcriptions. The API has no route
   to confirm a rights status, to add or change a license, to set the valid
   version, to accept or reject a proposal, to close a task, to decide a
   review point, to save a correction, to delete or restore anything, to
   make a backup or to change settings. These stay with people in the app.
2. **No right beyond the roles.** The API calls the same services as the
   user interface; every role check applies unchanged. An agent signs in
   with an account of its own and can do exactly what the roles of that
   account allow. Use the role "contributor" (proposals and questions) or
   "reader" (reading only).
3. **Tools propose, people take over** (guideline 6). Writing creates
   proposals (the catalog librarian accepts or rejects them in the inbox),
   questions or tasks (the addressed role answers them) and review points
   from the report of a transcription (the conductor and the catalog
   librarian decide them); an agent may answer a task addressed to a role
   it holds.
4. **Only what is switched on** (guideline 12). The whole API is the module
   "Interface for agents" (`agent_api`), off by default and in no preset.
   Each route also needs the module of its function, as in the app.
5. **Internal preservation copies are not passed on** (guideline 2). For
   roles that may not view them (all except the catalog librarian and the
   conductor) the search and the correction orders leave them out, and a
   copy, a text reference, a task, the review or a report on one is refused
   with 403.
6. **No account names.** Answers name roles, never accounts: the fields
   `confirmed_by`, `confirmed_by_name`, `valid_set_by`, `rule1_checked`,
   `created_by`, `decided_by` and `decided_by_name` are left out; tasks,
   proposals, reports and review points say only whether the calling
   account created them (`own`).

## Authentication

Every request needs

- Basic authentication with the agent's account and an **app password** of
  that account (Nextcloud: *Personal settings → Security → Devices &
  sessions → Create new app password*, or `/ocs/v2.php/core/getapppassword`
  with the login password once),
- the header `OCS-APIRequest: true` (instead of a CSRF token), and
- `Accept: application/json` (or `?format=json`), otherwise Nextcloud
  answers in XML.

Write requests send JSON (`Content-Type: application/json`).

```sh
curl -u "cantoria-agent:$APP_PASSWORD" -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  'https://cloud.example.org/ocs/v2.php/apps/cantoria/api/v1/modules'
```

## Answers

Every answer is an OCS envelope:

```json
{ "ocs": { "meta": { "status": "ok", "statuscode": 200, "message": "OK" },
           "data": { "…": "…" } } }
```

Rules of the data in version 1:

- Field names are **snake_case**, as the catalog fields of the app
  (`lib/Service/Fields.php`). Some internal answers use camelCase (score,
  releases, statistics); this API maps them to snake_case.
- Dates are `YYYY-MM-DD`, points in time ISO 8601 in UTC
  (`2026-10-22T12:00:00Z`).
- A value that is not known is `null`; fields are never left out.
- Texts for people (reasons, warnings, labels, error messages) are
  translated into the language of the account.

### Lists and pages

Lists take `limit` (1–100, default 25) and `cursor` and answer

```json
{ "items": [ … ], "total": 87, "next_cursor": "WzEsMiwzXQ" }
```

`total` counts all entries that match the filters. For the next page, send
`next_cursor` as `cursor`; it is `null` on the last page. A cursor is
opaque: it names the position after the last entry (not a page number), so
paging stays correct when entries are added or removed in between. Do not
build cursors yourself; an invalid one is refused with 400.

### Errors

| HTTP | `ocs.meta.statuscode` | Meaning |
|---|---|---|
| 400 | 400 | Invalid input: a value, a filter, the limit or the cursor; the reason is in `ocs.meta.message` |
| 401 | 997 | Not logged in, wrong account or app password |
| 403 | 403 | Not permitted for the roles of the account (e.g. proposing as "reader", answering a task addressed to another role, a preservation copy) |
| 404 | 404 | Not found, **or switched off**: `ocs.meta.message` is "This function is switched off." when the interface for agents or the module of the route is off |
| 429 | – | Too many requests for this account (rate limit, body without OCS envelope); wait and try again |
| 500 | 500 | Internal error; details are only in the Nextcloud log |

`ocs.data` is empty (`[]`) for errors.

## Routes

All routes need `agent_api`, a role in the music archive and the module in
the table. Every route is rate limited per account.

| Method and path | Module | Limit per 10 min | Purpose |
|---|---|---|---|
| `GET /api/v1/modules` | – | 120 | API version, roles of the account, active modules, permissions, proposable fields, choices of a task |
| `GET /api/v1/catalog` | – | 120 | Search the catalog (filters, pages) |
| `GET /api/v1/copies/{id}` | – | 300 | One copy with version, work, rights, score, files, tasks, open proposals |
| `GET /api/v1/copies/{id}/reference` | – | 300 | Text reference to a place |
| `GET /api/v1/due` | – | 120 | Due list of the own roles (cards, concerts with a deadline) |
| `GET /api/v1/tasks` | `tasks` | 120 | Questions and tasks (`scope` mine, open, all) |
| `POST /api/v1/copies/{id}/tasks` | `tasks` | 30 | Ask a question or create a task |
| `POST /api/v1/tasks/{id}/answer` | `tasks` | 30 | Answer a task addressed to an own role |
| `GET /api/v1/proposals` | `proposals` | 120 | The own proposals |
| `POST /api/v1/proposals` | `proposals` | 30 | Propose changes of catalog fields |
| `GET /api/v1/concerts` | `concerts` | 120 | Concerts and song lists |
| `GET /api/v1/concerts/{id}` | `concerts` | 300 | One concert or song list with its programme |
| `GET /api/v1/statistics` | `statistics` | 60 | Figures of the archive |
| `GET /api/v1/copies/{id}/review` | `transcription_review` | 300 | Review of the transcription of a copy: reports, counts, review points (pages) |
| `GET /api/v1/correction_orders` | `transcription_review` | 120 | Correction orders for the tool chain |
| `POST /api/v1/copies/{id}/transcription_reports` | `transcription_review` | 30 | Hand in the report of a transcription (`bericht/1`) |
| `GET /api/v1/copies/{id}/states` | `version_states` | 300 | Revisions of the version of a copy, with the current one |

Parameters and fields of every route are in `openapi.json`. The most
important ones follow.

### Status: `GET /api/v1/modules`

```json
{ "api_version": 1, "roles": ["contributor"],
  "modules": { "tasks": true, "proposals": true, "concerts": true, "statistics": false, "transcription_review": true, "agent_api": true, "…": true },
  "permissions": { "create_tasks": true, "answer_tasks_for": [], "propose": true, "view_preservation_copies": false,
                   "submit_transcription_reports": true },
  "proposable_fields": { "work": ["work_no", "title", "composer", "notes"], "version": ["kind", "…", "duration"], "copy": ["status", "…", "pages"] },
  "task_options": { "kinds": ["question", "check", "report"], "addressees": ["conductor", "librarian", "operations", "reader"],
                    "categories": ["reading", "structure", "origin", "shelf", "rights", "other"] } }
```

### Catalog: `GET /api/v1/catalog`

Filters (all optional, combined): `q` (shelf mark, title, composer,
arranger, work number; case-insensitive, parts of words), `shelfmark`
(exact; "5-1" also finds "5-01"), `binder`, `status` (`active`, `retired`,
`missing`, `lent`), `rights_status` (`public_domain`, `protected`, `open`,
`none`), `confirmed` (true/false), `repertoire_class` (`current`, `dormant`,
`earlier`, `none`), `has_musicxml` (true/false). Rows in shelf order:

```json
{ "id": 1, "shelfmark": "1-1", "binder": 1, "number": 1, "status": "active", "work_id": 1, "version_id": 1,
  "title": "Der Mond ist aufgegangen", "composer": "Johann Abraham Peter Schulz", "work_no": null, "arranger_text": "Demo-Satz SATB",
  "rights_status": "public_domain", "rights_confirmed": false, "preservation_copy": false, "valid_for_choir": false,
  "repertoire_class": "current", "performances": 4, "last_performed_on": "2025-12-06", "has_musicxml": true, "files": 2 }
```

```sh
curl -u "cantoria-agent:$APP_PASSWORD" -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  'https://cloud.example.org/ocs/v2.php/apps/cantoria/api/v1/catalog?q=Mond&has_musicxml=true&limit=10'
```

### One copy: `GET /api/v1/copies/{id}`

`id` is the `id` of a search row (the copy), not the shelf mark. The answer
has the head of the row (`id`, `shelfmark`, `binder`, `number`, `status`,
`work_id`, `version_id`, `title`, `composer`) and

- `fields`: `work`, `version` and `copy`, each with every catalog field of
  `lib/Service/Fields.php` and its value (without the account names above);
- `rights`: `status`, `confirmed`, `confirmed_on`, `preservation_copy`,
  `valid_for_choir`, `valid_since`, `publication_status`, `portal`
  (`allowed`, `reason`, `on_portal`) and `print_station` (`allowed`,
  `reason`);
- `score`: the MusicXML to show (`file_id`, `file_name`, `can_view`,
  `can_play`, `reason`) or `null`; `files`: `id`, `name`, `path` (in the
  archive folder), `type`, `role`, `found`. The API does not hand out file
  contents;
- `other_copies`: other copies of the same work (`id`, `shelfmark`,
  `version_id`, `status`);
- `tasks` (all tasks of the copy) and `open_proposals`.

Parts of switched-off modules are empty or `null`, as in the app.

### Text reference: `GET /api/v1/copies/{id}/reference`

Optional `page`, `measure_from`, `measure_to` (printed measure numbers such
as `17`, `0`, `34X`), `voice`, `verse`, `note`:

```json
{ "text": "1-1 · Der Mond ist aufgegangen · p. 1 · m. 1–4 · Sopran", "shelfmark": "1-1", "title": "Der Mond ist aufgegangen" }
```

The words ("p.", "m.", "verse") are those of the account's language.

### Questions and tasks

`GET /api/v1/tasks?scope=mine|open|all&copy_id=…` lists tasks in shelf
order; `mine` (default) are the open tasks addressed to a role of the
account and, for the catalog librarian, answered tasks to apply.

`POST /api/v1/copies/{id}/tasks` (roles contributor, librarian, conductor,
operations) answers **201** with the task:

```sh
curl -u "cantoria-agent:$APP_PASSWORD" -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -X POST \
  -d '{"addressee":"conductor","kind":"question","category":"reading","measure_from":"12","measure_to":"12","voice":"Tenor II","question":"F or F sharp in bar 12?"}' \
  'https://cloud.example.org/ocs/v2.php/apps/cantoria/api/v1/copies/1/tasks'
```

`addressee` (required): `conductor`, `librarian`, `operations`, `reader`;
`question` (required, at most 10,000 characters); `kind`: `question`
(default), `check`, `report`; optional `category`, `page`, `measure_from`,
`measure_to`, `voice`, `verse`, `origin`, `due` (YYYY-MM-DD). The addressed
role is notified.

`POST /api/v1/tasks/{id}/answer` with `{ "answer": "…" }`: only a role the
task is addressed to, only while it is open (otherwise 403 or 400).
Closing a task stays with the catalog librarian in the app.

### Proposals

`POST /api/v1/proposals` (roles contributor, librarian) answers **201** with
one proposal per field:

```sh
curl -u "cantoria-agent:$APP_PASSWORD" -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -X POST \
  -d '{"level":"version","object_id":1,"fields":{"key_signature":"D major","scoring":"SATB"},"reason":"from the score, p. 1","source":"agent round 1"}' \
  'https://cloud.example.org/ocs/v2.php/apps/cantoria/api/v1/proposals'
```

`level` is `work`, `version` or `copy`; `object_id` the `work_id`,
`version_id` or `id` of a copy. Only fields the catalog librarian may write
can be proposed (`proposable_fields` of `GET /api/v1/modules`); decisions
(rights confirmation, valid version, portal release) never. All fields of
a request are taken, or none (400). Nothing changes until the catalog
librarian accepts a proposal.

`GET /api/v1/proposals?status=open|accepted|rejected` lists the proposals of
the calling account with `status` and `decision_reason`.

### Concerts and statistics

`GET /api/v1/concerts?kind=concert|song_list&status=draft|fixed|sung|cancelled&from=YYYY-MM-DD&to=YYYY-MM-DD`
lists events by date (without a date last); `GET /api/v1/concerts/{id}`
gives the programme with `warnings` per line and `total_duration` (seconds).

`GET /api/v1/statistics?from=YYYY&to=YYYY` gives stock, rights, releases,
licenses, repertoire classes, concerts and pieces per year, the most
performed works and composers, open tasks and proposals. Lists that name
copies (works not sung for a long time, protection terms) stay in the app.

### Review of transcriptions

A transcription (scan → MusicXML) ends with a report in the format
`bericht/1` (`bericht.json`, see [docs/api.md](api.md), "Reviewing
transcriptions and corrections"). The app reads it into **review points**
with a place in the score; the conductor decides the musical ones, the
catalog librarian everything. An agent reads the review, hands in reports
and picks up the correction orders; it never decides.

`POST /api/v1/copies/{id}/transcription_reports` (roles contributor,
librarian; module `transcription_review`) with the report as text
(`content`, preferred: the text is kept unchanged as evidence) or as object
(`report`), not both:

```sh
curl -u "cantoria-agent:$APP_PASSWORD" -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -X POST \
  --data "$(jq -n --rawfile report bericht.json '{content: $report}')" \
  'https://cloud.example.org/ocs/v2.php/apps/cantoria/api/v1/copies/1/transcription_reports'
```

```json
{ "report": { "id": 4, "copy_id": 1, "version_id": 1, "source": "api", "sha256": "…", "report_file_id": null,
              "round": "2", "report_date": "2026-10-20", "title": "Der Mond ist aufgegangen",
              "basename": "1-01_Der-Mond-ist-aufgegangen_Schulz", "method": "neuaufbau",
              "musicxml_file_id": 2, "musicxml_sha256": "…", "origin": "…", "review": "…", "reasoning": "…",
              "rights_class": "gemeinfrei", "warnings": ["…translated…"], "superseded_by": null,
              "created_at": "2026-10-22T12:00:00Z", "own": true },
  "unchanged": false, "points": { "created": 1, "kept": 7, "closed": 0, "open": 8 },
  "proposals": 0, "proposals_skipped": false, "warnings": [] }
```

**201** when the report was read, **200** with `unchanged: true` when the
same text was read for this copy before (nothing is created again). The
checks are those of the app (size, nesting, types, `format`, base name),
plus: `signatur` must be the shelf mark of the copy in the path (400
`The shelf mark of the report does not belong to this copy.`). `points`:
`created` new review points, `kept` points the report names again,
`closed` points of earlier rounds closed because the MusicXML changed,
`open` open points of the copy afterwards. Rights and catalog fields of the
report become proposals (`proposals`; `proposals_skipped` when the inbox is
off); the catalog never changes.

`GET /api/v1/copies/{id}/review?status=…&role=…&limit=…&cursor=…` (every
role) gives the review of the copy:

```json
{ "copy_id": 1, "shelfmark": "1-1", "version_id": 1,
  "counts": { "open": 7, "confirmed": 0, "correct": 1, "corrected": 0, "question": 0, "dismissed": 0 },
  "reports": [ { "id": 4, "…": "as above" } ],
  "items": [{ "id": 12, "copy_id": 1, "version_id": 1, "report_id": 4, "file_id": 2, "file_sha256": "…",
              "category": "uncertain", "measures": "3", "measure_from": "3", "measure_to": "3", "voice": "T",
              "verse": null, "page": null, "text": "…", "role": "conductor", "status": "open",
              "correction": null, "decision_reason": null, "task_id": null, "resolved_report_id": null,
              "created_at": "2026-10-22T12:00:00Z", "decided_at": null, "own": false }],
  "total": 8, "next_cursor": null }
```

`reports`: the reports of this copy, newest first, without the received
text; the one with `superseded_by: null` gives the current review state
(`origin`, `review`, `reasoning` = `wv.herkunft`, `wv.pruefstand`,
`wv.begruendung`). `counts` counts all points of the copy; `items` are the
points in the order they were created, in pages, filtered by `status`
(`open`, `confirmed`, `correct`, `corrected`, `question`, `dismissed`) and
`role` (`conductor`, `librarian`). `category`: `uncertain`, `omitted`,
`material`, `question`. `file_id` is the MusicXML the point was made for
(`files[].id` of the copy).

`GET /api/v1/correction_orders?copy_id=…&limit=…&cursor=…` (every role)
lists the points decided as `correct`, oldest first:

```json
{ "items": [{ "id": 12, "copy_id": 1, "version_id": 1, "shelfmark": "1-1", "title": "Der Mond ist aufgegangen",
              "report_id": 4, "basename": "1-01_Der-Mond-ist-aufgegangen_Schulz", "file_id": 2,
              "file_name": "1-01_Der-Mond-ist-aufgegangen_Schulz.musicxml", "file_sha256": "…",
              "category": "uncertain", "measures": "3", "measure_from": "3", "measure_to": "3", "voice": "T",
              "verse": null, "page": null, "text": "…", "role": "conductor", "correction": "c sharp, as in the scan",
              "decision_reason": null, "decided_at": "2026-10-22T13:00:00Z", "own": true }],
  "total": 1, "next_cursor": null }
```

The tool chain carries out an order outside this API: it writes the
corrected MusicXML as a **new file** into the archive folder (the file sync
takes it over) and hands in its next report, naming the order `id` in
`erledigt` (or the place as `{ "takt", "stimme" }`); the app closes the
order when the MusicXML has changed.

**Roles compared with the routes of the app.** Reading the review and the
orders is open to every role, handing in reports to the contributor and the
catalog librarian, as in the app. Deciding a point (`POST
/api/review-points/{id}/decision`: conductor, catalog librarian) and saving
a correction in the app (`POST /api/versions/{id}/corrections`: conductor,
catalog librarian) have no counterpart here. Three differences on purpose:
the routes take the **copy** (as every route of v1; a report belongs to the
copy its `signatur` names), a report must name exactly that copy (the app
route accepts any copy of the version), and internal preservation copies
are refused or left out for roles that may not view them (principle 5).

### Revisions of a version

`GET /api/v1/copies/{id}/states` (every role; module `version_states`)
lists the revisions of the MusicXML of the version the copy belongs to,
oldest first (see "Revisions of a version" in `docs/api.md`):

```json
{ "copy_id": 2, "shelfmark": "1-2", "version_id": 2, "current_number": 2,
  "items": [
    { "id": 4, "number": 1, "source": "first", "copy_id": 2, "file_id": 3, "file_name": "1-02_Title.mxl",
      "sha256": "…", "measures": null, "voices": null, "note": null, "changes": [],
      "current": false, "valid_since": null, "created_at": "2026-10-27T09:00:00Z", "own": false },
    { "id": 7, "number": 2, "source": "correction", "copy_id": 2, "file_id": 31,
      "file_name": "1-02_Title (korrigiert 2026-10-27).mxl", "sha256": "…", "measures": "12", "voices": "Tenor I/II",
      "note": "Wrong note in the tenor", "current": true, "valid_since": "2026-10-27",
      "changes": [{ "measure": "12", "part": "Tenor I/II", "kind": "pitch", "verse": null, "old": "D4", "new": "E4" }],
      "created_at": "2026-10-27T10:00:00Z", "own": false }
  ] }
```

`source`: `first` (recorded when the recording of revisions started),
`correction` (a correction in the app), `sync` (a new or changed MusicXML
found by the file sync). Account names are left out; `own` tells whether
the calling account recorded the revision. Setting the current revision
(`PUT /api/states/{id}/current`, catalog librarian) has no counterpart here
(principle 1). Comparing two revisions happens in the app; `sha256` tells
whether a file still has the content of its revision.

## Versioning

- **v1 stays stable.** Within v1, changes are only **additive**: new routes,
  new optional parameters, new fields in answers, new values in lists of
  choices that are documented as open (modules, cards, warnings). Clients
  must ignore fields they do not know.
- Nothing is renamed, removed or given another type or meaning within v1.
  A change that breaks clients comes only with **v2** under
  `/api/v2/`; v1 then stays for at least one more minor version of the app
  and is marked deprecated in `openapi.json` and the changelog.
- `api_version` of `GET /api/v1/modules` is `1` for the whole of v1.
- `openapi.json` is generated from the code and checked in CI; a change of
  the API shows up there.
- A new route follows the rules above: `agent_api` plus the module of its
  function, the roles of the service, a rate limit, no route that decides
  for people. `tests/Unit/Controller/ControllerSecurityTest.php` lists
  every route of v1 with its counterpart in the app and the only routes
  that write.
