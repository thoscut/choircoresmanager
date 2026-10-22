# Tests

There are two kinds of tests. They are kept apart on purpose:

| | Unit tests | Integration tests |
|---|---|---|
| Location | `tests/Unit/` | `tests/Integration/` |
| Needs a Nextcloud server | no | yes, with the app installed |
| Runner | PHPUnit (`tests/phpunit.xml`) | plain PHP scripts |
| Runs in CI | yes | yes, with the demo data |

## Unit tests

The unit tests run without a Nextcloud server and without a database. The
public Nextcloud API (`OCP`) comes from the `nextcloud/ocp` package in the
version of the **oldest supported Nextcloud** (see `composer.json`), so a test
fails when the app uses an API that this version does not have. Nextcloud
services (`IDBConnection`, `ITimeFactory`, `IUserManager`, …) and the mappers
are replaced by PHPUnit test doubles; entities and the app's own logic are
real.

Requirements: PHP 8.2 or newer with the extensions `ctype`, `dom`, `json`,
`mbstring`, `tokenizer`, `xml` and `zip` (without `zip` the spreadsheet tests
are skipped), and Composer.

```sh
composer install                 # app dev dependencies and the tools in vendor-bin/
composer test:unit               # all unit tests
vendor/bin/phpunit -c tests/phpunit.xml --filter ImportServiceTest
vendor/bin/phpunit -c tests/phpunit.xml --testdox
```

The suite fails on warnings, notices, deprecations and risky tests.

What is covered:

| Test | Subject |
|---|---|
| `Service/CatalogCsvTest` | CSV parsing (BOM, double BOM, `;`, quotes, CRLF in cells, empty lines), writing byte for byte like Python's `csv` module with `QUOTE_MINIMAL` and CRLF (reference output embedded), round trip, legacy publication rules |
| `Service/FieldValueTest` | value checks per field type, length in characters, strict calendar dates (e.g. `2026-02-31`) |
| `Service/FieldsTest` | the field registry against the schema of the migrations: every column has a field or is listed as internal, `max` equals the column length, types and NOT NULL fit, every legacy CSV column maps to a field |
| `Db/EntitySchemaTest` | every entity against the table of its mapper: columns, types, nullability |
| `Db/LicenseTest` | `License::covers()` (uses, first and last day) |
| `Service/RightsServiceTest` | releases for the singers' portal and the print station, licenses, confirmation, portal workflow, transactions |
| `Service/RightsPolicyTest` | viewing and playback of scores, preservation copies |
| `Service/ImportServiceTest` | CSV import: invalid values skipped, decision fields never overwritten, shelf marks normalized, dry run rolled back, snapshot before a real import |
| `Service/ProposalServiceTest` | proposed values stored exactly (also `0`, `false`, `""`), accepting applies them |
| `Service/LifeDatesTest` | life dates from free text with their precision (ranges, full dates, "ca.", "?", living, before Christ) |
| `Service/ProtectionTermTest` | protection proposal: 70 years after the end of the year of death, the last person decides, "unknown means protected" |
| `Service/FullBackupServiceTest` | format check of the complete backup before a restore |
| `Service/XlsxReaderTest` | reading a synthetic .xlsx: shared and rich text, inline strings, gaps, sheet order, damaged files |
| `Service/OdsWriterTest` | the written .ods: package structure, sheets, escaping |
| `Service/PerformanceImportServiceTest` | counting rule for documented performances, detection of the own choir |
| `Service/CatalogServiceTest` | repertoire figures (documented or imported), works after merges |
| `Service/InputTest`, `Service/SortTest`, `Db/MergeTest` | request data checks, typed sorting, what a merge recorded |
| `Controller/ControllerSecurityTest` | route attributes of every controller (no public page, CSRF skipped only for plain downloads, admin-only settings, brute-force and rate limits), headers of the score file response, the app page's content security policy |
| `Service/ScoreServiceTest` | only real MusicXML reaches the browser (root element, document type declarations, `.mxl` contents, size limit) |
| `Service/UserFilesTest`, `Service/RequestBoundaryTest` | reading uploaded files with a size limit, limits of request data (unknown fields, list sizes) |
| `Notification/NotifierTest` | notification texts, no internal error texts |
| `Service/IcsWriterTest`, `Service/ConcertIcsTest` | calendar files after RFC 5545: CRLF, folding after 75 octets without splitting umlauts or other multi-octet characters, escaping of `,;\` and line breaks, time zone definitions (daylight saving time, half-hour offsets), all-day entries, stable UIDs, deadlines as separate entries, cancelled concerts |
| `Service/ConcertDeadlinesTest`, `Service/DueServiceTest` | the deadlines 10, 8 and 6 weeks before a concert and the confirmation after it; which roles see them on the start page |
| `Service/ConcertCalendarServiceTest` | entries in the account's own calendars (test double in `Support/FakeCalendar`): only writable calendars, roles, adding again updates instead of duplicating, cancelled concerts are only updated |
| `Dashboard/DueWidgetTest` | the dashboard widget: only for accounts with a role, items and deep links of the start page, no personal data |

`Support/MigratedSchema` runs all migrations in `lib/Migration` against an
in-memory Doctrine schema. `EntitySchemaTest` discovers the mappers in
`lib/Db`. New migrations, mappers and entities are therefore checked without
changing the tests; a new column of a work, version or copy needs a field in
`Service/Fields` or an entry in `FieldsTest::INTERNAL_COLUMNS`.

`tests/stubs/` holds stubs of private server classes that the public API refers
to (used by the unit tests and by Psalm).

## Integration tests

The scripts in `tests/Integration/` run against a real Nextcloud instance with
the app installed and a catalog imported. They change data inside a database
transaction that is rolled back at the end and remove the files they export.
Run them only on a test instance, never on a production system.

Run them as the user that owns the Nextcloud data (the web server user). The
classes are loaded from the app installed in Nextcloud; the scripts
themselves may lie anywhere, together with `harness.php`:

```sh
sudo -u www-data php tests/Integration/workflow_test.php      /var/www/nextcloud <uid> <shelfmark-a> <shelfmark-b>
sudo -u www-data php tests/Integration/notifications_test.php /var/www/nextcloud <uid> <shelfmark>
sudo -u www-data php tests/Integration/tasks_links_test.php   /var/www/nextcloud <uid> <shelfmark>
sudo -u www-data php tests/Integration/events_test.php        /var/www/nextcloud <uid> <programme-csv> <song-list-csv> <shelfmark-a> <shelfmark-b>
sudo -u www-data php tests/Integration/maintenance_test.php   /var/www/nextcloud <uid> <persons-csv> <shelfmark-a> <shelfmark-b> <shelfmark-free>
sudo -u www-data php tests/Integration/backup_test.php        /var/www/nextcloud <uid>
sudo -u www-data php tests/Integration/calendar_test.php      /var/www/nextcloud <uid>
```

`calendar_test.php` needs the dav app (calendars). It creates a calendar of
its own for `<uid>`, adds a concert with its deadlines through Nextcloud's
calendar interface, adds it again after a change and after the cancellation
(updates instead of duplicates), checks the calendar files with Nextcloud's
iCalendar parser (Sabre VObject) and the dashboard widget; the calendar is
deleted and everything is rolled back.

`<uid>` must hold the role `librarian`; each script's header lists further
requirements (CSV paths are relative to the user's files). `backup_test.php`
first adds a record to every app table (license, proposal, task, link,
concert with an item, person with a contribution, merge), so that the backup
round trip covers every table with content.

All scripts use `harness.php`: it prints one line per check and ends with
exit code 0 only when every check passed. A failed check, an uncaught
exception, a fatal error or a script that stops early ends with exit code 1
(Nextcloud's own error handling would otherwise end such a run with 0);
missing arguments end with exit code 2.

### Integration tests in CI (demo data)

The CI job `integration` runs all seven scripts against a freshly installed
Nextcloud 33 and 35 (release archives from download.nextcloud.com, checked
against their SHA-512 checksum) with MariaDB 11.8 and PHP 8.3. The job
installs the app with its built frontend, builds the public demo data
(`node tools/demo/build.mjs`, see `tools/demo/README.md`) and calls

```sh
bash tools/ci/integration-demo.sh /path/to/nextcloud
```

The script refuses to run when the catalog is not empty or the test account
exists. It creates a test account (random password, never printed) in its
own group, gives the group the roles `librarian` and `reader` and no other
role, copies the demo folder into the account's files (`occ files:scan`),
sets it as archive folder, imports `_Import/catalog.csv` (dry run, then for
real) and runs the file sync. It checks the figures of
`tools/demo/README.md`: 23 rows, 7 with MusicXML, 1 preservation copy,
19 × public domain, 2 × protected, 2 × open; 30 files (23 PDF, 4 MXL,
3 MusicXML), 29 assigned, `Mappe_4/4-04_…pdf` not assignable, `LIESMICH.txt`
ignored, `_Import` skipped, 4-3 without a file. Then it runs the tests with
these shelf marks of the demo catalog:

| Script | Arguments | Why |
|---|---|---|
| `workflow_test.php` | `2-2 1-3` | A is confirmed as public domain, B stays unconfirmed |
| `notifications_test.php` | `2-1` | |
| `tasks_links_test.php` | `1-2` | |
| `events_test.php` | `_Import/performances.csv _Import/song-lists.csv 2-1 3-1` | 2-1 is current repertoire; the first ambiguous programme line (1-1 or 3-1) is resolved to 3-1 |
| `calendar_test.php` | – | creates its own calendar and concert (rolled back) |
| `maintenance_test.php` | `_Import/persons.csv 2-4 3-2 2-1` | 2-4 and 3-2 are two copies of the same print with an uncertainly identified editor; all persons of 2-1 died more than 70 years ago |
| `backup_test.php` | – | |

The person and performance lists are imported only inside the tests'
rolled-back transactions. `events_test.php` expects no concert at all and
`maintenance_test.php` expects new persons in its dry run, so a real import
of these lists must never come before them.

Environment variables `CI_USER`, `CI_GROUP`, `DEMO_DIR` and `PHP` change the
defaults. The script ends with exit code 0 only when every check and every
test passed and prints the lines of a failed test. On an instance that
already holds a catalog, back it up first (`occ notenarchiv:export --full`),
empty it, run the script with an account of its own, then empty the catalog
again, restore the backup (`occ notenarchiv:restore`), delete the account and
set the archive folder and the roles back.

## Static analysis and coding standard

```sh
composer psalm      # Psalm, see psalm.xml (no baseline)
composer cs:check   # PHP-CS-Fixer with the Nextcloud coding standard, dry run
composer cs:fix     # apply the coding standard
composer lint       # php -l on all PHP files
```

The CI workflow (`.github/workflows/ci.yml`) runs all of these, the unit
tests on PHP 8.2, 8.3 and 8.4, the frontend checks and the integration tests
with the demo data on Nextcloud 33 and 35.
