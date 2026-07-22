# Herkunft und Migration

Dieses Verzeichnis macht die Überführung des historischen eStab-Projekts von
Subversion nach Git nachvollziehbar. Die importierten SVN-Commits bleiben
unverändert; alle Modernisierungen beginnen erst danach.

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

`git svn` folgte der Copy-Historie über die Umbenennung zurück zum früheren
`/eStab`-Pfad. Die sechs SVN-Tags wurden als annotierte Git-Tags mit gleichem
Namen gesichert. Die vier historischen Entwicklungszweige liegen unter
`legacy/*`. Der Tag `svn-r85` markiert den letzten SVN-Repository-Stand; sein
Trunk-Baum entspricht r84.

## Nachweise

- `verify_svn_refs.py` vergleicht Trunk, alle vier Branches und alle sechs Tags
  bytegenau mit der lokalen SVN-Working-Copy.
- `capture_svn_metadata.py` erzeugt ein Manifest der SVN-Properties, leeren
  Verzeichnisse und die Revision-zu-Commit-Tabelle.
- `svn-trunk-r84.sha256` enthält einen sortierten SHA-256-Hash für jede der
  1.683 Dateien des letzten SVN-Trunks.
- `svn-documentation-r85.sha256` enthält dasselbe für alle 95 Dateien der
  separat versionierten Originaldokumentation.
- `svn-ref-verification.txt` ist das protokollierte Ergebnis des vollständigen
  Ref-Vergleichs beim Import.

Prüfung wiederholen:

```console
python3 migration/verify_svn_refs.py \
  /Users/adrianboende/git/estab-svn/eStab_0.9 \
  /Users/adrianboende/git/estab
python3 migration/capture_svn_metadata.py \
  /Users/adrianboende/git/estab-svn \
  /Users/adrianboende/git/estab \
  migration
```

## Bewusste Abbildungen

Git kann leere Verzeichnisse und SVN-Properties nicht direkt speichern. Beide
werden deshalb in Manifesten festgehalten. Laufzeitverzeichnisse werden später
vom Container-Entrypoint deterministisch angelegt. `svn:mime-type` und
`bugtraq:number` sind dokumentarisch; `svn:ignore` wird in die moderne
`.gitignore` übertragen. Die vier `svn:mergeinfo`-Werte bleiben im
Property-Manifest erhalten.

Die separate, rund 128 MB große Projektdokumentation lag nie im Trunk. Sie wird
als eigener unveränderter Bestand unter `docs/legacy` übernommen und indexiert.

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

Ein Snapshot lässt sich gegen ein entpacktes Archiv prüfen, zum Beispiel:

```console
python3 migration/verify_release_snapshot.py /tmp/ver0.9.26c/kats . \
  --ref ver0.9.26c
```
