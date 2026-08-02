# Umsetzung der THW-DV 1-101 in eStab

## Prüfgrundlage und Aussagegrenze

Die fachliche Prüfgrundlage besteht aus zwei vom Auftraggeber bereitgestellten
Unterlagen:

1. „Dienstvorschrift 1-101 – Führen im THW“, Stand 1. Januar 2006. Die
   geprüfte Datei hat 283 A4-Seiten und folgende SHA-256-Prüfsumme:

```text
053fce85f0ebc15b8ab45dc89214ebed442027c3f486363e630153d82e9378d1
```

2. „ETB-/und TBB-Führung in THW-Führungsstellen – Grundlagen/Handhabung“,
   herausgegeben von der Bundesanstalt Technisches Hilfswerk,
   Ausbildungszentrum. Der Unterlagenkopf nennt „Vorlage Stand: September
   2015 – Version: 1.0“, der Seitenfuß „Handbuch ETB/TBB, Version 1.0, Stand:
   März 2022“. Die geprüfte Datei
   `ETB_TBB_Fuehrung_in_THW_FueSt.pdf` hat 33 A4-Seiten und folgende
   SHA-256-Prüfsumme:

```text
2457d1deccd01892655bbc329b08885a0b3c8b3ebfb6372c79997d3427d1ae59
```

Umgesetzt wird der für eStab relevante Teil von Kapitel 4.3: Arbeit in der
Führungsstelle, Nachrichtenvordruck, Fernmeldebetrieb, Sichter, Melder,
Einsatztagebuch, Funktionsbesetzung und Kommunikationsplanung. Das offizielle
[THW-Handbuch Sprechfunk, Version 2.0 von Oktober 2020](https://ov-woerth.thw.de/fileadmin/user_upload/LVBY/GSTR/OWOE/Mediathek/Ausbildungsmaterial/Handbuch_Sprechfunk_im_THW.pdf)
führt die DV 1-101 weiterhin mit dem Stand 1. Januar 2006 und den
Vierfach-Nachrichtenvordruck als deren Anlage auf.

Dieses Dokument ist ein technischer und fachlicher Abdeckungsnachweis für die
genannten Fassungen. Es ist keine behördliche Zertifizierung. Die
ETB-/TBB-Unterlage verweist für elektronische Bücher ausdrücklich auf eine
Freigabe durch die THW-Leitung und schließt andere elektronische Mittel aus.
Für eStab liegt in diesem Repository **keine formale THW-Freigabe** vor. Die
nachfolgende Umsetzung der Regeln und Formblätter darf deshalb nicht als eine
solche Freigabe oder als Berechtigung zum amtlichen urkundlichen Betrieb
verstanden werden. Vor einem entsprechenden Produktiveinsatz sind mindestens
die schriftliche Freigabe der zuständigen THW-Stelle und die dokumentierte
fachliche, datenschutzrechtliche sowie informationssicherheitstechnische
Abnahme der konkreten Betriebsumgebung erforderlich. Ausbildung,
Berechtigungen und lokale Stabsdienstordnung bleiben ebenfalls in der
Verantwortung der einsetzenden Stelle.

## Abdeckungsmatrix Kapitel 4.3

Angaben im Format „S. 4-…“ beziehen sich auf die Dienstvorschrift; ausdrücklich
als „Handbuch ETB/TBB“ bezeichnete Seiten auf die Ausbildungsunterlage von
März 2022. „Erfüllt“ bedeutet hier: Die benannte Softwaregrenze ist
implementiert und mit dem angegebenen automatisierten Nachweis prüfbar. Es
bedeutet weder, dass eStab die fachliche Entscheidung einer ausgebildeten
Einsatzkraft ersetzt, noch dass eine formale THW-Freigabe vorliegt.

Die funktionsbezogenen Aussagen dieser Matrix setzen den einsatzbezogenen
Berechtigungsmodus **Streng** (`STRICT`) voraus. Der optionale Modus **Locker**
(`LOOSE`) hebt ausschließlich die technische Durchsetzung von Funktion/Rolle
bei den dafür vorgesehenen operativen Workflow-, ETB-/TTB-, S6-Plan- und
Melder-Schreibschritten auf. Damit kann er die in der Matrix nachgewiesene
Funktionstrennung nicht technisch belegen und darf nur aufgrund einer
dokumentierten örtlichen Einsatzentscheidung verwendet werden. Auch dann
bleiben persönliche Authentisierung, aktives ungesperrtes Konto, aktiver
offener Einsatz, Workflowzustände, Sperren, Integrität, Audit und Aufbewahrung
verbindlich; die organisatorisch zuständige Funktion ändert sich durch die
Softwareeinstellung nicht.

| Fundstelle | Anforderung | Technische Umsetzung | Nachweis |
| --- | --- | --- | --- |
| S. 4-44/4-45 | S2 muss ständig in den Informationsfluss eingebunden sein und alle Ein- und Ausgänge als roten Durchschlag erhalten. | S2/Stab ist die einzige Fähigkeit `LAGE_DOKUMENTATION`; jede abgeschlossene Nachricht enthält die S2-Rotkopie, eine beliebige Umkonfiguration oder Autosichtung ist gesperrt. | `tests/php/admin_operations_security.php`, `tests/integration/message_workflow_http.sh`, Schema-Verifikation |
| S. 4-45 sowie Handbuch ETB/TBB S. 6 und 26 | Die Einsatzdokumentation wird durch S2 sichergestellt; das ETB ist urkundlicher Nachweis. Die DV-Fassung nennt ein Jahr, die spätere ETB-/TBB-Unterlage zehn Jahre für ETB und TBB. | S2 bleibt alleinige Lage-/Rotkopiefunktion. Die Anwendung setzt die strengere Mindestaufbewahrung von zehn Jahren ab formalem Abschluss um. ETB und TBB sind nur anhängbar; eine Berichtigung ist ein neuer Eintrag mit Gegenreferenz. Ein Legal Hold kann die Frist verlängern, aber nicht verkürzen. | `tests/php/logbook_security.php`, `tests/php/schema_migration_contract.php`, `tests/integration/schema_migrator.sh`, `tests/integration/dv_evidence.php` |
| S. 4-59/4-60 | S6 plant und führt den Telekommunikationseinsatz und stellt die Führbarkeit über geeignete Verbindungen sicher. | Im strengen Modus darf nur ein Konto mit der festen Funktion `S6` versionierte Fernmeldepläne erstellen und veröffentlichen. LdF kann nur einen aktuell gültigen, veröffentlichten Planweg disponieren; veröffentlichte Fassungen bleiben in beiden Modi unveränderlich. | `tests/integration/dv_operations.php`, `tests/integration/message_workflow_http.sh` |
| S. 4-63 | Der Sichter analysiert Eingänge inhaltlich und leitet sie an zuständige Bearbeiter weiter. | Eingänge laufen zwingend `Fernmelder → LdF → Si`; Si setzt Empfänger und Abschluss. Der Fernmelder darf den Absender nicht schreiben, LdF übersetzt den aufgenommenen Rufnamen und bestätigt den vom Fernmelder erfassten Eingangsweg. Eine Änderung verlangt eine Begründung und wird mit Alt-/Neuwert und LdF-Identität nachgewiesen; Aufnahmezeit und -zeichen des Fernmelders bleiben unverändert. | `tests/php/workflow_security.php`, `tests/php/ldf_validation_security.php`, `tests/php/ldf_ui_flow_security.php`, `tests/integration/message_workflow_http.sh` |
| S. 4-63 | Bei Ausgängen prüft Si nur Anschrift, Unterschrift/Zeichen und Funktion, nicht den Inhalt. | Ausgänge laufen zwingend `Verfasser → Si → LdF → Fernmelder`. Si kann formal freigeben oder mit Pflichtgrund zurückgeben, aber keine Inhaltsfelder verändern. Auch LdF kann einen fachlich nicht disponierbaren Vordruck nur begründet an den Verfasser zurückgeben. Nach jeder Korrektur folgen Si und LdF erneut. | `tests/php/message_security.php`, `tests/php/ldf_return_security.php`, `tests/php/message_timeline_security.php`, `tests/integration/message_concurrency.php`, `tests/integration/message_workflow_http.sh` |
| Nachrichtenvordruck-Unterlagen sowie S. 4-63/4-64 | Eine Gesprächsnotiz dokumentiert die ursprüngliche Gesprächsart, bleibt aber als Ausgang im geordneten Informations- und Fernmeldeweg. | Von den fernmeldespezifischen Angaben erfasst der Verfasser ausschließlich die ursprüngliche Gesprächsart. Si prüft formal; danach setzt LdF den Rufnamen der Gegenstelle und disponiert einen aktiven, veröffentlichten S6-Beförderungsweg. Der Fernmelder weist die Beförderung nach. Erst dieser Abschluss erzeugt TBB-Nachweis und PDF-Vordruck. | `tests/integration/message_workflow_http.sh`, `tests/integration/http_smoke.sh` |
| S. 4-64 | Der Melder darf den Inhalt nicht ändern, muss schnell zustellen, Rücknachrichten feststellen, zurückkehren, sich zurückmelden und den tatsächlichen Empfänger nennen. | Das Medium `Me` verlangt einen LdF-Auftrag und die Zustandskette Beauftragung, persönliche Übernahme, Übergabe mit Empfänger, Rückweg mit explizitem Rücknachrichtenvermerk und Rückkehr. Danach bestätigt in `STRICT` ausschließlich ein Konto mit der festen Funktion `LdF` die Rückmeldung an die FmZt; in `LOOSE` bleibt dieser Zustandsschritt zwingend und nur die Funktion des bestätigenden Kontos wird nicht erzwungen. Der Nachrichtenabschluss wartet auf die vollständige Kette. | `tests/integration/dv_operations.php`, `tests/integration/message_workflow_http.sh` |
| S. 4-64 | Bis zur Rückkehr darf der Melder keine anderen Aufträge annehmen; in einer FüSt mit Stab gehört er zur FmZt und wird durch LdF eingesetzt. | Nur ein aktives, ungesperrtes Konto mit der Funktion `Fernmelder` ist als Melder wählbar. Im strengen Modus darf ausschließlich LdF es beauftragen; im lockeren Modus bleibt die Melder-Eignung bestehen, nur die Funktionsprüfung des disponierenden Kontos entfällt. Während Übernahme, Übergabe und Rückweg sperrt eine zentrale Request-Grenze alle fremden operativen Schreibvorgänge dieses Kontos. | `tests/php/dv_operations_security.php`, `tests/integration/dv_operations.php` |
| S. 4-64 | LdF verantwortet den Fernmeldebetrieb und unterweist, unterstützt und überwacht das Betriebspersonal. | LdF ist eine gesonderte feste Kontofunktion. Im strengen Modus ist sie technisch exklusiv für Rufnamenübersetzung, Planwegentscheidung, Melderbeauftragung und Bestätigung der Rückkehr gebunden. Im lockeren Modus bleibt diese organisatorische Zuständigkeit bestehen, wird bei Schreibschritten aber nicht durch die Kontofunktion erzwungen. | `tests/integration/message_workflow_http.sh`, `tests/integration/dv_operations.php` |
| S. 4-70 bis 4-73 | Die Führungsstelle besitzt benannte Funktionen; Kombinationen S1/S4, S2/S3 sowie ETB/Si sind organisatorisch möglich. | Im strengen Modus stammen Schreibrechte aus genau einer festen Kontofunktion und der serverseitig abgeleiteten Rolle. Funktionskombinationen werden organisatorisch durch getrennte persönliche Konten abgebildet; eine Hutauswahl innerhalb der Sitzung gibt es nicht. Optionale Zugangsschichten gruppieren Zugänge, verändern aber keine Fachrechte. Der lockere Modus ist eine sichtbare, auditierte Ausnahmeentscheidung und kein Nachweis der Funktionstrennung. | Authentifizierungs-, Autorisierungs-, Berechtigungsmodus- und Schema-Verifikation |
| S. 4-73 | Die Arbeitsfähigkeit hängt von zweckmäßiger Organisation und raschem Informationsfluss ab. | Führungsstellenname, aktiver Einsatz, feste Funktion, Warteschlangen und Zuständigkeit sind sichtbar. Der Führungsstellenname ist die einsatzbezogene lokale Nachrichtenanschrift/-absendereinheit und von Einsatzname, Bedarfsträger sowie Einsatzleitung getrennt. Ohne aktiven Einsatz oder bestätigten Führungsstellennamen wird serverseitig keine operative Eingabe angenommen; eine Schicht ist nicht erforderlich. | Schema-, Einsatzdomänen-, HTTP- und Browser-Abnahme sowie `tests/integration/message_workflow_http.sh` |

Die Führungsstellenidentität wird nicht aus einer Installationseinstellung
abgeleitet. Neue Einsätze verlangen sie in der Administration. Bei
Bestands-Einsätzen bleibt ein vor Migration 97 unbekannter Wert ehrlich
`NULL` und muss vor weiterer operativer Arbeit einmalig bestätigt werden.
Nach der ersten operativen Eintragung ist ein bestätigter Wert
durch einen dauerhaften, atomar gesetzten Sperrmarker unveränderlich. Beim
Nachrichtenschreiben bindet die gesperrte Einsatztransaktion Eingänge an diese
lokale Anschrift und Ausgänge an diese Absendereinheit; Browser- und
Umgebungswerte können die Zuordnung nicht ersetzen.

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
| `1` | Ausgang | LdF | Rufname der Gegenstelle übersetzen und vorgesehenen Beförderungsweg entscheiden oder mit Pflichtgrund an den Verfasser zurückgeben |
| `2` | Ausgang | Fernmelder | Nachricht tatsächlich befördern und Zeit sowie realen Weg nachweisen |
| `1` | Eingang | LdF | aufgenommenen Rufnamen übersetzen, Absender festlegen und den vom Fernmelder erfassten Eingangsweg bestätigen oder begründet korrigieren |
| `4` | Eingang | Si | Inhalt auswerten, Empfänger festlegen und weitergeben |
| `8` | beide | abgeschlossen | nur lesen, nachweisen und exportieren |

Damit gelten zwei feste Abläufe:

```text
Ausgang: Verfasser → Si → LdF → Fernmelder → abgeschlossen
Eingang: Fernmelder → LdF → Si → Empfänger/abgeschlossen
```

Rückgaben verkürzen diesen Lauf nicht. Si oder LdF geben einen Ausgang mit
Pflichtgrund in Status `10` an den Verfasser zurück; danach folgen erneut
`Verfasser → Si → LdF`. Kann der Fernmelder den disponierten Weg nicht nutzen,
führt die Nachricht von Status `2` zurück zu LdF in Status `1`. Jede Runde
bleibt als eigener Übergang in der Nachrichtenereigniskette erhalten.

Eine neu erfasste Gesprächsnotiz ist als Nachrichtenart gekennzeichnet, aber
keine Ausnahme vom Ausgangslauf. Der Verfasser hält die ursprüngliche
Gesprächsart fest; diese bleibt ein eigenständiger Nachweis und wird durch die
spätere Disposition nicht überschrieben. Si prüft den Vordruck formal. LdF
ergänzt anschließend den Rufnamen der Gegenstelle und wählt ausschließlich
einen aktuell gültigen, veröffentlichten S6-Beförderungsweg. Der Fernmelder
übernimmt die Nachricht erst in Status `2` und weist die Beförderung mit Zeit
und eigenem Zeichen nach. Erst dieser Schritt schließt die Gesprächsnotiz in
Status `8` ab und erzeugt ihren TBB-Nachrichteneintrag sowie den generierten
PDF-Vordruck.

Bereits vorhandene Gesprächsnotizen mit Richtung `E` und Status `8` bleiben
als historische Bestandsdaten unverändert les- und exportierbar. Die
Anwendung öffnet sie nicht erneut, verschiebt sie nicht in den Ausgangslauf
und erzeugt keinen rückwirkenden TBB-Nachweis. Dieser reine
Kompatibilitätsfall darf bei einer Neuanlage nicht entstehen.

Für Ausgänge prüft Si ausschließlich die formalen Merkmale, insbesondere
Anschrift, Verfasserzeichen und Verfasserfunktion. Inhaltliche Änderungen sind
in dieser Rolle technisch gesperrt. Eine Rückgabe verlangt einen Grund und
erzeugt keine Abkürzung: Nach der Korrektur beginnt die formale Prüfung erneut.
Auch LdF verändert einen nicht disponierbaren Vordruck nicht stellvertretend,
sondern gibt ihn begründet an den Verfasser zurück. Nach der Korrektur prüft Si
erneut, bevor LdF erneut disponiert. Der Rückgabegrund, die handelnde Identität
und der Übergang `1 → 10` werden atomar mit der Nachricht hashverkettet.
Für Eingänge bleibt die inhaltliche Auswertung und Empfängerzuordnung Aufgabe
des Sichters. Der Fernmelder erfasst Medium, Aufnahmezeit und Aufnahmezeichen. LdF muss
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

Jede operative Lesesicht verlangt darüber hinaus einen aktiven Einsatz und
eine gültige feste Kontofunktion mit serverseitig abgeleiteter Rolle. Ein frei
übermittelter Funktionswert genügt nicht. Das gilt für Nachrichtenlisten und
-details, Kategorien, Vordrucke, Anhänge sowie ETB und TBB. Die beiden
Gesamtansichten sind enger gebunden: Nur `S2/Stab` mit
`LAGE_DOKUMENTATION` erhält die Meldungsübersicht; die Nachweisung bleibt
den Funktionen `LdF` beziehungsweise `Fernmelder` vorbehalten. Navigation und
Controller prüfen diese Kontorechte unabhängig voneinander; eine Hutauswahl
oder aktive Schicht gibt es als Autorisierungsbedingung nicht.

Vordruckliste und -download leiten ihr Recht aus genau der zugrunde liegenden
Nachricht ab. Normaler Stab/FB sieht eine terminale Empfängerkopie oder den
eigenen Ausgang. Si, LdF und Fernmelder sehen die eigene aktuelle Warteschlange oder
Sperre sowie Nachrichten mit ihrer eigenen unveränderlichen
Bearbeitungsmarke. Ein verknüpfter Anhang erbt die Leserechte mindestens einer
exakt über den vollständigen Dateinamen verknüpften Nachricht. Ein noch freier
Anhang ist nur für seinen Uploader oder die festen Funktionen S2, Si und
LdF sichtbar. Auswahl und endgültiges Speichern der Nachricht autorisieren
jedem Anhang erneut. ETB schreiben feste Konten `ETB/Stab` oder `S2/Stab` mit
`EINSATZTAGEBUCH`; das TTB schreibt die Funktion `Fernmelder` mit
`BEFOERDERUNG`. Eine
aktive Schicht oder angenommene Besetzung ist nicht erforderlich. Der statische Vertrag liegt
in `tests/php/read_authorization_security.php` und
`tests/php/logbook_security.php`.

Autosichtung und eine Konfiguration „nur Eingänge sichten“ gibt es nicht mehr.
Ist die Funktion Si nicht besetzt, bleibt die Nachricht sichtbar in ihrer
Warteschlange. Der Fernmelder darf weder den Sichtervermerk noch eine Sichtung stellvertretend
erzeugen.

Aufnahme-, Verfasser-, Sichter-, LdF- und Beförderungsvermerke werden aus der
serverseitig geprüften Sitzung abgeleitet. Ein Browser kann diese Zeichen und
Funktionen nicht durch manipulierte Formularwerte ersetzen. Zeiten dürfen im
fachlich vorgesehenen Formular bewusst korrigiert werden; der tatsächlich
speichernde Zeitpunkt und die handelnde Identität bleiben zusätzlich im
Ereignisnachweis erhalten.

Die gemeinsame Stationsleiste oberhalb des Vordrucks visualisiert genau diesen
Nachweis. Sie zeigt den aktuellen Bearbeitungsort, abgeschlossene und geplante
Stationen sowie jede tatsächlich durchlaufene Rückgabeschleife. Die angezeigte
Verweildauer zwischen zwei Übergängen wird ausschließlich aus der
serverseitigen Datenbankzeit `recorded_at` berechnet. Fachlich zurückdatierbare
Zeitfelder und `occurred_at` verändern diese Laufzeit nicht. Bei historischem
Bestand werden unbekannte Abschnitte als nicht rekonstruierbar bezeichnet,
statt einen Ablauf oder eine Dauer zu erfinden.

Ist ein von LdF disponierter Weg beim Fernmelder tatsächlich nicht verfügbar,
darf der Fernmelder keinen erfundenen Beförderungsnachweis eintragen. Die Nachricht geht mit
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
Dokumentationsverantwortung selbst wahrnehmen oder ein separates persönliches
ETB-Konto das Buch führen. Für eine organisatorische Kombination ETB/Si werden
zwei getrennte Konten verwendet. Das Si-Konto erhält dadurch kein
ETB-Schreibrecht; das ETB-Konto erhält weder `LAGE_DOKUMENTATION` noch
Meldungsübersicht, Rotkopien oder normale Nachrichten-/Kategorietabellen.

### Gemeinsamer Pflichtkopf

Ein neuer Einsatz kann nur aktiviert werden, wenn folgende Angaben vorhanden
sind:

- Einsatzkennung und genaue Einsatzbezeichnung,
- Einsatzbeginn mit Datum und Uhrzeit,
- Bedarfsträger; technisch wird dafür die historische Spalte
  `organisation` verwendet,
- Name der Führungsstelle,
- verantwortliche Einsatz-/Führungsleitung,
- Einsatzauftrag und Ausgangslage.

Der Einsatz-Insert legt bereits die leeren Nummernköpfe `ETB:1` und `TTB:1`
an. Die erste tatsächliche Buchzeile erhält dadurch lokal die Nummer 1, ohne
dass zuvor eine Schicht aktiviert werden muss. Der im Formblatt vorgesehene
Arbeitsplatz wird nicht erfunden: Das Einsatzdatenmodell besitzt dafür kein
eigenes Feld und die Ausbildungsunterlage sieht vor, dass es bei einem TBB je
Fernmeldebetriebsstelle in der Regel frei bleibt.

Der derzeit geprüfte und von eStab unterstützte Produktumfang ist ausschließlich
eine Führungsstelle mit eingerichteter Fernmeldebetriebsstelle. Deshalb sind
Konten für die Funktionen LdF und Fernmelder organisatorisch vorzusehen und je
Einsatz wird genau ein TBB geführt. Führungsstellen ohne eigene
Fernmeldebetriebsstelle, insbesondere ein
reiner ETB-Betrieb, gehören nicht zum unterstützten Produktumfang. Für diesen
abweichenden Aufbau behauptet eStab keine Konformität. Der Begriff
„unterstützter Produktumfang“ bezeichnet dabei ausschließlich die technische
eStab-Produktgrenze und ausdrücklich keine formale THW-Freigabe.

Für vorbereitete Bestands-Einsätze können Bedarfsträger,
Einsatz-/Führungsleitung sowie Auftrag/Ausgangslage in der Administration
ergänzt werden. Sobald eine ETB-/TBB-Zeile existiert, sind diese Kopfangaben als Tatsachengrundlage der
Eröffnung gesperrt. Ein bereits geführtes Bestandsbuch erhält beim Upgrade
keinen nachträglich erfundenen Eröffnungseintrag.

### Lokale Nummern und schreibberechtigte Kontofunktionen

ETB und TBB bilden je Einsatz jeweils genau einen fortlaufenden Buchstrom.
Neue Nummern werden nicht aus der globalen technischen Primärschlüsselnummer
abgeleitet. Der Einsatz-Insert-Trigger legt zusammen mit jedem neuen Einsatz
genau zwei zunächst leere Kopfzeilen in `nv_logbuch_koepfe` an: `ETB:1` und
`TTB:1`. Jeder Eintrag aktualisiert ausschließlich seinen bereits vorhandenen
einsatz- und buchbezogenen Kopf unter Zeilensperre; ein fehlender Kopf wird
nicht still neu erzeugt, sondern sperrt die Eintragung. Damit beginnen beide
Bücher je Einsatz bei 1 und bleiben auch bei gleichzeitigen ersten
Schreibversuchen eindeutig. Die Strategie benötigt keine Abschaltung der
MariaDB-Standardisolation `REPEATABLE READ`; die konsistenten
Read-only-Export-Snapshots bleiben erhalten. Bestehende Zeilen erhalten beim
Upgrade eine deterministische lokale Nummer nach unveränderlicher
Erfassungszeit und globalem Alt-Schlüssel; ihr Text wird dabei nicht
umgedeutet. Die globalen Primärschlüssel bleiben nur technische Identitäten.

Für manuelle Einträge prüft eStab im strengen Modus die feste Kontofunktion:
ETB schreiben `ETB/Stab` oder `S2/Stab`, das TTB schreibt die Funktion
`Fernmelder`. Im lockeren Modus entfällt ausschließlich diese
Funktions-/Rollenbedingung. Kontosperre, konkretes aktives Konto und aktiver
Einsatz werden bei jedem Schreiben erneut geprüft; feste Funktion,
serverseitig abgeleitete Rolle und fachliche Fähigkeit zusätzlich in
`STRICT`. Eine aktive Dienst- oder
Zugangsschicht und eine Besetzungsannahme sind nicht erforderlich.

Die Felder `estab_shift_id` und `estab_writer_assignment_id` sind
Legacy-Provenienz. Neue manuelle oder automatische ETB-/TBB-Zeilen dürfen sie
`NULL` lassen; eine Zugangsschicht wird dort ausdrücklich nicht eingetragen.
Historische Zeilen mit belegter formaler Dienstschicht beziehungsweise
Dienstbesetzung behalten ihre Werte unverändert und bleiben exportierbar.

### ETB-Inhalt und Kennzeichen

Das ETB speichert fachliche Ereigniszeit und unveränderliche serverseitige
Erfassungszeit getrennt, dazu Darstellung, Bemerkung, handelnde Person,
Kürzel und feste Kontofunktion. Optional sind einsatzsichere Verweise auf
eine Nachricht, einen Anhang, eine lokale ETB-Nummer oder den direkt
berichtigten Originaleintrag möglich.

Neue ETB-Einträge verwenden ausschließlich die Such-/Bewertungskennzeichen:

- `A` – Aufgabe erhalten,
- `B` – Befehl/Auftrag,
- `E` – Erledigung,
- `K` – Kräfteanforderung,
- `W` – sehr wichtig,
- ohne Kennzeichen oder `korrektur` für die entsprechende technische
  Behandlung.

Die Kennzeichen unterstützen Suche und Auswertung. Entsprechend der
Ausbildungsunterlage erscheinen sie nicht als zusätzliche Spalte im
Fb-Fü-2-Ausdruck.

Eine Anlage ist kein Pflichtbestandteil eines ETB-Eintrags. Optional kann die
schreibende Besetzung genau einen fertig hochgeladenen, einsatzgleichen und
noch unbenutzten Anhang auswählen. Erst zusammen mit dem unveränderlichen
Eintrag steht dessen lokale Nummer fest; daraus bildet eStab automatisch
`ETB {einsatz_id}-{estab_book_lfd}-1`. Es gibt genau einen ETB-Buchstrom je
Einsatz, und der ausgewählte Upload gilt als eine zusammengehörige gebündelte
digitale Einheit. Die Einheitenkomponente ist deshalb derzeit immer `1`.
Ein Ablage-/FmZt-Kennzeichen wie `EL0001` bleibt ein davon getrenntes
technisches Ablagekennzeichen und wird nicht zur ETB-Anlagennummer umgedeutet.

Die Auswahlliste zeigt ausschließlich finalisierte Anhänge des aktiven
Einsatzes ohne vorhandene ETB-Zuordnung. Anwendung und Datenbank sperren den
Anhang bis zum Commit; der eindeutige Index auf `estab_attachment_id`
verhindert auch bei Konkurrenz oder umgangener Oberfläche einen Mehrfachlink.
Ein Bestands-Upgrade mit bereits mehrfach verknüpftem Anhang bricht
ausdrücklich ab. Webliste, Fb-Fü-2-PDF und Dossier-Anlagenverzeichnis zeigen
die abgeleitete ETB-Anlagennummer neben dem getrennten Ablagekennzeichen.

Die ETB-Auswertung kombiniert ein Volltextfeld mit Art und „Nummer oder
Bezug“. Ohne Filter wird das vollständige Buch angezeigt; „Alle Arten“ setzt
keine Artgrenze. Gesucht werden Darstellung, Bemerkung, Person, Kürzel,
Referenz sowie Ablage- und Originaldateiname. Der Bezugsfilter versteht lokale
ETB-Nummer, Korrektur-, Nachrichten- und Anhangsbezug, kanonische lokale und
historische Bestandsreferenz sowie die vollständige ETB-Anlagennummer. Alle
Filter bleiben auf den aktiven Einsatz beschränkt.

Zusätzlich gibt es den kombinierbaren Filter „Zuordnung“. Eine optionale
Bearbeitungszuordnung ist ausschließlich Such- und Anzeigehilfe; sie verleiht
keine Rechte. Die Anwendung prüft den Schreibvorgang gegen aktiven Einsatz,
feste Kontofunktion, Rolle und Sperrstatus. Anschließend bleibt der Snapshot
`Funktion (Rolle): Name [Kürzel]`
unveränderlich am ETB-Eintrag. Volltext- und Zuordnungsfilter durchsuchen ihn,
die Webliste zeigt ihn; im amtlichen Fb-Fü-2-PDF erscheint er bewusst nicht.

### Referenzen nach Kapitel 2.3.2

Ein neuer ETB-Bezug ist kein freier Aktenvermerk. Das Eingabefeld akzeptiert
ausschließlich die kanonische positive lokale ETB-Nummer eines bereits
vorhandenen Eintrags desselben aktiven Einsatzes. Führende Nullen, Freitext,
globale Primärschlüssel, Werte außerhalb des Buchnummernbereichs und nicht
vorhandene Ziele werden abgewiesen. Die Zielzeile wird in der
Schreibtransaktion gesperrt. Historischer Freitext bleibt unverändert les- und
suchbar, bildet aber keine nachträglich interpretierte Referenzkante.

Eine Berichtigung speichert intern weiterhin den direkten unveränderlichen
Originaldatensatz; die sichtbare Referenz wird serverseitig aus dessen lokaler
ETB-Nummer abgeleitet. Dadurch kann der Browser weder eine globale technische
ID noch ein anderes Ziel als scheinbaren Korrekturbezug ausgeben.

Die nur lesende Referenzauswertung startet bei einer lokalen ETB-Nummer.
Vorwärts liefert sie alle Einträge, die unmittelbar oder mittelbar darauf
verweisen, einschließlich Verzweigungen; rückwärts folgt sie dem gespeicherten
Bezugspfad zu den Vorgängern. Die maximale Tiefe ist ausdrücklich zwischen 1
und 25 wählbar, weitere Kanten werden als abgeschnitten gekennzeichnet. Eine
separate Druckansicht beschränkt die Ausgabe auf den Referenznachweis.

### TBB-Inhalt und Nachrichtentransport

Das TBB besitzt ebenfalls fachliche Ereigniszeit und unveränderliche
Erfassungszeit. Ein Eintrag muss mindestens einen der folgenden fachlichen
Bereiche enthalten:

- Einsatz-/Betriebsbereitschaft, Personal, Dienst, Ablösung oder Übergabe,
- Kanal, Rufgruppe, Bedienung oder Kanal-/Rufgruppenwechsel,
- Nachricht von/an mit internem, einsatzgleichem Nachrichtenbezug,
- Betriebsablauf, Ereignis, Störung/Unterbrechung und deren Beseitigung,
- Quittung, Empfänger oder Aushändigung.

Die primäre Eintragsart lautet `betrieb_personal`, `kanal`,
`betriebsereignis`, `quittung` oder `korrektur`; sie ist eine technische
Such-/Eingabehilfe und keine zusätzliche Spalte des Fb-Fü-44-Ausdrucks. Der
exakte Typ `nachricht` ist ausschließlich für automatisch und atomar aus dem
Nachrichtenworkflow erzeugte, intern verknüpfte Transporte reserviert und
kann nicht als freier manueller TBB-Eintrag angelegt werden. Historische
unverknüpfte Bestandszeilen bleiben lesbar.
Ein echter Nachrichteneingang erzeugt schon bei der nummerierten Aufnahme
atomar genau einen TBB-Eintrag des Typs `nachricht`. Ein Ausgang erzeugt ihn
erst beim tatsächlichen Übergang durch den Fernmelder auf „befördert“. Weg, Gegenstellen,
Betreff, Bearbeitungs- beziehungsweise Quittungsangaben und Nachrichtenbezug
werden dabei aus dem gesperrten Nachrichtendatensatz übernommen. Generator,
Nachrichtendetail und Dossier suchen ausdrücklich nur nach diesem
automatischen Typ `nachricht`; die zuerst vergebene lokale TBB-Nummer erscheint
anschließend auf dem Nachrichtenvordruck.

Gesprächsnotizen folgen derselben Ausgangsregel. Ihre ursprüngliche
Gesprächsart ist kein Ersatz für den von LdF gewählten S6-Beförderungsweg und
löst weder einen vorzeitigen TBB-Eintrag noch einen PDF-Vordruck aus. Erst der
Beförderungsnachweis des Fernmelders erzeugt atomar den TBB-Eintrag und macht
den abgeschlossenen Vordruck für die PDF-Ausgabe verfügbar. Historische
Gesprächsnotizen in Richtung `E` und Status `8` ohne verknüpften
TBB-Nachrichteneintrag werden weiterhin unverändert dargestellt; aus ihrem
Fehlen wird kein Nachweis rekonstruiert.

Ändert LdF bei einem Eingang den vom Fernmelder erfassten Weg oder übersetzt den
aufgenommenen Rufnamen in einen abweichenden Absender, bleibt der ursprüngliche
TBB-Nachrichtennachweis unverändert. Innerhalb derselben
Nachrichtentransaktion entsteht ein neuer TBB-Eintrag des Typs `korrektur` mit
direktem Bezug auf den ursprünglichen globalen Datensatz und sichtbarem Bezug
auf dessen lokale Nummer. Er enthält vorher/nachher, LdF-Identität und eine
gegebenenfalls erforderliche Begründung; die auf dem Vordruck ausgegebene
ursprüngliche lokale TBB-Nachrichtennummer ändert sich dadurch nicht.

### Abschluss und Berichtigung

Das Buch bleibt einsatz- und führungsstellenbezogen und wird nicht je Schicht
neu begonnen. Historische formale Schichtübergaben und ihre belegten ETB-/TBB-
Zeilen bleiben als Evidenz erhalten; neue Zugangsschichten erzeugen keine
fachlichen Übergabezeilen und bestimmen keine Buchführung.

Der formale Einsatzabschluss schreibt vor der Deaktivierung je einen letzten
ETB- und TBB-Eintrag mit tatsächlichem Ende, Abschlussvermerk und letzter
fachlicher Führung. Erst damit werden beide Bücher gemeinsam mit dem
Gesamteinsatz geschlossen. Frühere Schichtenden schließen kein Buch. Eine
fehlende oder offene historische Dienstschicht und fehlende schichtbezogene
Eröffnungszeilen blockieren den formalen Abschluss nicht.

Bereits gespeicherte ETB- und TBB-Zeilen können weder über die Anwendung noch
per normalem SQL `UPDATE` oder `DELETE` verändert werden. Eine Berichtigung
ist ein neuer Eintrag, der direkt auf den unveränderlichen Originaleintrag
desselben Einsatzes verweist; eine Korrektur einer Korrektur ist gesperrt. Beim
TBB ist zusätzlich eine Korrekturbegründung Pflicht. PDF-Unterschriftslinien
sind für die anschließende manuelle Zeichnung vorhanden; eStab speichert keine
kryptografische oder qualifizierte elektronische Unterschrift.

## Ereignisnachweis und Einsatzabschluss

Alle fachlichen Nachrichtenübergänge erzeugen in derselben
Datenbanktransaktion ein strukturiertes, einsatzgebundenes Ereignis. Der
Nachweis enthält mindestens Nachricht, vorherigen und neuen Zustand,
Ereignistyp, Identität, Funktion, Zeitpunkt und fachliche Zusatzdaten. Die
Ereigniskette ist nur anhängbar; UPDATE und DELETE werden von der Datenbank
abgewiesen.

„Nicht mehr aktiv“ und „formal abgeschlossen“ sind getrennte Zustände. Vor
einem formalen Abschluss prüft eStab offene Nachrichten, offene Melderläufe, noch nicht freigegebene
Kommunikationspläne sowie Verfügbarkeit und Eingangsintegrität neuer Anhänge.
Vor dem Upgrade vorhandene Anhänge werden dabei sichtbar als nicht
rückwirkend belegbarer Legacy-Bestand gezählt. Der Abschluss erfolgt nur nach ausdrücklicher Bestätigung
und wird selbst unveränderbar nachgewiesen. Danach sind operative Änderungen
für diesen Einsatz gesperrt.

Historische formale Dienstschichten oder Besetzungen werden beim
Einsatzabschluss nicht als Blocker behandelt. Der produktive Schließ-POST
übergibt weiterhin ausdrücklich den konfigurierten Anhang-Ablageroot an den
Preflight; er prüft also die realen Dateibytes und nicht nur die formal
vollständigen Datenbankfelder. Der HTTP-Integrationstest verändert unmittelbar
vor diesem POST ein Byte eines Pflichtanhangs bei gleicher Dateigröße.
Erwartet werden HTTP 409 und der Blocker „Anhang-Integritätsfehler“.

Für jeden Einsatz wird ein frühestes Aufbewahrungsende von mindestens zehn
Jahren ab formalem Abschluss gespeichert. Migration 110 verlängert auch
bereits formal geschlossene Einsätze auf mindestens dieses Ende, ohne eine
längere bestehende Frist zu verkürzen. Eine rechtliche beziehungsweise
fachliche Aufbewahrungssperre kann das Löschen darüber hinaus unterbinden.
eStab löscht Einsatzfachdaten nicht automatisch beim Erreichen dieses Datums.

## Feste Kontofunktion, Berechtigungsmodus und optionale Zugangsschichten

Das persönliche Benutzerkonto trägt genau eine feste Funktion. Die Rolle wird
serverseitig aus dieser Funktion und der Empfängermatrix abgeleitet. Im
strengen Modus sind diese beiden Werte die Quelle der funktionsbezogenen
Schreibrechte; eine Sitzung kann nicht zwischen Funktions-Hüten wechseln. Im
lockeren Modus bleiben Funktion und Rolle unveränderte Identitäts- und
Provenienzmerkmale, sperren den Schreibschritt aber nicht. Organisatorische Funktionskombinationen
nach Kapitel 4.3 werden bei Bedarf durch getrennte persönliche Konten
abgebildet. Gemeinsame oder geteilte Kennwörter bleiben unzulässig.

Operative Lese- und Schreibvorgänge verlangen eine gültige Kontositzung, ein
ungesperrtes Konto und einen aktiven Einsatz. Allgemeine Leserechte bleiben
funktions- und objektbezogen. Die vom Modus erfassten Workflow-, ETB-/TTB-,
S6-Plan- und Melder-Schreibvorgänge verlangen in `STRICT` zusätzlich die
fachlich passende feste Funktion/Rolle; in `LOOSE` entfällt nur diese
Bedingung. Rollenstrenge Übersichten, Kategorien- und Administrationsrechte
bleiben ausgenommen. Eine aktive Dienst- oder Zugangsschicht wird nicht verlangt.
Unbekannte neue Schreibendpunkte fallen standardmäßig ebenfalls unter diese
Einsatz- und Kontogrenze und bei fehlendem eindeutigen Modus fail-closed unter
`STRICT`.

Neue und beim Upgrade vorhandene Einsätze stehen standardmäßig auf `STRICT`.
Nur die revisionsgesicherte Administration kann einen offenen Einsatz
umstellen. `LOOSE` verlangt eine ausdrückliche Warnungsbestätigung; jeder echte
Wechsel wird mit Alt-/Neumodus auditiert und in der Statusleiste angezeigt.
CSRF, Eingabevalidierung, Einsatzscoping, Workflowzustände, Sperrinhaber,
Integrität, Ereignisketten, Append-only- und Aufbewahrungsregeln bleiben in
beiden Modi identisch. Rollenstrenge Übersichten, Nachweisung,
Zweitsichtungsarchive, Kategorien- und Administrationsrechte bleiben
ebenfalls unverändert. Eine ausdrücklich gewählte Schreibstufe darf nur die
dazu notwendige Workflow-Objektsicht erhalten; bei einem zurückgewiesenen
Ausgang bewahrt die Evidenz ursprüngliche und neue verantwortliche Funktion.
Für den hier beschriebenen DV-Nachweis ist `STRICT`
verbindlich; ein örtlich freigegebener lockerer Betrieb benötigt eine eigene
organisatorische Zuständigkeits- und Abnahmeregel.

Die Administration kann optional einsatzbezogene Zugangsschichten anlegen und
Konten zuordnen. Ein unzugeordnetes Konto bleibt zugelassen. Bei mehreren
Zuordnungen genügt eine aktive Gruppe (OR-Semantik). Das Aktivieren einer
Gruppe erzeugt keine Sitzung; das Deaktivieren widerruft die Sitzungen der
betroffenen Konten, sofern keine weitere aktive Zuordnung verbleibt.
Zugangsschichten verändern weder Funktion noch Rolle und können keine
Fachrechte verleihen. Die dauerhafte manuelle Kontosperre ist unabhängig und
hat stets Vorrang.

Die vorhandenen Tabellen für formale Dienstschichten, Dienstbesetzungen und
Übergaben bleiben als historische, exportierbare Evidenz bestehen. Sie werden
nicht als aktuelle Autorisierungsquelle verwendet und blockieren weder
operative Eingaben noch den formalen Einsatzabschluss.

## S6-Kommunikationsplanung

Kommunikationspläne sind einsatzgebunden und versioniert. Plan-ID, Einsatz,
Version, Erstellungszeit und Ersteller sind ab Anlage unveränderlich. Nur der
Inhalt eines Entwurfs darf bearbeitet werden; Freigabefelder bleiben bis zur
Veröffentlichung leer. Die Veröffentlichung verlangt ein aktives,
ungesperrtes Konto mit der festen Funktion `S6` sowie mindestens einen
Planweg. Eine
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

Nur ein dafür berechtigtes S6-Konto darf Planinhalte pflegen. Andere
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
ausschließlich ein aktives, ungesperrtes Konto `LdF/Fernmelder`. Ziel und jeder bereits
gesetzte Zeit-, Empfänger- oder Rücknachrichtenbeleg sind bei allen späteren
Statusübergängen unveränderlich. Ein bereits vollständig gemeldeter Lauf
bleibt endgültig und kann nicht erneut disponiert werden.

## Export und Nachweisführung

Der PDF-Einsatzexport verwendet für jede Nachricht denselben
Vierfach-Vordruckrenderer wie „Generierte Vordrucke“. Seine neun wählbaren
Abschnitte sind ETB, TBB, Nachrichtenvordrucke, Anhänge,
Nachrichtenereignisse, Dienstorganisation, S6-Fernmeldepläne, Melderläufe und
Betriebsereignisse. Die Dienstorganisation enthält optionale
Zugangsschichten mit aktuellen/entfernten Zuordnungen und davon getrennt den
historischen formalen `nv_dienst*`-Legacy-Nachweis. Sie werden einsatzgebunden aus einem konsistenten
Datenbanksnapshot ausgegeben. Offene beziehungsweise noch nicht formal
abgeschlossene Einsätze werden im Dossier deutlich als vorläufig bezeichnet.
Führungsstellenname, Einsatzkennung und Einsatzname erscheinen getrennt.
Fehlt der Führungsstellenname in einem historischen Einsatz, lautet die
Kennzeichnung ausdrücklich „historisch nicht erfasst“; Bedarfsträger,
Einsatzleitung und Umgebung werden nicht als Ersatz ausgegeben.

Eine neue Gesprächsnotiz steht den Generatoren erst nach der formalen
Si-Prüfung, der LdF-Disposition von Rufname und aktivem S6-Weg sowie dem
Beförderungsnachweis des Fernmelders zur Verfügung. TBB-Nachweis und
PDF-Vordruck entstehen gemeinsam mit diesem terminalen Abschluss. Historische
Gesprächsnotizen der Richtung `E` in Status `8` bleiben ohne erfundenen
TBB-Nachtrag exportierbar.

Die Anlagensektion stellt JPEG, PNG, GIF und BMP direkt dar und rastert jede
Seite einer PDF-Anlage samt Anmerkungen in Originalreihenfolge. Text wird nur
bei verlustfreier Windows-1252-Darstellbarkeit ausgegeben. TIFF, ZIP, Office,
Video, nicht darstellbarer Text und andere nicht verlässlich statisch
darstellbare Formate erhalten eine eindeutige Hinweisseite. Das gegen
Eingangs-SHA-256 und -größe sowie den MIME-Typ des exakten Byte-Snapshots
geprüfte Original bleibt bei jedem Format bytegleich als `EmbeddedFile`
erhalten; sichtbare Vorschauseiten ersetzen diesen Nachweis nicht.

ETB-Seiten werden als **Fb Fü 2** auf A4 hoch mit den vier Spalten laufende
Nummer, Datum/Uhrzeit, Darstellung der Ereignisse und Bemerkungen erzeugt.
Einsatz, Kopfdatum und ein buchlokaler Seitenzähler werden auf jeder Seite
wiederholt; ebenso die Linien für Leiter/-in Führungsstelle und
ETB-Führer/-in. TBB-Seiten werden als **Fb Fü 44** auf A4 quer mit den sieben
fachlichen Spalten, Fernmeldebetriebsstelle, Arbeitsplatz, buchlokalem
Seitenzähler und LdF-Unterschriftslinie erzeugt. Lange Einträge werden als
Fortsetzung derselben lokalen Nummer über Seiten mit wiederholtem Formkopf
geführt. Im ETB stammt Datum/Uhrzeit aus der unveränderlichen Erfassungszeit
`estab_recorded_at`, ersatzweise aus `etb_time` und erst danach aus der
fachlichen Ereigniszeit. Im TBB wird dagegen die fachliche Vorgangszeit
`estab_event_time` gedruckt, ersatzweise `tbb_time` und erst danach die
Erfassungszeit. Bei formal geschlossenen Einsätzen wird der unbeschriebene
Rest des letzten Formblatts diagonal gestrichen; bei offenen vorläufigen
Abzügen bleibt er frei. Die Formblätter enthalten die textliche THW-Kennung
und das bestehende einfarbige THW-Zahnrad als Kopfzeichen, aber keine digitale
Signatur. Einzelheiten und die automatisierte visuelle Prüfung stehen in
[PDF-Einsatzdossier](PDF-EINSATZDOSSIER.md).

Bei strukturierten TBB-Zeilen unterdrückt der Renderer ausschließlich die
redundante Kompatibilitätszusammenfassung aus `tbb_aktion`. Eine eigenständige
Bemerkung in `tbb_bemerk` bleibt fachlicher Inhalt und erscheint genau einmal
in der Betriebsspalte. ETB-Anlagen werden im Fb Fü 2 mit ihrer automatisch
gebildeten Nummer und im Anlagenverzeichnis zusätzlich mit dem getrennten
Ablagekennzeichen ausgewiesen. Der Export bietet für ETB/TBB entweder das
Gesamtbuch oder für historischen Bestand genau eine frühere formale
Dienstschicht des ausgewählten Einsatzes an. Dieser Legacy-Filter wird im
konsistenten Datenbanksnapshot nochmals gegen den Einsatz geprüft und filtert
ausschließlich ETB und TBB über deren gespeicherte alte Schicht-ID. Neue Zeilen
mit `NULL`-Provenienz und optionale Zugangsschichten gehören nicht zu diesem
Filter. Alle anderen ausgewählten Dossierabschnitte bleiben einsatzweit.
Deckblatt und einsatzgebundener `pdf_export`-Audit nennen den gewählten Umfang
samt historischen Schichtmetadaten. Das Gesamtbuch enthält auch Bestand,
dessen Schichtprovenienz mangels Beleg `NULL` geblieben ist.

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
   Korrektur und erneuter Freigabe. Zusätzlich eine Gesprächsnotiz mit
   getrennter ursprünglicher Gesprächsart, formaler Si-Prüfung, Rufname und
   aktivem S6-Beförderungsweg durch LdF, Beförderungsnachweis durch den
   Fernmelder und nachweislich erst dann erzeugtem TBB-Eintrag und PDF-Vordruck
   prüfen; historische Bestandsnotizen in Richtung `E` und Status `8` müssen
   unverändert les- und exportierbar bleiben.
3. Vollständiger Eingang mit Rufnamenübersetzung und Empfängerzuordnung.
4. Manipulationsversuche gegen Identitätsvermerke, Statusfolge,
   Rollenrechte, Berechtigungsmodus und Einsatzgrenze.
5. Positive und negative Lesetests mit getrennten festen Funktionskonten für
   Nachricht, Vordruck, verknüpften und freien Anhang, Meldungsübersicht,
   Nachweisung, Kategorien sowie ETB/TBB.
6. Pflichtkopf, exakt zwei vorab angelegte Köpfe `ETB:1`/`TTB:1` und die erste
   Buchnummer ohne Schichtvoraussetzung prüfen; ETB-Schreibrecht für
   `ETB/Stab` und `S2/Stab`, TTB-Schreibrecht der Funktion `Fernmelder` positiv und
   für andere Funktionen im strengen Modus negativ nachweisen.
7. ETB-Kennzeichen, alle fünf TBB-Inhaltsbereiche, automatischen
   TBB-Typ `nachricht`, LdF-Nachtrag als direkte Korrektur und unveränderte
   ursprüngliche lokale TBB-Nummer auf dem Vordruck prüfen; zusätzlich leere
   und kombinierte ETB-Suche sowie optionale Anlage mit automatisch
   abgeleiteter Nummer, getrenntem Ablagekennzeichen und gesperrter
   Mehrfachzuordnung nachweisen.
8. Unveränderbarkeit beider Bücher, direkte Korrekturbeziehung und nullable
   Legacy-Schicht-/Schreiberprovenienz prüfen; alte belegte Schichtwerte müssen
   unverändert exportierbar bleiben.
9. Veröffentlichung einer S6-Planfolge und vollständiger Melderlauf.
10. Abschluss-Preflight, automatische ETB-/TBB-Abschlusszeilen, erfolgreichen
    Abschluss ohne frühere Schicht/Bucheröffnung, Schreibsperre, zehnjährige
    Mindestfrist und Aufbewahrungssperre prüfen.
11. PDF-Dossier mit allen neun Abschnitten samt Fb Fü 2/Fb Fü 44,
   ETB-Erfassungs-/TBB-Vorgangszeit, buchlokalen Seitenzählern,
   Fortsetzungsseiten, Unterschriftslinien, gestrichenem Restbereich nur beim
   formalen Abschluss, ETB-Anlagennummer im Formblatt/Anlagenverzeichnis,
   genau einmal ausgegebener `tbb_bemerk` und verifizierten beziehungsweise
   klar als Legacy gekennzeichneten Anhängen sowie Backup-/Restore-Roundtrip.
   Gesamtbuch und bei belegter Legacy-Provenienz eine frühere formale
   Dienstschicht getrennt erzeugen, die ausschließliche ETB-/TTB-Filterung
   sowie Umfang auf Deckblatt und im Audit prüfen; neue `NULL`-Zeilen und die
   übrigen Dossiersektionen müssen einsatzweit im Gesamtbuch bleiben.
12. Browserabnahme aller eingesetzten Rollen in der vorgesehenen
    Zielumgebung.
13. Abweisung sämtlicher operativer Schreibpfade ohne aktiven Einsatz sowie im
    strengen Modus mit fachlich unpassender fester Kontofunktion; dieselben
    Vorgänge ohne aktive
    Schicht müssen zulässig sein. Während eines übernommenen Melderlaufs
    zusätzlich alle fremden operativen Schreibpfade abweisen.
14. Nicht verfügbarer Beförderungsweg mit begründeter Rückgabe an LdF,
    Neudisposition und unverändertem historischen Nachweis.
15. Optionale Zugangsschichten prüfen: unzugeordnetes Konto zulassen,
    Mehrfachzuordnung per OR auswerten, Deaktivierung mit Sitzungswiderruf und
    Aktivierung ohne automatische Anmeldung nachweisen; Funktion und Rolle
    dürfen sich dabei nie ändern, eine manuelle Sperre muss Vorrang behalten.
16. Führungsstellennamen getrennt von Einsatzname, Bedarfsträger und
    Einsatzleitung anlegen; historischen Fehlwert einmalig bestätigen,
    weitere Eingaben vorher und Änderungen nach dem ersten operativen
    Datensatz abweisen sowie Anzeige, Nachrichtenvordruck und Dossier prüfen.
17. Beide Berechtigungsmodi getrennt abnehmen: Bestand und Neuanlage müssen
    standardmäßig streng bleiben; eine unbestätigte oder direkte
    Lockerung muss scheitern. Im lockeren Modus müssen
    funktionsübergreifende Schreibschritte mit konkretem aktivem und
    ungesperrtem Konto möglich sein, während Sitzung, Einsatz, CSRF,
    Workflowzustand, Sperrinhaber, Integrität, Audit und Retention weiterhin
    negative Manipulationstests bestehen. Moduswechsel, Statusanzeige und
    Vorher-/Nachher-Audit kontrollieren und anschließend wieder `STRICT`
    setzen.
18. Die erforderliche formale THW-Freigabe, örtliche Organisationsfreigabe
    und den Umgang mit den nur manuell zu zeichnenden PDF-Unterschriftslinien
    schriftlich dokumentieren.

Die konkreten automatisierten Testdateien und das Freigabeprotokoll stehen in
`FUNKTIONSNACHWEIS.md` und `TESTS-UND-MONITORING.md`.

## Organisatorische Restkontrollen

Software kann die fachliche Beurteilung und Führung nicht ersetzen. Vor Ort
müssen weiterhin geregelt und geübt werden:

- wer für den konkreten Einsatz Leiter der Führungsstelle, S1 bis S6,
  Sichter, LdF, Fernmelder, ETB und Melder ist,
- dass `STRICT` für den Regelbetrieb gilt und wer eine zeitweilige
  `LOOSE`-Entscheidung fachlich freigeben, dokumentieren, überwachen und wieder
  zurücknehmen darf,
- welche Funktionskombinationen lageabhängig zulässig sind,
- wie bei System-, Strom- oder Netzwerkausfall auf Papier zurückgefallen und
  anschließend nacherfasst wird,
- wie Uhrzeitsynchronisation, TLS, Endgeräteschutz, Backup, Restore,
  Zugriffsentzug und Datenschutz betrieben werden,
- welche neuere oder örtlich ergänzende Vorschrift gegenüber der hier
  geprüften Fassung Vorrang hat.
