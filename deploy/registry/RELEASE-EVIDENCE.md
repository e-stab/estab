# Dauerhafte Release-Evidence und Offline-Vorsorge

Zu jedem veröffentlichten Release gehören vier dauerhafte GitHub-Release-
Assets:

- `estab-<TAG>.tar.gz` und dessen äußere SHA-256-Datei;
- `estab-<TAG>-evidence.tar.gz` und dessen äußere SHA-256-Datei.

Das Evidence-Archiv enthält die erfolgreichen nativen Prüfungen für
`linux/amd64` und `linux/arm64`, rohe OCI-Index- und Manifestdaten, SPDX-SBOM,
SLSA-Provenance, Trivy-Ergebnisse, Testprotokolle, die strukturierten
GitHub-Attestationsprüfungen, die dabei verwendeten Attestationsbundles und
einen zum Releasezeitpunkt bezogenen Sigstore-Trusted-Root. `SHA256SUMS`
bindet jede Datei im Archiv. Die auf 90 Tage begrenzten Actions-Artefakte sind
nur eine zusätzliche Kopie; das Release-Asset ist die dauerhafte Ablage.
Die Repository-Einstellung **Immutable releases** ist ein verpflichtendes
Publish-Gate. Nach der Sichtbarkeit muss GitHub den Release als `isImmutable`
ausweisen; zusätzlich prüft der Workflow die von GitHub erzeugte
Release-Attestation mit `gh release verify` und jede der vier lokalen
Originaldateien mit `gh release verify-asset`. Dadurch können Tag und die vier
Assets nach der Veröffentlichung nicht mehr geändert oder gelöscht werden.
Beide Nachprüfungen werden wegen möglicher kurzer GitHub-Verzögerungen
begrenzt wiederholt und brechen danach geschlossen ab.
Der Policy-Check benötigt die Repositoryvariable
`ESTAB_RELEASE_TAG_RULESET_ID` mit der positiven ID des aktiven tagweiten
Rulesets sowie das environmentgeschützte Secret
`ESTAB_RELEASE_POLICY_TOKEN`. Das Ruleset muss `~ALL` umfassen, Update und
Löschen verbieten, Erstellung erlauben und eine leere `bypass_actors`-Liste
haben. GitHub liefert diese vollständige Liste nur an Aufrufer mit
Ruleset-Schreibzugriff; das fein abgestufte Token benötigt deshalb für genau
dieses Repository **Administration: write**. Es wird ausschließlich den drei
Policy-Schritten übergeben, nie jobweit exportiert, regelmäßig rotiert und
durch den Required Reviewer des Environments geschützt.

Download und Prüfung auf einer Admin-Workstation:

```sh
release=RELEASE
evidence=estab-$release-evidence
gh release download "$release" \
  --repo e-stab/estab \
  --pattern "$evidence.tar.gz" \
  --pattern "$evidence.tar.gz.sha256"
sha256sum --check "$evidence.tar.gz.sha256"
tar -xzf "$evidence.tar.gz"
cd "$evidence"
sha256sum --check SHA256SUMS
```

Auf macOS wird `shasum -a 256 --check` verwendet.

## Verpflichtende Prüfung auf einer Admin-Workstation

Die Installation auf NAS oder Zielgerät setzt weder GitHub CLI noch
Registry-Schreibrechte voraus. Deshalb ist die kryptografische
Attestationsprüfung kein stiller Installer-Schritt. Vor Freigabe eines neuen
Releases prüft eine aktuelle, online angebundene Admin-Workstation beide
Digestreferenzen aus `EVIDENCE` erneut:

```sh
gh attestation verify \
  "oci://ghcr.io/e-stab/estab@sha256:<APP-DIGEST>" \
  --repo e-stab/estab \
  --signer-workflow e-stab/estab/.github/workflows/publish-images.yml \
  --bundle-from-oci \
  --source-digest "<GIT-COMMIT>" \
  --format=json >admin-app-attestation.json

gh attestation verify \
  "oci://ghcr.io/e-stab/estab-migrate@sha256:<MIGRATOR-DIGEST>" \
  --repo e-stab/estab \
  --signer-workflow e-stab/estab/.github/workflows/publish-images.yml \
  --bundle-from-oci \
  --source-digest "<GIT-COMMIT>" \
  --format=json >admin-migrate-attestation.json
```

Nur Exitcode 0 und jeweils ein nicht leeres JSON-Array gelten als Freigabe.
Die beiden Ausgaben werden zusammen mit Datum, Git-Tag und prüfenden
Administrator in der organisationsinternen, unveränderbaren
Freigabedokumentation abgelegt. Der Publish-Workflow führt denselben gebundenen
Check aus; seine vollständigen JSON-Ausgaben liegen dauerhaft im
Evidence-Release-Asset unter `trust/`.

Für einen netzlosen Wiederholungscheck enthält das Archiv außerdem
`trust/*-attestations.jsonl`, `trust/trusted-root.jsonl` und die rohen
OCI-Indexdateien. GitHub dokumentiert dafür `gh attestation verify` mit
`--bundle` und `--custom-trusted-root`. Ein gespeicherter Trusted-Root kann
spätere Schlüsselwiderrufe oder -rotationen nicht erkennen. Bei vorhandener
Netzverbindung ist deshalb immer die vorstehende frische Online-Prüfung
verpflichtend; Offline-Verifikation ist eine zusätzliche
Disaster-Recovery-Eigenschaft, kein gleichwertiger Aktualitätsnachweis.

## Multi-Arch-OCI-Archive

`offline-images.sh` benötigt `skopeo`, `jq` und ein SHA-256-Werkzeug. Es
verwendet bewusst das OCI-Archivformat; ein Docker-Archiv kann einen
Multi-Arch-Index und dessen ursprünglichen Digest nicht zuverlässig erhalten.
Nach `cp .env.example .env` und erfolgreichem `verify-release.sh`:

```sh
archive_parent=/srv/estab-dr
install -d -m 0700 "$archive_parent"
archive="$archive_parent/estab-RELEASE-images"
sh ./offline-images.sh export "$archive"
sh ./offline-images.sh verify "$archive"
```

Das Archivziel muss ein absoluter, kontrollzeichenfreier Pfad ohne leere,
`.`- oder `..`-Komponenten und ohne Doppelpunkt sein. Sein direkter
Elternordner muss bereits als echtes, dem Operator gehörendes Verzeichnis mit
exakt Modus `0700` und ohne erweiterte POSIX-, macOS- oder Synology-DSM-ACL
existieren; `/` ist verboten. Skopeos
`oci-archive:`-Transport beendet den lokalen Pfad am ersten Doppelpunkt,
weshalb ein solcher Pfad strikt abgewiesen wird. Das Exportziel darf noch
nicht vorhanden sein. Der Helfer reserviert es selbst mit Modus `0700`, bindet
es an Device/Inode/Eigentümer/Modus und markiert es bis zum vollständigen
Abschluss als unvollständig. Bei Fehler oder Signal bleibt dieser eindeutig
erkennbare Teilstand zur kontrollierten Untersuchung erhalten.

Der Export verwendet für App, Migrator und die in der
prüfsummengebundenen `compose.yaml` festgelegte MariaDB-Basis
`skopeo copy --all --preserve-digests`. Die Datenbankreferenz wird eindeutig
aus `services.db.image` gelesen und auf das offizielle Repository sowie einen
exakten `sha256`-Digest begrenzt. Der Helfer bricht ab, wenn ein Digest nicht
erhalten werden kann, prüft anschließend erneut jeden Indexdigest und
verlangt je genau ein `linux/amd64`- und `linux/arm64`-Manifest.
`OFFLINE-IMAGES` und `SHA256SUMS` binden Tag, Commit, Quellreferenzen und die
drei Archive `app.oci.tar`, `migrate.oci.tar` und `database.oci.tar`.
Ungebundene zusätzliche Dateien oder Verzeichnisse lassen die Prüfung
geschlossen fehlschlagen. Der Archivordner gehört auf getrennten, regelmäßig
lesend geprüften DR-Speicher.

## Administrativ bereitgestellte Registry prüfen

`offline-images.sh` schreibt selbst keine Registry-Tags und kann deshalb
keinen konkurrierenden Writer überschreiben. Das Einspielen der drei
OCI-Archive erfolgt außerhalb des Helfers durch eine Registryadministration
in einen dedizierten, serverseitig gegen Überschreiben und Löschen geschützten
Namespace. Kennwörter oder Tokens gehören nie in Befehlsargumente. Nach dem
Import prüft der Helfer ausschließlich die drei erwarteten Digestreferenzen:

```sh
mirror_prefix=registry.example.org/estab-dr
sh ./offline-images.sh check-mirror "$archive" "$mirror_prefix"
```

Der Prefix muss mit einem expliziten Registry-Host beginnen: `localhost`, ein
DNS-Name mit Punkt oder ein Host mit numerischem Port. Hostlose Werte wie
`team/estab-dr` sind verboten, weil der Docker-Transport sie sonst als
`docker.io/team/estab-dr` interpretieren würde.

`check-mirror` liest ausschließlich
`<prefix>/estab@sha256:…`, `<prefix>/estab-migrate@sha256:…` und
`<prefix>/estab-db@sha256:…`, prüft deren vollständigen Multi-Arch-Index und
vergleicht die Manifestdigests mit dem gebundenen Archiv. Es vertraut keinem
Tag. Der Check beweist die Verfügbarkeit zum Prüfzeitpunkt; Registry-Löschung
oder Garbage Collection kann diese später ändern. Die separat gelagerten und
regelmäßig verifizierten OCI-Archive bleiben deshalb der dauerhafte
Disaster-Recovery-Bestand.

Das veröffentlichte eStab-Installationspaket ist absichtlich an die
kanonischen GHCR-Referenzen gebunden. Ein Registry-Check autorisiert keine
manuelle Änderung von `.env`, `RELEASE` oder `SHA256SUMS`. Ein produktiver
Wechsel auf eine andere Registry benötigt ein neu erzeugtes und regulär
freigegebenes Releasepaket, das diese Zielreferenzen ausdrücklich bindet.

Referenzen:

- GitHub CLI: `gh attestation verify`, `gh attestation download` und
  Offline-Verifikation sowie `gh release verify` und
  `gh release verify-asset`;
- GitHub: Immutable Releases, Repository-Rulesets sowie
  `gh release verify`;
- Skopeo-Handbuch: `copy --all` kopiert den gesamten Image-Index,
  `--preserve-digests` bricht ab, falls die Digests nicht erhalten werden.
