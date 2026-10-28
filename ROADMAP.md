<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Roadmap Notenarchiv

Das Notenarchiv ist eine Nextcloud-App für Chöre. Sie führt das
Werkverzeichnis eines Notenarchivs in Nextcloud. Die Dateien selbst (Scans,
MusicXML, MuseScore) bleiben, wo sie sind, in einem Ordner der Nextcloud.

Die App ist aus den Anforderungen von fünf Beteiligten entstanden: Sänger,
Chorleitung, Rechteinhaber, Vereinsorgane und Archivverwaltung (Notenwarte).

Unterstützt werden Nextcloud 33 bis 35.

## Leitlinien

Dauerhafte Ziele jeder Runde: **einfach gebaut (KISS), barrierefrei, sicher
und nutzerfreundlich** (Leitlinien 11, 13, 14 und 15).

1. **Die App schlägt vor, Menschen entscheiden.**
   - Einen Rechtestatus bestätigt die Archivverwaltung.
   - Welche Fassung „gilt“, legt die Chorleitung fest.
   - Kein Import und keine Automatik setzt eins von beiden.
2. **Rechte hängen an der Fassung, nicht am Werk.**
   - Ein Ausschnitt ist rechtlich so viel wie das Ganze.
   - Interne Erhaltungskopien werden nie weitergegeben, abgespielt oder
     exportiert.
3. **Nichts wird gelöscht.**
   - Exemplare bekommen einen Status.
   - Jede Änderung wird protokolliert: wer, wann, alt, neu, Grund.
4. **Dateien werden nur gelesen.** Die App ändert, verschiebt und benennt
   keine Datei um. Es gibt zwei Ausnahmen:
   - Eine kleine Korrektur einer MusicXML (Tonhöhe, Vorzeichen, Silbe)
     speichert die App als neue Datei neben der alten. Die alte bleibt
     unverändert, die Änderung steht im Protokoll.
   - Der Spiegelordner für die Druckstation, nur mit seinem Modul: Die App
     schreibt nur in den eigenen Zielordner, nie in den Archivordner. Dort
     ersetzt oder entfernt sie nur Dateien, die sie selbst geschrieben hat.
     Jede Schreib- und Löschaktion steht im Protokoll.
5. **Rollen statt Personen.**
   - Rollen werden Nextcloud-Gruppen zugeordnet.
   - Jede Rolle schreibt nur ihre Felder.
   - Wer keine Rolle hat, sieht die App nicht.
   - Chormitglieder sind eine eigene Rolle, aber keine Archivrolle: Sie sehen
     nur „Für mich freigegeben“ mit den Ausschnitten, die für sie geteilt
     sind, und das nur, solange das Modul eingeschaltet ist.
6. **Werkzeuge schlagen vor, Menschen übernehmen.** Massenänderungen aus
   Skripten landen in einem Eingang.
7. **Es gibt immer einen Rückweg.** Ein datierter Export (CSV/ODS) liegt
   regelmäßig im Archivordner.
8. **Papier bleibt die Rückfallebene.** Alle Listen lassen sich in A4
   schwarzweiß drucken.
9. **Keine fremden Server.** Notensatz, Schriften und Klänge sind in der App
   enthalten. Es gibt kein CDN und keine Telemetrie.
10. **Keine Rechtsberatung.** Die Rechte-Hilfen bilden deutsches Recht ab
    (Stand der jeweiligen Version). Sie unterstützen eine Entscheidung,
    ersetzen sie aber nicht.
11. **Barrierefrei und inklusiv.**
    - Ziel ist WCAG 2.2 AA.
    - Alles ist per Tastatur und mit Bildschirmleser bedienbar.
    - Information steht nie nur in der Farbe; Kontraste stimmen im hellen und
      im dunklen Design.
    - Zoom bis 200 % geht ohne Verlust; reduzierte Bewegung wird beachtet.
    - Zum Notenbild gibt es Alternativen: Textverweis, Textbeschreibung des
      Ausschnitts und Abspielen.
    - Die deutsche Oberfläche verwendet neutrale, einfache Sprache.
12. **Nur, was gebraucht wird.**
    - Jede Funktion außerhalb des Kerns ist ein Modul, das die Administration
      abschalten kann. Zum Kern gehören Werkverzeichnis, Dateiabgleich,
      Rollen, Protokoll, Import, Export und Sicherung.
    - Ein abgeschaltetes Modul ist auch auf dem Server aus: keine Routen,
      keine Hintergrundjobs, keine Meldungen. Seine Daten bleiben erhalten.
    - Jede Person kann Bereiche ausblenden, die sie nicht braucht.
13. **Einfach und fehlerfrei.** Die App wird von Ehrenamtlichen ohne
    IT-Hintergrund bedient.
    - Fehler vermeiden statt melden: Die Einrichtung ist geführt, Eingaben
      werden vorab geprüft, Folgen stehen vor dem Speichern da, und es gibt
      einen Rückweg.
    - Jede Meldung sagt, was los ist und was zu tun ist, mit Verweis auf die
      passende Stelle der Doku. Leere Ansichten nennen den nächsten Schritt.
    - Die Doku ist nach Aufgaben je Rolle gegliedert und hat einen Abschnitt
      „Häufige Probleme“.
14. **Einfach gebaut (KISS).**
    - Die einfachste Lösung, die trägt; keine Abstraktion auf Vorrat.
    - Vorhandene Dienste werden wiederverwendet, statt eine Regel ein zweites
      Mal zu bauen.
    - Eine neue Abhängigkeit nur, wenn sie deutlich Aufwand spart.
    - Viele Module heißen nicht viel Komplexität je Modul.
15. **Sicher von Anfang an.**
    - Jede Route prüft Rolle und Modul auf dem Server; Tests prüfen das für
      alle Routen.
    - Sichere Voreinstellungen: Was etwas nach außen öffnet, ist anfangs aus.
    - Begrenzte Aufrufe, keine Kontonamen nach außen, keine Geheimnisse in
      Protokollen.
    - Das Bedrohungsmodell steht in `SECURITY.md`.

## Benachrichtigungen (Querschnitt)

Die App meldet sich über die Nextcloud-Benachrichtigungen, die auf Wunsch
auch als E-Mail-Zusammenfassung kommen. Dafür gilt:

- Meldungen gehen nur an Rollen (Archivverwaltung, Chorleitung), nie an
  Sänger.
- Sie enthalten keine personenbezogenen Daten.
- Sie lassen sich bündeln.
- Eine erledigte Aufgabe nimmt ihre Meldung zurück.

Ab welchem Meilenstein welcher Anlass gemeldet wird, steht bei den
Meilensteinen.

## Meilensteine

Jeder Meilenstein ergibt eine Minor-Version (0.6 → 0.6.0, 0.7 → 0.7.0);
interne Zwischenstände heißen `0.7.0-dev.1` usw. (siehe `docs/release.md`).

### Stand auf einen Blick

Stand: 28. Oktober 2026. **Fertig** heißt: im Code, getestet und in einer
Version auf `main`. **Im Review** heißt: fertig im Code und grün in der CI,
aber noch nicht in `main` zusammengeführt. **Geplant** heißt: noch nicht
begonnen.

| Meilenstein | Stand | Wo |
|---|---|---|
| 0.1 Fundament bis 0.6 Agenten und Prüfung | fertig | Version 0.6.0 |
| 0.7 Teilen, Mappen und Druckstation | fertig | Version 0.9.0 |
| 0.8 Archiv und Abspielen | fertig (Übersetzungen außer Deutsch: muttersprachliche Prüfung offen) | Version 0.9.0 |
| 0.9 Benutzerführung | fertig | Version 0.9.0, 0.9.1 |
| 0.10 Verlässlich bauen und sichern | im Review | PR #10 |
| 0.11 Rechte und Datenschutz schärfen | im Review | PR #10 |
| 0.12 Klar bedienen und an das Chorsystem übergeben | im Review | PR #10 |
| 0.13 Üben in der Notenansicht | im Review | PR #11 (baut auf PR #10 auf) |
| 0.14 Text und Tonhöhe beim Mitsingen | im Review | PR #11 |
| 0.15 Ohne Netz üben und vortragen | im Review | PR #12 (baut auf PR #11 auf) |
| 1.0 Stabil | geplant | – |

Die aktuelle Version ist 0.9.1. Mit PR #10 und PR #11 kommen 0.10 bis 0.14
dazu, mit PR #12 0.15; danach ist 1.0 offen.

### 0.1 Fundament

*Fertig, Version 0.6.0.*

- **Datenmodell** in vier Ebenen:
  - Werk
  - Fassung (Satz, Bearbeitung, Ausgabe)
  - Exemplar (Signatur)
  - Datei
  - dazu ein Protokoll
- **Rollen und Gruppen.** Der Server prüft jeden Zugriff, und die App ist für
  andere unsichtbar.
- **Import** eines vorhandenen Werkverzeichnisses aus CSV:
  - Datei aus der Nextcloud wählen
  - Bericht und Schnappschuss
  - Wiederholung möglich
- **Dateiabgleich** mit dem Archivordner über das Signatur-Präfix im
  Dateinamen
- **Liste, Suche, Filter und Werkansicht**, auch am Smartphone, mit
  Rechteampel
- **Pflege mit Protokoll.** Status statt Löschen.
- **CSV-Export**, rundlauffähig zum Import

### 0.2 Rechte und Verantwortung

*Fertig, Version 0.6.0.*

- **Rechte bestätigen**, mit Begründung und Beleg als Pflicht
- **Drei getrennte Freigaben:**
  - digitale Bereitstellung für Mitglieder
  - Veröffentlichung
  - Prüfstand
- **Lizenzen mit einzelnen Nutzungsarten**, Stückzahl und Gültigkeit
- **Erhaltungskopien** als harte Kennzeichnung mit Prüfung des Originals
- **„Gilt für den Chor“** nur durch die Chorleitung, mit Historie
- **Soll/Ist-Abgleich** für das Chorsystem, in dem die Sänger üben, und für
  eine Druckstation
- **Vorschlags-Eingang** für Werkzeuge
- **Automatischer, datierter Export** (CSV/ODS mit Protokoll) als
  Hintergrundjob
- **Benachrichtigungen, Grundlage:**
  - neuer Vorschlag
  - Fassung herabgestuft, aber noch bereitgestellt
  - Lizenz läuft ab
  - Export fehlgeschlagen
- **Erste Veröffentlichung im App Store**, als experimentell gekennzeichnet

### 0.3 Aufgaben, Rückfragen und Teilen

*Fertig, Version 0.6.0.*

- **Rückfragen und Prüfaufgaben** mit Stelle: Seite, Takt von–bis, Stimme,
  Strophe. Dazu Adressat-Rolle und Status.
- **Startseite „fällig“** je Rolle, mit Druckliste
- **Benachrichtigungen:** neue Rückfrage an meine Rolle; Rückfrage
  beantwortet, zu übernehmen
- **MusicXML-Ansicht in der App:**
  - gedruckte Taktnummern
  - Ausschnitt nach Taktbereich, mit der Scan-Seite daneben
  - gewählte Stimme hervorgehoben, auch innerhalb eines Systems (z. B.
    Tenor II in „Tenor I/II“)
- **Teilen nach Stück, Takt und Stimme:**
  - **Textverweis ohne Noten:** für alle Werke, frei weitergebbar
  - **Ausschnitt-Link:** für angemeldete Archivrollen. Er zeigt den
    Taktbereich mit allen Stimmen; die gewählte Stimme ist der Fokus und wird
    hervorgehoben. Der Server prüft bei jedem Aufruf. Der Link läuft ab, lässt
    sich widerrufen und zeigt „Stand geändert“, wenn sich die Datei ändert.

### 0.4 Programme, Listen und Aufführungen

*Fertig, Version 0.6.0.*

- **Programme und Notenlisten:**
  - Fassung je Programmpunkt
  - Status Entwurf → fest → gesungen
  - Rechtewarnungen und Fristen
- **„Gesungen“ buchen.** Repertoire, Aufführungszahl und „zuletzt gesungen“
  werden daraus abgeleitet.
- **Ausgaben:**
  - Liederfolge (CSV/PDF) für Verwertungsgesellschaft und Programmheft
  - Konzertmappenliste
  - Notenliste
- **Import einer vorhandenen Aufführungsliste.** Mehrdeutige Zeilen werden zu
  Rückfragen.
- **Benachrichtigungen:**
  - Konzertfristen
  - „gesungen“ bestätigen
  - wählbare wöchentliche Zusammenfassung statt Einzelmeldungen

### 0.5 Bestand pflegen

*Fertig, Version 0.6.0.*

- **Neuzugang-Assistent:**
  - schlägt die nächste freie Signatur vor
  - fragt die Pflichtfelder ab
  - druckt Etiketten als PDF
- **Personenregister und Beteiligungen:**
  - Rollen: Komponist, Text, Bearbeitung, Übersetzung
  - Lebensdaten mit Genauigkeit und Quelle
  - Schutzende nur als Vorschlag; unbekannte Daten gelten als geschützt
  - Wiedervorlage
- **Werke zusammenführen** per Vorschlag, rücknehmbar
- **Benachrichtigung:** Wiedervorlage zum Schutzfristende
- **Inventurliste und Prüfsummenvergleich**

### 0.5.1 Module und Voreinstellungen

*Umgesetzt mit App-Version 0.1.27* (Register: `lib/Service/Modules.php`;
Beschreibung im Admin-Guide, Abschnitt „Modules and presets“, und in
`docs/api.md`).

- **Modul-Schalter in den Admin-Einstellungen**, gruppiert und mit
  Beschreibung. Abhängigkeiten werden angezeigt (z. B. Übe-Audio braucht die
  Notenansicht, der Kalender die Konzerte). Dazu ein `occ`-Befehl.
- **Die Server-Seite erzwingt die Schalter:**
  - Routen eines abgeschalteten Moduls antworten nicht.
  - Hintergrundjobs, Benachrichtigungen, Fällig-Karten, Dashboard-Widget und
    Navigation lassen es aus.
  - Ein Test stellt sicher, dass jede Route zum Kern gehört oder einem Modul
    zugeordnet ist.
- **Voreinstellungen:** „Einfach“ (Werkverzeichnis, Dateien, Notenansicht),
  „Standard“ und „Vollständig“.
  - Bestehende Installationen behalten alle bisherigen Funktionen.
  - Die Agenten-Schnittstelle ist anfangs aus.
- **Persönliche Ansicht:** Jede Person blendet Bereiche aus, die sie nicht
  braucht. Die Rechte ändern sich dadurch nicht.
- **Benachrichtigungen** lassen sich je Anlass abschalten.

### 0.6 Agenten und Prüfung der Übertragungen

*Umgesetzt mit Version 0.6.0: die Schnittstelle für Agenten (Beschreibung in
`docs/api-v1.md`, Einrichtung im Admin-Guide, Abschnitt „Agents and the
API“, MCP-Server in `tools/mcp`) mit Prüfstand, Prüfpunkten,
Korrekturaufträgen und dem Einreichen von Berichten, sowie Übertragung
prüfen, Prüfen in der Notenansicht, Korrigieren und die
Benachrichtigungen dazu, als Module `transcription_review` und
`corrections` (Beschreibung in `docs/api.md`, Abschnitt „Reviewing
transcriptions and corrections“, und im Benutzerhandbuch, Abschnitt
„Reviewing transcriptions“).*

- **Schnittstelle für Agenten:**
  - eine feste, versionierte API (OCS, `api/v1`) mit OpenAPI-Beschreibung
    für Lesen, Fällig-Liste, Vorschläge, Rückfragen und Prüfpunkte
    – *umgesetzt; Prüfstand und Prüfpunkte eines Exemplars,
    Korrekturaufträge und Berichte (`bericht/1`) als Routen von v1.*
  - ein MCP-Server, der lokal neben dem Agenten läuft, mit App-Passwort
    eines eigenen Kontos – *umgesetzt.*
  - Schreibende Werkzeuge erzeugen nur Vorschläge, Rückfragen und
    Prüfergebnisse. Der Server begrenzt sie über die Rolle. – *umgesetzt:
    Prüfergebnisse kommen als Bericht einer Übertragung und werden zu
    Prüfpunkten; entscheiden (Lesung richtig, korrigieren, verwerfen) und
    Korrekturen speichern bleiben bei Chorleitung und Archivverwaltung in
    der App.*
  - Ein Agentenlauf gegen den Demo-Bestand läuft in der CI mit.
    – *umgesetzt (`tools/ci/integration-demo.sh`, Schritt 7).*
  - Das Modul „Schnittstelle für Agenten“ ist anfangs aus und in keiner
    Voreinstellung enthalten, auch nicht in „Vollständig“.
- **Übertragung prüfen** (umgesetzt):
  - Der Bericht einer Übertragung (`bericht.json`, Format `bericht/1`) kommt
    über den Dateiabgleich oder die API.
  - Unsicherheiten, Auslassungen und Rückfragen werden zu Prüfpunkten mit
    Takt und Stimme.
  - Rechte und Werkverzeichnis-Felder gehen als Vorschläge in den Eingang.
  - Der Prüfstand steht bei der Fassung und lässt sich filtern.
- **Prüfen in der Notenansicht** (umgesetzt):
  - Ein Prüfpunkt springt zu den Takten, hebt die Stimme hervor und spielt
    die Stelle. Die Scan-Seite steht daneben.
  - Entscheidungen: Lesung richtig, korrigieren oder Rückfrage.
  - Musikalische Fragen entscheidet die Chorleitung, Material- und
    Rechtefragen die Archivverwaltung.
- **Korrigieren** (umgesetzt):
  - Kleine Korrekturen (Tonhöhe, Vorzeichen, Silbe) gehen in der App über
    ein Formular je Takt und Stimme, mit Vorschau. Die App speichert sie als
    neue Datei.
  - Alles Größere geht als Korrekturauftrag an die Werkzeugkette. Deren
    neue Datei und Bericht schließen die Punkte.
- **Benachrichtigungen** (umgesetzt): neue Prüfpunkte an die zuständige
  Rolle; Korrektur eingetroffen.

### 0.7 Teilen, Mappen und Druckstation

Auch Funktionen, über deren Nutzung der Verein noch entscheiden muss, werden
gebaut: als Module, die anfangs aus sind. Was etwas nach außen öffnet, steht in
keiner Voreinstellung. Ob eine Funktion genutzt wird, entscheidet der Verein
mit dem Schalter.

*Umgesetzt mit Version 0.9.0: das Teilen über die Archivrollen hinaus (ganze
Stücke und Ausschnitte, auf Wunsch mit Scan), als Module
`member_links`, `member_practice_audio`, `public_links` und
`public_repertoire` in der Gruppe „Teilen über die Archivrollen hinaus“, alle
in keiner Voreinstellung (Beschreibung in `docs/api.md`, Abschnitt „Sharing
beyond the archive roles“, im Benutzerhandbuch, Abschnitt „Sharing with choir
members and the public“, im Admin-Guide, Abschnitt „Sharing with choir members
and the public“, und in `SECURITY.md`), dazu Mappenbuch und Spiegelordner
für die Druckstation (siehe unten).*

- **Links für Chormitglieder** mit Abspielen – *umgesetzt:*
  - eine eigene Rolle „Chormitglieder“ (Gruppen wie bei den anderen Rollen),
    die nur die für sie freigegebenen Ausschnitte sieht, nicht das
    Werkverzeichnis
  - die gewählte Stimme wird betont (solo oder lauter, die übrigen leiser)
  - nur für Fassungen, die für die digitale Bereitstellung freigegeben sind;
    der Server schneidet den Taktbereich zu
- **Öffentliche Links** für Fassungen mit Veröffentlichungsfreigabe: Ausschnitt
  ansehen und abspielen ohne Anmeldung, mit Ablaufdatum, Abrufzähler und
  Begrenzung der Aufrufe; keine Downloads, keine Kontonamen. – *umgesetzt;
  dazu braucht es die bestätigte Gemeinfreiheit oder eine Lizenz mit der neuen
  Nutzungsart „Online-Veröffentlichung“.*
- **Öffentliche Repertoireseite** mit gemeinfreien Werken und Links zu CPDL
  und IMSLP (nur Links, nichts wird von dort geladen). – *umgesetzt.*
- **Übe-Audio je Stimme für Chormitglieder** bei freigegebenen Fassungen.
  – *umgesetzt.*
- **Mappenbuch:** Ausgabe und Rücknahme von Chormappen, mit Löschfristen für
  die Personenangaben. – *Umgesetzt als Modul `folder_book` (in
  „Vollständig“): Mappen mit Inhalt, Zustand und fehlenden Blättern, Ausgabe
  an ein Nextcloud-Konto oder einen Namen, Rücknahme, Hinweis auf nicht mehr
  geltende Fassungen, Druckliste; Namen, Konten und Notizen werden nach der
  Löschfrist (Standard 12 Monate nach der Rücknahme) auch im Protokoll
  anonymisiert. Beschreibung im Benutzerhandbuch, Abschnitt „Folder book“,
  und im Admin-Guide, Abschnitt 15.*
- **Spiegelordner für die Druckstation:** freigegebene PDFs in einem eigenen
  Zielordner, nie im Archivordner (Ausnahme zu Leitlinie 4, nur mit Modul).
  – *Umgesetzt als Modul `print_mirror` (in keiner Voreinstellung):
  Zielordner mit Prüfung, Vorschau, täglicher Abgleich nach dem ersten
  Abgleich von Hand, Manifest mit Prüfsummen, Bericht und Protokoll.
  Beschreibung im Admin-Guide, Abschnitt 16, und in `docs/api.md`.*

### 0.8 Archiv und Abspielen

Aus der Parallelentwicklung (ein Fork des Orchestra Scores Manager, der
dieselben Anforderungen auf einem anderen Weg umgesetzt hat):

- **Stände einer Fassung:**
  - Jede Korrektur ergibt einen neuen, unveränderlichen Stand, mit
    Änderungsnotiz (Takte, Stimmen, Grund) und Prüfsumme je Datei.
  - Innerhalb der geltenden Fassung legt die Archivverwaltung den geltenden
    Stand fest.
  - Eine Fassungszeile (Signatur · Fassung · Stand · gültig seit) steht auf
    jeder Ausgabe.
  - Zwei Stände lassen sich takt- und stimmweise vergleichen. Daraus entsteht
    eine kurze Notiz für Chormitglieder.
  - Aufführungen merken sich den gesungenen Stand.
  - *Umgesetzt als Modul `version_states` (Standard und Vollständig): Ein
    Stand ist ein Datensatz mit Verweis auf die MusicXML-Datei, Prüfsumme,
    Notiz, wer und wann; die App kopiert keine Datei. Korrekturen in der App
    und der Dateiabgleich erfassen neue Stände, der erste Dateiabgleich nach
    dem Einschalten den ersten. Den geltenden Stand legt die
    Archivverwaltung fest; der Vergleich läuft im Browser auf den beiden
    Dateien. Beschreibung in `docs/api.md`, Abschnitt „Revisions of a
    version“, und im Benutzerhandbuch.*

*Umgesetzt mit Version 0.9.0: der Teil „Archiv“ (Schutzfrist-Sonderfälle, Nutzungsart,
Nextcloud-Suche, Lückenbericht, Jahresstand, Lizenzvermerk, Vorlagen für
Infomaterial; Beschreibung in `docs/api.md`, im Benutzerhandbuch und im
Admin-Guide), das Abspielen (Einzähler, Schleife, Startpunkt, Abspielfolge)
und die Stände einer Fassung sowie weitere Sprachen (Französisch, Spanisch,
Italienisch, Niederländisch, Polnisch; KI-erstellt, muttersprachliche
Prüfung offen).*

- **Schutzfrist-Sonderfälle** als Schalter: wissenschaftliche Ausgaben
  (§ 70 UrhG) und nachgelassene Werke (§ 71 UrhG). – *umgesetzt im Modul
  „Schutzfristen“, mit dem Jahr des ersten Erscheinens; Herausgabe zählt
  dann mit 25 Jahren statt 70 Jahren nach dem Tod.*
- **Lizenz-Nutzungsart „Weitergabe an Nachfolger“.** – *umgesetzt als
  „Weitergabe an die Rechtsnachfolge“, mit Erklärung.*
- **Nextcloud-Suche** nach Signatur, Titel und Person. – *umgesetzt, Modul
  `unified_search` (Standard und Vollständig).*
- **Abspielen:**
  - Einzähler, A–B-Schleife, Klick auf eine Note als Startpunkt
  - Abgleich der Abspielfolge mit den dortigen Testfällen: dreifache Volte,
    `times="3"`, D.C. al Fine, D.S. al Coda, Sprungzeichen nur in einer Stimme

  – *umgesetzt in der Notenansicht und in der Ansicht geteilter Ausschnitte:
  Einzähler (1 oder 2 Takte, je Person gemerkt, auch für Übe-Dateien),
  Schalter „Wiederholen“ für die Takte aus „Von Takt“/„Bis Takt“, Klick auf
  Note oder Takt, Pfeiltasten als Tastatur-Weg. Die Testfälle sind eigene
  MusicXML-Tests; behoben: Rücksprung zur letzten Vorwärts-Wiederholung,
  `times="3"` mit vierter Volte, Sprungzeichen aus allen Stimmen, gedruckte
  Segno- und Coda-Zeichen.*
- **Lückenbericht der Signaturen** je Mappe, nur zur Information; Nummern
  werden weiter nie wiederverwendet. – *umgesetzt im Modul „Inventur“.*
- **Jahresstand einfrieren** (CSV, JSON, Prüfsummen-Manifest) und mit dem
  Vorjahr vergleichen. – *umgesetzt, Modul `annual_snapshot` (Vollständig),
  auf Knopfdruck und zum Jahreswechsel.*
- **Lizenzvermerk aus der MusicXML** anzeigen (`<identification><rights>`).
  – *umgesetzt: beim Dateiabgleich gelesen, als ungeprüft angezeigt,
  höchstens ein Vorschlag im Eingang.*
- **Vorlagen für Infomaterial** (Flyer, Banner) mit eigenem Absender je Chor.
  – *umgesetzt als Werkzeug `tools/outreach` (Flyer DIN lang, Visitenkarte,
  zwei Banner), nicht Teil der App.*
- **Weitere Sprachen** (z. B. Französisch, Spanisch, Italienisch,
  Niederländisch, Polnisch), zuletzt, wenn die Texte stehen. – *umgesetzt:
  je Sprache `tools/l10n/translations.<lang>.mjs` mit Glossar
  (`tools/l10n/glossary.md`); fehlende Texte fallen auf Englisch zurück.*

### 0.9 Benutzerführung

*Umgesetzt mit Version 0.9.0: Hilfe am Ort an den Fachbegriffen,
Meldungen und leere Ansichten mit nächstem Schritt, Folgen vor dem Bestätigen
der Rechte, Sammelliste „Geltende Fassungen festlegen“, gefaltete Filter,
Doku nach Aufgaben je Rolle mit „Häufige Probleme“, Durchgang ohne Vorwissen
im Testaufbau und Abnahme im Browser auf Nextcloud 33; Handbücher auf Deutsch
und Englisch. Die geführte Einrichtung kam schon mit den Einstellungen.*

- **Geführte Einrichtung** unter Administration → Notenarchiv: Archivordner,
  Rollen und Gruppen, Zugriffsprüfung, Import, Dateiabgleich, mit Status je
  Schritt.
- **Hilfe am Ort:** kurze Erklärungen an Feldern und Ansichten, Verweise auf
  die Doku aus Meldungen und leeren Ansichten.
- **Durchgang ohne Vorwissen:** die wichtigsten Aufgaben je Rolle, nur mit
  Oberfläche und Doku gelöst; was dabei stolpert, wird behoben.
- **Doku nach Aufgaben** („Wie mache ich …“) je Rolle, Schnellstart, häufige
  Probleme.

### 0.10 Verlässlich bauen und sichern

*Im Review (PR #10), noch nicht in `main`. Umgesetzt (Review Oktober
2026):* CI grün, Sicherungen außerhalb des
Archivordners und ohne Link-Token, Löschfristen, Sicherung als Datenstrom mit
Meldung bei alter Sicherung, Läufe gegeneinander gesperrt, Grenzen der
delegierten Einstellungsseite, `SECURITY.md` als Tabelle „Zusage → Test“.

### 0.11 Rechte und Datenschutz schärfen

*Im Review (PR #10), noch nicht in `main`. Umgesetzt:* eine Regel für Erhaltungskopien auf allen Wegen, Link-Token
gehasht und verschlüsselt, Ausschnitte ohne Kommentare und Zusatzfelder,
neue Nutzungsarten für Lizenzen, Rechtekonflikte, Datenschutz-Paket,
rechtlicher Hintergrund DE/EN.

### 0.12 Klar bedienen und an das Chorsystem übergeben

*Im Review (PR #10), noch nicht in `main`. Umgesetzt:* Begriffe bereinigt, Fehlermeldungen mit nächstem Schritt, Fokus
und Handy-Ansichten, Ausdrucke mit Kopfzeile, Modul `choir_handover`
(Übergabeordner) und Vergleich mit dem Export des Chorsystems, Leitfaden
„Notenarchiv neben einem Chorsystem“, Erklärung zur Barrierefreiheit.

### 0.13 Üben in der Notenansicht

*Im Review (PR #11), noch nicht in `main`. Alle Punkte umgesetzt.*

Aus dem Vergleich mit der Chor-App cori (Oktober 2026): was Sänger beim Üben
eines geteilten Stücks erwarten und der Notenansicht noch fehlt. Dafür gilt:

- Alles läuft im Browser. Über das Üben einzelner Personen wird nichts auf
  dem Server gespeichert oder ausgewertet.
- Keine fremden Server (Leitlinie 9); das Mikrofon nur nach einem Klick, und
  Aufnahmen verlassen den Browser nicht.
- Jedes Werkzeug ist per Tastatur und mit Bildschirmleser bedienbar und
  sagt sein Ergebnis an (Leitlinie 11).
- Die Werkzeuge gehören zum Abspielen: Wo nicht abgespielt werden darf, gibt
  es sie nicht.

Punkte:

- **Anfangstöne:** der erste Ton jeder Stimme ab der Startstelle,
  nacheinander von unten und dann zusammen; mit „Nur diese Stimme“ nur die
  eigene. – *umgesetzt, mit Ansage der Tonnamen (deutsch mit H).*
- **Metronom beim Abspielen**, in den Zählzeiten des Einzählers.
  – *umgesetzt.*
- **Tonhöhe ändern:** bis zu sechs Halbtöne höher oder tiefer, die Noten
  bleiben, wie sie notiert sind; Übe-Dateien übernehmen die Tonhöhe (MIDI mit
  passender Tonart). – *umgesetzt.*
- **Mischpult:** Lautstärke je Stimme, 0 % stumm, zusätzlich zu „Stimmen
  beim Abspielen“; die Übe-Datei eines geteilten Stücks klingt wie das
  Mischpult. – *umgesetzt.*
- **Übe-Stellen merken:** Taktbereiche mit eigenem Namen je Stück, im
  Browser gemerkt, mit einem Klick als Schleife. – *umgesetzt.*
- **Selbst aufnehmen:** zur Wiedergabe singen, die Aufnahme anhören und
  zusammen mit der Begleitung abspielen; die Aufnahme bleibt im Browser und
  kann nur heruntergeladen werden. – *umgesetzt; die Seiten erlauben dafür
  das Mikrofon nur für sich selbst (`microphone 'self'`) und Medien aus dem
  Browser-Speicher (`media-src blob:`).*
- **Blättern mit Fußpedal:** Bild auf und Bild ab (wie sie Bluetooth-Pedale
  senden) gehen zum vorigen oder nächsten System. – *umgesetzt, auch nach
  einem Klick auf Abspielen; Eingabefelder und Regler behalten die Tasten.*

### 0.14 Text und Tonhöhe beim Mitsingen

*Im Review (PR #11), noch nicht in `main`. Alle Punkte umgesetzt.*

- **Mitlaufender Text:** die Textzeile der gewählten Stimme groß unter den
  Noten, die gesungene Silbe hervorgehoben; Strophe wählbar. – *umgesetzt,
  über den Noten in der Leiste, die oben stehen bleibt, mit der nächsten
  Zeile darunter; die Strophe folgt dem Durchgang der Wiederholung.*
- **Text-Lernmodus:** Buchstaben des Texts stufenweise ausblenden, bis er
  sitzt. – *umgesetzt, in drei Stufen bis zu den Anfangsbuchstaben.*
- **Mitsingen mit Tonhöhen-Anzeige:** Mikrofon an, die gesungene Tonhöhe als
  Linie neben den Soll-Tönen der eigenen Stimme, Erkennung im Browser. Kein
  Punktestand, keine Auswertung auf dem Server. – *umgesetzt: Töne der
  Fokusstimme als Kästchen, der Gesang als Linie in der Oktave der Stimme,
  dazu in Worten (sauber bis 30 Cent, etwas oder zu hoch/tief); Erkennung
  mit YIN im Browser, das Mikrofon nur, solange der Schalter an ist.*

### 0.15 Ohne Netz üben und vortragen

*Im Review (PR #12), noch nicht in `main`. Umgesetzt bis auf die
öffentlichen Links und die Abnahme auf echten Geräten.* Aus der Planung
„Offline-Betrieb ohne eigene Apps“ (Oktober 2026). Ziel: Stücke, die online
bewusst „auf diesem Gerät gespeichert“ wurden, lassen sich ohne Netz
ansehen, abspielen und üben, mit allen Werkzeugen aus 0.13 und 0.14. Keine
native App, kein neuer Server: ein Service Worker innerhalb der
Nextcloud-App.

- **Vorab, nur Doku:** Den Übergabeordner (`choir_handover`, 0.12)
  schreibgeschützt mit den Chormitgliedern teilen und in der Nextcloud-App
  „Offline verfügbar“ setzen. Liefert Scans und Dateien, aber keine
  Übe-Werkzeuge. – *umgesetzt, im Administrationshandbuch unter „Übergabe
  an ein Chorsystem“.*
- **Lesen offline** für „Mit mir geteilt“: – *umgesetzt als Modul
  `offline` (aus, in keiner Voreinstellung).*
  - Service Worker über eine eigene Route (`GET /offline-worker`, Scope der
    App, CSP mit `worker-src 'self'` nur auf dieser Seite), als eigenes
    Skript gebaut; ist das Modul aus, entfernt er sich und alles Gespeicherte
  - Liste der Freigaben als JSON (`GET /api/member/links`)
  - je Stück „Auf diesem Gerät speichern“: MusicXML, auf Wunsch der Scan;
    dazu die Seite und ihre Skripte samt Notensatz
  - Abgleich bei jedem Start mit Netz; Ablauf und Widerruf werden
    durchgesetzt, gesperrt nach 30 Tagen ohne Abgleich, nie länger als der
    Link gilt (auch der Service Worker antwortet dann nicht vom Gerät)
  - Hinweis ohne Netz mit Stand, Bereich „Auf diesem Gerät“ mit
    Speicheranzeige und „Alles auf diesem Gerät löschen“; ein anderes Konto
    im selben Browser beginnt leer
  - offline nicht verfügbare Funktionen sichtbar abgeschaltet, mit Grund
    („Übe-Datei“ braucht die Freigabe des Servers)
  - *offen:* öffentliche Links (kein Konto zum Trennen, kein Recht
    „Speichern“ für die Öffentlichkeit); der Scan offline in der Seite statt
    im neuen Tab; Abnahme auf iPad, Android und Firefox in einem echten
    Nextcloud
- **Übe-Daten offline:** Übe-Einstellungen je Stück dauerhaft und
  versioniert, mit einem stabilen Schlüssel statt Titel und Komposition. –
  *umgesetzt: Tempo, Tonhöhe, Metronom und Mischpult je Fassung
  (`practiceKey`, ein Hash ohne Katalog-IDs), Übe-Stellen ziehen einmal vom
  alten Schlüssel um; alles im Browser gebündelt (`src/storage.ts`).*
  Aufnahmen bleiben wie bisher nur im Speicher.

Dafür gilt: Geschrieben wird offline nur im Browser (Übe-Stellen,
Einstellungen), nie auf den Server (Leitlinie 3). Gespeichert wird nur auf
ausdrücklichen Wunsch, getrennt je Konto. Gespeichert werden darf ein Stück
nur, wenn Chormitglieder die Noten speichern und drucken dürfen (wie beim
Scan); eine Freigabe nur zum Ansehen und Abspielen reicht nicht.

Nach 1.0: die Konzertmappe der Chorleitung (Programm eines Konzerts offline
in der Archiv-App) und Schreiben ohne Netz.

### 1.0 Stabil

*Geplant.*

- Abnahme auf der niedrigsten unterstützten Nextcloud-Version
- Übersetzungen
- Dokumentation für Archivverwaltung und Admins
- Mindestens zwei Maintainer

## Bewusst nicht vorgesehen

- **Funktionen eines Chorsystems:** Termine, Anwesenheit, Nachrichten an
  Sänger. Geübt wird nur in der Notenansicht (0.13, 0.14); Lernpfade,
  Punkte, Übe-Serien, Ranglisten und Übe-Statistiken über einzelne Sänger
  gibt es nicht.
- **Eine eigene (native) App fürs Üben ohne Netz.** Ohne Netz üben im
  Browser ist als Meilenstein 0.15 geplant.
- **Notizen und Zeichnungen im Notenbild.** Hinweise der Chorleitung gibt es
  als Text je Stelle (Übergabe, 0.12).
- **Ausgabe und Rücknahme am Probenabend** als eigener Ablauf (Anwesenheit,
  Erfassen an der Tür). Das Mappenbuch hält nur fest, wer welche Mappe hat.
- **Notensatz** über kleine Korrekturen hinaus
- **Hochladen zu externen Notenbibliotheken**
- **Automatische Urheberrechts- oder Lizenzvermerke**
