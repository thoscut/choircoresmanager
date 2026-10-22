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

**Practice files** (music librarian and conductor): **Practice files …** makes
files for the choir's practice system from the bars, tempo and repeats chosen
in the score view. Choose one file (a voice louder, only this voice, or all
voices equal) or a ZIP archive with a file per voice plus a tutti, as WAV
(16 bit, mono or stereo; sounds exactly like the playback) or MIDI (one track
per voice with its name; the balance is in the volume of each track, so it can
be changed in a MIDI player). File names follow
`<shelf mark>_<title>_<voice>_T<from>-<to>`, e.g.
`2-2_Kein-schoener-Land_Tenor-1_T0-10.wav`. The dialog shows the length and
roughly how large the files are; excerpts longer than 10 minutes get a warning.
A progress bar shows how far the files are, and **Cancel** stops.

The server checks the rights before every export: practice files are only
made for confirmed public domain versions and for versions with a valid
license for practice audio, never for internal preservation copies. Without
the right, the reason is shown instead of the button. Every export is recorded
in the history of the version (bars, voice, mode, format). The sound is
synthetic and no substitute for a recording; the files are only for practising
in the choir, as far as the rights or the license allow. Upload them to the
practice system yourself: the app gives nothing to choir members.

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

## Paper

Every list can be printed in A4 black and white, also in large print:
song list, concert binder list with columns to tick, inventory per binder,
open tasks sorted by binder, shelf labels.

## Backups

Librarians find the backups under **Archive management**: a backup now (CSV,
ODS and the complete JSON backup into the archive folder), the download of the
complete backup, and the restore into an empty catalog (see the
administration guide).
