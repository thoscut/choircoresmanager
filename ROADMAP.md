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
   keine Datei um. Einzige Ausnahme: Eine kleine Korrektur einer MusicXML
   (Tonhöhe, Vorzeichen, Silbe) speichert die App als neue Datei neben der
   alten. Die alte bleibt unverändert, die Änderung steht im Protokoll.
5. **Rollen statt Personen.**
   - Rollen werden Nextcloud-Gruppen zugeordnet.
   - Jede Rolle schreibt nur ihre Felder.
   - Wer keine Rolle hat, sieht die App nicht.
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

*Teilweise umgesetzt* (App-Version folgt): Übertragung prüfen, Prüfen in
der Notenansicht, Korrigieren und die Benachrichtigungen dazu, als Module
`transcription_review` und `corrections` (Beschreibung in `docs/api.md`,
Abschnitt „Reviewing transcriptions and corrections“, und im
Benutzerhandbuch, Abschnitt „Reviewing transcriptions“). Die Schnittstelle
für Agenten steht noch aus; sie bindet die Prüfpunkte und Korrekturaufträge
an.

- **Schnittstelle für Agenten** (offen):
  - eine feste, versionierte API (OCS, `api/v1`) mit OpenAPI-Beschreibung
    für Lesen, Fällig-Liste, Vorschläge, Rückfragen und Prüfpunkte
  - ein MCP-Server, der lokal neben dem Agenten läuft, mit App-Passwort
    eines eigenen Kontos
  - Schreibende Werkzeuge erzeugen nur Vorschläge, Rückfragen und
    Prüfergebnisse. Der Server begrenzt sie über die Rolle.
  - Ein Agentenlauf gegen den Demo-Bestand läuft in der CI mit.
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

### 1.0 Stabil

- Abnahme auf der niedrigsten unterstützten Nextcloud-Version
- Übersetzungen
- Dokumentation für Archivverwaltung und Admins
- Mindestens zwei Maintainer

### Später

- **Ausschnitt-Links für Sänger mit Abspielen.** Die gewählte Stimme wird
  betont (solo oder lauter, die übrigen leiser). Nur für Fassungen, die für
  die digitale Bereitstellung freigegeben sind; der Server schneidet den
  Taktbereich zu.
- **Öffentliche Links** für Fassungen mit Veröffentlichungsfreigabe
- **Ausgabe von Sängermappen** (Mappenbuch). Nur mit Löschfristen.
- **Übe-Audio je Stimme** für freigegebene Fassungen
- **Kalender und Auswertungen**

### Aus der Parallelentwicklung übernommen

Ein Fork des Orchestra Scores Manager hat dieselben Anforderungen auf einem
anderen Weg umgesetzt. Was dort entstanden ist und hier noch fehlt:

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
- **Schutzfrist-Sonderfälle** als Schalter: wissenschaftliche Ausgaben
  (§ 70 UrhG) und nachgelassene Werke (§ 71 UrhG).
- **Lizenz-Nutzungsart „Weitergabe an Nachfolger“.**
- **Nextcloud-Suche** nach Signatur, Titel und Person.
- **Abspielen:**
  - Einzähler, A–B-Schleife, Klick auf eine Note als Startpunkt
  - Abgleich der Abspielfolge mit den dortigen Testfällen: dreifache Volte,
    `times="3"`, D.C. al Fine, D.S. al Coda, Sprungzeichen nur in einer Stimme
- **Lückenbericht der Signaturen** je Mappe, nur zur Information; Nummern
  werden weiter nie wiederverwendet.
- **Jahresstand einfrieren** (CSV, JSON, Prüfsummen-Manifest) und mit dem
  Vorjahr vergleichen.
- **Lizenzvermerk aus der MusicXML** anzeigen (`<identification><rights>`).
- **Weitere Sprachen** (z. B. Französisch, Spanisch, Italienisch,
  Niederländisch, Polnisch).
- **Vorlagen für Infomaterial** (Flyer, Banner) mit eigenem Absender je Chor.

Nur nach Vereinsentscheidung: Gruppenfreigaben und öffentliche Links für
Chormitglieder, Mappenbuch (Ausgabe und Rücknahme), ein Spiegelordner für die
Druckstation, eine öffentliche Seite mit Links zu CPDL und IMSLP.

## Bewusst nicht vorgesehen

- **Funktionen eines Chorsystems:** Üben, Termine, Anwesenheit, Nachrichten an
  Sänger
- **Ausgabe und Rücknahme am Probenabend**
- **Notensatz** über kleine Korrekturen hinaus
- **Hochladen zu externen Notenbibliotheken**
- **Automatische Urheberrechts- oder Lizenzvermerke**
