# User guide

This guide is for the people who look after a choir's sheet music: music
librarians, the conductor, the board and the auditors. It first shows how to
find your way around, then the features everybody uses, and then what each
role does. The interface is available in English and German (informal and
formal address).

What you see depends on your roles. Roles are bound to Nextcloud groups; an
administrator assigns them (see the [administration guide](admin-guide.md)).
The server checks every request, so a button you do not see is a step your
role does not include.

**Contents**

- [Getting around](#getting-around)
- [Scores, practice files and questions](#scores-practice-files-and-questions)
- [Reviewing transcriptions](#reviewing-transcriptions)
- [Concerts and song lists](#concerts-and-song-lists)
- [Statistics and annual report](#statistics-and-annual-report)
- [For music librarians](#for-music-librarians)
- [For operations](#for-operations)
- [For conductors](#for-conductors)
- [For the board and auditors](#for-the-board-and-auditors)
- [For contributors and tools](#for-contributors-and-tools)
- [Paper](#paper)

## Getting around

### Navigation

The navigation on the left lists the views of the app in groups:

- **Due**, **Tasks** and **Inbox** (the inbox only for the roles that see
  proposals: catalog, proposals and read only)
- **Archive:** **Catalog** with its filters, **Persons**, **Protection
  terms** and **Statistics**
- **Releases:** **Singers' portal**, **Print station**, **Ending licenses**
  and **Internal links**
- **Programmes:** **Concerts**, **Song lists** and **Unclear programme
  lines**
- **Maintaining the catalog:** **New accession** (for the other roles
  **Shelf labels**), **Merge**, **Inventory** and **Checksums**

At the bottom, everyone finds **Help** (this guide; in German the quick
start) and **Customize view**, music librarians **Archive management**
(imports, file sync, exports, backups). **Settings** (for administrators and
the groups they have given the settings) leads to **Administration → Music
archive**, the settings page of the app in Nextcloud's administration.

Every view has its own address, so the browser's back button works and a
view can be bookmarked.

### Customize view

**Customize view** at the bottom of the navigation hides the areas of the
navigation and the parts of the start page you do not need, e.g. the
statistics or the concert deadlines. The choice is stored with your account
on the server, so it applies on every device. It only changes what you see:
your permissions stay the same, and a hidden area still opens through a
link or a notification. The start page and the catalog cannot be hidden.

Functions that the administration has switched off (see the admin guide,
"Modules and presets") are missing for everybody; a link to such a function
opens the start page with the note *This function is switched off.*

### Start page "Due"

![The start page "Due" of the Demo Librarian: cards with numbers of things to do, for example open tasks and proposals in the inbox, and below them the tasks for the librarian's roles, sorted by archive binder.](screenshots/start-page-due.png)

The app opens with **Due**: what your roles need now. Cards show how many
things are waiting, for example open tasks for your role, answered tasks to
apply, proposals in the inbox, versions to remove from or add to the singers'
portal, public domain versions of the current repertoire to confirm, licenses
ending or ended, or works of the current repertoire without a chosen valid
version. A click on a card opens the matching list or the catalog with the
right filters.

For conductors and music librarians, **Concert deadlines** lists the planned
concerts whose next preparation step is due: fix the programme and the valid
versions (from 10 weeks before), clarify the rights of all pieces (from 8
weeks), prepare the concert binders (from 6 weeks), and after the date
confirm what was sung (conductor only). **Tasks for your
roles** follow, sorted by archive binder, so that a printed list follows the
shelf.

### Finding a copy

![The catalog of the demo archive with the status filter "Active" (20 of 23 copies): shelf mark, title, composer, rights status with a symbol, repertoire and number of files per row; on the left the search field and the filters for binder, rights status, repertoire and status.](screenshots/catalog.png)

Open **Catalog** and type into the search field **Shelf mark, title or
composer**:

- A shelf mark (`2-1`, also `2/1`, `2 - 01` or `2.1`) finds exactly that
  copy, also a retired one.
- Any other text finds the copies whose title or composer contain every word
  you typed; accents do not matter.

The filters narrow the list: archive binder, rights status, repertoire,
status (active by default; retired, missing and lent copies only when you
choose their status or **All statuses**), **With MusicXML**, **Internal
preservation copies only**, **Public domain, not confirmed** and **No version
valid for the choir**. **Reset search and filters** clears them. The list can
be sorted by catalog order, shelf mark or title; on a phone it shows one card
per copy.

The **rights badge** always shows a text and a symbol, never only a colour:

| Badge | Meaning |
|---|---|
| *public domain* | public domain, confirmed by a music librarian |
| *public domain (suggestion, unconfirmed)* | public domain as imported or proposed, not confirmed yet |
| *protected* | protected by copyright |
| *open* | not clarified |
| *Internal preservation copy – do not share* | internal copy of a protected work: never shared or played |
| *not assessed* | no rights status yet |

### The details of a copy

![The sidebar of copy 1-1 on the tab "Overview": title, rights status, the button "Score", the tabs, and the fields of the work and the version, each section with an "Edit" button.](screenshots/copy-overview.png)

Click a row to open the sidebar. Its head shows the rights badge, the status
if the copy is not active, and **Score** if there is MusicXML you may view.
The tabs:

- **Overview:** work, version and copy, the repertoire (class, documented
  performances, last performed, song lists), merges and the other copies of
  the same work.
- **Rights:** confirmation, releases, the valid version, licenses, open
  proposals, the persons involved with the protection-term suggestion, and
  the rights and MusicXML fields.
- **Tasks:** questions and tasks of the copy and its internal links.
- **Files:** the files linked from the archive folder, each opening in
  Nextcloud in a new tab (see [Files you cannot open](#files-you-cannot-open)).
- **Audit log:** every change with who, when, old and new value and reason.

A section you may change shows **Edit**. Change the fields, optionally enter
a **Reason for the change**, which is stored in the audit log, and **Save**.

> [!NOTE]
> Nothing is ever deleted. A copy that leaves the archive gets the status
> *retired*, *missing* or *lent*, and the status needs a reason.

### Files you cannot open

The app opens files only with the permissions of your own account, the same
as the **Files** app. The scores, scans and practice files lie in the
archive folder; if that folder is not shared with you, you see the catalog,
but no file opens. The app then says so:

- at the top of the page: *The archive folder is not shared with your
  account, so its files (scores, scans, practice files) do not open for you.*
- on the tab **Files** of a copy, and
- in the score view: *The score cannot be shown – The file lies in a folder
  that is not shared with your account. Please ask the music librarians to
  share the archive folder with you or your group.*

Nothing is wrong with the file or with your role. Ask the music librarians
(or the administration) to share the archive folder with you or, better,
with the group of your role; reading is enough. Afterwards reload the page.
If you may change the settings yourself, the button **Check access to the
archive folder** opens the access check under Administration → Music
archive, which lists everybody who cannot open the folder (see the
[administration guide](admin-guide.md#checking-who-can-open-the-archive-folder)).

### Notifications

![The Nextcloud notifications menu with notifications of the music archive, for example a proposal waiting in the inbox.](screenshots/notifications.png)

The app notifies the holders of a role through Nextcloud's notifications, for
example about a question for your role, an answer to apply, a proposal in the
inbox or a concert deadline. A notification disappears when the matter is
done. Notifications never go to singers and contain no personal data. In your
personal Nextcloud settings you choose whether they also arrive by email and
how often, for example as a weekly summary. The
[administration guide](admin-guide.md#6-notifications) lists all of them.

### Dashboard widget

![The Nextcloud dashboard with the widget "Music archive – due", listing a concert deadline and the start page cards with their numbers, and the link "Open the start page".](screenshots/dashboard-widget.png)

The Nextcloud dashboard can show the widget **Music archive – due**: the same
as the start page (concert deadlines, the cards with their numbers, tasks for
your roles), each entry with a link into the app, and **Open the start page**.
Add it in the dashboard's settings. Only accounts with an archive role can
add it. It never shows the text of a question or a person's name.

### Opening from the Files app

<!-- Screenshot planned: screenshots/files-action.png (docs/screenshots/README.md) -->

The archive folder is an ordinary folder in Nextcloud, so you can also find a
file there in the **Files** app. Files of the archive folder have the action
**Open in music archive** in their actions menu (⋯):

- **MusicXML** (`.musicxml`, `.mxl`): a click on the file opens the score
  viewer of its copy directly. If another app in your Nextcloud already opens
  MusicXML, a click stays with that app and the action is in the menu.
- **Scans (PDF), MuseScore, audio and the other files of a copy**: the action
  opens the copy in the sidebar of the music archive. A click on a PDF keeps
  opening Nextcloud's own viewer.

The action is there for accounts with an archive role and for
administrators, and only for files in the archive folder with a type the
file sync knows. When you choose it, the app asks the server which copy the
file belongs to. A file that is not listed in the catalog (for example a file
whose name does not start with a shelf mark) gives the message **This file
is not listed in the music archive.** Opening a file this way grants
nothing: the score viewer checks the rights as always, so internal
preservation copies are only shown to the catalog librarian and the
conductor and never played.

Administrators switch the action off with the module **Opening from the
Files app** (see the [administration guide](admin-guide.md)).

## Scores, practice files and questions

### Score viewer

![The score viewer with measures 1 to 8 of the TTBB setting of copy 2-1: the focus voice Tenor II, the buttons to copy a text reference, create an internal link or add a task, and the notation, in which the notes of Tenor II are drawn in colour with bolder note heads and the other voices in a lighter grey.](screenshots/score-excerpt.png)

**Score** opens the MusicXML of a copy in the browser. Notation, fonts and
sounds are part of the app; nothing is loaded from other servers.

1. Enter **From measure** and **To measure** and choose **Show**. Measures
   are the printed numbers; the pickup is measure 0. **All measures** shows
   the whole piece again.
2. Choose a **Focus voice**, also one that shares a staff with another voice
   (for example Tenor II on the staff "Tenor I/II"). The focus voice is shown
   in colour with bolder note heads and stems, the other voices in a lighter
   grey; the lyrics of its staff stay dark.
3. Choose **Voices during playback**: **All equal**, **Focus voice louder**
   or **Focus voice only**. **Tempo and volume** opens the sliders for tempo
   and volume and the switches **Play repeats** and **Scroll along**.
4. **Play**, **Pause** and **Stop** control the playback.

Keyboard: Space plays and pauses unless a control has the focus, `+` and `-`
change the size while the score has the focus, Escape closes the viewer. The
current measure is announced to screen readers during playback, and the score
has a text description (measures, voices, focus voice, key and time
signature).

> [!IMPORTANT]
> Playback is offered for public domain versions and for versions with a
> valid license for practice audio. Internal preservation copies are never
> played; only the catalog librarian and the conductor may view them.
> Without the right, the viewer shows the reason instead.

### Practice files

![The dialog "Practice files": measures, tempo, repeats and length taken from the score view, the choice between one file and a ZIP archive with every voice and a tutti, the voice, the format WAV or MIDI, the file name and the estimated size.](screenshots/practice-audio-dialog.png)

Music librarians and the conductor make practice files for the choir's
practice system from the score viewer:

1. Choose measures, tempo and repeats in the score viewer.
2. Open **Practice files …**.
3. Choose **One file** (a voice, with the other voices quieter or silent, or
   all voices equal) or **Every voice and a tutti (ZIP)**.
4. Choose the format: **WAV (audio)**, 16 bit, mono or stereo, rendered with
   the same synthesizer and playing order as the playback; or **MIDI (one
   track per voice)**, each track named after its voice, with the balance in
   the volume of each track.
5. **Make file** or **Make ZIP archive**. A progress bar shows how far it is;
   **Cancel** stops. The browser saves the result in your downloads.

File names follow `<shelf mark>_<title>_<voice>_T<from>-<to>`, for example
`2-2_Kein-schoener-Land_Tenor-1_T0-10.wav`. The dialog shows the length and
roughly how large the files are; excerpts longer than 10 minutes get a
warning.

> [!IMPORTANT]
> The server checks the rights before every export: practice files are only
> made for confirmed public domain versions and for versions with a valid
> license for practice audio, never for internal preservation copies. Without
> the right, the reason is shown instead of the button. Every export is
> recorded in the audit log of the version (measures, voice, mode, format).

The sound is synthetic and no substitute for a recording. The files are only
for practising in the choir, as far as the rights or the license allow.
Upload them to the practice system yourself: the app gives nothing to choir
members.

### Questions, tasks and sharing

![The tab "Tasks" of copy 2-1 with an open question to the conductor about measures 5 to 8 of Tenor II, and below it an internal link to the same place.](screenshots/task-question.png)

A question or task always belongs to a copy and may name a place in the
score: page, measures, voice and verse.

1. In the score viewer choose the place, then **This passage** and **New task
   for this place**, or
   in the tab **Tasks** of the sidebar choose **New task**.
2. Choose the kind (**Question**, **Check** or **Report**) and the role it is
   **For** (conductor, catalog, operations or read only).
3. Describe it, add the place and optionally a category, a due date and its
   origin (for example a transcription round). **Create task**.

The addressed role is notified and answers with **Answer**. The music
librarian is notified of the answer and closes the task as **Applied** (the
catalog or the file was corrected) or **Discarded** (nothing to change); a
check can also be closed by the role it is addressed to. **Show in the
score** opens the place. **Tasks** in the navigation lists the tasks for your
roles or all tasks, also the closed ones, and can be printed.

Share a place in two ways, both under **This passage** in the score viewer:

- **Copy text reference** copies a plain text such as
  `2-1 · Ännchen von Tharau · m. 5–8 · Tenor II`. It contains no notation and
  can be passed on anywhere.
- **Create internal link** (music librarians, operations and conductor) makes
  a link for people with a role in the music archive. It shows the measures
  with all voices and highlights the chosen voice. A link is valid for at
  most six months, can be revoked at any time by its creator or a music
  librarian, and says so when the score has changed since. The server checks
  the rights on every call; calls are counted without names. **Internal
  links** in the navigation lists all active links.

## Reviewing transcriptions

When a scan is transcribed into MusicXML (by a person or with a tool), the
transcription ends with a **report** (`… - Bericht.json`, format
`bericht/1`). It says what was uncertain, what was left out and what was
found on the material, measure by measure. The app turns these findings into
**review points**, which the conductor and the music librarians decide in the
score view. The app proposes, people decide: nothing in the catalog changes
by itself.

### Where review points come from

- **File sync.** Put the report next to the MusicXML in the archive folder,
  named like the score file with ` - Bericht.json` at the end (for example
  `5-01_Title - Bericht.json`), and run the file sync. The sync lists the
  report as a report, not as a score file, and reads it.
- **Tools.** A tool with the role "Proposals (tools)" sends the report
  through the app's interface (see the [API](api.md)).

Reading the same report again creates nothing twice. Each report gives:

| In the report | Review point | Decides |
|---|---|---|
| uncertain readings, with measure and voice | **Uncertain reading** | conductor |
| marks left out (for example a breath mark) | **Not transcribed** | conductor |
| findings on the material (a missing page, notes in pencil) | **Material** | music librarian |
| questions | **Question** | conductor when a measure is named, otherwise music librarian |

The **catalog librarian may decide every point**; the conductor decides the
musical ones. The rights assessment and catalog fields of the report (for
example "Verlag / Bestellnummer") go to the [inbox](#inbox) as proposals,
while the proposals inbox is switched on; otherwise they are only shown with
the report. The report's origin and review state of the transcription are
shown with the version.

When a new report for the same copy arrives (a new round of the
transcription with a new MusicXML file), the points it no longer names are
set to **Corrected** ("done in a new version"). If the MusicXML has not
changed, they stay open.

### Reviewing in the score view

1. Open the copy and its **Score**, then **Review** (the sidebar of the copy
   also offers a button with the number of open review points, and a
   notification opens the review directly). The panel **Review of the
   transcription** lists the review points, the round and the review state;
   **Show review points** switches between **To do** and **All**.
2. Choose a point. The score jumps to its measures and highlights its voice
   as the focus voice. **Play this passage** plays exactly these measures
   (only where playback is allowed; the rights check is the same as
   always).
3. **Scan**: when a PDF scan is linked to the copy, **Show scan beside the
   score** shows it next to the score, and **Open scan in a new window** opens
   it in a second window, both at the **Page of the scan** (the page of the
   point, or the page you last looked at). Both use the browser's own PDF
   viewer; nothing is sent to another server. The second window helps on
   small screens and with a magnifier.
4. Decide:
   - **Reading is right** – the transcription stays as it is.
   - **Correct in the app …** – for a wrong pitch, accidental or syllable,
     see [Small corrections](#small-corrections-in-the-app).
   - **Correction order** – for anything larger: describe what is to be
     changed; the tool chain fetches the order and closes it with its next
     report.
   - **Ask a question** – becomes a question (task) with the measures and
     voice of the point, for the conductor or the music librarians.
   - **Dismiss** – not relevant; a reason is required.

   **Reopen** sets a decided point back to open. Who decided, when and why is
   stored; nothing is deleted.

Everything works with the keyboard; a change of status is announced to
screen readers. The catalog shows the number of open review points per copy,
and the filter **Open review points** lists the copies that still need a
decision. The responsible roles are notified of new review points (one
notification per report).

### Small corrections in the app

Small errors are corrected in the app itself (music librarians and
conductor). Only these can be changed:

- the pitch of a note (step, sharp or flat, octave) and its visible
  accidental,
- the syllable of a verse under a note.

Note values, rests, measures and everything else stay as they are; the
measures keep their length.

1. In the score view show a measure (or up to eight), choose the focus
   voice and **Correct notes …**; or choose a review point and **Correct in
   the app …**.
2. The notes of the voice are listed one by one with their duration; change
   pitch, accidental or syllable.
3. The **preview** shows the measures with the changed notes highlighted,
   and the list of changes in words (for example "measure 12, Bass: C3 →
   D3").
4. Save, optionally with a reason, and choose the review points it settles.

The app saves the correction as a **new file next to the old one**, with
your Nextcloud permissions, for example
`5-01_Title (korrigiert 2026-10-22).mxl`. The old file is not changed and
stays linked to the copy; the new one becomes the MusicXML the score view
shows. The audit log lists every change (measure, voice, old → new), and the
chosen review points are set to **Corrected**. The music librarians are
notified of the correction. Without write permission in the folder of the
score, the app says so and saves nothing. Internal preservation copies are
not corrected in the app.

## Concerts and song lists

### Planning a concert

![The programme of the concert "Adventskonzert 2026": the pieces in order with their durations and warnings, for example "Internal preservation copy: not a singing copy" and "No valid version chosen for this work", with buttons to move, edit and remove each piece.](screenshots/concert-warnings.png)

Conductors and music librarians plan concerts:

1. **Concerts** → **New concert**: title, date, optionally start and end time
   (local time at the venue), location, whether it is public and whether the
   board has agreed.
2. **Add a piece (search by shelf mark or title)** → **Add**. Order the
   pieces with the move buttons, enter duration, encore and a note per piece.
   The total duration is added up.
3. Check the warnings of each piece: internal preservation copy, rights not
   clarified, no valid version or another valid version, protected without a
   license for the binders and without a recorded number of originals.
4. **Fix programme** when it is settled; it can still be changed until the
   concert is confirmed as sung.

Deadline notifications go to the conductor and the music librarians 10, 8
and 6 weeks before the concert. If a concert does not take place, **Cancel
concert**: it stays in the list with its programme, the deadline
notifications stop, and **Reopen concert** takes the cancellation back.

### After the concert

On or after the date, **Confirm as sung** and tick the pieces that were not
sung. All other pieces count as documented performances; the repertoire
figures update by themselves, and the programme can no longer be changed.
**Song order (CSV)** exports position, shelf mark, title, composer,
arranger/lyrics, publisher and duration for the collecting society and the
programme booklet. **Print** offers the programme, the concert binder list
with columns to tick and the song list, also in large print.

### Concerts in your calendar

![The dialog "Add to my calendar" with the account's calendar "Choir" to choose and the notes that the preparation deadlines are added as all-day entries and that an existing entry is updated, not added twice.](screenshots/calendar-dialog.png)

In a concert, and for all upcoming concerts in the concert list:

- **Add to my calendar** puts the concert into one of your Nextcloud
  calendars; choose the calendar in the dialog. If the concert is already in
  one of your calendars (also after a change or a cancellation), the entry
  there is updated instead of added a second time, and changes you made to it
  in the calendar are replaced. A cancelled concert is marked as cancelled,
  but not added anew.
- **Calendar file (.ics)** downloads the concert for any calendar program.
  Every concert keeps its identifier, so most programs update the entry when
  you import the file again.
- **With the preparation deadlines (10, 8 and 6 weeks before)** adds three
  all-day entries: programme and valid versions, rights, concert binders.
  They are marked as free, so they block no time.

A concert without a time is an all-day entry. The entries contain title,
date, time, location, status and a link back to the concert, nothing else.

> [!NOTE]
> There is no subscription link that updates by itself: a feed reachable
> without logging in would make concert data public. Add the concerts again
> after changes.

### Song lists and earlier performances

**Song lists** hold the pieces of a period (for example `2025/1`); they are
no proof of performance. Concerts and song lists of earlier years come from
the import in the archive management (see
[the administration guide](admin-guide.md#4-first-import)); **Show imported
history** shows them. Imported lines whose shelf mark is unclear wait under
**Unclear programme lines**: choose one of the candidates or another copy, or
**Not in the archive**, then **Assign**.

## Statistics and annual report

![The statistics of the demo archive: the period, links to the sections stock, rights, repertoire and performances, maintenance and annual report, and the table of key figures with a bar beside each number.](screenshots/statistics.png)

**Statistics** (under *Archive*) is open to every archive role. Choose the
period with **From year** and **To year** (default: the last ten years);
figures without a year are those of today. Each figure is a number in a
table; the bars beside it are only for a quick comparison.

- **Stock:** works, versions and copies; active copies with a file and with
  MusicXML; internal preservation copies; copies per binder by status; new
  copies per year, from new accessions and from the catalog import.
- **Rights:** rights status of the versions, confirmed and not confirmed;
  works with and without a valid version; licenses valid, ending, ended and
  not valid yet; the states of the singers' portal and print station lists;
  publication status.
- **Repertoire and performances:** concerts and pieces sung per year, the
  most performed works and composers of the period, the repertoire classes,
  and works not sung for a long time that can be sung from the archive.
- **Maintenance:** open tasks per role and age, proposals in the inbox,
  checksums, and the protection terms that end in the period (a proposal by
  the life dates, no legal advice).

The statistics contain no personal data of choir members and no account
names; internal preservation copies are counted but never listed by title or
shelf mark. **Print** prints the overview, **Statistics as CSV** downloads all
tables.

![The annual report of a year: the year in figures, the concerts with date, location, status and number of pieces, and the new accessions.](screenshots/annual-report.png)

The **Annual report** at the end of the page summarises one year for the
board and the general meeting: concerts with their dates and pieces, new
accessions, licenses that started or ended, open rights questions and the
stock. In the first half of the year it proposes the past year. **Print
annual report** prints only the report on A4 in black and white, optionally
in large print; **Annual report as CSV** downloads its tables.

> [!NOTE]
> Open rights questions and the stock are always those of today, because the
> archive keeps no earlier states of them.

## For music librarians

The role **Catalog (music librarian)** keeps the catalog: works, versions and
rights, imports, the file sync, proposals and closing tasks. It also may do
everything operations does with copies.

### Confirming rights

![The rights tab of a version: the confirmation with status, date, name, reason and evidence, the releases for the singers' portal and the print station, the version valid for the choir and the licenses.](screenshots/rights-panel.png)

Rights belong to the version, not to the work. An imported or proposed status
stays a suggestion until a music librarian confirms it:

1. Open the copy → tab **Rights** → **Confirm**.
2. Choose the confirmed status: public domain or protected.
3. Enter a **Reason** and the **Evidence**, for example "All persons involved
   died more than 70 years ago" and "Life dates from the register of
   persons". Both are required.
4. **Confirm**. Name and date are recorded.

Changing the rights status later removes the confirmation. **When in doubt:
set to open** sets the status to *open* with a reason and removes any
confirmation.

> [!NOTE]
> The rights helpers model German copyright law. They support a decision and
> are no legal advice.

### Licenses

**Add license** on the rights tab records holder or publisher, the uses
(at least one), number of copies, validity, evidence and notes. Each use is
separate: copies for the binders, singers' portal, self-printing by singers,
print station, practice audio, recording and publication. A license for
printing does not allow the singers' portal. Licenses are never deleted; an
ended license stays as a record. **Ending licenses** lists the licenses that
end within the chosen period or have ended.

### Releases: singers' portal and print station

The singers practise in the choir's own system; the app only keeps track of
what may be there. A version is released for the **singers' portal** or the
**print station** if it is confirmed public domain or has a valid license for
that use, and never if it is an internal preservation copy.

- **Provide in the singers' portal** records since when a version is there;
  it is offered only for released versions.
- **Take out of the singers' portal** records the removal with a reason.
- The lists **Singers' portal** and **Print station** compare the target and
  the actual state: what to remove, what to add (released and valid for the
  choir), and what waits for a valid version.

### Inbox

Tools and transcription rounds do not change the catalog. Their proposals
wait in the **Inbox**, grouped by the work, version or copy they concern,
with the current and the proposed value, the source and the reason. **Accept** applies the value with your rights and
records it in the audit log; **Reject** discards it. Both take an optional
reason.

### Applying answers

Answered questions appear on the start page as **Answered tasks to apply**.
Correct the catalog or the file if needed, then close the task as **Applied**
or **Discarded** (see [Questions, tasks and sharing](#questions-tasks-and-sharing)).

### Transcriptions and correction orders

Material findings and questions without a measure of a transcription report
are yours, and you may decide every other review point, too (see
[Reviewing transcriptions](#reviewing-transcriptions)). Correction orders wait
for the tool chain until its next report closes them. You are notified when a
correction arrives, from the app or with a new round.

### Imports, file sync and exports

**Archive management** at the bottom of the navigation holds the imports of
the catalog, of performances and song lists and of the persons register, the
**File sync** of the archive folder, the exports and the backups. Every
import first runs as a dry run with a report. The
[administration guide](admin-guide.md#4-first-import) describes the first
import and the file sync.

A file is linked to a copy by the shelf mark at the start of its name. A file
whose name does not fit can be linked by hand on the tab **Files** with
**Link a file …**; such a link stays, whatever the file is called. A file
whose name ends with `bericht.json` is a transcription report: the sync reads
it into review points instead of linking it as a score file.

### New accessions and shelf labels

![The form "Record a new piece": binder and number with the next free number suggested, the resulting shelf mark, title, composer, title source, accession date and origin, and a button to link a file.](screenshots/accession-form.png)

1. **New accession** → **Record a new piece**.
2. Choose the binder. The app suggests the next free number; numbers are never
   reused, not even those of retired copies.
3. Fill in title, composer, title source, accession date and origin; **More
   details** holds arranger, publisher, rights and counts.
4. Optionally **Link a file (optional) …** from the archive folder.
5. **Record accession**. Work, version and copy are created, and the label
   is added to the sheet below.

Next, confirm the rights and add the persons involved on the rights tab. New
copies go only into the **Binders open for new copies**; open a new binder
when the current one is full.

**Shelf labels:** enter shelf marks (separated by commas or line breaks;
`10-70..75` stands for 10-70 to 10-75) and **Add labels**. Choose the first
free place if a sheet has been used in part, then **Print labels**.

> [!TIP]
> The labels fit Avery 3653 and compatible sheets (2 × 7 labels of
> 105 × 42.3 mm on A4). Print at actual size (100 %), without fitting to the
> page and without the browser's headers and footers; Firefox adds them by
> default.

### Persons and protection terms

![The list "Protection terms" of the demo archive: copy 1-7, marked public domain although the persons recorded for it do not allow a public domain proposal, with rights status, proposal and reason.](screenshots/protection-terms.png)

**Persons** is the register of composers, lyricists, arrangers, translators
and editors with their life dates, the precision and source of the dates, and
how certain the identification is. **Mark as checked** records who checked a
person and when. A person can only be deleted while no version names them.

On the rights tab, **Persons involved and protection** → **Add a person**
links a person to the version with a role. From their life dates the app
suggests the protection term with its reasoning: protection ends at the end
of the 70th year after the death of the last of them. Unknown or approximate
years of death, living persons and uncertain identifications count as
protected.

**Protection terms** lists what to check: versions marked protected that are
public domain by the life dates, versions marked public domain that the
recorded persons do not allow, and protected versions with a known end.

> [!IMPORTANT]
> The suggestion never changes the rights status. A music librarian checks it
> and confirms the rights.

### Merging works and moving copies

![The view "Merge" with the three pairs of works listed twice in the demo archive: each pair with its copies, the choice of the work to keep and the button to merge into it.](screenshots/merge-proposals.png)

When the catalog lists the same work twice, **Merge** proposes the works with
the same title and the same composer surname:

1. Check the copies; different arrangements stay different versions of one
   work.
2. Choose the **Work to keep** and **Merge into the kept work**.

**Merge by shelf mark** merges any two works or moves a copy to another
version, for example a second copy of the same print. Nothing is deleted:
the merged work disappears from the lists, programme lines move along, and a
merge never changes a rights status. A merge is refused when both works have
a version valid for the choir. **Undo** works as long as the moved data has
not been changed.

### Checksums

A background job reads the archive files hour by hour and keeps their
checksum. **Checksums** lists files whose content changed although their
size and modification time stayed the same; they may be damaged. Restore such
a file from a backup or from the version history, and the mark disappears.
**Accept new content** only if the change was intended.

### Backups

**Archive management** → **Backup** → **Store a complete backup now** writes
the catalog as CSV, a spreadsheet with the audit log, and the complete JSON
backup into the archive folder; the app does this every week by itself.
**Complete backup and restore** downloads the complete backup and restores
it into an empty catalog. See the
[administration guide](admin-guide.md#5-backups-and-restore).

## For operations

The role **Operations (music librarian)** looks after the copies on the
shelf. It changes status, kind and counts of copies, counts the binders, and
answers and closes the checks addressed to it.

### Changing the status of a copy

1. Open the copy → tab **Overview** → **Edit** in the section **Copy**.
2. Choose the status *retired*, *missing* or *lent* and enter the **Reason
   for status**, or *active* when the copy is back.
3. **Save**.

### Inventory

1. **Inventory** → choose the archive binder and **Print** the list in shelf
   order.
2. Tick on paper what is there.
3. Back in the app, tick **Counted** for each copy that is there and enter a
   figure only where it differs; an empty field keeps the listed one.
4. Check the **Date of the count** and record the count.

The list shows when each copy was last counted and how many copies of the
binder were never counted.

## For conductors

The **Conductor** decides which version the choir sings, answers musical
questions and plans concerts with the music librarians. The conductor may
also enter key, scoring, language, duration and the review of a
transcription, view internal preservation copies (without playback), and
make practice files.

### Choosing the valid version

1. Open a copy of the version the choir sings → tab **Rights** → section
   **Valid for the choir**.
2. **Set as valid for the choir**, optionally with a reason, and confirm.

There is at most one valid version per work; if another version was valid,
the dialog says so and that version is no longer valid. **Set as no longer
valid for the choir** clears it. Choosing the valid version changes nothing
about the rights.

> [!TIP]
> The filter **No version valid for the choir** in the catalog and the start
> page card for the current repertoire show where a decision is missing.

### Answering questions

Questions for the conductor appear on the start page, in **Tasks** and as a
notification.

1. Open the question; **Show in the score** shows the place.
2. **Answer**, write the answer and **Save answer**.

The music librarian then applies the answer to the catalog or discards it.

### Reviewing transcriptions

The musical review points of a transcription report are yours: uncertain
readings, marks that were not transcribed and questions about a measure. You
are notified when a report brings new ones. In the score view each point
shows its measures with the voice highlighted and plays them; confirm the
reading, order a correction, ask a question or dismiss the point, or correct
a wrong note or syllable in the app yourself (see
[Reviewing transcriptions](#reviewing-transcriptions)).

### Concerts, calendar and practice files

- [Planning a concert](#planning-a-concert) and
  [confirming what was sung](#after-the-concert)
- [Concerts in your calendar](#concerts-in-your-calendar)
- [Practice files](#practice-files) for the choir's practice system

## For the board and auditors

The role **Read only (board, auditors)** reads everything, including the
audit log of every copy, the inbox, the internal links and the statistics,
and answers the tasks addressed to it. It changes nothing.

- The [annual report](#statistics-and-annual-report) summarises a year for the
  general meeting.
- **Internal links** shows every active excerpt link with its creator, expiry
  and number of calls.
- The tab **Audit log** of a copy shows who changed what, when and why.

## For contributors and tools

The role **Proposals (tools)** is meant for transcription rounds and scripts.
They send proposals for catalog fields through the app's interface (see the
[API](api.md)); nothing changes until a music librarian accepts a proposal in
the inbox. Contributors see the inbox and can ask questions about a copy.
They also send transcription reports, which become review points, and fetch
the correction orders; the next report of a round closes them (see
[Reviewing transcriptions](#reviewing-transcriptions)).

## Paper

Paper stays the fallback. The views with a **Print** button print on A4 in
black and white: the start page, tasks, inbox, release lists, ending
licenses, internal links, concerts and song lists, unclear programme lines,
persons, protection terms, the inventory of a binder, and the statistics. A
programme prints as programme, concert binder list with columns to tick or
song list; programmes, statistics and the annual report also in large print.
Shelf labels print on label sheets (see
[New accessions and shelf labels](#new-accessions-and-shelf-labels)).
