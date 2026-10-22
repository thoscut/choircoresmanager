# Roadmap: Notenverwaltung für Chöre

Stand 22.10.2026 · Fork von [Orchestra Scores Manager](https://github.com/Bibo-Joshi/orchestrascoresmanager) (AGPL-3.0-or-later)

Diese Roadmap fasst zusammen, was ein Chor von der App braucht. Die
Anforderungen stammen aus der Praxis eines Chor-Notenarchivs: Werke in
Mappen, ein Werkverzeichnis als CSV, eine abgeschlossene Rechteprüfung, ein
Aufführungsverzeichnis über Jahrzehnte und viele MusicXML-Übertragungen.
Formuliert ist sie so, dass jeder Chor und auch das Orchester-Upstream etwas
davon hat.

## Leitlinien

1. **Upstream zuerst.** Was allgemein nützt, geht als kleiner PR an
   Upstream, vorher als Issue. Anknüpfungspunkte gibt es schon:
   #125 Setlist-Historie je Stück, #124 Audiobeispiele, #126 mehrere Aliasse
   je Stück, #40 Spalten je Nutzer. Seit dem 22.10.2026 ist der Fork eine
   eigene App mit der ID `choirscores` (Vereinscloud bei Hetzner installiert
   nur aus dem App Store); Upstream-PRs werden deshalb von Hand portiert.
2. **Einstellbar statt vereinsspezifisch.** Statuslisten, Schwellen und
   Schutzfristen sind Einstellungen. Vereinsdaten, Personen und Ordnerpfade
   gehören nicht in den Code und nicht ins Repo.
3. **Ehrlich lizenzieren.** Übertragungen gemeinfreier Werke als CC0 1.0,
   eigene Analysetexte als CC BY-SA 4.0, geschützte Werke ohne CC-Angabe.
   Der Vermerk gehört in die Datei (`<identification><rights>`).
4. **Die App rechnet und erinnert, ein Mensch entscheidet.** Rechtsfragen
   werden angezeigt, nicht automatisch beantwortet. Wer ein Todesjahr nicht
   kennt, behandelt das Werk als geschützt.
5. **Nichts löschen, nur Status setzen.** Eine Signatur wird nie
   wiederverwendet. Archiv ist der Nextcloud-Gruppenordner; die App verweist
   auf dessen Dateien, damit Sicherung und Prüfsummen alles erfassen.
6. **Rechte an Gruppen, nie an Personen.** Für das Werkverzeichnis gibt es
   eine Schreibergruppe; alle anderen machen Vorschläge.
7. **Der gültige Stand ist für jeden Sänger erkennbar** — siehe
   Versionierung. Nach außen übernehmen wir keine Verpflichtung: Was wir
   veröffentlichen, steht „wie besehen" mit Datum, ohne Gewähr und ohne
   Pflicht, ältere Stände bereitzuhalten.

## Versionierung: der gültige Stand

Sänger müssen jederzeit sehen können, **welche Fassung gilt und ob ihr Blatt
dem aktuellen Stand entspricht**, und zwei Stände miteinander vergleichen
können. Dafür trennt die App vier Ebenen:

| Ebene | Was es ist | Beispiel |
|---|---|---|
| **Werk** | die Komposition | *Der Mond ist aufgegangen* |
| **Fassung** | ein bestimmter Satz: Original, Einrichtung, Bearbeitung, Neusatz, Transposition, Auszug | Neusatz 2026 |
| **Stand** | eine Revision dieser Fassung; eine Korrektur ergibt einen neuen Stand | Stand 3 vom 20.10.2026 |
| **Datei** | PDF, MusicXML, Quelldatei *desselben* Standes | `1-1_Der Mond ist aufgegangen - Neusatz 2026.mxl` |

Die Signatur bleibt der Platz im Archiv (das Exemplar); sie ist weder Werk
noch Fassung.

**Regeln**

- **Stände sind unveränderlich.**
  - Jede Änderung, auch ein einzelner korrigierter Ton, erzeugt einen neuen Stand.
  - Zu jedem Stand gehören eine Änderungsnotiz (was, welche Takte und Stimmen, warum, von wem) und eine Prüfsumme je Datei.
  - Alte Stände bleiben abrufbar; gelöscht wird nichts.
- **Genau ein gültiger Stand je Werk**, mit Datum und Person:
  - Die Fassung legt die Chorleitung fest (musikalische Entscheidung).
  - Den Stand innerhalb der Fassung legt der Notenwart fest.
- **Fassungszeile auf jedem Blatt.**
  - Jede Ausgabe aus der App trägt in Kopf- oder Fußzeile Signatur, Fassung, Stand und „gültig seit": Druck, Druckstation, Upload auf die Mitgliederplattform.
  - Beispiel: „1-1 · Neusatz 2026 · Stand 3 vom 20.10.2026 · gültig seit 10/2026".
  - Wer ein altes Blatt in der Mappe hat, sieht es sofort.
- **Vergleichen.**
  - Zwei Stände derselben Fassung werden takt- und stimmweise verglichen: Töne, Rhythmen, Liedtext, Dynamik.
  - Ergebnis ist eine Liste der geänderten Takte und eine Ansicht beider Stände, in der die Takte markiert sind.
  - Liegen nur PDFs vor, werden die Seiten nebeneinander gezeigt.
  - Fassungen untereinander werden strukturell verglichen (Stimmen, Tonart, Taktzahl, auch transponiert). Eine erprobte Vergleichslogik für MusicXML liegt aus der Übertragungswerkzeugkette vor.
- **Für Sänger knapp.** Je neuem gültigem Stand gibt es eine Notiz in Sängersprache, etwa „T. 12 Bass II: d statt c; Strophe 3 neuer Text". Sie geht mit dem Upload auf die Mitgliederplattform.
- **Aufführungen merken sich den Stand.** Ein Setlist-Eintrag verweist auf den gesungenen Stand. Damit bleibt nachvollziehbar, was im Konzert X gesungen wurde, auch wenn später korrigiert wird.
- **Mappen** (Upstream: Notenmappen mit Versionsverlauf) zeigen, welcher Stand in welcher Mappe liegt, und melden veraltete Blätter.

## Phase 0 – offene PRs auf Kurs bringen

| PR | Anpassung |
|---|---|
| #3 | Vier Rechtewerte statt drei: gemeinfrei · lizenziert · geschützt · offen. **Daneben** ein eigener Veröffentlichungsstatus: frei · zu klären · gesperrt (mit Datum „bis") · interne Erhaltungskopie. Die öffentliche Seite zeigt nur, was zur Veröffentlichung freigegeben **und** in den Rechten bestätigt ist |
| #3 | Erhaltungskopien geschützter Werke bleiben intern: kein Download außer für die Notenwart-Gruppe, kein Player, keine Audio- oder MIDI-Ausgabe |
| #3 | MusicXML im Nextcloud-Ordner des Werks ablegen statt in App-Daten, oder mindestens in Export und Sicherung aufnehmen |
| #3 | Der Player spielt Wiederholungen, Klammern, D.C./D.S., Fine und Coda. Die Referenzsemantik ist an 92 Stücken gegen MuseScore geprüft und liegt mit Testfällen vor |
| #2 | Zwei Dinge trennen. **Bestandsstatus** wird von Hand gesetzt: aktiv · ausgemustert · vermisst · verliehen. Die **Repertoireklasse** wird aus Aufführungen abgeleitet: aktuell · ruhend · früher · nicht belegt, mit einstellbaren Schwellen |
| #2 | CSV-Import des Werkverzeichnisses. Kopfzeile und Wertelisten liegen vor |

**Fertig, wenn:** ein vorhandenes Werkverzeichnis verlustfrei importiert und
wieder exportiert werden kann.

## Phase 1 – das Werkverzeichnis ablösen

- **Import und Export** als CSV (Semikolon, UTF-8):
  - Probelauf mit Vorschau der Änderungen.
  - Vor jedem Import ein automatischer Schnappschuss.
  - Signaturen bleiben Text, werden also nie zu einem Datum.
  - Unbekannte Spalten bleiben erhalten.
- **Personen** als eigene Einträge:
  - Rollen: Komponist, Textdichter, Bearbeiter, Übersetzer.
  - Je Person Lebensdaten, GND- oder Wikidata-Link, Quelle und auch der Vermerk „nicht ermittelbar, geprüft am …".
  - Die Werkliste einer Person ist exportierbar, z. B. für eine Vereinbarung mit dem Chorleiter über seine Bearbeitungen.
- **Rechtemodul**:
  - Die maschinelle Ersteinschätzung bleibt unverändert stehen; die Bestätigung kommt mit Datum und Kürzel daneben.
  - Weitere Felder: Verlag und Bestellnummer, Zahl der Originale im Bestand, Erwerbsbeleg.
  - Lizenzen mit einzeln erlaubten Nutzungen: Mappenkopien, Mitgliederplattform, Selbstausdruck, Druckstation, Übe-Audio, Mitschnitt, Weitergabe an Nachfolger.
- **Schutzfrist** berechnen:
  - 31.12. des 70. Jahres nach dem Tod des zuletzt verstorbenen Beteiligten (Komponist, Textdichter *und* Bearbeiter).
  - Sonderfälle als Schalter: anonyme Werke, wissenschaftliche Ausgaben.
  - Dazu eine Wiedervorlage „wird frei am …".
- **Signaturen**: eindeutig, natürlich sortiert (`5-9` vor `5-10` vor `10-1`), mit Lückenbericht und Vorschlag der nächsten freien Nummer.
- **Fassungen und Stände** (siehe Versionierung):
  - Unveränderliche Stände mit Änderungsnotiz und Prüfsumme.
  - Genau ein gültiger Stand je Werk.
  - Die Fassungszeile steht auf jeder Ausgabe.
  - Die Vollständigkeit wird angezeigt (PDF, MusicXML, Quelldatei); Analyse und Prüfstand einer Übertragung sind sichtbar.
- **Nachvollziehbarkeit**: Änderungsprotokoll je Feld. Ein Jahresstand lässt sich einfrieren (CSV, JSON und ein Prüfsummen-Manifest, verglichen mit dem Vorjahr).

**Fertig, wenn:** die CSV nur noch exportiert, nicht mehr gepflegt wird —
und auf jedem ausgegebenen Blatt steht, welcher Stand es ist.

## Phase 2 – Aufführungen und Probenbetrieb

- **Konzertprogramm und Probenliste** unterscheiden: Nur das Programm zählt als Aufführung. Jeder Eintrag verweist auf den gesungenen Stand.
- **Vergleichsansicht** zweier Stände oder Fassungen (siehe Versionierung) und eine Änderungsnotiz für Sänger beim Wechsel des gültigen Standes.
- **Historische Programme** importieren:
  - Titel, die zu mehreren Sätzen passen, landen in einer Klärungsliste; ein Mensch ordnet sie zu.
  - Daraus entsteht die Aufführungshistorie je Werk (= #125).
- **Rechte-Ampel** je datierter Setlist, mit Hinweis acht Wochen vor dem Konzert. Der GEMA-Export bekommt Bearbeiter und Textdichter dazu.
- **Mappenbuch**: Mappen ausgeben und zurücknehmen. Ein Austritt erzeugt eine Aufgabe für die Notenwart-Gruppe. Die Daten bleiben sparsam: keine Adressen.
- **Druckstation**: Die druckbaren PDFs einer Setlist werden in einen Ordner gespiegelt, den ein Raspberry Pi in der Probe synchronisiert.
- **Nextcloud-Integration**: Suche nach Signatur, Titel und Person, Dashboard-Widget mit Wiedervorlagen und offenen Rechtefällen, Benachrichtigungen.

**Fertig, wenn:** die Spalten „Repertoire", „Aufführungen" und „zuletzt aufgeführt" berechnet statt gepflegt werden.

## Phase 3 – üben und teilen

- **Player**:
  - Chorklang mit lizenzsauberem SoundFont, Übemodus (eigene Stimme laut, die anderen leise), A–B-Schleife, Einzähler.
  - Audio-Export nur, wo die Rechte Übe-Audio decken.
- **Barrierearm**: Schrift ab 20 pt, Bedienelemente ab 56 px, hoher Kontrast.
- **Öffentliche Seite** mit einem Paket je freigegebenem Werk:
  - MusicXML, Analyse mit Prüfstand und Quell-Scan, dazu ein maschinenlesbarer Katalog.
  - Links zu CPDL und IMSLP, damit andere Chöre die Arbeit nicht doppelt machen.
  - Gezeigt wird nur der aktuelle Stand mit Datum, „ohne Gewähr". Es gibt keine Zusage, ältere Stände öffentlich bereitzuhalten.
- **Werk über mehrere Signaturen**:
  - Eine Werk-ID verbindet Exemplare und Fassungen, die heute auf verschiedenen Signaturen liegen.
  - Rechte und MusicXML hängen an der Fassung.
  - Größte Modelländerung, deshalb erst nach Abstimmung mit Upstream (knüpft an #126 an). Fassungen und Stände je Signatur kommen schon in Phase 1.

## Außerhalb des Vorhabens

- Kein Ersatz für die Mitgliederplattform, aus der die Sänger singen. Die App
  ist Archiv und Nachweis.
- Keine Notenerkennung in der App. Übertragungen kommen aus einer eigenen
  Werkzeugkette; die App nimmt MusicXML, Analyse und Prüfstand auf.
- Keine automatische Rechtsentscheidung.

## So arbeiten wir

- Je Funktion: Issue → kurze Design-Notiz (Datenmodell, API, Migration,
  Berechtigungen) → PR mit Tests, Psalm, Lintern, neu erzeugtem OpenAPI-Client
  und vollständiger deutscher Übersetzung.
- Mengengerüst im Blick: ~600 Werke, ~3.500 Programmzeilen, ~1.000 Dateien.
- Code, Commits und Upstream-Issues auf Englisch; Beispieldaten synthetisch
  oder gemeinfrei.

## Mitmachen und weitersagen

Vorlagen für Flyer, Visitenkarte und Banner liegen unter
[`docs/outreach/`](outreach/README.md). Jeder Chor kann damit eine eigene
Ausgabe mit Namen, Logo und Kontakt bauen. Die Projekttexte darin, vor allem
„Wo wir stehen", werden zusammen mit dieser Roadmap gepflegt.
