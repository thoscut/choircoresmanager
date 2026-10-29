<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Data protection: templates for the choir

**English** | [Deutsch](de/datenschutz-vorlagen.md)

These templates help a choir add the music archive to its data protection
records. They describe what the app stores (as of version 0.11). They are
**no legal advice**: adapt them to your choir, your Nextcloud and your
modules, and if in doubt have your data protection officer check them. Fill
in what is in square brackets `[…]`.

What the app stores in detail is in the
[administration guide, section 10](admin-guide.md#10-data-protection). The
German version, [datenschutz-vorlagen.md](de/datenschutz-vorlagen.md), is the
one to use for German choirs; it follows the same structure.

## 1. Register of processing activities (Art. 30 GDPR)

For all entries:

- **Controller:** [name of the choir, address, represented by …]
- **Data protection officer, if appointed:** [name, contact]
- **Technology:** Nextcloud on [own server / at provider …]; with a provider
  there is a data processing agreement (Art. 28 GDPR).
- **Technical and organisational measures:** access only with a Nextcloud
  account and a role in the music archive; roles separated by task;
  encrypted connections (HTTPS); backups in the backup folder of the account
  that set the archive folder; [further measures of the Nextcloud, e.g.
  two-factor login].

| Processing | Purpose | Data subjects | Data | Legal basis | Erasure |
|---|---|---|---|---|---|
| Catalog and audit log | managing the sheet music, evidence of rights and changes | members with a role in the archive | account and display name with every change | Art. 6 (1) f | not erased (evidence); a deleted account is removed, the display name stays |
| Register of persons | protection terms and rights of the works | living composers, lyricists, arrangers, translators, editors (the dead are not covered) | name, role, year of birth (no full date), source, GND/Wikidata | Art. 6 (1) f; no individual information (Art. 14 (5) b) | while a version needs the person; life dates at once on objection |
| Folder book (module) | getting the choir folders back | choir members with a folder | name or account, days of issue and return, notes | Art. 6 (1) b or f | [12] months after the return; 36 months after the issue if the folder never comes back; at once when the account is deleted (open loan: only the account); backups until rotated after eight weeks |
| Sharing with choir members (modules) | practising with shared excerpts | choir members | calls counted without who; practice files with the role only, **no account or name** | Art. 6 (1) b; for the log f | no personal data kept |
| Public pages (modules) | showing excerpts and the repertoire; protection against abuse | visitors without an account | IP address, time, address called, user agent in the web server log, brute-force protection, rate limit, `nextcloud.log` on errors | Art. 6 (1) f | web server log [7 days]; brute-force protection [48 hours]; no cookies, nothing from third parties |
| Agents (module) | [proposals, reviewing transcriptions] | as catalog and register (role "Proposals (tools)": no folder book) | what the agent reads | Art. 6 (1) f | provider of the model as processor (Art. 28), [third country and safeguard, Art. 44 ff.] |

## 2. Information for choir members (Art. 13 GDPR)

> **Music archive.** [The choir] keeps its sheet music in a Nextcloud with
> the app "Notenarchiv". When you get a choir folder, we record which folder
> you have since when (name or Nextcloud account, day of issue, notes), so
> that the choir's music comes back (Art. 6 (1) b GDPR). [12] months after
> the return we remove name and notes automatically; if a folder does not
> come back, after 36 months. [Music librarians, conductor, board] can read
> these data.
>
> When we share excerpts with you, we count calls without recording who
> opened them. When you make a practice file, we record that a choir member
> made it (piece, measures, voice), not who. We need this as evidence for the
> rights holders (Art. 6 (1) f GDPR).
>
> You can ask for access to your data, their correction or erasure, and
> object to the processing: [contact]. You can complain to a supervisory
> authority, e.g. [competent authority].

## 3. Privacy policy of the public pages

> **Public pages of the music archive.** [The choir] shows excerpts and its
> repertoire for everyone at [address]. Our server processes technically
> necessary data: IP address, time, address called and user agent. They are
> kept in the log of the web server to protect the server against abuse, for
> example against guessing link addresses (Art. 6 (1) f GDPR), and deleted
> after [7 days]; entries of the protection against repeated failed attempts
> after [48 hours]. The pages set no cookies and load nothing from third
> parties. Playback happens in your browser. [Controller, contact, rights as
> in section 2.]

Before switching on the public modules, check:

- Are the addresses of the **imprint** and the **privacy policy** set under
  *Administration → Theming*? The module settings warn while one is missing.
- Do the retention periods above match your server (log rotation of the web
  server, `nextcloud.log`)?
- Behind a reverse proxy, does Nextcloud see the real addresses
  (`trusted_proxies`)? Otherwise the brute-force protection hits the proxy.
