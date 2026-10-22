# Notenarchiv — sheet music archive for choirs

A Nextcloud app that keeps the catalog of a choir's sheet music archive:
works, versions (settings, arrangements, editions), copies (shelf marks) and
the scans, MusicXML and MuseScore files that already live in your Nextcloud.

It is built for the people who look after a choir's music: the music
librarians, the conductor, the board and the auditors. Singers keep using the
choir's own practice system; the archive itself is not shown to them.

## What it does

- **Catalog in three levels:** work → version → copy (shelf mark), plus the
  files in the archive folder, matched by the shelf mark at the start of each
  file name. Nothing is ever deleted; copies get a status (active, retired,
  missing, lent), and every change is written to an audit log.
- **Rights per version:** the initial assessment stays as a recommendation; a
  music librarian confirms public domain or protection with reason and
  evidence. Licenses list each use separately (binders, singers' portal,
  self-printing, print station, practice audio, recording, publication).
  Internal preservation copies are never shared or played.
- **Valid version:** only the conductor decides which version the choir sings.
- **Score viewer:** MusicXML rendered in the browser, cut to a range of printed
  bar numbers, a chosen voice highlighted and emphasised in playback — for
  public domain or licensed versions only. Notation, fonts and sounds are
  bundled; nothing is loaded from other servers.
- **Questions and tasks** with a location in the score (page, bars, voice,
  verse), internal excerpt links that expire and are checked on every call, and
  a start page showing what each role has to do.
- **Concerts and song lists:** plan a programme with rights warnings and
  deadlines, confirm what was sung, export the song order for the collecting
  society; repertoire figures are derived from documented performances.
- **Maintenance:** new copy assistant with the next free shelf mark, label
  data, register of persons with a protection-term suggestion, merging works
  and copies with undo, inventory lists, background checksums against silent
  file corruption.
- **Notifications** to role holders (never to singers), weekly backups (CSV,
  ODS and a complete JSON backup) into the archive folder, and import of an
  existing catalog, performance records and song lists from CSV or Excel.

## Principles

The app suggests, people decide. Rights belong to the version, an excerpt
counts as much as the whole sheet, files are only read, roles are bound to
groups, there is always a way back without the developer, paper stays the
fallback, and there are no external requests. The rights helpers model German
copyright law; they support a decision and are no legal advice. See
[ROADMAP.md](ROADMAP.md) for all principles and milestones.

## Requirements

- Nextcloud 33–35, PHP 8.2 or newer
- MySQL or MariaDB as Nextcloud's database (the only ones tested)
- The archive files in a folder the music librarians can access (typically a
  group folder)
- Optional: the Notifications app; a working background job runner (cron) for
  weekly backups, deadlines and checksum checks

## Trying it out

[tools/demo](tools/demo/README.md) builds the invented archive of a fictitious
choir: 23 copies of public domain works with placeholder scans, seven
MusicXML settings, persons, concerts and song lists, all CC0. Upload the
folder, import the catalog and follow the steps in its README.

## Documentation

- [Administration guide](docs/admin-guide.md): installation, roles, archive
  folder, import, backups and restore, managed hosting
- [User guide](docs/user-guide.md): daily work for librarians and conductors
- [API](docs/api.md): the internal JSON API of the user interface
- [Tests](tests/README.md): unit and integration tests
- [Releasing](docs/release.md): signing and publishing in the app store

## Development

```
npm ci && npm run build      # frontend (Vue 3, Vite)
npm run lint
npm run l10n                 # regenerate translations, fails on missing texts
composer install             # PHP tools (Psalm, PHPUnit, coding standard)
composer test:unit && composer psalm && composer cs:check
```

Code, identifiers and comments are in English; user-facing texts are English
source strings translated via `l10n/` (German: informal and formal).

## License

AGPL-3.0-or-later. Bundled third-party components keep their own licenses
(e.g. Verovio, LGPL-3.0-or-later).
