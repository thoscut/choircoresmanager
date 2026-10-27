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

## Review Oktober 2026

Nach Version 0.9.1 wurde die App aus sechs Blickwinkeln geprüft: Sicherheit,
Nextcloud-Architektur, Benutzerführung und Barrierefreiheit, Urheberrecht und
Datenschutz, Tests und CI sowie ein Vergleich mit anderen Chor-Apps. Die
Ergebnisse stehen in den Meilensteinen 0.10 bis 1.0 und unter „Bewusst nicht
vorgesehen“.

- **Gesamtbild:** Für eine App von Ehrenamtlichen sorgfältig gebaut. Die
  öffentlichen Routen sind schmal und gut geschützt, die App nutzt nur
  öffentliche Nextcloud-Schnittstellen, die Schutzfristberechnung ist richtig
  und stützt sich auf keine Schranke, die es für Vereine nicht gibt. Die
  Barrierefreiheit liegt über dem Durchschnitt.
- **Dringend:** Die CI auf `main` ist seit dem ersten Push rot, die
  Integrationstests sind dort nie gelaufen. 0.9.0 und 0.9.1 sind trotzdem
  entstanden, obwohl `docs/release.md` ein grünes CI verlangt (Meilenstein
  0.10).
- **Lücken zwischen Zusage und Code:** Sicherungen und Jahresstände halten
  Personenangaben über die Löschfrist hinaus, die Doku sagt an einer Stelle,
  ohne Mappenbuch speichere die App keine Mitgliederdaten, und einige Aussagen
  in `SECURITY.md` decken sich nicht ganz mit dem Code. Einzelheiten zu
  Sicherheitsbefunden werden nach `SECURITY.md` nicht öffentlich geführt.
- **Wartbarkeit:** Eine Person hält Code, Signierschlüssel und Release. Für
  1.0 („mindestens zwei Maintainer“) fehlen Entwickler-Doku, ein Release-Ablauf
  in der CI und eine Regel für den Schlüssel.

### Marktumfeld

Verglichen wurden das **Chorsystem Singste** (dawesys GmbH) und **cori**
(cori Tech GmbH), Stand 27.10.2026, nach den öffentlichen Webseiten der
Anbieter.

- **Singste** ist das Chorsystem, in dem die Sänger üben, und schon jetzt
  das Gegenstück des Notenarchivs (Spalte „Auf Singste seit“ im Import, Soll/
  Ist-Abgleich). Stark bei Terminen, Zu- und Absagen, Nachrichten, Umfragen
  und GEMA-Listen; Noten nur als Repertoire, MP3-Player und Dateiablage.
- **cori** ist stark beim Üben: Stimmen-Mixer, Stimmen aus MusicXML und
  MuseScore, Stimmtraining, Zeichnen im Notenbild, Offline-App, Chat und
  Anwesenheit. Bezahlt je Mitglied und Monat, Cloud-Dienst.
- **Was nur das Notenarchiv hat:** Rechte je Fassung mit Bestätigung,
  Lizenzen je Nutzungsart, echte Bestandsführung (Signaturen, Inventur,
  Prüfsummen, Protokoll), selbst gehostet ohne fremde Server, offene
  Schnittstelle und Lizenz (AGPL), Barrierefreiheit als Ziel. Zu
  Rechteverwaltung, offener Schnittstelle und Barrierefreiheit fanden sich bei
  beiden Anbietern keine Angaben.
- **Folgerung:** Kein Nachbau von Chorsystem-Funktionen (Leitlinie 12 und
  „Bewusst nicht vorgesehen“), dafür eine gute Übergabe an das Chorsystem
  (Meilenstein 0.12). Die größte Lücke ist heute die Handarbeit dazwischen:
  Übe-Dateien werden von Hand hochgeladen, „Auf Singste seit“ wird von Hand
  gepflegt.

## Meilensteine

Jeder Meilenstein ergibt eine Minor-Version (0.6 → 0.6.0, 0.7 → 0.7.0);
interne Zwischenstände heißen `0.7.0-dev.1` usw. (siehe `docs/release.md`).

### 0.1 Fundament

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

*Aus dem Review Oktober 2026. Vor jeder neuen Funktion.*

- **CI grün und verbindlich:**
  - Die bekannten Ausfälle beheben: Speichergrenze der Unit-Tests, fehlende
    PHP-Erweiterungen im Psalm-Job (und `ext-zip`/`ext-intl` in
    `composer.json`), die mehrzeilige Ausgabe des Prüfsummen-Schritts, das
    Testmuster des MCP-Servers, die feste Reihenfolge in `tools/l10n/extract.mjs`.
  - Pflicht-Checks für `main` (Branch-Schutz); ein Release entsteht nur aus
    einem Tag mit grünem CI, wie `docs/release.md` es verlangt.
  - Die Prüfungen von `docs.test.ts` laufen auch bei reinen Doku-Änderungen.
- **Sicherungen außerhalb des Archivordners:** Wochensicherung, ODS mit
  Protokoll und Jahresstände liegen in einem eigenen Ordner, den nur die
  Archivverwaltung sieht. Sie enthalten keine Link-Token im Klartext.
- **Löschfristen gelten überall:**
  - Alte Wochensicherungen werden rotiert.
  - Jahresstände enthalten keine Namen aus dem Mappenbuch und keine Konten.
  - Die Admin-Doku nennt Dateiversionen und Papierkorb der Nextcloud.
- **Sicherung als Datenstrom:** Die Sicherung entsteht nicht mehr ganz im
  Speicher. Die App merkt sich die letzte gelungene Sicherung und meldet,
  wenn sie älter als acht Tage ist.
- **Läufe gegeneinander sperren:** Dateiabgleich, Spiegelordner und Jahresstand
  laufen nie doppelt (Web, `occ` und Hintergrundjob). Lange Läufe gehen als
  Hintergrundjob mit Statusanzeige; der Jahresstand nutzt die gespeicherten
  Prüfsummen. Der Dateiabgleich schreibt nur geänderte Zeilen.
- **Einstellungen und Rollen:** Wer die Einstellungsseite delegiert bekommt,
  kann sich keine Rolle geben und keine Module einschalten, die nach außen
  öffnen. Wechsel von Rollen, Gruppen und Archivordner stehen im Protokoll.
- **`SECURITY.md` als Tabelle „Zusage → Test“**, laufend gepflegt; veraltete
  und widersprüchliche Stellen werden bereinigt.

### 0.11 Rechte und Datenschutz schärfen

*Aus dem Review Oktober 2026.*

- **Erhaltungskopien an einer Stelle prüfen:** Ein Dienst entscheidet für alle
  Wege (interne API, API v1, Links, Scans, Druckstation), ob eine Datei eine
  Erhaltungskopie ist. Ein Test vergleicht die interne API mit v1.
- **Link-Token** werden gehasht gespeichert und nur dem Ersteller und der
  Archivverwaltung gezeigt, ohne Konto-ID. Die Druckstation liest nur aus dem
  Archivordner. Öffentliche Ausschnitte enthalten nur, was zum Anzeigen und
  Abspielen nötig ist (keine Kommentare, Encoder- oder Zusatzfelder).
- **Neue Nutzungsarten** für Lizenzen: Digitalisierung, Herunterladen und
  Ausdrucken durch Mitglieder, Audio online. Der ganze Scan in Mitglieder-Links
  braucht die passende Nutzungsart.
- **Notenkopien prüfen:** Fotokopierte Exemplare und Mappenanzahl werden mit
  der Lizenz verglichen (Notenkopierverbot, § 53 Abs. 4 a UrhG); ohne Lizenz
  gibt es eine Warnung.
- **Rechtekonflikte melden:** Kommt zu einer als gemeinfrei bestätigten
  Fassung ein Beteiligter hinzu, der noch geschützt sein kann, gibt es eine
  Meldung an die Archivverwaltung. Ein Gemeinfrei-Vorschlag braucht Komponist
  oder Textdichter oder den Vermerk „traditionell“.
- **Urhebernennung auf geteilten Seiten:** Komponist, Text, Bearbeitung und
  Übersetzung sowie ein bestätigter Lizenzvermerk stehen dabei (§ 13 UrhG).
- **Datenschutz-Paket:**
  - Auskunft und Löschung je Person per `occ`.
  - Wird ein Nextcloud-Konto gelöscht, werden seine Angaben anonymisiert;
    Mappen, die nie zurückkommen, haben eine eigene Frist.
  - Übe-Dateien von Mitgliedern werden ohne Konto oder nur befristet
    protokolliert; die Doku sagt, was gespeichert wird.
  - Lebende Personen nur mit Geburtsjahr.
  - Muster für das Verzeichnis der Verarbeitungstätigkeiten und die
    Information nach Art. 13 DSGVO.
- **Doku „Rechtlicher Hintergrund“:** Schutzfristen, Erhaltungskopien
  (§ 53 Abs. 2 UrhG passt für ein Vereinsarchiv selten), Notenerkennung,
  öffentliches Abspielen (§ 19a UrhG), Impressum und Datenschutzerklärung vor
  dem Einschalten öffentlicher Seiten, IP-Adressen in Server-Logs, Agenten und
  KI-Anbieter (Rolle, Auftragsverarbeitung, Drittland). Keine Rechtsberatung
  (Leitlinie 10).
- **Liederfolge nach dem GEMA-Musikfolgebogen.**

### 0.12 Klar bedienen und an das Chorsystem übergeben

*Aus dem Review Oktober 2026 und dem Vergleich mit Singste und cori.*

- **Begriffe bereinigen:** ein Wort, eine Bedeutung („Freigabe“, „Stand“,
  „Ausgabe“, „geltende Fassung“), Hilfe am Ort auch für „Stand“, eine Prüfung
  in `tools/l10n`.
  - Im Korrekturformular heißt der Ton **H**, nicht B (deutsche Notennamen).
  - „Wiederholen“ (Schleife) und „Wiederholungen spielen“ bekommen Namen, die
    sich nicht verwechseln lassen.
- **Fehlermeldungen bleiben stehen**, bis man sie schließt, und sagen, was zu
  tun ist, auch bei Fehlern des Servers (statt „HTTP 500“). Die Inventur nennt
  die fehlerhaften Zeilen.
- **Fokus:** Beim Öffnen der Seitenleiste springt der Fokus hinein, beim
  Schließen zurück zur Zeile.
- **Am Handy:** Die Sängerseite zeigt die Liste vor den Noten und merkt sich
  die Notengröße; die Notenansicht der Archivrollen zeigt weniger vor den
  Noten.
- **Ausdrucke** mit Kopfzeile: Chor, Ansicht, Filter, Datum, Seitenzahl.
- **Übergabe an das Chorsystem** (Modul, anfangs aus, nach dem Muster des
  Spiegelordners): ein Übergabeordner mit Übe-Dateien je Stimme, freigegebenen
  PDFs und MusicXML, jede Datei mit Fassungszeile und bestätigtem
  Lizenzvermerk, dazu eine Liste. Die App lädt nichts zu einem Anbieter hoch.
- **Ist-Stand aus dem Chorsystem einlesen:** Ein Export des Chorsystems
  (CSV) wird mit dem Soll verglichen; Abweichungen landen als Vorschläge im
  Eingang (Leitlinie 6), statt „Auf Singste seit“ von Hand zu pflegen.
- **Hinweise der Chorleitung je Stelle** (Takt, Stimme) als Text für das
  Chorsystem ausgeben, statt im Notenbild zu zeichnen.
- **Admin-Guide „Notenarchiv neben einem Chorsystem“:** welche Daten wo
  liegen, was nie ins Chorsystem geht, Konzerte als ICS-Datei. Bei dawesys
  klären, ob sich die Ablage von Singste als Nextcloud-Freigabe einbinden
  lässt.
- **Erklärung zur Barrierefreiheit** in `docs/`.

### 1.0 Stabil

- Abnahme auf der niedrigsten unterstützten Nextcloud-Version
- Übersetzungen außer Deutsch und Englisch von Muttersprachlern
  geprüft
- Dokumentation für Archivverwaltung und Admins
- Mindestens zwei Maintainer:
  - `docs/architecture.md` für Entwickler, CODEOWNERS, Maintainer-Handbuch
  - Release-Workflow in der CI mit geschützter Umgebung für Signierung und
    App-Store-Upload; ein Notfallplan für Signierschlüssel und App-Store-Konto
  - Renovate oder Dependabot für Lockfiles, Actions und Images
- **Testtiefe:**
  - Upgrade-Test von der letzten veröffentlichten Version auf den aktuellen
    Stand, mit Altdaten
  - Nächtliche Matrix: Nextcloud 33, 34 und 35, PHP 8.2 bis 8.5, MySQL und
    MariaDB; Psalm auch gegen das OCP der neuesten Version
  - Test der Rolle je Route (Route × Rolle) und ein HTTP-Test durch die
    Middleware
  - Browser-Tests mit axe, Tastatur und Handybreite auf dem vorhandenen
    Screenshot-Aufbau; Durchgang mit NVDA, VoiceOver und TalkBack und mit
    älteren Sängern und Notenwarten
  - Lasttest mit einem großen erfundenen Bestand; die getestete Größe steht
    im Admin-Guide
- **Wartbarkeit:** Ein gemeinsamer Abspielteil für Notenansicht und geteilte
  Ausschnitte statt zweier Kopien; Modulregister ohne den Vorrat `planned`;
  ESLint 9.
- **Entscheidung zu PostgreSQL:** unterstützen und testen oder den toten Code
  entfernen.

### Später (nach 1.0)

- Notenansicht für PDF-Scans mit Seiten statt Takten, wenn sie ohne viel
  Komplexität geht (Leitlinien 2 und 14).
- Vorbereitung auf Nextcloud 36.

## Bewusst nicht vorgesehen

- **Funktionen eines Chorsystems:** Üben, Termine, Anwesenheit, Nachrichten an
  Sänger
- **Ausgabe und Rücknahme am Probenabend** als eigener Ablauf (Anwesenheit,
  Erfassen an der Tür). Das Mappenbuch hält nur fest, wer welche Mappe hat.
- **Notensatz** über kleine Korrekturen hinaus
- **Hochladen zu externen Notenbibliotheken**
- **Automatische Urheberrechts- oder Lizenzvermerke**
- **Eine eigene App, Offline-Modus, Chat, Stimmtraining und
  Zeichnen im Notenbild.** Das leisten Chorsysteme wie Singste oder cori; das
  Notenarchiv übergibt ihnen, was freigegeben ist (Meilenstein 0.12).
