"""Generate and verify the self-contained historical source provenance.

The committed manifests were generated from Git refs which had first been
compared byte-for-byte with the local Subversion working copy at r85.  Normal
CI does not need that working copy: it checks the pinned Git objects and the
historical documentation subtree against the deterministic manifests.
"""

from __future__ import annotations

import argparse
import base64
import csv
import hashlib
import io
import json
import os
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
from collections.abc import Iterable
from dataclasses import dataclass
from pathlib import Path
from typing import Any

FORMAT = "estab-source-provenance-v1"
SVN_REPOSITORY = "https://svn.code.sf.net/p/estab/svn"
SVN_UUID = "595569ee-1d76-4581-98bb-cbe32a4b19d9"
SNAPSHOT_REVISION = 85
SOURCE_EVIDENCE_FILES = (
    "migration/sourceforge-releases.tsv",
    "migration/svn-ref-verification.txt",
    "migration/svn-documentation-verification.txt",
    "migration/svn-trunk-r84.sha256",
    "migration/svn-documentation-r85.sha256",
)


@dataclass(frozen=True)
class Subject:
    identifier: str
    kind: str
    manifest: str
    source_path: str
    last_changed_revision: int | None
    git_ref: str | None = None
    ref_candidates: tuple[str, ...] = ()
    workspace_path: str | None = None
    source_kind: str = "svn"
    release_version: str | None = None
    archive_sha256: str | None = None
    excluded_root_entries: tuple[str, ...] = ()
    archive_git_commit: str | None = None
    archive_git_subtree: str | None = None


SUBJECTS = (
    Subject(
        "application-trunk-r84",
        "git",
        "application-trunk-r84.jsonl",
        "/eStab_0.9/trunk",
        84,
        "svn-r85",
        ("refs/tags/svn-r85",),
    ),
    Subject(
        "legacy-branch-0.9.20_bugfix",
        "git",
        "legacy-branch-0.9.20_bugfix.jsonl",
        "/eStab_0.9/branch/0.9.20_bugfix",
        69,
        "legacy/0.9.20_bugfix",
        (
            "refs/heads/legacy/0.9.20_bugfix",
            "refs/remotes/origin/legacy/0.9.20_bugfix",
        ),
    ),
    Subject(
        "legacy-branch-0.9.20_buttons_rework",
        "git",
        "legacy-branch-0.9.20_buttons_rework.jsonl",
        "/eStab_0.9/branch/0.9.20_buttons_rework",
        21,
        "legacy/0.9.20_buttons_rework",
        (
            "refs/heads/legacy/0.9.20_buttons_rework",
            "refs/remotes/origin/legacy/0.9.20_buttons_rework",
        ),
    ),
    Subject(
        "legacy-branch-0.9.20_kto_usr_fkt",
        "git",
        "legacy-branch-0.9.20_kto_usr_fkt.jsonl",
        "/eStab_0.9/branch/0.9.20_kto_usr_fkt",
        50,
        "legacy/0.9.20_kto_usr_fkt",
        (
            "refs/heads/legacy/0.9.20_kto_usr_fkt",
            "refs/remotes/origin/legacy/0.9.20_kto_usr_fkt",
        ),
    ),
    Subject(
        "legacy-branch-0.9.20_ticket20",
        "git",
        "legacy-branch-0.9.20_ticket20.jsonl",
        "/eStab_0.9/branch/0.9.20_ticket20",
        58,
        "legacy/0.9.20_ticket20",
        (
            "refs/heads/legacy/0.9.20_ticket20",
            "refs/remotes/origin/legacy/0.9.20_ticket20",
        ),
    ),
    Subject(
        "legacy-tag-ver0.9.09",
        "git",
        "legacy-tag-ver0.9.09.jsonl",
        "/eStab_0.9/tags/ver0.9.09",
        4,
        "ver0.9.09",
        ("refs/tags/ver0.9.09",),
    ),
    Subject(
        "legacy-tag-ver0.9.10",
        "git",
        "legacy-tag-ver0.9.10.jsonl",
        "/eStab_0.9/tags/ver0.9.10",
        7,
        "ver0.9.10",
        ("refs/tags/ver0.9.10",),
    ),
    Subject(
        "legacy-tag-ver0.9.11",
        "git",
        "legacy-tag-ver0.9.11.jsonl",
        "/eStab_0.9/tags/ver0.9.11",
        10,
        "ver0.9.11",
        ("refs/tags/ver0.9.11",),
    ),
    Subject(
        "legacy-tag-ver0.9.12",
        "git",
        "legacy-tag-ver0.9.12.jsonl",
        "/eStab_0.9/tags/ver0.9.12",
        13,
        "ver0.9.12",
        ("refs/tags/ver0.9.12",),
    ),
    Subject(
        "legacy-tag-ver0.9.20",
        "git",
        "legacy-tag-ver0.9.20.jsonl",
        "/eStab_0.9/tags/ver0.9.20",
        31,
        "ver0.9.20",
        ("refs/tags/ver0.9.20",),
    ),
    Subject(
        "legacy-tag-ver0.9.20b",
        "git",
        "legacy-tag-ver0.9.20b.jsonl",
        "/eStab_0.9/tags/ver0.9.20b",
        72,
        "ver0.9.20b",
        ("refs/tags/ver0.9.20b",),
    ),
    Subject(
        "legacy-documentation-r85",
        "filesystem",
        "legacy-documentation-r85.jsonl",
        "/eStab_0.9/docu",
        47,
        workspace_path="docs/legacy/svn-r85",
        archive_git_commit=(
            "9cd6fc0779ed72181d71aa9042f85c971c92f0c1"
        ),
        archive_git_subtree="docs/legacy/svn-r85",
    ),
    Subject(
        "sourceforge-release-ver0.9.26b",
        "git",
        "sourceforge-release-ver0.9.26b.jsonl",
        "ver0.9.26b.zip:kats/",
        None,
        "ver0.9.26b",
        ("refs/tags/ver0.9.26b",),
        source_kind="sourceforge-release",
        release_version="0.9.26b",
        archive_sha256=(
            "fcedda942ff783141a75c806dfc89a2045ad74929d015185959518339de5c81d"
        ),
        excluded_root_entries=(".gitignore", ".mailmap", "migration"),
    ),
    Subject(
        "sourceforge-release-ver0.9.26c",
        "git",
        "sourceforge-release-ver0.9.26c.jsonl",
        "ver0.9.26c.zip:kats/",
        None,
        "ver0.9.26c",
        ("refs/tags/ver0.9.26c",),
        source_kind="sourceforge-release",
        release_version="0.9.26c",
        archive_sha256=(
            "8376c58cfd5e57c3a9c24f56a2148088afbb98eb425fc2eb166f815cdbf06041"
        ),
        excluded_root_entries=(".gitignore", ".mailmap", "migration"),
    ),
)


class ProvenanceError(RuntimeError):
    """A provenance invariant was violated."""


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical_json(value: object) -> bytes:
    return (
        json.dumps(
            value,
            ensure_ascii=False,
            separators=(",", ":"),
            sort_keys=True,
        ).encode("utf-8")
        + b"\n"
    )


def run_git(repository: Path, arguments: list[str]) -> bytes:
    try:
        return subprocess.run(
            ["git", *arguments],
            cwd=repository,
            check=True,
            capture_output=True,
        ).stdout
    except FileNotFoundError as error:
        raise ProvenanceError("git is required for full provenance verification") from error
    except subprocess.CalledProcessError as error:
        detail = error.stderr.decode("utf-8", "replace").strip()
        raise ProvenanceError(
            f"git {' '.join(arguments)} failed: {detail or 'unknown error'}"
        ) from error


def release_metadata(repository: Path, subject: Subject) -> dict[str, object]:
    if subject.source_kind != "sourceforge-release":
        return {}
    assert subject.release_version is not None
    assert subject.archive_sha256 is not None
    table_path = repository / "migration" / "sourceforge-releases.tsv"
    try:
        with table_path.open("r", encoding="utf-8", newline="") as stream:
            reader = csv.DictReader(stream, delimiter="\t")
            required_fields = [
                "version",
                "released_utc",
                "archive_bytes",
                "md5",
                "sha256",
                "source_url",
                "snapshot_policy",
            ]
            if reader.fieldnames != required_fields:
                raise ProvenanceError(
                    "sourceforge-releases.tsv has an unexpected header"
                )
            matches = [
                row for row in reader if row.get("version") == subject.release_version
            ]
    except (OSError, UnicodeError, csv.Error) as error:
        raise ProvenanceError("cannot read sourceforge-releases.tsv") from error
    if len(matches) != 1:
        raise ProvenanceError(
            f"{subject.identifier}: expected exactly one SourceForge release record"
        )
    row = matches[0]
    if row["sha256"] != subject.archive_sha256:
        raise ProvenanceError(
            f"{subject.identifier}: documented archive SHA-256 differs from contract"
        )
    if (
        re.fullmatch(r"[0-9]+", row["archive_bytes"]) is None
        or int(row["archive_bytes"]) <= 0
        or re.fullmatch(r"[0-9a-f]{32}", row["md5"]) is None
        or re.fullmatch(r"[0-9a-f]{64}", row["sha256"]) is None
        or row["source_url"] == ""
        or row["released_utc"] == ""
        or row["snapshot_policy"] == ""
    ):
        raise ProvenanceError(
            f"{subject.identifier}: invalid SourceForge release metadata"
        )
    return {
        "archive_bytes": int(row["archive_bytes"]),
        "archive_md5": row["md5"],
        "archive_sha256": row["sha256"],
        "archive_source_url": row["source_url"],
        "release_version": row["version"],
        "released_utc": row["released_utc"],
        "snapshot_policy": row["snapshot_policy"],
    }


def verify_release_tag(
    repository: Path,
    subject: Subject,
    object_id: str,
    object_type: str,
    commit_id: str,
) -> None:
    if subject.source_kind != "sourceforge-release":
        return
    assert subject.archive_sha256 is not None
    if object_type != "tag":
        raise ProvenanceError(
            f"{subject.identifier}: release ref is not an annotated Git tag"
        )
    tag = run_git(repository, ["cat-file", "-p", object_id]).decode(
        "utf-8", "strict"
    )
    commit_message = run_git(
        repository, ["show", "-s", "--format=%B", commit_id]
    ).decode("utf-8", "strict")
    pattern = re.compile(r"(?m)^Archive-SHA256: ([0-9a-f]{64})$")
    tag_hashes = pattern.findall(tag)
    commit_hashes = pattern.findall(commit_message)
    if tag_hashes != [subject.archive_sha256]:
        raise ProvenanceError(
            f"{subject.identifier}: annotated tag archive SHA-256 mismatch"
        )
    if commit_hashes != [subject.archive_sha256]:
        raise ProvenanceError(
            f"{subject.identifier}: snapshot commit archive SHA-256 mismatch"
        )


def resolve_ref(repository: Path, subject: Subject) -> tuple[str, str, str, str]:
    found: list[tuple[str, str]] = []
    for candidate in subject.ref_candidates:
        try:
            object_id = run_git(
                repository, ["rev-parse", "--verify", "--end-of-options", candidate]
            ).decode("ascii").strip()
        except ProvenanceError:
            continue
        found.append((candidate, object_id))

    if not found:
        candidates = ", ".join(subject.ref_candidates)
        raise ProvenanceError(
            f"{subject.identifier}: no required ref found ({candidates}); "
            "use a full checkout containing all historical branches and tags"
        )
    object_ids = {object_id for _, object_id in found}
    if len(object_ids) != 1:
        rendered = ", ".join(f"{name}={oid}" for name, oid in found)
        raise ProvenanceError(
            f"{subject.identifier}: local and remote refs disagree ({rendered})"
        )

    candidate, object_id = found[0]
    object_type = run_git(repository, ["cat-file", "-t", object_id]).decode("ascii").strip()
    commit_id = run_git(
        repository, ["rev-parse", "--verify", "--end-of-options", f"{candidate}^{{commit}}"]
    ).decode("ascii").strip()
    tree_id = run_git(
        repository, ["rev-parse", "--verify", "--end-of-options", f"{candidate}^{{tree}}"]
    ).decode("ascii").strip()
    return object_id, object_type, commit_id, tree_id


def git_tree_rows(repository: Path, subject: Subject) -> tuple[list[dict[str, object]], dict[str, str]]:
    object_id, object_type, commit_id, tree_id = resolve_ref(repository, subject)
    verify_release_tag(repository, subject, object_id, object_type, commit_id)
    raw = run_git(
        repository,
        ["ls-tree", "-rz", "--full-tree", "-r", commit_id, "--"],
    )
    records: list[tuple[bytes, str, str]] = []
    for record in raw.split(b"\0"):
        if not record:
            continue
        try:
            metadata, path_bytes = record.split(b"\t", 1)
            mode_bytes, kind_bytes, blob_bytes = metadata.split(b" ", 2)
        except ValueError as error:
            raise ProvenanceError(
                f"{subject.identifier}: malformed git ls-tree record"
            ) from error
        if kind_bytes != b"blob":
            raise ProvenanceError(
                f"{subject.identifier}: unsupported Git object {kind_bytes!r}"
            )
        try:
            mode = mode_bytes.decode("ascii")
            blob_id = blob_bytes.decode("ascii")
        except UnicodeDecodeError as error:
            raise ProvenanceError(
                f"{subject.identifier}: non-ASCII Git metadata"
            ) from error
        if subject.excluded_root_entries:
            top_level = path_bytes.split(b"/", 1)[0]
            if top_level in {
                name.encode("utf-8") for name in subject.excluded_root_entries
            }:
                continue
        records.append((path_bytes, mode, blob_id))

    records.sort(key=lambda row: row[0])
    if len({path for path, _, _ in records}) != len(records):
        raise ProvenanceError(f"{subject.identifier}: duplicate Git path")

    blob_cache: dict[str, tuple[int, str]] = {}
    rows: list[dict[str, object]] = []
    process = subprocess.Popen(
        ["git", "cat-file", "--batch"],
        cwd=repository,
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    assert process.stdin is not None
    assert process.stdout is not None
    try:
        for path_bytes, mode, blob_id in records:
            if blob_id not in blob_cache:
                process.stdin.write(blob_id.encode("ascii") + b"\n")
                process.stdin.flush()
                header = process.stdout.readline().rstrip(b"\n")
                parts = header.split(b" ")
                if len(parts) != 3 or parts[0] != blob_id.encode("ascii") or parts[1] != b"blob":
                    raise ProvenanceError(
                        f"{subject.identifier}: cannot read Git blob {blob_id}"
                    )
                size = int(parts[2])
                content = process.stdout.read(size)
                trailer = process.stdout.read(1)
                if len(content) != size or trailer != b"\n":
                    raise ProvenanceError(
                        f"{subject.identifier}: truncated Git blob {blob_id}"
                    )
                blob_cache[blob_id] = (size, sha256(content))
            size, content_hash = blob_cache[blob_id]
            rows.append(entry(path_bytes, mode, size, content_hash))
    finally:
        if process.stdin is not None:
            process.stdin.close()
        return_code = process.wait()
        if return_code != 0:
            detail = b""
            if process.stderr is not None:
                detail = process.stderr.read()
            raise ProvenanceError(
                f"{subject.identifier}: git cat-file failed: "
                f"{detail.decode('utf-8', 'replace').strip() or return_code}"
            )

    metadata = {
        "git_ref_object": object_id,
        "git_ref_object_type": object_type,
        "git_commit": commit_id,
        "git_tree": tree_id,
    }
    return rows, metadata


def validate_relative_path(path_bytes: bytes, subject: str) -> str:
    if not path_bytes or b"\0" in path_bytes:
        raise ProvenanceError(f"{subject}: empty path or NUL byte")
    if path_bytes.startswith(b"/"):
        raise ProvenanceError(f"{subject}: absolute path is forbidden")
    parts = path_bytes.split(b"/")
    if any(part in (b"", b".", b"..") for part in parts):
        raise ProvenanceError(f"{subject}: non-canonical relative path")
    try:
        return path_bytes.decode("utf-8", "strict")
    except UnicodeDecodeError as error:
        raise ProvenanceError(f"{subject}: path is not valid UTF-8") from error


def entry(path_bytes: bytes, mode: str, size: int, content_hash: str) -> dict[str, object]:
    display = validate_relative_path(path_bytes, "manifest generation")
    return {
        "mode": mode,
        "path": display,
        "path_bytes_base64": base64.b64encode(path_bytes).decode("ascii"),
        "sha256": content_hash,
        "size": size,
    }


def filesystem_rows(root: Path, subject: Subject) -> tuple[list[dict[str, object]], dict[str, str]]:
    if not root.is_dir():
        raise ProvenanceError(f"{subject.identifier}: missing directory {root}")
    paths: list[tuple[bytes, Path]] = []
    for directory, names, filenames in os.walk(root):
        names[:] = sorted(names, key=os.fsencode)
        for name in sorted(filenames, key=os.fsencode):
            path = Path(directory, name)
            if path.is_symlink() or not path.is_file():
                raise ProvenanceError(
                    f"{subject.identifier}: unsupported filesystem entry {path}"
                )
            relative = path.relative_to(root).as_posix()
            paths.append((os.fsencode(relative), path))
    paths.sort(key=lambda row: row[0])
    rows: list[dict[str, object]] = []
    for path_bytes, path in paths:
        content = path.read_bytes()
        rows.append(entry(path_bytes, "file", len(content), sha256(content)))
    return rows, {}


def archived_filesystem_rows(
    repository: Path, subject: Subject
) -> tuple[list[dict[str, object]], dict[str, str]]:
    """Read the removed documentation payload from one pinned Git subtree."""
    commit = subject.archive_git_commit
    subtree = subject.archive_git_subtree
    if (
        commit is None
        or re.fullmatch(r"[0-9a-f]{40,64}", commit) is None
        or subtree is None
        or subtree == ""
        or subtree.startswith("/")
        or any(part in ("", ".", "..") for part in subtree.split("/"))
    ):
        raise ProvenanceError(
            f"{subject.identifier}: invalid documentation archive locator"
        )
    resolved = run_git(
        repository,
        ["rev-parse", "--verify", "--end-of-options", f"{commit}^{{commit}}"],
    ).decode("ascii").strip()
    if resolved != commit:
        raise ProvenanceError(
            f"{subject.identifier}: documentation archive commit mismatch"
        )
    archive = run_git(
        repository,
        ["archive", "--format=tar", commit, "--", subtree],
    )
    prefix = subtree.rstrip("/") + "/"
    rows: list[dict[str, object]] = []
    try:
        with tarfile.open(fileobj=io.BytesIO(archive), mode="r:") as bundle:
            for member in bundle.getmembers():
                if member.isdir():
                    continue
                if not member.isfile() or not member.name.startswith(prefix):
                    raise ProvenanceError(
                        f"{subject.identifier}: unsupported archived entry "
                        f"{member.name}"
                    )
                relative = member.name[len(prefix) :]
                path_bytes = relative.encode("utf-8", "strict")
                stream = bundle.extractfile(member)
                if stream is None:
                    raise ProvenanceError(
                        f"{subject.identifier}: cannot read archived entry "
                        f"{relative}"
                    )
                content = stream.read()
                rows.append(
                    entry(path_bytes, "file", len(content), sha256(content))
                )
    except (tarfile.TarError, UnicodeError) as error:
        raise ProvenanceError(
            f"{subject.identifier}: invalid documentation archive"
        ) from error
    rows.sort(key=lambda row: base64.b64decode(str(row["path_bytes_base64"])))
    if len({str(row["path_bytes_base64"]) for row in rows}) != len(rows):
        raise ProvenanceError(
            f"{subject.identifier}: duplicate archived documentation path"
        )
    return rows, {}


def manifest_header(
    subject: Subject,
    release: dict[str, object] | None = None,
) -> dict[str, object]:
    header: dict[str, object] = {
        "format": FORMAT,
        "kind": subject.kind,
        "source_kind": subject.source_kind,
        "source_path": subject.source_path,
        "subject": subject.identifier,
    }
    if subject.source_kind == "svn":
        header["last_changed_revision"] = subject.last_changed_revision
        header["snapshot_revision"] = SNAPSHOT_REVISION
    else:
        if release is None:
            raise ProvenanceError(
                f"{subject.identifier}: missing release archive metadata"
            )
        header.update(release)
        header["excluded_root_entries"] = list(subject.excluded_root_entries)
    if subject.git_ref is not None:
        header["git_ref"] = subject.git_ref
    if subject.workspace_path is not None:
        header["workspace_path"] = subject.workspace_path
    if subject.archive_git_commit is not None:
        header["archive_git_commit"] = subject.archive_git_commit
    if subject.archive_git_subtree is not None:
        header["archive_git_subtree"] = subject.archive_git_subtree
    return header


def encode_manifest(
    subject: Subject,
    rows: list[dict[str, object]],
    release: dict[str, object] | None = None,
) -> bytes:
    chunks = [canonical_json(manifest_header(subject, release))]
    chunks.extend(canonical_json(row) for row in rows)
    return b"".join(chunks)


def rows_digest(rows: Iterable[dict[str, object]]) -> str:
    state = hashlib.sha256()
    for row in rows:
        state.update(canonical_json(row))
    return state.hexdigest()


def collect_subject(
    repository: Path, subject: Subject
) -> tuple[list[dict[str, object]], dict[str, str]]:
    if subject.kind == "git":
        return git_tree_rows(repository, subject)
    if subject.archive_git_commit is not None:
        return archived_filesystem_rows(repository, subject)
    assert subject.workspace_path is not None
    return filesystem_rows(repository / subject.workspace_path, subject)


def generate(repository: Path, evidence_dir: Path) -> None:
    evidence_dir.mkdir(parents=True, exist_ok=True)
    index_subjects: list[dict[str, object]] = []
    for subject in SUBJECTS:
        release = release_metadata(repository, subject)
        rows, git_metadata = collect_subject(repository, subject)
        manifest = encode_manifest(subject, rows, release)
        (evidence_dir / subject.manifest).write_bytes(manifest)
        record: dict[str, object] = {
            **manifest_header(subject, release),
            **git_metadata,
            "file_count": len(rows),
            "total_bytes": sum(int(row["size"]) for row in rows),
            "entries_sha256": rows_digest(rows),
            "manifest": subject.manifest,
            "manifest_sha256": sha256(manifest),
        }
        index_subjects.append(record)
        print(
            f"WROTE\t{subject.identifier}\tfiles={len(rows)}\t"
            f"bytes={record['total_bytes']}"
        )

    index = {
        "format": FORMAT,
        "git_object_format": run_git(
            repository, ["rev-parse", "--show-object-format"]
        ).decode("ascii").strip(),
        "svn_snapshot_revision": SNAPSHOT_REVISION,
        "source_evidence_sha256": {
            path: sha256((repository / path).read_bytes())
            for path in SOURCE_EVIDENCE_FILES
        },
        "source_repository": SVN_REPOSITORY,
        "source_uuid": SVN_UUID,
        "subjects": index_subjects,
    }
    index_bytes = json.dumps(
        index, ensure_ascii=False, indent=2, sort_keys=True
    ).encode("utf-8") + b"\n"
    (evidence_dir / "index.json").write_bytes(index_bytes)
    (evidence_dir / "index.sha256").write_text(
        f"{sha256(index_bytes)}  index.json\n", encoding="ascii"
    )
    print(f"WROTE\tindex\tsha256={sha256(index_bytes)}")


def load_index(evidence_dir: Path) -> tuple[dict[str, Any], bytes]:
    lock_path = evidence_dir / "index.sha256"
    try:
        lock = lock_path.read_text(encoding="ascii")
    except (OSError, UnicodeError) as error:
        raise ProvenanceError(f"cannot read {lock_path}") from error
    parts = lock.rstrip("\n").split("  ")
    if (
        len(parts) != 2
        or parts[1] != "index.json"
        or len(parts[0]) != 64
        or any(character not in "0123456789abcdef" for character in parts[0])
        or lock != f"{parts[0]}  index.json\n"
    ):
        raise ProvenanceError("index.sha256 is not canonical")
    try:
        index_bytes = (evidence_dir / "index.json").read_bytes()
    except OSError as error:
        raise ProvenanceError("cannot read provenance index") from error
    if sha256(index_bytes) != parts[0]:
        raise ProvenanceError("provenance index checksum mismatch")
    try:
        index = json.loads(index_bytes)
    except json.JSONDecodeError as error:
        raise ProvenanceError("provenance index is invalid JSON") from error
    if not isinstance(index, dict):
        raise ProvenanceError("provenance index must be an object")
    return index, index_bytes


def decode_manifest(
    subject: Subject, content: bytes
) -> tuple[dict[str, object], list[dict[str, object]]]:
    lines = content.splitlines(keepends=True)
    if not lines or any(not line.endswith(b"\n") for line in lines):
        raise ProvenanceError(f"{subject.identifier}: manifest lines must end in LF")
    values: list[object] = []
    for number, line in enumerate(lines, 1):
        try:
            value = json.loads(line)
        except (UnicodeDecodeError, json.JSONDecodeError) as error:
            raise ProvenanceError(
                f"{subject.identifier}: invalid JSON on manifest line {number}"
            ) from error
        if canonical_json(value) != line:
            raise ProvenanceError(
                f"{subject.identifier}: non-canonical manifest line {number}"
            )
        values.append(value)
    if not isinstance(values[0], dict):
        raise ProvenanceError(f"{subject.identifier}: manifest header is not an object")
    rows: list[dict[str, object]] = []
    previous: bytes | None = None
    for number, value in enumerate(values[1:], 2):
        if not isinstance(value, dict):
            raise ProvenanceError(
                f"{subject.identifier}: entry on line {number} is not an object"
            )
        required = {"mode", "path", "path_bytes_base64", "sha256", "size"}
        if set(value) != required:
            raise ProvenanceError(
                f"{subject.identifier}: invalid keys on manifest line {number}"
            )
        encoded = value["path_bytes_base64"]
        if not isinstance(encoded, str):
            raise ProvenanceError(f"{subject.identifier}: path encoding is not text")
        try:
            path_bytes = base64.b64decode(encoded, validate=True)
        except (ValueError, base64.binascii.Error) as error:
            raise ProvenanceError(
                f"{subject.identifier}: invalid path base64 on line {number}"
            ) from error
        if base64.b64encode(path_bytes).decode("ascii") != encoded:
            raise ProvenanceError(
                f"{subject.identifier}: non-canonical path base64 on line {number}"
            )
        display = validate_relative_path(path_bytes, subject.identifier)
        if value["path"] != display:
            raise ProvenanceError(
                f"{subject.identifier}: UTF-8 path display mismatch on line {number}"
            )
        if previous is not None and path_bytes <= previous:
            raise ProvenanceError(
                f"{subject.identifier}: paths are duplicate or not byte-sorted"
            )
        previous = path_bytes
        if value["mode"] not in ("100644", "100755", "120000", "file"):
            raise ProvenanceError(
                f"{subject.identifier}: unsupported mode on line {number}"
            )
        size = value["size"]
        digest = value["sha256"]
        if not isinstance(size, int) or isinstance(size, bool) or size < 0:
            raise ProvenanceError(f"{subject.identifier}: invalid size on line {number}")
        if (
            not isinstance(digest, str)
            or len(digest) != 64
            or any(character not in "0123456789abcdef" for character in digest)
        ):
            raise ProvenanceError(
                f"{subject.identifier}: invalid SHA-256 on line {number}"
            )
        rows.append(value)
    return values[0], rows


def compare_rows(
    subject: Subject,
    expected: list[dict[str, object]],
    actual: list[dict[str, object]],
) -> None:
    if expected == actual:
        return
    expected_by_path = {str(row["path"]): row for row in expected}
    actual_by_path = {str(row["path"]): row for row in actual}
    missing = sorted(expected_by_path.keys() - actual_by_path.keys())
    extra = sorted(actual_by_path.keys() - expected_by_path.keys())
    changed = sorted(
        path
        for path in expected_by_path.keys() & actual_by_path.keys()
        if expected_by_path[path] != actual_by_path[path]
    )
    details = []
    for label, paths in (("missing", missing), ("extra", extra), ("changed", changed)):
        if paths:
            details.append(f"{label}={paths[:3]!r}")
    raise ProvenanceError(
        f"{subject.identifier}: source differs from manifest"
        + (f" ({'; '.join(details)})" if details else "")
    )


def verify(
    repository: Path,
    evidence_dir: Path,
    *,
    verify_sources: bool = True,
    quiet: bool = False,
) -> None:
    index, _ = load_index(evidence_dir)
    expected_top = {
        "format": FORMAT,
        "git_object_format": "sha1",
        "svn_snapshot_revision": SNAPSHOT_REVISION,
        "source_repository": SVN_REPOSITORY,
        "source_uuid": SVN_UUID,
    }
    for key, value in expected_top.items():
        if index.get(key) != value:
            raise ProvenanceError(f"provenance index has invalid {key}")
    source_evidence = index.get("source_evidence_sha256")
    if (
        not isinstance(source_evidence, dict)
        or set(source_evidence) != set(SOURCE_EVIDENCE_FILES)
    ):
        raise ProvenanceError("provenance index has incomplete source evidence")
    for relative_path in SOURCE_EVIDENCE_FILES:
        try:
            content = (repository / relative_path).read_bytes()
        except OSError as error:
            raise ProvenanceError(
                f"cannot read source evidence {relative_path}"
            ) from error
        if source_evidence.get(relative_path) != sha256(content):
            raise ProvenanceError(
                f"source evidence checksum mismatch: {relative_path}"
            )
    records = index.get("subjects")
    if not isinstance(records, list) or len(records) != len(SUBJECTS):
        raise ProvenanceError("provenance index has an incomplete subject set")
    by_id: dict[str, dict[str, object]] = {}
    for record in records:
        if not isinstance(record, dict) or not isinstance(record.get("subject"), str):
            raise ProvenanceError("provenance index contains an invalid subject")
        identifier = str(record["subject"])
        if identifier in by_id:
            raise ProvenanceError(f"duplicate provenance subject {identifier}")
        by_id[identifier] = record
    if set(by_id) != {subject.identifier for subject in SUBJECTS}:
        raise ProvenanceError("provenance index subject names do not match the contract")

    for subject in SUBJECTS:
        record = by_id[subject.identifier]
        release = release_metadata(repository, subject)
        expected_header = manifest_header(subject, release)
        for key, value in expected_header.items():
            if record.get(key) != value:
                raise ProvenanceError(f"{subject.identifier}: invalid index field {key}")
        if record.get("manifest") != subject.manifest:
            raise ProvenanceError(f"{subject.identifier}: wrong manifest filename")
        manifest_path = evidence_dir / subject.manifest
        try:
            content = manifest_path.read_bytes()
        except OSError as error:
            raise ProvenanceError(f"{subject.identifier}: cannot read manifest") from error
        if sha256(content) != record.get("manifest_sha256"):
            raise ProvenanceError(f"{subject.identifier}: manifest checksum mismatch")
        header, expected_rows = decode_manifest(subject, content)
        if header != expected_header:
            raise ProvenanceError(f"{subject.identifier}: manifest header mismatch")
        if record.get("file_count") != len(expected_rows):
            raise ProvenanceError(f"{subject.identifier}: file count mismatch")
        total_bytes = sum(int(row["size"]) for row in expected_rows)
        if record.get("total_bytes") != total_bytes:
            raise ProvenanceError(f"{subject.identifier}: byte count mismatch")
        if record.get("entries_sha256") != rows_digest(expected_rows):
            raise ProvenanceError(f"{subject.identifier}: entry aggregate mismatch")

        if verify_sources:
            actual_rows, git_metadata = collect_subject(repository, subject)
            compare_rows(subject, expected_rows, actual_rows)
            for key, value in git_metadata.items():
                if record.get(key) != value:
                    raise ProvenanceError(
                        f"{subject.identifier}: pinned {key} differs from source"
                    )
        if not quiet:
            print(
                f"OK\t{subject.identifier}\tfiles={len(expected_rows)}\t"
                f"bytes={total_bytes}"
            )
    if not quiet:
        print(
            f"Historical provenance verification: OK ({len(SUBJECTS)} subjects)"
        )


def self_test(repository: Path, evidence_dir: Path) -> None:
    release_subject = next(
        subject
        for subject in SUBJECTS
        if subject.source_kind == "sourceforge-release"
    )
    cases = (SUBJECTS[0], release_subject)
    for selected in cases:
        with tempfile.TemporaryDirectory(prefix="estab-provenance-") as temporary:
            copy = Path(temporary, "provenance")
            shutil.copytree(evidence_dir, copy)
            index, _ = load_index(copy)
            manifest_path = copy / selected.manifest
            lines = manifest_path.read_bytes().splitlines(keepends=True)
            row = json.loads(lines[1])
            original = str(row["sha256"])
            row["sha256"] = ("0" if original[0] != "0" else "1") + original[1:]
            lines[1] = canonical_json(row)
            changed_manifest = b"".join(lines)
            manifest_path.write_bytes(changed_manifest)

            for record in index["subjects"]:
                if record["subject"] == selected.identifier:
                    changed_rows = [json.loads(line) for line in lines[1:]]
                    record["manifest_sha256"] = sha256(changed_manifest)
                    record["entries_sha256"] = rows_digest(changed_rows)
                    break
            index_bytes = json.dumps(
                index, ensure_ascii=False, indent=2, sort_keys=True
            ).encode("utf-8") + b"\n"
            (copy / "index.json").write_bytes(index_bytes)
            (copy / "index.sha256").write_text(
                f"{sha256(index_bytes)}  index.json\n", encoding="ascii"
            )
            try:
                verify(repository, copy, quiet=True)
            except ProvenanceError as error:
                if "source differs from manifest" not in str(error):
                    raise ProvenanceError(
                        f"self-test failed for {selected.identifier} "
                        f"for the wrong reason: {error}"
                    ) from error
            else:
                raise ProvenanceError(
                    f"self-test did not detect resealed {selected.identifier}"
                )
    print(
        f"Historical provenance manipulation self-test: OK ({len(cases)} cases)"
    )


def parse_args(arguments: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "repository",
        nargs="?",
        type=Path,
        default=Path(__file__).resolve().parent.parent,
        help="Git repository to verify (default: script parent repository)",
    )
    parser.add_argument(
        "--write",
        action="store_true",
        help="regenerate the deterministic manifests from the pinned sources",
    )
    parser.add_argument(
        "--manifests-only",
        action="store_true",
        help="verify the evidence bundle without reading Git refs or docs",
    )
    parser.add_argument(
        "--self-test",
        action="store_true",
        help="prove that a checksum-resealed content manipulation is detected",
    )
    return parser.parse_args(arguments)


def main(arguments: list[str] | None = None) -> int:
    options = parse_args(sys.argv[1:] if arguments is None else arguments)
    repository = options.repository.resolve()
    evidence_dir = repository / "migration" / "provenance"
    try:
        if options.write:
            if options.manifests_only or options.self_test:
                raise ProvenanceError("--write cannot be combined with verification options")
            generate(repository, evidence_dir)
        else:
            verify(
                repository,
                evidence_dir,
                verify_sources=not options.manifests_only,
            )
            if options.self_test:
                if options.manifests_only:
                    raise ProvenanceError("--self-test requires source verification")
                self_test(repository, evidence_dir)
    except ProvenanceError as error:
        print(f"Historical provenance verification: FAIL: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
