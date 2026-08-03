"""Compare each imported Git ref byte-for-byte with the local SVN snapshot."""

from __future__ import annotations

import hashlib
import io
import os
import subprocess
import sys
import tarfile
from pathlib import Path

REFS = {
    "trunk": "svn-r85",
    "branch/0.9.20_bugfix": "legacy/0.9.20_bugfix",
    "branch/0.9.20_buttons_rework": "legacy/0.9.20_buttons_rework",
    "branch/0.9.20_kto_usr_fkt": "legacy/0.9.20_kto_usr_fkt",
    "branch/0.9.20_ticket20": "legacy/0.9.20_ticket20",
    "tags/ver0.9.09": "ver0.9.09^{}",
    "tags/ver0.9.10": "ver0.9.10^{}",
    "tags/ver0.9.11": "ver0.9.11^{}",
    "tags/ver0.9.12": "ver0.9.12^{}",
    "tags/ver0.9.20": "ver0.9.20^{}",
    "tags/ver0.9.20b": "ver0.9.20b^{}",
}


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def svn_files(root: Path) -> dict[str, str]:
    files: dict[str, str] = {}
    for directory, names, filenames in os.walk(root):
        names[:] = sorted(name for name in names if name != ".svn")
        for name in sorted(filenames):
            path = Path(directory, name)
            files[path.relative_to(root).as_posix()] = digest(path.read_bytes())
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
            if member.isfile():
                stream = tar.extractfile(member)
                assert stream is not None
                files[member.name] = digest(stream.read())
    return files


def compare(source: dict[str, str], target: dict[str, str]) -> tuple[list[str], list[str], list[str]]:
    missing = sorted(source.keys() - target.keys())
    extra = sorted(target.keys() - source.keys())
    changed = sorted(path for path in source.keys() & target.keys() if source[path] != target[path])
    return missing, extra, changed


def main() -> int:
    if len(sys.argv) != 3:
        print(f"usage: {Path(sys.argv[0]).name} SVN_PROJECT_ROOT GIT_REPOSITORY", file=sys.stderr)
        return 2
    svn_root = Path(sys.argv[1]).resolve()
    repository = Path(sys.argv[2]).resolve()
    failures = 0
    for relative_path, ref in REFS.items():
        source = svn_files(svn_root / relative_path)
        target = git_files(repository, ref)
        missing, extra, changed = compare(source, target)
        state = "OK" if not (missing or extra or changed) else "FAIL"
        print(
            f"{state}\t{relative_path}\t{ref}\tfiles={len(source)}\t"
            f"missing={len(missing)}\textra={len(extra)}\tchanged={len(changed)}"
        )
        if state == "FAIL":
            failures += 1
            for category, paths in (("missing", missing), ("extra", extra), ("changed", changed)):
                for path in paths[:20]:
                    print(f"  {category}: {path}")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
