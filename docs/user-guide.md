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
and 6 weeks before; the start page lists the concerts with a deadline now.
After the concert, **confirm what was sung** (strike what was left out); the
repertoire figures update by themselves. The **song order** export contains
title, composer, arranger/lyrics, publisher and duration.

A concert may have a **start and end time** (local time at the venue). If it
does not take place, **cancel** it: it stays in the list with its programme,
the deadline notifications stop, and the cancellation can be taken back.

### Concerts in your calendar

In a concert, and for all upcoming concerts in the concert list:

- **Add to my calendar** puts the concert into one of your Nextcloud
  calendars. Choose the calendar in the dialog; a message tells you what was
  added. If the concert is already in one of your calendars (also after a
  change or a cancellation), the entry there is updated instead of added a
  second time; changes you made to it in the calendar are replaced. A
  cancelled concert is marked as cancelled, but not added anew.
- **Calendar file (.ics)** downloads the concert for any calendar program
  (Thunderbird, Outlook, Apple, Google). Every concert keeps its identifier,
  so most programs update the entry when you import the file again.
- **With the preparation deadlines** adds three all-day entries 10, 8 and 6
  weeks before (programme and valid versions, rights, concert binders). They
  are marked "free", so they block no time. Useful for the conductor and the
  music librarian; singers usually leave it off.

A concert without a time is an all-day entry. The entries contain title,
date, time, location, status and a link back to the concert, nothing else
(no notes).

There is no subscription link that updates by itself: a feed reachable
without logging in would make concert data public. Add the concerts again
after changes.

## Dashboard

The Nextcloud dashboard can show the widget **Music archive – due**: the same
as the start page (concert deadlines, numbers of things to do, tasks for your
roles), each with a link into the app. Only accounts with an archive role can
add it.

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
