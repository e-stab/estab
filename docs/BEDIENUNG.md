# Bedienung

## Grundprinzip

eStab ordnet jede operative Eingabe einem aktiven Einsatz und einem
angemeldeten persönlichen Konto zu. Ohne aktiven Einsatz bleiben Anmeldung
und Administration erreichbar, operative Änderungen sind jedoch gesperrt.

Die Statusleiste zeigt Einsatz, Führungsstelle, Benutzer, wirksame Funktion
und Berechtigungsmodus. Nach 15 Minuten ohne echte Interaktion erscheint ein
Benutzer als inaktiv; nach 12 Stunden endet die Sitzung.

## Einsatz einrichten

Unter `/4fadm/incidents.php` werden mindestens erfasst:

- eindeutige Kennung und Einsatzbezeichnung,
- Beginn und Bedarfsträger,
- Name der Führungsstelle,
- Einsatz- oder Führungsleitung,
- Auftrag und Ausgangslage,
- Berechtigungsmodus.

Es kann nur einen aktiven Einsatz geben. Der Modus soll vor der ersten
operativen oder formalen Eintragung feststehen. Danach bleibt genau eine
Richtung offen: der Aufwuchs von „Locker“ auf „Streng“, wenn die
Führungsstufe steigt und ein Stab zusammentritt. Der Wechsel steht im
Einsatztagebuch, und bis eine Dienstschicht mit allen Pflichtfunktionen
aktiviert und angenommen ist, bleiben operative Eingaben gesperrt. Der
umgekehrte Weg ist gesperrt: eine formal geführte Führungsstelle darf ihre
Nachweisführung nicht abschwächen.

Vor der Freigabe benennt die Einsatzverwaltung die Stationen des
Nachrichtenlaufs, die nicht besetzt sind. Das ist ein Hinweis, keine Sperre --
eine Führungsstelle ohne Stab arbeitet auch mit Lücken.

### Streng

- Standardmodus,
- aktive formale Dienstschicht erforderlich,
- Funktionen werden persönlich besetzt,
- die Person nimmt die Besetzung an und wählt sie als aktuelle Funktion,
- nur diese ausgewählte Besetzung verleiht operative Rechte,
- fällt eine Kraft aus, lässt sich ihre Funktion einzeln neu besetzen, ohne
  die Schicht zu übergeben; beide Namen stehen mit Grund im Einsatztagebuch.

### Locker

- keine formale Dienstschicht erforderlich,
- Rechte stammen aus fester Kontofunktion und ausdrücklich vergebenen
  Zusatzfunktionen,
- optionale Zugangsschichten steuern Gruppenzugänge, nicht Fachrechte,
- die manuelle Kontosperre hat immer Vorrang,
- ausgehende Nachrichten sind auch ohne veröffentlichten S6-Fernmeldeplan
  beförderbar; der LdF benennt Übermittlungsmittel und Weg dann unmittelbar,
- trägt eine Person mehrere Funktionen, führt die Seitenleiste für jede einen
  eigenen Warteschlangenzähler.

## Benutzer

Unter `/4fadm/users.php` erhält jede Person ein eigenes Konto mit Name,
eindeutigem Kürzel, fester Funktion und Startkennwort. Dort lassen sich Konten
sperren, entsperren, zurücksetzen und im lockeren Modus um Zusatzfunktionen
ergänzen.

Die Kennwortrichtlinie liegt unter `/4fadm/password_policy.php`.
Selbstregistrierung ist standardmäßig geschlossen und kann unter
`/4fadm/self_registration.php` dauerhaft oder zeitlich begrenzt geöffnet
werden. Das sollte nur in einem kontrollierten Netz geschehen.

## Nachrichten

Die Anwendung führt Nachrichten durch feste Stationen:

```text
Ausgang:       Verfasser → Si → LdF → Fernmelder → abgeschlossen
Eingang:       Fernmelder → LdF → Si → Empfänger → abgeschlossen
Gesprächsnotiz: Verfasser → Si → abgeschlossen
```

Eine zurückgegebene Nachricht geht zur korrigierenden Station zurück; die
Statusleiste des Vordrucks zeigt auch wiederholte Durchläufe und Laufzeiten.

Die Gesprächsnotiz hat einen eigenen, kurzen Laufweg: Sie hält ein bereits
geführtes Gespräch fest, deshalb ist mit der Sichtung nichts mehr zu
befördern. Eine Disposition durch den LdF und ein Beförderungsnachweis der
Fernmelder entfallen.

### Nachricht anlegen

1. Richtung und Art wählen.
2. Rufnamen, Absender, Empfänger, Überschrift und Text ausfüllen.
3. Vorrangstufe, gewünschtes Übermittlungsmittel (Feld 7) und erforderliche
   Vermerke im Vordruck setzen.
4. Abfassungszeit (Feld 16) eintragen. Die Anwendung setzt sie nicht selbst
   ein: sie kennt den Zeitpunkt der Erfassung, nicht den der Abfassung.
5. Verteiler (Feld 19) ausfüllen. Die rote Lage- und die eigene grüne
   Durchschrift ergänzt der Server unabwählbar.
6. Anhänge direkt am Vordruck auswählen und hochladen.
7. Nachricht an die nächste Station übergeben.

Rufnamen und Absender werden aus früheren Einträgen desselben Einsatzes
vorgeschlagen. Die tatsächliche Aufnahme- oder Beförderungszeit bleibt
änderbar.

Feld 7 trägt den Wunsch des Verfassers und bleibt dauerhaft stehen. Der LdF
disponiert davon unabhängig in Feld 1; erreicht der Fernmelder den Empfänger
darüber nicht, geht die Nachricht mit einem Vermerk in Feld 20 an den LdF
zurück und wird neu disponiert.

Eine eingehende Nachricht lässt sich erst abschliessen, wenn im Verteiler
mindestens ein Bearbeiter benannt ist -- sonst erreichte sie niemanden.

### Anhänge

Unterstützt werden unter anderem Bilder, PDF, Office-Dateien, ZIP, Text und
`.eml`. E-Mails werden passiv ohne aktive Inhalte angezeigt; das Original
bleibt herunterladbar. Bilder und PDFs können im Browser angezeigt werden.

„Vom Vordruck entfernen“ löst nur die Verknüpfung. Das Löschen archivierter
Dateien ist eine getrennte administrative Handlung.

## Meldungen finden

Meldungsübersicht, zweite Sichtung und Nachweisung bieten eine gemeinsame
Suche nach Nummer, Richtung, Status, Rufnamen, Absender, Empfänger,
Überschrift, Text und Zeitraum. Filter lassen sich kombinieren und
zurücksetzen. Die Ergebnisse sind paginiert und bleiben auf den aktiven
Einsatz begrenzt.

## ETB und TTB

Je Einsatz existiert genau ein ETB und ein TTB.

- ETB schreiben `ETB/Stab` oder `S2/Stab`.
- TTB schreiben Fernmelder.
- Gespeicherte Zeilen werden nicht geändert oder gelöscht.
- Eine Korrektur ist ein neuer Eintrag mit Bezug auf die ursprüngliche Zeile.
- Nachrichteneingänge erscheinen im TTB bei der Aufnahme.
- Nachrichtenausgänge erscheinen im TTB erst nach tatsächlicher Beförderung.

## Fernmeldeplan

Eigener Bereich unter `/4fach/fuehrungsstelle.php`. Die Melderaufträge stehen
daneben, nicht darunter: Der Plan ist eine Unterlage, die tagelang gilt; ein
Melderauftrag ist ein einzelner Botengang.

S6 legt einen Planentwurf mit Gültigkeitszeitraum und mindestens einem Weg an.
Je nach Medium werden nur passende Felder angezeigt. Ein Entwurf wird
veröffentlicht oder verworfen; die bisherige Version bleibt bis zur
Veröffentlichung aktiv und danach unverändert in der Historie.

LdF kann für einen Nachrichtenausgang nur einen aktuell gültigen,
veröffentlichten Beförderungsweg auswählen.

## Melderauftrag

Eigener Bereich unter `/4fach/melderauftraege.php`.

```text
LdF beauftragt → Melder übernimmt → Zustellung →
Rücknachricht → Rückkehr → LdF bestätigt
```

LdF darf auch einen fachlich geeigneten, derzeit inaktiven Fernmelder
auswählen. In diesem Fall muss die Person außerhalb von eStab informiert
werden. Übernahme und weitere Schritte erledigt ausschließlich das
beauftragte, persönlich angemeldete Konto.

## Export

Unter `/4fadm/incident_export.php` lässt sich für einen aktiven oder
historischen Einsatz ein PDF-Dossier mit ETB, TTB, Nachrichten, Anhängen,
Nachrichtenereignissen, Dienstorganisation, Fernmeldeplänen,
Melderaufträgen und Betriebsereignissen erzeugen.

Bilder und PDF-Anlagen werden sichtbar dargestellt. Andere Formate erhalten
bei Bedarf eine Hinweisseite; das bytegleiche Original wird zusätzlich in das
PDF eingebettet.

`/4fadm/export.php` erzeugt maschinenlesbare Exporte, listet vorhandene
Exporte und erlaubt Download oder bestätigtes Löschen.

Ein PDF oder Einsatzexport ersetzt kein Vollbackup.

## Einsatz abschließen

Vor dem formalen Abschluss müssen offene Nachrichten, Melderaufträge,
Planentwürfe und im strengen Modus offene Dienstschichten beziehungsweise
Besetzungen beendet sein. Der Abschluss erzeugt die letzten ETB-/TTB-Einträge
und sperrt weitere operative Änderungen. Historische Daten bleiben für Suche
und Export verfügbar.
