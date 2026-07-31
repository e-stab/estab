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

| Fundstelle | Anforderung | Technische Umsetzung | Nachweis |
| --- | --- | --- | --- |
| S. 4-44/4-45 | S2 muss ständig in den Informationsfluss eingebunden sein und alle Ein- und Ausgänge als roten Durchschlag erhalten. | S2/Stab ist die einzige Fähigkeit `LAGE_DOKUMENTATION`; jede abgeschlossene Nachricht enthält die S2-Rotkopie, eine beliebige Umkonfiguration oder Autosichtung ist gesperrt. | `tests/php/admin_operations_security.php`, `tests/integration/message_workflow_http.sh`, Schema-Verifikation |
| S. 4-45 sowie Handbuch ETB/TBB S. 6 und 26 | Die Einsatzdokumentation wird durch S2 sichergestellt; das ETB ist urkundlicher Nachweis. Die DV-Fassung nennt ein Jahr, die spätere ETB-/TBB-Unterlage zehn Jahre für ETB und TBB. | S2 bleibt alleinige Lage-/Rotkopiefunktion. Die Anwendung setzt die strengere Mindestaufbewahrung von zehn Jahren ab formalem Abschluss um. ETB und TBB sind nur anhängbar; eine Berichtigung ist ein neuer Eintrag mit Gegenreferenz. Ein Legal Hold kann die Frist verlängern, aber nicht verkürzen. | `tests/php/logbook_security.php`, `tests/php/schema_migration_contract.php`, `tests/integration/schema_migrator.sh`, `tests/integration/dv_evidence.php` |
| S. 4-59/4-60 | S6 plant und führt den Telekommunikationseinsatz und stellt die Führbarkeit über geeignete Verbindungen sicher. | Nur die angenommene aktive S6-Funktion darf versionierte Fernmeldepläne erstellen und veröffentlichen. LdF kann nur einen aktuell gültigen, veröffentlichten Planweg disponieren; veröffentlichte Fassungen bleiben unveränderlich. | `tests/integration/dv_operations.php`, `tests/integration/message_workflow_http.sh` |
| S. 4-63 | Der Sichter analysiert Eingänge inhaltlich und leitet sie an zuständige Bearbeiter weiter. | Eingänge laufen zwingend `A/W → LdF → Si`; Si setzt Empfänger und Abschluss. A/W darf den Absender nicht schreiben, LdF übersetzt den aufgenommenen Rufnamen und bestätigt den von A/W erfassten Eingangsweg. Eine Änderung verlangt eine Begründung und wird mit Alt-/Neuwert und LdF-Identität nachgewiesen; A/W-Aufnahmezeit und -zeichen bleiben unverändert. | `tests/php/workflow_security.php`, `tests/php/ldf_validation_security.php`, `tests/php/ldf_ui_flow_security.php`, `tests/integration/message_workflow_http.sh` |
| S. 4-63 | Bei Ausgängen prüft Si nur Anschrift, Unterschrift/Zeichen und Funktion, nicht den Inhalt. | Ausgänge laufen zwingend `Verfasser → Si → LdF → A/W`. Si kann formal freigeben oder mit Pflichtgrund zurückgeben, aber keine Inhaltsfelder verändern. Nach Korrektur folgt die Sichtung erneut. | `tests/php/message_security.php`, `tests/integration/message_concurrency.php`, `tests/integration/message_workflow_http.sh` |
| S. 4-64 | Der Melder darf den Inhalt nicht ändern, muss schnell zustellen, Rücknachrichten feststellen, zurückkehren, sich zurückmelden und den tatsächlichen Empfänger nennen. | Das Medium `Me` verlangt einen LdF-Auftrag und die Zustandskette Beauftragung, persönliche Übernahme, Übergabe mit Empfänger, Rückweg mit explizitem Rücknachrichtenvermerk und Rückkehr. Danach bestätigt ausschließlich die ausgewählte aktive LdF-Besetzung die Rückmeldung an die FmZt. Der Nachrichtenabschluss wartet auf die vollständige Kette. | `tests/integration/dv_operations.php`, `tests/integration/message_workflow_http.sh` |
| S. 4-64 | Bis zur Rückkehr darf der Melder keine anderen Aufträge annehmen; in einer FüSt mit Stab gehört er zur FmZt und wird durch LdF eingesetzt. | Nur eine angenommene aktive A/W-Besetzung ist als Melder wählbar, ausschließlich LdF beauftragt. Während Übernahme, Übergabe und Rückweg sperrt eine zentrale Request-Grenze alle fremden operativen Schreibvorgänge dieses Kontos. | `tests/php/dv_operations_security.php`, `tests/integration/dv_operations.php` |
| S. 4-64 | LdF verantwortet den Fernmeldebetrieb und unterweist, unterstützt und überwacht das Betriebspersonal. | LdF ist eine gesonderte, schichtgebundene Funktion. Sie übersetzt Rufnamen, entscheidet den Planweg, beauftragt Melder und überwacht die sichtbaren Melderzustände; A/W kann diese Entscheidungen nicht vorwegnehmen. | `tests/integration/message_workflow_http.sh`, `tests/integration/dv_operations.php` |
| S. 4-70 bis 4-73 | Die Führungsstelle besitzt benannte Funktionen; Kombinationen S1/S4, S2/S3 sowie ETB/Si sind möglich. | Persönliche Konten und einsatz-/schichtbezogene Funktions-Hüte sind getrennt. Mehrfachzuweisung ist möglich, jede Zuweisung wird persönlich angenommen; S2, Si, S6, LdF und A/W sind vor Schichtaktivierung Pflicht. Pro Nicht-A/W-Funktion gibt es nur eine aktive Besetzung. Der Datenbanktest wechselt real S2→S3 sowie Si→ETB und prüft Funktionstabellen, Gesprächsvermerk, Gelesen-/Erledigt-Zustand, Kategorien und getrennte Rechte. | `tests/integration/dv_operations.php`, `tests/php/dv_operations_security.php`, Authentifizierungs- und Schema-Verifikation |
| S. 4-73 | Die Arbeitsfähigkeit hängt von zweckmäßiger Organisation und raschem Informationsfluss ab. | Führungsstellenname, Einsatz, aktive Schicht, gewählte Funktion, Warteschlangen und aktuelle Zuständigkeit sind sichtbar. Der Führungsstellenname ist die einsatzbezogene lokale Nachrichtenanschrift/-absendereinheit und von Einsatzname, Bedarfsträger sowie Einsatzleitung getrennt. Ohne aktiven Einsatz, bestätigten Führungsstellennamen oder aktive Schicht wird serverseitig keine operative Eingabe angenommen. | Schema-, Einsatzdomänen-, HTTP- und Browser-Abnahme sowie `tests/integration/message_workflow_http.sh` |

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
Dienstfunktionen lesen. Pro aktiver Schicht ist jedoch nur genau eine
angenommene Besetzung als Schreiber bestimmt: für das ETB die zuerst
zugewiesene ETB-Besetzung, ersatzweise die zuerst zugewiesene S2-Besetzung;
für das TBB die zuerst zugewiesene A/W-Besetzung. Zusätzlich bleiben
`EINSATZTAGEBUCH` beziehungsweise `BEFOERDERUNG` Pflicht. Weitere fachlich
fähige Besetzungen erhalten die Bücher nur lesend. Der statische Vertrag liegt
in `tests/php/read_authorization_security.php` und
`tests/php/logbook_security.php`.

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

### Gemeinsamer Pflichtkopf und Eröffnung

Ein neuer Einsatz kann nur angelegt und die erste Schicht nur aktiviert
werden, wenn folgende Angaben vorhanden sind:

- Einsatzkennung und genaue Einsatzbezeichnung,
- Einsatzbeginn mit Datum und Uhrzeit,
- Bedarfsträger; technisch wird dafür die historische Spalte
  `organisation` verwendet,
- Name der Führungsstelle,
- verantwortliche Einsatz-/Führungsleitung,
- Einsatzauftrag und Ausgangslage.

Die erste Schichtaktivierung schreibt in derselben Datenbanktransaktion die
laufende Nummer 1 beider Bücher. Der ETB-Eröffnungseintrag enthält
Einsatzbezeichnung, den Einsatzbeginn ausdrücklich als Datum/Uhrzeit,
Auftrag/Ausgangslage, Bedarfsträger, Führungspersonal, vollständige angenommene
Führungsstellenbesetzung und ETB-Führung. Der erste TBB-Eintrag dokumentiert
Fernmeldebetriebsstelle, Einsatz-/Betriebsbereitschaft, LdF sowie
A/W-Betriebspersonal und TBB-Führung. Der im Formblatt vorgesehene
Arbeitsplatz wird nicht erfunden: Das Einsatzdatenmodell besitzt dafür kein
eigenes Feld und die Ausbildungsunterlage sieht vor, dass es bei einem TBB je
Fernmeldebetriebsstelle in der Regel frei bleibt.

Der derzeit geprüfte und von eStab unterstützte Produktumfang ist ausschließlich
eine Führungsstelle mit eingerichteter Fernmeldebetriebsstelle. Deshalb sind
LdF und A/W Pflichtbesetzungen und je Einsatz wird genau ein TBB eröffnet und
geführt. Führungsstellen ohne eigene Fernmeldebetriebsstelle, insbesondere ein
reiner ETB-Betrieb, gehören nicht zum unterstützten Produktumfang. Für diesen
abweichenden Aufbau behauptet eStab keine Konformität. Der Begriff
„unterstützter Produktumfang“ bezeichnet dabei ausschließlich die technische
eStab-Produktgrenze und ausdrücklich keine formale THW-Freigabe.

Für vorbereitete Bestands-Einsätze können Bedarfsträger,
Einsatz-/Führungsleitung sowie Auftrag/Ausgangslage in der Administration
ergänzt werden. Sobald eine Schicht erstmals aktiviert wurde oder bereits eine
ETB-/TBB-Zeile existiert, sind diese Kopfangaben als Tatsachengrundlage der
Eröffnung gesperrt. Ein bereits geführtes Bestandsbuch erhält beim Upgrade
keinen nachträglich erfundenen Eröffnungseintrag.

### Lokale Nummern und eine schreibende Besetzung

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

Alle ausgewählten aktiven Dienstfunktionen dürfen beide Bücher lesen. Für
manuelle Einträge bestimmt eStab pro Schicht genau eine schreibende Besetzung:
Beim ETB hat die zuerst zugewiesene angenommene ETB-Funktion Vorrang, sonst die
zuerst zugewiesene angenommene S2-Funktion; beim TBB schreibt die zuerst
zugewiesene angenommene A/W-Funktion. Kontosperre, aktiver Einsatz, aktive
Schicht, persönlich angenommener und ausgewählter Hut sowie die fachliche
Fähigkeit werden bei jedem Schreiben erneut geprüft.

Der Kontozustand gehört bewusst nicht zur Wahl dieser designierten ersten
Besetzung. Wird ihr Konto gesperrt oder deaktiviert, blockiert das Schreiben,
statt unbemerkt auf die nächste geeignete Person zu fallen. Erst eine
dokumentierte Ablösung beziehungsweise Übergabe ändert die Buchführung. Der
Insert-Trigger prüft unabhängig von der Oberfläche aktive einsatzgleiche
Schicht, Status `ANGENOMMEN`, Benutzer-/Kürzel-/Funktionsidentität und ein
aktives, ungesperrtes Konto.

Jede neue manuelle ETB-/TBB-Zeile speichert nicht nur die sichtbare
Bedieneridentität, sondern auch die serverseitig gesperrte aktive
Dienstschicht (`estab_shift_id`) und die tatsächlich schreibende
Dienstbesetzung (`estab_writer_assignment_id`). Automatische Eröffnungs-,
Übergabe-, Nachrichten- und Abschlusszeilen speichern ebenfalls ihre Schicht,
lassen die menschliche Schreiberzuordnung aber `NULL`; das System darf keine
Person für eine automatisch erzeugte Zeile beanspruchen. Migration 111 erfindet
diese Provenienz nicht rückwirkend: Historische Zeilen behalten in den neuen
Feldern `NULL`.

### ETB-Inhalt und Kennzeichen

Das ETB speichert fachliche Ereigniszeit und unveränderliche serverseitige
Erfassungszeit getrennt, dazu Darstellung, Bemerkung, handelnde Person,
Kürzel und ausgewählte Funktion. Optional sind einsatzsichere Verweise auf
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

Zusätzlich gibt es den kombinierbaren Filter „Zuordnung“. Die schreibende
Person kann optional eine bereits angenommene Besetzung der aktiven Schicht
als Bearbeitungs- und Suchhilfe auswählen; ein Freitextfeld gibt es dafür
nicht. Die Anwendung sperrt und prüft die ausgewählte ID im selben
Schreibvorgang erneut gegen Einsatz, aktive Schicht, Status `ANGENOMMEN` und
ein ungesperrtes Konto. Abmeldung und Präsenzstatus machen eine angenommene
Besetzung nicht fachlich ungültig. Anschließend bleibt der Snapshot
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
erst beim tatsächlichen Übergang von A/W auf „befördert“. Weg, Gegenstellen,
Betreff, Bearbeitungs- beziehungsweise Quittungsangaben und Nachrichtenbezug
werden dabei aus dem gesperrten Nachrichtendatensatz übernommen. Generator,
Nachrichtendetail und Dossier suchen ausdrücklich nur nach diesem
automatischen Typ `nachricht`; die zuerst vergebene lokale TBB-Nummer erscheint
anschließend auf dem Nachrichtenvordruck.

Ändert LdF bei einem Eingang den von A/W erfassten Weg oder übersetzt den
aufgenommenen Rufnamen in einen abweichenden Absender, bleibt der ursprüngliche
TBB-Nachrichtennachweis unverändert. Innerhalb derselben
Nachrichtentransaktion entsteht ein neuer TBB-Eintrag des Typs `korrektur` mit
direktem Bezug auf den ursprünglichen globalen Datensatz und sichtbarem Bezug
auf dessen lokale Nummer. Er enthält vorher/nachher, LdF-Identität und eine
gegebenenfalls erforderliche Begründung; die auf dem Vordruck ausgegebene
ursprüngliche lokale TBB-Nachrichtennummer ändert sich dadurch nicht.

### Übergabe, Abschluss und Berichtigung

Bei bestätigter Schichtübergabe entstehen atomar neue ETB- und TBB-Zeilen mit
abgebender und übernehmender Besetzung, beiden persönlich handelnden Personen,
getrennt nachgewiesenem Übergabe-/Übernahmezeitpunkt, Zusammenfassung und
jeweils letzter lokaler Nummer vor der Übergabe. Als Personal werden
ausschließlich nach dem Statuswechsel tatsächlich abgelöste Besetzungen der
alten und angenommene Besetzungen der neuen Schicht aufgenommen; bloß
zugewiesene oder zurückgezogene Planungszeilen werden nicht als eingesetztes
Personal dargestellt. Das Buch selbst verbleibt damit einsatz- und
führungsstellenbezogen und wird nicht je Schicht neu begonnen.

Der formale Einsatzabschluss schreibt vor der Deaktivierung je einen letzten
ETB- und TBB-Eintrag mit tatsächlichem Ende, Abschlussvermerk und letzter
fachlicher Führung. Erst damit werden beide Bücher gemeinsam mit dem
Gesamteinsatz geschlossen. Frühere Schichtenden schließen kein Buch. Ein
Einsatz ohne mindestens eine aktivierte Schicht und ohne exakt je eine
Eröffnungszeile Nummer 1 in ETB und TBB kann nicht formal geschlossen werden.

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

Für jeden Einsatz wird ein frühestes Aufbewahrungsende von mindestens zehn
Jahren ab formalem Abschluss gespeichert. Migration 110 verlängert auch
bereits formal geschlossene Einsätze auf mindestens dieses Ende, ohne eine
längere bestehende Frist zu verkürzen. Eine rechtliche beziehungsweise
fachliche Aufbewahrungssperre kann das Löschen darüber hinaus unterbinden.
eStab löscht Einsatzfachdaten nicht automatisch beim Erreichen dieses Datums.

## Dienstbesetzung und Funktionskombination

Das Benutzerkonto bezeichnet die Person; die einsatz- und schichtbezogene
Dienstbesetzung bezeichnet die ausgeübte Funktion. Beginn, persönliche
Annahme, Ende, personeller Nachfolger und Bemerkung werden nachvollziehbar
gespeichert. Ein ungesperrtes Konto darf bereits offline für eine geplante
Schicht eingeteilt werden. In der Weboberfläche verlangt die persönliche
Annahme eine gültige Anmeldung und ein ungesperrtes Konto. Die gespeicherte
Annahme bleibt nach einer Abmeldung fachlich gültig; weder der widerrufbare
Sitzungsmarker `nv_benutzer.aktiv` noch der 15-Minuten-Präsenzzustand sind
fachliche Gültigkeitsmerkmale. Ein gesperrtes Konto
erfüllt dagegen keine Pflichtbesetzung für Aktivierung oder Übergabe.

Die Administration darf eine bereits aktive Schicht um eine bisher nicht
besetzte Funktion erweitern. Diese Zuweisung ist zunächst nur ein Angebot und
wird erst wirksam, wenn die betroffene Person sie unter ihrem eigenen Konto
annimmt. Die persönliche Annahme wird atomar als neue ETB-Zeile protokolliert;
für LdF oder A/W entsteht zusätzlich eine TBB-Personalzeile. A/W darf zur
personellen Aufstockung mehrfach besetzt sein.
Eine ETB-Ergänzung, die eine angenommene ETB- oder S2-Besetzung als bestimmten
Schreiber verdrängen würde, ist in der aktiven Schicht unzulässig. Die
Anwendung sperrt bereits die neue Zuweisung. Die spätere Annahme einer noch aus
der Planung stammenden ETB-Zuweisung sperren Anwendung und Datenbank-Trigger
unabhängig voneinander. Die ETB-Führung darf nur über eine dokumentierte und
bestätigte Schichtübergabe wechseln.
Jede andere Funktion darf innerhalb derselben aktiven Schicht nur einmal
vorkommen und nicht über die Erweiterung ausgetauscht werden; ein
Personenwechsel erfolgt über die geordnete Schichtübergabe.

Eine Übergabe ist bewusst zweistufig und auf beiden Seiten persönlich. Eine
angemeldete Person muss dafür exakt eine eigene angenommene Besetzung der
aktiven Schicht ausgewählt haben und initiiert die Übergabe mit einer
Zusammenfassung. Dadurch wird noch keine Schicht umgeschaltet. Erst ein
persönlich angemeldetes Konto mit angenommener Funktion in der
Nachfolgeschicht bestätigt die Übernahme. Dann werden die Schichten atomar
gewechselt und jede tatsächlich abgelöste Besetzung mit einer angenommenen
Besetzung derselben Funktion und Rolle in der Nachfolgeschicht verknüpft, auch
wenn eine andere Person übernimmt. Eine fehlerhafte offene Anforderung kann
nur mit Pflichtgrund storniert werden; Initiierung, Stornierung oder
Bestätigung bleiben mit Besetzungsbezug, Akteur und Zeitpunkt erhalten.

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
Führungsstellenname, Einsatzkennung und Einsatzname erscheinen getrennt.
Fehlt der Führungsstellenname in einem historischen Einsatz, lautet die
Kennzeichnung ausdrücklich „historisch nicht erfasst“; Bedarfsträger,
Einsatzleitung und Umgebung werden nicht als Ersatz ausgegeben.

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
Gesamtbuch oder genau eine Dienstschicht des ausgewählten Einsatzes an. Die
Schichtwahl wird im konsistenten Datenbanksnapshot nochmals gegen den Einsatz
geprüft und filtert ausschließlich ETB und TBB über deren gespeicherte
Schicht-ID. Alle anderen ausgewählten Dossierabschnitte bleiben einsatzweit.
Deckblatt und einsatzgebundener `pdf_export`-Audit nennen den gewählten Umfang
samt Schichtmetadaten. Das Gesamtbuch enthält auch historischen Bestand,
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
   Korrektur und erneuter Freigabe.
3. Vollständiger Eingang mit Rufnamenübersetzung und Empfängerzuordnung.
4. Manipulationsversuche gegen Identitätsvermerke, Statusfolge,
   Rollenrechte und Einsatzgrenze.
5. Positive und negative Lesetests mit mehreren ausgewählten Funktions-Hüten
   für Nachricht, Vordruck, verknüpften und freien Anhang, Meldungsübersicht,
   Nachweisung, Kategorien sowie ETB/TBB.
6. Pflichtkopf, exakt zwei vorab angelegte Köpfe `ETB:1`/`TTB:1` und atomare
   ETB-/TBB-Eröffnung bei der ersten Schicht einschließlich des ausdrücklich
   ausgeschriebenen Einsatzbeginns im ersten ETB-Text sowie genau eine
   schreibende Besetzung je Buch prüfen.
7. ETB-Kennzeichen, alle fünf TBB-Inhaltsbereiche, automatischen
   TBB-Typ `nachricht`, LdF-Nachtrag als direkte Korrektur und unveränderte
   ursprüngliche lokale TBB-Nummer auf dem Vordruck prüfen; zusätzlich leere
   und kombinierte ETB-Suche sowie optionale Anlage mit automatisch
   abgeleiteter Nummer, getrenntem Ablagekennzeichen und gesperrter
   Mehrfachzuordnung nachweisen.
8. Unveränderbarkeit beider Bücher, direkte Korrekturbeziehung und atomare
   beidseitig persönliche Schichtübergabe mit echten Statusbesetzungen und
   letzter lokaler Nummer prüfen; in einer aktiven Schicht eine neue Funktion
   persönlich annehmen, ihren ETB-/gegebenenfalls TBB-Nachweis prüfen, A/W
   aufstocken und den Austausch einer anderen bereits besetzten Funktion
   abweisen.
9. Veröffentlichung einer S6-Planfolge und vollständiger Melderlauf.
10. Abschluss-Preflight, automatische ETB-/TBB-Abschlusszeilen,
   Abweisung eines Abschlusses vor erster Schicht/Bucheröffnung,
   Schreibsperre, zehnjährige Mindestfrist und Aufbewahrungssperre prüfen.
11. PDF-Dossier mit allen neun Abschnitten samt Fb Fü 2/Fb Fü 44,
   ETB-Erfassungs-/TBB-Vorgangszeit, buchlokalen Seitenzählern,
   Fortsetzungsseiten, Unterschriftslinien, gestrichenem Restbereich nur beim
   formalen Abschluss, ETB-Anlagennummer im Formblatt/Anlagenverzeichnis,
   genau einmal ausgegebener `tbb_bemerk` und verifizierten beziehungsweise
   klar als Legacy gekennzeichneten Anhängen sowie Backup-/Restore-Roundtrip.
   Gesamtbuch und eine einzelne Dienstschicht getrennt erzeugen, die
   ausschließliche ETB-/TTB-Filterung sowie Umfang auf Deckblatt und im Audit
   prüfen; die übrigen Dossiersektionen müssen einsatzweit bleiben.
12. Browserabnahme aller eingesetzten Rollen in der vorgesehenen
    Zielumgebung.
13. Abweisung sämtlicher operativer Lese- und Schreibpfade ohne ausgewählte
    aktive Dienstfunktion; während eines übernommenen Melderlaufs zusätzlich
    Abweisung aller fremden operativen Schreibpfade.
14. Nicht verfügbarer Beförderungsweg mit begründeter Rückgabe an LdF,
    Neudisposition und unverändertem historischen Nachweis.
15. Pre-Hat-Prüfung: ausschließlich Führungsstellen-Grunddaten, eigene
    Annahme/Übergabebestätigung und Auswahl einer eigenen Besetzungs-ID sind
    möglich; fremde oder abgelaufene IDs sowie Plan-, Melder-, Nachrichten-,
    Anhangs- und Logbuchzugriffe bleiben gesperrt.
16. Führungsstellennamen getrennt von Einsatzname, Bedarfsträger und
    Einsatzleitung anlegen; historischen Fehlwert einmalig bestätigen,
    weitere Eingaben vorher und Änderungen nach dem ersten operativen
    Datensatz abweisen sowie Anzeige, Nachrichtenvordruck und Dossier prüfen.
17. Die erforderliche formale THW-Freigabe, örtliche Organisationsfreigabe
    und den Umgang mit den nur manuell zu zeichnenden PDF-Unterschriftslinien
    schriftlich dokumentieren.

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
