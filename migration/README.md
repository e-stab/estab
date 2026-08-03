# Herkunft und Migration

Dieses Verzeichnis macht die Überführung des historischen eStab-Projekts von
Subversion nach Git nachvollziehbar. Die vollständige Anwendungshistorie und
ihr Endbestand sind bis zum SVN-Repository-Ende r85 belegt. Die importierten
Anwendungscommits bleiben unverändert; alle Modernisierungen beginnen erst
danach. Die separat versionierte Dokumentation wird ausdrücklich als
bytegenauer Endbestand von r85 übernommen, nicht als erfundene Git-
Einzelcommithistorie.

## Quelle

| Merkmal | Wert |
| --- | --- |
| Repository | `https://svn.code.sf.net/p/estab/svn` |
| UUID | `595569ee-1d76-4581-98bb-cbe32a4b19d9` |
| letzter Repository-Stand | r85 vom 10. Juli 2012 |
| letzter Trunk-Inhalt | r84 vom 10. Oktober 2011 |
| lokale Ausgangskopie | `/Users/adrianboende/git/estab-svn` |
| Anwendungspfad | `/eStab_0.9/trunk` |
| Branch-Pfad | `/eStab_0.9/branch/*` |
| Tag-Pfad | `/eStab_0.9/tags/*` |
| separate Dokumentation | `/eStab_0.9/docu` |

Die auf den ersten Blick widersprüchlichen Datumsangaben stammen aus SVN: r74
benannte den damaligen Projektwurzelpfad per Copy-Historie von `eStab` nach
`eStab_0.9` um. r85 änderte nur die Repository-Struktur; der Anwendungstrunk
wurde zuletzt in r84 verändert.

r85 enthält nur die Logmeldung `eStab 1.0 will use GIT` und keine Änderung am
0.9-Trunk. Deshalb zeigt `svn-r85` auf den letzten in Git darstellbaren
Trunk-Commit r84, während die Provenienz den Repository-Endstand r85 ausweist.

## Import

Verwendet wurde Git 2.55 mit `git svn`:

```console
git svn init \
  --trunk=eStab_0.9/trunk \
  --branches=eStab_0.9/branch/* \
  --tags=eStab_0.9/tags/* \
  --prefix=svn/ \
  https://svn.code.sf.net/p/estab/svn
git svn fetch --authors-file=migration/svn-authors.txt --log-window-size=1000
```

`git svn` folgte der vollständigen Anwendungshistorie und der Copy-Historie
über die Umbenennung zurück zum früheren `/eStab`-Pfad. Die sechs SVN-Tags
wurden als annotierte Git-Tags mit gleichem Namen gesichert. Die vier
historischen Entwicklungszweige liegen unter `legacy/*`. Der Tag `svn-r85`
bezeichnet die belegte Zuordnung zum SVN-Repository-Ende; sein aufgelöster
Anwendungsbaum ist der zuletzt in r84 geänderte Trunk, weil r85 ausschließlich
die oben beschriebene Repository-Struktur änderte.

Der eigenständige SVN-Pfad `/eStab_0.9/docu` war nie Teil des
Anwendungstrunks und wurde daher nicht künstlich in dessen Commitfolge
gemischt. Sein vollständiger Endbestand bei r85 bleibt mit allen 95 Dateien im
Git-Commit `9cd6fc0779ed72181d71aa9042f85c971c92f0c1` unter
`docs/legacy/svn-r85/` erhalten. Der rund 128 MB große Baum wurde anschließend
aus dem aktuellen Arbeitsbaum entfernt. Revisionen und letzter Änderungsstand
bleiben in den Provenienzmetadaten sichtbar; zugesagt wird für diesen
separaten Pfad der verifizierte Endbestand, keine nachträglich konstruierte
Dokument-Einzelhistorie.

## Nachweise

- `verify_svn_refs.py` vergleicht Trunk, alle vier Branches und alle sechs Tags
  bytegenau mit der lokalen SVN-Working-Copy. Dieser Quellvergleich wurde für
  die jetzt festgeschriebenen Refs bei r85 erneut erfolgreich ausgeführt.
- `verify_provenance.py` prüft anschließend ohne SVN-Server und ohne lokale
  SVN-Working-Copy insgesamt 13 Git-Ref-Snapshots – Trunk, vier
  Entwicklungszweige, sechs SVN-Tags und die beiden späteren
  SourceForge-Release-Tags – sowie den im fest gebundenen Commit archivierten
  Dokument-Endbestand gegen die deterministischen Manifeste unter
  `provenance/`.
- `capture_svn_metadata.py` erzeugt ein Manifest der SVN-Properties, leeren
  Verzeichnisse und die Revision-zu-Commit-Tabelle.
- `svn-trunk-r84.sha256` enthält einen sortierten SHA-256-Hash für jede der
  1.683 Dateien des letzten SVN-Trunks.
- `svn-documentation-r85.sha256` enthält dasselbe für alle 95 Dateien der
  separat versionierten Originaldokumentation.
- `svn-ref-verification.txt` ist das protokollierte Ergebnis des vollständigen
  Ref-Vergleichs beim Import.

Der CI-taugliche Nachweis verwendet ein vollständiges Git-Checkout mit allen
historischen Branches und Tags:

```console
python3 migration/verify_provenance.py --self-test
```

Jedes JSONL-Manifest enthält Dateigröße, Modus und SHA-256. Pfade werden
sowohl als strikt validiertes UTF-8 als auch als kanonisches Base64 ihrer
rohen Bytes gespeichert und nach diesen Bytes sortiert. Dadurch bleiben
Leerzeichen, Umlaute und andere Unicode-Zeichen eindeutig. `index.json`
bindet Dateianzahl, Gesamtgröße, Git-Refobjekt, Commit, Tree und
Manifestprüfsummen. Zusätzlich bindet er die protokollierten direkten
SVN-Ref-/Dokumentvergleiche, die beiden ursprünglichen
Trunk-/Dokumentmanifeste und `sourceforge-releases.tsv` per SHA-256;
`index.sha256` versiegelt den Index. Der Selbsttest verändert jeweils einen
Dateihash des SVN-Trunks und eines SourceForge-Release-Tags und versiegelt
Manifest und Index bewusst neu. Die Prüfung muss beide Manipulationen dennoch
am tatsächlichen Git-Ref erkennen. Die minimale PHP-Suite prüft zusätzlich
Index, sämtliche Manifeste und den festgeschriebenen Archiv-Locator und belegt
negative Manifest-, aufgezeichnete Releaseidentitäts- und
Dokumentmanifest-Manipulationen.

Den ursprünglichen Quellvergleich mit der lokalen SVN-Kopie wiederholen:

```console
python3 migration/verify_svn_refs.py \
  /Users/adrianboende/git/estab-svn/eStab_0.9 \
  /Users/adrianboende/git/estab
python3 migration/capture_svn_metadata.py \
  /Users/adrianboende/git/estab-svn \
  /Users/adrianboende/git/estab \
  migration
```

`python3 migration/verify_provenance.py --write` ist keine normale
Testaktion. Es regeneriert den festgeschriebenen Nachweis und darf nur nach
einem erneut erfolgreichen SVN-Quellvergleich und bewusster Prüfung der
geänderten Refidentitäten verwendet werden.

## Bewusste Abbildungen

Git kann leere Verzeichnisse und SVN-Properties nicht direkt speichern. Beide
werden deshalb vollständig in Manifesten festgehalten. Laufzeitverzeichnisse
werden später vom Container-Entrypoint deterministisch angelegt.
`svn:mime-type`, `bugtraq:number` und sämtliche historischen `svn:ignore`-
Werte sind dokumentarisch in `svn-properties.tsv` erhalten. `svn:ignore`
wurde nicht als angeblich identische globale Regel übernommen: Nur heute
zutreffende Laufzeit-, Secret-, Werkzeug- und Betriebssystemmuster wurden
selektiv und an Git angepasst in `.gitignore` übertragen. So bleibt etwa das
historisch ignorierte Handbuch als belegter Bestand versioniert, während
generierte `4fdata`-Daten weiterhin ausgeschlossen sind. Die vier
`svn:mergeinfo`-Werte bleiben ebenfalls im Property-Manifest erhalten.

Die separate, rund 128 MB große Projektdokumentation lag nie im Trunk. Sie ist
als eigener unveränderter r85-Endbestand im Commit
`9cd6fc0779ed72181d71aa9042f85c971c92f0c1` unter
`docs/legacy/svn-r85` archiviert und indexiert, ohne den aktuellen Arbeitsbaum
und das Containerimage zu vergrößern.

## Spätere offizielle Releases

Der SVN-Stand ist nicht der letzte veröffentlichte Programmstand. SourceForge
veröffentlichte später die Archive `ver0.9.26b.zip` und `ver0.9.26c.zip`. Diese
werden als klar gekennzeichnete Snapshot-Commits mit Archivprüfsummen auf den
SVN-Verlauf gesetzt. Es wird keine nicht belegte Zwischenhistorie erfunden.

Die Download-Zeitstempel sowie MD5- und SHA-256-Prüfsummen stehen in
`sourceforge-releases.tsv`. `unzip -t` prüfte beide Archive vollständig. Für die
Git-Snapshots wird jeweils der enthaltene `kats`-Baum übernommen; rein
betriebssystembezogene `.DS_Store`- und AppleDouble-Dateien (`._*` und der
äußere `__MACOSX`-Baum) werden ausgelassen. Fachdateien, IDE-Metadaten und
historische Drittkomponenten bleiben im getaggten Snapshot unverändert.
Der in 0.9.26b zerlegt (NFD) geschriebene Dateiname
`ubltg/Übungsmodul eStab.pap` wird wie von Git auf macOS vorgesehen in die
portable NFC-Form normalisiert; der Dateiinhalt bleibt identisch. 0.9.26c
lieferte denselben Namen bereits in NFC.

Beide Release-Tags sind eigene Subjects des Provenienzprüfers. Das jeweilige
Manifest bindet den nach der dokumentierten Snapshot-Policy übernommenen
`kats`-Inhalt; der Index bindet zusätzlich das vollständige Git-Refobjekt,
Commit und Tree. Der Prüfer liest die aufgezeichnete
Originalarchiv-SHA-256 aus `sourceforge-releases.tsv` und verlangt exakt
denselben Wert im annotierten Tag sowie im Snapshot-Commit. Damit beweist die
CI, dass weder der getaggte Inhalt noch seine gebundene, beim ursprünglichen
Download verifizierte Archividentität gedriftet ist.

Die CI lädt das externe SourceForge-Archiv bewusst nicht erneut herunter und
behauptet deshalb keine neue Netzverifikation des heutigen Downloads. Eine
erneute Prüfung der tatsächlichen Archivbytes erfordert ein separat
bereitgestelltes, anhand der aufgezeichneten SHA-256 geprüftes Archiv und den
folgenden Vergleich.

Ein Snapshot lässt sich gegen ein entpacktes Archiv prüfen, zum Beispiel:

```console
python3 migration/verify_release_snapshot.py /tmp/ver0.9.26c/kats . \
  --ref ver0.9.26c
```
