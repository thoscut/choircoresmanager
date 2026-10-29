<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Accessibility statement

This statement covers the Cantoria app for Nextcloud: its pages inside
Nextcloud, the pages "Shared with me" for choir members, and the public
pages (public links and the repertoire page). [Deutsch](de/barrierefreiheit.md)

## Target and status

The app aims at **WCAG 2.2 level AA**. It is **partially conformant**: the
parts listed below do not meet it yet. The statement is a self-assessment
by the developers, not a formal audit. Before the first release all roles
were checked in Nextcloud 33 with axe-core, keyboard-only operation, 320 px
width, 200 % zoom and increased text spacing; the views added since were
checked again with axe-core in the light and the dark theme. These checks
are done by hand; the continuous integration does not run them.

What the app does:

- Every function can be used with the keyboard; the focus moves into a
  dialog or the sidebar and back to where it came from.
- Buttons, fields and icons have labels for screen readers; lists and
  results that change are announced.
- Contrast is sufficient in the light and the dark theme; information is
  never given by colour alone.
- The pages work with zoom up to 200 % and on narrow screens; animations
  respect "reduce motion".
- Error messages stay until they are closed and say what to do.
- The score viewer describes the excerpt in text, announces the current
  measure while playing, and offers playback and text references as an
  alternative to the notation.
- Statistics show every figure as a number in a table; charts are only an
  addition.
- Printouts carry a header with the view, the filters and the date.

## Known limitations

- **Scans** of printed scores are images (PDF). They have no text layer, and
  notation cannot be read by a screen reader. Where a MusicXML file exists,
  the score viewer and playback are the accessible alternative.
- **Notation** in the score viewer is a drawing. Its content is available as
  the text description and through playback, not as the notation itself.
- **Page numbers** in printouts depend on the browser (for example Chrome
  and Edge; Firefox prints without them).
- **Parts of Nextcloud** (navigation, file picker, dialogs, the calendar and
  the Files app) are outside the app. Their accessibility is that of the
  Nextcloud version in use.
- **Translations** other than German and English have not yet been checked
  by native speakers; wording there may be less clear.

## Public pages of a choir

A choir that shows public links or the repertoire page on its website is
responsible for its own website, and may be obliged by law to publish an
accessibility statement for it. The public pages of the app follow the
target above; this statement can serve as a basis for the part they cover.

## Feedback

If something in the app is hard or impossible to use for you, please tell
us in an issue of the repository
([github.com/thoscut/choircoresmanager/issues](https://github.com/thoscut/choircoresmanager/issues)),
or ask the administration of your Nextcloud to pass it on. Describe the
page, what you wanted to do and which assistive technology you use. Do not
include personal data of others.

This statement was written in October 2026 and is updated with each
release that changes the interface.
