# Release technisch prüfen

Diese Datei beschreibt nur die technische Prüfung eines veröffentlichten
Releasepakets. Sie ist keine fachliche oder organisatorische Freigabe.

## Installationspaket

```console
sha256sum --check estab-RELEASE.tar.gz.sha256
tar -xzf estab-RELEASE.tar.gz
cd estab-RELEASE
sha256sum --check SHA256SUMS
sh ./verify-release.sh --inspect-images
```

`RELEASE` muss Git-Tag, Git-Commit sowie die digestgebundenen App- und
Migrator-Images enthalten. `compose.yaml` und `.env.example` müssen
dieselben Referenzen verwenden.

## GitHub-Attestierungen

Auf einer Admin-Workstation mit GitHub CLI:

```console
gh attestation verify oci://APP-IMAGE@sha256:DIGEST \
  --repo e-stab/estab \
  --signer-workflow publish-images.yml

gh attestation verify oci://MIGRATOR-IMAGE@sha256:DIGEST \
  --repo e-stab/estab \
  --signer-workflow publish-images.yml
```

Die erwarteten Digests stehen in `RELEASE`. Bei einer eigenen
Vertrauenswurzel sind die dafür vorgesehenen Optionen der eingesetzten
`gh`-Version zu verwenden; eine fehlgeschlagene Attestierungsprüfung darf
nicht ignoriert werden.

## Offline-Archiv

```console
sh ./offline-images.sh verify /absoluter/pfad/estab-images
```

Das Archiv enthält App, Migrator und Datenbank einschließlich aller
Plattformmanifeste. `database.oci.tar` muss exakt dem Wert
`services.db.image` aus `compose.yaml` entsprechen.

Beim Übertragen in eine interne Registry müssen Digests erhalten bleiben:

```console
skopeo copy --all --preserve-digests \
  oci-archive:app.oci.tar \
  docker://registry.example.org/estab/app@sha256:DIGEST
```

Danach `offline-images.sh check-mirror` ausführen und in `.env`
ausschließlich die geprüften Digestreferenzen verwenden.

## Abbruchkriterium

Nicht installieren, wenn Prüfsumme, Releaseidentität, Attestierung,
Plattformliste oder Image-Digest abweichen. Ein neues, vollständig
veröffentlichtes Release verwenden; Paketdateien nicht lokal reparieren.
