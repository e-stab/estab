"""Verify a cleaned SourceForge release tree against a Git ref or worktree."""

from __future__ import annotations

import argparse
import hashlib
import io
import os
import subprocess
import tarfile
import unicodedata
from pathlib import Path

INFRASTRUCTURE = {".git", ".gitignore", ".mailmap", "migration"}


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def ignored(path: Path) -> bool:
    return path.name == ".DS_Store" or path.name.startswith("._") or "__MACOSX" in path.parts


def portable_path(path: Path | str) -> str:
    """Use Git's portable NFC representation (not macOS' decomposed form)."""
    return unicodedata.normalize("NFC", path.as_posix() if isinstance(path, Path) else path)


def directory_files(root: Path, omit_infrastructure: bool = False) -> dict[str, str]:
    files: dict[str, str] = {}
    for directory, names, filenames in os.walk(root):
        relative_directory = Path(directory).relative_to(root)
        names[:] = sorted(
            name
            for name in names
            if not ignored(relative_directory / name)
            and not (omit_infrastructure and len(relative_directory.parts) == 0 and name in INFRASTRUCTURE)
        )
        for name in sorted(filenames):
            path = Path(directory, name)
            relative = path.relative_to(root)
            if ignored(relative) or (omit_infrastructure and relative.parts[0] in INFRASTRUCTURE):
                continue
            files[portable_path(relative)] = digest(path.read_bytes())
    return files


def git_files(repository: Path, ref: str) -> dict[str, str]:
    archive = subprocess.run(
        ["git", "archive", "--format=tar", ref],
        cwd=repository,
        check=True,
        stdout=subprocess.PIPE,
    ).stdout
    files: dict[str, str] = {}
    with tarfile.open(fileobj=io.BytesIO(archive), mode="r:") as tar:
        for member in tar.getmembers():
            path = Path(member.name)
            if not member.isfile() or path.parts[0] in INFRASTRUCTURE or ignored(path):
                continue
            stream = tar.extractfile(member)
            assert stream is not None
            files[portable_path(member.name)] = digest(stream.read())
    return files


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("release_tree", type=Path)
    parser.add_argument("repository", type=Path)
    parser.add_argument("--ref", help="Git ref; without it, compare the working tree")
    arguments = parser.parse_args()

    source = directory_files(arguments.release_tree.resolve())
    if arguments.ref:
        target = git_files(arguments.repository.resolve(), arguments.ref)
        target_label = arguments.ref
    else:
        target = directory_files(arguments.repository.resolve(), omit_infrastructure=True)
        target_label = "working-tree"

    missing = sorted(source.keys() - target.keys())
    extra = sorted(target.keys() - source.keys())
    changed = sorted(path for path in source.keys() & target.keys() if source[path] != target[path])
    state = "OK" if not (missing or extra or changed) else "FAIL"
    print(
        f"{state}\t{target_label}\tfiles={len(source)}\tmissing={len(missing)}\t"
        f"extra={len(extra)}\tchanged={len(changed)}"
    )
    for category, paths in (("missing", missing), ("extra", extra), ("changed", changed)):
        for path in paths[:50]:
            print(f"  {category}: {path}")
    return 0 if state == "OK" else 1


if __name__ == "__main__":
    raise SystemExit(main())
