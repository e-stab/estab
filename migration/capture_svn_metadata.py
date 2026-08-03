"""Create deterministic metadata manifests for the SVN-to-Git migration."""

from __future__ import annotations

import hashlib
import os
import re
import subprocess
import sys
import xml.etree.ElementTree as ET  # nosemgrep: python.lang.security.use-defused-xml.use-defused-xml
from pathlib import Path

SVN_TRAILER = re.compile(
    r"git-svn-id: (?P<url>\S+)@(?P<revision>\d+) (?P<uuid>[0-9a-f-]+)"
)
MAX_SVN_PROPERTY_XML_BYTES = 64 * 1024 * 1024


def run(command: list[str], cwd: Path) -> bytes:
    return subprocess.run(command, cwd=cwd, check=True, stdout=subprocess.PIPE).stdout


def write_empty_directories(svn_wc: Path, output: Path) -> None:
    paths: list[str] = []
    for directory, names, filenames in os.walk(svn_wc):
        names[:] = sorted(name for name in names if name != ".svn")
        visible_files = [name for name in filenames if name != ".DS_Store"]
        if not names and not visible_files:
            paths.append(Path(directory).relative_to(svn_wc).as_posix())
    output.write_text("\n".join(sorted(paths)) + "\n", encoding="utf-8")


def write_properties(svn_wc: Path, output: Path) -> None:
    raw = run(["svn", "proplist", "--verbose", "--xml", "--recursive", "."], svn_wc)
    if len(raw) > MAX_SVN_PROPERTY_XML_BYTES:
        raise ValueError("SVN property XML exceeds the 64 MiB safety limit")

    declaration_probe = raw.upper()
    if b"<!DOCTYPE" in declaration_probe or b"<!ENTITY" in declaration_probe:
        raise ValueError("SVN property XML must not contain DTD or entity declarations")

    # The XML is emitted by the local svn process. Size and declaration checks above
    # prevent expansion attacks before the standard-library parser receives it.
    root = ET.fromstring(raw)  # nosec B314
    if root.tag != "properties":
        raise ValueError(f"unexpected SVN property XML root element: {root.tag!r}")
    rows = ["path\tproperty\tvalue_sha256\tvalue"]
    for target in root.findall("target"):
        path = target.attrib["path"]
        for prop in target.findall("property"):
            value = prop.text or ""
            printable = value.replace("\\", "\\\\").replace("\t", "\\t").replace("\r", "\\r").replace("\n", "\\n")
            rows.append(
                f"{path}\t{prop.attrib['name']}\t{hashlib.sha256(value.encode()).hexdigest()}\t{printable}"
            )
    output.write_text("\n".join(rows) + "\n", encoding="utf-8")


def write_revision_map(repository: Path, output: Path) -> None:
    record_separator = b"\x1e"
    field_separator = b"\x1f"
    raw = run(
        ["git", "log", "--all", "--format=%H%x1f%aI%x1f%an%x1f%B%x1e"], repository
    )
    rows: set[tuple[int, str, str, str, str, str]] = set()
    for record in raw.split(record_separator):
        fields = record.strip().split(field_separator, 3)
        if len(fields) != 4:
            continue
        commit, date, author, message = (field.decode("utf-8", "replace") for field in fields)
        match = SVN_TRAILER.search(message)
        if match:
            rows.add(
                (
                    int(match.group("revision")),
                    commit,
                    date,
                    author,
                    match.group("url"),
                    match.group("uuid"),
                )
            )
    lines = ["svn_revision\tgit_commit\tauthor_date\tauthor\tsvn_url\tsvn_uuid"]
    lines.extend("\t".join(map(str, row)) for row in sorted(rows))
    output.write_text("\n".join(lines) + "\n", encoding="utf-8")


def write_file_manifest(root: Path, output: Path) -> None:
    rows: list[str] = []
    for directory, names, filenames in os.walk(root):
        names[:] = sorted(name for name in names if name != ".svn")
        for name in sorted(filenames):
            path = Path(directory, name)
            rows.append(f"{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.relative_to(root).as_posix()}")
    output.write_text("\n".join(rows) + "\n", encoding="utf-8")


def main() -> int:
    if len(sys.argv) != 4:
        print(f"usage: {Path(sys.argv[0]).name} SVN_WORKING_COPY GIT_REPOSITORY OUTPUT_DIR", file=sys.stderr)
        return 2
    svn_wc, repository, output_dir = (Path(argument).resolve() for argument in sys.argv[1:])
    output_dir.mkdir(parents=True, exist_ok=True)
    write_empty_directories(svn_wc, output_dir / "svn-empty-directories.txt")
    write_properties(svn_wc, output_dir / "svn-properties.tsv")
    write_revision_map(repository, output_dir / "svn-revision-map.tsv")
    write_file_manifest(svn_wc / "eStab_0.9" / "trunk", output_dir / "svn-trunk-r84.sha256")
    write_file_manifest(
        svn_wc / "eStab_0.9" / "docu", output_dir / "svn-documentation-r85.sha256"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
