<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Datenschutz: Vorlagen für den Verein

Diese Vorlagen helfen dem Verein, das Notenarchiv in seine
Datenschutzunterlagen aufzunehmen. Sie beschreiben, was die App speichert
(Stand: Version 0.11). Sie sind **keine Rechtsberatung**: Passe sie an deinen
Verein, deine Nextcloud und deine Module an, und lass sie im Zweifel von
deiner oder deinem Datenschutzbeauftragten prüfen. Eckige Klammern
`[…]` füllst du selbst aus.

Was die App im Einzelnen speichert, steht im
[Administrationshandbuch, Abschnitt 10](administrationshandbuch.md#10-datenschutz).

## 1. Verzeichnis von Verarbeitungstätigkeiten (Art. 30 DSGVO)

Allgemeine Angaben für alle Einträge:

- **Verantwortlicher:** [Name des Vereins, Anschrift, vertreten durch …]
- **Datenschutzbeauftragte(r), falls benannt:** [Name, Kontakt]
- **Technik:** Nextcloud auf [eigenem Server / bei Anbieter …]; mit einem
  Anbieter besteht ein Vertrag zur Auftragsverarbeitung nach Art. 28 DSGVO.
- **Technische und organisatorische Maßnahmen:** Zugriff nur mit
  Nextcloud-Konto und Rolle im Notenarchiv; Rollen nach Aufgaben getrennt;
  verschlüsselte Verbindung (HTTPS); Sicherungen im Sicherungsordner des
  Kontos, das den Archivordner eingerichtet hat; [weitere Maßnahmen der
  Nextcloud, z. B. Zwei-Faktor-Anmeldung].

### 1.1 Notenarchiv: Werkverzeichnis und Protokoll

| Feld | Eintrag |
|---|---|
| Zweck | Verwaltung des Notenbestands des Vereins, Nachweis der Rechte und Lizenzen, Nachvollziehbarkeit von Änderungen |
| Betroffene | Mitglieder mit einer Rolle im Notenarchiv (Notenwart, Chorleitung, Vorstand …) |
| Daten | Konto und Anzeigename bei jeder Änderung, Zeitpunkt, was geändert wurde |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. f DSGVO (Nachweis der Rechte und Änderungen); für Funktionsträger auch die Satzung |
| Empfänger | die Rollen des Notenarchivs; [Anbieter der Nextcloud als Auftragsverarbeiter] |
| Drittland | [nein / ja: …] |
| Löschfrist | Das Protokoll wird nicht gelöscht (Nachweis). Wird ein Konto gelöscht, entfernt die App das Konto aus dem Protokoll; der Anzeigename bleibt. |

### 1.2 Personenregister (Urheberinnen und Urheber)

| Feld | Eintrag |
|---|---|
| Zweck | Einschätzung der Schutzfrist und der Rechte an den Werken im Bestand |
| Betroffene | lebende Personen aus Komposition, Text, Bearbeitung, Übersetzung und Herausgabe (Verstorbene fallen nicht unter die DSGVO) |
| Daten | Name, Rolle an einer Fassung, Geburtsjahr (kein volles Geburtsdatum), Quelle, Identifikatoren (GND, Wikidata) |
| Herkunft | öffentliche Quellen: Notendrucke, Verlagsverzeichnisse, Normdaten |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. f DSGVO; keine Einzelinformation, weil unverhältnismäßig (Art. 14 Abs. 5 lit. b DSGVO) |
| Empfänger | die Rollen des Notenarchivs; mit dem Modul „Öffentliche Repertoireseite“ die Öffentlichkeit (nur Komposition und Text bestätigt gemeinfreier Werke) |
| Löschfrist | solange eine Fassung im Bestand die Person für die Rechte braucht; Lebensdaten auf Widerspruch sofort |

### 1.3 Mappenbuch (nur mit dem Modul „Mappenbuch“)

| Feld | Eintrag |
|---|---|
| Zweck | Nachweis, welche Chormappe bei wem ist, damit sie zurückkommt |
| Betroffene | Chormitglieder, die eine Mappe haben |
| Daten | Name oder Nextcloud-Konto, Tag der Ausgabe und Rücknahme, Notizen zur Ausgabe |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. b DSGVO (Mitgliedschaft) oder lit. f (Eigentum des Vereins) |
| Empfänger | Rollen „Bestand“, „Betrieb“, „Chorleitung“ und „Lesen“ |
| Löschfrist | [12] Monate nach der Rücknahme (einstellbar); eine Mappe, die nicht zurückkommt, 36 Monate nach der Ausgabe; bei gelöschtem Konto sofort (offene Ausgabe: nur das Konto). Sicherungen enthalten die Daten bis zu ihrer Rotation nach acht Wochen. |

### 1.4 Teilen mit Chormitgliedern (nur mit den Modulen „Links für Chormitglieder“ und „Übe-Audio für Chormitglieder“)

| Feld | Eintrag |
|---|---|
| Zweck | Üben mit Ausschnitten, die Notenwart oder Chorleitung teilen |
| Betroffene | Chormitglieder mit Nextcloud-Konto |
| Daten | Aufrufe eines Links werden gezählt, ohne festzuhalten, wer; für jede Übe-Datei: Rolle „Chormitglied“, Fassung, Takte, Stimme, Format, Zeitpunkt, **ohne Konto und Namen** |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. b DSGVO (Mitgliedschaft); für das Protokoll der Übe-Dateien lit. f (Nachweis gegenüber Rechteinhabern) |
| Empfänger | die Rollen des Notenarchivs mit Zugriff auf das Protokoll |
| Löschfrist | Das Protokoll wird nicht gelöscht; es enthält keine personenbezogenen Daten der Mitglieder. |

### 1.5 Öffentliche Seiten (nur mit den Modulen „Öffentliche Links“ und „Öffentliche Repertoireseite“)

| Feld | Eintrag |
|---|---|
| Zweck | Ausschnitte und das Repertoire für alle zeigen; Schutz gegen Missbrauch |
| Betroffene | Besucherinnen und Besucher ohne Konto |
| Daten | IP-Adresse, Zeitpunkt, aufgerufene Adresse (mit der Adresse des Links), Browserkennung: im Log des Webservers, im Schutz gegen Brute-Force-Angriffe und in der Begrenzung der Aufrufe der Nextcloud, bei Fehlern in `nextcloud.log` |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. f DSGVO (Sicherheit des Servers) |
| Empfänger | Betreiber des Servers [Anbieter] |
| Löschfrist | Log des Webservers: [z. B. 7 Tage]; Brute-Force-Schutz: [Nextcloud: 48 Stunden]; `nextcloud.log`: [Rotation …]. Die App setzt keine Cookies für Besucher ohne Konto und lädt nichts von Dritten. |

### 1.6 Agenten (nur mit dem Modul „Schnittstelle für Agenten“)

| Feld | Eintrag |
|---|---|
| Zweck | [z. B. Vorschläge für das Werkverzeichnis, Prüfung von Übertragungen] |
| Betroffene | wie 1.1 und 1.2 (mit der Rolle „Vorschläge (Werkzeuge)“ kein Mappenbuch) |
| Daten | was der Agent liest: Werkverzeichnis, Personenregister, Protokoll ohne Mappenbuch |
| Empfänger | Anbieter des Sprachmodells [Name] als Auftragsverarbeiter (Vertrag nach Art. 28 DSGVO) |
| Drittland | [nein / ja: Land, Garantie nach Art. 44 ff. DSGVO, z. B. Angemessenheitsbeschluss oder Standardvertragsklauseln] |
| Löschfrist | beim Anbieter: [laut Vertrag]; im Notenarchiv wie 1.1 |

## 2. Information der Chormitglieder (Art. 13 DSGVO)

Baustein für die Datenschutzhinweise des Vereins, zum Beispiel als Abschnitt
„Notenarchiv“:

> **Notenarchiv.** Der [Verein] verwaltet seinen Notenbestand in einer
> Nextcloud mit der App „Notenarchiv“. Wenn du eine Chormappe bekommst,
> halten wir fest, welche Mappe du seit wann hast (Name oder
> Nextcloud-Konto, Tag der Ausgabe, Notizen zur Ausgabe). Das dient dazu,
> dass die Noten des Vereins zurückkommen (Art. 6 Abs. 1 lit. b DSGVO).
> [12] Monate nach der Rückgabe entfernen wir Name und Notizen automatisch;
> kommt eine Mappe nicht zurück, nach 36 Monaten. Lesen können diese Angaben
> [Notenwart, Chorleitung, Vorstand].
>
> Wenn wir Ausschnitte mit dir teilen, zählen wir Aufrufe, ohne
> festzuhalten, wer sie geöffnet hat. Machst du eine Übe-Datei, halten wir
> fest, dass ein Chormitglied sie gemacht hat (Stück, Takte, Stimme), nicht
> wer. Das brauchen wir als Nachweis gegenüber den Rechteinhabern (Art. 6
> Abs. 1 lit. f DSGVO).
>
> Du kannst Auskunft über deine Daten verlangen, ihre Berichtigung oder
> Löschung und der Verarbeitung widersprechen. Wende dich an [Kontakt].
> Du kannst dich bei einer Aufsichtsbehörde beschweren, zum Beispiel bei
> [zuständige Landesdatenschutzbehörde].

## 3. Datenschutzerklärung der öffentlichen Seiten

Baustein für die Datenschutzerklärung, die unter *Verwaltung → Design* als
Adresse eingetragen ist und in der Fußzeile der öffentlichen Seiten
erscheint:

> **Öffentliche Seiten des Notenarchivs.** Der [Verein] zeigt auf
> [Adresse] Notenausschnitte und sein Repertoire für alle. Beim Aufruf
> verarbeitet unser Server technisch notwendige Daten: IP-Adresse,
> Zeitpunkt, aufgerufene Adresse und Browserkennung. Sie stehen im Log des
> Webservers und dienen dazu, den Server vor Missbrauch zu schützen, zum
> Beispiel vor dem Durchprobieren von Link-Adressen (Art. 6 Abs. 1 lit. f
> DSGVO). Wir löschen sie nach [7 Tagen]; Einträge des Schutzes gegen
> wiederholte Fehlversuche nach [48 Stunden]. Die Seiten setzen keine
> Cookies und laden keine Inhalte von Dritten. Das Abspielen geschieht in
> deinem Browser. [Verantwortlicher, Kontakt, Rechte wie in Abschnitt 2.]

Vor dem Einschalten der öffentlichen Module prüfst du:

- Ist unter *Verwaltung → Design* eine Adresse für das **Impressum** und für
  die **Datenschutzerklärung** eingetragen? Die Moduleinstellungen warnen,
  solange eine fehlt.
- Stimmen die Speicherdauern oben mit deinem Server überein (Log-Rotation
  des Webservers, `nextcloud.log`)?
- Sieht die Nextcloud hinter einem Reverse Proxy die echten Adressen
  (`trusted_proxies`)? Sonst trifft der Schutz gegen Brute-Force-Angriffe
  den Proxy.
