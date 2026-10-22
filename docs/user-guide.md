# User guide

For music librarians, conductors, board members and auditors. The interface is
available in English and German.

## Finding a piece

Type a shelf mark (`5-1`, also `5/1` or `6-08`) or a part of the title or
composer into the search field. Filters narrow the list: binder, rights
status, repertoire, with MusicXML, internal preservation copies, status.
Retired copies are shown when you choose the status filter.

The **rights badge** always shows text and a symbol, not only a colour:

- *Internal preservation copy — no sharing*
- *protected*
- *open* (not clarified)
- *public domain (recommendation, unconfirmed)*
- *public domain (confirmed)*

## The details of a copy

Click a row to open the sidebar: overview (work, version, copy), rights,
files, tasks and links, history (audit log). Sections you may edit show an
**Edit** button; an optional reason is stored with every change. There is no
delete button: a copy that leaves the archive gets the status *retired*,
*missing* or *lent* with a reason.

## Rights (music librarian)

- **Confirm** public domain or protection with a reason and evidence (e.g.
  "composer died 1827; title page and GND record"). Changing the status later
  removes the confirmation.
- **When in doubt: set to open** — one click with a reason.
- **Licenses** list each use separately; a print license does not allow the
  singers' portal.
- **Singers' portal:** **Provide in the singers' portal** records since when
  a version is there; it is offered only when the version is released
  (confirmed public domain or a license for that use). **Take out of the
  singers' portal** records the removal with a reason. The list *Singers'
  portal* shows what to add and what to remove there.

## Valid version (conductor)

Choose **Valid for the choir** on the version the choir sings. There is at most
one per work; choosing it changes nothing about the rights.

## Score viewer

Open **Score** on a copy with MusicXML. Choose the bars (printed numbers, the
upbeat is bar 0), the voice to focus on (e.g. Tenor II, also when it shares a
staff with Tenor I) and a playback mode: all equal, voice emphasised, or only
this voice. The focus voice is shown in color with bolder note heads and
stems, the other voices in a lighter gray; the lyrics of its staff stay dark. Playback is offered for public domain and licensed versions only.
Keyboard: Space plays and pauses, Esc closes. The current bar is announced to
screen readers.

## Questions and tasks

Ask a question about a place in the score — page, bars, voice, verse — and
address it to a role (usually the conductor). The addressed role answers; the
music librarian applies the answer to the catalog or discards it. The
**start page** shows what is due for your roles.

**Share** a place: either as a *text reference* (no notation, can be copied
anywhere, e.g. "5-1 · p. 2 · bars 17–24 · Tenor II") or as an *internal link*
for people with an archive role. Links expire (at most six months), can be
revoked and show when the score has changed since.

## Concerts and song lists

Create a concert, add copies, order them, enter durations. Warnings show
preservation copies, unclear rights, missing valid versions and protected
pieces without enough originals or a license. Deadlines are reminded 10, 8
and 6 weeks before. After the concert, **confirm what was sung** (strike what
was left out); the repertoire figures update by themselves. The **song order**
export contains title, composer, arranger/lyrics, publisher and duration.

Imported performance records whose title fits several works appear under
**Unclear programme lines**; assign each to the right copy or leave it out.

## Proposals

Tools and transcription rounds do not change the catalog; their proposals
wait in the **inbox** until a music librarian accepts or rejects them.

## Maintaining the catalog (music librarian)

- **New accession:** enter work, version and copy in one form. The app
  suggests the next free shelf mark of the chosen binder (numbers are never
  reused) and can link a file from the archive folder right away.
- **Shelf labels:** print labels for new or chosen shelf marks on A4 sheets
  (Avery 3653, 2 × 7 labels). Choose the first free label on a sheet that is
  already partly used. Print at actual size and switch off the browser's
  headers and footers (Firefox prints them by default).
- **Persons:** composers, arrangers, lyricists and publishers with their life
  dates. Linked to a version as contributions, they give the **protection-term
  suggestion** in the rights section, with its reasoning: protection ends 70
  years after the end of the year in which the last of them died; unknown
  dates count as protected. **Protection terms** lists what to check:
  versions marked protected that are public domain by the suggestion,
  versions marked public domain that the recorded persons contradict, and
  protected versions with a known end. The suggestion never changes the
  rights status by itself.
- **Merge:** the list proposes works with the same title and composer.
  Merging moves the versions to the work that stays; moving a copy puts it
  under another version. Every merge can be undone as long as the moved data
  is unchanged.
- **Inventory:** print the list of a binder, tick on paper, then enter the
  counts. Only changed counts are saved.
- **Checksums:** the app checks the archive files in the background and lists
  files whose content changed although they were not modified. Confirm a
  change if it was intended, otherwise restore the file from a backup.
- **Licenses ending or ended:** the start page card leads to the licenses that
  end within 30 days or have ended.

## Statistics and annual report

**Statistics** (under *Archive*) is open to every archive role. Choose the
period (default: the last ten years); figures without a year are those of
today. Each figure is a number in a table; the bars beside them are only for
a quick comparison. The links at the top lead to the sections:

- **Stock:** works, versions and copies; active copies with a file and with
  MusicXML; internal preservation copies; copies per binder (active,
  retired, missing, lent); new copies per year, split into new accessions
  and copies taken over from the catalog import.
- **Rights:** rights status of the versions, confirmed and not confirmed;
  works with and without a valid version (and how many of them have several
  versions to choose from); licenses valid, ending within 30 days, ended and
  not valid yet; the states of the singers' portal and print station lists;
  publication status.
- **Repertoire and performances:** concerts and pieces sung per year, the
  ten most performed works and composers of the period (performance days),
  the repertoire classes (current, dormant, earlier, not documented) and the
  works sung before but not lately that can be sung from the archive. Pieces
  sung are the documented performances: lines of concerts confirmed as sung.
- **Maintenance:** open tasks per role and age, proposals in the inbox,
  checksums, and the protection terms that end in the period (a proposal by
  the life dates, no legal advice).

The statistics contain no personal data of choir members and no account
names; internal preservation copies are counted but never listed by title or
shelf mark. **Print** prints the overview, **Statistics as CSV** downloads
all tables.

The **annual report** at the end of the page summarises one year for the
board and the general meeting: concerts with their dates and pieces, new
accessions, licenses that started or ended, open rights questions and the
stock. In the first half of the year it proposes the past year. **Print
annual report** prints only the report on A4, in black and white and on
request in large print; **Annual report as CSV** downloads its tables.
Open rights questions and the stock are always those of today, because the
archive keeps no earlier states of them.

## Paper

Every list can be printed in A4 black and white, also in large print:
song list, concert binder list with columns to tick, inventory per binder,
open tasks sorted by binder, shelf labels, statistics and annual report.

## Backups

Librarians find the backups under **Archive management**: a backup now (CSV,
ODS and the complete JSON backup into the archive folder), the download of the
complete backup, and the restore into an empty catalog (see the
administration guide).
