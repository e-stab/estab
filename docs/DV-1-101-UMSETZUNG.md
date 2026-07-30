# Umsetzung der THW-DV 1-101 in eStab

## Prüfgrundlage und Aussagegrenze

Die fachliche Prüfgrundlage ist die vom Auftraggeber bereitgestellte
„Dienstvorschrift 1-101 – Führen im THW“, Stand 1. Januar 2006. Die geprüfte
Datei hat 283 A4-Seiten und folgende SHA-256-Prüfsumme:

```text
053fce85f0ebc15b8ab45dc89214ebed442027c3f486363e630153d82e9378d1
```

Umgesetzt wird der für eStab relevante Teil von Kapitel 4.3: Arbeit in der
Führungsstelle, Nachrichtenvordruck, Fernmeldebetrieb, Sichter, Melder,
Einsatztagebuch, Funktionsbesetzung und Kommunikationsplanung. Das offizielle
[THW-Handbuch Sprechfunk, Version 2.0 von Oktober 2020](https://ov-woerth.thw.de/fileadmin/user_upload/LVBY/GSTR/OWOE/Mediathek/Ausbildungsmaterial/Handbuch_Sprechfunk_im_THW.pdf)
führt die DV 1-101 weiterhin mit dem Stand 1. Januar 2006 und den
Vierfach-Nachrichtenvordruck als deren Anlage auf.

Dieses Dokument ist ein technischer und fachlicher Abdeckungsnachweis für die
genannte Fassung. Es ist keine behördliche Zertifizierung. Ausbildung,
Berechtigungen, lokale Stabsdienstordnung, Datenschutz, Informationssicherheit
und Freigabe der konkreten Betriebsumgebung bleiben in der Verantwortung der
einsetzenden Stelle.

## Abdeckungsmatrix Kapitel 4.3

Die Seitenangaben beziehen sich auf die im vorigen Abschnitt bezeichnete
Fassung der Dienstvorschrift. „Erfüllt“ bedeutet hier: Die benannte
Softwaregrenze ist implementiert und mit dem angegebenen automatisierten
Nachweis prüfbar. Es bedeutet nicht, dass eStab die fachliche Entscheidung
einer ausgebildeten Einsatzkraft ersetzt.

| Fundstelle | Anforderung | Technische Umsetzung | Nachweis |
| --- | --- | --- | --- |
| S. 4-44/4-45 | S2 muss ständig in den Informationsfluss eingebunden sein und alle Ein- und Ausgänge als roten Durchschlag erhalten. | S2/Stab ist die einzige Fähigkeit `LAGE_DOKUMENTATION`; jede abgeschlossene Nachricht enthält die S2-Rotkopie, eine beliebige Umkonfiguration oder Autosichtung ist gesperrt. | `tests/php/admin_operations_security.php`, `tests/integration/message_workflow_http.sh`, Schema-Verifikation |
| S. 4-45 | Die Einsatzdokumentation wird durch S2 sichergestellt; das ETB ist urkundlicher Nachweis und ein Jahr aufzubewahren. | S2 bleibt alleinige Lage-/Rotkopiefunktion. ETB-Schreiben verlangt die getrennte Fähigkeit `EINSATZTAGEBUCH`, die ausschließlich einer ausgewählten S2- oder ETB-Besetzung zugeordnet ist. Einträge sind anhängbar, unterscheiden Ereignis-/Erfassungszeit und werden nur durch begründete Gegenbuchung berichtigt. Formeller Abschluss setzt eine Mindestaufbewahrung von einem Jahr und unterstützt eine Aufbewahrungssperre. | `tests/php/logbook_security.php`, `tests/php/read_authorization_security.php`, `tests/integration/dv_evidence.php`, `tests/integration/dv_operations.php` |
| S. 4-59/4-60 | S6 plant und führt den Telekommunikationseinsatz und stellt die Führbarkeit über geeignete Verbindungen sicher. | Nur die angenommene aktive S6-Funktion darf versionierte Fernmeldepläne erstellen und veröffentlichen. LdF kann nur einen aktuell gültigen, veröffentlichten Planweg disponieren; veröffentlichte Fassungen bleiben unveränderlich. | `tests/integration/dv_operations.php`, `tests/integration/message_workflow_http.sh` |
| S. 4-63 | Der Sichter analysiert Eingänge inhaltlich und leitet sie an zuständige Bearbeiter weiter. | Eingänge laufen zwingend `A/W → LdF → Si`; Si setzt Empfänger und Abschluss. A/W darf den Absender nicht schreiben, LdF übersetzt den aufgenommenen Rufnamen und bestätigt den von A/W erfassten Eingangsweg. Eine Änderung verlangt eine Begründung und wird mit Alt-/Neuwert und LdF-Identität nachgewiesen; A/W-Aufnahmezeit und -zeichen bleiben unverändert. | `tests/php/workflow_security.php`, `tests/php/ldf_validation_security.php`, `tests/php/ldf_ui_flow_security.php`, `tests/integration/message_workflow_http.sh` |
| S. 4-63 | Bei Ausgängen prüft Si nur Anschrift, Unterschrift/Zeichen und Funktion, nicht den Inhalt. | Ausgänge laufen zwingend `Verfasser → Si → LdF → A/W`. Si kann formal freigeben oder mit Pflichtgrund zurückgeben, aber keine Inhaltsfelder verändern. Nach Korrektur folgt die Sichtung erneut. | `tests/php/message_security.php`, `tests/integration/message_concurrency.php`, `tests/integration/message_workflow_http.sh` |
| S. 4-64 | Der Melder darf den Inhalt nicht ändern, muss schnell zustellen, Rücknachrichten feststellen, zurückkehren, sich zurückmelden und den tatsächlichen Empfänger nennen. | Das Medium `Me` verlangt einen LdF-Auftrag und die Zustandskette Beauftragung, persönliche Übernahme, Übergabe mit Empfänger, Rückweg mit explizitem Rücknachrichtenvermerk und Rückkehr. Danach bestätigt ausschließlich die ausgewählte aktive LdF-Besetzung die Rückmeldung an die FmZt. Der Nachrichtenabschluss wartet auf die vollständige Kette. | `tests/integration/dv_operations.php`, `tests/integration/message_workflow_http.sh` |
| S. 4-64 | Bis zur Rückkehr darf der Melder keine anderen Aufträge annehmen; in einer FüSt mit Stab gehört er zur FmZt und wird durch LdF eingesetzt. | Nur eine angenommene aktive A/W-Besetzung ist als Melder wählbar, ausschließlich LdF beauftragt. Während Übernahme, Übergabe und Rückweg sperrt eine zentrale Request-Grenze alle fremden operativen Schreibvorgänge dieses Kontos. | `tests/php/dv_operations_security.php`, `tests/integration/dv_operations.php` |
| S. 4-64 | LdF verantwortet den Fernmeldebetrieb und unterweist, unterstützt und überwacht das Betriebspersonal. | LdF ist eine gesonderte, schichtgebundene Funktion. Sie übersetzt Rufnamen, entscheidet den Planweg, beauftragt Melder und überwacht die sichtbaren Melderzustände; A/W kann diese Entscheidungen nicht vorwegnehmen. | `tests/integration/message_workflow_http.sh`, `tests/integration/dv_operations.php` |
| S. 4-70 bis 4-73 | Die Führungsstelle besitzt benannte Funktionen; Kombinationen S1/S4, S2/S3 sowie ETB/Si sind möglich. | Persönliche Konten und einsatz-/schichtbezogene Funktions-Hüte sind getrennt. Mehrfachzuweisung ist möglich, jede Zuweisung wird persönlich angenommen; S2, Si, S6, LdF und A/W sind vor Schichtaktivierung Pflicht. Pro Nicht-A/W-Funktion gibt es nur eine aktive Besetzung. Der Datenbanktest wechselt real S2→S3 sowie Si→ETB und prüft Funktionstabellen, Gesprächsvermerk, Gelesen-/Erledigt-Zustand, Kategorien und getrennte Rechte. | `tests/integration/dv_operations.php`, `tests/php/dv_operations_security.php`, Authentifizierungs- und Schema-Verifikation |
| S. 4-73 | Die Arbeitsfähigkeit hängt von zweckmäßiger Organisation und raschem Informationsfluss ab. | Einsatz, aktive Schicht, gewählte Funktion, Warteschlangen und aktuelle Zuständigkeit sind sichtbar. Ohne aktiven Einsatz oder aktive Schicht wird serverseitig keine operative Eingabe angenommen. | HTTP-/Browser-Abnahme sowie `tests/integration/message_workflow_http.sh` |

Die Dienstvorschrift verlangt daneben Ausbildung, fachliche Qualifikation,
Weisungsorganisation, räumliche Arbeitsplätze, Lagekarte und physische
Telekommunikationsmittel. Diese Dinge kann eine Webanwendung weder bereitstellen
noch zertifizieren. eStab unterstützt ihren Informations- und
Nachweisprozess; die organisatorische Bereitstellung bleibt zwingende
Betriebsvoraussetzung.

## Verbindlicher Nachrichtenlauf

Die Anwendung behandelt die Statuswerte als Zuständigkeit und nicht nur als
Darstellungsmerkmal:

| Status | Richtung | Zuständig | Zulässige nächste Aktion |
| --- | --- | --- | --- |
| `4` | Ausgang | Si | formale Freigabe an LdF oder begründete Rückgabe an den Verfasser |
| `10` | Ausgang | Verfasser | korrigieren und erneut vollständig zur Sichtung einreichen |
| `1` | Ausgang | LdF | Rufname der Gegenstelle übersetzen und vorgesehenen Beförderungsweg entscheiden |
| `2` | Ausgang | A/W | Nachricht tatsächlich befördern und Zeit sowie realen Weg nachweisen |
| `1` | Eingang | LdF | aufgenommenen Rufnamen übersetzen, Absender festlegen und den von A/W erfassten Eingangsweg bestätigen oder begründet korrigieren |
| `4` | Eingang | Si | Inhalt auswerten, Empfänger festlegen und weitergeben |
| `8` | beide | abgeschlossen | nur lesen, nachweisen und exportieren |

Damit gelten zwei feste Abläufe:

```text
Ausgang: Verfasser → Si → LdF → A/W → abgeschlossen
Eingang: A/W → LdF → Si → Empfänger/abgeschlossen
```

Für Ausgänge prüft Si ausschließlich die formalen Merkmale, insbesondere
Anschrift, Verfasserzeichen und Verfasserfunktion. Inhaltliche Änderungen sind
in dieser Rolle technisch gesperrt. Eine Rückgabe verlangt einen Grund und
erzeugt keine Abkürzung: Nach der Korrektur beginnt die formale Prüfung erneut.
Für Eingänge bleibt die inhaltliche Auswertung und Empfängerzuordnung Aufgabe
des Sichters. A/W erfasst Medium, Aufnahmezeit und Aufnahmezeichen. LdF muss
das Medium vor der Weitergabe ausdrücklich bestätigen. Eine Korrektur ohne
Begründung bleibt mit HTTP 409 in Status 1; bei erfolgreicher Korrektur werden
ursprüngliches und bestätigtes Medium, Begründung und authentifiziertes
LdF-Kürzel im Übergabeereignis gespeichert, ohne Aufnahmezeit oder
Aufnahmezeichen umzuschreiben.

Die bloße Eintragung eines Empfängers erteilt vor dieser Prüfung noch keinen
Zugriff. Empfänger sehen Ein- und Ausgänge erst im abgeschlossenen Status
`8`; der Verfasser eines Ausgangs darf seinen eigenen Entwurf, eine Rückgabe
und den laufenden Beförderungsstatus weiterhin verfolgen und eine begründet
zurückgegebene Nachricht korrigieren. Diese Grenze gilt gleichermaßen für
Liste, Detailansicht sowie persönliche Gelesen-/Erledigt-Markierungen.

Jede operative Lesesicht verlangt darüber hinaus einen aktiven Einsatz und eine
persönlich angenommene, aktive Dienstbesetzung, die in der Sitzung ausgewählt
ist. Eine bloße Kontoanmeldung oder ein frei übermittelter Funktionswert
genügen nicht. Das gilt für Nachrichtenlisten und -details, Kategorien,
Vordrucke, Anhänge sowie ETB und TBB. Die beiden Gesamtansichten sind enger
gebunden: Nur die ausgewählte S2-Funktion mit `LAGE_DOKUMENTATION` erhält die
Meldungsübersicht; die Nachweisung bleibt ausgewähltem LdF beziehungsweise A/W
vorbehalten. Die Navigation blendet unzulässige Spezialziele aus, die
Controller wiederholen die Datenbankprüfung jedoch unabhängig davon.
Das Manifest enthält neun operative Bereiche und zwei Dienste: Vor
Hutauswahl bleiben nur die vier öffentlichen beziehungsweise separat
geschützten Ziele plus Führungsstellen-Bootstrap sichtbar; mit ausgewähltem
Hut sehen normale Stabs-/FB-Funktionen, Si und S6 neun Links, S2 sowie LdF/A/W
jeweils zehn.

Vordruckliste und -download leiten ihr Recht aus genau der zugrunde liegenden
Nachricht ab. Normaler Stab/FB sieht eine terminale Empfängerkopie oder den
eigenen Ausgang. Si, LdF und A/W sehen die eigene aktuelle Warteschlange oder
Sperre sowie Nachrichten mit ihrer eigenen unveränderlichen
Bearbeitungsmarke. Ein verknüpfter Anhang erbt die Leserechte mindestens einer
exakt über den vollständigen Dateinamen verknüpften Nachricht. Ein noch freier
Anhang ist nur für seinen Uploader oder die ausgewählten Funktionen S2, Si und
LdF sichtbar. Auswahl und endgültiges Speichern der Nachricht autorisieren
jeden Anhang erneut. ETB und TBB dürfen alle ausgewählten aktiven
Dienstfunktionen lesen; Schreiben verlangt zusätzlich
`EINSATZTAGEBUCH` beziehungsweise `BEFOERDERUNG`. Der statische Vertrag
liegt in `tests/php/read_authorization_security.php`.

Autosichtung und eine Konfiguration „nur Eingänge sichten“ gibt es nicht mehr.
Ist die Funktion Si nicht besetzt, bleibt die Nachricht sichtbar in ihrer
Warteschlange. A/W darf weder den Sichtervermerk noch eine Sichtung stellvertretend
erzeugen.

Aufnahme-, Verfasser-, Sichter-, LdF- und Beförderungsvermerke werden aus der
serverseitig geprüften Sitzung abgeleitet. Ein Browser kann diese Zeichen und
Funktionen nicht durch manipulierte Formularwerte ersetzen. Zeiten dürfen im
fachlich vorgesehenen Formular bewusst korrigiert werden; der tatsächlich
speichernde Zeitpunkt und die handelnde Identität bleiben zusätzlich im
Ereignisnachweis erhalten.

Ist ein von LdF disponierter Weg bei A/W tatsächlich nicht verfügbar, darf A/W
keinen erfundenen Beförderungsnachweis eintragen. Die Nachricht geht mit
Pflichtgrund an LdF zurück; LdF disponiert danach einen aktuell gültigen
S6-Planweg neu. Alte und neue Entscheidung bleiben in der Ereigniskette
sichtbar. Beim Medium Melder ist eine Neudisposition erst möglich, nachdem ein
noch nicht übernommener Auftrag sauber storniert wurde.

## Lage und Dokumentation

Jeder Ein- und Ausgang erhält unabhängig von frei wählbaren Empfängerkopien
eine rote Kopie für die Fähigkeit „Lage und Dokumentation“. Ausschließlich
S2/Stab besitzt `LAGE_DOKUMENTATION`; die Administrationsmatrix kann
Rotkopie, Meldungsübersicht und Empfängeridentität nicht auf eine beliebige
Funktion verschieben.

Die Tagebuchführung ist davon technisch getrennt. `EINSATZTAGEBUCH` gehört
ausschließlich S2/Stab und der eigenen Funktion ETB/Stab. Damit kann S2 seine
Dokumentationsverantwortung selbst wahrnehmen oder eine ausdrücklich besetzte
ETB-Funktion das Buch führen. Eine nach Kapitel 4.3 kombinierte Person wählt
zwischen getrennten ETB- und Si-Hüten. Der Si-Hut erhält dadurch kein
ETB-Schreibrecht; der ETB-Hut erhält weder `LAGE_DOKUMENTATION` noch
Meldungsübersicht, Rotkopien oder normale Nachrichten-/Kategorietabellen.

Das ETB unterscheidet:

- Ereigniszeit und Erfassungszeit,
- Eintragstyp,
- handelnde Person und aktive Funktion,
- optionale Referenz auf Nachricht, Anhang, Melderlauf oder anderen Eintrag,
- Berichtigung mit Begründung und Gegenreferenz.

Ein bestehender ETB-Eintrag wird nicht überschrieben. Eine Korrektur ist ein
neuer, nachvollziehbarer Eintrag. Dadurch bleibt die zeitliche Entwicklung
sichtbar.

## Ereignisnachweis und Einsatzabschluss

Alle fachlichen Nachrichtenübergänge erzeugen in derselben
Datenbanktransaktion ein strukturiertes, einsatzgebundenes Ereignis. Der
Nachweis enthält mindestens Nachricht, vorherigen und neuen Zustand,
Ereignistyp, Identität, Funktion, Zeitpunkt und fachliche Zusatzdaten. Die
Ereigniskette ist nur anhängbar; UPDATE und DELETE werden von der Datenbank
abgewiesen.

„Nicht mehr aktiv“ und „formal abgeschlossen“ sind getrennte Zustände. Vor
einem formalen Abschluss prüft eStab offene Nachrichten, offene
Dienstbesetzungen, offene Melderläufe, noch nicht freigegebene
Kommunikationspläne sowie Verfügbarkeit und Eingangsintegrität neuer Anhänge.
Vor dem Upgrade vorhandene Anhänge werden dabei sichtbar als nicht
rückwirkend belegbarer Legacy-Bestand gezählt. Der Abschluss erfolgt nur nach ausdrücklicher Bestätigung
und wird selbst unveränderbar nachgewiesen. Danach sind operative Änderungen
für diesen Einsatz gesperrt.

Auch die letzte aktive Dienstschicht darf nicht vorzeitig geschlossen werden.
Für diesen Schritt läuft dieselbe fachliche Abschlussprüfung, wobei nur die
gerade zu schließende Schicht und ihre eigenen Besetzungen ausgenommen werden.
Offene oder gesperrte Nachrichten, unfertige beziehungsweise fehlerhaft
nachgewiesene Anhänge, Planentwürfe, Melderläufe, weitere Schichten,
Übergabeanforderungen oder eine ungültige Ereigniskette halten die Schicht
offen. Damit kann kein Einsatz mit Restarbeit irreversibel ohne aktive Schicht
zurückbleiben. Der produktive Schließ-POST übergibt dabei ausdrücklich den
konfigurierten Anhang-Ablageroot an den Preflight; er prüft also die realen
Dateibytes und nicht nur die formal vollständigen Datenbankfelder. Der
HTTP-Integrationstest verändert unmittelbar vor diesem POST ein Byte eines
Pflichtanhangs bei gleicher Dateigröße. Erwartet werden HTTP 409 und der
Blocker „Anhang-Integritätsfehler“; Status und Endzeit der Schicht sowie
Status und Ablösezeit aller Funktionsbesetzungen müssen exakt unverändert
bleiben.

Für jeden Einsatz wird ein frühestes Aufbewahrungsende von mindestens einem
Jahr ab formalem Abschluss gespeichert. Eine rechtliche beziehungsweise
fachliche Aufbewahrungssperre kann das Löschen darüber hinaus unterbinden.
eStab löscht Einsatzfachdaten nicht automatisch beim Erreichen dieses Datums.

## Dienstbesetzung und Funktionskombination

Das Benutzerkonto bezeichnet die Person; die einsatz- und schichtbezogene
Dienstbesetzung bezeichnet die ausgeübte Funktion. Beginn, persönliche
Annahme, Ende, personeller Nachfolger und Bemerkung werden nachvollziehbar
gespeichert. Ein ungesperrtes Konto darf bereits offline für eine geplante
Schicht eingeteilt werden. In der Weboberfläche verlangt die persönliche
Annahme eine gültige Anmeldung und ein ungesperrtes Konto. Die gespeicherte
Annahme bleibt nach einer Abmeldung fachlich gültig; `nv_benutzer.aktiv` ist
nur momentaner Onlinezustand und kein Gültigkeitsmerkmal. Ein gesperrtes Konto
erfüllt dagegen keine Pflichtbesetzung für Aktivierung oder Übergabe.

Eine Übergabe ist bewusst zweistufig. Die Administration initiiert sie mit
einer Zusammenfassung; dadurch wird noch keine Schicht umgeschaltet und kein
Bestätigungsnachweis erzeugt. Erst ein persönlich angemeldetes Konto mit
angenommener Funktion in der Nachfolgeschicht bestätigt die Übernahme. Dann
werden die Schichten atomar gewechselt und jede abgelöste Besetzung mit einer
angenommenen Besetzung derselben Funktion und Rolle in der Nachfolgeschicht
verknüpft, auch wenn eine andere Person übernimmt. Eine fehlerhafte offene
Anforderung kann nur mit Pflichtgrund storniert werden; Initiierung,
Stornierung oder Bestätigung bleiben mit Akteur und Zeitpunkt erhalten.

Eine Person kann mehrere ausdrücklich zugewiesene Funktionen wahrnehmen und
in der Oberfläche zwischen diesen „Hüten“ wechseln. Privilegierte S6- und
LdF-Aktionen sowie alle operativen Lese- und Schreibvorgänge prüfen nicht nur
das Konto, sondern die aktuell ausgewählte, persönlich angenommene
Besetzungs-ID. Jeder Schreibpfad bindet Konto, Funktion und Rolle an exakt
diese ID und validiert sie gegen aktiven Einsatz und aktive Schicht erneut;
eine fremde, abgelaufene oder nur funktionsgleiche Besetzung genügt nicht.
Eine freie Auswahl nicht zugewiesener Rollen ist ausgeschlossen. Ohne aktive
Schicht oder ohne zum Konto passende angenommene Funktion sind operative
Lese- und Schreibvorgänge gesperrt. Dasselbe gilt bereits ohne aktiven Einsatz;
unbekannte neue Schreibendpunkte fallen standardmäßig ebenfalls unter diese
Sperre.

Vor der Auswahl ist nur der eng begrenzte Führungsstellen-Bootstrap zulässig:
Er zeigt Einsatz-/Schichtgrunddaten und ausschließlich eigene Besetzungen und
ermöglicht deren persönliche Annahme, eine persönliche Übergabebestätigung
sowie die Auswahl einer eigenen aktiven, angenommenen Besetzungs-ID. Er
gewährt noch keinen Zugriff auf ETB, TBB, Nachrichten, Anhänge,
Telekommunikationspläne oder Melderaufträge. Öffentliche und separat
administrativ geschützte Bereiche behalten ihre eigenen Zugriffsgrenzen.

Die in Kapitel 4.3 vorgesehenen Kombinationen werden semantisch unterstützt:
S1/S4, S2/S3 sowie ETB/Sichter. Der Integrationsnachweis wechselt mit realen
PHP-Sitzungen ein Konto von S2 nach S3 und ein zweites von Si nach ETB.
Für den zusätzlichen S3-Hut werden die sechs benötigten
Nachrichten-/Status-/Kategorietabellen vor dem Sitzungswechsel idempotent und
unter Advisory Lock bereitgestellt; anschließend werden Gesprächsvermerk,
Gelesen-/Erledigt-Zustände und Kategorien tatsächlich geschrieben. Der
ETB-Hut benötigt diese Legacy-Tabellen nicht und erhält sie deshalb nicht.
Eine Kombination hebt keine Einzelpflicht auf. Insbesondere bleiben
S2-Rotkopie, ETB-Verantwortung, Sichtergrenze und serverseitige
Identitätsbindung getrennt erhalten.

## S6-Kommunikationsplanung

Kommunikationspläne sind einsatzgebunden und versioniert. Plan-ID, Einsatz,
Version, Erstellungszeit und Ersteller sind ab Anlage unveränderlich. Nur der
Inhalt eines Entwurfs darf bearbeitet werden; Freigabefelder bleiben bis zur
Veröffentlichung leer. Die Veröffentlichung verlangt die aktuell ausgewählte,
aktive und ungesperrte S6-Besetzung sowie mindestens einen Planweg. Eine
veröffentlichte Version bleibt vollständig unverändert; Änderungen erzeugen
einen Entwurf beziehungsweise eine Folgeversion. Der Plan weist mindestens
aus:

- Einsatz und Herkunft,
- Gültigkeitsbeginn und optionales Ende,
- beteiligte Betriebsstellen in Klarbezeichnung,
- Rufnamen,
- Kanal beziehungsweise Rufgruppe einschließlich Bandlage,
- Verkehrsform oder besondere Behandlung,
- Bemerkung und Betriebsleitung,
- erstellende, freigebende und veröffentlichende Identität.

Nur die dafür berechtigte S6-Besetzung darf Planinhalte pflegen. Andere
Funktionen erhalten die freigegebene Fassung lesend.

## Melderlauf

Das Medium „Melder“ ist mehr als ein Textwert. Ein einsatz- und
nachrichtengebundener Lauf bildet die Kette Auftrag, Übernahme, Zustellung,
Empfängerbestätigung und Rückkehr ab. Jeder Schritt erhält Zeit und
servergebundene Identität. Während eines offenen Laufs kann dieselbe Person
keinen zweiten Lauf übernehmen. Vor der persönlichen Übernahme kann LdF einen
fehlerhaft disponierten Lauf begründet abbrechen und anschließend
nachvollziehbar neu disponieren. Nach der Übernahme ist kein stiller Abbruch
mehr zulässig: Zustellung, ausdrückliche Entscheidung über eine
Rücknachricht und Rückkehr werden vom konkret beauftragten Melder persönlich
bestätigt. Den abschließenden Eingang der Rückmeldung bei der FmZt bestätigt
ausschließlich die ausgewählte aktive LdF-Besetzung. Ziel und jeder bereits
gesetzte Zeit-, Empfänger- oder Rücknachrichtenbeleg sind bei allen späteren
Statusübergängen unveränderlich. Ein bereits vollständig gemeldeter Lauf
bleibt endgültig und kann nicht erneut disponiert werden.

## Export und Nachweisführung

Der PDF-Einsatzexport verwendet für jede Nachricht denselben
Vierfach-Vordruckrenderer wie „Generierte Vordrucke“. Seine neun wählbaren
Abschnitte sind ETB, TBB, Nachrichtenvordrucke, Anhänge,
Nachrichtenereignisse, Dienstbetrieb, S6-Fernmeldepläne, Melderläufe und
Betriebsereignisse. Sie werden einsatzgebunden aus einem konsistenten
Datenbanksnapshot ausgegeben. Offene beziehungsweise noch nicht formal
abgeschlossene Einsätze werden im Dossier deutlich als vorläufig bezeichnet.

Die maschinenlesbaren Exporte und Backups bleiben zusätzlich erforderlich:
Eine PDF ist ein lesbarer Abzug, aber kein Ersatz für Datenbank,
Anhänge, Eingangsintegritätsstatus, Prüfsummen und Wiederherstellungsnachweis.
Neue Dateien sind an SHA-256 und Bytezahl ihres Eingangs gebunden; beim
Upgrade vorhandene Dateien heißen ausdrücklich „Integrität beim Eingang nicht
belegbar“ und erhalten keinen erfundenen rückwirkenden Nachweis.

## Verbindliche Abnahme vor dem Einsatz

Vor einer fachlichen Freigabe müssen mindestens folgende Prüfungen erfolgreich
sein:

1. Fresh-Install-Migration und Schema-Readiness.
2. Vollständiger Ausgang mit Freigabe sowie ein Ausgang mit Rückgabe,
   Korrektur und erneuter Freigabe.
3. Vollständiger Eingang mit Rufnamenübersetzung und Empfängerzuordnung.
4. Manipulationsversuche gegen Identitätsvermerke, Statusfolge,
   Rollenrechte und Einsatzgrenze.
5. Positive und negative Lesetests mit mehreren ausgewählten Funktions-Hüten
   für Nachricht, Vordruck, verknüpften und freien Anhang, Meldungsübersicht,
   Nachweisung, Kategorien sowie ETB/TBB.
6. Rotkopie und ETB-Referenz für Ein- und Ausgang.
7. Schichtübergabe und zulässige Mehrfachfunktion.
8. Veröffentlichung einer S6-Planfolge und vollständiger Melderlauf.
9. Abschluss-Preflight, formaler Abschluss, Schreibsperre,
   Aufbewahrungsfrist und Aufbewahrungssperre.
10. PDF-Dossier mit allen neun Abschnitten samt verifizierten beziehungsweise
   klar als Legacy
   gekennzeichneten Anhängen sowie Backup-/Restore-Roundtrip.
11. Browserabnahme aller eingesetzten Rollen in der vorgesehenen
    Zielumgebung.
12. Abweisung sämtlicher operativer Lese- und Schreibpfade ohne ausgewählte
    aktive Dienstfunktion; während eines übernommenen Melderlaufs zusätzlich
    Abweisung aller fremden operativen Schreibpfade.
13. Nicht verfügbarer Beförderungsweg mit begründeter Rückgabe an LdF,
    Neudisposition und unverändertem historischen Nachweis.
14. Pre-Hat-Prüfung: ausschließlich Führungsstellen-Grunddaten, eigene
    Annahme/Übergabebestätigung und Auswahl einer eigenen Besetzungs-ID sind
    möglich; fremde oder abgelaufene IDs sowie Plan-, Melder-, Nachrichten-,
    Anhangs- und Logbuchzugriffe bleiben gesperrt.

Die konkreten automatisierten Testdateien und das Freigabeprotokoll stehen in
`FUNKTIONSNACHWEIS.md` und `TESTS-UND-MONITORING.md`.

## Organisatorische Restkontrollen

Software kann die fachliche Beurteilung und Führung nicht ersetzen. Vor Ort
müssen weiterhin geregelt und geübt werden:

- wer für den konkreten Einsatz Leiter der Führungsstelle, S1 bis S6,
  Sichter, LdF, A/W, ETB und Melder ist,
- welche Funktionskombinationen lageabhängig zulässig sind,
- wie bei System-, Strom- oder Netzwerkausfall auf Papier zurückgefallen und
  anschließend nacherfasst wird,
- wie Uhrzeitsynchronisation, TLS, Endgeräteschutz, Backup, Restore,
  Zugriffsentzug und Datenschutz betrieben werden,
- welche neuere oder örtlich ergänzende Vorschrift gegenüber der hier
  geprüften Fassung Vorrang hat.
