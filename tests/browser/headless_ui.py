#!/usr/bin/env python3
"""Dependency-free ESTAB browser acceptance test using Chrome DevTools Protocol."""

from __future__ import annotations

import argparse
import base64
import contextlib
import dataclasses
import hashlib
import json
import os
import pathlib
import re
import secrets
import shutil
import signal
import socket
import ssl
import struct
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request
import zlib
from types import TracebackType
from typing import Any, BinaryIO, Self


class TestFailure(RuntimeError):
    """Expected test or environment failure with a user-facing message."""


def _png_rgb_content_summary(payload: bytes) -> dict[str, int]:
    """Decode Chrome's 8-bit RGB(A) PNG without an external image library."""
    if not payload.startswith(b"\x89PNG\r\n\x1a\n"):
        raise TestFailure("Chrome lieferte keinen gültigen PNG-Bildnachweis.")
    offset = 8
    width = height = bit_depth = colour_type = interlace = -1
    compressed = bytearray()
    while offset + 12 <= len(payload):
        length = struct.unpack(">I", payload[offset : offset + 4])[0]
        chunk_type = payload[offset + 4 : offset + 8]
        chunk_end = offset + 12 + length
        if chunk_end > len(payload):
            raise TestFailure("Chromes PNG-Bildnachweis ist abgeschnitten.")
        chunk = payload[offset + 8 : offset + 8 + length]
        if chunk_type == b"IHDR":
            if len(chunk) != 13:
                raise TestFailure("Chromes PNG-Kopfdaten sind ungültig.")
            (
                width,
                height,
                bit_depth,
                colour_type,
                _compression,
                _filter,
                interlace,
            ) = struct.unpack(">IIBBBBB", chunk)
        elif chunk_type == b"IDAT":
            compressed.extend(chunk)
        elif chunk_type == b"IEND":
            break
        offset = chunk_end
    channels = {2: 3, 6: 4}.get(colour_type)
    if (
        width <= 0
        or height <= 0
        or bit_depth != 8
        or channels is None
        or interlace != 0
        or not compressed
    ):
        raise TestFailure("Chromes PNG-Format kann nicht sicher geprüft werden.")
    try:
        scanlines = zlib.decompress(bytes(compressed))
    except zlib.error as exc:
        raise TestFailure("Chromes PNG-Bilddaten sind nicht lesbar.") from exc
    stride = width * channels
    if len(scanlines) != height * (stride + 1):
        raise TestFailure("Chromes PNG-Bildmaße stimmen nicht mit den Bilddaten überein.")

    previous = bytearray(stride)
    position = 0
    blue_pixels = 0
    dark_pixels = 0
    non_white_pixels = 0
    white_pixels = 0
    blue_min_x = width
    blue_max_x = -1
    blue_min_y = height
    blue_max_y = -1
    decoded_rows: list[bytes] = []

    def paeth(left: int, above: int, upper_left: int) -> int:
        prediction = left + above - upper_left
        left_distance = abs(prediction - left)
        above_distance = abs(prediction - above)
        upper_left_distance = abs(prediction - upper_left)
        if left_distance <= above_distance and left_distance <= upper_left_distance:
            return left
        if above_distance <= upper_left_distance:
            return above
        return upper_left

    for row_index in range(height):
        filter_type = scanlines[position]
        position += 1
        current = bytearray(scanlines[position : position + stride])
        position += stride
        if filter_type not in {0, 1, 2, 3, 4}:
            raise TestFailure("Chromes PNG verwendet einen unbekannten Zeilenfilter.")
        for index in range(stride):
            left = current[index - channels] if index >= channels else 0
            above = previous[index]
            upper_left = previous[index - channels] if index >= channels else 0
            if filter_type == 1:
                current[index] = (current[index] + left) & 0xFF
            elif filter_type == 2:
                current[index] = (current[index] + above) & 0xFF
            elif filter_type == 3:
                current[index] = (current[index] + ((left + above) // 2)) & 0xFF
            elif filter_type == 4:
                current[index] = (
                    current[index] + paeth(left, above, upper_left)
                ) & 0xFF
        for index in range(0, stride, channels):
            red, green, blue = current[index : index + 3]
            if abs(red - 162) <= 8 and abs(green - 217) <= 8 and abs(blue - 247) <= 8:
                blue_pixels += 1
                column = index // channels
                blue_min_x = min(blue_min_x, column)
                blue_max_x = max(blue_max_x, column)
                blue_min_y = min(blue_min_y, row_index)
                blue_max_y = max(blue_max_y, row_index)
            if red <= 55 and green <= 55 and blue <= 55:
                dark_pixels += 1
            if red < 245 or green < 245 or blue < 245:
                non_white_pixels += 1
            if red >= 248 and green >= 248 and blue >= 248:
                white_pixels += 1
        decoded_rows.append(bytes(current))
        previous = current

    blue_bounds_width = 0
    blue_bounds_height = 0
    dark_pixels_in_blue_bounds = 0
    if blue_max_x >= blue_min_x and blue_max_y >= blue_min_y:
        blue_bounds_width = blue_max_x - blue_min_x + 1
        blue_bounds_height = blue_max_y - blue_min_y + 1
        for row in decoded_rows[blue_min_y : blue_max_y + 1]:
            for column in range(blue_min_x, blue_max_x + 1):
                index = column * channels
                red, green, blue = row[index : index + 3]
                if red <= 55 and green <= 55 and blue <= 55:
                    dark_pixels_in_blue_bounds += 1
    return {
        "width": width,
        "height": height,
        "blue_pixels": blue_pixels,
        "blue_bounds_width": blue_bounds_width,
        "blue_bounds_height": blue_bounds_height,
        "dark_pixels_in_blue_bounds": dark_pixels_in_blue_bounds,
        "dark_pixels": dark_pixels,
        "non_white_pixels": non_white_pixels,
        "white_pixels": white_pixels,
    }


@dataclasses.dataclass(frozen=True)
class TestConfig:
    base_url: str
    login_name: str
    login_code: str
    login_function: str
    login_password: str = dataclasses.field(repr=False)
    workflow_marker: str | None = None
    message_suggestion_marker: str | None = None
    message_overview_subject: str | None = None
    admin_user: str | None = None
    admin_password: str | None = dataclasses.field(default=None, repr=False)
    timeout: float = 25.0
    startup_timeout: float = 15.0

    @classmethod
    def from_environment(
        cls,
        require_login_password: bool = True,
    ) -> TestConfig:
        base_url = os.environ.get("ESTAB_TEST_BASE_URL", "http://127.0.0.1:8080").rstrip("/")
        parsed = urllib.parse.urlsplit(base_url)
        if (
            parsed.scheme not in {"http", "https"}
            or not parsed.netloc
            or parsed.username
            or parsed.password
            or parsed.query
            or parsed.fragment
        ):
            raise TestFailure("ESTAB_TEST_BASE_URL muss eine HTTP(S)-Basis-URL ohne Zugangsdaten sein.")

        login_name = os.environ.get("ESTAB_TEST_LOGIN_NAME", "Browser Acceptance")
        login_code = os.environ.get("ESTAB_TEST_LOGIN_CODE", "brw001").lower()
        login_function = os.environ.get("ESTAB_TEST_LOGIN_FUNCTION", "S1")
        password = os.environ.get("ESTAB_TEST_LOGIN_PASSWORD")
        password_file = os.environ.get("ESTAB_TEST_LOGIN_PASSWORD_FILE")
        if password is None and password_file:
            try:
                password = pathlib.Path(password_file).read_text(encoding="utf-8").rstrip("\r\n")
            except OSError as exc:
                raise TestFailure("Die Datei aus ESTAB_TEST_LOGIN_PASSWORD_FILE ist nicht lesbar.") from exc
        if password is None:
            if require_login_password:
                raise TestFailure(
                    "Der vollständige Browsertest benötigt das Kennwort des "
                    "vorher provisionierten Kontos in "
                    "ESTAB_TEST_LOGIN_PASSWORD(_FILE)."
                )
            # Read-only/admin-only modes never submit this value. Keeping a
            # non-empty placeholder lets them share one validated config
            # object without pretending a random secret could authenticate an
            # existing account.
            password = "unused-in-this-browser-test-mode"
        if not password:
            raise TestFailure("Das konfigurierte Browser-Testkennwort darf nicht leer sein.")
        if not login_name.strip():
            raise TestFailure("ESTAB_TEST_LOGIN_NAME darf nicht leer sein.")
        if not re.fullmatch(r"[a-z0-9_]{1,6}", login_code):
            raise TestFailure(
                "ESTAB_TEST_LOGIN_CODE muss aus 1 bis 6 Kleinbuchstaben, Ziffern oder _ bestehen."
            )
        if not re.fullmatch(r"[A-Za-z0-9_/-]{1,20}", login_function):
            raise TestFailure("ESTAB_TEST_LOGIN_FUNCTION enthält nicht unterstützte Zeichen.")
        workflow_marker = os.environ.get("ESTAB_TEST_WORKFLOW_MARKER")
        if (
            workflow_marker is not None
            and re.fullmatch(
                r"ESTAB_BACKUP_ROUNDTRIP_[a-f0-9]{16}",
                workflow_marker,
            )
            is None
        ):
            raise TestFailure("ESTAB_TEST_WORKFLOW_MARKER ist ungültig.")
        message_suggestion_marker = os.environ.get(
            "ESTAB_TEST_MESSAGE_SUGGESTION_MARKER"
        )
        if (
            message_suggestion_marker is not None
            and re.fullmatch(
                r"BROWSER-GEGENSTELLE-[a-f0-9]{16}",
                message_suggestion_marker,
            )
            is None
        ):
            raise TestFailure(
                "ESTAB_TEST_MESSAGE_SUGGESTION_MARKER ist ungültig."
            )

        message_overview_subject = os.environ.get(
            "ESTAB_TEST_MESSAGE_OVERVIEW_SUBJECT"
        )
        if message_overview_subject is not None:
            if re.search(r"[\x00-\x1F\x7F]", message_overview_subject):
                raise TestFailure(
                    "ESTAB_TEST_MESSAGE_OVERVIEW_SUBJECT darf keine "
                    "Steuerzeichen enthalten."
                )
            message_overview_subject = re.sub(
                r"\s+", " ", message_overview_subject
            ).strip()
            if (
                not message_overview_subject
                or len(message_overview_subject) > 120
            ):
                raise TestFailure(
                    "ESTAB_TEST_MESSAGE_OVERVIEW_SUBJECT muss eine "
                    "nichtleere, höchstens 120 Zeichen lange Überschrift sein."
                )

        admin_user = os.environ.get("ESTAB_TEST_ADMIN_USER")
        admin_password = os.environ.get("ESTAB_TEST_ADMIN_PASSWORD")
        admin_password_file = os.environ.get("ESTAB_TEST_ADMIN_PASSWORD_FILE")
        if admin_password is None and admin_password_file:
            try:
                admin_password = pathlib.Path(admin_password_file).read_text(
                    encoding="utf-8"
                ).rstrip("\r\n")
            except OSError as exc:
                raise TestFailure(
                    "Die Datei aus ESTAB_TEST_ADMIN_PASSWORD_FILE ist nicht lesbar."
                ) from exc
        if bool(admin_user) != bool(admin_password):
            raise TestFailure(
                "Für den Browser-Admin-Test müssen Benutzer und Kennwort gemeinsam gesetzt sein."
            )
        if admin_user and not re.fullmatch(r"[A-Za-z0-9_.-]{1,128}", admin_user):
            raise TestFailure("ESTAB_TEST_ADMIN_USER enthält nicht unterstützte Zeichen.")

        timeout = _positive_float("ESTAB_BROWSER_TIMEOUT", 25.0)
        startup_timeout = _positive_float("ESTAB_BROWSER_STARTUP_TIMEOUT", 15.0)
        return cls(
            base_url=base_url,
            login_name=login_name.strip(),
            login_code=login_code,
            login_function=login_function,
            login_password=password,
            workflow_marker=workflow_marker,
            message_suggestion_marker=message_suggestion_marker,
            message_overview_subject=message_overview_subject,
            admin_user=admin_user,
            admin_password=admin_password,
            timeout=timeout,
            startup_timeout=startup_timeout,
        )


def _positive_float(name: str, default: float) -> float:
    raw = os.environ.get(name)
    if raw is None:
        return default
    try:
        value = float(raw)
    except ValueError as exc:
        raise TestFailure(f"{name} muss eine positive Zahl sein.") from exc
    if value <= 0:
        raise TestFailure(f"{name} muss eine positive Zahl sein.")
    return value


def _enabled(name: str) -> bool:
    return os.environ.get(name, "").strip().lower() in {"1", "true", "yes", "on"}


def find_chrome() -> pathlib.Path:
    configured = os.environ.get("ESTAB_BROWSER_BINARY")
    if configured:
        candidate = pathlib.Path(configured).expanduser()
        if candidate.is_file() and os.access(candidate, os.X_OK):
            return candidate.resolve()
        raise TestFailure("ESTAB_BROWSER_BINARY verweist nicht auf eine ausführbare Datei.")

    command_names = (
        "google-chrome",
        "google-chrome-stable",
        "chromium",
        "chromium-browser",
        "chrome",
    )
    for command_name in command_names:
        resolved = shutil.which(command_name)
        if resolved:
            return pathlib.Path(resolved).resolve()

    home = pathlib.Path.home()
    candidates = (
        pathlib.Path("/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"),
        pathlib.Path(
            "/Applications/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing"
        ),
        pathlib.Path("/Applications/Chromium.app/Contents/MacOS/Chromium"),
        home / "Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
        home
        / "Applications/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing",
        home / "Applications/Chromium.app/Contents/MacOS/Chromium",
        pathlib.Path("/usr/bin/google-chrome"),
        pathlib.Path("/usr/bin/google-chrome-stable"),
        pathlib.Path("/usr/bin/chromium"),
        pathlib.Path("/usr/bin/chromium-browser"),
        pathlib.Path("/snap/bin/chromium"),
    )
    for candidate in candidates:
        if candidate.is_file() and os.access(candidate, os.X_OK):
            return candidate.resolve()

    raise TestFailure(
        "Kein Chrome/Chromium gefunden. ESTAB_BROWSER_BINARY kann den Pfad explizit setzen."
    )


def browser_version(binary: pathlib.Path) -> str:
    try:
        result = subprocess.run(
            [str(binary), "--version"],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=5,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise TestFailure("Browser-Version konnte nicht ermittelt werden.") from exc
    version = re.sub(r"\s+", " ", result.stdout).strip()
    if result.returncode != 0 or not version:
        raise TestFailure("Browser-Version konnte nicht ermittelt werden.")
    return version[:200]


class ChromeProcess:
    def __init__(self, binary: pathlib.Path, startup_timeout: float) -> None:
        self.binary = binary
        self.startup_timeout = startup_timeout
        self._profile: tempfile.TemporaryDirectory[str] | None = None
        self._resources: contextlib.ExitStack | None = None
        self._log: BinaryIO | None = None
        self.process: subprocess.Popen[bytes] | None = None
        self.websocket_url: str | None = None

    def start(self) -> None:
        try:
            self._profile = tempfile.TemporaryDirectory(
                prefix="estab-chrome-profile-"
            )
            self._resources = contextlib.ExitStack()
            self._log = self._resources.enter_context(
                tempfile.TemporaryFile(mode="w+b")  # noqa: SIM115
            )
        except OSError as exc:
            self.close()
            raise TestFailure(
                "Chrome-Ressourcen konnten nicht angelegt werden."
            ) from exc
        profile_path = pathlib.Path(self._profile.name)
        command = [
            str(self.binary),
            "--headless=new",
            "--disable-gpu",
            "--disable-dev-shm-usage",
            "--no-first-run",
            "--no-default-browser-check",
            "--remote-debugging-port=0",
            f"--user-data-dir={profile_path}",
            "--window-size=1440,1000",
        ]
        if _enabled("ESTAB_BROWSER_NO_SANDBOX"):
            command.append("--no-sandbox")
        command.append("about:blank")
        try:
            self.process = subprocess.Popen(
                command,
                stdin=subprocess.DEVNULL,
                stdout=self._log,
                stderr=self._log,
                start_new_session=os.name == "posix",
            )
        except OSError as exc:
            self.close()
            raise TestFailure("Chrome konnte nicht gestartet werden.") from exc

        active_port_file = profile_path / "DevToolsActivePort"
        deadline = time.monotonic() + self.startup_timeout
        port: int | None = None
        while time.monotonic() < deadline:
            if self.process.poll() is not None:
                self.close()
                raise TestFailure("Chrome wurde beim Start unerwartet beendet.")
            try:
                lines = active_port_file.read_text(encoding="ascii").splitlines()
                if lines:
                    port = int(lines[0])
                    break
            except (OSError, ValueError):
                pass
            time.sleep(0.05)
        if port is None:
            self.close()
            raise TestFailure("Timeout beim Start der Chrome-Debugging-Schnittstelle.")

        endpoint = f"http://127.0.0.1:{port}/json/list"
        last_error: Exception | None = None
        while time.monotonic() < deadline:
            try:
                with urllib.request.urlopen(endpoint, timeout=1.0) as response:
                    targets = json.load(response)
                pages = [
                    target
                    for target in targets
                    if target.get("type") == "page" and target.get("webSocketDebuggerUrl")
                ]
                if pages:
                    self.websocket_url = str(pages[0]["webSocketDebuggerUrl"])
                    return
            except (OSError, ValueError, json.JSONDecodeError) as exc:
                last_error = exc
            time.sleep(0.05)
        self.close()
        raise TestFailure("Chrome hat kein steuerbares Browser-Tab bereitgestellt.") from last_error

    def close(self) -> None:
        process = self.process
        self.process = None
        if process is not None:
            # Chromium helpers may outlive the browser parent and continue to
            # write into its profile. The isolated group makes cleanup atomic.
            group_signalled = False
            if os.name == "posix":
                try:
                    os.killpg(process.pid, signal.SIGTERM)
                    group_signalled = True
                except ProcessLookupError:
                    pass
                except PermissionError:
                    pass
            if not group_signalled and process.poll() is None:
                process.terminate()
            if process.poll() is None:
                try:
                    process.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    if os.name == "posix":
                        try:
                            os.killpg(process.pid, signal.SIGKILL)
                        except (ProcessLookupError, PermissionError):
                            process.kill()
                    else:
                        process.kill()
                    process.wait(timeout=3)
            if os.name == "posix":
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except (ProcessLookupError, PermissionError):
                    pass
        resources = self._resources
        self._resources = None
        self._log = None
        if resources is not None:
            resources.close()
        if self._profile is not None:
            profile = self._profile
            self._profile = None
            for attempt in range(5):
                try:
                    profile.cleanup()
                    break
                except OSError:
                    if attempt == 4:
                        raise
                    time.sleep(0.05 * (attempt + 1))

    def __enter__(self) -> Self:
        self.start()
        return self

    def __exit__(
        self,
        _exc_type: type[BaseException] | None,
        _exc: BaseException | None,
        _traceback: TracebackType | None,
    ) -> None:
        self.close()


class WebSocket:
    """Small RFC 6455 client sufficient for Chrome's local CDP endpoint."""

    def __init__(self, url: str, timeout: float) -> None:
        parsed = urllib.parse.urlsplit(url)
        if parsed.scheme not in {"ws", "wss"} or not parsed.hostname:
            raise TestFailure("Chrome lieferte eine ungültige WebSocket-Adresse.")
        port = parsed.port or (443 if parsed.scheme == "wss" else 80)
        try:
            raw_socket = socket.create_connection((parsed.hostname, port), timeout=timeout)
            if parsed.scheme == "wss":
                context = ssl.create_default_context()
                raw_socket = context.wrap_socket(raw_socket, server_hostname=parsed.hostname)
        except OSError as exc:
            raise TestFailure("Verbindung zur Chrome-Debugging-Schnittstelle fehlgeschlagen.") from exc
        self.socket = raw_socket
        self.buffer = bytearray()
        self.fragments = bytearray()
        self.fragment_opcode: int | None = None

        key = base64.b64encode(secrets.token_bytes(16)).decode("ascii")
        path = urllib.parse.urlunsplit(("", "", parsed.path or "/", parsed.query, ""))
        host = parsed.hostname
        if port not in {80, 443}:
            host = f"{host}:{port}"
        request = (
            f"GET {path} HTTP/1.1\r\n"
            f"Host: {host}\r\n"
            "Upgrade: websocket\r\n"
            "Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\n"
            "Sec-WebSocket-Version: 13\r\n"
            "\r\n"
        ).encode("ascii")
        try:
            self.socket.sendall(request)
            header = self._read_until(b"\r\n\r\n", 65536)
        except OSError as exc:
            self.close()
            raise TestFailure("WebSocket-Handshake mit Chrome fehlgeschlagen.") from exc
        header_text = header.decode("iso-8859-1")
        if not header_text.startswith("HTTP/1.1 101"):
            self.close()
            raise TestFailure("Chrome hat den WebSocket-Handshake abgelehnt.")
        expected = base64.b64encode(
            hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode("ascii")).digest()
        ).decode("ascii")
        headers = {}
        for line in header_text.split("\r\n")[1:]:
            if ":" in line:
                name, value = line.split(":", 1)
                headers[name.strip().lower()] = value.strip()
        if headers.get("sec-websocket-accept") != expected:
            self.close()
            raise TestFailure("Chrome lieferte eine ungültige WebSocket-Bestätigung.")

    def _read_until(self, marker: bytes, limit: int) -> bytes:
        while marker not in self.buffer:
            chunk = self.socket.recv(4096)
            if not chunk:
                raise ConnectionError("WebSocket closed")
            self.buffer.extend(chunk)
            if len(self.buffer) > limit:
                raise ConnectionError("WebSocket header too large")
        end = self.buffer.index(marker) + len(marker)
        result = bytes(self.buffer[:end])
        del self.buffer[:end]
        return result

    def _read_exact(self, size: int) -> bytes:
        while len(self.buffer) < size:
            chunk = self.socket.recv(max(4096, size - len(self.buffer)))
            if not chunk:
                raise ConnectionError("WebSocket closed")
            self.buffer.extend(chunk)
        result = bytes(self.buffer[:size])
        del self.buffer[:size]
        return result

    def _send_frame(self, opcode: int, payload: bytes) -> None:
        header = bytearray([0x80 | opcode])
        length = len(payload)
        if length < 126:
            header.append(0x80 | length)
        elif length < 65536:
            header.append(0x80 | 126)
            header.extend(struct.pack("!H", length))
        else:
            header.append(0x80 | 127)
            header.extend(struct.pack("!Q", length))
        mask = secrets.token_bytes(4)
        header.extend(mask)
        masked = bytes(value ^ mask[index % 4] for index, value in enumerate(payload))
        self.socket.sendall(header + masked)

    def send_json(self, value: Any) -> None:
        self._send_frame(0x1, json.dumps(value, separators=(",", ":")).encode("utf-8"))

    def receive_json(self, deadline: float) -> Any:
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise TimeoutError
            self.socket.settimeout(remaining)
            first, second = self._read_exact(2)
            finished = bool(first & 0x80)
            opcode = first & 0x0F
            masked = bool(second & 0x80)
            length = second & 0x7F
            if length == 126:
                length = struct.unpack("!H", self._read_exact(2))[0]
            elif length == 127:
                length = struct.unpack("!Q", self._read_exact(8))[0]
            mask = self._read_exact(4) if masked else b""
            payload = self._read_exact(length)
            if masked:
                payload = bytes(value ^ mask[index % 4] for index, value in enumerate(payload))

            if opcode == 0x8:
                raise ConnectionError("WebSocket closed")
            if opcode == 0x9:
                self._send_frame(0xA, payload)
                continue
            if opcode == 0xA:
                continue
            if opcode in {0x1, 0x2}:
                self.fragment_opcode = opcode
                self.fragments = bytearray(payload)
            elif opcode == 0x0 and self.fragment_opcode is not None:
                self.fragments.extend(payload)
            else:
                continue
            if not finished:
                continue
            completed_opcode = self.fragment_opcode
            completed = bytes(self.fragments)
            self.fragment_opcode = None
            self.fragments.clear()
            if completed_opcode != 0x1:
                continue
            return json.loads(completed.decode("utf-8"))

    def close(self) -> None:
        sock = getattr(self, "socket", None)
        if sock is None:
            return
        self.socket = None  # type: ignore[assignment]
        try:
            self._send_frame(0x8, b"")
        except (OSError, AttributeError):
            pass
        try:
            sock.close()
        except OSError:
            pass

    def __enter__(self) -> Self:
        return self

    def __exit__(
        self,
        _exc_type: type[BaseException] | None,
        _exc: BaseException | None,
        _traceback: TracebackType | None,
    ) -> None:
        self.close()


class CDP:
    def __init__(self, websocket: WebSocket, timeout: float) -> None:
        self.websocket = websocket
        self.timeout = timeout
        self.next_id = 1
        self.events: list[dict[str, Any]] = []

    def _queue_event(self, message: dict[str, Any]) -> None:
        if not isinstance(message.get("method"), str):
            return
        self.events.append(message)
        if len(self.events) > 256:
            del self.events[:-256]

    def call(self, method: str, params: dict[str, Any] | None = None) -> dict[str, Any]:
        call_id = self.next_id
        self.next_id += 1
        message: dict[str, Any] = {"id": call_id, "method": method}
        if params is not None:
            message["params"] = params
        try:
            self.websocket.send_json(message)
            deadline = time.monotonic() + self.timeout
            while True:
                response = self.websocket.receive_json(deadline)
                if response.get("id") != call_id:
                    if "method" in response:
                        self._queue_event(response)
                    continue
                if "error" in response:
                    raise TestFailure(f"Chrome-CDP-Aufruf {method} ist fehlgeschlagen.")
                return response.get("result", {})
        except (OSError, ConnectionError, TimeoutError, ValueError) as exc:
            raise TestFailure(f"Timeout oder Verbindungsfehler bei Chrome-CDP-Aufruf {method}.") from exc

    def discard_events(self, method: str) -> None:
        self.events = [
            event for event in self.events if event.get("method") != method
        ]

    def call_with_javascript_dialog(
        self,
        method: str,
        params: dict[str, Any],
        *,
        accept: bool,
        description: str,
    ) -> tuple[dict[str, Any], dict[str, Any]]:
        event_method = "Page.javascriptDialogOpening"
        self.discard_events(event_method)
        command_id = self.next_id
        self.next_id += 1
        command_result: dict[str, Any] | None = None
        dialog: dict[str, Any] | None = None
        handler_id: int | None = None
        handler_finished = False
        deadline = time.monotonic() + self.timeout
        try:
            self.websocket.send_json(
                {"id": command_id, "method": method, "params": params}
            )
            while True:
                message = self.websocket.receive_json(deadline)
                if message.get("method") == event_method and dialog is None:
                    raw_dialog = message.get("params")
                    dialog = raw_dialog if isinstance(raw_dialog, dict) else {}
                    handler_id = self.next_id
                    self.next_id += 1
                    self.websocket.send_json(
                        {
                            "id": handler_id,
                            "method": "Page.handleJavaScriptDialog",
                            "params": {"accept": accept},
                        }
                    )
                elif message.get("id") == command_id:
                    if "error" in message:
                        raise TestFailure(
                            f"Chrome-CDP-Aufruf {method} ist fehlgeschlagen."
                        )
                    raw_result = message.get("result")
                    command_result = raw_result if isinstance(raw_result, dict) else {}
                elif handler_id is not None and message.get("id") == handler_id:
                    if "error" in message:
                        raise TestFailure(
                            "Chrome konnte den nativen Bestätigungsdialog nicht beantworten."
                        )
                    handler_finished = True
                elif "method" in message:
                    self._queue_event(message)

                if (
                    command_result is not None
                    and dialog is not None
                    and handler_finished
                ):
                    return command_result, dialog
        except (OSError, ConnectionError, TimeoutError, ValueError) as exc:
            raise TestFailure(f"Timeout: {description}.") from exc

    def evaluate(self, expression: str) -> Any:
        result = self.call(
            "Runtime.evaluate",
            {
                "expression": expression,
                "returnByValue": True,
                "awaitPromise": True,
                "userGesture": True,
            },
        )
        exception_details = result.get("exceptionDetails")
        if isinstance(exception_details, dict):
            exception = exception_details.get("exception")
            description = (
                exception.get("description")
                if isinstance(exception, dict)
                else exception_details.get("text")
            )
            suffix = f": {description}" if description else "."
            raise TestFailure(
                "JavaScript-Auswertung im Browser ist fehlgeschlagen"
                + suffix
            )
        return result.get("result", {}).get("value")

    def wait_for(self, expression: str, description: str, timeout: float | None = None) -> Any:
        deadline = time.monotonic() + (timeout if timeout is not None else self.timeout)
        while time.monotonic() < deadline:
            try:
                value = self.evaluate(expression)
                if value:
                    return value
            except TestFailure:
                pass
            time.sleep(0.1)
        raise TestFailure(f"Timeout: {description}.")

    def navigate(self, url: str) -> None:
        result = self.call("Page.navigate", {"url": url})
        if result.get("errorText"):
            raise TestFailure("Chrome konnte die Test-URL nicht laden.")
        expected_url = json.dumps(url)
        self.wait_for(
            f"""
            (() => {{
                const expected = new URL({expected_url});
                return document.readyState === "complete" &&
                    location.origin === expected.origin &&
                    location.pathname === expected.pathname &&
                    location.search === expected.search;
            }})()
            """,
            "Zielseite wurde nicht vollständig geladen",
        )

    def click(
        self,
        frame_name: str | None,
        selector: str,
        description: str,
        *,
        dialog_accept: bool | None = None,
    ) -> dict[str, Any] | None:
        position_expression = _frame_expression(
            frame_name,
            f"""
            const element = doc.querySelector({json.dumps(selector)});
            if (!element || element.disabled) return null;
            const style = target.getComputedStyle(element);
            const rect = element.getBoundingClientRect();
            if (style.display === "none" || style.visibility === "hidden" ||
                rect.width <= 0 || rect.height <= 0) return null;
            element.scrollIntoView({{block: "center", inline: "center"}});
            const updated = element.getBoundingClientRect();
            const left = Math.max(0, updated.left);
            const right = Math.min(target.innerWidth, updated.right);
            const top = Math.max(0, updated.top);
            const bottom = Math.min(target.innerHeight, updated.bottom);
            if (right <= left || bottom <= top) return null;
            const xCandidates = [
                left + (right - left) / 2,
                left + Math.min(4, (right - left) / 2),
                right - Math.min(4, (right - left) / 2)
            ];
            const yCandidates = [
                bottom - Math.min(4, (bottom - top) / 2),
                top + (bottom - top) / 2,
                top + Math.min(4, (bottom - top) / 2)
            ];
            let x = null;
            let y = null;
            for (const candidateY of yCandidates) {{
                for (const candidateX of xCandidates) {{
                    const hit = doc.elementFromPoint(candidateX, candidateY);
                    if (hit && (hit === element || element.contains(hit))) {{
                        x = candidateX;
                        y = candidateY;
                        break;
                    }}
                }}
                if (x !== null) break;
            }}
            if (x === null || y === null) return null;
            let current = target;
            while (current !== current.top) {{
                const frame = current.frameElement;
                if (!frame) return null;
                const frameRect = frame.getBoundingClientRect();
                x += frameRect.left;
                y += frameRect.top;
                current = current.parent;
            }}
            return {{x, y}};
            """,
        )
        position = self.wait_for(position_expression, f"{description} ist nicht anklickbar")
        x = float(position["x"])
        y = float(position["y"])
        self.call("Input.dispatchMouseEvent", {"type": "mouseMoved", "x": x, "y": y})
        self.call(
            "Input.dispatchMouseEvent",
            {
                "type": "mousePressed",
                "x": x,
                "y": y,
                "button": "left",
                "clickCount": 1,
            },
        )
        release = {
            "type": "mouseReleased",
            "x": x,
            "y": y,
            "button": "left",
            "clickCount": 1,
        }
        if dialog_accept is None:
            self.call("Input.dispatchMouseEvent", release)
            return None
        _, dialog = self.call_with_javascript_dialog(
            "Input.dispatchMouseEvent",
            release,
            accept=dialog_accept,
            description=f"nativer Bestätigungsdialog für {description} fehlt",
        )
        return dialog

    def set_value(
        self,
        frame_name: str,
        selector: str,
        value: str,
        description: str,
        *,
        select: bool = False,
    ) -> None:
        prototype = "HTMLSelectElement" if select else "HTMLInputElement"
        expression = _frame_expression(
            frame_name,
            f"""
            const element = doc.querySelector({json.dumps(selector)});
            if (!element) return false;
            const desired = {json.dumps(value)};
            if ({str(select).lower()} &&
                !Array.from(element.options).some(option => option.value === desired)) {{
                return false;
            }}
            const descriptor = Object.getOwnPropertyDescriptor(
                target.{prototype}.prototype, "value"
            );
            descriptor.set.call(element, desired);
            element.dispatchEvent(new target.Event("input", {{bubbles: true}}));
            element.dispatchEvent(new target.Event("change", {{bubbles: true}}));
            return element.value === desired;
            """,
        )
        if not self.evaluate(expression):
            raise TestFailure(f"{description} konnte nicht gesetzt werden.")


def _frame_expression(frame_name: str | None, body: str) -> str:
    frame_literal = "null" if frame_name is None else json.dumps(frame_name)
    return f"""
    (() => {{
        const requested = {frame_literal};
        const findFrame = (root, name) => {{
            for (let index = 0; index < root.frames.length; index += 1) {{
                const child = root.frames[index];
                try {{
                    if (child.name === name) return child;
                    const nested = findFrame(child, name);
                    if (nested) return nested;
                }} catch (_error) {{
                    // The ESTAB frames are same-origin; ignore unrelated inaccessible frames.
                }}
            }}
            return null;
        }};
        const target = requested === null ? window : findFrame(window, requested);
        if (!target) return null;
        let doc;
        try {{
            doc = target.document;
        }} catch (_error) {{
            return null;
        }}
        {body}
    }})()
    """


def _visible_count_expression(frame_name: str | None, selector: str) -> str:
    return _frame_expression(
        frame_name,
        f"""
        const visible = element => {{
            const style = target.getComputedStyle(element);
            const rect = element.getBoundingClientRect();
            return style.display !== "none" && style.visibility !== "hidden" &&
                rect.width > 0 && rect.height > 0;
        }};
        return Array.from(doc.querySelectorAll({json.dumps(selector)})).filter(visible).length;
        """,
    )


def _text_expression(frame_name: str | None, selector: str) -> str:
    return _frame_expression(
        frame_name,
        f"""
        const element = doc.querySelector({json.dumps(selector)});
        return element ? element.innerText.replace(/\\s+/g, " ").trim() : null;
        """,
    )


class BrowserAcceptance:
    navigation_keys = (
        "overview",
        "messages",
        "command-post",
        "message-overview",
        "forms",
        "incident-log",
        "technical-log",
        "tracking",
        "bos-info",
    )
    protected_card_keys = (
        "messages",
        "command-post",
        "message-overview",
        "forms",
        "incident-log",
        "technical-log",
        "tracking",
    )
    root_card_keys = (
        *protected_card_keys,
        "bos-info",
        "administration",
        "handbook",
    )
    protected_redirects = (
        ("4fach/fuehrungsstelle.php", "command-post", "4fach/index.php"),
        ("4fach/vordrucke.php", "forms", "4fach/index.php"),
        ("4fueltg/ue_ltg.php", "message-overview", "4fach/index.php"),
        ("stabetb/etb.php", "incident-log", "4fach/index.php"),
        ("fmtbb/tbb.php", "technical-log", "4fach/index.php"),
        ("4fach/nachwea.php?nwalle", "tracking", "4fach/index.php"),
        ("4fach/anhang.php", "messages", "4fach/mainindex.php"),
        (
            "4fach/katgoedt.php?dbtyp=fkt&msgno=1",
            "messages",
            "4fach/mainindex.php",
        ),
        (
            "4fach/download.php?area=vordruck&file=EL0001.pdf",
            "forms",
            "4fach/index.php",
        ),
    )
    bos_documents = (
        ("Buchstabier.html", "Buchstabieralphabet"),
        ("Kartendatum.html", "Neues Kartendatum"),
        ("IuK-InfoPack.html", "Stabzusammensetzung"),
        ("Orgas.html", "Behörden und Organisationen"),
        ("FF-Rufnamenschema.html", "F-Rufnamenregel"),
        ("DRK%20Rufnamenschema.html", "DRK-Rufnamenregel"),
        ("THWFuRNR.html", "THW-Rufnamenregel"),
    )

    def __init__(self, cdp: CDP, config: TestConfig) -> None:
        self.cdp = cdp
        self.config = config

    def _authenticated_navigation_keys(self) -> list[str]:
        """Return LOOSE areas from primary and explicit extra functions."""
        keys = list(self.navigation_keys)
        if self.config.login_function != "S2":
            keys.remove("message-overview")
        if self.config.login_function not in ("LdF", "A/W"):
            keys.remove("tracking")
        return keys

    def _authenticated_navigation_link_count(self) -> int:
        # The two public service links (administration and handbook) remain
        # available alongside the function-filtered operational areas.
        return len(self._authenticated_navigation_keys()) + 2

    def run_overview(self) -> None:
        """Check the anonymous start page without changing application data."""
        self.cdp.call("Page.enable")
        self.cdp.call("Runtime.enable")
        self.cdp.call("Network.enable")
        self.cdp.navigate(self.config.base_url + "/")
        self._assert_anonymous_overview(registration_allowed=None)
        self._assert_protected_cards()
        self._assert_root_card_layout("anonyme Übersicht bei 1440 px")

    def run_handbook(self) -> None:
        """Check the public handbook, local search and responsive layout."""
        self.cdp.call("Page.enable")
        self.cdp.call("Runtime.enable")
        self.cdp.call("Network.enable")
        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 1440,
                "height": 1000,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 1440,
                "screenHeight": 1000,
            },
        )
        self.cdp.navigate(self.config.base_url + "/handbuch/")
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            Boolean(document.querySelector("[data-estab-handbook]")) &&
            document.querySelectorAll("[data-estab-handbook-section]").length === 19
            """,
            "öffentliches Web-Handbuch wurde nicht vollständig geladen",
        )

        desktop = self.cdp.evaluate(
            """
            (() => {
                const visible = element => {
                    const style = getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    return style.display !== "none" &&
                        style.visibility !== "hidden" &&
                        rect.width > 0 && rect.height > 0;
                };
                const navigation = document.querySelector(
                    "aside[data-estab-public-bar] [data-estab-navigation]"
                );
                const toc = document.querySelector(".estab-handbook-toc");
                const search = document.querySelector("[data-estab-handbook-search]");
                const ids = Array.from(document.querySelectorAll(
                    "[data-estab-handbook-section]"
                )).map(section => section.id);
                return {
                    title: document.title,
                    h1: document.querySelectorAll("h1").length,
                    publicBars: document.querySelectorAll(
                        "aside[data-estab-public-bar]"
                    ).length,
                    sessionBars: document.querySelectorAll(
                        "aside[data-estab-session-bar]"
                    ).length,
                    active: navigation
                        ? Array.from(navigation.querySelectorAll(
                            '[aria-current="page"]'
                        )).map(link => link.getAttribute("data-estab-nav-key"))
                        : [],
                    sections: ids.length,
                    uniqueIds: new Set(ids).size,
                    tocLinks: document.querySelectorAll(
                        "[data-estab-handbook-toc] a[href^='#']"
                    ).length,
                    tocVisible: Boolean(toc && visible(toc)),
                    tocOverflow: toc ? getComputedStyle(toc).overflowY : null,
                    searchVisible: Boolean(search && visible(search)),
                    searchLabelled: Boolean(search && search.labels.length === 1),
                    statusLive: document.querySelector(
                        '[data-estab-handbook-status][role="status"]' +
                        '[aria-live="polite"][aria-atomic="true"]'
                    ) !== null,
                    pageFits: document.documentElement.scrollWidth <=
                        document.documentElement.clientWidth + 1
                };
            })()
            """
        )
        self._truth(
            isinstance(desktop, dict)
            and desktop.get("title") == "eStab Web-Handbuch"
            and desktop.get("h1") == 1
            and desktop.get("publicBars") == 1
            and desktop.get("sessionBars") == 0
            and desktop.get("active") == ["handbook"]
            and desktop.get("sections") == 19
            and desktop.get("uniqueIds") == 19
            and desktop.get("tocLinks") == 19
            and desktop.get("tocVisible") is True
            and desktop.get("tocOverflow") not in ("auto", "scroll")
            and desktop.get("searchVisible") is True
            and desktop.get("searchLabelled") is True
            and desktop.get("statusLive") is True
            and desktop.get("pageFits") is True,
            f"Desktop-Handbuch ist unvollständig oder nicht responsiv: {desktop!r}",
        )

        self.cdp.set_value(
            None,
            "[data-estab-handbook-search]",
            "Beförderungsweg Absender",
            "Handbuchsuche",
        )
        search_state = self.cdp.wait_for(
            """
            (() => {
                const sections = Array.from(document.querySelectorAll(
                    "[data-estab-handbook-section]"
                ));
                const visible = sections.filter(section => !section.hidden);
                const status = document.querySelector(
                    "[data-estab-handbook-status]"
                );
                const clear = document.querySelector(
                    "[data-estab-handbook-clear]"
                );
                return visible.length > 0 && visible.length < sections.length &&
                    visible.some(section => section.id === "nachrichtenlauf") &&
                    status && status.textContent.includes("passende") &&
                    clear && !clear.hidden ? {
                        visible: visible.map(section => section.id),
                        status: status.textContent.trim(),
                        query: new URL(location.href).searchParams.get("q")
                    } : null;
            })()
            """,
            "lokale AND-Suche des Web-Handbuchs filtert nicht nachvollziehbar",
        )
        self._equal(
            search_state.get("query"),
            "Beförderungsweg Absender",
            "Suchbegriff in der Handbuch-URL",
        )
        self.cdp.click(
            None,
            "[data-estab-handbook-clear]",
            "Handbuchsuche löschen",
        )
        self.cdp.wait_for(
            """
            document.querySelectorAll(
                "[data-estab-handbook-section]:not([hidden])"
            ).length === 19 &&
            !new URL(location.href).searchParams.has("q")
            """,
            "gelöschte Handbuchsuche stellt nicht alle Kapitel wieder her",
        )

        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 390,
                "height": 844,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 390,
                "screenHeight": 844,
            },
        )
        mobile = self.cdp.evaluate(
            """
            (() => {
                const bounds = element => {
                    const rect = element.getBoundingClientRect();
                    return {
                        left: rect.left,
                        right: rect.right,
                        width: rect.width,
                        height: rect.height
                    };
                };
                const main = document.querySelector(".estab-handbook-main");
                const search = document.querySelector(".estab-handbook-search");
                const toc = document.querySelector(".estab-handbook-toc");
                const firstCard = document.querySelector(
                    ".estab-handbook-role-grid a"
                );
                const sessionBar = document.querySelector(
                    "body > aside.estab-session-bar"
                );
                const navigation = document.querySelector(
                    ".estab-navigation"
                );
                const navigationContent = document.querySelector(
                    ".estab-navigation-content"
                );
                const layoutMetrics = element => {
                    if (!element) return null;
                    const rect = element.getBoundingClientRect();
                    const style = getComputedStyle(element);
                    return {
                        left: rect.left,
                        right: rect.right,
                        width: rect.width,
                        clientWidth: element.clientWidth,
                        scrollWidth: element.scrollWidth,
                        overflowX: style.overflowX,
                        position: style.position
                    };
                };
                const mainRect = bounds(main);
                const searchRect = bounds(search);
                const tocRect = bounds(toc);
                const cardRect = bounds(firstCard);
                const overflowElements = Array.from(
                    document.body.querySelectorAll("*")
                ).filter(element => {
                    const style = getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    return style.display !== "none" &&
                        style.visibility !== "hidden" &&
                        rect.width > 0 && rect.height > 0 &&
                        (rect.left < -0.5 ||
                            rect.right > innerWidth + 0.5);
                }).slice(0, 20).map(element => {
                    const rect = element.getBoundingClientRect();
                    return {
                        tag: element.tagName.toLowerCase(),
                        id: element.id,
                        classes: String(element.className),
                        left: rect.left,
                        right: rect.right,
                        width: rect.width
                    };
                });
                return {
                    innerWidth,
                    documentClientWidth: document.documentElement.clientWidth,
                    documentScrollWidth: document.documentElement.scrollWidth,
                    sessionBar: layoutMetrics(sessionBar),
                    navigation: layoutMetrics(navigation),
                    navigationContent: layoutMetrics(navigationContent),
                    pageFits: document.documentElement.scrollWidth <=
                        document.documentElement.clientWidth + 1,
                    mainFits: mainRect.left >= -0.5 &&
                        mainRect.right <= innerWidth + 0.5,
                    searchFits: searchRect.left >= -0.5 &&
                        searchRect.right <= innerWidth + 0.5,
                    tocFits: tocRect.left >= -0.5 &&
                        tocRect.right <= innerWidth + 0.5,
                    tocStatic: getComputedStyle(toc).position === "static",
                    tocScrollFree: toc.scrollWidth <= toc.clientWidth + 1 &&
                        toc.scrollHeight <= toc.clientHeight + 1,
                    cardTouchTarget: cardRect.height >= 44,
                    overflowElements,
                    roleColumns: getComputedStyle(
                        document.querySelector(".estab-handbook-role-grid")
                    ).gridTemplateColumns.split(" ").length
                };
            })()
            """
        )
        self._truth(
            isinstance(mobile, dict)
            and mobile.get("innerWidth") == 390
            and mobile.get("pageFits") is True
            and mobile.get("mainFits") is True
            and mobile.get("searchFits") is True
            and mobile.get("tocFits") is True
            and mobile.get("tocStatic") is True
            and mobile.get("tocScrollFree") is True
            and mobile.get("cardTouchTarget") is True
            and mobile.get("roleColumns") == 1,
            f"Mobiles Handbuch ist nicht vollständig bedienbar: {mobile!r}",
        )

    def run_bos(self) -> None:
        self.cdp.call("Page.enable")
        self.cdp.call("Runtime.enable")
        self.cdp.call("Network.enable")
        self.cdp.navigate(self.config.base_url + "/stabinfo/index.php")
        self._wait_for_top_level_path(
            "/stabinfo/index.php",
            "öffentliche BOS-Infosammlung wurde nicht geladen",
        )
        self.cdp.wait_for(
            _frame_expression(
                "status",
                """
                return target.location.pathname.endsWith(
                    "/stabinfo/l_index.php"
                ) && doc.readyState === "complete";
                """,
            ),
            "öffentliche BOS-Sidebar wurde nicht vollständig geladen",
        )
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression(
                    "status",
                    "aside[data-estab-public-bar]"
                )
            ),
            1,
            "öffentliche Shared-Bar in der BOS-Sidebar",
        )
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression(
                    "status",
                    "aside[data-estab-session-bar]"
                )
            ),
            0,
            "authentifizierte Bar in der öffentlichen BOS-Sidebar",
        )
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression(
                    "mainframe",
                    "aside[data-estab-session-bar],aside[data-estab-public-bar]"
                )
            ),
            0,
            "zusätzliche Shared-Bar im BOS-Dokument",
        )
        self._assert_bos_workspace_layout(
            "öffentliche BOS-Infosammlung bei 1440×1000 px"
        )
        for href, title in self.bos_documents:
            self._open_bos_document(
                href,
                title,
                f"öffentliche Desktop-Ansicht „{title}“",
            )
        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 390,
                "height": 844,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 390,
                "screenHeight": 844,
            },
        )
        self._assert_bos_workspace_layout(
            "öffentliche BOS-Infosammlung bei 390×844 px"
        )
        for href, title in self.bos_documents:
            location = f"öffentliche Mobilansicht „{title}“"
            self._open_bos_document(href, title, location)
            self._assert_mobile_bos_navigation(location)

    def run_message_overview(self) -> None:
        """Check the real S2 message overview and its responsive headings."""
        if self.config.login_function != "S2":
            raise TestFailure(
                "--message-overview benötigt ein fest provisioniertes S2-Konto."
            )

        self.cdp.call("Page.enable")
        self.cdp.call("Runtime.enable")
        self.cdp.call("Network.enable")
        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 1280,
                "height": 900,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 1280,
                "screenHeight": 900,
            },
        )

        print("[1/3] Meldungsübersicht direkt aufrufen und als S2 anmelden")
        navigation = self.cdp.call(
            "Page.navigate",
            {"url": self.config.base_url + "/4fueltg/ue_ltg.php"},
        )
        if navigation.get("errorText"):
            raise TestFailure(
                "Chrome konnte den direkten Aufruf der "
                "Meldungsübersicht nicht starten."
            )
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            location.pathname.endsWith("/4fach/index.php") &&
            new URLSearchParams(location.search).get("login_flow") ===
                "existing" &&
            new URLSearchParams(location.search).get("next") ===
                "message-overview"
            """,
            "Direktaufruf der Meldungsübersicht führte nicht zum "
            "Bestandslogin",
        )
        self._wait_for_frame("mainframe")
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const query = new target.URLSearchParams(
                    target.location.search
                );
                return target.location.pathname.endsWith(
                        "/4fach/mainindex.php"
                    ) &&
                    query.get("login_flow") === "existing" &&
                    query.get("next") === "message-overview" &&
                    Boolean(doc.querySelector('input[name="benutzer"]')) &&
                    Boolean(doc.querySelector('input[name="kuerzel"]')) &&
                    Boolean(doc.querySelector('select[name="funktion"]')) &&
                    Boolean(doc.querySelector('input[name="kennwort1"]'));
                """,
            ),
            "Bestandskonto-Formular für die Meldungsübersicht fehlt",
        )
        self.cdp.set_value(
            "mainframe",
            'input[name="benutzer"]',
            self.config.login_name,
            "S2-Benutzername",
        )
        self.cdp.set_value(
            "mainframe",
            'input[name="kuerzel"]',
            self.config.login_code,
            "S2-Kürzel",
        )
        self.cdp.set_value(
            "mainframe",
            'select[name="funktion"]',
            self.config.login_function,
            "S2-Funktion",
            select=True,
        )
        self.cdp.set_value(
            "mainframe",
            'input[name="kennwort1"]',
            self.config.login_password,
            "S2-Kennwort",
        )
        self.cdp.click(
            "mainframe",
            'button.estab-button-primary[type="submit"]',
            "S2-Bestandskonto anmelden",
        )
        self._wait_for_top_level_path(
            "/4fueltg/ue_ltg.php",
            "Meldungsübersicht wurde nach dem S2-Login nicht geöffnet",
        )
        self.cdp.wait_for(
            """
            Boolean(document.querySelector(
                "[data-estab-message-overview]" +
                "[data-estab-message-list]"
            )) &&
            Boolean(document.querySelector(
                "[data-estab-message-list-controls]"
            )) &&
            Boolean(document.querySelector(
                ".estab-message-list-resultbar"
            )) &&
            Boolean(document.querySelector(
                ".estab-message-list-table, " +
                "[data-estab-message-list-empty]"
            ))
            """,
            "Meldungsliste wurde nach dem S2-Login nicht vollständig geladen",
        )

        expected_subject = self.config.message_overview_subject
        if expected_subject is not None:
            print("[2/3] Explizit bekannten Betreff über die echte Suche filtern")
            self.cdp.set_value(
                None,
                '.estab-message-list-search-row input[name="ml_q"]',
                expected_subject,
                "bekannten Vordruck-Betreff suchen",
            )
            self.cdp.click(
                None,
                '.estab-message-list-search-row '
                'button[name="ml_apply"][value="1"]',
                "Suche nach bekanntem Vordruck-Betreff absenden",
            )
            subject_literal = json.dumps(expected_subject)
            self.cdp.wait_for(
                f"""
                document.readyState === "complete" &&
                location.pathname.endsWith("/4fueltg/ue_ltg.php") &&
                new URLSearchParams(location.search).get("ml_q") ===
                    {subject_literal} &&
                Boolean(document.querySelector(
                    "[data-estab-message-overview]" +
                    "[data-estab-message-list]"
                ))
                """,
                "Betreffsuche in der Meldungsübersicht wurde nicht angewendet",
            )
        else:
            print("[2/3] Vorhandene Betreffmarker oder den Leerzustand prüfen")

        self._assert_session_bar(
            None,
            "S2-Meldungsübersicht",
            "message-overview",
        )

        print("[3/3] Betreffdarstellung auf Desktop und bei 390 px vermessen")
        for width, height, mobile in (
            (1280, 900, False),
            (390, 844, True),
        ):
            self.cdp.call(
                "Emulation.setDeviceMetricsOverride",
                {
                    "width": width,
                    "height": height,
                    "deviceScaleFactor": 1,
                    "mobile": False,
                    "screenWidth": width,
                    "screenHeight": height,
                },
            )
            expected_table_display = "block" if mobile else "table"
            display_literal = json.dumps(expected_table_display)
            self.cdp.wait_for(
                f"""
                innerWidth === {width} && (() => {{
                    const table = document.querySelector(
                        ".estab-message-list-table"
                    );
                    return !table || getComputedStyle(table).display ===
                        {display_literal};
                }})()
                """,
                f"Responsive Meldungsliste bei {width}×{height} px "
                "wurde nicht berechnet",
            )
            self._assert_message_overview_layout(
                f"Meldungsübersicht bei {width}×{height} px",
                mobile=mobile,
            )

    def _assert_message_overview_layout(
        self,
        description: str,
        *,
        mobile: bool,
    ) -> None:
        expected_subject = json.dumps(self.config.message_overview_subject)
        state = self.cdp.evaluate(
            """
            (() => {
                const expectedSubject = __EXPECTED_SUBJECT__;
                const normalize = value => value.replace(/\\s+/g, " ").trim();
                const visible = element => {
                    if (!element) return false;
                    const style = getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    return style.display !== "none" &&
                        style.visibility !== "hidden" &&
                        rect.width > 0 && rect.height > 0;
                };
                const overlaps = (first, second) => {
                    const a = first.getBoundingClientRect();
                    const b = second.getBoundingClientRect();
                    return Math.min(a.right, b.right) -
                            Math.max(a.left, b.left) > 0.5 &&
                        Math.min(a.bottom, b.bottom) -
                            Math.max(a.top, b.top) > 0.5;
                };
                const overlapPairs = elements => {
                    const pairs = [];
                    for (let left = 0; left < elements.length; left += 1) {
                        for (
                            let right = left + 1;
                            right < elements.length;
                            right += 1
                        ) {
                            if (overlaps(elements[left], elements[right])) {
                                pairs.push([left, right]);
                            }
                        }
                    }
                    return pairs;
                };
                const fitsViewport = element => {
                    const rect = element.getBoundingClientRect();
                    return rect.left >= -0.5 &&
                        rect.right <= innerWidth + 0.5;
                };
                const containedBy = (element, container) => {
                    const rect = element.getBoundingClientRect();
                    const outer = container.getBoundingClientRect();
                    return rect.left >= outer.left - 0.5 &&
                        rect.right <= outer.right + 0.5 &&
                        rect.top >= outer.top - 0.5 &&
                        rect.bottom <= outer.bottom + 0.5;
                };

                const root = document.querySelector(
                    "[data-estab-message-overview]" +
                    "[data-estab-message-list]"
                );
                const controls = document.querySelector(
                    "[data-estab-message-list-controls]"
                );
                const search = controls?.querySelector(
                    '.estab-message-list-search-row input[name="ml_q"]'
                );
                const searchRow = controls?.querySelector(
                    ".estab-message-list-search-row"
                );
                const resultbar = document.querySelector(
                    ".estab-message-list-resultbar"
                );
                const wrapper = document.querySelector(
                    ".estab-message-list-table-wrap"
                );
                const table = document.querySelector(
                    ".estab-message-list-table"
                );
                const empty = document.querySelector(
                    "[data-estab-message-list-empty]"
                );
                const pager = document.querySelector(
                    ".estab-message-list-pager"
                );
                const rows = table
                    ? Array.from(table.querySelectorAll(
                        "tbody > .estab-message-list-row"
                    ))
                    : [];
                const headings = Array.from(document.querySelectorAll(
                    "[data-estab-message-list-heading]"
                ));
                const searchChildren = searchRow
                    ? Array.from(searchRow.children).filter(visible)
                    : [];
                const resultChildren = resultbar
                    ? Array.from(resultbar.children).filter(visible)
                    : [];
                const horizontalSections = [
                    root,
                    controls,
                    resultbar,
                    wrapper,
                    empty,
                    pager,
                ].filter(visible);
                const headingContentOverlaps = headings.flatMap(
                    (heading, index) => {
                        const summary = heading.closest(
                            ".estab-message-list-summary"
                        );
                        const excerpt = summary?.querySelector(
                            ".estab-message-list-excerpt"
                        );
                        return excerpt && visible(excerpt) &&
                            overlaps(heading, excerpt)
                            ? [index]
                            : [];
                    }
                );
                const exactHeadingCount = expectedSubject === null
                    ? null
                    : headings.filter(
                        heading => normalize(heading.textContent || "") ===
                            expectedSubject
                    ).length;
                const emptyVisible = visible(empty);
                const tablePresent = Boolean(table);
                const tableDisplay = table
                    ? getComputedStyle(table).display
                    : null;
                const wrapperOverflow = wrapper
                    ? getComputedStyle(wrapper).overflowX
                    : null;

                return {
                    rootVisible: visible(root),
                    controlsVisible: visible(controls),
                    searchVisible: visible(search),
                    resultbarVisible: visible(resultbar),
                    resultModeValid:
                        (tablePresent && rows.length > 0 && !emptyVisible) ||
                        (!tablePresent && rows.length === 0 && emptyVisible),
                    rowCount: rows.length,
                    headingCount: headings.length,
                    headingsVisible: headings.every(visible),
                    oneHeadingPerRow: rows.every(
                        row => row.querySelectorAll(
                            "[data-estab-message-list-heading]"
                        ).length === 1
                    ),
                    headingSemanticsExact: headings.every(heading => {
                        const text = normalize(heading.textContent || "");
                        const emptyMarker = heading.getAttribute(
                            "data-estab-message-list-heading-empty"
                        );
                        return emptyMarker === "true"
                            ? text === "Keine Überschrift angegeben"
                            : emptyMarker === "false" &&
                                text.length > 0 &&
                                text !== "Keine Überschrift angegeben";
                    }),
                    headingsContained: headings.every(heading => {
                        const summary = heading.closest(
                            ".estab-message-list-summary"
                        );
                        return Boolean(summary) &&
                            containedBy(heading, summary);
                    }),
                    headingsWrap: headings.every(
                        heading => heading.scrollWidth <=
                            heading.clientWidth + 1
                    ),
                    exactHeadingCount,
                    queryValue: search ? search.value : null,
                    pageFits: document.documentElement.scrollWidth <=
                        innerWidth + 1,
                    sectionsFit: horizontalSections.every(fitsViewport),
                    controlsScrollFree: Boolean(controls) &&
                        controls.scrollWidth <= controls.clientWidth + 1,
                    mobileRowsScrollFree: !__MOBILE__ || rows.every(
                        row => row.scrollWidth <= row.clientWidth + 1
                    ),
                    searchOverlaps: overlapPairs(searchChildren),
                    resultOverlaps: overlapPairs(resultChildren),
                    rowOverlaps: overlapPairs(rows),
                    headingContentOverlaps,
                    responsiveMode: !tablePresent || (__MOBILE__
                        ? tableDisplay === "block" &&
                            wrapperOverflow === "visible" &&
                            rows.every(
                                row => getComputedStyle(row).display ===
                                    "block"
                            )
                        : tableDisplay === "table" &&
                            ["auto", "scroll"].includes(wrapperOverflow)),
                };
            })()
            """
            .replace("__EXPECTED_SUBJECT__", expected_subject)
            .replace("__MOBILE__", "true" if mobile else "false")
        )
        self._truth(
            isinstance(state, dict)
            and state.get("rootVisible") is True
            and state.get("controlsVisible") is True
            and state.get("searchVisible") is True
            and state.get("resultbarVisible") is True
            and state.get("resultModeValid") is True,
            f"{description}: Arbeitsbereich, Suche oder Ergebniszustand fehlt: "
            f"{state!r}",
        )
        self._truth(
            state.get("headingCount") == state.get("rowCount")
            and state.get("headingsVisible") is True
            and state.get("oneHeadingPerRow") is True
            and state.get("headingSemanticsExact") is True,
            f"{description}: Betreffmarker sind nicht sichtbar, eindeutig oder "
            f"semantisch exakt: {state!r}",
        )
        if self.config.message_overview_subject is not None:
            self._truth(
                int(state.get("exactHeadingCount") or 0) >= 1
                and state.get("queryValue")
                == self.config.message_overview_subject,
                f"{description}: Der explizit bekannte Vordruck-Betreff wird "
                f"nicht exakt angezeigt: {state!r}",
            )
        self._truth(
            state.get("pageFits") is True
            and state.get("sectionsFit") is True
            and state.get("controlsScrollFree") is True
            and state.get("mobileRowsScrollFree") is True
            and state.get("headingsContained") is True
            and state.get("headingsWrap") is True,
            f"{description}: Seite, Bedienelemente oder Betreff laufen "
            f"horizontal über: {state!r}",
        )
        self._truth(
            state.get("searchOverlaps") == []
            and state.get("resultOverlaps") == []
            and state.get("rowOverlaps") == []
            and state.get("headingContentOverlaps") == []
            and state.get("responsiveMode") is True,
            f"{description}: Suchfelder, Trefferkarten oder Betreff/Inhalt "
            f"überlappen: {state!r}",
        )

    def run_message_suggestions(self) -> None:
        marker = self.config.message_suggestion_marker
        if self.config.login_function != "A/W" or marker is None:
            raise TestFailure(
                "--message-suggestions benötigt ein A/W-Konto und einen "
                "gültigen Vorschlagsmarker."
            )

        self.cdp.call("Page.enable")
        self.cdp.call("Runtime.enable")
        self.cdp.call("Network.enable")
        self.cdp.navigate(self.config.base_url + "/4fach/index.php?next=messages")
        self._wait_for_frame("mainframe")
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                return Boolean(doc.querySelector(
                    'button[name="login_flow"][value="existing"]'
                ));
                """,
            ),
            "Bestandskonto-Auswahl für den Vorschlagstest fehlt",
        )
        self.cdp.click(
            "mainframe",
            'button[name="login_flow"][value="existing"]',
            "Bestandskonto für den Vorschlagstest anmelden",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                return Boolean(
                    doc.querySelector('input[name="benutzer"]')
                    && doc.querySelector('input[name="kuerzel"]')
                    && doc.querySelector('select[name="funktion"]')
                    && doc.querySelector('input[name="kennwort1"]')
                );
                """,
            ),
            "Bestandskonto-Formular für den Vorschlagstest fehlt",
        )
        self.cdp.set_value(
            "mainframe",
            'input[name="benutzer"]',
            self.config.login_name,
            "A/W-Benutzername",
        )
        self.cdp.set_value(
            "mainframe",
            'input[name="kuerzel"]',
            self.config.login_code,
            "A/W-Kürzel",
        )
        self.cdp.set_value(
            "mainframe",
            'select[name="funktion"]',
            self.config.login_function,
            "A/W-Funktion",
            select=True,
        )
        self.cdp.set_value(
            "mainframe",
            'input[name="kennwort1"]',
            self.config.login_password,
            "A/W-Kennwort",
        )
        self.cdp.click(
            "mainframe",
            'button.estab-button-primary[type="submit"]',
            "A/W-Bestandskonto absenden",
        )
        # This browser fixture runs in the explicitly LOOSE central incident:
        # the fixed A/W account function needs no duty-assignment selector.
        self._wait_for_authenticated_frames()
        self.cdp.click(
            "vorgaben",
            'button[name="fm_eingang_x"]'
            '[data-estab-workflow-key="fm_eingang"]',
            "A/W-Eingangsformular öffnen",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const input = doc.querySelector("#f_05_gegenstelle");
                const list = doc.querySelector(
                    "#estab-message-callsign-suggestions"
                );
                const fallback = doc.querySelector(
                    "#estab-message-callsign-suggestions-native"
                );
                return target.location.pathname.endsWith(
                    "/4fach/mainindex.php"
                ) && Boolean(
                    input
                    && list
                    && fallback
                    && input.getAttribute("role") === "combobox"
                    && list.getAttribute("role") === "listbox"
                );
                """,
            ),
            "A/W-Rufnamen-Combobox wurde nicht gerendert",
        )
        self.cdp.click(
            "mainframe",
            "#f_05_gegenstelle",
            "Rufname der Gegenstelle fokussieren",
        )

        marker_literal = json.dumps(marker)
        focus_state = self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                f"""
                const input = doc.querySelector("#f_05_gegenstelle");
                const list = doc.querySelector(
                    "#estab-message-callsign-suggestions"
                );
                const fallback = doc.querySelector(
                    "#estab-message-callsign-suggestions-native"
                );
                const options = Array.from(
                    list.querySelectorAll('[role="option"]')
                );
                return {{
                    focused: doc.activeElement === input,
                    expanded: input.getAttribute("aria-expanded"),
                    nativeListDetached: !input.hasAttribute("list"),
                    listVisible: !list.hidden
                        && target.getComputedStyle(list).display !== "none",
                    customMarker: options.some(
                        option => option.textContent === {marker_literal}
                    ),
                    fallbackMarker: Array.from(fallback.options).some(
                        option => option.value === {marker_literal}
                    )
                }};
                """,
            ),
            "Vorschlagsliste öffnete sich beim echten Fokus nicht",
        )
        self._truth(
            isinstance(focus_state, dict)
            and focus_state.get("focused") is True
            and focus_state.get("expanded") == "true"
            and focus_state.get("nativeListDetached") is True
            and focus_state.get("listVisible") is True
            and focus_state.get("customMarker") is True
            and focus_state.get("fallbackMarker") is True,
            "Fokus-Listbox oder native Rückfalloption enthält den "
            "einsatzbezogenen Rufnamen nicht.",
        )

        self.cdp.set_value(
            "mainframe",
            "#f_05_gegenstelle",
            "BROWSER-GEGENSTELLE-",
            "Vorschlagsfilter",
        )
        self.cdp.call(
            "Input.dispatchKeyEvent",
            {
                "type": "keyDown",
                "key": "ArrowDown",
                "code": "ArrowDown",
                "windowsVirtualKeyCode": 40,
            },
        )
        self.cdp.call(
            "Input.dispatchKeyEvent",
            {
                "type": "keyUp",
                "key": "ArrowDown",
                "code": "ArrowDown",
                "windowsVirtualKeyCode": 40,
            },
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const input = doc.querySelector("#f_05_gegenstelle");
                return Boolean(input.getAttribute("aria-activedescendant"));
                """,
            ),
            "Pfeiltaste aktivierte keinen Rufnamenvorschlag",
        )
        self.cdp.call(
            "Input.dispatchKeyEvent",
            {
                "type": "keyDown",
                "key": "Enter",
                "code": "Enter",
                "windowsVirtualKeyCode": 13,
            },
        )
        self.cdp.call(
            "Input.dispatchKeyEvent",
            {
                "type": "keyUp",
                "key": "Enter",
                "code": "Enter",
                "windowsVirtualKeyCode": 13,
            },
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                f"""
                const input = doc.querySelector("#f_05_gegenstelle");
                const list = doc.querySelector(
                    "#estab-message-callsign-suggestions"
                );
                return input.value === {marker_literal}
                    && input.getAttribute("aria-expanded") === "false"
                    && list.hidden;
                """,
            ),
            "Tastaturauswahl übernahm den Rufnamenvorschlag nicht",
        )

        free_value = "Freie Gegenstelle 4711"
        self.cdp.set_value(
            "mainframe",
            "#f_05_gegenstelle",
            free_value,
            "freie Rufnameneingabe",
        )
        free_state = self.cdp.evaluate(
            _frame_expression(
                "mainframe",
                """
                const input = doc.querySelector("#f_05_gegenstelle");
                return {
                    value: input.value,
                    expanded: input.getAttribute("aria-expanded")
                };
                """,
            )
        )
        self._truth(
            isinstance(free_state, dict)
            and free_state.get("value") == free_value
            and free_state.get("expanded") == "false",
            "freie Rufnameneingabe wurde durch die Vorschlagsliste verändert.",
        )
        self.cdp.set_value(
            "mainframe",
            "#f_05_gegenstelle",
            "",
            "Rufnamen-Testfeld zurücksetzen",
        )
        self.cdp.click(
            "vorgaben",
            "[data-estab-logout-form] button",
            "A/W nach dem Vorschlagstest abmelden",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                return Boolean(doc.querySelector(
                    'button[name="login_flow"][value="existing"]'
                ));
                """,
            ),
            "anonyme Anmeldung nach dem A/W-Vorschlagstest fehlt",
        )

    def run_telecom_plan(self) -> None:
        """Edit and publish a cloned S6 plan in the real browser."""
        if self.config.login_function != "S6":
            raise TestFailure(
                "--telecom-plan benötigt ein fest provisioniertes S6-Konto."
            )

        self.cdp.call("Page.enable")
        self.cdp.call("Runtime.enable")
        self.cdp.call("Network.enable")
        self.cdp.navigate(self.config.base_url + "/4fach/index.php?next=command-post")
        self._wait_for_frame("mainframe")
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                return Boolean(doc.querySelector(
                    'button[name="login_flow"][value="existing"]'
                ));
                """,
            ),
            "Bestandskonto-Auswahl für den Fernmeldeplantest fehlt",
        )
        self.cdp.click(
            "mainframe",
            'button[name="login_flow"][value="existing"]',
            "S6-Bestandskonto anmelden",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                return Boolean(
                    doc.querySelector('input[name="benutzer"]')
                    && doc.querySelector('input[name="kuerzel"]')
                    && doc.querySelector('select[name="funktion"]')
                    && doc.querySelector('input[name="kennwort1"]')
                );
                """,
            ),
            "Bestandskonto-Formular für den Fernmeldeplantest fehlt",
        )
        for selector, value, label, select in (
            ('input[name="benutzer"]', self.config.login_name, "S6-Name", False),
            ('input[name="kuerzel"]', self.config.login_code, "S6-Kürzel", False),
            ('select[name="funktion"]', self.config.login_function, "S6-Funktion", True),
            ('input[name="kennwort1"]', self.config.login_password, "S6-Kennwort", False),
        ):
            self.cdp.set_value(
                "mainframe", selector, value, label, select=select
            )
        self.cdp.click(
            "mainframe",
            'button.estab-button-primary[type="submit"]',
            "S6-Bestandskonto absenden",
        )
        operations_url = self.config.base_url + "/4fach/fuehrungsstelle.php"
        operations_path = urllib.parse.urlsplit(operations_url).path
        operations_path_literal = json.dumps(operations_path)
        self.cdp.wait_for(
            f"""
            document.readyState === "complete" &&
            window === window.top &&
            location.pathname === {operations_path_literal} &&
            Boolean(document.querySelector("[data-estab-telecom-editor]")) &&
            Boolean(document.querySelector("[data-estab-active-telecom-plan]")) &&
            Boolean(document.querySelector(".estab-telecom-start-form"))
            """,
            "Command-Post wurde nach Anmeldung nicht als Top-Level-Seite geöffnet",
        )
        labels = self.cdp.evaluate(
            """
            Array.from(document.querySelectorAll(
                '[data-estab-telecom-entry-form] select[name="medium"] option'
            )).map(option => option.textContent.trim()).filter(Boolean)
            """
        )
        # The entry editor exists only after cloning, so the active page first
        # proves the explicit clone action and immutable source presentation.
        self._truth(
            labels == [],
            "aktiver Fernmeldeplan zeigte unerwartet einen direkten Wegeeditor.",
        )
        active_plan_state = self.cdp.evaluate(
            """
            (() => {
                const active = document.querySelector(
                    '[data-estab-active-telecom-plan]'
                );
                const validity = active?.querySelector(
                    '[data-estab-telecom-header-validity]'
                );
                const normalize = value => String(value || "")
                    .replace(/\\s+/g, " ").trim();
                const routes = Array.from(active?.querySelectorAll(
                    '.estab-tool-table tbody tr'
                ) || []).map(row => ({
                    station: normalize(row.querySelector(
                        '[data-label="Betriebsstelle"]'
                    )?.textContent),
                    callsign: normalize(row.querySelector(
                        '[data-label="Rufname"]'
                    )?.textContent),
                    technical: normalize(row.querySelector(
                        '[data-label="Medium und technische Angaben"]'
                    )?.textContent),
                    traffic: normalize(row.querySelector(
                        '[data-label="Verkehrsform"]'
                    )?.textContent),
                    notes: normalize(row.querySelector(
                        '[data-label="Vermerke"]'
                    )?.textContent)
                }));
                return active ? {
                    origin: normalize(active.querySelector(
                        '[data-estab-telecom-header-origin]'
                    )?.textContent),
                    validFrom: validity?.dataset.estabValidFrom || "",
                    validUntil: validity?.dataset.estabValidUntil || "",
                    lead: normalize(active.querySelector(
                        '[data-estab-telecom-header-lead]'
                    )?.textContent),
                    remarks: normalize(active.querySelector(
                        '[data-estab-telecom-header-remarks]'
                    )?.textContent),
                    routes
                } : null;
            })()
            """
        )
        self._truth(
            isinstance(active_plan_state, dict)
            and bool(active_plan_state.get("origin"))
            and bool(active_plan_state.get("validFrom"))
            and bool(active_plan_state.get("lead"))
            and bool(active_plan_state.get("remarks"))
            and isinstance(active_plan_state.get("routes"), list)
            and len(active_plan_state["routes"]) >= 1,
            "aktive Fernmeldeplanfassung war vor dem Klonen nicht vollständig lesbar.",
        )
        self._assert_tool_page_layout(
            "aktiver S6-Fernmeldeplan bei 1280×800 px",
            "[data-estab-dv-operations]",
            mobile=False,
            require_responsive_table=True,
        )
        self.cdp.click(
            None,
            ".estab-telecom-start-form button.estab-button-primary",
            "Bearbeitung des aktiven Fernmeldeplans starten",
        )
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            Boolean(document.querySelector("[data-estab-telecom-draft]")) &&
            Boolean(document.querySelector(
                '[data-estab-telecom-entry-mode="edit"]'
            )) &&
            !document.querySelector(".estab-telecom-start-form")
            """,
            "vollständig kopierter Fernmeldeplanentwurf fehlt",
        )
        cloned_plan_state = self.cdp.evaluate(
            """
            (() => {
                const header = document.querySelector(
                    'form:has(input[name="operation_action"]'
                    + '[value="update_plan"])'
                );
                const normalize = value => String(value || "")
                    .replace(/\\s+/g, " ").trim();
                const routes = Array.from(document.querySelectorAll(
                    '[data-estab-telecom-entry-mode="edit"]'
                )).map(form => {
                    const medium = form.elements.medium;
                    const technical = [
                        medium?.selectedOptions[0]?.textContent,
                        form.elements.kanal?.value,
                        form.elements.bandlage?.value
                    ].map(normalize).filter(Boolean).join(" · ");
                    return {
                        station: normalize(form.elements.betriebsstelle?.value),
                        callsign: normalize(form.elements.rufname?.value),
                        technical,
                        traffic: normalize(form.elements.verkehrsform?.value),
                        notes: [
                            form.elements.besondere_vermerke?.value,
                            form.elements.bemerkungen?.value
                        ].map(normalize).filter(Boolean).join(" ")
                    };
                });
                return header ? {
                    origin: normalize(header.elements.herkunft?.value),
                    validFrom: header.elements.gueltig_ab?.value || "",
                    validUntil: header.elements.gueltig_bis?.value || "",
                    lead: normalize(header.elements.betriebsleitung?.value),
                    remarks: normalize(header.elements.bemerkungen?.value),
                    routes
                } : null;
            })()
            """
        )
        self._equal(
            cloned_plan_state,
            active_plan_state,
            "vollständige Vorbelegung aller sichtbaren Kopfwerte und Fernmeldewege",
        )
        pristine_guard_state = self.cdp.evaluate(
            """
            (() => {
                const addForm = document.querySelector(
                    '[data-estab-telecom-entry-mode="add"]'
                );
                const placeholder = addForm?.querySelector(
                    'select[name="medium"] option[value=""]'
                );
                const navigation = document.createElement("nav");
                navigation.setAttribute("data-estab-navigation", "");
                const link = document.createElement("a");
                link.href = location.href;
                link.textContent = "Dirty-Guard-Prüfung";
                link.addEventListener("click", event => event.preventDefault());
                navigation.append(link);
                document.body.append(navigation);
                const originalConfirm = window.confirm;
                let confirms = 0;
                try {
                    window.confirm = () => { confirms += 1; return false; };
                    link.dispatchEvent(new MouseEvent("click", {
                        bubbles: true, cancelable: true
                    }));
                } finally {
                    window.confirm = originalConfirm;
                    navigation.remove();
                }
                return {
                    selected: placeholder?.selected === true,
                    defaultSelected: placeholder?.defaultSelected === true,
                    confirms
                };
            })()
            """
        )
        self._equal(
            pristine_guard_state,
            {"selected": True, "defaultSelected": True, "confirms": 0},
            "frischer Entwurf ohne falsche Verlustwarnung durch Medien-Platzhalter",
        )
        inherited_route = self.cdp.evaluate(
            """
            (() => {
                const details = document.querySelector(
                    '.estab-telecom-route[data-estab-telecom-entry-id]'
                );
                const form = details?.querySelector(
                    '[data-estab-telecom-entry-mode="edit"]'
                );
                const summary = details?.querySelector(':scope > summary');
                const marker = summary
                    ? getComputedStyle(summary, '::before')
                    : null;
                return details && form && summary ? {
                    id: details.id,
                    entryId: details.dataset.estabTelecomEntryId,
                    station: form.elements.betriebsstelle.value,
                    callsign: form.elements.rufname.value,
                    medium: form.elements.medium.value,
                    disclosureWidth: marker?.borderLeftWidth,
                    disclosureStyle: marker?.borderLeftStyle
                } : null;
            })()
            """
        )
        self._truth(
            isinstance(inherited_route, dict)
            and bool(inherited_route.get("id"))
            and bool(inherited_route.get("entryId"))
            and bool(inherited_route.get("station"))
            and bool(inherited_route.get("callsign"))
            and bool(inherited_route.get("medium"))
            and inherited_route.get("disclosureWidth") not in (None, "0px")
            and inherited_route.get("disclosureStyle") == "solid",
            "übernommener Fernmeldeweg oder sichtbare Aufklappmarkierung fehlt.",
        )
        inherited_route_id = str(inherited_route["id"])
        inherited_entry_id = str(inherited_route["entryId"])
        inherited_selector = "#" + inherited_route_id
        self.cdp.click(
            None,
            inherited_selector + " > summary",
            "übernommenen Fernmeldeweg sichtbar aufklappen",
        )
        inherited_selector_literal = json.dumps(inherited_selector)
        self.cdp.wait_for(
            f"""
            (() => {{
                const details = document.querySelector(
                    {inherited_selector_literal}
                );
                const input = details?.querySelector(
                    'input[name="rufname"]'
                );
                const rect = input?.getBoundingClientRect();
                return Boolean(details?.open && rect && rect.width > 0 &&
                    rect.height > 0 && rect.bottom > 0 && rect.top < innerHeight);
            }})()
            """,
            "aufgeklappter Fernmeldeweg wurde nicht sichtbar bedienbar",
        )

        edited_callsign = "Browser-geprüfter Rufname"
        self.cdp.set_value(
            None,
            inherited_selector + ' input[name="rufname"]',
            edited_callsign,
            "Rufname des übernommenen Fernmeldewegs",
        )
        self.cdp.click(
            None,
            inherited_selector
            + ' form[data-estab-telecom-entry-mode="edit"] '
            + 'button[type="submit"]',
            "Änderungen am übernommenen Fernmeldeweg speichern",
        )
        inherited_entry_literal = json.dumps(inherited_entry_id)
        inherited_hash_literal = json.dumps("#" + inherited_route_id)
        edited_callsign_literal = json.dumps(edited_callsign)
        self.cdp.wait_for(
            f"""
            (() => {{
                const details = document.querySelector(
                    {inherited_selector_literal}
                );
                const rect = details?.getBoundingClientRect();
                const params = new URLSearchParams(location.search);
                return document.readyState === "complete" &&
                    params.get("result") === "plan_entry_updated" &&
                    params.get("entry") === {inherited_entry_literal} &&
                    location.hash === {inherited_hash_literal} &&
                    details?.open === true && rect && rect.bottom > 0 &&
                    rect.top < innerHeight &&
                    details.querySelector('input[name="rufname"]')?.value ===
                        {edited_callsign_literal} &&
                    details.querySelector(':scope > summary')?.textContent
                        .includes({edited_callsign_literal}) &&
                    Boolean(document.querySelector(
                        '.estab-tool-feedback-success[role="status"]'
                    ));
            }})()
            """,
            "gespeicherter Fernmeldeweg kehrte nicht geöffnet an seine Position zurück",
        )

        labels = self.cdp.evaluate(
            """
            Array.from(document.querySelectorAll(
                '[data-estab-telecom-entry-mode="add"] '
                + 'select[name="medium"] option'
            )).map(option => option.textContent.trim()).filter(Boolean)
            """
        )
        self._equal(
            labels,
            [
                "Medium auswählen",
                "Fernsprecher",
                "Funk",
                "Melder",
                "Telefax",
                "Fernschreiber",
                "Datenübertragung",
            ],
            "ausgeschriebene Medien im Fernmeldeplanentwurf",
        )
        add_details_selector = (
            'details.estab-telecom-section:has('
            + 'form[data-estab-telecom-entry-mode="add"])'
        )
        self.cdp.click(
            None,
            add_details_selector + " > summary",
            "Formular für einen weiteren Fernmeldeweg sichtbar öffnen",
        )
        add_select = (
            '[data-estab-telecom-entry-mode="add"] select[name="medium"]'
        )
        self.cdp.wait_for(
            f"""
            (() => {{
                const details = document.querySelector(
                    {json.dumps(add_details_selector)}
                );
                const select = details?.querySelector('select[name="medium"]');
                const rect = select?.getBoundingClientRect();
                return Boolean(details?.open && rect && rect.width > 0 &&
                    rect.height > 0 && rect.bottom > 0 && rect.top < innerHeight);
            }})()
            """,
            "Formular für den neuen Fernmeldeweg ist nicht sichtbar",
        )
        self.cdp.set_value(None, add_select, "Fu", "Medium Funk", select=True)
        radio_fields = self.cdp.evaluate(
            """
            (() => {
                const form = document.querySelector(
                    '[data-estab-telecom-entry-mode="add"]'
                );
                const state = name => {
                    const wrapper = form.querySelector(
                        '[data-estab-telecom-field="' + name + '"]'
                    );
                    const input = wrapper.querySelector('input');
                    return {hidden: wrapper.hidden, disabled: input.disabled,
                        required: input.required};
                };
                return {channel: state('kanal'), band: state('bandlage'),
                    traffic: state('verkehrsform')};
            })()
            """
        )
        self._truth(
            isinstance(radio_fields, dict)
            and radio_fields.get("channel") == {
                "hidden": False, "disabled": False, "required": True
            }
            and radio_fields.get("band") == {
                "hidden": False, "disabled": False, "required": True
            }
            and radio_fields.get("traffic") == {
                "hidden": False, "disabled": False, "required": True
            },
            "Funk blendet Kanal, Bandlage oder Verkehrsform nicht korrekt ein.",
        )
        self.cdp.set_value(None, add_select, "Me", "Medium Melder", select=True)
        messenger_fields = self.cdp.evaluate(
            """
            (() => {
                const form = document.querySelector(
                    '[data-estab-telecom-entry-mode="add"]'
                );
                const state = name => {
                    const wrapper = form.querySelector(
                        '[data-estab-telecom-field="' + name + '"]'
                    );
                    const input = wrapper.querySelector('input');
                    return {hidden: wrapper.hidden, disabled: input.disabled,
                        required: input.required};
                };
                return {channel: state('kanal'), band: state('bandlage'),
                    traffic: state('verkehrsform')};
            })()
            """
        )
        self._truth(
            isinstance(messenger_fields, dict)
            and messenger_fields.get("channel") == {
                "hidden": True, "disabled": True, "required": False
            }
            and messenger_fields.get("band") == {
                "hidden": True, "disabled": True, "required": False
            }
            and messenger_fields.get("traffic") == {
                "hidden": False, "disabled": False, "required": True
            },
            "Melder blendet unpassende Funkfelder nicht korrekt aus.",
        )

        messenger_station = "Browser-Melderziel"
        messenger_callsign = "Browser-Melderrufname"
        messenger_traffic = "Persönliche Beförderung"
        add_form_selector = '[data-estab-telecom-entry-mode="add"]'
        for selector, value, description in (
            (
                add_form_selector + ' input[name="betriebsstelle"]',
                messenger_station,
                "Betriebsstelle des neuen Melderwegs",
            ),
            (
                add_form_selector + ' input[name="rufname"]',
                messenger_callsign,
                "Rufname des neuen Melderwegs",
            ),
            (
                add_form_selector + ' input[name="verkehrsform"]',
                messenger_traffic,
                "Verkehrsform des neuen Melderwegs",
            ),
        ):
            self.cdp.set_value(None, selector, value, description)
        self.cdp.click(
            None,
            add_form_selector + ' button[type="submit"]',
            "sichtbar ausgefüllten Melderweg hinzufügen",
        )
        messenger_station_literal = json.dumps(messenger_station)
        messenger_callsign_literal = json.dumps(messenger_callsign)
        added_route_id = self.cdp.wait_for(
            f"""
            (() => {{
                if (document.readyState !== "complete") return "";
                const params = new URLSearchParams(location.search);
                if (params.get("result") !== "plan_entry_added") return "";
                const details = Array.from(document.querySelectorAll(
                    '.estab-telecom-route[data-estab-telecom-entry-id]'
                )).find(candidate => {{
                    const form = candidate.querySelector(
                        '[data-estab-telecom-entry-mode="edit"]'
                    );
                    return form?.elements.betriebsstelle.value ===
                        {messenger_station_literal} &&
                        form?.elements.rufname.value ===
                            {messenger_callsign_literal} &&
                        form?.elements.medium.value === "Me";
                }});
                return details?.id || "";
            }})()
            """,
            "neu angelegter Melderweg wurde nicht dargestellt",
        )
        self._truth(
            isinstance(added_route_id, str) and added_route_id.startswith(
                "fernmeldeweg-"
            ),
            "neu angelegter Melderweg besitzt keine stabile UI-Identität.",
        )
        added_selector = "#" + added_route_id
        self.cdp.click(
            None,
            added_selector + " > summary",
            "neu angelegten Melderweg sichtbar öffnen",
        )
        self.cdp.wait_for(
            f"""
            (() => {{
                const details = document.querySelector(
                    {json.dumps(added_selector)}
                );
                const summary = details?.querySelector(':scope > summary');
                return Boolean(details?.open && summary &&
                    summary.textContent.includes({messenger_station_literal}) &&
                    summary.textContent.includes({messenger_callsign_literal}) &&
                    summary.textContent.includes("Melder"));
            }})()
            """,
            "neu angelegter Melderweg ist nicht sichtbar und vollständig",
        )
        delete_dialog = self.cdp.click(
            None,
            added_selector
            + ' button[data-estab-confirm="delete-telecom-entry"]',
            "neu angelegten Melderweg löschen",
            dialog_accept=True,
        )
        self._truth(
            isinstance(delete_dialog, dict)
            and delete_dialog.get("type") == "confirm"
            and "wirklich" in str(delete_dialog.get("message", "")),
            "Löschen eines Fernmeldewegs wurde nicht verständlich bestätigt.",
        )
        self.cdp.wait_for(
            f"""
            document.readyState === "complete" &&
            new URLSearchParams(location.search).get("result") ===
                "plan_entry_deleted" &&
            !document.querySelector({json.dumps(added_selector)}) &&
            !Array.from(document.querySelectorAll(
                '[data-estab-telecom-entry-mode="edit"] '
                + 'input[name="betriebsstelle"]'
            )).some(input => input.value === {messenger_station_literal}) &&
            Boolean(document.querySelector(
                '.estab-tool-feedback-success[role="status"]'
            ))
            """,
            "bestätigt gelöschter Melderweg blieb im Entwurf sichtbar",
        )

        discarded_header_value = "Browser-verwerfbare Kopfänderung"
        self.cdp.set_value(
            None,
            'form:has(input[name="operation_action"][value="update_plan"]) '
            + 'input[name="herkunft"]',
            discarded_header_value,
            "vorübergehend geänderte Herkunft der Folgeversion",
        )
        location_before_block = self.cdp.evaluate("location.href")
        self.cdp.click(
            None,
            ".estab-telecom-publish button.estab-button-primary",
            "Aktivierung mit ungespeicherten Kopfdaten versuchen",
        )
        self.cdp.wait_for(
            f"""
            (() => {{
                const warning = document.querySelector(
                    '[data-estab-telecom-publish-warning]'
                );
                const style = warning && getComputedStyle(warning);
                return location.href === {json.dumps(location_before_block)} &&
                    Boolean(document.querySelector(
                        '[data-estab-telecom-draft]'
                    )) && warning && !warning.hidden &&
                    style.display !== "none" && style.visibility !== "hidden" &&
                    warning.textContent.includes("ungespeicherte Änderungen") &&
                    warning.dataset.estabTelecomDirtyAction === "update_plan" &&
                    document.activeElement === warning;
            }})()
            """,
            "ungespeicherte Änderungen blockierten die Aktivierung nicht inline",
        )
        self.cdp.click(
            None,
            inherited_selector + " > summary",
            "übernommenen Fernmeldeweg für teilformularübergreifenden Schutz öffnen",
        )
        resolved_callsign = "Browser-geprüfter Mehrfachentwurf"
        self.cdp.set_value(
            None,
            inherited_selector + ' input[name="rufname"]',
            resolved_callsign,
            "gleichzeitig geänderter Rufname des Fernmeldewegs",
        )
        location_before_cross_form_block = self.cdp.evaluate("location.href")
        self.cdp.click(
            None,
            inherited_selector
            + ' form[data-estab-telecom-entry-mode="edit"] '
            + 'button[type="submit"]',
            "Weg speichern während andere Kopfdaten ungespeichert sind",
        )
        self.cdp.wait_for(
            f"""
            (() => {{
                const warning = document.querySelector(
                    '[data-estab-telecom-draft-warning]'
                );
                const headerForm = document.querySelector(
                    'form:has(input[name="operation_action"]'
                    + '[value="update_plan"])'
                );
                return location.href ===
                        {json.dumps(location_before_cross_form_block)} &&
                    warning && !warning.hidden &&
                    warning.dataset.estabTelecomDirtyAction === "update_plan" &&
                    warning.textContent.includes("Kopfdaten") &&
                    warning.querySelector(
                        '[data-estab-telecom-pending-action]'
                    )?.textContent.includes("Änderungen am Weg speichern") &&
                    headerForm?.classList.contains("estab-telecom-unsaved") &&
                    document.activeElement === warning;
            }})()
            """,
            "andere ungespeicherte Teilformulare blockierten die Wegeaktion nicht",
        )
        self.cdp.click(
            None,
            "[data-estab-telecom-continue-action]",
            "andere Kopfdaten bewusst verwerfen und Wegeaktion fortsetzen",
        )
        resolved_callsign_literal = json.dumps(resolved_callsign)
        authoritative_origin_literal = json.dumps(active_plan_state["origin"])
        self.cdp.wait_for(
            f"""
            (() => {{
                const params = new URLSearchParams(location.search);
                const route = document.querySelector(
                    {inherited_selector_literal}
                );
                const routeCallsign = route?.querySelector(
                    'input[name="rufname"]'
                );
                const headerOrigin = document.querySelector(
                    'form:has(input[name="operation_action"]'
                    + '[value="update_plan"]) input[name="herkunft"]'
                );
                return document.readyState === "complete" &&
                    params.get("result") === "plan_entry_updated" &&
                    params.get("entry") === {inherited_entry_literal} &&
                    routeCallsign?.value === {resolved_callsign_literal} &&
                    routeCallsign?.defaultValue ===
                        {resolved_callsign_literal} &&
                    headerOrigin?.value === {authoritative_origin_literal} &&
                    headerOrigin?.defaultValue ===
                        {authoritative_origin_literal} &&
                    headerOrigin?.value !== {json.dumps(discarded_header_value)} &&
                    Boolean(document.querySelector(
                        '.estab-tool-feedback-success[role="status"]'
                    ));
            }})()
            """,
            "explizit fortgesetzte Wegeaktion verwarf nur die anderen Browserwerte",
        )

        header_value = "Browser-geprüfte Fernmeldeplanfolge"
        self.cdp.set_value(
            None,
            'form:has(input[name="operation_action"][value="update_plan"]) '
            + 'input[name="herkunft"]',
            header_value,
            "endgültige Herkunft der Folgeversion",
        )
        self.cdp.click(
            None,
            'form:has(input[name="operation_action"][value="update_plan"]) '
            + 'button[type="submit"]',
            "Kopfdaten des Entwurfs speichern",
        )
        header_literal = json.dumps(header_value)
        self.cdp.wait_for(
            f"""
            document.readyState === "complete" &&
            new URLSearchParams(location.search).get("result") ===
                "plan_updated" &&
            Boolean(document.querySelector(
                '.estab-tool-feedback-success[role="status"]'
            )) &&
            document.querySelector(
                'form:has(input[value="update_plan"]) input[name="herkunft"]'
            )?.value === {header_literal} &&
            document.querySelector(
                'form:has(input[value="update_plan"]) input[name="herkunft"]'
            )?.defaultValue === {header_literal}
            """,
            "bearbeitete Kopfdaten wurden nicht im Entwurf gespeichert",
        )

        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 390,
                "height": 844,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 390,
                "screenHeight": 844,
            },
        )
        self._assert_tool_page_layout(
            "S6-Fernmeldeplanentwurf bei 390×844 px",
            "[data-estab-dv-operations]",
            mobile=True,
            require_responsive_table=True,
        )
        self.cdp.click(
            None,
            ".estab-telecom-publish button.estab-button-primary",
            "bearbeiteten Fernmeldeplan aktiv schalten",
        )
        activation_outcome = self.cdp.wait_for(
            """
            (() => {
                const result = new URLSearchParams(location.search).get(
                    "result"
                );
                if (result === "plan_activated") return "activated";
                const warning = document.querySelector(
                    '[data-estab-telecom-publish-warning]'
                );
                if (warning && !warning.hidden) {
                    return "blocked:" + (
                        warning.dataset.estabTelecomDirtyAction || "unknown"
                    );
                }
                return "";
            })()
            """,
            "Aktivierung löste weder Navigation noch eine Inlinewarnung aus",
        )
        self._equal(
            activation_outcome,
            "activated",
            "Ergebnis der Aktivierung des gespeicherten Entwurfs",
        )
        self.cdp.wait_for(
            f"""
            document.readyState === "complete" &&
            !document.querySelector("[data-estab-telecom-draft]") &&
            Boolean(document.querySelector(".estab-telecom-start-form")) &&
            document.querySelector("[data-estab-active-telecom-plan]")
                ?.textContent.includes({header_literal}) &&
            Boolean(document.querySelector(
                '[data-estab-telecom-history-state="replaced"]'
            ))
            """,
            "bearbeitete Folgeversion wurde nicht zum aktiven Plan",
        )
        history_state = self.cdp.evaluate(
            """
            (() => {
                const history = document.querySelector(
                    '[data-estab-telecom-history]'
                );
                const active = document.querySelector(
                    '[data-estab-active-telecom-plan]'
                );
                return history ? {
                    versions: history.querySelectorAll(
                        '[data-estab-telecom-history-version]'
                    ).length,
                    replaced: history.querySelectorAll(
                        '[data-estab-telecom-history-state="replaced"]'
                    ).length,
                    editableControls: history.querySelectorAll(
                        'form, input, select, textarea, button'
                    ).length,
                    hasRoute: history.textContent.includes("CI Betriebsstelle"),
                    hasHeading: history.textContent.includes(
                        "Versionshistorie Fernmeldeplan"
                    ),
                    hasSpecialNote: history.textContent.includes(
                        "Aktiver besonderer Vermerk"
                    ),
                    hasRouteNote: history.textContent.includes(
                        "Aktiver Workflow-Fixpunkt"
                    ),
                    hasSpecialLabel: history.textContent.includes(
                        "Besondere Vermerke:"
                    ),
                    hasRouteNoteLabel: history.textContent.includes(
                        "Bemerkungen zum Weg:"
                    ),
                    activeHeaderNote: active?.querySelector(
                        '[data-estab-telecom-header-remarks]'
                    )?.textContent.trim() || ""
                } : null;
            })()
            """
        )
        self._truth(
            isinstance(history_state, dict)
            and int(history_state.get("versions", 0)) >= 2
            and int(history_state.get("replaced", 0)) >= 2
            and history_state.get("editableControls") == 0
            and history_state.get("hasRoute") is True
            and history_state.get("hasHeading") is True
            and history_state.get("hasSpecialNote") is True
            and history_state.get("hasRouteNote") is True
            and history_state.get("hasSpecialLabel") is True
            and history_state.get("hasRouteNoteLabel") is True
            and history_state.get("activeHeaderNote")
            == "Aktiver Workflow-Fixpunkt",
            "Kopf- oder Wegehinweise fehlen in der kompakten Read-only-Historie.",
        )
        self.cdp.click(
            None,
            "[data-estab-logout-form] button",
            "S6 nach dem Fernmeldeplantest abmelden",
        )

    def run_inactive_messenger(self) -> None:
        """Prove inactive dispatch in the rendered UI and after real POST/PRG."""
        if self.config.login_function != "LdF":
            raise TestFailure(
                "--inactive-messenger benötigt ein fest provisioniertes "
                "LdF-Konto."
            )
        inactive_code = os.environ.get(
            "ESTAB_TEST_INACTIVE_MESSENGER_CODE", ""
        ).strip().lower()
        online_code = os.environ.get(
            "ESTAB_TEST_ONLINE_MESSENGER_CODE", ""
        ).strip().lower()
        message_marker = os.environ.get(
            "ESTAB_TEST_INACTIVE_MESSENGER_MESSAGE_MARKER", ""
        ).strip()
        destination = os.environ.get(
            "ESTAB_TEST_INACTIVE_MESSENGER_DESTINATION", ""
        ).strip()
        if not re.fullmatch(r"[a-z0-9_]{1,6}", inactive_code):
            raise TestFailure(
                "ESTAB_TEST_INACTIVE_MESSENGER_CODE fehlt oder ist ungültig."
            )
        if not re.fullmatch(r"[a-z0-9_]{1,6}", online_code):
            raise TestFailure(
                "ESTAB_TEST_ONLINE_MESSENGER_CODE fehlt oder ist ungültig."
            )
        if inactive_code == online_code:
            raise TestFailure("Aktiver und inaktiver Fernmelder sind identisch.")
        if not re.fullmatch(r"BROWSER-MELDER-[a-f0-9]{16}", message_marker):
            raise TestFailure(
                "ESTAB_TEST_INACTIVE_MESSENGER_MESSAGE_MARKER ist ungültig."
            )
        if not re.fullmatch(
            r"BROWSER-MELDERZIEL-[a-f0-9]{16}", destination
        ):
            raise TestFailure(
                "ESTAB_TEST_INACTIVE_MESSENGER_DESTINATION ist ungültig."
            )

        self.cdp.call("Page.enable")
        self.cdp.call("Runtime.enable")
        self.cdp.call("Network.enable")
        self.cdp.navigate(
            self.config.base_url + "/4fach/index.php?next=command-post"
        )
        self._wait_for_frame("mainframe")
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                return Boolean(doc.querySelector(
                    'button[name="login_flow"][value="existing"]'
                ));
                """,
            ),
            "Bestandskonto-Auswahl für den Melderauftragstest fehlt",
        )
        self.cdp.click(
            "mainframe",
            'button[name="login_flow"][value="existing"]',
            "LdF-Bestandskonto anmelden",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                return Boolean(
                    doc.querySelector('input[name="benutzer"]')
                    && doc.querySelector('input[name="kuerzel"]')
                    && doc.querySelector('select[name="funktion"]')
                    && doc.querySelector('input[name="kennwort1"]')
                );
                """,
            ),
            "Bestandskonto-Formular für den Melderauftragstest fehlt",
        )
        for selector, value, label, select in (
            (
                'input[name="benutzer"]',
                self.config.login_name,
                "LdF-Name",
                False,
            ),
            (
                'input[name="kuerzel"]',
                self.config.login_code,
                "LdF-Kürzel",
                False,
            ),
            (
                'select[name="funktion"]',
                self.config.login_function,
                "LdF-Funktion",
                True,
            ),
            (
                'input[name="kennwort1"]',
                self.config.login_password,
                "LdF-Kennwort",
                False,
            ),
        ):
            self.cdp.set_value(
                "mainframe", selector, value, label, select=select
            )
        self.cdp.click(
            "mainframe",
            'button.estab-button-primary[type="submit"]',
            "LdF-Bestandskonto absenden",
        )

        operations_path = "/4fach/fuehrungsstelle.php"
        inactive_literal = json.dumps(inactive_code)
        online_literal = json.dumps(online_code)
        marker_literal = json.dumps(message_marker)
        destination_literal = json.dumps(destination)
        self.cdp.wait_for(
            f"""
            document.readyState === "complete" && window === window.top &&
            location.pathname === {json.dumps(operations_path)} &&
            Boolean(document.querySelector(
                '[data-estab-messenger-assignment]'
            )) && Array.from(document.querySelectorAll(
                '[data-estab-messenger-select] option'
            )).some(option => option.value === {inactive_literal}) &&
            Array.from(document.querySelectorAll(
                '[data-estab-messenger-select] option'
            )).some(option => option.value === {online_literal}) &&
            Array.from(document.querySelectorAll(
                'select[name="nachricht_id"] option'
            )).some(option => option.textContent.includes({marker_literal}))
            """,
            "Browser-Fixture für den Melderauftrag wurde nicht vollständig gerendert",
        )

        initial_state = self.cdp.evaluate(
            """
            (() => {
                const select = document.querySelector(
                    '[data-estab-messenger-select]'
                );
                const option = select?.options[select.selectedIndex] || null;
                const warning = document.querySelector(
                    '[data-estab-messenger-presence-warning]'
                );
                return select && option && warning ? {
                    value: select.value,
                    text: option.textContent.replace(/\\s+/g, " ").trim(),
                    warningHidden: warning.hidden
                } : null;
            })()
            """
        )
        self._truth(
            isinstance(initial_state, dict)
            and initial_state.get("value") == ""
            and initial_state.get("text") == "Bitte Fernmelder auswählen"
            and initial_state.get("warningHidden") is True,
            "Die leere Fernmelder-Auswahl ist nicht sicher vorausgewählt.",
        )

        option_states = self.cdp.evaluate(
            f"""
            (() => {{
                const options = Array.from(document.querySelectorAll(
                    '[data-estab-messenger-select] option'
                ));
                const state = (code) => {{
                    const option = options.find(candidate =>
                        candidate.value === code
                    );
                    return option ? {{
                        text: option.textContent.replace(/\\s+/g, " ").trim(),
                        presence: option.dataset.estabPresenceState || "",
                        label: option.dataset.estabPresenceLabel || "",
                        notification:
                            option.dataset.estabNotificationRequired || ""
                    }} : null;
                }};
                return {{
                    inactive: state({inactive_literal}),
                    online: state({online_literal})
                }};
            }})()
            """
        )
        inactive_state = (
            option_states.get("inactive")
            if isinstance(option_states, dict)
            else None
        )
        online_state = (
            option_states.get("online")
            if isinstance(option_states, dict)
            else None
        )
        self._truth(
            isinstance(inactive_state, dict)
            and inactive_state.get("presence") == "signed_out"
            and inactive_state.get("label") == "abgemeldet"
            and inactive_state.get("notification") == "1"
            and "abgemeldet" in str(inactive_state.get("text", "")),
            "inaktiver Fernmelder ist nicht verständlich gekennzeichnet.",
        )
        self._truth(
            isinstance(online_state, dict)
            and online_state.get("presence") == "online"
            and online_state.get("label") == "aktiv"
            and online_state.get("notification") == "0"
            and "aktiv" in str(online_state.get("text", "")),
            "aktiver Fernmelder ist nicht verständlich gekennzeichnet.",
        )

        self.cdp.set_value(
            None,
            "[data-estab-messenger-select]",
            online_code,
            "aktiven Fernmelder auswählen",
            select=True,
        )
        self.cdp.wait_for(
            """
            (() => {
                const warning = document.querySelector(
                    '[data-estab-messenger-presence-warning]'
                );
                return Boolean(warning && warning.hidden);
            })()
            """,
            "bei aktivem Fernmelder blieb der Warnhinweis sichtbar",
        )

        self.cdp.set_value(
            None,
            "[data-estab-messenger-select]",
            inactive_code,
            "inaktiven Fernmelder auswählen",
            select=True,
        )
        self.cdp.wait_for(
            """
            (() => {
                const warning = document.querySelector(
                    '[data-estab-messenger-presence-warning]'
                );
                const rect = warning?.getBoundingClientRect();
                const style = warning && getComputedStyle(warning);
                return Boolean(warning && !warning.hidden && rect &&
                    rect.width > 0 && rect.height > 0 &&
                    style.display !== "none" &&
                    style.visibility !== "hidden" &&
                    warning.textContent.includes("abgemeldet") &&
                    warning.textContent.includes("separat über den Auftrag"));
            })()
            """,
            "Hinweis zur separaten Information wurde nicht sichtbar",
        )

        message_option = self.cdp.evaluate(
            f"""
            (() => {{
                const option = Array.from(document.querySelectorAll(
                    'select[name="nachricht_id"] option'
                )).find(candidate =>
                    candidate.textContent.includes({marker_literal})
                );
                return option ? {{
                    value: option.value,
                    text: option.textContent.replace(/\\s+/g, " ").trim()
                }} : null;
            }})()
            """
        )
        self._truth(
            isinstance(message_option, dict)
            and str(message_option.get("value", "")).isdigit()
            and message_marker in str(message_option.get("text", "")),
            "Die eindeutig markierte Ausgangsnachricht ist nicht auswählbar.",
        )
        self.cdp.set_value(
            None,
            'select[name="nachricht_id"]',
            str(message_option["value"]),
            "markierte Ausgangsnachricht auswählen",
            select=True,
        )
        self.cdp.set_value(
            None,
            '[data-estab-messenger-assignment] input[name="ziel"]',
            destination,
            "eindeutiges Melderziel eintragen",
        )
        self.cdp.click(
            None,
            '[data-estab-messenger-assignment] button[type="submit"]',
            "Melderauftrag verbindlich absenden",
        )
        self.cdp.wait_for(
            f"""
            document.readyState === "complete" &&
            location.pathname === {json.dumps(operations_path)} &&
            new URLSearchParams(location.search).get("result") ===
                "messenger_assigned_notification_required" &&
            new URLSearchParams(location.search).get("presence") ===
                "signed_out" &&
            location.hash === "#melderauftraege" &&
            Boolean(document.querySelector(
                '.estab-tool-feedback-success'
            )) && Boolean(document.querySelector(
                '.estab-tool-feedback-warning'
            ))
            """,
            "PRG-Rückmeldung für den inaktiven Fernmelder fehlt",
        )
        prg_state = self.cdp.evaluate(
            f"""
            (() => {{
                const success = document.querySelector(
                    '.estab-tool-feedback-success'
                );
                const warning = document.querySelector(
                    '.estab-tool-feedback-warning'
                );
                const job = Array.from(document.querySelectorAll(
                    '#melderauftraege article.estab-tool-panel'
                )).find(candidate =>
                    candidate.textContent.includes({destination_literal}) &&
                    candidate.textContent.includes({inactive_literal})
                );
                return {{
                    success: success?.textContent.replace(/\\s+/g, " ")
                        .trim() || "",
                    warning: warning?.textContent.replace(/\\s+/g, " ")
                        .trim() || "",
                    distinct: Boolean(success && warning && success !== warning),
                    job: job?.textContent.replace(/\\s+/g, " ").trim() || ""
                }};
            }})()
            """
        )
        self._truth(
            isinstance(prg_state, dict)
            and prg_state.get("distinct") is True
            and "verbindlich erteilt" in str(prg_state.get("success", ""))
            and "Status des Fernmelders: abgemeldet" in str(
                prg_state.get("warning", "")
            )
            and "separat über den Auftrag informieren" in str(
                prg_state.get("warning", "")
            ),
            "Erfolg und serverseitiger Inaktivitätshinweis sind nicht getrennt.",
        )
        self._truth(
            "Auftrag #" in str(prg_state.get("job", ""))
            and "BEAUFTRAGT" in str(prg_state.get("job", ""))
            and destination in str(prg_state.get("job", ""))
            and inactive_code in str(prg_state.get("job", "")),
            "Der gespeicherte Auftrag mit Ziel ist nach PRG nicht sichtbar.",
        )
        self.cdp.click(
            None,
            "[data-estab-logout-form] button",
            "LdF nach dem Melderauftragstest abmelden",
        )

    def run(self, auth_recovery_only: bool = False) -> None:
        self.cdp.call("Page.enable")
        self.cdp.call("Runtime.enable")
        self.cdp.call("Network.enable")
        self.cdp.navigate(self.config.base_url + "/")
        total_steps = 5 if auth_recovery_only else 12

        print(f"[1/{total_steps}] Anonyme Übersicht, Bestandslogin und gesperrte Module")
        self._assert_anonymous_overview()
        self._assert_protected_cards()
        self._assert_root_card_layout("anonyme Übersicht bei 1440 px")

        print(f"[2/{total_steps}] Bestehenden Konto-Flow über das Frameset öffnen")
        self.cdp.click(None, "#estab-login", "Button für ein bestehendes Konto")
        self._wait_for_frame("mainframe")
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                return Array.from(doc.querySelectorAll("h1, h2")).some(
                    heading => heading.innerText.includes(
                        "Mit bestehendem Konto anmelden"
                    )
                ) && Boolean(doc.querySelector(
                    'input[autocomplete="current-password"]'
                ));
                """,
            ),
            "Formular für ein bestehendes Konto fehlt",
        )

        self.cdp.navigate(self.config.base_url + "/")
        print(
            f"[3/{total_steps}] Direkten ETB-Aufruf und sicheren "
            "Login-Abbruch prüfen"
        )
        navigation = self.cdp.call(
            "Page.navigate",
            {"url": self.config.base_url + "/stabetb/etb.php"},
        )
        if navigation.get("errorText"):
            raise TestFailure(
                "Chrome konnte den direkten ETB-Aufruf nicht starten."
            )
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            location.pathname.endsWith("/4fach/index.php") &&
            new URLSearchParams(location.search).get("login_flow") ===
                "existing" &&
            new URLSearchParams(location.search).get("next") === "incident-log"
            """,
            "direkter ETB-Aufruf wurde nicht zum Bestandslogin umgeleitet",
        )
        self._wait_for_frame("mainframe")
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const query = new target.URLSearchParams(target.location.search);
                return query.get("login_flow") === "existing" &&
                    query.get("next") === "incident-log" &&
                    Boolean(doc.querySelector("#estab-login-name")) &&
                    Boolean(doc.querySelector("[data-estab-auth-cancel]")) &&
                    Boolean(doc.querySelector(
                        '[data-estab-login-destination] strong'
                    ));
                """,
            ),
            "direkter ETB-Aufruf zeigt keinen vollständigen, verlassbaren Login",
        )
        self.cdp.click(
            "mainframe",
            "[data-estab-auth-cancel]",
            "Anmeldung abbrechen und zur Übersicht zurückkehren",
        )
        self._wait_for_top_level_path(
            "/",
            "Abbruch der Anmeldung führte nicht zur Übersicht",
        )
        self._assert_anonymous_overview()

        print(
            f"[4/{total_steps}] Abgelaufene Nachrichten-Navigation und "
            "-Eingabe sicher auffangen"
        )
        operational_navigation = self.cdp.call(
            "Page.navigate",
            {
                "url": self.config.base_url
                + "/4fach/mainindex.php?filter_anzahl_x=1&filter_anzahl=10"
            },
        )
        if operational_navigation.get("errorText"):
            raise TestFailure(
                "Chrome konnte die abgelaufene Nachrichten-Navigation "
                "nicht starten."
            )
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            location.pathname.endsWith("/4fach/mainindex.php") &&
            (() => {
                const query = new URLSearchParams(location.search);
                return query.size === 2 &&
                    query.get("login_flow") === "existing" &&
                    query.get("next") === "messages";
            })() &&
            document.forms.length > 0 &&
            Array.from(document.forms).every(
                form => form.target === "_self"
            ) &&
            !document.querySelector('form[target="mainframe"]') &&
            Boolean(document.querySelector("[data-estab-auth-cancel]")) &&
            !document.body.innerText.includes("Aktion nicht erlaubt")
            """,
            "abgelaufene Nachrichten-Navigation führte nicht zum "
            "verlassbaren Login ohne alte Filterwerte",
        )
        self.cdp.click(
            None,
            "[data-estab-auth-cancel]",
            "abgelaufene Nachrichten-Navigation abbrechen",
        )
        self._wait_for_top_level_path(
            "/",
            "abgebrochene Nachrichten-Navigation führte nicht zur Übersicht",
        )
        self._assert_anonymous_overview()

        expired_action = json.dumps(
            self.config.base_url + "/4fach/mainindex.php"
        )
        self._truth(
            self.cdp.evaluate(
                f"""
                (() => {{
                    const form = document.createElement("form");
                    form.method = "post";
                    form.action = {expired_action};
                    const task = document.createElement("input");
                    task.name = "task";
                    task.value = "Stab_schreiben";
                    form.append(task);
                    const content = document.createElement("input");
                    content.name = "12_inhalt";
                    content.value = "BROWSER_EXPIRED_POST_MUST_NOT_RUN";
                    form.append(content);
                    const csrf = document.createElement("input");
                    csrf.name = "csrf_token";
                    csrf.value = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
                        + "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
                    form.append(csrf);
                    document.body.append(form);
                    setTimeout(() => form.submit(), 0);
                    return true;
                }})()
                """
            ),
            "abgelaufenes Nachrichtenformular konnte nicht simuliert werden",
        )
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            location.pathname.endsWith("/4fach/mainindex.php") &&
            new URLSearchParams(location.search).get("login_flow") ===
                "existing" &&
            new URLSearchParams(location.search).get("next") === "messages" &&
            new URLSearchParams(location.search).get("interrupted") === "1" &&
            Boolean(document.querySelector(
                "[data-estab-submission-discarded]"
            )) &&
            Boolean(document.querySelector("[data-estab-auth-cancel]")) &&
            document.forms.length > 0 &&
            Array.from(document.forms).every(
                form => form.target === "_self"
            ) &&
            !document.querySelector('form[target="mainframe"]')
            """,
            "abgelaufenes Nachrichtenformular führte nicht zum "
            "verständlichen Login",
        )
        self.cdp.click(
            None,
            "[data-estab-auth-cancel]",
            "unterbrochene Nachrichtenanmeldung abbrechen",
        )
        self._wait_for_top_level_path(
            "/",
            "unterbrochene Nachrichtenanmeldung verließ den Login nicht",
        )
        self._assert_anonymous_overview()

        print(
            f"[5/{total_steps}] Wiederanmeldung aus Anhang und Kategorie "
            "ohne verschachtelten Arbeitsbereich"
        )
        for protected_path, description in (
            ("4fach/anhang.php", "Anhang"),
            (
                "4fach/katgoedt.php?dbtyp=fkt&msgno=1",
                "Kategorieverwaltung",
            ),
        ):
            self.cdp.navigate(
                self.config.base_url
                + "/4fach/index.php?login_flow=existing"
            )
            self._wait_for_frame("mainframe")
            protected_url = json.dumps(
                self.config.base_url + "/" + protected_path
            )
            self._truth(
                self.cdp.evaluate(
                    f"""
                    new Promise((resolve, reject) => {{
                        const frame = document.querySelector(
                            'iframe[name="mainframe"]'
                        );
                        if (!frame) {{
                            reject(new Error("mainframe fehlt"));
                            return;
                        }}
                        const timer = setTimeout(
                            () => reject(new Error("Frame-Navigation Timeout")),
                            15000
                        );
                        frame.addEventListener("load", () => {{
                            clearTimeout(timer);
                            resolve(true);
                        }}, {{once: true}});
                        frame.src = {protected_url};
                    }})
                    """
                ),
                f"{description}-Navigation im Inhaltsframe wurde nicht geladen",
            )
            self.cdp.wait_for(
                """
                document.readyState === "complete" &&
                location.pathname.endsWith("/4fach/index.php") &&
                document.querySelectorAll("iframe").length === 2 &&
                Boolean(document.querySelector('iframe[name="vorgaben"]')) &&
                Boolean(document.querySelector('iframe[name="mainframe"]'))
                """,
                f"{description}-Login veränderte den äußeren Arbeitsbereich",
            )
            self.cdp.wait_for(
                _frame_expression(
                    "mainframe",
                    """
                    const query = new target.URLSearchParams(
                        target.location.search
                    );
                    const forms = Array.from(doc.forms);
                    const cancel = doc.querySelector(
                        "[data-estab-auth-cancel]"
                    );
                    return doc.readyState === "complete" &&
                        target.location.pathname.endsWith(
                            "/4fach/mainindex.php"
                        ) &&
                        query.size === 2 &&
                        query.get("login_flow") === "existing" &&
                        query.get("next") === "messages" &&
                        doc.querySelectorAll("iframe").length === 0 &&
                        forms.length > 0 &&
                        forms.every(form => form.target === "_self") &&
                        !doc.querySelector('form[target="mainframe"]') &&
                        Boolean(cancel) &&
                        cancel.target === "_top";
                    """,
                ),
                f"{description} zeigte keinen frame-sicheren "
                "Bestandslogin",
            )
            self.cdp.click(
                "mainframe",
                "[data-estab-auth-cancel]",
                f"{description}-Wiederanmeldung abbrechen",
            )
            self._wait_for_top_level_path(
                "/",
                f"{description}-Anmeldeabbruch führte nicht zur Übersicht",
            )
            self._assert_anonymous_overview()

        if auth_recovery_only:
            return

        print("[6/12] Gesperrte ETB-Karte mit erhaltenem Anmeldeziel öffnen")
        self.cdp.click(
            None,
            'a.estab-menu-link[data-estab-nav-key="incident-log"]',
            "gesperrte Root-Karte für das Einsatztagebuch",
        )
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            location.pathname.endsWith("/4fach/index.php") &&
            new URLSearchParams(location.search).get("login_flow") ===
                "existing" &&
            new URLSearchParams(location.search).get("next") === "incident-log"
            """,
            "ETB-Karte hat das angeforderte Anmeldeziel nicht geöffnet",
        )
        self._wait_for_frame("mainframe")
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const query = new target.URLSearchParams(target.location.search);
                return target.location.pathname.endsWith("/4fach/mainindex.php") &&
                    query.get("login_flow") === "existing" &&
                    query.get("next") === "incident-log" &&
                    Boolean(doc.querySelector("#estab-login-name")) &&
                    !doc.querySelector('button[name="login_flow"][value="new"]');
                """,
            ),
            "Bestandskonto-Formular mit erhaltenem ETB-Ziel fehlt",
        )

        print("[7/12] Provisioniertes Konto anmelden und Einsatztagebuch öffnen")
        self.cdp.set_value(
            "mainframe", 'input[name="benutzer"]', self.config.login_name, "Benutzername"
        )
        self.cdp.set_value(
            "mainframe", 'input[name="kuerzel"]', self.config.login_code, "Kürzel"
        )
        self.cdp.set_value(
            "mainframe",
            'select[name="funktion"]',
            self.config.login_function,
            "Funktion",
            select=True,
        )
        self.cdp.set_value(
            "mainframe",
            'input[name="kennwort1"]',
            self.config.login_password,
            "Kennwort",
        )
        self.cdp.click(
            "mainframe",
            'button.estab-button-primary[type="submit"]',
            "Bestandskonto anmelden",
        )
        self._wait_for_top_level_path(
            "/stabetb/etb.php",
            "Einsatztagebuch wurde im LOOSE-Modus mit Kontofunktion nicht direkt "
            "als angefordertes Ziel geöffnet",
        )
        self._assert_session_bar(
            None,
            "Einsatztagebuch nach Bestandslogin",
            "incident-log",
        )

        if self.config.login_function not in ("LdF", "A/W"):
            self._assert_tracking_access_denied_page()

        self.cdp.click(
            None,
            '[data-estab-navigation] a[data-estab-nav-key="messages"]',
            "Nachrichtenvordruck aus dem angeforderten Einsatztagebuch",
        )
        self._wait_for_authenticated_frames()

        print("[8/12] Ungespeicherte fachliche Eingaben schützen den Bereichswechsel")
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression("mainframe", "aside[data-estab-session-bar]")
            ),
            0,
            "zusätzliche Session-Bar im Inhaltsframe",
        )
        self._assert_session_bar(
            "vorgaben",
            "Anwendungs-Navigationsframe",
            "messages",
        )
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression(
                    "vorgaben",
                    "aside[data-estab-session-bar].estab-session-bar-compact",
                )
            ),
            1,
            "kompakte Session-Bar im Anwendungs-Navigationsframe",
        )
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression(None, "aside[data-estab-session-bar]")
            ),
            0,
            "zusätzliche Session-Bar im Frameset-Dokument",
        )
        for width, height in (
            (1440, 1000),
            (1280, 720),
            (700, 760),
            (390, 844),
        ):
            self.cdp.call(
                "Emulation.setDeviceMetricsOverride",
                {
                    "width": width,
                    "height": height,
                    "deviceScaleFactor": 1,
                    "mobile": False,
                    "screenWidth": width,
                    "screenHeight": height,
                },
            )
            self._assert_application_sidebar_layout(
                f"Nachrichten-Sidebar bei {width}×{height} px"
            )
        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 1440,
                "height": 1000,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 1440,
                "screenHeight": 1000,
            },
        )

        self._assert_existing_message_attachment_previews()
        self._assert_dirty_navigation_guard()
        self._wait_for_authenticated_overview(
            "angemeldete Übersicht wurde nach bestätigtem Bereichswechsel nicht geöffnet"
        )

        print("[9/12] Navigation über Übersicht, BOS und Einsatztagebuch")
        self._click_root_card(
            "stabinfo/index.php",
            "Root-Karte für die Infosammlung BOS",
        )
        self._wait_for_top_level_path(
            "/stabinfo/index.php",
            "Infosammlung BOS wurde nicht über ihre Root-Karte geöffnet",
        )
        self.cdp.wait_for(
            _frame_expression(
                "status",
                """
                return target.location.pathname.endsWith("/stabinfo/l_index.php") &&
                    doc.readyState === "complete";
                """,
            ),
            "BOS-Navigationsframe wurde nicht vollständig geladen",
        )
        self._assert_session_bar("status", "BOS-Navigationsframe", "bos-info")
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression(
                    "status",
                    "aside[data-estab-session-bar].estab-session-bar-compact",
                )
            ),
            1,
            "kompakte Session-Bar im BOS-Navigationsframe",
        )
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression(None, "aside[data-estab-session-bar]")
            ),
            0,
            "zusätzliche Session-Bar im BOS-Frameset-Dokument",
        )
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression("mainframe", "aside[data-estab-session-bar]")
            ),
            0,
            "Session-Bar im statischen BOS-Inhaltsframe",
        )
        self._assert_bos_workspace_layout(
            "BOS-Infosammlung bei 1440×1000 px"
        )
        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 390,
                "height": 844,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 390,
                "screenHeight": 844,
            },
        )
        self._assert_bos_workspace_layout(
            "BOS-Infosammlung bei 390×844 px"
        )
        self.cdp.click(
            "status",
            'a[href$="Buchstabier.html"][target="mainframe"]',
            "BOS-Inhaltslink zum Buchstabieralphabet",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                return target.location.pathname.endsWith("/stabinfo/Buchstabier.html") &&
                    doc.readyState === "complete";
                """,
            ),
            "BOS-Inhaltslink wurde nicht im Inhaltsframe geöffnet",
        )
        self._assert_mobile_bos_navigation(
            "BOS-Infosammlung bei 390×844 px"
        )
        self._assert_session_bar(
            "status",
            "BOS-Navigationsframe nach Inhaltswechsel",
            "bos-info",
        )
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression(
                    "status",
                    "aside[data-estab-session-bar].estab-session-bar-compact",
                )
            ),
            1,
            "kompakte BOS-Session-Bar nach Inhaltswechsel",
        )
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression(None, "aside[data-estab-session-bar]")
            ),
            0,
            "zusätzliche Session-Bar im BOS-Frameset nach Inhaltswechsel",
        )
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression("mainframe", "aside[data-estab-session-bar]")
            ),
            0,
            "Session-Bar im BOS-Inhalt nach Inhaltswechsel",
        )
        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 1440,
                "height": 1000,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 1440,
                "screenHeight": 1000,
            },
        )
        self.cdp.click(
            "status",
            '[data-estab-navigation] a[data-estab-nav-key="overview"]',
            "Übersichtslink im BOS-Navigationsframe",
        )
        self._wait_for_authenticated_overview(
            "angemeldete Übersicht wurde nicht aus dem BOS-Bereich geöffnet"
        )

        self._click_root_card(
            "stabetb/etb.php",
            "Root-Karte für das Einsatztagebuch",
        )
        self._wait_for_top_level_path(
            "/stabetb/etb.php",
            "Einsatztagebuch wurde nicht über seine Root-Karte geöffnet",
        )
        self._assert_session_bar(None, "Einsatztagebuch", "incident-log")
        self._assert_command_post_tool()
        self._assert_generated_forms_tool()
        self._assert_attachment_upload_form()
        if self.config.admin_user and self.config.admin_password:
            self._assert_authenticated_administration_session_chrome()
        else:
            print(
                "      übersprungen: authentifizierte Admin-Sitzungsleisten "
                "ohne Admin-Testzugangsdaten"
            )

        print("[10/12] Logout aus dem Einsatztagebuch und Rückkehr in den anonymen Zustand")
        self.cdp.click(
            None,
            "[data-estab-logout-form] button",
            "Abmeldebutton im Einsatztagebuch",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const existing = doc.querySelector('button[name="login_flow"][value="existing"]');
                return Boolean(existing && !doc.querySelector("aside[data-estab-session-bar]"));
                """,
            ),
            "anonyme Anmeldung nach dem Logout fehlt",
        )
        for frame_name in ("mainframe", "vorgaben"):
            self._equal(
                self.cdp.evaluate(
                    _visible_count_expression(frame_name, "aside[data-estab-session-bar]")
                ),
                0,
                f"Session-Bar nach Logout im Frame {frame_name}",
            )
        self.cdp.navigate(self.config.base_url + "/")
        self._assert_anonymous_overview()
        self._assert_protected_cards()

        print("[11/12] Kartenraster in Desktop-, Zwischen- und Schmalansicht")
        for width in (1120, 800, 700, 672):
            self.cdp.call(
                "Emulation.setDeviceMetricsOverride",
                {
                    "width": width,
                    "height": 844,
                    "deviceScaleFactor": 1,
                    "mobile": False,
                    "screenWidth": width,
                    "screenHeight": 844,
                },
            )
            self.cdp.navigate(self.config.base_url + "/")
            self._assert_root_card_layout(
                f"anonyme Übersicht bei {width} px"
            )
        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 390,
                "height": 844,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 390,
                "screenHeight": 844,
            },
        )
        self.cdp.navigate(self.config.base_url + "/")
        self._assert_narrow_overview()
        self._assert_root_card_layout("anonyme Übersicht bei 390 px")

        print("[12/12] Adminübersicht, Exportverwaltung und Matrix-Bestätigungen")
        if self.config.admin_user and self.config.admin_password:
            self._assert_export_management()
        else:
            print("      übersprungen: keine Admin-Testzugangsdaten gesetzt")

    def _assert_export_management(self) -> None:
        assert self.config.admin_user is not None
        assert self.config.admin_password is not None
        credentials = (
            f"{self.config.admin_user}:{self.config.admin_password}".encode()
        )
        authorization = "Basic " + base64.b64encode(credentials).decode("ascii")
        self.cdp.call(
            "Network.setExtraHTTPHeaders",
            {"headers": {"Authorization": authorization}},
        )
        try:
            self.cdp.call(
                "Emulation.setDeviceMetricsOverride",
                {
                    "width": 1280,
                    "height": 800,
                    "deviceScaleFactor": 1,
                    "mobile": False,
                    "screenWidth": 1280,
                    "screenHeight": 800,
                },
            )
            self.cdp.navigate(self.config.base_url + "/4fadm/admin.php")
            self.cdp.wait_for(
                """
                document.readyState === "complete" &&
                Boolean(document.querySelector("[data-estab-admin-dashboard]")) &&
                document.querySelectorAll("[data-estab-admin-card]").length === 11 &&
                Boolean(document.querySelector(
                    '[data-estab-public-bar] [data-estab-admin-user]'
                ))
                """,
                "administrative Übersicht wurde nicht vollständig geladen",
            )
            self._assert_admin_dashboard_layout(
                "Adminübersicht bei 1280×800 px"
            )

            self.cdp.call(
                "Emulation.setDeviceMetricsOverride",
                {
                    "width": 390,
                    "height": 844,
                    "deviceScaleFactor": 1,
                    "mobile": False,
                    "screenWidth": 390,
                    "screenHeight": 844,
                },
            )
            self._assert_admin_dashboard_layout(
                "Adminübersicht bei 390×844 px",
                require_single_column=True,
            )
            self._assert_administration_tools()

            self.cdp.call(
                "Emulation.setDeviceMetricsOverride",
                {
                    "width": 1280,
                    "height": 800,
                    "deviceScaleFactor": 1,
                    "mobile": False,
                    "screenWidth": 1280,
                    "screenHeight": 800,
                },
            )
            self.cdp.navigate(self.config.base_url + "/4fadm/export.php")
            self.cdp.wait_for(
                """
                document.readyState === "complete" &&
                Boolean(document.querySelector("[data-estab-export-list]")) &&
                Boolean(document.querySelector("[data-estab-export-create]")) &&
                Boolean(document.querySelector(
                    '[data-estab-public-bar] [data-estab-admin-user]'
                ))
                """,
                "administrative Exportübersicht wurde nicht vollständig geladen",
            )
            self._assert_export_layout("Exportübersicht bei 1280×800 px")
            self._truth(
                "/var/lib/estab/export" not in str(
                    self.cdp.evaluate("document.body.innerText")
                ),
                "Exportübersicht zeigt einen internen Containerpfad.",
            )

            self.cdp.click(
                None,
                "[data-estab-export-create] button[type=submit]",
                "vollständigen Einsatzexport erstellen",
            )
            self.cdp.wait_for(
                """
                document.readyState === "complete" &&
                new URLSearchParams(location.search).has("created") &&
                Boolean(document.querySelector(
                    ".estab-export-card-new[data-estab-export-id]"
                )) &&
                Boolean(document.querySelector(
                    ".estab-export-card-new .estab-export-manifest[open]"
                ))
                """,
                "neu erstellter Export erscheint nicht in der Übersicht",
            )
            run_id = self.cdp.evaluate(
                """
                document.querySelector(
                    ".estab-export-card-new[data-estab-export-id]"
                )?.getAttribute("data-estab-export-id")
                """
            )
            self._truth(
                isinstance(run_id, str)
                and re.fullmatch(
                    r"estab-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}",
                    run_id,
                )
                is not None,
                "Neu erstellter Export hat keine kanonische Kennung.",
            )
            self._assert_export_layout(
                "Exportübersicht mit neuem Lauf bei 1280×800 px"
            )
            export_actions = self.cdp.evaluate(
                    """
                    (() => {
                        const card = document.querySelector(
                            ".estab-export-card-new"
                        );
                        if (!card) return null;
                        return {
                            downloads: card.querySelectorAll(
                                'a[href*="action=download"]'
                            ).length,
                            deleteDetails: card.querySelectorAll(
                                ".estab-export-delete-confirm"
                            ).length,
                            deleteForms: card.querySelectorAll(
                                "form[data-estab-export-delete]"
                            ).length,
                            hashes: card.querySelectorAll(
                                ".estab-export-manifest-list code"
                            ).length,
                            accessibleNames: [
                                card.querySelector(
                                    'a[href*="action=download"]'
                                )?.getAttribute("aria-label") || "",
                                card.querySelector(
                                    ".estab-export-delete-confirm summary"
                                )?.textContent || "",
                                card.querySelector(
                                    "[data-estab-export-delete] button"
                                )?.getAttribute("aria-label") || "",
                                card.querySelector(
                                    ".estab-export-manifest summary"
                                )?.textContent || ""
                            ]
                        };
                    })()
                    """
            )
            unique_run_code = run_id[-8:] if isinstance(run_id, str) else ""
            self._truth(
                isinstance(export_actions, dict)
                and export_actions.get("downloads") == 1
                and export_actions.get("deleteDetails") == 1
                and export_actions.get("deleteForms") == 1
                and int(export_actions.get("hashes", 0)) >= 1
                and unique_run_code != ""
                and all(
                    unique_run_code in accessible_name
                    for accessible_name in export_actions.get(
                        "accessibleNames",
                        [],
                    )
                )
                and len(export_actions.get("accessibleNames", [])) == 4,
                "Aktionen oder Manifest des neu erstellten Exports fehlen.",
            )

            self.cdp.call(
                "Emulation.setDeviceMetricsOverride",
                {
                    "width": 390,
                    "height": 844,
                    "deviceScaleFactor": 1,
                    "mobile": False,
                    "screenWidth": 390,
                    "screenHeight": 844,
                },
            )
            self._assert_export_layout("Exportübersicht bei 390×844 px")
            self._equal(
                self.cdp.evaluate(
                    """
                    document.querySelector(
                        ".estab-export-card-new .estab-export-delete-confirm"
                    )?.open
                    """
                ),
                False,
                "Löschbestätigung ist vor der bewussten Auswahl geöffnet",
            )
            self.cdp.click(
                None,
                ".estab-export-card-new .estab-export-delete-confirm > summary",
                "zweistufige Export-Löschbestätigung öffnen",
            )
            delete_state = self.cdp.evaluate(
                """
                (() => {
                    const details = document.querySelector(
                        ".estab-export-card-new .estab-export-delete-confirm"
                    );
                    const button = details?.querySelector(
                        "button.estab-button-danger"
                    );
                    if (!details || !button) return null;
                    const rect = button.getBoundingClientRect();
                    return {
                        open: details.open,
                        buttonWidth: rect.width,
                        buttonHeight: rect.height,
                        warning: details.innerText.includes(
                            "kann nicht rückgängig gemacht werden"
                        )
                    };
                })()
                """
            )
            self._truth(
                isinstance(delete_state, dict)
                and delete_state.get("open") is True
                and float(delete_state.get("buttonWidth", 0)) >= 44
                and float(delete_state.get("buttonHeight", 0)) >= 44
                and delete_state.get("warning") is True,
                "Zweistufige Löschbestätigung ist mobil nicht vollständig bedienbar.",
            )
            self._truth(
                "Abbrechen" in str(
                    self.cdp.evaluate(
                        """
                        document.querySelector(
                            ".estab-export-card-new " +
                            ".estab-export-delete-confirm > summary"
                        )?.innerText
                        """
                    )
                ),
                "Geöffnete Löschbestätigung bietet keinen verständlichen Rückweg.",
            )
            self._assert_export_layout(
                "Geöffnete Löschbestätigung bei 390×844 px"
            )
            self.cdp.click(
                None,
                ".estab-export-card-new [data-estab-export-delete] " +
                "button.estab-button-danger",
                "bestätigten Einsatzexport endgültig löschen",
            )
            expected_deleted_id = json.dumps(run_id)
            self.cdp.wait_for(
                f"""
                document.readyState === "complete" &&
                new URLSearchParams(location.search).get("deleted") ===
                    {expected_deleted_id} &&
                Boolean(document.querySelector(
                    '.estab-export-alert-success[role="status"]'
                )) &&
                !document.querySelector(
                    '[data-estab-export-id={json.dumps(run_id)}]'
                ) &&
                Boolean(document.querySelector("[data-estab-export-list]"))
                """,
                "bestätigter Export wurde nicht aus der Übersicht entfernt",
            )
            self._assert_export_layout(
                "Exportübersicht nach Löschung bei 390×844 px"
            )
            self._assert_matrix_confirmations()
        finally:
            self.cdp.call("Network.setExtraHTTPHeaders", {"headers": {}})

    def _assert_command_post_tool(self) -> None:
        for width, height in ((1280, 800), (390, 844)):
            self.cdp.call(
                "Emulation.setDeviceMetricsOverride",
                {
                    "width": width,
                    "height": height,
                    "deviceScaleFactor": 1,
                    "mobile": False,
                    "screenWidth": width,
                    "screenHeight": height,
                },
            )
            self.cdp.navigate(
                self.config.base_url + "/4fach/fuehrungsstelle.php"
            )
            self.cdp.wait_for(
                """
                document.readyState === "complete" &&
                Boolean(document.querySelector("[data-estab-dv-operations]")) &&
                Boolean(document.querySelector("aside[data-estab-session-bar]"))
                """,
                "Führungsstellenbetrieb wurde nicht vollständig geladen",
            )
            self._assert_session_bar(
                None,
                f"Führungsstellenbetrieb bei {width}×{height} px",
                "command-post",
            )
            self._assert_tool_page_layout(
                f"Führungsstellenbetrieb bei {width}×{height} px",
                "[data-estab-dv-operations]",
                mobile=width <= 390,
                require_responsive_table=True,
            )

        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 1440,
                "height": 1000,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 1440,
                "screenHeight": 1000,
            },
        )
        self.cdp.navigate(self.config.base_url + "/stabetb/etb.php")
        self._wait_for_top_level_path(
            "/stabetb/etb.php",
            "Einsatztagebuch wurde nach der Führungsstellenprüfung nicht "
            "wieder geladen",
        )
        self._assert_session_bar(
            None,
            "Einsatztagebuch nach der Führungsstellenprüfung",
            "incident-log",
        )

    def _assert_generated_forms_tool(self) -> None:
        for width, height in ((1280, 800), (390, 844)):
            self.cdp.call(
                "Emulation.setDeviceMetricsOverride",
                {
                    "width": width,
                    "height": height,
                    "deviceScaleFactor": 1,
                    "mobile": False,
                    "screenWidth": width,
                    "screenHeight": height,
                },
            )
            self.cdp.navigate(self.config.base_url + "/4fach/vordrucke.php")
            self.cdp.wait_for(
                """
                document.readyState === "complete" &&
                Boolean(document.querySelector("[data-estab-generated-forms]")) &&
                Boolean(document.querySelector("aside[data-estab-session-bar]"))
                """,
                "einsatzbezogene Vordruckübersicht wurde nicht vollständig geladen",
            )
            self._assert_tool_page_layout(
                f"Vordruckübersicht bei {width}×{height} px",
                "[data-estab-generated-forms]",
                mobile=width <= 390,
                require_responsive_table=True,
            )

        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 1440,
                "height": 1000,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 1440,
                "screenHeight": 1000,
            },
        )
        self.cdp.navigate(self.config.base_url + "/stabetb/etb.php")
        self._wait_for_top_level_path(
            "/stabetb/etb.php",
            "Einsatztagebuch wurde nach der Vordruckprüfung nicht wieder geladen",
        )
        self._assert_session_bar(
            None,
            "Einsatztagebuch nach Vordruckprüfung",
            "incident-log",
        )

    def _assert_attachment_upload_form(self) -> None:
        self.cdp.navigate(self.config.base_url + "/4fach/anhang.php")
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            Boolean(document.querySelector('input[name="ah_upload"]')) &&
            Boolean(document.querySelector("aside[data-estab-session-bar]"))
            """,
            "eigenständige Anhangübersicht wurde nicht vollständig geladen",
        )
        self.cdp.click(
            None,
            'input[name="ah_upload"]',
            "Uploadaktion in der Anhangübersicht",
        )
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            Boolean(document.querySelector("[data-estab-attachment-upload]")) &&
            Boolean(document.querySelector("#attachment-upload-file"))
            """,
            "Formular für einen neuen Anhang wurde nicht geladen",
        )
        upload_state = self.cdp.evaluate(
            """
            (() => {
                const input = document.querySelector(
                    "#attachment-upload-file"
                );
                const help = document.querySelector(
                    "#attachment-upload-help"
                );
                const label = document.querySelector(
                    'label[for="attachment-upload-file"]'
                );
                const cancel = document.querySelector(
                    'input[name="abbrechen"]'
                );
                return {
                    accept: input?.accept || "",
                    required: input?.required === true,
                    help: help?.innerText || "",
                    label: label?.innerText || "",
                    describedBy: input?.getAttribute(
                        "aria-describedby"
                    ) || "",
                    cancelSkipsValidation: cancel?.formNoValidate === true
                };
            })()
            """
        )
        self._truth(
            isinstance(upload_state, dict)
            and ".jpg" in str(upload_state.get("accept", "")).lower()
            and ".jpeg" in str(upload_state.get("accept", "")).lower()
            and upload_state.get("required") is True
            and "JPG, JPEG" in str(upload_state.get("help", ""))
            and "20 MiB" in str(upload_state.get("help", ""))
            and upload_state.get("label") == "Datei:"
            and upload_state.get("describedBy") == "attachment-upload-help"
            and upload_state.get("cancelSkipsValidation") is True,
            "JPEG-Unterstützung, Uploadgrenze oder Beschriftung fehlen im "
            f"Dateidialog: {upload_state!r}",
        )
        self.cdp.click(
            None,
            'input[name="abbrechen"]',
            "vorbereitete Anhangreservierung abbrechen",
        )
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            Boolean(document.querySelector('input[name="ah_upload"]')) &&
            !document.querySelector("[data-estab-attachment-upload]")
            """,
            "Anhangreservierung wurde nach Abbruch nicht verlassen",
        )
        self.cdp.navigate(self.config.base_url + "/stabetb/etb.php")
        self._wait_for_top_level_path(
            "/stabetb/etb.php",
            "Einsatztagebuch wurde nach der Anhangprüfung nicht wieder geladen",
        )
        self._assert_session_bar(
            None,
            "Einsatztagebuch nach Anhangprüfung",
            "incident-log",
        )

    def _assert_authenticated_administration_session_chrome(self) -> None:
        assert self.config.admin_user is not None
        assert self.config.admin_password is not None
        credentials = (
            f"{self.config.admin_user}:{self.config.admin_password}".encode()
        )
        authorization = "Basic " + base64.b64encode(credentials).decode("ascii")
        surfaces = (
            (
                "/4fadm/admin.php",
                "[data-estab-admin-dashboard]",
                "Adminübersicht",
            ),
            (
                "/4fadm/incidents.php",
                "[data-estab-incident-admin]",
                "Einsatzverwaltung",
            ),
            (
                "/4fadm/users.php",
                "[data-estab-user-admin]",
                "Benutzerverwaltung",
            ),
            (
                "/4fadm/password_policy.php",
                "[data-estab-password-policy]",
                "Kennwortrichtlinie",
            ),
            (
                "/4fadm/self_registration.php",
                "[data-estab-self-registration-admin]",
                "Selbstregistrierung",
            ),
            (
                "/4fadm/fuehrungsstelle.php",
                "[data-estab-shift-admin]",
                "Optionale Zugangsschichten",
            ),
            (
                "/4fadm/make_fkt.php",
                "[data-estab-matrix-tool]",
                "Empfängermatrix",
            ),
            (
                "/4fadm/set_number_after_crash.php",
                "[data-estab-counter-tool]",
                "Nachrichtenzähler",
            ),
            (
                "/4fach/resetpic.php",
                "[data-estab-print-reset-tool]",
                "Vordruck-Wiedererzeugung",
            ),
            (
                "/4fadm/incident_export.php",
                "[data-estab-incident-export]",
                "PDF-Einsatzdossier",
            ),
            (
                "/4fadm/export.php",
                "[data-estab-export-tool]",
                "Einsatzexporte",
            ),
            (
                "/4fadm/system_status.php",
                "[data-estab-system-status]",
                "Systemstatus",
            ),
        )
        self.cdp.call(
            "Network.setExtraHTTPHeaders",
            {"headers": {"Authorization": authorization}},
        )
        try:
            self.cdp.call(
                "Emulation.setDeviceMetricsOverride",
                {
                    "width": 1280,
                    "height": 800,
                    "deviceScaleFactor": 1,
                    "mobile": False,
                    "screenWidth": 1280,
                    "screenHeight": 800,
                },
            )
            for path, marker, label in surfaces:
                self.cdp.navigate(self.config.base_url + path)
                self.cdp.wait_for(
                    f"""
                    document.readyState === "complete" &&
                    Boolean(document.querySelector({json.dumps(marker)})) &&
                    Boolean(document.querySelector(
                        "aside[data-estab-session-bar] " +
                        "[data-estab-admin-user]"
                    ))
                    """,
                    f"{label} mit kombinierter Anmeldung wurde nicht geladen",
                )
                self._assert_session_bar(
                    None,
                    f"{label} mit eStab- und Administrationsanmeldung",
                    "administration",
                )
                self._equal(
                    self.cdp.evaluate(
                        """
                        document.querySelector(
                            "aside[data-estab-session-bar] " +
                            "[data-estab-admin-user]"
                        )?.getAttribute("data-estab-admin-user")
                        """
                    ),
                    self.config.admin_user,
                    f"technische Administrationsidentität in {label}",
                )
        finally:
            self.cdp.call("Network.setExtraHTTPHeaders", {"headers": {}})

        self.cdp.navigate(self.config.base_url + "/stabetb/etb.php")
        self._wait_for_top_level_path(
            "/stabetb/etb.php",
            "Einsatztagebuch wurde nach der Admin-Sitzungsprüfung nicht geladen",
        )
        self._assert_session_bar(
            None,
            "Einsatztagebuch nach der Admin-Sitzungsprüfung",
            "incident-log",
        )

    def _assert_administration_tools(self) -> None:
        tools = (
            (
                "/4fadm/incidents.php",
                "[data-estab-incident-admin]",
                "Einsatzverwaltung",
                False,
                True,
            ),
            (
                "/4fadm/users.php",
                "[data-estab-user-admin]",
                "Benutzerverwaltung",
                True,
                False,
            ),
            (
                "/4fadm/password_policy.php",
                "[data-estab-password-policy]",
                "Kennwortrichtlinie",
                False,
                True,
            ),
            (
                "/4fadm/self_registration.php",
                "[data-estab-self-registration-admin]",
                "Selbstregistrierung",
                False,
                True,
            ),
            (
                "/4fadm/fuehrungsstelle.php",
                "[data-estab-shift-admin]",
                "Optionale Zugangsschichten",
                True,
                True,
            ),
            (
                "/4fadm/set_number_after_crash.php",
                "[data-estab-counter-tool]",
                "Nachrichtenzähler",
                False,
                True,
            ),
            (
                "/4fach/resetpic.php",
                "[data-estab-print-reset-tool]",
                "Vordruck-Wiedererzeugung",
                False,
                True,
            ),
            (
                "/4fadm/make_fkt.php",
                "[data-estab-matrix-tool]",
                "Empfängermatrix",
                True,
                True,
            ),
            (
                "/4fadm/incident_export.php",
                "[data-estab-incident-export]",
                "PDF-Einsatzdossier",
                False,
                True,
            ),
            (
                "/4fadm/system_status.php",
                "[data-estab-system-status]",
                "Systemstatus",
                True,
                False,
            ),
        )
        for path, marker, label, responsive_table, require_target in tools:
            for width, height in ((1280, 800), (390, 844)):
                self.cdp.call(
                    "Emulation.setDeviceMetricsOverride",
                    {
                        "width": width,
                        "height": height,
                        "deviceScaleFactor": 1,
                        "mobile": False,
                        "screenWidth": width,
                        "screenHeight": height,
                    },
                )
                self.cdp.navigate(self.config.base_url + path)
                escaped_marker = json.dumps(marker)
                self.cdp.wait_for(
                    f"""
                    document.readyState === "complete" &&
                    Boolean(document.querySelector({escaped_marker})) &&
                    (
                        document.querySelectorAll(
                            "aside[data-estab-session-bar]"
                        ).length +
                        document.querySelectorAll(
                            "aside[data-estab-public-bar]"
                        ).length
                    ) === 1
                    """,
                    f"{label} wurde nicht vollständig geladen",
                )
                self._assert_tool_page_layout(
                    f"{label} bei {width}×{height} px",
                    marker,
                    mobile=width <= 390,
                    require_responsive_table=responsive_table,
                    require_target=require_target,
                )
                if path == "/4fadm/incidents.php":
                    self._assert_incident_archive_layout(
                        f"{label} bei {width}×{height} px",
                        mobile=width <= 390,
                    )
                if path == "/4fadm/self_registration.php" and width == 1280:
                    self_registration_state = self.cdp.evaluate(
                        """
                        (() => {
                            const form = action => document.querySelector(
                                `form:has(input[name="admin_action"]` +
                                `[value="${action}"])`
                            );
                            const temporary = form("enable_temporary");
                            const permanent = form("enable_permanent");
                            const disable = form("disable");
                            const durations = Array.from(
                                temporary?.querySelectorAll(
                                    'select[name="duration_minutes"] option'
                                ) || []
                            ).map(option => option.value);
                            return {
                                durations,
                                temporaryConfirmation:
                                    temporary?.querySelector(
                                        'input[name="confirm_activation"]'
                                    )?.required === true,
                                permanentConfirmation:
                                    permanent?.querySelector(
                                        'input[name="confirm_activation"]'
                                    )?.required === true,
                                disableWithoutConfirmation:
                                    disable !== null && !disable.querySelector(
                                        'input[name="confirm_activation"]'
                                    ),
                                revisionCount: document.querySelectorAll(
                                    'input[name="expected_revision"]'
                                ).length,
                                warningVisible: document.body.innerText.includes(
                                    "kontrollierten Netz und unter Aufsicht"
                                ),
                                expiryRefreshAbsent: !document.querySelector(
                                    '[data-estab-self-registration-refresh-ms], ' +
                                    '[data-estab-self-registration-expiry-refresh]'
                                )
                            };
                        })()
                        """
                    )
                    self._truth(
                        isinstance(self_registration_state, dict)
                        and self_registration_state.get("durations") == [
                            "15", "30", "60", "120", "240", "480",
                            "720", "1440",
                        ]
                        and self_registration_state.get(
                            "temporaryConfirmation"
                        ) is True
                        and self_registration_state.get(
                            "permanentConfirmation"
                        ) is True
                        and self_registration_state.get(
                            "disableWithoutConfirmation"
                        ) is True
                        and self_registration_state.get("revisionCount") == 3
                        and self_registration_state.get("warningVisible") is True
                        and self_registration_state.get(
                            "expiryRefreshAbsent"
                        ) is True,
                        "Selbstregistrierung trennt Zeitfenster, sofortiges "
                        "Schließen und Sicherheitsbestätigung nicht eindeutig: "
                        f"{self_registration_state!r}",
                    )
                if path == "/4fadm/users.php" and width == 1280:
                    password_length_state = self.cdp.evaluate(
                        """
                        (() => {
                            const input = document.querySelector(
                                'input[name="new_password"]' +
                                '[data-estab-password-minimum-codepoints]'
                            );
                            if (!input) return null;
                            const minimum = Number.parseInt(
                                input.getAttribute(
                                    'data-estab-password-minimum-codepoints'
                                ),
                                10
                            );
                            input.value = '🧭'.repeat(
                                Math.max(1, Math.ceil(minimum / 2))
                            );
                            input.dispatchEvent(new Event(
                                'input', {bubbles: true}
                            ));
                            const shortRejected = !input.checkValidity();
                            input.value = '🧭'.repeat(minimum);
                            input.dispatchEvent(new Event(
                                'input', {bubbles: true}
                            ));
                            return {
                                minimum,
                                nativeMinimum: input.getAttribute('minlength'),
                                shortRejected,
                                validAccepted: input.checkValidity(),
                                scriptLoaded: Array.from(document.scripts).some(
                                    script => script.src.endsWith(
                                        '/estab-password-policy.js'
                                    )
                                )
                            };
                        })()
                        """
                    )
                    self._truth(
                        isinstance(password_length_state, dict)
                        and int(password_length_state.get("minimum", 0)) >= 8
                        and password_length_state.get("nativeMinimum") is None
                        and password_length_state.get("shortRejected") is True
                        and password_length_state.get("validAccepted") is True
                        and password_length_state.get("scriptLoaded") is True,
                        "Benutzerverwaltung zählt die Kennwort-Mindestlänge "
                        "nicht exakt in Unicode-Codepoints: "
                        f"{password_length_state!r}",
                    )

    def _assert_incident_archive_layout(
        self,
        description: str,
        *,
        mobile: bool,
    ) -> None:
        state = self.cdp.evaluate(
            """
            (() => {
                const visible = element => {
                    if (!element) return false;
                    let ancestor = element.parentElement;
                    while (ancestor) {
                        if (
                            ancestor.matches("details:not([open])") &&
                            element !== ancestor.querySelector(":scope > summary")
                        ) {
                            return false;
                        }
                        ancestor = ancestor.parentElement;
                    }
                    const style = getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    return style.display !== "none" &&
                        style.visibility !== "hidden" &&
                        rect.width > 0 && rect.height > 0;
                };
                const overlaps = (first, second) =>
                    Math.min(first.right, second.right) -
                        Math.max(first.left, second.left) > 0.5 &&
                    Math.min(first.bottom, second.bottom) -
                        Math.max(first.top, second.top) > 0.5;
                const cards = Array.from(document.querySelectorAll(
                    "[data-estab-incident-card]"
                )).filter(visible);
                return {
                    cards: cards.map(card => {
                        const longTextProbes = [
                            {
                                node: card.querySelector(
                                    ".estab-incident-card-heading h3"
                                ),
                                boundary: card.querySelector(
                                    "[data-estab-incident-summary]"
                                )
                            },
                            {
                                node: card.querySelector(
                                    "[data-estab-command-post-readonly] strong"
                                ),
                                boundary: card.querySelector(
                                    "[data-estab-command-post-readonly]"
                                )
                            }
                        ].filter(probe => probe.node && probe.boundary);
                        const originalProbeText = longTextProbes.map(
                            probe => probe.node.textContent
                        );
                        longTextProbes.forEach((probe, index) => {
                            probe.node.textContent =
                                `GEOMETRIEPROBE${index}` + "X".repeat(240);
                        });
                        const longTextContained =
                            longTextProbes.length >= 1 &&
                            longTextProbes.every(probe => {
                                const boundaryRect =
                                    probe.boundary.getBoundingClientRect();
                                const nodeRect = probe.node.getBoundingClientRect();
                                return probe.boundary.scrollWidth <=
                                        probe.boundary.clientWidth + 1 &&
                                    nodeRect.left >= boundaryRect.left - 0.5 &&
                                    nodeRect.right <= boundaryRect.right + 0.5;
                            });
                        longTextProbes.forEach((probe, index) => {
                            probe.node.textContent = originalProbeText[index];
                        });
                        const cardRect = card.getBoundingClientRect();
                        const overview = card.querySelector(
                            "[data-estab-incident-summary]"
                        );
                        const actions = card.querySelector(
                            "[data-estab-incident-actions]"
                        );
                        const title = actions?.querySelector(
                            ".estab-incident-card-actions-title"
                        );
                        const status = card.querySelector(
                            "[data-estab-incident-card-status]"
                        );
                        const code = card.querySelector(
                            ".estab-tool-card-code"
                        );
                        const overviewRect =
                            overview?.getBoundingClientRect() || null;
                        const actionsRect =
                            actions?.getBoundingClientRect() || null;
                        const codeRect = code?.getBoundingClientRect() || null;
                        const codeStyle = code ? getComputedStyle(code) : null;
                        const parsedLineHeight = codeStyle
                            ? Number.parseFloat(codeStyle.lineHeight)
                            : 0;
                        const lineHeight = Number.isFinite(parsedLineHeight)
                            ? parsedLineHeight
                            : Number.parseFloat(codeStyle?.fontSize || "0") * 1.2;
                        const panels = Array.from(
                            actions?.querySelectorAll(
                                ":scope > .estab-incident-action"
                            ) || []
                        ).filter(visible);
                        const panelRects = panels.map(panel => {
                            const rect = panel.getBoundingClientRect();
                            return {
                                left: rect.left,
                                right: rect.right,
                                top: rect.top,
                                bottom: rect.bottom,
                                width: rect.width
                            };
                        });
                        const panelOverlaps = [];
                        for (
                            let first = 0;
                            first < panelRects.length;
                            first += 1
                        ) {
                            for (
                                let second = first + 1;
                                second < panelRects.length;
                                second += 1
                            ) {
                                if (overlaps(
                                    panelRects[first],
                                    panelRects[second]
                                )) {
                                    panelOverlaps.push([first, second]);
                                }
                            }
                        }
                        const firstPanel = panelRects[0] || null;
                        const controls = Array.from(card.querySelectorAll(
                            ".estab-incident-action button:not([disabled])," +
                            ".estab-incident-action summary," +
                            ".estab-incident-action select:not([disabled])," +
                            ".estab-incident-action textarea:not([disabled])," +
                            ".estab-incident-action input:not([type=hidden])" +
                                ":not([disabled])"
                        )).filter(visible);
                        const controlStates = controls.map(control => {
                            const target = control.matches(
                                'input[type="checkbox"],input[type="radio"]'
                            ) ? control.closest("label") : control;
                            const panel = control.closest(
                                ".estab-incident-action"
                            );
                            if (!target || !panel) {
                                return {
                                    ok: false,
                                    element: control.tagName.toLowerCase(),
                                    type: control.getAttribute("type") || "",
                                    name: control.getAttribute("name") || "",
                                    reason: "target-or-panel-missing"
                                };
                            }
                            target.scrollIntoView({
                                block: "center",
                                inline: "nearest"
                            });
                            const rect = target.getBoundingClientRect();
                            const panelRect = panel.getBoundingClientRect();
                            const pointX = rect.left + rect.width / 2;
                            const pointY = rect.top + rect.height / 2;
                            const hit = document.elementFromPoint(
                                pointX,
                                pointY
                            );
                            control.focus({preventScroll: true});
                            const largeEnough =
                                rect.width >= 44 && rect.height >= 44;
                            const contained =
                                rect.left >= panelRect.left - 0.5 &&
                                rect.right <= panelRect.right + 0.5;
                            const hitTarget = Boolean(hit) &&
                                (target === hit || target.contains(hit) ||
                                    hit.contains(target));
                            const focused =
                                document.activeElement === control;
                            return {
                                ok: largeEnough && contained && hitTarget && focused,
                                element: control.tagName.toLowerCase(),
                                type: control.getAttribute("type") || "",
                                name: control.getAttribute("name") || "",
                                width: rect.width,
                                height: rect.height,
                                largeEnough,
                                contained,
                                hitTarget,
                                focused
                            };
                        });
                        const controlFailures = controlStates.filter(
                            control => !control.ok
                        );
                        return {
                            cardWidth: cardRect.width,
                            overviewWidth: overviewRect?.width || 0,
                            overviewActionsOverlap: Boolean(
                                overviewRect && actionsRect &&
                                overlaps(overviewRect, actionsRect)
                            ),
                            actionsBelowOverview: Boolean(
                                overviewRect && actionsRect &&
                                actionsRect.top >= overviewRect.bottom - 0.5
                            ),
                            actionDisplay: actions
                                ? getComputedStyle(actions).display
                                : "",
                            actionTitleVisible: visible(title),
                            statusVisible: visible(status),
                            codeVisible: visible(code),
                            longTextContained,
                            panelCount: panelRects.length,
                            panelOverlaps,
                            panelsContained: Boolean(actionsRect) &&
                                panelRects.every(rect =>
                                    rect.left >= actionsRect.left - 0.5 &&
                                    rect.right <= actionsRect.right + 0.5
                                ),
                            panelsSingleColumn: Boolean(firstPanel) &&
                                panelRects.every(rect =>
                                    Math.abs(rect.left - firstPanel.left) <= 1 &&
                                    Math.abs(rect.right - firstPanel.right) <= 1
                                ),
                            narrowestPanel: panelRects.reduce(
                                (width, rect) => Math.min(width, rect.width),
                                panelRects.length > 0
                                    ? panelRects[0].width
                                    : 0
                            ),
                            codeLines: codeRect && lineHeight > 0
                                ? Math.ceil(codeRect.height / lineHeight)
                                : 0,
                            controlCount: controls.length,
                            controlsUsable: controlFailures.length === 0,
                            controlFailures,
                            cardScrollFits:
                                card.scrollWidth <= card.clientWidth + 1
                        };
                    })
                };
            })()
            """
        )
        self._truth(
            isinstance(state, dict)
            and isinstance(state.get("cards"), list)
            and len(state["cards"]) >= 1,
            f"{description}: keine vermessbare Einsatzkarte vorhanden.",
        )
        for index, card in enumerate(state["cards"], start=1):
            self._truth(
                card.get("overviewWidth", 0) >= card.get("cardWidth", 0) * 0.85
                and card.get("overviewActionsOverlap") is False
                and card.get("actionsBelowOverview") is True
                and card.get("actionDisplay") == "grid"
                and card.get("actionTitleVisible") is True
                and card.get("statusVisible") is True
                and card.get("codeVisible") is True
                and card.get("longTextContained") is True
                and int(card.get("panelCount", 0)) >= 1
                and card.get("panelOverlaps") == []
                and card.get("panelsContained") is True
                and 1 <= int(card.get("codeLines", 0)) <= 2
                and int(card.get("controlCount", 0)) >= 1
                and card.get("controlsUsable") is True
                and card.get("cardScrollFits") is True,
                f"{description}: Einsatzkarte {index} kollabiert, überlappt "
                f"oder verliert Status/Aktionen: {card!r}",
            )
            if mobile:
                self._truth(
                    card.get("panelsSingleColumn") is True,
                    f"{description}: Aktionskacheln der Einsatzkarte {index} "
                    "bilden mobil keine einheitliche Spalte.",
                )
            else:
                self._truth(
                    float(card.get("narrowestPanel", 0)) >= 260,
                    f"{description}: Eine Aktionskachel der Einsatzkarte "
                    f"{index} ist am Desktop zu schmal: {card!r}",
                )

    def _assert_tool_page_layout(
        self,
        description: str,
        marker: str,
        *,
        mobile: bool,
        require_responsive_table: bool = False,
        require_target: bool = False,
    ) -> None:
        state = self.cdp.evaluate(
            f"""
            (() => {{
                const visible = element => {{
                    if (!element) return false;
                    const style = getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    return style.display !== "none" &&
                        style.visibility !== "hidden" &&
                        rect.width > 0 && rect.height > 0;
                }};
                const marker = document.querySelector({json.dumps(marker)});
                const main = document.querySelector(".estab-tool-main");
                const mainRect = main?.getBoundingClientRect() || null;
                const targets = Array.from(document.querySelectorAll(
                    ".estab-tool-main .estab-button," +
                    ".estab-tool-main summary"
                )).filter(visible);
                const table = document.querySelector(".estab-tool-table");
                const wrapper = document.querySelector(
                    ".estab-tool-table-responsive"
                );
                const firstCell = table?.querySelector("tbody td") || null;
                return {{
                    markerVisible: visible(marker),
                    heroVisible: visible(document.querySelector(
                        ".estab-tool-hero"
                    )),
                    footerVisible: visible(document.querySelector(
                        ".estab-tool-footer"
                    )),
                    navigationVisible: visible(document.querySelector(
                        "[data-estab-navigation]"
                    )),
                    barCount:
                        document.querySelectorAll(
                            "aside[data-estab-session-bar]"
                        ).length +
                        document.querySelectorAll(
                            "aside[data-estab-public-bar]"
                        ).length,
                    mainFits: Boolean(mainRect) &&
                        mainRect.left >= -0.5 &&
                        mainRect.right <= innerWidth + 0.5,
                    targetsFit: targets.every(target => {{
                        const rect = target.getBoundingClientRect();
                        return rect.width >= 44 &&
                            rect.height >= 44 &&
                            rect.left >= -0.5 &&
                            rect.right <= innerWidth + 0.5;
                    }}),
                    targetCount: targets.length,
                    documentScrollWidth:
                        document.documentElement.scrollWidth,
                    innerWidth,
                    tablePresent: Boolean(table),
                    emptyVisible: visible(document.querySelector(
                        ".estab-tool-empty"
                    )),
                    responsiveTable: Boolean(
                        table && wrapper && firstCell &&
                        getComputedStyle(table).display === "block" &&
                        getComputedStyle(firstCell).display === "block" &&
                        getComputedStyle(wrapper).overflowX === "visible"
                    )
                }};
            }})()
            """
        )
        self._truth(
            isinstance(state, dict)
            and state.get("markerVisible") is True
            and state.get("heroVisible") is True
            and state.get("footerVisible") is True
            and state.get("navigationVisible") is True
            and state.get("barCount") == 1,
            f"{description}: Werkzeugseite, Navigation oder einzelne Shared-Bar fehlt.",
        )
        self._truth(
            state.get("mainFits") is True
            and state.get("targetsFit") is True,
            f"{description}: Inhalt oder Bedienelemente ragen aus dem Viewport.",
        )
        if require_target:
            self._truth(
                int(state.get("targetCount", 0)) >= 1,
                f"{description}: kein bedienbares Werkzeugziel sichtbar.",
            )
        self._truth(
            int(state.get("documentScrollWidth", 0))
            <= int(state.get("innerWidth", 0)) + 1,
            f"{description}: Seite erzeugt horizontales Dokument-Scrolling: "
            f"{state!r}",
        )
        if mobile and require_responsive_table:
            self._truth(
                (
                    state.get("tablePresent") is True
                    and state.get("responsiveTable") is True
                )
                or (
                    state.get("tablePresent") is False
                    and state.get("emptyVisible") is True
                ),
                f"{description}: Datentabelle wird mobil nicht zu beschrifteten "
                "Karten und der Leerzustand fehlt.",
            )

    def _assert_admin_dashboard_layout(
        self,
        description: str,
        require_single_column: bool = False,
    ) -> None:
        state = self.cdp.evaluate(
            """
            (() => {
                const visible = element => {
                    if (!element) return false;
                    const style = getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    return style.display !== "none" &&
                        style.visibility !== "hidden" &&
                        rect.width > 0 && rect.height > 0;
                };
                const cards = Array.from(document.querySelectorAll(
                    "[data-estab-admin-card]"
                )).filter(visible);
                const cardRects = cards.map(card => {
                    const rect = card.getBoundingClientRect();
                    return {
                        key: card.getAttribute("data-estab-admin-card"),
                        left: rect.left,
                        right: rect.right,
                        top: rect.top,
                        bottom: rect.bottom,
                        width: rect.width,
                        height: rect.height
                    };
                });
                const overlaps = [];
                for (let first = 0; first < cardRects.length; first += 1) {
                    for (
                        let second = first + 1;
                        second < cardRects.length;
                        second += 1
                    ) {
                        const a = cardRects[first];
                        const b = cardRects[second];
                        if (
                            Math.min(a.right, b.right) -
                                Math.max(a.left, b.left) > 0.5 &&
                            Math.min(a.bottom, b.bottom) -
                                Math.max(a.top, b.top) > 0.5
                        ) {
                            overlaps.push([a.key, b.key]);
                        }
                    }
                }
                const firstRect = cardRects[0] || null;
                return {
                    dashboardVisible: visible(document.querySelector(
                        "[data-estab-admin-dashboard]"
                    )),
                    sectionCount: document.querySelectorAll(
                        ".estab-admin-dashboard-section"
                    ).length,
                    keys: cardRects.map(card => card.key),
                    cardsFit: cardRects.every(card =>
                        card.width >= 44 &&
                        card.height >= 44 &&
                        card.left >= -0.5 &&
                        card.right <= innerWidth + 0.5
                    ),
                    singleColumn: Boolean(firstRect) && cardRects.every(card =>
                        Math.abs(card.left - firstRect.left) <= 1 &&
                        Math.abs(card.right - firstRect.right) <= 1
                    ),
                    overlaps,
                    innerWidth,
                    documentScrollWidth:
                        document.documentElement.scrollWidth,
                    adminUserVisible: visible(document.querySelector(
                        "[data-estab-public-bar] [data-estab-admin-user]"
                    )),
                    navigationVisible: visible(document.querySelector(
                        "[data-estab-navigation]"
                    ))
                };
            })()
            """
        )
        self._truth(isinstance(state, dict), f"{description}: Layoutstatus fehlt.")
        self._truth(
            state.get("dashboardVisible") is True
            and state.get("adminUserVisible") is True
            and state.get("navigationVisible") is True,
            f"{description}: Übersicht, Adminidentität oder Navigation fehlt.",
        )
        self._equal(
            state.get("keys"),
            [
                "incidents",
                "users",
                "self-registration",
                "password-policy",
                "command-post",
                "matrix",
                "counter",
                "print-reset",
                "incident-pdf",
                "export",
                "system-status",
            ],
            f"{description}: administrative Karten",
        )
        self._equal(
            state.get("sectionCount"),
            3,
            f"{description}: administrative Gruppen",
        )
        self._truth(
            state.get("cardsFit") is True,
            f"{description}: Eine Karte ragt aus dem Viewport oder ist kleiner "
            "als 44 × 44 Pixel.",
        )
        self._equal(
            state.get("overlaps"),
            [],
            f"{description}: überlappende Karten",
        )
        self._truth(
            int(state.get("documentScrollWidth", 0))
            <= int(state.get("innerWidth", 0)) + 1,
            f"{description}: Seite erzeugt horizontales Dokument-Scrolling: "
            f"{state!r}",
        )
        if require_single_column:
            self._truth(
                state.get("singleColumn") is True,
                f"{description}: Karten bilden mobil keine einheitliche Spalte.",
            )

    def _assert_matrix_confirmations(self) -> None:
        self.cdp.navigate(self.config.base_url + "/4fadm/make_fkt.php")
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            Boolean(document.querySelector('input[name="pos_13"]')) &&
            Boolean(document.querySelector(
                'button[value="load_standard"][data-estab-confirm]'
            )) &&
            Boolean(document.querySelector(
                'button[value="save_matrix_and_standard"][data-estab-confirm]'
            ))
            """,
            "Matrixeditor mit bestätigungspflichtigen Aktionen wurde nicht geladen",
        )
        self.cdp.set_value(
            None,
            'input[name="pos_13"]',
            "BRTEST",
            "ungespeicherter Matrix-Testwert",
        )

        for selector, expected_prefix, description in (
            (
                'button[value="load_standard"]',
                "Die aktuellen Editorwerte werden verworfen",
                "Standardmatrix laden",
            ),
            (
                'button[value="save_matrix_and_standard"]',
                "Die aktive Matrix wird gespeichert",
                "bisherigen Standard ersetzen",
            ),
        ):
            dialog = self.cdp.click(
                None,
                selector,
                description,
                dialog_accept=False,
            )
            self._truth(
                isinstance(dialog, dict)
                and dialog.get("type") == "confirm"
                and str(dialog.get("message", "")).startswith(expected_prefix),
                f"{description} öffnete keinen verständlichen Bestätigungsdialog.",
            )
            self.cdp.wait_for(
                """
                location.pathname.endsWith("/4fadm/make_fkt.php") &&
                document.querySelector('input[name="pos_13"]')?.value ===
                    "BRTEST"
                """,
                f"Abgelehnte Aktion „{description}“ verwarf Editorwerte.",
            )

    def _assert_export_layout(self, description: str) -> None:
        state = self.cdp.evaluate(
            """
            (() => {
                const visible = element => {
                    if (!element) return false;
                    const style = getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    return style.display !== "none" &&
                        style.visibility !== "hidden" &&
                        rect.width > 0 && rect.height > 0;
                };
                const targets = Array.from(document.querySelectorAll(
                    "[data-estab-export-create] button," +
                    ".estab-export-card a.estab-button," +
                    ".estab-export-card summary.estab-button"
                )).filter(visible);
                const cards = Array.from(document.querySelectorAll(
                    ".estab-export-card"
                ));
                const fullSessionBars = Array.from(document.querySelectorAll(
                    "body > aside.estab-session-bar" +
                    ":not(.estab-session-bar-compact)"
                ));
                const narrowViewport = matchMedia(
                    "(max-width: 42rem)"
                ).matches;
                const navigation = document.querySelector(
                    ".estab-navigation"
                );
                const navigationContent = document.querySelector(
                    ".estab-navigation-content"
                );
                const metrics = element => {
                    if (!element) return null;
                    const rect = element.getBoundingClientRect();
                    const style = getComputedStyle(element);
                    return {
                        left: rect.left,
                        right: rect.right,
                        width: rect.width,
                        clientWidth: element.clientWidth,
                        scrollWidth: element.scrollWidth,
                        overflowX: style.overflowX,
                        position: style.position
                    };
                };
                const overflowElements = Array.from(
                    document.body.querySelectorAll("*")
                ).filter(element => {
                    const style = getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    return style.display !== "none" &&
                        style.visibility !== "hidden" &&
                        rect.width > 0 &&
                        rect.height > 0 &&
                        (
                            rect.left < -0.5 ||
                            rect.right > innerWidth + 0.5
                        );
                }).slice(0, 20).map(element => {
                    const rect = element.getBoundingClientRect();
                    return {
                        tag: element.tagName.toLowerCase(),
                        id: element.id,
                        classes: String(element.className),
                        left: rect.left,
                        right: rect.right,
                        width: rect.width
                    };
                });
                return {
                    innerWidth: innerWidth,
                    bodyScrollWidth: document.documentElement.scrollWidth,
                    bodyClientWidth: document.documentElement.clientWidth,
                    body: metrics(document.body),
                    sessionBar: metrics(document.querySelector(
                        ".estab-session-bar"
                    )),
                    fullSessionBarCount: fullSessionBars.length,
                    mobileHeaderNonSticky: fullSessionBars.length === 1 &&
                        (!narrowViewport || getComputedStyle(
                            fullSessionBars[0]
                        ).position === "static"),
                    sessionTopline: metrics(document.querySelector(
                        ".estab-session-topline"
                    )),
                    sessionIdentity: metrics(document.querySelector(
                        ".estab-session-identity"
                    )),
                    sessionActions: metrics(document.querySelector(
                        ".estab-session-actions"
                    )),
                    navigation: metrics(navigation),
                    navigationContent: metrics(navigationContent),
                    overflowElements,
                    cards: cards.length,
                    cardsFit: cards.every(card => {
                        const rect = card.getBoundingClientRect();
                        return rect.left >= -0.5 &&
                            rect.right <= innerWidth + 0.5;
                    }),
                    targetCount: targets.length,
                    targetsFit: targets.every(target => {
                        const rect = target.getBoundingClientRect();
                        return rect.width >= 44 && rect.height >= 44 &&
                            rect.left >= -0.5 &&
                            rect.right <= innerWidth + 0.5;
                    }),
                    listVisible: visible(document.querySelector(
                        "[data-estab-export-list]"
                    )),
                    createVisible: visible(document.querySelector(
                        "[data-estab-export-create]"
                    ))
                };
            })()
            """
        )
        self._truth(isinstance(state, dict), f"{description}: Layoutstatus fehlt.")
        self._truth(
            state.get("listVisible") is True
            and state.get("createVisible") is True,
            f"{description}: Erstellen oder Exportliste ist nicht sichtbar.",
        )
        self._truth(
            int(state.get("bodyScrollWidth", 0)) <= int(state.get("innerWidth", 0)) + 1,
            f"{description}: Seite erzeugt horizontales Dokument-Scrolling: "
            f"{state!r}",
        )
        self._truth(
            state.get("cardsFit") is True and state.get("targetsFit") is True,
            f"{description}: Karten oder Bedienelemente ragen aus dem Viewport.",
        )
        self._truth(
            state.get("fullSessionBarCount") == 1
            and state.get("mobileHeaderNonSticky") is True,
            f"{description}: Die mobile Status- und Navigationsleiste "
            "verdeckt den Arbeitsbereich beim Scrollen.",
        )
        self._truth(
            int(state.get("targetCount", 0)) >= 1,
            f"{description}: Keine mindestens 44 px großen Aktionen gefunden.",
        )

    def _assert_anonymous_overview(
        self,
        registration_allowed: bool | None = False,
    ) -> None:
        self._equal(
            self.cdp.evaluate(_visible_count_expression(None, "#estab-login")),
            1,
            "Anmeldebutton auf der anonymen Übersicht",
        )
        self._equal(
            self.cdp.evaluate(_text_expression(None, "#estab-login")),
            "Mit bestehendem Konto anmelden",
            "Beschriftung des Anmeldebuttons",
        )
        registration_count = self.cdp.evaluate(
            _visible_count_expression(None, "#estab-register")
        )
        if registration_allowed is None:
            self._truth(
                registration_count in (0, 1),
                "Die anonyme Übersicht zeigt den Registrierungsbutton mehrfach.",
            )
        else:
            self._equal(
                registration_count,
                1 if registration_allowed else 0,
                (
                    "Registrierungsbutton auf der anonymen Übersicht"
                    if registration_allowed
                    else "unerwarteter Selbstregistrierungsbutton "
                        "auf der anonymen Übersicht"
                ),
            )
        registration_visible = registration_count == 1
        flow_urls = self.cdp.evaluate(
            """
            (() => {
                const existing = document.querySelector("#estab-login");
                if (!existing) return null;
                const existingUrl = new URL(existing.href, location.href);
                return {
                    existing: existingUrl.pathname + existingUrl.search
                };
            })()
            """
        )
        self._truth(flow_urls, "Der Bestandskonto-Flow ist nicht eindeutig verlinkt.")
        self._truth(
            str(flow_urls["existing"]).endswith("/4fach/index.php?login_flow=existing"),
            "Der Button für ein bestehendes Konto öffnet nicht den bestehenden Konto-Flow.",
        )
        copy_expression = (
            "text.includes('Bestehendes Konto') && "
            "text.includes('Neues Konto anlegen') && "
            "text.includes('Kürzel') && "
            "text.includes('Registrierung')"
            if registration_visible
            else "text.includes('Bestehendes Konto') && "
                "text.includes('nicht selbst angelegt') && "
                "text.includes('Administration') && "
                "text.includes('Benutzerverwaltung')"
        )
        copy_is_clear = self.cdp.evaluate(
            f"""
            (() => {{
                const text = document.body.innerText.replace(/\\s+/g, " ");
                return {copy_expression};
            }})()
            """
        )
        self._truth(
            copy_is_clear,
            (
                "Übersicht erklärt Bestandslogin und Neuanlage nicht klar."
                if registration_visible
                else "Übersicht erklärt Bestandslogin und administrative "
                    "Kontoanlage nicht klar."
            ),
        )
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression(None, "aside[data-estab-session-bar]")
            ),
            0,
            "Session-Bar auf der anonymen Übersicht",
        )
        public_navigation = self.cdp.evaluate(
            """
            (() => {
                const bar = document.querySelector("aside[data-estab-public-bar]");
                const navigation = bar &&
                    bar.querySelector("[data-estab-navigation]");
                const core = navigation
                    ? Array.from(navigation.querySelectorAll(
                        '[data-estab-navigation-group="areas"] a[data-estab-nav-key]'
                    ))
                    : [];
                const current = navigation
                    ? Array.from(navigation.querySelectorAll('[aria-current="page"]'))
                    : [];
                return bar && navigation ? {
                    keys: core.map(link => link.getAttribute("data-estab-nav-key")),
                    active: current.map(link => link.getAttribute("data-estab-nav-key")),
                    locked: core.filter(link =>
                        link.closest("[data-estab-navigation-locked]")
                    ).length
                } : null;
            })()
            """
        )
        self._truth(
            isinstance(public_navigation, dict),
            "Öffentliche Bereichsnavigation fehlt auf der anonymen Übersicht.",
        )
        self._equal(
            public_navigation.get("keys"),
            list(self.navigation_keys),
            "Reihenfolge der anonymen Bereichsnavigation",
        )
        self._equal(
            public_navigation.get("active"),
            ["overview"],
            "Aktiver Bereich der anonymen Navigation",
        )
        self._equal(
            public_navigation.get("locked"),
            7,
            "Anzahl anmeldepflichtiger Bereiche in der anonymen Navigation",
        )
        technical_log_label = self.cdp.evaluate(
            """
            (() => {
                const link = document.querySelector(
                    '.estab-menu-link[data-estab-nav-key="technical-log"]'
                );
                const title = link && link.querySelector(".estab-menu-title");
                return title
                    ? title.innerText.replace(/\\s+/g, " ").trim()
                    : null;
            })()
            """
        )
        self._equal(
            technical_log_label,
            "Technisches Betriebsbuch (TBB)",
            "Bezeichnung des Technischen Betriebsbuchs",
        )

    def _wait_for_authenticated_overview(self, description: str) -> None:
        expected_url = json.dumps(self.config.base_url + "/")
        self.cdp.wait_for(
            f"""
            (() => {{
                const expected = new URL({expected_url});
                return document.readyState === "complete" &&
                    location.origin === expected.origin &&
                    location.pathname === expected.pathname &&
                    location.search === expected.search &&
                    Boolean(document.querySelector("#estab-open")) &&
                    Boolean(document.querySelector("aside[data-estab-session-bar]"));
            }})()
            """,
            description,
        )
        self._assert_session_bar(None, "Modulübersicht", "overview")
        self._equal(
            self.cdp.evaluate(_visible_count_expression(None, "#estab-open")),
            1,
            "Anwendungsbutton auf der angemeldeten Übersicht",
        )
        self._assert_internal_cards_same_tab()
        self._assert_root_card_layout("angemeldete Übersicht")

    def _assert_application_sidebar_layout(self, location: str) -> None:
        workspace = self.cdp.evaluate(
            """
            (() => {
                if (innerWidth <= 672) {
                    scrollTo(0, 0);
                }
                const shell = document.querySelector(
                    "[data-estab-message-workspace]"
                );
                const sidebar = document.querySelector(
                    'iframe[name="vorgaben"]'
                );
                const content = document.querySelector(
                    'iframe[name="mainframe"]'
                );
                if (!shell || !sidebar || !content) return null;
                const sidebarRect = sidebar.getBoundingClientRect();
                const contentRect = content.getBoundingClientRect();
                return {
                    frameNames: Array.from(
                        document.querySelectorAll("iframe[name]")
                    ).map(frame => frame.getAttribute("name")),
                    sidebar: {
                        left: sidebarRect.left,
                        right: sidebarRect.right,
                        top: sidebarRect.top,
                        bottom: sidebarRect.bottom,
                        width: sidebarRect.width
                    },
                    content: {
                        left: contentRect.left,
                        right: contentRect.right,
                        top: contentRect.top,
                        bottom: contentRect.bottom,
                        width: contentRect.width
                    },
                    innerWidth,
                    innerHeight,
                    clientWidth: document.documentElement.clientWidth,
                    clientHeight: document.documentElement.clientHeight,
                    scrollHeight: document.scrollingElement.scrollHeight
                };
            })()
            """
        )
        self._truth(isinstance(workspace, dict), f"Arbeitsbereich fehlt: {location}.")
        self._equal(
            workspace.get("frameNames"),
            ["vorgaben", "mainframe"],
            f"Frame-Struktur in {location}",
        )
        sidebar_frame = workspace.get("sidebar")
        content_frame = workspace.get("content")
        inner_width = float(workspace.get("innerWidth", 0))
        inner_height = float(workspace.get("innerHeight", 0))
        # A classic Linux scrollbar reduces the usable layout viewport while
        # Chrome deliberately keeps innerWidth at the emulated device width.
        client_width = float(workspace.get("clientWidth", 0))
        client_height = float(workspace.get("clientHeight", 0))
        self._truth(
            0 < client_width <= inner_width and client_height > 0,
            f"Nachrichten-Layout-Viewport ist in {location} ungültig: "
            f"{workspace!r}",
        )
        if inner_width <= 672:
            self._truth(
                isinstance(sidebar_frame, dict)
                and isinstance(content_frame, dict)
                and abs(float(sidebar_frame.get("left", -1))) <= 0.5
                and abs(float(content_frame.get("left", -1))) <= 0.5
                and abs(float(sidebar_frame.get("top", -1))) <= 0.5
                and abs(float(sidebar_frame.get("bottom", -1)) - client_height)
                <= 0.5
                and abs(float(content_frame.get("top", -1)) - client_height)
                <= 0.5
                and abs(
                    float(content_frame.get("bottom", -1))
                    - (2 * client_height)
                )
                <= 0.5
                and abs(float(sidebar_frame.get("width", 0)) - client_width)
                <= 0.5
                and abs(float(content_frame.get("width", 0)) - client_width)
                <= 0.5
                and abs(
                    float(workspace.get("scrollHeight", 0))
                    - (2 * client_height)
                )
                <= 1,
                f"Sidebar und Inhalt bilden in {location} keine zwei vollen "
                f"Viewport-Zeilen: {workspace!r}",
            )
        else:
            self._truth(
                isinstance(sidebar_frame, dict)
                and isinstance(content_frame, dict)
                and abs(float(sidebar_frame.get("top", -1))) <= 0.5
                and abs(
                    float(sidebar_frame.get("bottom", -1)) - client_height
                )
                <= 0.5
                and abs(float(content_frame.get("top", -1))) <= 0.5
                and abs(float(content_frame.get("bottom", -1)) - client_height)
                <= 0.5
                and float(sidebar_frame.get("width", 0)) >= 260
                and float(sidebar_frame.get("right", 0))
                <= float(content_frame.get("left", -1)) + 0.5
                and float(content_frame.get("width", 0)) >= 300,
                f"Sidebar nutzt in {location} nicht die volle linke Höhe.",
            )

        state = self.cdp.evaluate(
            _frame_expression(
                "vorgaben",
                """
                const root = doc.scrollingElement;
                const status = doc.querySelector("[data-estab-sidebar-status]");
                const bar = doc.querySelector("aside[data-estab-session-bar]");
                const topline = bar && bar.querySelector(
                    ".estab-session-topline"
                );
                const navigation = bar && (
                    bar.querySelector("[data-estab-navigation]") ||
                    doc.querySelector(
                        "[data-estab-sidebar-root] > [data-estab-navigation]"
                    )
                );
                const workflow = doc.querySelector(
                    "[data-estab-workflow-menu]"
                );
                const links = navigation
                    ? Array.from(
                        navigation.querySelectorAll("a[data-estab-nav-key]")
                    )
                    : [];
                const actions = workflow
                    ? Array.from(
                        workflow.querySelectorAll(
                            "button[data-estab-workflow-key]"
                        )
                    )
                    : [];
                const rect = element => {
                    if (!element) return null;
                    const value = element.getBoundingClientRect();
                    return {
                        left: value.left,
                        right: value.right,
                        top: value.top,
                        bottom: value.bottom,
                        width: value.width,
                        height: value.height
                    };
                };
                const nestedScrollers = Array.from(
                    doc.querySelectorAll("body *")
                ).filter(element => {
                    const style = target.getComputedStyle(element);
                    return /(auto|scroll)/.test(style.overflowY) &&
                        element.scrollHeight > element.clientHeight + 1;
                });
                const currentPresence = status && status.querySelector(
                    '[data-estab-presence-state="current"]'
                );
                const queue = status && status.querySelector(
                    "[data-estab-queue-count]"
                );
                const queueContainer = status && status.querySelector(
                    "[data-estab-queue-state]"
                );
                const time = status && status.querySelector("time[datetime]");
                const soundToggle = doc.querySelector(
                    "[data-estab-sound-toggle]"
                );
                const soundFeedback = doc.querySelector(
                    "[data-estab-sound-feedback]"
                );
                const sidebarAudio = doc.querySelector(
                    "audio[data-estab-sidebar-audio]"
                );
                return {
                    status: rect(status),
                    bar: rect(bar),
                    topline: rect(topline),
                    navigation: rect(navigation),
                    workflow: rect(workflow),
                    navigationMode: navigation && navigation.getAttribute(
                        "data-estab-navigation-mode"
                    ),
                    hasDisclosure: Boolean(
                        navigation && navigation.querySelector("details,summary")
                    ),
                    linkCount: links.length,
                    linksFit: links.every(link => {
                        const value = rect(link);
                        const style = target.getComputedStyle(link);
                        return value && value.width >= 44 &&
                            value.height >= 44 &&
                            style.display !== "none" &&
                            style.visibility !== "hidden";
                    }),
                    navigationScrollFree: Boolean(
                        navigation &&
                        navigation.scrollWidth <= navigation.clientWidth + 1 &&
                        navigation.scrollHeight <= navigation.clientHeight + 1
                    ),
                    nestedScrollers: nestedScrollers.map(element =>
                        element.className || element.tagName
                    ),
                    documentWidthFits:
                        root.scrollWidth <= root.clientWidth + 1,
                    documentCanReachEnd:
                        root.scrollHeight >= root.clientHeight,
                    actionKeys: actions.map(button =>
                        button.getAttribute("data-estab-workflow-key")
                    ),
                    actionsFit: actions.every(button => {
                        const value = rect(button);
                        return value && value.width >= 44 && value.height >= 44;
                    }),
                    currentFunction: currentPresence &&
                        currentPresence.getAttribute(
                            "data-estab-presence-function"
                        ),
                    queueText: queue && queue.textContent.trim(),
                    queueState: queueContainer && queueContainer.getAttribute(
                        "data-estab-queue-state"
                    ),
                    queueWarningVisible: Boolean(
                        queueContainer
                        && queueContainer.getAttribute(
                            "data-estab-queue-state"
                        ) === "has-work"
                        && target.getComputedStyle(
                            queueContainer
                        ).backgroundColor !== "rgba(0, 0, 0, 0)"
                        && target.getComputedStyle(
                            queueContainer
                        ).borderTopColor !== "rgba(0, 0, 0, 0)"
                    ),
                    timeValue: time && time.getAttribute("datetime"),
                    onlineCount: status && Number(
                        status.querySelector("[data-estab-online-count]")
                            ?.getAttribute("data-estab-online-count")
                    ),
                    soundToggle: rect(soundToggle),
                    soundToggleVisible: Boolean(
                        soundToggle
                        && target.getComputedStyle(soundToggle).display !== "none"
                        && target.getComputedStyle(soundToggle).visibility !== "hidden"
                    ),
                    soundTogglePressed: soundToggle &&
                        soundToggle.getAttribute("aria-pressed"),
                    soundFeedback: rect(soundFeedback),
                    soundFeedbackVisible: Boolean(
                        soundFeedback
                        && target.getComputedStyle(soundFeedback).display !== "none"
                        && target.getComputedStyle(soundFeedback).visibility !== "hidden"
                    ),
                    soundFeedbackText: soundFeedback &&
                        soundFeedback.textContent.trim(),
                    soundFeedbackLive: soundFeedback &&
                        soundFeedback.getAttribute("aria-live"),
                    audioCount: doc.querySelectorAll(
                        "audio[data-estab-sidebar-audio]"
                    ).length,
                    audioSource: sidebarAudio &&
                        sidebarAudio.getAttribute("src"),
                    refreshAvailable:
                        typeof target.estabRefreshSidebarStatus === "function"
                };
                """,
            )
        )
        self._truth(isinstance(state, dict), f"Sidebar-Inhalt fehlt: {location}.")
        for key in ("status", "bar", "topline", "navigation", "workflow"):
            self._truth(
                isinstance(state.get(key), dict),
                f"{key} fehlt in {location}.",
            )
        status_rect = state["status"]
        bar_rect = state["bar"]
        topline_rect = state["topline"]
        navigation_rect = state["navigation"]
        workflow_rect = state["workflow"]
        self._truth(
            status_rect["bottom"] <= bar_rect["top"] + 0.5
            and bar_rect["bottom"] <= workflow_rect["top"] + 0.5
            and workflow_rect["bottom"] <= navigation_rect["top"] + 0.5
            and topline_rect["bottom"] <= bar_rect["bottom"] + 0.5,
            f"Status, Benutzer, Aktionen und Bereiche sind in {location} "
            "nicht überschneidungsfrei angeordnet.",
        )
        self._equal(
            state.get("navigationMode"),
            "sidebar",
            f"Navigationsmodus in {location}",
        )
        self._truth(
            not state.get("hasDisclosure"),
            f"Bereichsnavigation ist in {location} noch eingeklappt.",
        )
        self._equal(
            state.get("linkCount"),
            self._authenticated_navigation_link_count(),
            f"Bereichslinks in {location}",
        )
        self._truth(
            state.get("linksFit") and state.get("navigationScrollFree"),
            f"Bereichslinks besitzen in {location} eigene Scroll- oder zu kleine Flächen.",
        )
        self._equal(
            state.get("nestedScrollers"),
            [],
            f"Verschachtelte vertikale Scrollflächen in {location}",
        )
        self._truth(
            state.get("documentWidthFits") and state.get("documentCanReachEnd"),
            f"Gesamte Sidebar passt horizontal oder scrollt nicht vollständig in {location}.",
        )
        self._equal(
            state.get("actionKeys"),
            [
                "stab_schreiben",
                "stab_lesen",
                "m2_benutzer",
            ],
            f"Arbeitsaktionen in {location}",
        )
        self._truth(state.get("actionsFit"), f"Zu kleine Aktion in {location}.")
        self._equal(
            state.get("currentFunction"),
            self.config.login_function,
            f"Eigene Online-Funktion in {location}",
        )
        queue_text = state.get("queueText")
        self._truth(
            isinstance(queue_text, str) and queue_text.isdigit(),
            f"Arbeitszähler ist in {location} nicht numerisch.",
        )
        queue_count = int(queue_text)
        self._equal(
            state.get("queueState"),
            "has-work" if queue_count > 0 else "empty",
            f"Persistenter Warteschlangenstatus in {location}",
        )
        if queue_count > 0:
            self._truth(
                state.get("queueWarningVisible") is True,
                f"Offene Meldungen sind in {location} nicht hervorgehoben.",
            )
        time_value = state.get("timeValue")
        self._truth(
            isinstance(time_value, str) and "T" in time_value,
            f"Serverzeit fehlt in {location}.",
        )
        self._truth(
            isinstance(state.get("onlineCount"), (int, float))
            and state["onlineCount"] >= 1,
            f"Online-Übersicht ist in {location} unvollständig.",
        )
        self._truth(
            state.get("refreshAvailable"),
            f"Schonende Statusaktualisierung fehlt in {location}.",
        )
        sound_toggle = state.get("soundToggle")
        sound_feedback = state.get("soundFeedback")
        self._truth(
            isinstance(sound_toggle, dict)
            and float(sound_toggle.get("width", 0)) >= 44
            and float(sound_toggle.get("height", 0)) >= 44
            and state.get("soundToggleVisible") is True,
            f"Sound-Schalter ist in {location} nicht sichtbar oder kleiner als 44 px.",
        )
        self._equal(
            state.get("soundTogglePressed"),
            "false",
            f"Initialer Sound-Zustand in {location}",
        )
        self._truth(
            isinstance(sound_feedback, dict)
            and float(sound_feedback.get("width", 0)) > 0
            and float(sound_feedback.get("height", 0)) > 0
            and state.get("soundFeedbackVisible") is True
            and isinstance(state.get("soundFeedbackText"), str)
            and bool(state["soundFeedbackText"])
            and state.get("soundFeedbackLive") == "polite",
            f"Sichtbarer Sound-Status fehlt in {location}.",
        )
        self._equal(
            state.get("audioCount"),
            1,
            f"Anzahl langlebiger Audio-Elemente in {location}",
        )
        audio_source = state.get("audioSource")
        self._truth(
            isinstance(audio_source, str) and audio_source.lower().endswith(".wav"),
            f"PCM-WAV-Quelle fehlt in {location}.",
        )

        audio_contract = self.cdp.evaluate(
            _frame_expression(
                "vorgaben",
                """
                return (async () => {
                    const audio = doc.querySelector(
                        "audio[data-estab-sidebar-audio]"
                    );
                    if (!audio) return null;
                    const rawSource = audio.getAttribute("src");
                    if (!rawSource) return null;
                    const source = new target.URL(rawSource, target.location.href);
                    if (source.origin !== target.location.origin ||
                        !source.pathname.toLowerCase().endsWith(".wav")) {
                        return null;
                    }
                    const response = await target.fetch(source.href, {
                        credentials: "same-origin",
                        cache: "no-store"
                    });
                    if (!response.ok) return null;
                    const bytes = await response.arrayBuffer();
                    const view = new target.DataView(bytes);
                    const ascii = (offset, length) => {
                        let value = "";
                        for (let index = 0; index < length; index += 1) {
                            value += String.fromCharCode(
                                view.getUint8(offset + index)
                            );
                        }
                        return value;
                    };
                    if (view.byteLength < 20 ||
                        ascii(0, 4) !== "RIFF" ||
                        ascii(8, 4) !== "WAVE") {
                        return null;
                    }
                    let offset = 12;
                    while (offset + 8 <= view.byteLength) {
                        const chunk = ascii(offset, 4);
                        const size = view.getUint32(offset + 4, true);
                        const dataOffset = offset + 8;
                        if (chunk === "fmt " && size >= 16 &&
                            dataOffset + size <= view.byteLength) {
                            return {
                                sameOrigin: true,
                                path: source.pathname,
                                format: view.getUint16(dataOffset, true)
                            };
                        }
                        offset = dataOffset + size + (size % 2);
                    }
                    return null;
                })();
                """,
            )
        )
        self._truth(
            isinstance(audio_contract, dict)
            and audio_contract.get("sameOrigin") is True
            and str(audio_contract.get("path", "")).lower().endswith(".wav")
            and audio_contract.get("format") == 1,
            f"Audioquelle ist in {location} kein gleich-originiges PCM-WAV.",
        )

        refresh = self.cdp.evaluate(
            _frame_expression(
                "vorgaben",
                """
                return (async () => {
                    const root = doc.scrollingElement;
                    const action = doc.querySelector(
                        'button[data-estab-workflow-key="m2_benutzer"]'
                    );
                    const audio = doc.querySelector(
                        "audio[data-estab-sidebar-audio]"
                    );
                    if (!root || !action ||
                        !audio ||
                        typeof target.estabRefreshSidebarStatus !== "function") {
                        return null;
                    }
                    root.scrollTop = Math.max(
                        0,
                        root.scrollHeight - root.clientHeight
                    );
                    action.focus({preventScroll: true});
                    const before = root.scrollTop;
                    const refreshed = await target.estabRefreshSidebarStatus();
                    await new Promise(resolve =>
                        target.requestAnimationFrame(resolve)
                    );
                    return {
                        refreshed,
                        before,
                        after: root.scrollTop,
                        focusPreserved: doc.activeElement === action,
                        statusCount: doc.querySelectorAll(
                            "[data-estab-sidebar-status]"
                        ).length,
                        soundToggleCount: doc.querySelectorAll(
                            "[data-estab-sound-toggle]"
                        ).length,
                        soundFeedbackCount: doc.querySelectorAll(
                            "[data-estab-sound-feedback]"
                        ).length,
                        audioPreserved: audio === doc.querySelector(
                            "audio[data-estab-sidebar-audio]"
                        )
                    };
                })();
                """,
            )
        )
        self._truth(
            isinstance(refresh, dict)
            and refresh.get("refreshed") is True
            and refresh.get("focusPreserved") is True
            and refresh.get("statusCount") == 1
            and refresh.get("soundToggleCount") == 1
            and refresh.get("soundFeedbackCount") == 1
            and refresh.get("audioPreserved") is True
            and abs(
                float(refresh.get("before", -10))
                - float(refresh.get("after", 10))
            ) <= 1,
            f"Statusaktualisierung verändert Fokus oder Scrollposition in {location}.",
        )

        sound_focus_refresh = self.cdp.evaluate(
            _frame_expression(
                "vorgaben",
                """
                return (async () => {
                    const root = doc.scrollingElement;
                    const button = doc.querySelector(
                        "[data-estab-sound-toggle]"
                    );
                    if (!root || !button ||
                        typeof target.estabRefreshSidebarStatus !== "function") {
                        return null;
                    }
                    button.focus({preventScroll: true});
                    const before = root.scrollTop;
                    const refreshed = await target.estabRefreshSidebarStatus();
                    await new Promise(resolve =>
                        target.requestAnimationFrame(resolve)
                    );
                    const restored = doc.querySelector(
                        "[data-estab-sound-toggle]"
                    );
                    return {
                        refreshed,
                        identityPreserved: restored === button,
                        focusPreserved:
                            Boolean(restored && doc.activeElement === restored),
                        before,
                        after: root.scrollTop
                    };
                })();
                """,
            )
        )
        self._truth(
            isinstance(sound_focus_refresh, dict)
            and sound_focus_refresh.get("refreshed") is True
            and sound_focus_refresh.get("identityPreserved") is True
            and sound_focus_refresh.get("focusPreserved") is True
            and abs(
                float(sound_focus_refresh.get("before", -10))
                - float(sound_focus_refresh.get("after", 10))
            )
            <= 1,
            f"Statusaktualisierung verliert in {location} den Fokus des "
            "Hinweiston-Schalters.",
        )

        if inner_width == 390 and inner_height == 844:
            self._assert_sidebar_stale_recovery(location)
            self._assert_sidebar_sound_toggle(location)
            self._assert_mobile_message_navigation(location)

    def _assert_sidebar_stale_recovery(self, location: str) -> None:
        result = self.cdp.evaluate(
            _frame_expression(
                "vorgaben",
                """
                return (async () => {
                    const action = doc.querySelector(
                        'button[data-estab-workflow-key="m2_benutzer"]'
                    );
                    const originalFetch = target.fetch;
                    if (!action ||
                        typeof target.estabRefreshSidebarStatus !== "function") {
                        return null;
                    }
                    action.focus({preventScroll: true});
                    try {
                        target.fetch = function () {
                            return target.Promise.resolve(
                                new target.Response("unavailable", {status: 503})
                            );
                        };
                        const failed =
                            await target.estabRefreshSidebarStatus();
                        const staleStatus = doc.querySelector(
                            "[data-estab-sidebar-status]"
                        );
                        const staleFreshness = doc.querySelector(
                            "[data-estab-sidebar-freshness]"
                        );
                        const stale = {
                            failed,
                            state: staleFreshness &&
                                staleFreshness.getAttribute(
                                    "data-estab-status-freshness"
                                ),
                            text: staleFreshness &&
                                staleFreshness.textContent.trim(),
                            classed: Boolean(
                                staleStatus &&
                                staleStatus.classList.contains(
                                    "estab-sidebar-status-stale"
                                )
                            ),
                            focusPreserved: doc.activeElement === action,
                            navigationCount: doc.querySelectorAll(
                                'a[data-estab-nav-key]'
                            ).length
                        };
                        target.fetch = originalFetch;
                        const recovered =
                            await target.estabRefreshSidebarStatus();
                        const currentStatus = doc.querySelector(
                            "[data-estab-sidebar-status]"
                        );
                        const currentFreshness = doc.querySelector(
                            "[data-estab-sidebar-freshness]"
                        );
                        return {
                            stale,
                            recovered,
                            currentState: currentFreshness &&
                                currentFreshness.getAttribute(
                                    "data-estab-status-freshness"
                                ),
                            currentText: currentFreshness &&
                                currentFreshness.textContent.trim(),
                            staleClassCleared: Boolean(
                                currentStatus &&
                                !currentStatus.classList.contains(
                                    "estab-sidebar-status-stale"
                                )
                            )
                        };
                    } finally {
                        target.fetch = originalFetch;
                    }
                })();
                """,
            )
        )
        stale = result.get("stale", {}) if isinstance(result, dict) else {}
        self._truth(
            isinstance(result, dict)
            and stale.get("failed") is False
            and stale.get("state") == "stale"
            and "Status nicht aktuell" in str(stale.get("text", ""))
            and "letzter Abruf" in str(stale.get("text", ""))
            and stale.get("classed") is True
            and stale.get("focusPreserved") is True
            and stale.get("navigationCount")
                == self._authenticated_navigation_link_count()
            and result.get("recovered") is True
            and result.get("currentState") == "current"
            and result.get("currentText") == "Status wieder aktuell"
            and result.get("staleClassCleared") is True,
            f"Fehlgeschlagener Statusabruf ist in {location} nicht sichtbar "
            "oder erholt sich nicht.",
        )

    def _assert_sidebar_sound_toggle(self, location: str) -> None:
        initial = self.cdp.evaluate(
            _frame_expression(
                "vorgaben",
                """
                const button = doc.querySelector("[data-estab-sound-toggle]");
                const feedback = doc.querySelector(
                    "[data-estab-sound-feedback]"
                );
                const audio = doc.querySelector(
                    "audio[data-estab-sidebar-audio]"
                );
                if (!button || !feedback || !audio) return null;
                audio.__estabBrowserAudioIdentity = "persistent";
                audio.__estabBrowserPlayCalls = 0;
                audio.play = function () {
                    this.__estabBrowserPlayCalls += 1;
                    const error = new target.Error("gesture required");
                    error.name = "NotAllowedError";
                    return target.Promise.reject(error);
                };
                return {
                    pressed: button.getAttribute("aria-pressed"),
                    feedback: feedback.textContent.trim()
                };
                """,
            )
        )
        self._truth(
            isinstance(initial, dict)
            and initial.get("pressed") == "false"
            and bool(initial.get("feedback")),
            f"Sound-Schalter besitzt in {location} keinen deaktivierten Ausgangszustand.",
        )

        self.cdp.click(
            "vorgaben",
            "[data-estab-sound-toggle]",
            f"blockierter Sound-Schalter in {location}",
        )
        self.cdp.wait_for(
            _frame_expression(
                "vorgaben",
                """
                const button = doc.querySelector("[data-estab-sound-toggle]");
                const feedback = doc.querySelector(
                    "[data-estab-sound-feedback]"
                );
                const audio = doc.querySelector(
                    "audio[data-estab-sidebar-audio]"
                );
                if (!button || !feedback || !audio ||
                    button.getAttribute("aria-pressed") !== "true" ||
                    button.getAttribute("data-estab-sound-state") !== "blocked" ||
                    !feedback.textContent.includes("blockiert") ||
                    target.localStorage.getItem(
                        "estab.sidebar.sounds"
                    ) !== "on" ||
                    audio.__estabBrowserAudioIdentity !== "persistent" ||
                    audio.__estabBrowserPlayCalls < 1) {
                    return false;
                }
                return {
                    feedback: feedback.textContent.trim(),
                    playCalls: audio.__estabBrowserPlayCalls
                };
                """,
            ),
            f"blockierte Audiowiedergabe wird in {location} nicht sichtbar",
        )

        self.cdp.evaluate(
            """
            (() => {
                const sidebar = document.querySelector(
                    'iframe[name="vorgaben"]'
                );
                if (!sidebar || !sidebar.contentWindow) return false;
                sidebar.contentWindow.location.reload();
                return true;
            })()
            """
        )
        reloaded = self.cdp.wait_for(
            _frame_expression(
                "vorgaben",
                """
                const button = doc.querySelector("[data-estab-sound-toggle]");
                const feedback = doc.querySelector(
                    "[data-estab-sound-feedback]"
                );
                if (!button || !feedback ||
                    doc.readyState !== "complete" ||
                    typeof target.estabSidebarSoundState !== "function") {
                    return false;
                }
                const state = target.estabSidebarSoundState();
                if (button.getAttribute("aria-pressed") !== "true" ||
                    button.getAttribute("data-estab-sound-state") !== "blocked" ||
                    state.state !== "blocked" ||
                    state.enabled !== true ||
                    !feedback.textContent.includes("erneut freigeben")) {
                    return false;
                }
                return state;
                """,
            ),
            f"Reload meldet blockierte Töne in {location} fälschlich als aktiv",
        )
        self._equal(
            reloaded.get("state"),
            "blocked",
            f"Sound-Zustand nach Reload in {location}",
        )
        self.cdp.click(
            "vorgaben",
            "[data-estab-sound-toggle]",
            f"blockierten Sound-Schalter in {location} ausschalten",
        )
        self.cdp.wait_for(
            _frame_expression(
                "vorgaben",
                """
                const button = doc.querySelector("[data-estab-sound-toggle]");
                const state = target.estabSidebarSoundState();
                return Boolean(
                    button &&
                    button.getAttribute("aria-pressed") === "false" &&
                    state.enabled === false &&
                    state.state === "inactive" &&
                    target.localStorage.getItem(
                        "estab.sidebar.sounds"
                    ) === "off"
                );
                """,
            ),
            f"blockierter Sound-Schalter lässt sich in {location} nicht ausschalten",
        )

        prepared = self.cdp.evaluate(
            _frame_expression(
                "vorgaben",
                """
                const audio = doc.querySelector(
                    "audio[data-estab-sidebar-audio]"
                );
                if (!audio) return false;
                audio.__estabBrowserAudioIdentity = "persistent";
                audio.__estabBrowserPlayCalls = 0;
                audio.play = function () {
                    this.__estabBrowserPlayCalls += 1;
                    return target.Promise.resolve();
                };
                return true;
                """,
            )
        )
        self._truth(prepared, f"Audiofreigabe in {location} nicht vorbereitet.")
        self.cdp.click(
            "vorgaben",
            "[data-estab-sound-toggle]",
            f"Sound-Schalter zum Freigeben in {location}",
        )
        enabled = self.cdp.wait_for(
            _frame_expression(
                "vorgaben",
                """
                const button = doc.querySelector("[data-estab-sound-toggle]");
                const feedback = doc.querySelector(
                    "[data-estab-sound-feedback]"
                );
                const audio = doc.querySelector(
                    "audio[data-estab-sidebar-audio]"
                );
                if (!button || !feedback || !audio ||
                    button.getAttribute("aria-pressed") !== "true" ||
                    button.getAttribute("data-estab-sound-state") !== "ready" ||
                    !feedback.textContent.includes("aktiviert") ||
                    audio.__estabBrowserAudioIdentity !== "persistent" ||
                    audio.__estabBrowserPlayCalls < 1) {
                    return false;
                }
                return {
                    feedback: feedback.textContent.trim(),
                    playCalls: audio.__estabBrowserPlayCalls
                };
                """,
            ),
            f"Sound-Schalter wurde in {location} nicht freigegeben",
        )

        automatic = self.cdp.evaluate(
            _frame_expression(
                "vorgaben",
                """
                return (async () => {
                    const audio = doc.querySelector(
                        "audio[data-estab-sidebar-audio]"
                    );
                    if (!audio ||
                        typeof target.estabRefreshSidebarStatus !== "function") {
                        return null;
                    }
                    const before = audio.__estabBrowserPlayCalls;
                    const originalFetch = target.fetch.bind(target);
                    let injected = false;
                    target.fetch = async function (...parameters) {
                        const response = await originalFetch(...parameters);
                        const body = await response.text();
                        target.fetch = originalFetch;
                        injected = true;
                        return new target.Response(
                            body.replace(
                                'data-estab-notify="0"',
                                'data-estab-notify="1"'
                            ),
                            {
                                status: response.status,
                                statusText: response.statusText,
                                headers: response.headers
                            }
                        );
                    };
                    const refreshed =
                        await target.estabRefreshSidebarStatus();
                    await new Promise(resolve =>
                        target.requestAnimationFrame(resolve)
                    );
                    const feedback = doc.querySelector(
                        "[data-estab-sound-feedback]"
                    );
                    return {
                        refreshed,
                        injected,
                        before,
                        after: audio.__estabBrowserPlayCalls,
                        feedback: feedback && feedback.textContent.trim()
                    };
                })();
                """,
            )
        )
        self._truth(
            isinstance(automatic, dict)
            and automatic.get("refreshed") is True
            and automatic.get("injected") is True
            and int(automatic.get("after", 0))
            == int(automatic.get("before", -1)) + 1
            and "abgespielt" in str(automatic.get("feedback", "")),
            f"Automatischer Warteschlangenton läuft in {location} nicht.",
        )

        sound_races = self.cdp.evaluate(
            _frame_expression(
                "vorgaben",
                """
                return (async () => {
                    const key = "estab.sidebar.sounds";
                    const audio = doc.querySelector(
                        "audio[data-estab-sidebar-audio]"
                    );
                    const button = doc.querySelector(
                        "[data-estab-sound-toggle]"
                    );
                    if (!audio || !button) return null;
                    const settle = async () => {
                        await target.Promise.resolve();
                        await new target.Promise(resolve =>
                            target.setTimeout(resolve, 0)
                        );
                    };
                    const storage = (oldValue, newValue) => {
                        target.localStorage.setItem(key, newValue);
                        target.dispatchEvent(
                            new target.StorageEvent("storage", {
                                key,
                                oldValue,
                                newValue
                            })
                        );
                    };
                    audio.__estabBrowserPauseCalls = 0;
                    audio.pause = function () {
                        this.__estabBrowserPauseCalls += 1;
                    };

                    button.click();
                    let resolveClick;
                    audio.play = function () {
                        this.__estabBrowserPlayCalls += 1;
                        return new target.Promise(resolve => {
                            resolveClick = resolve;
                        });
                    };
                    button.click();
                    const clickChecking = target.estabSidebarSoundState();
                    button.click();
                    const clickCancelled = target.estabSidebarSoundState();
                    resolveClick();
                    await settle();
                    const clickSettled = target.estabSidebarSoundState();

                    let resolveStorage;
                    audio.play = function () {
                        this.__estabBrowserPlayCalls += 1;
                        return new target.Promise(resolve => {
                            resolveStorage = resolve;
                        });
                    };
                    button.click();
                    const storageChecking = target.estabSidebarSoundState();
                    storage("on", "off");
                    const storageCancelled = target.estabSidebarSoundState();
                    resolveStorage();
                    await settle();
                    const storageSettled = target.estabSidebarSoundState();

                    storage("off", "on");
                    const on = target.estabSidebarSoundState();
                    const onPressed = button.getAttribute("aria-pressed");
                    const onButtonState =
                        button.getAttribute("data-estab-sound-state");
                    button.click();
                    const optedOut = target.estabSidebarSoundState();
                    audio.play = function () {
                        this.__estabBrowserPlayCalls += 1;
                        return target.Promise.resolve();
                    };
                    return {
                        clickChecking,
                        clickCancelled,
                        clickSettled,
                        storageChecking,
                        storageCancelled,
                        storageSettled,
                        pauseCalls: audio.__estabBrowserPauseCalls,
                        on,
                        onPressed,
                        onButtonState,
                        optedOut
                    };
                })();
                """,
            )
        )
        self._truth(
            isinstance(sound_races, dict)
            and sound_races.get("clickChecking", {}).get("state") == "checking"
            and sound_races.get("clickCancelled", {}).get("enabled") is False
            and sound_races.get("clickSettled", {}).get("enabled") is False
            and sound_races.get("clickSettled", {}).get("state") == "inactive"
            and sound_races.get("storageChecking", {}).get("state")
            == "checking"
            and sound_races.get("storageCancelled", {}).get("enabled") is False
            and sound_races.get("storageSettled", {}).get("enabled") is False
            and sound_races.get("storageSettled", {}).get("state") == "inactive"
            and int(sound_races.get("pauseCalls", 0)) >= 2
            and sound_races.get("on", {}).get("enabled") is True
            and sound_races.get("on", {}).get("state") == "blocked"
            and sound_races.get("onPressed") == "true"
            and sound_races.get("onButtonState") == "blocked"
            and sound_races.get("optedOut", {}).get("enabled") is False,
            f"Abbruch oder browserweite Sound-Synchronisation läuft in "
            f"{location} nicht racesicher.",
        )

        self.cdp.click(
            "vorgaben",
            "[data-estab-sound-toggle]",
            f"erneut freizugebender Sound-Schalter in {location}",
        )
        ready_again = self.cdp.wait_for(
            _frame_expression(
                "vorgaben",
                """
                const button = doc.querySelector("[data-estab-sound-toggle]");
                const audio = doc.querySelector(
                    "audio[data-estab-sidebar-audio]"
                );
                if (!button || !audio ||
                    button.getAttribute("data-estab-sound-state") !== "ready") {
                    return false;
                }
                return {playCalls: audio.__estabBrowserPlayCalls};
                """,
            ),
            f"Sound-Schalter wurde in {location} nicht erneut freigegeben",
        )
        self.cdp.click(
            "vorgaben",
            "[data-estab-sound-toggle]",
            f"Sound-Schalter zum Deaktivieren in {location}",
        )
        disabled = self.cdp.wait_for(
            _frame_expression(
                "vorgaben",
                f"""
                const button = doc.querySelector("[data-estab-sound-toggle]");
                const feedback = doc.querySelector(
                    "[data-estab-sound-feedback]"
                );
                const audio = doc.querySelector(
                    "audio[data-estab-sidebar-audio]"
                );
                if (!button || !feedback || !audio ||
                    button.getAttribute("aria-pressed") !== "false" ||
                    !feedback.textContent.trim() ||
                    feedback.textContent.trim() === {
                        json.dumps(str(enabled.get("feedback", "")))
                    } ||
                    audio.__estabBrowserAudioIdentity !== "persistent") {{
                    return false;
                }}
                return {{
                    playCalls: audio.__estabBrowserPlayCalls
                }};
                """,
            ),
            f"Sound-Schalter wurde in {location} nicht deaktiviert",
        )
        self._equal(
            disabled.get("playCalls"),
            ready_again.get("playCalls"),
            f"Deaktivieren des Sound-Schalters startet in {location} Audio",
        )

    def _assert_bos_workspace_layout(self, location: str) -> None:
        workspace = self.cdp.evaluate(
            """
            (() => {
                if (innerWidth <= 672) {
                    scrollTo(0, 0);
                }
                const shell = document.querySelector(
                    "[data-estab-bos-workspace]"
                );
                const sidebar = document.querySelector(
                    'iframe[name="status"]'
                );
                const content = document.querySelector(
                    'iframe[name="mainframe"]'
                );
                if (!shell || !sidebar || !content) return null;
                const sidebarRect = sidebar.getBoundingClientRect();
                const contentRect = content.getBoundingClientRect();
                return {
                    frameNames: Array.from(
                        document.querySelectorAll("iframe[name]")
                    ).map(frame => frame.getAttribute("name")),
                    sidebar: {
                        left: sidebarRect.left,
                        right: sidebarRect.right,
                        top: sidebarRect.top,
                        bottom: sidebarRect.bottom,
                        width: sidebarRect.width
                    },
                    content: {
                        left: contentRect.left,
                        right: contentRect.right,
                        top: contentRect.top,
                        bottom: contentRect.bottom,
                        width: contentRect.width
                    },
                    innerWidth,
                    innerHeight,
                    clientWidth: document.documentElement.clientWidth,
                    clientHeight: document.documentElement.clientHeight,
                    scrollHeight: document.scrollingElement.scrollHeight,
                    scrollWidth: document.scrollingElement.scrollWidth
                };
            })()
            """
        )
        self._truth(
            isinstance(workspace, dict),
            f"Responsive BOS-Arbeitsbereich fehlt: {location}.",
        )
        self._equal(
            workspace.get("frameNames"),
            ["status", "mainframe"],
            f"BOS-Frame-Struktur in {location}",
        )
        sidebar_frame = workspace.get("sidebar")
        content_frame = workspace.get("content")
        inner_width = float(workspace.get("innerWidth", 0))
        # A classic Linux scrollbar reduces the usable layout viewport while
        # Chrome deliberately keeps innerWidth at the emulated device width.
        client_width = float(workspace.get("clientWidth", 0))
        client_height = float(workspace.get("clientHeight", 0))
        self._truth(
            0 < client_width <= inner_width and client_height > 0,
            f"BOS-Layout-Viewport ist in {location} ungültig: {workspace!r}",
        )
        self._truth(
            float(workspace.get("scrollWidth", 0)) <= client_width + 1,
            f"BOS-Arbeitsbereich erzeugt in {location} horizontales Scrolling.",
        )
        if inner_width <= 672:
            self._truth(
                isinstance(sidebar_frame, dict)
                and isinstance(content_frame, dict)
                and abs(float(sidebar_frame.get("left", -1))) <= 0.5
                and abs(float(content_frame.get("left", -1))) <= 0.5
                and abs(float(sidebar_frame.get("top", -1))) <= 0.5
                and abs(float(sidebar_frame.get("bottom", -1)) - client_height)
                <= 0.5
                and abs(float(content_frame.get("top", -1)) - client_height)
                <= 0.5
                and abs(
                    float(content_frame.get("bottom", -1))
                    - (2 * client_height)
                )
                <= 0.5
                and abs(float(sidebar_frame.get("width", 0)) - client_width)
                <= 0.5
                and abs(float(content_frame.get("width", 0)) - client_width)
                <= 0.5
                and abs(
                    float(workspace.get("scrollHeight", 0))
                    - (2 * client_height)
                )
                <= 1,
                f"BOS-Navigation und Inhalt bilden in {location} keine "
                f"zwei vollen mobilen Ansichten: {workspace!r}",
            )
        else:
            self._truth(
                isinstance(sidebar_frame, dict)
                and isinstance(content_frame, dict)
                and abs(float(sidebar_frame.get("top", -1))) <= 0.5
                and abs(
                    float(sidebar_frame.get("bottom", -1)) - client_height
                )
                <= 0.5
                and abs(float(content_frame.get("top", -1))) <= 0.5
                and abs(float(content_frame.get("bottom", -1)) - client_height)
                <= 0.5
                and float(sidebar_frame.get("width", 0)) >= 260
                and float(sidebar_frame.get("right", 0))
                <= float(content_frame.get("left", -1)) + 0.5
                and float(content_frame.get("width", 0)) >= 300,
                f"BOS-Sidebar nutzt in {location} nicht die volle linke Höhe.",
            )

        sidebar_state = self.cdp.evaluate(
            _frame_expression(
                "status",
                """
                const root = doc.scrollingElement;
                const bar = doc.querySelector(
                    "aside[data-estab-session-bar],aside[data-estab-public-bar]"
                );
                const navigation = bar && bar.querySelector(
                    "[data-estab-navigation]"
                );
                const documents = doc.querySelector(
                    "[data-estab-bos-document-navigation]"
                );
                const links = documents
                    ? Array.from(
                        documents.querySelectorAll(
                            "a[data-estab-bos-document-link]"
                        )
                    )
                    : [];
                const barRect = bar && bar.getBoundingClientRect();
                const documentRect =
                    documents && documents.getBoundingClientRect();
                return {
                    navigationMode: navigation && navigation.getAttribute(
                        "data-estab-navigation-mode"
                    ),
                    detailsCount: doc.querySelectorAll("details").length,
                    documentCount: links.length,
                    documentOrder:
                        Boolean(barRect && documentRect) &&
                        documentRect.top >= barRect.bottom - 0.5,
                    linksFit: links.every(link => {
                        const rect = link.getBoundingClientRect();
                        return rect.width >= 44 && rect.height >= 44 &&
                            rect.left >= -0.5 &&
                            rect.right <= root.clientWidth + 0.5;
                    }),
                    scrollWidth: root.scrollWidth,
                    clientWidth: root.clientWidth
                };
                """,
            )
        )
        self._truth(
            isinstance(sidebar_state, dict)
            and sidebar_state.get("navigationMode") == "sidebar"
            and sidebar_state.get("detailsCount") == 0
            and sidebar_state.get("documentCount") == 7
            and sidebar_state.get("documentOrder") is True
            and sidebar_state.get("linksFit") is True
            and int(sidebar_state.get("scrollWidth", 0))
            <= int(sidebar_state.get("clientWidth", 0)) + 1,
            f"BOS-Sidebar ist in {location} nicht vollständig sichtbar: "
            f"{sidebar_state!r}",
        )

        content_state = self.cdp.evaluate(
            _frame_expression(
                "mainframe",
                """
                const root = doc.scrollingElement || doc.documentElement;
                return {
                    enhanced: doc.documentElement.classList.contains(
                        "estab-bos-embedded-document"
                    ) && doc.body.classList.contains(
                        "estab-bos-embedded-content"
                    ),
                    scrollWidth: root.scrollWidth,
                    clientWidth: root.clientWidth
                };
                """,
            )
        )
        self._truth(
            isinstance(content_state, dict)
            and content_state.get("enhanced") is True
            and int(content_state.get("scrollWidth", 0))
            <= int(content_state.get("clientWidth", 0)) + 1,
            f"BOS-Inhalt ist in {location} nicht responsiv: {content_state!r}",
        )

    def _open_bos_document(
        self,
        href: str,
        expected_title: str,
        location: str,
    ) -> None:
        selector = f'a[href="{href}"][target="mainframe"]'
        expected_path = "/stabinfo/" + urllib.parse.unquote(href)
        self.cdp.click(
            "status",
            selector,
            f"BOS-Dokument „{expected_title}“",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                f"""
                return decodeURIComponent(target.location.pathname).endsWith(
                    {json.dumps(expected_path)}
                ) && doc.readyState === "complete" &&
                    Boolean(doc.querySelector(
                        '[data-estab-bos-document-shell]'
                    )) &&
                    doc.documentElement.hasAttribute(
                        'data-estab-bos-layout-ready'
                    );
                """,
            ),
            f"BOS-Dokument „{expected_title}“ wurde nicht vollständig geladen",
        )
        self._assert_bos_document_presentation(expected_title, location)

    def _assert_bos_document_presentation(
        self,
        expected_title: str,
        location: str,
    ) -> None:
        state = self.cdp.evaluate(
            """
            (async () => {
                const frame = document.querySelector(
                    'iframe[name="mainframe"]'
                );
                const sidebar = document.querySelector(
                    'iframe[name="status"]'
                );
                if (
                    !frame
                    || !frame.contentDocument
                    || !frame.contentWindow
                    || !sidebar
                    || !sidebar.contentDocument
                ) {
                    return null;
                }
                const doc = frame.contentDocument;
                const shell = doc.querySelector(
                    '[data-estab-bos-document-shell]'
                );
                const header = shell && shell.querySelector(
                    '.estab-bos-document-header'
                );
                const heading = header && header.querySelector('h1');
                const description = header && header.querySelector('p');
                const content = shell && shell.querySelector(
                    '[data-estab-bos-original-content]'
                );
                const selected = sidebar.contentDocument.querySelector(
                    'a[data-estab-bos-document-link][aria-current="page"]'
                );
                if (
                    !shell
                    || !header
                    || !heading
                    || !description
                    || !content
                    || !selected
                ) {
                    return null;
                }

                const response = await fetch(
                    frame.contentWindow.location.href,
                    {cache: 'no-store'}
                );
                if (!response.ok) return null;
                const source = await response.text();
                const parsed = new DOMParser().parseFromString(
                    source,
                    'text/html'
                );
                const tables = Array.from(
                    content.querySelectorAll('table')
                );
                const wrappers = Array.from(
                    content.querySelectorAll(
                        '[data-estab-bos-table-scroll]'
                    )
                );
                const images = Array.from(
                    content.querySelectorAll('img')
                );
                const root = doc.scrollingElement || doc.documentElement;
                const contentStyle = frame.contentWindow.getComputedStyle(
                    content
                );
                const headerStyle = frame.contentWindow.getComputedStyle(
                    header
                );
                const selectedTitle = selected.querySelector('strong');
                const selectedDescription = selected.querySelector('span');
                const contrastRatio = (foreground, background) => {
                    const channels = colour => {
                        const values = colour.match(/[\\d.]+/g);
                        if (!values || values.length < 3) return null;
                        return values.slice(0, 3).map(value => {
                            const channel = Number(value) / 255;
                            return channel <= 0.04045
                                ? channel / 12.92
                                : Math.pow(
                                    (channel + 0.055) / 1.055,
                                    2.4
                                );
                        });
                    };
                    const first = channels(foreground);
                    const second = channels(background);
                    if (!first || !second) return 0;
                    const luminance = values =>
                        0.2126 * values[0]
                        + 0.7152 * values[1]
                        + 0.0722 * values[2];
                    const lighter = Math.max(
                        luminance(first),
                        luminance(second)
                    );
                    const darker = Math.min(
                        luminance(first),
                        luminance(second)
                    );
                    return (lighter + 0.05) / (darker + 0.05);
                };
                const semanticColoursReadable = Array.from(
                    content.querySelectorAll('font[color="#ffffff" i]')
                ).every(label => {
                    const labelStyle =
                        frame.contentWindow.getComputedStyle(label);
                    const cell = label.closest('td');
                    const ownBackground = labelStyle.backgroundColor;
                    const background =
                        ownBackground !== 'rgba(0, 0, 0, 0)'
                            && ownBackground !== 'transparent'
                        ? ownBackground
                        : cell
                            ? frame.contentWindow.getComputedStyle(
                                cell
                            ).backgroundColor
                            : 'rgb(255, 255, 255)';
                    return contrastRatio(
                        labelStyle.color,
                        background
                    ) >= 4.5;
                });
                const scrollRegionsReady = wrappers.every(wrapper => {
                    const scrollable =
                        wrapper.scrollWidth > wrapper.clientWidth + 1;
                    return scrollable
                        ? wrapper.getAttribute('role') === 'region'
                            && wrapper.tabIndex === 0
                        : !wrapper.hasAttribute('role')
                            && !wrapper.hasAttribute('tabindex');
                });
                return {
                    shellCount: doc.querySelectorAll(
                        '[data-estab-bos-document-shell]'
                    ).length,
                    title: heading.textContent.trim(),
                    titleMatchesNavigation:
                        Boolean(selectedTitle) &&
                        heading.textContent.trim()
                            === selectedTitle.textContent.trim(),
                    descriptionMatchesNavigation:
                        Boolean(selectedDescription) &&
                        description.textContent.trim()
                            === selectedDescription.textContent.trim(),
                    originalTextPreserved:
                        Boolean(parsed.body) &&
                        content.textContent === parsed.body.textContent,
                    tableCount: tables.length,
                    wrapperCount: wrappers.length,
                    tablesWrapped: tables.every(table =>
                        Boolean(table.parentElement) &&
                        table.parentElement.matches(
                            '[data-estab-bos-table-scroll]'
                        )
                    ),
                    imagesFit: images.every(image =>
                        image.getBoundingClientRect().width
                            <= content.getBoundingClientRect().width + 1
                    ),
                    rootFits:
                        root.scrollWidth <= root.clientWidth + 1,
                    contentBackground: contentStyle.backgroundColor,
                    contentRadius: parseFloat(
                        contentStyle.borderTopLeftRadius
                    ),
                    fontFamily: contentStyle.fontFamily,
                    headerGradient:
                        headerStyle.backgroundImage.includes(
                            'linear-gradient'
                        ),
                    semanticColoursReadable,
                    scrollRegionsReady
                };
            })()
            """
        )
        self._truth(
            isinstance(state, dict)
            and state.get("shellCount") == 1
            and state.get("title") == expected_title
            and state.get("titleMatchesNavigation") is True
            and state.get("descriptionMatchesNavigation") is True
            and state.get("originalTextPreserved") is True
            and int(state.get("tableCount", 0)) > 0
            and state.get("wrapperCount") == state.get("tableCount")
            and state.get("tablesWrapped") is True
            and state.get("imagesFit") is True
            and state.get("rootFits") is True
            and state.get("contentBackground") == "rgb(255, 255, 255)"
            and float(state.get("contentRadius", 0)) >= 8
            and "Arial" in str(state.get("fontFamily", ""))
            and state.get("headerGradient") is True
            and state.get("semanticColoursReadable") is True
            and state.get("scrollRegionsReady") is True,
            f"BOS-Dokument ist in {location} nicht konsistent: {state!r}",
        )

    def _assert_mobile_bos_navigation(self, location: str) -> None:
        time.sleep(0.25)
        content_state = self.cdp.evaluate(
            """
            (() => {
                const content = document.querySelector(
                    'iframe[name="mainframe"]'
                );
                const returnButton = document.querySelector(
                    "[data-estab-mobile-menu-return]"
                );
                if (!content || !content.contentDocument || !returnButton) {
                    return false;
                }
                const contentRect = content.getBoundingClientRect();
                const buttonRect = returnButton.getBoundingClientRect();
                const buttonStyle = getComputedStyle(returnButton);
                const root = content.contentDocument.scrollingElement ||
                    content.contentDocument.documentElement;
                if (!root) {
                    return false;
                }
                const visibleHeight = Math.max(
                    0,
                    Math.min(innerHeight, contentRect.bottom)
                        - Math.max(0, contentRect.top)
                );
                const buttonVisible =
                    buttonStyle.display !== "none" &&
                    buttonStyle.visibility !== "hidden" &&
                    buttonRect.width >= 44 &&
                    buttonRect.height >= 44 &&
                    buttonRect.right > 0 &&
                    buttonRect.left < innerWidth &&
                    buttonRect.bottom > 0 &&
                    buttonRect.top < innerHeight;
                return {
                    visibleHeight,
                    buttonWidth: buttonRect.width,
                    buttonHeight: buttonRect.height,
                    buttonVisible,
                    enhanced:
                        content.contentDocument.documentElement.classList.contains(
                            "estab-bos-embedded-document"
                        ),
                    horizontalOverflow:
                        root.scrollWidth > root.clientWidth + 1,
                    scrollY
                };
            })()
            """,
        )
        self._truth(
            isinstance(content_state, dict)
            and content_state.get("scrollY", 0) >= 843
            and content_state.get("visibleHeight", 0) >= 843
            and content_state.get("buttonWidth", 0) >= 44
            and content_state.get("buttonHeight", 0) >= 44
            and content_state.get("buttonVisible") is True
            and content_state.get("enhanced") is True
            and content_state.get("horizontalOverflow") is False,
            f"Mobiler BOS-Dokumentwechsel ist in {location} unvollständig: "
            f"{content_state!r}",
        )

        self.cdp.click(
            None,
            "[data-estab-mobile-menu-return]",
            f"Mobiler BOS-Rückkehrbutton in {location}",
        )
        self.cdp.wait_for(
            """
            (() => {
                const sidebar = document.querySelector(
                    'iframe[name="status"]'
                );
                const returnButton = document.querySelector(
                    "[data-estab-mobile-menu-return]"
                );
                if (!sidebar || !returnButton) return false;
                const rect = sidebar.getBoundingClientRect();
                const visibleHeight = Math.max(
                    0,
                    Math.min(innerHeight, rect.bottom)
                        - Math.max(0, rect.top)
                );
                return scrollY <= 1 &&
                    visibleHeight >= innerHeight - 1 &&
                    document.activeElement === sidebar &&
                    returnButton.hidden;
            })()
            """,
            f"BOS-Rückkehrbutton bringt in {location} nicht zum Info-Menü",
        )

    def _assert_mobile_message_navigation(self, location: str) -> None:
        armed = self.cdp.evaluate(
            """
            (() => {
                const sidebar = document.querySelector(
                    'iframe[name="vorgaben"]'
                );
                const content = document.querySelector(
                    'iframe[name="mainframe"]'
                );
                if (!sidebar || !content || !content.contentWindow) {
                    return false;
                }
                window.__estabMobileLoadFocusProbe = false;
                content.addEventListener("load", () => {
                    window.__estabMobileLoadFocusProbe = true;
                    sidebar.focus({preventScroll: true});
                }, {once: true});
                content.contentWindow.__estabMobileActionProbe = true;
                return true;
            })()
            """
        )
        self._truth(
            armed,
            f"Mobiler Rollenaktions-Test konnte in {location} nicht vorbereitet werden.",
        )
        self.cdp.click(
            "vorgaben",
            'button[data-estab-workflow-key="stab_lesen"]',
            f"Rollenaktion Lesen in {location}",
        )
        content_state = self.cdp.wait_for(
            """
            (() => {
                const content = document.querySelector(
                    'iframe[name="mainframe"]'
                );
                const returnButton = document.querySelector(
                    "[data-estab-mobile-menu-return]"
                );
                if (!content || !content.contentWindow || !returnButton) {
                    return false;
                }
                const contentRect = content.getBoundingClientRect();
                const buttonRect = returnButton.getBoundingClientRect();
                const buttonStyle = getComputedStyle(returnButton);
                const visibleHeight = Math.max(
                    0,
                    Math.min(innerHeight, contentRect.bottom)
                        - Math.max(0, contentRect.top)
                );
                const buttonVisible =
                    buttonStyle.display !== "none" &&
                    buttonStyle.visibility !== "hidden" &&
                    buttonRect.width >= 44 &&
                    buttonRect.height >= 44 &&
                    buttonRect.right > 0 &&
                    buttonRect.left < innerWidth &&
                    buttonRect.bottom > 0 &&
                    buttonRect.top < innerHeight;
                let actionLoaded = false;
                try {
                    actionLoaded =
                        content.contentDocument.readyState === "complete" &&
                        content.contentWindow.__estabMobileActionProbe !== true;
                } catch (_error) {
                    return false;
                }
                if (!actionLoaded ||
                    visibleHeight < innerHeight - 1 ||
                    !buttonVisible ||
                    window.__estabMobileLoadFocusProbe !== true ||
                    document.activeElement !== content) {
                    return false;
                }
                return {
                    visibleHeight,
                    buttonWidth: buttonRect.width,
                    buttonHeight: buttonRect.height,
                    scrollY,
                    contentFocused: document.activeElement === content,
                    loadFocusRestored:
                        window.__estabMobileLoadFocusProbe === true
                };
            })()
            """,
            f"Rollenaktion zeigt in {location} den mainframe nicht sichtbar an",
        )
        self._truth(
            content_state.get("scrollY", 0) >= 843
            and content_state.get("visibleHeight", 0) >= 843
            and content_state.get("buttonWidth", 0) >= 44
            and content_state.get("buttonHeight", 0) >= 44
            and content_state.get("contentFocused") is True
            and content_state.get("loadFocusRestored") is True,
            f"Mobiler Inhaltswechsel oder Rückkehrbutton ist in {location} unvollständig.",
        )

        self.cdp.click(
            None,
            "[data-estab-mobile-menu-return]",
            f"Mobiler Rückkehrbutton in {location}",
        )
        self.cdp.wait_for(
            """
            (() => {
                const sidebar = document.querySelector(
                    'iframe[name="vorgaben"]'
                );
                if (!sidebar) return false;
                const rect = sidebar.getBoundingClientRect();
                const visibleHeight = Math.max(
                    0,
                    Math.min(innerHeight, rect.bottom)
                        - Math.max(0, rect.top)
                );
                return scrollY <= 1 &&
                    visibleHeight >= innerHeight - 1 &&
                    document.activeElement === sidebar;
            })()
            """,
            f"Rückkehrbutton bringt in {location} nicht zur Sidebar zurück",
        )

    def _assert_existing_message_attachment_previews(self) -> None:
        marker = self.config.workflow_marker
        if marker is None:
            print(
                "      übersprungen: echte Anlagenvorschau ohne "
                "ESTAB_TEST_WORKFLOW_MARKER"
            )
            return
        message_marker = marker + "_DIRECT_ATTACHMENT_SUBMIT"
        # The legacy search field really has maxlength=30. Keep this input
        # short enough for the actual UI while the full unique marker below
        # still identifies the exact result row.
        search_marker = "DIRECT_ATTACHMENT_SUBMIT"

        self.cdp.click(
            "mainframe",
            'input[name="flt_find_mask_ein"]',
            "Nachrichtensuche für die echte Anlagenvorschau",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                return doc.readyState === "complete" &&
                    Boolean(doc.querySelector('input[name="flt_search"]')) &&
                    Boolean(doc.querySelector('input[name="filter_suche"]'));
                """,
            ),
            "Nachrichtensuche für die Anlagenvorschau wurde nicht geöffnet",
        )
        self.cdp.set_value(
            "mainframe",
            'input[name="flt_search"]',
            search_marker,
            "Suchmarker der Nachricht mit Bild, PDF und E-Mail",
        )
        self.cdp.click(
            "mainframe",
            'input[name="filter_suche"]',
            "Nachricht mit Bild, PDF und E-Mail suchen",
        )
        row_state = self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                f"""
                if (doc.readyState !== "complete") return false;
                const row = Array.from(doc.querySelectorAll("tr")).find(
                    item => item.innerText.includes({json.dumps(message_marker)})
                );
                if (!row) return false;
                const badge = row.querySelector(
                    '[data-estab-message-attachment-badge]'
                );
                const openForm = Array.from(
                    row.querySelectorAll("form")
                ).find(form =>
                    form.querySelector('input[name="stab"][value="meldung"]') &&
                    form.querySelector('input[name="00_lfd"]')
                );
                const open = openForm?.querySelector(
                    'button[type="submit"], input[type="submit"], '
                        + 'input[type="image"]'
                );
                if (!badge || !open) return false;
                open.setAttribute(
                    "data-estab-browser-open-attachment-message",
                    "true"
                );
                return {{
                    count: badge.getAttribute(
                        "data-estab-message-attachment-count"
                    ),
                    label: badge.innerText.trim()
                }};
                """,
            ),
            "gespeicherte Nachricht mit Anlagen wurde nicht gefunden",
        )
        self._truth(
            isinstance(row_state, dict)
            and row_state.get("count") == "3"
            and row_state.get("label") == "3 Anlagen",
            f"Anlagenhinweis der echten Nachricht ist falsch: {row_state!r}",
        )
        self.cdp.click(
            "mainframe",
            '[data-estab-browser-open-attachment-message]',
            "Nachricht mit Bild, PDF und E-Mail öffnen",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const panel = doc.querySelector(
                    '[data-estab-message-attachments]'
                );
                if (
                    doc.readyState !== "complete" ||
                    !panel ||
                    panel.getAttribute("data-estab-attachment-count") !== "3"
                ) return false;
                panel.scrollIntoView({block: "start"});
                return true;
                """,
            ),
            "Anlagenbereich der gespeicherten Nachricht wurde nicht geladen",
        )
        self._assert_message_timeline_layout(
            mobile=False,
            description="gespeicherter Nachrichtenvordruck mit Anlagen",
        )
        preview_state = self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const image = doc.querySelector(
                    '.estab-message-attachment-preview img'
                );
                const details = doc.querySelector(
                    '[data-estab-pdf-preview]'
                );
                const frame = details?.querySelector('iframe[data-src]');
                const emailDetails = doc.querySelector(
                    '[data-estab-email-preview]'
                );
                const emailFrame = emailDetails?.querySelector(
                    'iframe[data-src]'
                );
                const emailCard = emailDetails?.closest(
                    '[data-estab-email-attachment]'
                );
                const originalDownload = emailCard?.querySelector(
                    '.estab-message-attachment-actions a[download]'
                );
                const browserLinks = Array.from(doc.querySelectorAll(
                    '.estab-message-attachment-actions a[target="_blank"]'
                ));
                if (
                    !image || !image.complete ||
                    image.naturalWidth !== 640 || image.naturalHeight !== 640 ||
                    !details || details.open || !frame ||
                    frame.hasAttribute("src") ||
                    !frame.getAttribute("data-src")?.includes("view=inline") ||
                    !emailDetails || emailDetails.open || !emailFrame ||
                    emailFrame.hasAttribute("src") ||
                    !emailFrame.getAttribute("data-src")?.includes(
                        "email.php?file="
                    ) ||
                    !originalDownload ||
                    !originalDownload.getAttribute("href")?.includes(
                        "download.php"
                    ) ||
                    browserLinks.length !== 3
                ) return false;
                return {
                    imageWidth: image.naturalWidth,
                    imageHeight: image.naturalHeight,
                    imageSource: image.currentSrc,
                    pdfUrl: frame.getAttribute("data-src"),
                    emailUrl: emailFrame.getAttribute("data-src"),
                    originalName: originalDownload.getAttribute("download"),
                    accessibleLinks: browserLinks.every(link =>
                        link.rel.includes("noopener") &&
                        link.getAttribute("aria-label")?.includes(
                            "neuem Browser-Tab"
                        )
                    )
                };
                """,
            ),
            "echte Bild-, PDF- oder E-Mail-Vorschau wurde nicht aufgebaut",
        )
        self._truth(
            isinstance(preview_state, dict)
            and preview_state.get("imageWidth") == 640
            and preview_state.get("imageHeight") == 640
            and "showpic.php" in str(preview_state.get("imageSource", ""))
            and "email.php?file=" in str(preview_state.get("emailUrl", ""))
            and preview_state.get("originalName") == "Einsatzmail-Uebung.EML"
            and preview_state.get("accessibleLinks") is True,
            "Bild-/PDF-/E-Mail-Aktionen sind im echten Browser nicht sichtbar oder "
            f"zugänglich: {preview_state!r}",
        )
        pdf_url = str(preview_state.get("pdfUrl", ""))
        pdf_request_url = urllib.parse.urljoin(
            self.config.base_url + "/",
            pdf_url,
        )
        self.cdp.discard_events("Network.responseReceived")
        self.cdp.discard_events("Network.loadingFinished")
        self.cdp.discard_events("Network.loadingFailed")
        self.cdp.click(
            "mainframe",
            '[data-estab-pdf-preview] > summary',
            "eingebettete PDF-Anlage öffnen",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                f"""
                const details = doc.querySelector(
                    '[data-estab-pdf-preview]'
                );
                const frame = details?.querySelector("iframe");
                return Boolean(
                    details?.open &&
                    frame?.getAttribute("src") === {json.dumps(pdf_url)} &&
                    !frame.hasAttribute("data-src") &&
                    frame.getBoundingClientRect().height >= 300
                );
                """,
            ),
            "lazy PDF-Anlage wurde beim Aufklappen nicht geladen",
        )

        pdf_response: dict[str, Any] | None = None
        pdf_request_id: str | None = None
        pdf_loading_finished = False
        pdf_loading_failure: dict[str, Any] | None = None
        pdf_response_seen_at: float | None = None
        deadline = time.monotonic() + self.config.timeout
        while time.monotonic() < deadline:
            self.cdp.call("Runtime.evaluate", {"expression": "0"})
            for event in self.cdp.events:
                method = event.get("method")
                params = event.get("params", {})
                if method == "Network.responseReceived":
                    response = params.get("response", {})
                    if (
                        isinstance(response, dict)
                        and response.get("url") == pdf_request_url
                        and response.get("status") == 200
                        and response.get("mimeType") == "application/pdf"
                    ):
                        pdf_response = response
                        request_id = params.get("requestId")
                        pdf_request_id = (
                            request_id if isinstance(request_id, str) else None
                        )
                        if pdf_response_seen_at is None:
                            pdf_response_seen_at = time.monotonic()
                elif (
                    pdf_request_id is not None
                    and params.get("requestId") == pdf_request_id
                ):
                    if method == "Network.loadingFinished":
                        pdf_loading_finished = True
                    elif method == "Network.loadingFailed":
                        pdf_loading_failure = params
            # Chrome can hand a successful PDF response to PDFium with no
            # classic loadingFinished event (or with a benign ERR_ABORTED).
            # Give the terminal event a short opportunity to arrive, then let
            # the visible rendered page be the authoritative completion proof.
            if pdf_response is not None and (
                pdf_loading_finished
                or pdf_loading_failure is not None
                or (
                    pdf_response_seen_at is not None
                    and time.monotonic() - pdf_response_seen_at >= 1.5
                )
            ):
                break
            time.sleep(0.1)
        response_headers = {
            str(name).lower(): str(value)
            for name, value in (
                pdf_response.get("headers", {}).items()
                if isinstance(pdf_response, dict)
                and isinstance(pdf_response.get("headers"), dict)
                else []
            )
        }
        # CDP joins repeated response fields with newlines. Apache adds the
        # application-wide protection headers and the download boundary adds
        # the matching object-specific policy, so identical directives may be
        # reported more than once even though every effective policy is the
        # intended one. Validate each effective value instead of treating
        # CDP's transport representation as one literal field value.
        content_type_options = [
            value.strip().lower()
            for value in response_headers.get(
                "x-content-type-options", ""
            ).splitlines()
            if value.strip()
        ]
        frame_options = [
            value.strip().upper()
            for value in response_headers.get(
                "x-frame-options", ""
            ).splitlines()
            if value.strip()
        ]
        content_security_policies = [
            value.strip().lower()
            for value in response_headers.get(
                "content-security-policy", ""
            ).splitlines()
            if value.strip()
        ]
        self._truth(
            pdf_response is not None,
            "eingebettete PDF-Anlage erreichte Chrome nicht als application/pdf",
        )
        self._truth(
            response_headers.get("content-disposition", "").lower().startswith(
                "inline"
            )
            and "no-store" in response_headers.get("cache-control", "").lower()
            and content_type_options
            and all(value == "nosniff" for value in content_type_options)
            and frame_options
            and all(value == "SAMEORIGIN" for value in frame_options)
            and content_security_policies
            and all(
                "frame-ancestors 'self'" in value
                and "sandbox" not in value
                for value in content_security_policies
            ),
            "eingebettete PDF-Anlage hat nicht die erwarteten geschützten "
            f"Inline-Header: {response_headers!r}",
        )

        frame_rect = self.cdp.evaluate(
            _frame_expression(
                "mainframe",
                """
                const frame = doc.querySelector(
                    '[data-estab-pdf-preview] iframe'
                );
                if (!frame) return null;
                const rect = frame.getBoundingClientRect();
                let x = rect.left;
                let y = rect.top;
                let current = target;
                while (current !== current.top) {
                    const parentFrame = current.frameElement;
                    if (!parentFrame) return null;
                    const parentRect = parentFrame.getBoundingClientRect();
                    x += parentRect.left;
                    y += parentRect.top;
                    current = current.parent;
                }
                return {
                    x: Math.max(0, x),
                    y: Math.max(0, y),
                    width: Math.min(rect.width, 900),
                    height: Math.min(rect.height, 600)
                };
                """,
            )
        )
        self._truth(
            isinstance(frame_rect, dict)
            and frame_rect.get("width", 0) >= 300
            and frame_rect.get("height", 0) >= 300,
            "eingebettete PDF-Fläche ist im Browser nicht messbar",
        )
        rendered_pdf = False
        last_pdf_render_summary: dict[str, int] = {}
        last_pdf_screenshot_bytes = 0
        deadline = time.monotonic() + self.config.timeout
        while time.monotonic() < deadline and not rendered_pdf:
            screenshot = self.cdp.call(
                "Page.captureScreenshot",
                {
                    "format": "png",
                    "fromSurface": True,
                    "captureBeyondViewport": True,
                    "clip": {
                        "x": float(frame_rect["x"]),
                        "y": float(frame_rect["y"]),
                        "width": float(frame_rect["width"]),
                        "height": float(frame_rect["height"]),
                        "scale": 1,
                    },
                },
            )
            image_bytes = b""
            try:
                image_bytes = base64.b64decode(
                    screenshot.get("data"),
                    validate=True,
                )
                summary = _png_rgb_content_summary(image_bytes)
            except (TypeError, ValueError):
                summary = {}
            last_pdf_render_summary = summary
            last_pdf_screenshot_bytes = len(image_bytes)
            pixels = summary.get("width", 0) * summary.get("height", 0)
            rendered_pdf = (
                len(image_bytes) > 5000
                and pixels > 0
                # A real PDF page supplies a large white paper surface. The
                # gray Chromium broken-document screen does not.
                and summary.get("white_pixels", 0) >= pixels * 0.15
                and summary.get("dark_pixels", 0) >= pixels * 0.0005
            )
            if not rendered_pdf:
                time.sleep(0.1)
        self._truth(
            rendered_pdf,
            "Chrome zeigt in der aufgeklappten PDF-Anlage keine sichtbare "
            "Seite: "
            f"request_id={pdf_request_id!r}, "
            f"loading_finished={pdf_loading_finished!r}, "
            f"loading_failure={pdf_loading_failure!r}, "
            f"frame={frame_rect!r}, png_bytes={last_pdf_screenshot_bytes}, "
            f"pixels={last_pdf_render_summary!r}",
        )

        email_url = str(preview_state.get("emailUrl", ""))
        email_request_url = urllib.parse.urljoin(
            self.config.base_url + "/",
            email_url,
        )
        self.cdp.evaluate(
            _frame_expression(
                "mainframe",
                """
                target.__estabEmailXss = "parent-clean";
                target.top.__estabEmailXss = "top-clean";
                return true;
                """,
            )
        )
        self.cdp.discard_events("Network.requestWillBeSent")
        self.cdp.discard_events("Network.responseReceived")
        self.cdp.discard_events("Network.loadingFinished")
        self.cdp.discard_events("Network.loadingFailed")
        self.cdp.click(
            "mainframe",
            '[data-estab-email-preview] > summary',
            "eingebettete E-Mail-Anlage öffnen",
        )
        email_state = self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                f"""
                const details = doc.querySelector(
                    '[data-estab-email-preview]'
                );
                const frame = details?.querySelector("iframe");
                if (
                    !details?.open ||
                    frame?.getAttribute("src") !== {json.dumps(email_url)} ||
                    frame.hasAttribute("data-src") ||
                    frame.getBoundingClientRect().height < 300
                ) return false;
                let emailDoc;
                try {{
                    emailDoc = frame.contentDocument;
                }} catch (_error) {{
                    return false;
                }}
                if (!emailDoc || emailDoc.readyState !== "complete") {{
                    return false;
                }}
                const root = emailDoc.querySelector(
                    '[data-estab-email-preview]'
                );
                const subject = emailDoc.querySelector(
                    '[data-estab-email-subject]'
                );
                const headerText = emailDoc.querySelector(
                    '[data-estab-email-headers]'
                )?.innerText || "";
                const body = emailDoc.querySelector(
                    '[data-estab-email-body]'
                );
                const contained = Array.from(emailDoc.querySelectorAll(
                    '[data-estab-email-contained-attachments] li'
                ));
                const forbiddenElements = emailDoc.querySelectorAll(
                    'script, style, iframe, object, embed, form, img'
                ).length;
                const eventAttributes = Array.from(
                    emailDoc.querySelectorAll("*")
                ).flatMap(element => Array.from(element.attributes)).filter(
                    attribute => /^on/i.test(attribute.name)
                );
                const dangerousUrls = Array.from(
                    emailDoc.querySelectorAll("*")
                ).flatMap(element => Array.from(element.attributes)).filter(
                    attribute =>
                        /^(?:href|src|action|formaction|srcdoc)$/i.test(
                            attribute.name
                        ) &&
                        /^(?:javascript:|https?:\\/\\/)/i.test(
                            attribute.value.trim()
                        )
                );
                const text = emailDoc.body?.innerText || "";
                if (
                    !root ||
                    root.getAttribute("data-estab-email-rendering") !==
                        "passive-text" ||
                    subject?.textContent.trim() !== "Lage <Übung> – Grüße" ||
                    !headerText.includes("Erika Müller") ||
                    !headerText.includes("Führungsstelle Göppingen") ||
                    body?.getAttribute("data-estab-email-body-source") !==
                        "html" ||
                    !text.includes("E-Mail-Lagemeldung") ||
                    !text.includes("Gefahr & Rückmeldung aus der Übung.") ||
                    !text.includes("Rückfrage") ||
                    !text.includes("Absenderangaben nicht verifiziert") ||
                    contained.length !== 2 ||
                    !contained.some(item =>
                        item.innerText.includes("Lage-Übung.png")
                    ) ||
                    !contained.some(item =>
                        item.innerText.includes("Notiz-Übung.txt")
                    ) ||
                    forbiddenElements !== 0 ||
                    eventAttributes.length !== 0 ||
                    dangerousUrls.length !== 0 ||
                    emailDoc.documentElement.innerHTML.includes(
                        "evil.invalid"
                    ) ||
                    emailDoc.documentElement.innerHTML.includes(
                        "window.__estabEmailXss"
                    )
                ) return false;
                return {{
                    rendering: root.getAttribute(
                        "data-estab-email-rendering"
                    ),
                    subject: subject.textContent.trim(),
                    attachments: contained.length,
                    forbiddenElements,
                    eventAttributes: eventAttributes.length,
                    dangerousUrls: dangerousUrls.length,
                    frameHeight: frame.getBoundingClientRect().height,
                    ownProbe: typeof frame.contentWindow.__estabEmailXss,
                    parentProbe: target.__estabEmailXss,
                    topProbe: target.top.__estabEmailXss
                }};
                """,
            ),
            "passive E-Mail-Anlage wurde nicht sicher im Browser dargestellt",
        )
        self._truth(
            isinstance(email_state, dict)
            and email_state.get("rendering") == "passive-text"
            and email_state.get("subject") == "Lage <Übung> – Grüße"
            and email_state.get("attachments") == 2
            and email_state.get("forbiddenElements") == 0
            and email_state.get("eventAttributes") == 0
            and email_state.get("dangerousUrls") == 0
            and email_state.get("frameHeight", 0) >= 300
            and email_state.get("ownProbe") == "undefined"
            and email_state.get("parentProbe") == "parent-clean"
            and email_state.get("topProbe") == "top-clean",
            "E-Mail-Anlage enthält aktive Inhalte oder ist nicht vollständig "
            f"sichtbar: {email_state!r}",
        )

        email_response: dict[str, Any] | None = None
        deadline = time.monotonic() + self.config.timeout
        while time.monotonic() < deadline:
            self.cdp.call("Runtime.evaluate", {"expression": "0"})
            for event in self.cdp.events:
                if event.get("method") != "Network.responseReceived":
                    continue
                response = event.get("params", {}).get("response", {})
                if (
                    isinstance(response, dict)
                    and response.get("url") == email_request_url
                    and response.get("status") == 200
                    and response.get("mimeType") == "text/html"
                ):
                    email_response = response
                    break
            if email_response is not None:
                break
            time.sleep(0.1)
        email_response_headers = {
            str(name).lower(): str(value)
            for name, value in (
                email_response.get("headers", {}).items()
                if isinstance(email_response, dict)
                and isinstance(email_response.get("headers"), dict)
                else []
            )
        }
        email_content_type_options = [
            value.strip().lower()
            for value in email_response_headers.get(
                "x-content-type-options", ""
            ).splitlines()
            if value.strip()
        ]
        email_frame_options = [
            value.strip().upper()
            for value in email_response_headers.get(
                "x-frame-options", ""
            ).splitlines()
            if value.strip()
        ]
        email_policies = [
            value.strip().lower()
            for value in email_response_headers.get(
                "content-security-policy", ""
            ).splitlines()
            if value.strip()
        ]
        email_rendering_headers = [
            value.strip().lower()
            for value in email_response_headers.get(
                "x-estab-email-rendering", ""
            ).splitlines()
            if value.strip()
        ]
        email_integrity_headers = [
            value.strip().lower()
            for value in email_response_headers.get(
                "x-estab-attachment-integrity", ""
            ).splitlines()
            if value.strip()
        ]
        email_sha_headers = [
            value.strip().lower()
            for value in email_response_headers.get(
                "x-estab-attachment-sha256", ""
            ).splitlines()
            if value.strip()
        ]
        self._truth(
            email_response is not None,
            "passive E-Mail-Anlage erreichte Chrome nicht als text/html",
        )
        self._truth(
            "no-store" in email_response_headers.get(
                "cache-control", ""
            ).lower()
            and email_content_type_options
            and all(
                value == "nosniff" for value in email_content_type_options
            )
            and email_frame_options
            and all(value == "SAMEORIGIN" for value in email_frame_options)
            and any(
                "default-src 'none'" in value
                and "script-src 'none'" in value
                and "img-src 'none'" in value
                and "frame-ancestors 'self'" in value
                for value in email_policies
            )
            and email_rendering_headers
            and all(
                value == "passive-text"
                for value in email_rendering_headers
            )
            and email_integrity_headers
            and all(
                value == "verified" for value in email_integrity_headers
            )
            and email_sha_headers
            and all(
                re.fullmatch(r"[a-f0-9]{64}", value) is not None
                for value in email_sha_headers
            ),
            "E-Mail-Anlage hat nicht die erwarteten geschützten Header: "
            f"{email_response_headers!r}",
        )
        hostile_requests = []
        for event in self.cdp.events:
            if event.get("method") != "Network.requestWillBeSent":
                continue
            request = event.get("params", {}).get("request", {})
            request_url = request.get("url", "") if isinstance(
                request, dict
            ) else ""
            if "evil.invalid" in str(request_url):
                hostile_requests.append(str(request_url))
        self._truth(
            hostile_requests == [],
            "Passive E-Mail-Ansicht lud externe Inhalte: "
            f"{hostile_requests!r}",
        )

    def _assert_conversation_medium_toggle(self) -> None:
        expected_values = ["Fu", "Fe", "FAX", "@", "Me"]
        expected_labels = [
            "Funk",
            "Telefon",
            "Telefax",
            "DFÜ",
            "Kurier/Melder",
        ]

        def medium_state_expression() -> str:
            return _frame_expression(
                "mainframe",
                """
                const container = doc.querySelector(
                    "[data-estab-conversation-medium]"
                );
                const status = doc.querySelector(
                    "[data-estab-conversation-medium-status]"
                );
                const checkbox = doc.querySelector("#f_11_gesprnotiz");
                const controls = Array.from(doc.querySelectorAll(
                    'input[type="radio"][name="01_medium"]'
                ));
                return {
                    containerCount: doc.querySelectorAll(
                        "[data-estab-conversation-medium]"
                    ).length,
                    statusCount: doc.querySelectorAll(
                        "[data-estab-conversation-medium-status]"
                    ).length,
                    statusText: status?.textContent.trim() || "",
                    checkboxExists: Boolean(checkbox),
                    checkboxChecked: checkbox?.checked ?? null,
                    values: controls.map(control => control.value),
                    ids: controls.map(control => control.id),
                    labels: controls.map(control =>
                        control.closest("label")?.textContent
                            .replace(/\\s+/g, " ").trim() || ""
                    ),
                    names: controls.map(control => control.name),
                    disabled: controls.map(control => control.disabled),
                    required: controls.map(control => control.required),
                    checked: controls
                        .filter(control => control.checked)
                        .map(control => control.value),
                    insideContainer: Boolean(
                        container
                        && controls.length === 5
                        && controls.every(control =>
                            container.contains(control)
                        )
                    )
                };
                """,
            )

        initial = self.cdp.wait_for(
            medium_state_expression(),
            "Gesprächsnotiz-Übermittlungswege fehlen im Stabformular",
        )
        self._truth(
            isinstance(initial, dict)
            and initial.get("containerCount") == 1
            and initial.get("statusCount") == 1
            and bool(initial.get("statusText"))
            and initial.get("checkboxExists") is True
            and initial.get("checkboxChecked") is False
            and initial.get("values") == expected_values
            and initial.get("ids") == [
                "f_01_medium_fu",
                "f_01_medium_fe",
                "f_01_medium_fax",
                "f_01_medium_at",
                "f_01_medium_me",
            ]
            and initial.get("labels") == expected_labels
            and initial.get("names") == ["01_medium"] * 5
            and initial.get("disabled") == [True] * 5
            and initial.get("required") == [False] * 5
            and initial.get("checked") == []
            and initial.get("insideContainer") is True,
            "Initialer Gesprächsnotiz-Medienblock ist nicht eindeutig, "
            f"vollständig oder sicher deaktiviert: {initial!r}",
        )

        self.cdp.click(
            "mainframe",
            "#f_11_gesprnotiz",
            "Gesprächsnotiz aktivieren",
        )
        enabled = self.cdp.wait_for(
            medium_state_expression(),
            "Gesprächsnotiz aktiviert ihre Übermittlungswege nicht",
        )
        self._truth(
            isinstance(enabled, dict)
            and enabled.get("checkboxChecked") is True
            and enabled.get("disabled") == [False] * 5
            and enabled.get("required") == [True] * 5
            and enabled.get("checked") == [],
            "Aktivierte Gesprächsnotiz macht die Medienauswahl nicht "
            f"vollständig verpflichtend: {enabled!r}",
        )

        self.cdp.click(
            "mainframe",
            "#f_01_medium_me",
            "Melder als Gesprächsweg auswählen",
        )
        selected = self.cdp.wait_for(
            medium_state_expression(),
            "Melder konnte nicht als Gesprächsweg ausgewählt werden",
        )
        self._equal(
            selected.get("checked") if isinstance(selected, dict) else None,
            ["Me"],
            "ausgewähltes Gesprächsmedium",
        )
        self._equal(
            selected.get("statusText") if isinstance(selected, dict) else None,
            "Ausgewählt: Kurier/Melder.",
            "Status des ausgewählten Gesprächsmediums",
        )

        self.cdp.click(
            "mainframe",
            "#f_11_gesprnotiz",
            "Gesprächsnotiz vorübergehend deaktivieren",
        )
        disabled = self.cdp.wait_for(
            medium_state_expression(),
            "Deaktivierte Gesprächsnotiz sperrt ihre Medien nicht",
        )
        self._truth(
            isinstance(disabled, dict)
            and disabled.get("checkboxChecked") is False
            and disabled.get("disabled") == [True] * 5
            and disabled.get("required") == [False] * 5
            and disabled.get("checked") == ["Me"],
            "Deaktivieren der Gesprächsnotiz verliert die Auswahl oder "
            f"lässt Medien aktiv: {disabled!r}",
        )

        self.cdp.click(
            "mainframe",
            "#f_11_gesprnotiz",
            "Gesprächsnotiz erneut aktivieren",
        )
        restored = self.cdp.wait_for(
            medium_state_expression(),
            "Erneut aktivierte Gesprächsnotiz stellt die Auswahl nicht her",
        )
        self._truth(
            isinstance(restored, dict)
            and restored.get("checkboxChecked") is True
            and restored.get("disabled") == [False] * 5
            and restored.get("required") == [True] * 5
            and restored.get("checked") == ["Me"]
            and restored.get("statusText")
                == "Ausgewählt: Kurier/Melder.",
            "Erneutes Aktivieren bewahrt das Gesprächsmedium nicht: "
            f"{restored!r}",
        )

    def _assert_dirty_navigation_guard(self) -> None:
        field_selector = (
            'form[name="4fach"][data-estab-dirty-guard] '
            'input#f_12_betreff'
        )
        dirty_value = "Browser-Dirty-Guard-Test"
        self.cdp.click(
            "vorgaben",
            'button[name="stab_schreiben_x"][data-estab-workflow-key="stab_schreiben"]',
            "fachliches Formular zum Schreiben einer Nachricht",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                f"""
                const form = doc.querySelector(
                    'form[name="4fach"][data-estab-dirty-guard]'
                );
                const field = doc.querySelector({json.dumps(field_selector)});
                return target.location.pathname.endsWith("/4fach/mainindex.php") &&
                    doc.readyState === "complete" &&
                    Boolean(form && field && !field.disabled &&
                        field.getAttribute("type") !== "hidden");
                """,
            ),
            "fachliches Nachrichtenformular wurde nicht im Inhaltsframe geöffnet",
        )
        official_form_document = self._assert_official_message_form()
        self._assert_conversation_medium_toggle()
        self.cdp.set_value(
            "mainframe",
            field_selector,
            dirty_value,
            "ungespeicherter Betreff",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                f"""
                const field = doc.querySelector({json.dumps(field_selector)});
                return Boolean(field &&
                    field.value === {json.dumps(dirty_value)} &&
                    field.defaultValue !== field.value);
                """,
            ),
            "fachliches Formularfeld wurde nicht als geändert erkannt",
        )

        def click_overview_and_handle_dialog(accept: bool, action: str) -> None:
            dialog = self.cdp.click(
                "vorgaben",
                '[data-estab-navigation] a[data-estab-nav-key="overview"]',
                f"Übersichtslink zum {action} des Bereichswechsels",
                dialog_accept=accept,
            )
            dialog_is_confirm = (
                isinstance(dialog, dict) and dialog.get("type") == "confirm"
            )
            dialog_has_expected_copy = isinstance(dialog, dict) and str(
                dialog.get("message", "")
            ).startswith(
                "Ungespeicherte Eingaben gehen beim Bereichswechsel verloren."
            )
            self._truth(
                dialog_is_confirm and dialog_has_expected_copy,
                "Der Dirty-Guard hat keinen erwarteten nativen Bestätigungsdialog geöffnet.",
            )

        click_overview_and_handle_dialog(False, "Ablehnen")
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                f"""
                const form = doc.querySelector(
                    'form[name="4fach"][data-estab-dirty-guard]'
                );
                const field = doc.querySelector({json.dumps(field_selector)});
                return target.top.location.pathname.endsWith("/4fach/index.php") &&
                    target.location.pathname.endsWith("/4fach/mainindex.php") &&
                    Boolean(form && field &&
                        field.value === {json.dumps(dirty_value)} &&
                        field.defaultValue !== field.value);
                """,
            ),
            "abgelehnter Bereichswechsel hat Seite oder Formularwert nicht bewahrt",
        )

        click_overview_and_handle_dialog(True, "Bestätigen")
        self._assert_official_message_form_print(official_form_document)

    def _assert_message_timeline_layout(
        self,
        *,
        mobile: bool,
        description: str,
    ) -> None:
        expected_mobile = str(mobile).lower()
        state = self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                f"""
                const expectedMobile = {expected_mobile};
                const timeline = doc.querySelector(
                    "section.estab-message-timeline" +
                    "[data-estab-message-timeline]"
                );
                const track = timeline?.querySelector(
                    ":scope > ol.estab-message-timeline__track"
                );
                const officialForm = doc.querySelector(
                    "[data-estab-official-message-form]"
                );
                const page = timeline?.closest(
                    ".estab-message-form-page"
                );
                if (
                    doc.readyState !== "complete" ||
                    !timeline || !track || !officialForm || !page
                ) return false;

                const timelineStyle = target.getComputedStyle(timeline);
                const trackStyle = target.getComputedStyle(track);
                const expectedDisplay = expectedMobile ? "grid" : "flex";
                if (trackStyle.display !== expectedDisplay) return false;

                const steps = Array.from(
                    track.querySelectorAll(
                        ":scope > li.estab-message-timeline__step"
                    )
                );
                if (steps.length < 2) return false;
                const rects = steps.map(step => step.getBoundingClientRect());
                const timelineRect = timeline.getBoundingClientRect();
                const formRect = officialForm.getBoundingClientRect();
                const pageStyle = target.getComputedStyle(page);
                const pageContentWidth = page.clientWidth
                    - parseFloat(pageStyle.paddingLeft || "0")
                    - parseFloat(pageStyle.paddingRight || "0");
                const current = steps.filter(step =>
                    step.getAttribute("aria-current") === "step"
                    && step.getAttribute("data-estab-timeline-state")
                        === "current"
                    && step.classList.contains(
                        "estab-message-timeline__step--current"
                    )
                );
                const stateLabels = steps.map(step =>
                    step.querySelector(".estab-message-timeline__state")
                );
                const stateSemantics = steps.every((step, index) => {{
                    const stateName = step.getAttribute(
                        "data-estab-timeline-state"
                    );
                    const stateLabel = stateLabels[index];
                    if (
                        !["completed", "current", "pending"].includes(
                            stateName
                        ) ||
                        !step.classList.contains(
                            "estab-message-timeline__step--" + stateName
                        ) ||
                        !stateLabel?.textContent.trim()
                    ) return false;
                    const symbol = target.getComputedStyle(
                        stateLabel,
                        "::before"
                    ).content;
                    return !["", "none", "normal", '\"\"'].includes(
                        symbol
                    );
                }});
                const overlapPairs = [];
                for (let left = 0; left < rects.length; left += 1) {{
                    for (
                        let right = left + 1;
                        right < rects.length;
                        right += 1
                    ) {{
                        const horizontalOverlap =
                            Math.min(rects[left].right, rects[right].right)
                            - Math.max(rects[left].left, rects[right].left);
                        const verticalOverlap =
                            Math.min(rects[left].bottom, rects[right].bottom)
                            - Math.max(rects[left].top, rects[right].top);
                        if (
                            horizontalOverlap > 0.5
                            && verticalOverlap > 0.5
                        ) overlapPairs.push([left, right]);
                    }}
                }}
                const horizontalOrder = rects.every((rect, index) =>
                    index === 0 || (
                        Math.abs(rect.top - rects[0].top) <= 1
                        && rect.left >= rects[index - 1].right - 1
                    )
                );
                const verticalOrder = rects.every((rect, index) =>
                    index === 0 || (
                        Math.abs(rect.left - rects[0].left) <= 1
                        && Math.abs(rect.width - rects[0].width) <= 1
                        && rect.top >= rects[index - 1].bottom - 1
                    )
                );
                const returnSteps = steps.filter(step =>
                    step.hasAttribute("data-estab-timeline-return")
                );
                const stationCounts = new Map();
                steps.forEach(step => {{
                    const station = step.getAttribute(
                        "data-estab-timeline-station"
                    );
                    stationCounts.set(
                        station,
                        (stationCounts.get(station) || 0) + 1
                    );
                }});
                const returnEvidenceValid = returnSteps.length === 0 || (
                    returnSteps.every(step => {{
                        const reason = step.querySelector(
                            ".estab-message-timeline__reason"
                        );
                        return step.classList.contains(
                            "estab-message-timeline__step--returned"
                        ) && Boolean(
                            reason?.textContent.includes("Grund:")
                        );
                    }})
                    && Array.from(stationCounts.values()).some(
                        count => count > 1
                    )
                );
                const headingId = timeline.getAttribute("aria-labelledby");
                const heading = headingId
                    ? doc.getElementById(headingId)
                    : null;
                const precedesForm = Boolean(
                    timeline.compareDocumentPosition(officialForm)
                    & target.Node.DOCUMENT_POSITION_FOLLOWING
                );
                return {{
                    timelineVisible: timelineStyle.display !== "none"
                        && timelineStyle.visibility !== "hidden"
                        && timelineRect.width > 0
                        && timelineRect.height > 0,
                    aboveForm: precedesForm
                        && timelineRect.bottom <= formRect.top + 1,
                    fullWidth: Math.abs(
                        timelineRect.width - pageContentWidth
                    ) <= 2,
                    semanticStructure: timeline.tagName === "SECTION"
                        && track.tagName === "OL"
                        && track.tabIndex === 0
                        && Boolean(track.getAttribute("aria-label"))
                        && Boolean(heading?.textContent.trim())
                        && steps.length === track.children.length,
                    stepCount: steps.length,
                    currentCount: current.length,
                    stateSemantics,
                    overlapPairs,
                    horizontalOrder,
                    verticalOrder,
                    trackDisplay: trackStyle.display,
                    overflowX: trackStyle.overflowX,
                    overflowY: trackStyle.overflowY,
                    maxHeight: trackStyle.maxHeight,
                    trackClientWidth: track.clientWidth,
                    trackScrollWidth: track.scrollWidth,
                    trackClientHeight: track.clientHeight,
                    trackScrollHeight: track.scrollHeight,
                    noInternalVerticalScroller:
                        track.scrollHeight <= track.clientHeight + 1,
                    noInternalHorizontalScroller:
                        track.scrollWidth <= track.clientWidth + 1,
                    fitsMobileViewport:
                        doc.body.scrollWidth
                            <= doc.documentElement.clientWidth + 1
                        && timelineRect.left >= -0.5
                        && timelineRect.right
                            <= doc.documentElement.clientWidth + 0.5,
                    returnCount: returnSteps.length,
                    returnEvidenceValid
                }};
                """,
            ),
            f"Stationsleiste in {description} wurde nicht aufgebaut",
        )
        common_valid = (
            isinstance(state, dict)
            and state.get("timelineVisible") is True
            and state.get("aboveForm") is True
            and state.get("fullWidth") is True
            and state.get("semanticStructure") is True
            and state.get("stepCount", 0) >= 2
            and state.get("currentCount") == 1
            and state.get("stateSemantics") is True
            and state.get("overlapPairs") == []
            and state.get("returnEvidenceValid") is True
        )
        if mobile:
            layout_valid = (
                state.get("trackDisplay") == "grid"
                and state.get("verticalOrder") is True
                and state.get("overflowX") == "visible"
                and state.get("overflowY") == "visible"
                and state.get("maxHeight") == "none"
                and state.get("noInternalVerticalScroller") is True
                and state.get("noInternalHorizontalScroller") is True
                and state.get("fitsMobileViewport") is True
            )
        else:
            layout_valid = (
                state.get("trackDisplay") == "flex"
                and state.get("horizontalOrder") is True
                and state.get("overflowX") in {"auto", "scroll"}
                and state.get("overflowY") == "hidden"
                and state.get("maxHeight") == "none"
                and state.get("noInternalVerticalScroller") is True
                and state.get("trackScrollWidth", 0)
                    >= state.get("trackClientWidth", 0)
            )
        self._truth(
            common_valid and layout_valid,
            "Stationsleiste ist nicht sichtbar oberhalb des Vordrucks oder "
            f"nicht überlappungsfrei responsiv ({description}): "
            + json.dumps(state, ensure_ascii=False, sort_keys=True),
        )

    def _assert_official_message_form(self) -> str:
        self._assert_message_timeline_layout(
            mobile=False,
            description="neuem Nachrichtenvordruck bei 1440 px",
        )
        desktop_state = self.cdp.evaluate(
            _frame_expression(
                "mainframe",
                """
                const form = doc.querySelector(
                    "[data-estab-official-message-form]"
                );
                const zones = Array.from(
                    doc.querySelectorAll("[data-estab-form-zone]")
                );
                const buttons = Array.from(
                    doc.querySelectorAll("[data-estab-form-help]")
                );
                const dialogs = Array.from(
                    doc.querySelectorAll(".estab-official-help-dialog")
                );
                const content = doc.querySelector(
                    '[data-estab-form-zone="nachricht"]'
                );
                const fmGrid = doc.querySelector(".estab-official-fmz-grid");
                const reviewGrid = doc.querySelector(
                    ".estab-official-review-grid"
                );
                const copyLegend = doc.querySelector(
                    "[data-estab-copy-distribution]"
                );
                const punchHoles = Array.from(
                    doc.querySelectorAll("[data-estab-punch-hole]")
                );
                const addressValue = doc.querySelector(
                    ".estab-official-address-value"
                );
                const phoneValue = doc.querySelector(
                    ".estab-official-phone-value"
                );
                const ttb = doc.querySelector(".estab-official-ttb");
                const callsign = doc.querySelector(
                    ".estab-official-callsign"
                );
                const callsignLabel = callsign?.querySelector(
                    ":scope > .estab-official-cell-heading"
                );
                const addressBlock = doc.querySelector(
                    ".estab-official-address-block"
                );
                const addressLabel = doc.querySelector(
                    ".estab-official-address-label"
                );
                const addressValueColumn = doc.querySelector(
                    ".estab-official-address-value"
                );
                const conversationColumn = doc.querySelector(
                    ".estab-official-conversation"
                );
                const composition = doc.querySelector(
                    ".estab-official-composition"
                );
                const compositionLabel = doc.querySelector(
                    ".estab-official-composition-label"
                );
                const compositionValue = doc.querySelector(
                    ".estab-official-composition-value"
                );
                const typePriority = doc.querySelector(
                    ".estab-official-type-priority"
                );
                const typeField = doc.querySelector(
                    ".estab-official-type"
                );
                const priorityField = doc.querySelector(
                    ".estab-official-priority"
                );
                const priorityGroup = priorityField?.querySelector(
                    ".estab-official-priority-choices"
                );
                const priorityDefinitions = [
                    ["sofort", "sss", "Sofort"],
                    ["blitz", "bbb", "Blitz"],
                    ["staatsnot", "aaa", "Staatsnot"]
                ];
                const priorityControls = priorityDefinitions.map(
                    ([id]) => doc.querySelector(
                        "#f_09_vorrangstufe_" + id
                    )
                );
                const priorityLabels = priorityControls.map(control =>
                    control?.closest("label") || null
                );
                const priorityClear = doc.querySelector(
                    "#f_09_vorrangstufe_keine"
                );
                const priorityClearLabel = priorityClear?.closest("label")
                    || null;
                const extraDistribution = doc.querySelector(
                    ".estab-message-distribution-extras"
                );
                const leadRecipients = Array.from(
                    doc.querySelectorAll(
                        ".estab-official-distribution-grid > section:first-child "
                        + ".estab-official-recipient > span:last-child"
                    )
                );
                const leadDirector = doc.querySelectorAll(
                    ".estab-official-lead-director "
                    + "> .estab-official-recipient"
                );
                const leadSections = doc.querySelectorAll(
                    ".estab-official-lead-sections "
                    + "> .estab-official-recipient"
                );
                const adviserRows = doc.querySelectorAll(
                    '[data-estab-recipient-group="adviser"] '
                    + "> .estab-official-recipient"
                );
                const liaisonRows = doc.querySelectorAll(
                    '[data-estab-recipient-group="liaison"] '
                    + "> .estab-official-recipient"
                );
                const extraRecipients = extraDistribution
                    ? Array.from(
                        extraDistribution.querySelectorAll(
                            ".estab-official-recipient > span:last-child"
                        )
                    )
                    : [];
                const externalGreenControls = Array.from(
                    doc.querySelectorAll(
                        'input[name="16_gncopy"], '
                        + '.estab-message-green-copy'
                    )
                );
                const readonlyCopyControls = Array.from(
                    doc.querySelectorAll(
                        ".estab-official-copy-indicator"
                    )
                );
                const activeOfficialRecipients = Array.from(
                    doc.querySelectorAll(
                        ".estab-official-distribution-grid "
                        + ".estab-official-recipient"
                        + ":not([data-estab-recipient-unavailable])"
                    )
                );
                const stamps = Array.from(
                    doc.querySelectorAll(".estab-official-stamp")
                );
                const ratio = (element, container) => {
                    if (!element || !container) return 0;
                    const containerWidth =
                        container.getBoundingClientRect().width;
                    return containerWidth > 0
                        ? element.getBoundingClientRect().width / containerWidth
                        : 0;
                };
                const compositionRect = composition?.getBoundingClientRect();
                const compositionValueRect =
                    compositionValue?.getBoundingClientRect();
                const priorityRect = priorityField?.getBoundingClientRect();
                const priorityHelpRect = priorityField?.querySelector(
                    ".estab-official-help-anchor"
                )?.getBoundingClientRect();
                const visiblePriorityLabels = [
                    priorityClearLabel,
                    ...priorityLabels
                ].filter(Boolean);
                const priorityLabelRects = visiblePriorityLabels.map(
                    label => label.getBoundingClientRect()
                );
                const priorityBoxesSquare = [
                    priorityClear,
                    ...priorityControls
                ].every(control => {
                    if (!control) return false;
                    const style = target.getComputedStyle(control);
                    const rect = control.getBoundingClientRect();
                    return control.type === "radio"
                        && style.appearance === "none"
                        && style.borderRadius === "0px"
                        && Math.abs(rect.width - rect.height) <= 0.5
                        && rect.width >= 17
                        && rect.width <= 18.5;
                });
                const staatsnotDescriptionId = priorityControls[2]
                    ?.getAttribute("aria-describedby") || "";
                const staatsnotDescription = staatsnotDescriptionId
                    ? doc.getElementById(staatsnotDescriptionId)
                    : null;
                const priorityGeometry = Boolean(
                    priorityRect
                    && priorityLabelRects.length === 4
                    && priorityLabelRects.every(rect =>
                        rect.left >= priorityRect.left - 1
                        && rect.right <= priorityRect.right + 1
                        && rect.top >= priorityRect.top - 1
                        && rect.bottom <= priorityRect.bottom + 1
                        && (
                            !priorityHelpRect
                            || rect.right <= priorityHelpRect.left - 1
                        )
                    )
                    && priorityLabelRects.every(
                        (rect, index) => index === 0
                            || rect.left
                                >= priorityLabelRects[index - 1].right - 1
                    )
                );
                const stampGeometry = stamps.length === 3
                    && stamps.every(stamp => {
                        const dateCell = stamp.querySelector(
                            '[data-estab-stamp-cell="datum"]'
                        );
                        const timeCell = stamp.querySelector(
                            '[data-estab-stamp-cell="uhrzeit"]'
                        );
                        const markCell = stamp.querySelector(
                            '[data-estab-stamp-cell="hdz"]'
                        );
                        const backend = stamp.querySelector(
                            "[data-estab-single-backend-field]"
                        );
                        if (!dateCell || !timeCell || !markCell || !backend) {
                            return false;
                        }
                        const fieldName = backend.getAttribute(
                            "data-estab-single-backend-field"
                        );
                        const control = fieldName
                            ? doc.getElementById("f_" + fieldName)
                            : null;
                        const describedBy = control?.getAttribute(
                            "aria-describedby"
                        ) || backend.getAttribute("aria-describedby");
                        const description = describedBy
                            ? doc.getElementById(describedBy)
                            : null;
                        const dateRect = dateCell.getBoundingClientRect();
                        const timeRect = timeCell.getBoundingClientRect();
                        const markRect = markCell.getBoundingClientRect();
                        const timeStyle = target.getComputedStyle(timeCell);
                        const markStyle = target.getComputedStyle(markCell);
                        return Boolean(
                            fieldName
                            && control
                            && description?.textContent.includes(
                                "Ein gemeinsames Eingabefeld"
                            )
                            && doc.querySelectorAll(
                                '[name="' + fieldName + '"]'
                            ).length <= 1
                            && !doc.querySelector(
                                '[name="' + fieldName + '_datum"],'
                                + '[name="' + fieldName + '_uhrzeit"]'
                            )
                            && Math.abs(dateRect.right - timeRect.left) <= 1
                            && Math.abs(timeRect.right - markRect.left) <= 1
                            && dateRect.width > 20
                            && timeRect.width > 20
                            && markRect.width > 20
                            && parseFloat(timeStyle.borderLeftWidth) >= 1
                            && parseFloat(markStyle.borderLeftWidth) >= 1
                        );
                    });
                const stampWidths = stamps.map(stamp =>
                    stamp.getBoundingClientRect().width
                );
                const stampWidthRatios = stampWidths.length === 3
                    && stampWidths[0] > 0
                    ? stampWidths.map(width => width / stampWidths[0])
                    : [];
                const stampCellRatios = stamps.map(stamp => {
                    const cells = ["datum", "uhrzeit", "hdz"].map(name =>
                        stamp.querySelector(
                            '[data-estab-stamp-cell="' + name + '"]'
                        )?.getBoundingClientRect().width || 0
                    );
                    const total = cells.reduce((sum, width) => sum + width, 0);
                    return total > 0
                        ? cells.map(width => width / total)
                        : [];
                });
                const helpNumbers = buttons.map(button => Number(
                    button.getAttribute("data-estab-form-help")
                ));
                const sortedHelpNumbers = helpNumbers.slice().sort(
                    (left, right) => left - right
                );
                const allHelpWorks = buttons.length === 20
                    && dialogs.length === 20
                    && new Set(helpNumbers).size === 20
                    && sortedHelpNumbers.every(
                        (number, index) => number === index + 1
                    )
                    && buttons.every(button => {
                        const dialog = doc.getElementById(
                            button.getAttribute("aria-controls")
                        );
                        if (!dialog) return false;
                        button.click();
                        const visible = !dialog.hidden
                            && button.getAttribute("aria-expanded") === "true"
                            && doc.activeElement === dialog
                            && dialogs.filter(item => !item.hidden).length === 1
                            && Boolean(
                                doc.getElementById(
                                    dialog.getAttribute("aria-labelledby")
                                )?.textContent.trim()
                            )
                            && Boolean(
                                doc.getElementById(
                                    dialog.getAttribute("aria-describedby")
                                )?.textContent.trim()
                            );
                        button.click();
                        return visible
                            && dialog.hidden
                            && button.getAttribute("aria-expanded") === "false"
                            && doc.activeElement === button;
                    });
                /*
                 * Opening every help dialog deliberately moves browser focus.
                 * Chrome may scroll the document while doing so, therefore all
                 * position-dependent rectangles must be captured afterwards.
                 */
                const formRect = form && form.getBoundingClientRect();
                const contentRect = content && content.getBoundingClientRect();
                const legendRect = copyLegend
                    && copyLegend.getBoundingClientRect();
                const addressRect = addressValue
                    ? addressValue.getBoundingClientRect()
                    : null;
                const phoneRect = phoneValue
                    ? phoneValue.getBoundingClientRect()
                    : null;
                const contentGutterStyle = content
                    ? target.getComputedStyle(content, "::before")
                    : null;
                const contentStyle = content
                    ? target.getComputedStyle(content)
                    : null;
                return {
                    zones: zones.map(zone =>
                        zone.getAttribute("data-estab-form-zone")
                    ),
                    helpButtons: buttons.length,
                    dialogs: dialogs.length,
                    allHelpWorks,
                    formWidth: formRect ? formRect.width : 0,
                    formMinWidth: form
                        ? target.getComputedStyle(form).minWidth
                        : "",
                    contentBackground: content
                        ? target.getComputedStyle(content).backgroundColor
                        : "",
                    contentBorder: content
                        ? target.getComputedStyle(content).borderLeftColor
                        : "",
                    contentGutterBackground: contentGutterStyle
                        ? contentGutterStyle.backgroundColor
                        : "",
                    contentGutterWidth: contentGutterStyle
                        ? contentGutterStyle.width
                        : "",
                    referenceProportions: {
                        ttb: ratio(ttb, fmGrid),
                        callsignLabel: ratio(callsignLabel, callsign),
                        addressLabel: ratio(addressLabel, addressBlock),
                        addressValue: ratio(
                            addressValueColumn,
                            addressBlock
                        ),
                        conversation: ratio(
                            conversationColumn,
                            addressBlock
                        ),
                        compositionLabel: ratio(
                            compositionLabel,
                            composition
                        ),
                        compositionValue: ratio(
                            compositionValue,
                            composition
                        ),
                        compositionBlank:
                            compositionRect && compositionValueRect
                                ? (
                                    compositionRect.right
                                    - compositionValueRect.right
                                ) / compositionRect.width
                                : 0,
                        type: ratio(typeField, typePriority),
                        priority: ratio(priorityField, typePriority)
                    },
                    priorityLabels: priorityLabels.map(label =>
                        label?.textContent.replace(/\\s+/g, " ").trim() || ""
                    ),
                    priorityValues: priorityControls.map(
                        control => control?.value || ""
                    ),
                    priorityInsideOfficialField: Boolean(
                        form
                        && priorityField
                        && priorityGroup
                        && form.contains(priorityField)
                        && priorityControls.every(control =>
                            Boolean(
                                control
                                && priorityField.contains(control)
                                && control.name === "09_vorrangstufe"
                            )
                        )
                        && priorityClear
                        && priorityField.contains(priorityClear)
                        && priorityClear.name === "09_vorrangstufe"
                        && priorityClear.checked
                        && Array.from(
                            doc.querySelectorAll(
                                '[name="09_vorrangstufe"]'
                            )
                        ).every(control => priorityField.contains(control))
                    ),
                    staatsnotWarning: priorityControls[2]?.getAttribute(
                        "title"
                    ) || "",
                    staatsnotDescription:
                        staatsnotDescription?.textContent || "",
                    priorityBoxesSquare,
                    priorityGeometry,
                    noExternalPriority: !doc.querySelector(
                        ".estab-message-priority-extension"
                    ),
                    noVisibleTransportHint: Boolean(
                        !doc.querySelector(
                            ".estab-message-legacy-transport, "
                            + "#f_08_befhinweis, "
                            + '[id^="f_08_befhinwausw_"]'
                        )
                        && !Array.from(
                            doc.querySelectorAll("label, legend")
                        ).some(element => element.textContent.includes(
                            "Zusätzlicher Beförderungshinweis"
                        ))
                    ),
                    addressPhoneNonOverlap: Boolean(
                        addressRect && phoneRect
                        && Math.abs(addressRect.left - phoneRect.left) <= 1
                        && Math.abs(addressRect.right - phoneRect.right) <= 1
                        && addressRect.bottom <= phoneRect.top + 1
                    ),
                    alignedGridEdges: Boolean(
                        fmGrid && content && reviewGrid
                        && Math.abs(
                            fmGrid.getBoundingClientRect().left
                                - content.getBoundingClientRect().left
                        ) <= 1
                        && Math.abs(
                            reviewGrid.getBoundingClientRect().left
                                - content.getBoundingClientRect().left
                        ) <= 1
                    ),
                    stampGeometry,
                    stampWidthRatios,
                    stampCellRatios,
                    copyLegend: copyLegend
                        ? Array.from(
                            copyLegend.querySelectorAll(
                                "[data-estab-copy-sheet]"
                            )
                        ).map(item => item.textContent.replace(/\\s+/g, " ").trim())
                        : [],
                    legendInsideLeftStrip: Boolean(
                        formRect && contentRect && legendRect
                        && legendRect.left >= formRect.left - 1
                        && legendRect.right <= contentRect.left
                            + parseFloat(
                                contentStyle?.borderLeftWidth || "0"
                            )
                            + 1
                        && legendRect.width >= 80
                        && legendRect.width <= 86
                    ),
                    legendGeometry: formRect && contentRect && legendRect
                        ? {
                            formLeft: formRect.left,
                            contentLeft: contentRect.left,
                            legendLeft: legendRect.left,
                            legendRight: legendRect.right,
                            legendWidth: legendRect.width
                        }
                        : null,
                    punchHoleRatios: contentRect
                        ? punchHoles.map(hole => {
                            const rect = hole.getBoundingClientRect();
                            return {
                                x: (rect.left + rect.width / 2 - formRect.left)
                                    / formRect.width,
                                y: (rect.top + rect.height / 2 - contentRect.top)
                                    / contentRect.height,
                                diameter: rect.width,
                                round: target.getComputedStyle(hole).borderRadius,
                                background: target.getComputedStyle(
                                    hole
                                ).backgroundColor,
                                visible: target.getComputedStyle(hole).display
                                    !== "none"
                                    && target.getComputedStyle(hole).visibility
                                    === "visible"
                                    && target.getComputedStyle(hole).opacity
                                    !== "0"
                                    && Number(
                                        target.getComputedStyle(hole).zIndex
                                    ) >= 4
                            };
                        })
                        : [],
                    leadRecipients: leadRecipients.map(
                        item => item.textContent.trim()
                    ),
                    fixedDistributorGeometry: leadDirector.length === 1
                        && leadSections.length === 6
                        && adviserRows.length === 6
                        && liaisonRows.length === 6,
                    extraRecipients: extraRecipients.map(
                        item => item.textContent.trim()
                    ),
                    extrasOutsideOfficialSheet: Boolean(
                        !extraDistribution
                        || (form && !form.contains(extraDistribution))
                    ),
                    extraControlsPersisted: Boolean(
                        !extraDistribution
                        || (
                            extraDistribution.querySelector(
                                ".estab-official-box-choice"
                            )
                            && Array.from(
                                extraDistribution.querySelectorAll(
                                    ".estab-official-box-choice"
                                )
                            ).every(control =>
                                Boolean(
                                    control.getAttribute("aria-label")
                                    && (control.disabled || control.name)
                                )
                            )
                        )
                    ),
                    noExternalGreenChoice:
                        externalGreenControls.length === 0,
                    readonlyCopyControlsLabeled:
                        activeOfficialRecipients.length > 0
                        && activeOfficialRecipients.every(recipient =>
                            Boolean(
                                recipient.querySelector(
                                    ":scope > .estab-official-copy-indicator"
                                )
                            )
                        )
                        && readonlyCopyControls.every(control =>
                            control.disabled
                            && Boolean(control.getAttribute("aria-label"))
                            && control.getAttribute("aria-label").includes(
                                "schreibgeschützt"
                            )
                        ),
                    timeOnlyStampCount: stamps.filter(stamp =>
                        stamp.querySelector(
                            '[data-estab-stamp-time-only="true"]'
                        )
                    ).length,
                    requiredGuideText: {
                        two: doc.querySelector(
                            "#estab-form-help-2-description"
                        )?.textContent || "",
                        four: doc.querySelector(
                            "#estab-form-help-4-description"
                        )?.textContent || "",
                        fourteen: doc.querySelector(
                            "#estab-form-help-14-description"
                        )?.textContent || ""
                    },
                    noImages: Boolean(form && !form.querySelector("img"))
                };
                """,
            )
        )
        reference_proportions = (
            desktop_state.get("referenceProportions", {})
            if isinstance(desktop_state, dict)
            else {}
        )
        self._truth(
            isinstance(desktop_state, dict)
            and desktop_state.get("zones")
                == ["fm-zentrale", "nachricht", "sichter"]
            and desktop_state.get("helpButtons") == 20
            and desktop_state.get("dialogs") == 20
            and desktop_state.get("allHelpWorks") is True
            and 895 <= desktop_state.get("formWidth", 0) <= 897
            and desktop_state.get("formMinWidth") == "896px"
            and desktop_state.get("contentBackground")
                == "rgb(162, 217, 247)"
            and desktop_state.get("contentBorder") == "rgb(0, 0, 0)"
            and desktop_state.get("contentGutterBackground")
                == "rgb(162, 217, 247)"
            and desktop_state.get("contentGutterWidth") == "84px"
            and abs(reference_proportions.get("ttb", 0) - 0.20) <= 0.005
            and abs(
                reference_proportions.get("callsignLabel", 0) - 0.33
            ) <= 0.005
            and abs(
                reference_proportions.get("addressLabel", 0) - 0.198
            ) <= 0.005
            and abs(
                reference_proportions.get("addressValue", 0) - 0.603
            ) <= 0.005
            and abs(
                reference_proportions.get("conversation", 0) - 0.199
            ) <= 0.005
            and abs(
                reference_proportions.get("compositionLabel", 0) - 0.198
            ) <= 0.005
            and abs(
                reference_proportions.get("compositionValue", 0) - 0.409
            ) <= 0.005
            and abs(
                reference_proportions.get("compositionBlank", 0) - 0.393
            ) <= 0.005
            and abs(reference_proportions.get("type", 0) - 0.61) <= 0.005
            and abs(
                reference_proportions.get("priority", 0) - 0.39
            ) <= 0.005
            and desktop_state.get("priorityLabels")
                == ["Sofort", "Blitz", "Staatsnot"]
            and desktop_state.get("priorityValues")
                == ["sss", "bbb", "aaa"]
            and desktop_state.get("priorityInsideOfficialField") is True
            and "ausdrückliche Weisung"
                in desktop_state.get("staatsnotWarning", "")
            and "ausdrückliche Weisung"
                in desktop_state.get("staatsnotDescription", "")
            and desktop_state.get("priorityBoxesSquare") is True
            and desktop_state.get("priorityGeometry") is True
            and desktop_state.get("noExternalPriority") is True
            and desktop_state.get("noVisibleTransportHint") is True
            and desktop_state.get("addressPhoneNonOverlap") is True
            and desktop_state.get("alignedGridEdges") is True
            and desktop_state.get("stampGeometry") is True
            and len(desktop_state.get("stampWidthRatios", [])) == 3
            and abs(desktop_state["stampWidthRatios"][0] - 1.0) <= 0.02
            and abs(desktop_state["stampWidthRatios"][1] - 1.016) <= 0.03
            and abs(desktop_state["stampWidthRatios"][2] - 1.07) <= 0.03
            and len(desktop_state.get("stampCellRatios", [])) == 3
            and all(
                len(ratios) == 3
                and 0.35 <= ratios[0] <= 0.40
                and 0.35 <= ratios[1] <= 0.40
                and 0.22 <= ratios[2] <= 0.28
                for ratios in desktop_state.get("stampCellRatios", [])
            )
            and desktop_state.get("copyLegend")
                == [
                    "Blatt 1 (blau) Sachgebiet/Fachber./Verbindungsstelle",
                    "Blatt 2 (grün) Sachgebiet/Fachber./Verbindungsstelle",
                    "Blatt 3 (rot) Sachgebiet 2 Lage",
                    "Blatt 4 (gelb) Techn. Betriebsbuch",
                ]
            and desktop_state.get("legendInsideLeftStrip") is True
            and len(desktop_state.get("punchHoleRatios", [])) == 2
            and all(
                31 <= hole.get("diameter", 0) <= 33
                and hole.get("round") in {"16px", "50%"}
                and 0 <= hole.get("x", -1) <= 0.095
                and hole.get("background") == "rgb(255, 255, 255)"
                and hole.get("visible") is True
                for hole in desktop_state.get("punchHoleRatios", [])
            )
            and 0.18 <= desktop_state["punchHoleRatios"][0].get("y", 0) <= 0.20
            and 0.81 <= desktop_state["punchHoleRatios"][1].get("y", 0) <= 0.83
            and "Datum mindestens zweistellig"
                in desktop_state.get("requiredGuideText", {}).get("two", "")
            and "Datum mindestens zweistellig"
                in desktop_state.get("requiredGuideText", {}).get("four", "")
            and "Blockschrift"
                in desktop_state.get("requiredGuideText", {}).get("fourteen", "")
            and desktop_state.get("leadRecipients")
                == ["Leiter", "S1", "S2", "S3", "S4", "S5", "S6"]
            and "S5" not in desktop_state.get("extraRecipients", [])
            and desktop_state.get("fixedDistributorGeometry") is True
            and desktop_state.get("extrasOutsideOfficialSheet") is True
            and desktop_state.get("extraControlsPersisted") is True
            and desktop_state.get("noExternalGreenChoice") is True
            and desktop_state.get("readonlyCopyControlsLabeled") is True
            and desktop_state.get("timeOnlyStampCount") == 1
            and desktop_state.get("noImages") is True,
            "Amtliches Dreizonen-Raster, Blauton oder die 20 Hilfen "
            "weichen im echten Browser ab. Messwerte: "
            + json.dumps(
                desktop_state,
                ensure_ascii=False,
                sort_keys=True,
            ),
        )

        self.cdp.click(
            "mainframe",
            "#f_09_vorrangstufe_staatsnot",
            "Staatsnot im amtlichen Vorrangfeld",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const field = doc.querySelector(
                    ".estab-official-priority"
                );
                const staatsnot = doc.querySelector(
                    "#f_09_vorrangstufe_staatsnot"
                );
                return Boolean(
                    field
                    && staatsnot
                    && field.contains(staatsnot)
                    && staatsnot.checked
                    && staatsnot.value === "aaa"
                    && doc.querySelectorAll(
                        '.estab-official-priority '
                        + 'input[name="09_vorrangstufe"]:checked'
                    ).length === 1
                );
                """,
            ),
            "Staatsnot wurde nicht im amtlichen Vorrangfeld angekreuzt",
        )
        self.cdp.click(
            "mainframe",
            "#f_09_vorrangstufe_keine",
            "Vorrang im amtlichen Feld zurücksetzen",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const clear = doc.querySelector(
                    "#f_09_vorrangstufe_keine"
                );
                return Boolean(
                    clear
                    && clear.checked
                    && clear.value === ""
                    && doc.querySelectorAll(
                        '.estab-official-priority '
                        + 'input[name="09_vorrangstufe"]:checked'
                    ).length === 1
                );
                """,
            ),
            "Vorrang ließ sich nicht im amtlichen Feld zurücksetzen",
        )

        self.cdp.click(
            "mainframe",
            '[data-estab-form-help="1"]',
            "erste Ausfüllhilfe des Nachrichtenvordrucks",
        )
        dialog_state = self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const button = doc.querySelector(
                    '[data-estab-form-help="1"]'
                );
                const dialog = button
                    ? doc.getElementById(button.getAttribute("aria-controls"))
                    : null;
                if (!button || !dialog || dialog.hidden) return false;
                const rect = dialog.getBoundingClientRect();
                return {
                    expanded: button.getAttribute("aria-expanded"),
                    focused: doc.activeElement === dialog,
                    visibleDialogs: Array.from(
                        doc.querySelectorAll(".estab-official-help-dialog")
                    ).filter(item => !item.hidden).length,
                    bounded: rect.left >= 0
                        && rect.top >= 0
                        && rect.right <= target.innerWidth
                        && rect.bottom <= target.innerHeight
                };
                """,
            ),
            "erste Ausfüllhilfe wurde nicht sichtbar geöffnet",
        )
        self._truth(
            dialog_state.get("expanded") == "true"
            and dialog_state.get("focused") is True
            and dialog_state.get("visibleDialogs") == 1
            and dialog_state.get("bounded") is True,
            "Ausfüllhilfe liegt außerhalb des sichtbaren Browserfensters.",
        )
        self.cdp.click(
            "mainframe",
            "#f_07_durchspruch_durchsage",
            "Nachrichtenform außerhalb der geöffneten Ausfüllhilfe",
        )
        self._truth(
            self.cdp.evaluate(
                _frame_expression(
                    "mainframe",
                    """
                    const button = doc.querySelector(
                        '[data-estab-form-help="1"]'
                    );
                    const dialog = button
                        ? doc.getElementById(
                            button.getAttribute("aria-controls")
                        )
                        : null;
                    return Boolean(
                        dialog
                        && dialog.hidden
                        && button.getAttribute("aria-expanded") === "false"
                        && doc.activeElement === doc.querySelector(
                            "#f_07_durchspruch_durchsage"
                        )
                    );
                    """,
                )
            ),
            "Außenklick schloss die Hilfe nicht am angeklickten Formularfeld.",
        )

        self.cdp.click(
            "mainframe",
            '[data-estab-form-help="1"]',
            "erste Ausfüllhilfe für Schließen-Schaltfläche",
        )
        self.cdp.click(
            "mainframe",
            '[data-estab-form-help-close="1"]',
            "Schließen-Schaltfläche der ersten Ausfüllhilfe",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const button = doc.querySelector(
                    '[data-estab-form-help="1"]'
                );
                const dialog = button
                    ? doc.getElementById(button.getAttribute("aria-controls"))
                    : null;
                return Boolean(
                    dialog
                    && dialog.hidden
                    && button.getAttribute("aria-expanded") === "false"
                    && doc.activeElement === button
                );
                """,
            ),
            "Schließen-Schaltfläche gab den Fokus nicht an die Ausfüllhilfe zurück",
        )

        self.cdp.click(
            "mainframe",
            '[data-estab-form-help="20"]',
            "letzte Ausfüllhilfe des Nachrichtenvordrucks",
        )
        self.cdp.call(
            "Input.dispatchKeyEvent",
            {
                "type": "keyDown",
                "key": "Escape",
                "code": "Escape",
                "windowsVirtualKeyCode": 27,
            },
        )
        self.cdp.call(
            "Input.dispatchKeyEvent",
            {
                "type": "keyUp",
                "key": "Escape",
                "code": "Escape",
                "windowsVirtualKeyCode": 27,
            },
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const button = doc.querySelector(
                    '[data-estab-form-help="20"]'
                );
                const dialog = button
                    ? doc.getElementById(button.getAttribute("aria-controls"))
                    : null;
                return Boolean(
                    dialog
                    && dialog.hidden
                    && button.getAttribute("aria-expanded") === "false"
                    && doc.activeElement === button
                );
                """,
            ),
            "Escape schloss die letzte Ausfüllhilfe nicht mit Fokusrückgabe",
        )

        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 390,
                "height": 844,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 390,
                "screenHeight": 844,
            },
        )
        self._assert_message_timeline_layout(
            mobile=True,
            description="neuem Nachrichtenvordruck bei 390 px",
        )
        mobile_state = self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const scroll = doc.querySelector(
                    ".estab-message-form-scroll"
                );
                const form = doc.querySelector(
                    "[data-estab-official-message-form]"
                );
                if (!scroll || !form) return false;
                return {
                    viewportWidth: doc.documentElement.clientWidth,
                    bodyScrollWidth: doc.body.scrollWidth,
                    scrollClientWidth: scroll.clientWidth,
                    scrollWidth: scroll.scrollWidth,
                    formWidth: form.getBoundingClientRect().width
                };
                """,
            ),
            "mobiles Nachrichtenvordruck-Raster wurde nicht aufgebaut",
        )
        self._truth(
            mobile_state.get("viewportWidth", 0) <= 390
            and mobile_state.get("bodyScrollWidth", 9999)
                <= mobile_state.get("viewportWidth", 0) + 1
            and mobile_state.get("scrollWidth", 0)
                > mobile_state.get("scrollClientWidth", 0)
            and 895 <= mobile_state.get("formWidth", 0) <= 897,
            "Das amtliche Raster bleibt mobil nicht in seinem beschrifteten "
            "Scrollbereich.",
        )
        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 1440,
                "height": 1000,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 1440,
                "screenHeight": 1000,
            },
        )
        self.cdp.click(
            "mainframe",
            "#f_09_vorrangstufe_staatsnot",
            "Staatsnot für den echten Drucknachweis",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const staatsnot = doc.querySelector(
                    "#f_09_vorrangstufe_staatsnot"
                );
                return Boolean(staatsnot && staatsnot.checked);
                """,
            ),
            "Staatsnot ist vor dem Drucknachweis nicht markiert",
        )
        print_document = self.cdp.evaluate(
            _frame_expression(
                "mainframe",
                """
                doc.querySelectorAll(
                    '.estab-official-priority input[type="radio"]'
                ).forEach(control => {
                    control.toggleAttribute("checked", control.checked);
                });
                return "<!doctype html>" + doc.documentElement.outerHTML;
                """,
            )
        )
        self._truth(
            isinstance(print_document, str)
            and "data-estab-official-message-form" in print_document,
            "Der echte Nachrichtenvordruck konnte nicht für den Drucknachweis gesichert werden.",
        )
        return print_document

    def _assert_official_message_form_print(self, print_document: str) -> None:
        self._wait_for_authenticated_overview(
            "angemeldete Übersicht fehlt vor dem Drucknachweis"
        )
        source = json.dumps(print_document)
        self._truth(
            self.cdp.evaluate(
                f"""
                (() => {{
                    document.open();
                    document.write({source});
                    document.close();
                    return true;
                }})()
                """
            )
            is True,
            "Der gesicherte Nachrichtenvordruck konnte nicht druckisoliert werden.",
        )
        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 390,
                "height": 844,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 390,
                "screenHeight": 844,
            },
        )
        self.cdp.call("Emulation.setEmulatedMedia", {"media": "print"})
        print_state = self.cdp.wait_for(
            """
            (() => {
                const sheet = document.querySelector(
                    "[data-estab-official-message-form]"
                );
                const scroll = document.querySelector(
                    ".estab-message-form-scroll"
                );
                const content = document.querySelector(
                    '[data-estab-form-zone="nachricht"]'
                );
                if (
                    document.readyState !== "complete"
                    || !sheet
                    || !scroll
                    || !content
                ) return false;
                const sheetStyle = getComputedStyle(sheet);
                const sheetRect = sheet.getBoundingClientRect();
                const pseudo = getComputedStyle(scroll, "::before");
                const contentBlue = getComputedStyle(content).backgroundColor;
                const priorityClear = document.querySelector(
                    ".estab-official-priority-clear"
                );
                const staatsnot = document.querySelector(
                    "#f_09_vorrangstufe_staatsnot"
                );
                if (
                    sheetStyle.zoom !== "0.78"
                    || contentBlue !== "rgb(162, 217, 247)"
                    || sheetRect.width < 695
                    || sheetRect.height <= 0
                ) return false;
                return {
                    x: sheetRect.left + window.scrollX,
                    y: sheetRect.top + window.scrollY,
                    width: sheetRect.width,
                    height: sheetRect.height,
                    zoom: sheetStyle.zoom,
                    breakInside: sheetStyle.breakInside,
                    pseudoContent: pseudo.content,
                    pseudoDisplay: pseudo.display,
                    priorityClearDisplay: priorityClear
                        ? getComputedStyle(priorityClear).display
                        : "missing",
                    staatsnotChecked: Boolean(staatsnot?.checked),
                    blue: contentBlue,
                    zones: document.querySelectorAll(
                        "[data-estab-form-zone]"
                    ).length
                };
            })()
            """,
            "Druck-CSS des echten Nachrichtenvordrucks wurde nicht berechnet",
        )
        self._truth(
            print_state.get("zones") == 3
            and print_state.get("blue") == "rgb(162, 217, 247)"
            and print_state.get("zoom") == "0.78"
            and print_state.get("breakInside") in {"avoid", "avoid-page"}
            and print_state.get("pseudoContent") in {"none", "normal"}
            and print_state.get("pseudoDisplay") == "none"
            and print_state.get("priorityClearDisplay") == "none"
            and print_state.get("staatsnotChecked") is True
            and 695 <= print_state.get("width", 0) <= 705
            and 0 < print_state.get("height", 9999) <= 1069.7,
            "Das echte Drucklayout passt nicht fragmentierungsfrei in den "
            "bedruckbaren A4-Bereich oder enthält den mobilen Wischhinweis.",
        )

        pdf_result = self.cdp.call(
            "Page.printToPDF",
            {
                "printBackground": True,
                "displayHeaderFooter": False,
                "preferCSSPageSize": True,
                "transferMode": "ReturnAsBase64",
            },
        )
        encoded_pdf = pdf_result.get("data")
        try:
            pdf_bytes = base64.b64decode(encoded_pdf, validate=True)
        except (TypeError, ValueError) as exc:
            raise TestFailure(
                "Chrome lieferte keinen gültigen PDF-Drucknachweis."
            ) from exc
        page_count = len(re.findall(rb"/Type\s*/Page\b", pdf_bytes))
        media_box = re.search(
            rb"/MediaBox\s*\[\s*([+-]?[0-9.]+)\s+([+-]?[0-9.]+)\s+"
            rb"([+-]?[0-9.]+)\s+([+-]?[0-9.]+)\s*\]",
            pdf_bytes,
        )
        media_width = 0.0
        media_height = 0.0
        if media_box is not None:
            media_width = float(media_box.group(3)) - float(media_box.group(1))
            media_height = float(media_box.group(4)) - float(media_box.group(2))
        self._truth(
            pdf_bytes.startswith(b"%PDF-")
            and len(pdf_bytes) > 5000
            and page_count == 1
            and 590 <= media_width <= 600
            and 837 <= media_height <= 846,
            "Chromes echter Drucknachweis ist nicht genau eine A4-Hochformatseite.",
        )

        screenshot_result = self.cdp.call(
            "Page.captureScreenshot",
            {
                "format": "png",
                "fromSurface": True,
                "captureBeyondViewport": True,
                "clip": {
                    "x": max(0.0, float(print_state.get("x", 0))),
                    "y": max(0.0, float(print_state.get("y", 0))),
                    "width": float(print_state.get("width", 0)),
                    "height": float(print_state.get("height", 0)),
                    "scale": 1,
                },
            },
        )
        encoded_screenshot = screenshot_result.get("data")
        try:
            screenshot_bytes = base64.b64decode(
                encoded_screenshot,
                validate=True,
            )
        except (TypeError, ValueError) as exc:
            raise TestFailure(
                "Chrome lieferte keinen gültigen Bildnachweis des Druckformulars."
            ) from exc
        screenshot_summary = _png_rgb_content_summary(screenshot_bytes)
        screenshot_pixels = (
            screenshot_summary["width"] * screenshot_summary["height"]
        )
        self._truth(
            len(screenshot_bytes) > 20000
            and abs(
                screenshot_summary["width"]
                - round(float(print_state.get("width", 0)))
            ) <= 2
            and abs(
                screenshot_summary["height"]
                - round(float(print_state.get("height", 0)))
            ) <= 2
            and screenshot_summary["blue_pixels"]
                >= screenshot_pixels * 0.35
            and screenshot_summary["dark_pixels"]
                >= screenshot_pixels * 0.003
            and screenshot_summary["non_white_pixels"]
                >= screenshot_pixels * 0.45,
            "Chromes Bildnachweis enthält nicht die sichtbare blaue "
            "Formularfläche mit schwarzem Raster und Inhalt.",
        )

        self._assert_rendered_pdf_bytes(pdf_bytes)
        self.cdp.navigate(self.config.base_url + "/")

    def _assert_rendered_pdf_bytes(self, pdf_bytes: bytes) -> None:
        encoded_pdf = base64.b64encode(pdf_bytes).decode("ascii")
        pdf_data_url = "data:application/pdf;base64," + encoded_pdf
        try:
            decoded_data_url = base64.b64decode(
                pdf_data_url.partition(",")[2],
                validate=True,
            )
        except (TypeError, ValueError) as exc:
            raise TestFailure(
                "Der PDF-Drucknachweis konnte nicht bytegenau an Chrome "
                "übergeben werden."
            ) from exc
        self._truth(
            decoded_data_url == pdf_bytes,
            "Die an Chrome übergebene PDF-Daten-URL weicht vom erzeugten "
            "Byte-Stream ab.",
        )

        self.cdp.call("Emulation.setEmulatedMedia", {"media": "screen"})
        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 1440,
                "height": 1000,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 1440,
                "screenHeight": 1000,
            },
        )
        navigation = self.cdp.call("Page.navigate", {"url": pdf_data_url})
        self._truth(
            not navigation.get("errorText")
            and navigation.get("isDownload") is not True,
            "Chrome hat den erzeugten PDF-Byte-Stream nicht im integrierten "
            "PDF-Renderer geöffnet.",
        )
        expected_url = json.dumps(pdf_data_url)
        viewer_state = self.cdp.wait_for(
            f"""
            (() => {{
                const embedder = Array.from(
                    document.querySelectorAll('link[href], embed[type]')
                ).some(element =>
                    element.href?.startsWith('chrome-extension://')
                        && element.href.endsWith('/pdf_embedder.css')
                    || element.tagName === 'EMBED'
                        && element.type === 'application/pdf'
                );
                if (
                    document.readyState !== "complete"
                    || location.href !== {expected_url}
                    || document.contentType !== "application/pdf"
                    || !embedder
                ) return false;
                return {{
                    contentType: document.contentType,
                    exactLocation: location.href === {expected_url},
                    embedder
                }};
            }})()
            """,
            "Chrome hat den exakten PDF-Byte-Stream nicht im PDF-Viewer geladen",
        )
        self._truth(
            viewer_state.get("contentType") == "application/pdf"
            and viewer_state.get("exactLocation") is True
            and viewer_state.get("embedder") is True,
            "Der geladene Chrome-PDF-Viewer ist nicht an den exakten "
            "Druck-Byte-Stream gebunden.",
        )

        rendered_summary: dict[str, int] | None = None
        rendered_bytes = b""
        deadline = time.monotonic() + self.config.timeout
        while time.monotonic() < deadline:
            screenshot_result = self.cdp.call(
                "Page.captureScreenshot",
                {
                    "format": "png",
                    "fromSurface": True,
                },
            )
            encoded_screenshot = screenshot_result.get("data")
            try:
                rendered_bytes = base64.b64decode(
                    encoded_screenshot,
                    validate=True,
                )
            except (TypeError, ValueError) as exc:
                raise TestFailure(
                    "Chrome lieferte kein gültiges Bild des erzeugten PDFs."
                ) from exc
            summary = _png_rgb_content_summary(rendered_bytes)
            pixel_count = summary["width"] * summary["height"]
            blue_bounds_area = (
                summary["blue_bounds_width"]
                * summary["blue_bounds_height"]
            )
            if (
                len(rendered_bytes) > 20000
                and summary["width"] >= 1200
                and summary["height"] >= 800
                and summary["blue_pixels"] >= pixel_count * 0.20
                and summary["blue_bounds_width"] >= 500
                and summary["blue_bounds_height"] >= 400
                and summary["dark_pixels_in_blue_bounds"]
                    >= blue_bounds_area * 0.01
            ):
                rendered_summary = summary
                break
            time.sleep(0.1)

        self._truth(
            rendered_summary is not None,
            "Der exakte PDF-Byte-Stream enthält im Chrome-Renderer nicht "
            "die sichtbare blaue Formularfläche mit schwarzem Raster.",
        )

    def _assert_narrow_overview(self) -> None:
        state = self.cdp.evaluate(
            """
            (() => {
                const bounds = element => {
                    if (!element) return null;
                    const rect = element.getBoundingClientRect();
                    return {
                        left: rect.left,
                        right: rect.right,
                        top: rect.top,
                        bottom: rect.bottom,
                        width: rect.width,
                        height: rect.height
                    };
                };
                const actions = Array.from(
                    document.querySelectorAll(
                        ".estab-root-auth-actions .estab-button"
                    )
                ).map(element => Object.assign(bounds(element), {
                    id: element.id,
                    text: element.innerText.trim()
                }));
                const authNote = document.querySelector(
                    ".estab-login-cta .estab-auth-note"
                );
                const navigation = document.querySelector(
                    ".estab-navigation-content"
                );
                const navigationLinks = navigation
                    ? Array.from(navigation.querySelectorAll(
                        "a[data-estab-nav-key]"
                    ))
                    : [];
                if (navigation) navigation.scrollLeft = 0;
                const navigationRect = bounds(navigation);
                const lastNavigationLink =
                    navigationLinks[navigationLinks.length - 1] || null;
                const cards = Array.from(
                    document.querySelectorAll(".estab-menu-card")
                ).map(bounds);
                const unexpectedOverflow = Array.from(
                    document.body.querySelectorAll("*")
                ).filter(element => {
                    if (navigation && navigation.contains(element)) return false;
                    const style = window.getComputedStyle(element);
                    if (
                        style.display === "none"
                        || style.visibility === "hidden"
                    ) {
                        return false;
                    }
                    const rect = element.getBoundingClientRect();
                    return (
                        rect.width > 0
                        && rect.height > 0
                        && (
                            rect.left < -0.5
                            || rect.right > window.innerWidth + 0.5
                        )
                    );
                }).slice(0, 20).map(element => ({
                    tag: element.tagName.toLowerCase(),
                    id: element.id,
                    classes: element.className
                }));
                return {
                    innerWidth: window.innerWidth,
                    unexpectedOverflow,
                    regions: {
                        publicBar: bounds(document.querySelector(
                            "aside[data-estab-public-bar]"
                        )),
                        masthead: bounds(document.querySelector(
                            ".estab-root-header"
                        )),
                        rootMain: bounds(document.querySelector(
                            ".estab-root-main"
                        )),
                        loginCard: bounds(document.querySelector(
                            ".estab-login-cta"
                        )),
                        authNote: bounds(authNote)
                    },
                    authNoteText: authNote ? authNote.innerText.trim() : "",
                    menuSections: Array.from(
                        document.querySelectorAll(".estab-menu-section")
                    ).map(bounds),
                    navigation: navigation && navigationRect ? {
                        left: navigationRect.left,
                        right: navigationRect.right,
                        top: navigationRect.top,
                        bottom: navigationRect.bottom,
                        clientWidth: navigation.clientWidth,
                        scrollWidth: navigation.scrollWidth,
                        scrollLeft: navigation.scrollLeft,
                        lastKey: lastNavigationLink
                            ? lastNavigationLink.getAttribute("data-estab-nav-key")
                            : null
                    } : null,
                    actions,
                    cards
                };
            })()
            """
        )
        self._truth(
            isinstance(state, dict),
            "Layout im schmalen Viewport konnte nicht geprüft werden.",
        )
        self._equal(state.get("innerWidth"), 390, "Breite des schmalen Viewports")
        unexpected_overflow = state.get("unexpectedOverflow")
        self._truth(
            isinstance(unexpected_overflow, list) and not unexpected_overflow,
            "Mindestens ein sichtbares Element liegt außerhalb des schmalen "
            f"Viewports: {unexpected_overflow!r}",
        )

        def assert_horizontally_contained(bounds: Any, description: str) -> None:
            self._truth(
                isinstance(bounds, dict)
                and bounds.get("left", -1) >= -0.5
                and bounds.get("right", 10000) <= state["innerWidth"] + 0.5
                and bounds.get("width", 0) > 0,
                f"{description} liegt horizontal außerhalb des schmalen Viewports.",
            )

        regions = state.get("regions")
        self._truth(
            isinstance(regions, dict),
            "Relevante Seitenbereiche fehlen im schmalen Viewport.",
        )
        for key in ("publicBar", "masthead", "rootMain", "loginCard", "authNote"):
            assert_horizontally_contained(
                regions.get(key),
                f"Seitenbereich {key}",
            )
        menu_sections = state.get("menuSections")
        self._truth(
            isinstance(menu_sections, list) and len(menu_sections) == 2,
            "Bereichsgruppen fehlen im schmalen Viewport.",
        )
        for index, section in enumerate(menu_sections, start=1):
            assert_horizontally_contained(
                section,
                f"Bereichsgruppe {index}",
            )

        navigation = state.get("navigation")
        self._truth(
            isinstance(navigation, dict)
            and navigation.get("left", -1) >= -0.5
            and navigation.get("right", 10000) <= state["innerWidth"] + 0.5
            and navigation.get("bottom", 0) > navigation.get("top", 0)
            and navigation.get("scrollLeft") == 0
            and navigation.get("scrollWidth", 0) > navigation.get("clientWidth", 0)
            and navigation.get("lastKey") == "handbook",
            "Bereichsnavigation besitzt im schmalen Viewport keinen sauber "
            "begrenzten horizontalen Scrollbereich.",
        )

        actions = state.get("actions")
        self._truth(
            isinstance(actions, list)
            and len(actions) == 1
            and actions[0].get("id") == "estab-login"
            and actions[0].get("text") == "Mit bestehendem Konto anmelden",
            "Eindeutige Bestandskonto-Anmeldung fehlt im schmalen Viewport.",
        )
        for index, action in enumerate(actions, start=1):
            assert_horizontally_contained(
                action,
                f"Anmeldeaktion {index}",
            )
            self._truth(
                action.get("height", 0) >= 44,
                f"Anmeldeaktion {index} ist im schmalen Viewport zu klein.",
            )
        auth_note_text = state.get("authNoteText")
        self._truth(
            isinstance(auth_note_text, str)
            and "nicht selbst angelegt" in auth_note_text
            and "Administration" in auth_note_text
            and "Benutzerverwaltung" in auth_note_text,
            "Hinweis zur administrativen Anlage neuer Konten fehlt im "
            "schmalen Viewport.",
        )

        cards = state.get("cards")
        self._truth(
            isinstance(cards, list)
            and len(cards) == len(self.root_card_keys),
            "Nicht alle Bereichskarten wurden im schmalen Viewport gefunden.",
        )
        for index, card in enumerate(cards, start=1):
            assert_horizontally_contained(
                card,
                f"Bereichskarte {index}",
            )
            self._truth(
                abs(card["left"] - cards[0]["left"]) < 1,
                "Bereichskarten sind im schmalen Viewport nicht durchgehend "
                "einspaltig ausgerichtet.",
            )

        scroll_distance = (
            navigation["scrollWidth"] - navigation["clientWidth"] + 64
        )
        scroll_x = (navigation["left"] + navigation["right"]) / 2
        scroll_y = (navigation["top"] + navigation["bottom"]) / 2
        self.cdp.call(
            "Input.dispatchMouseEvent",
            {"type": "mouseMoved", "x": scroll_x, "y": scroll_y},
        )
        self.cdp.call(
            "Input.dispatchMouseEvent",
            {
                "type": "mouseWheel",
                "x": scroll_x,
                "y": scroll_y,
                "deltaX": scroll_distance,
                "deltaY": 0,
            },
        )
        scroll_state = self.cdp.wait_for(
            """
            (() => {
                const navigation = document.querySelector(
                    ".estab-navigation-content"
                );
                const links = navigation
                    ? Array.from(navigation.querySelectorAll(
                        "a[data-estab-nav-key]"
                    ))
                    : [];
                const last = links[links.length - 1] || null;
                if (!navigation || !last) return null;
                const navigationRect = navigation.getBoundingClientRect();
                const lastRect = last.getBoundingClientRect();
                if (
                    navigation.scrollLeft <= 0 ||
                    lastRect.left < navigationRect.left - 0.5 ||
                    lastRect.right > navigationRect.right + 0.5
                ) {
                    return null;
                }
                return {
                    scrollLeft: navigation.scrollLeft,
                    lastKey: last.getAttribute("data-estab-nav-key")
                };
            })()
            """,
            "letzter Bereichslink wurde durch horizontales Scrollen "
            "im schmalen Viewport nicht erreichbar",
        )
        self._equal(
            scroll_state.get("lastKey"),
            "handbook",
            "Letzter horizontal erreichbarer Navigationsbereich",
        )

    def _assert_internal_cards_same_tab(self) -> None:
        state = self.cdp.evaluate(
            """
            (() => {
                const cards = Array.from(
                    document.querySelectorAll("a.estab-menu-link")
                );
                const internal = cards.filter(link => {
                    try {
                        return new URL(link.href, location.href).origin === location.origin;
                    } catch (_error) {
                        return false;
                    }
                });
                return {
                    count: internal.length,
                    targetViolations: internal
                        .filter(link => link.getAttribute("target") !== null)
                        .map(link => ({
                            href: link.getAttribute("href"),
                            target: link.getAttribute("target")
                        }))
                };
            })()
            """
        )
        self._truth(
            isinstance(state, dict) and state.get("count", 0) > 0,
            "Interne Modulkarten konnten auf der angemeldeten Übersicht nicht geprüft werden.",
        )
        violations = state.get("targetViolations")
        self._equal(
            violations,
            [],
            "Interne Modulkarten öffnen nicht durchgehend im selben Tab",
        )

    def _assert_root_card_layout(self, location: str) -> None:
        layout_expression = """
            (() => {
                const bounds = element => {
                    if (!element) return null;
                    const rect = element.getBoundingClientRect();
                    return {
                        left: rect.left,
                        right: rect.right,
                        top: rect.top,
                        bottom: rect.bottom,
                        width: rect.width,
                        height: rect.height
                    };
                };
                const intersects = (first, second) => (
                    first.left < second.right - 0.5
                    && first.right > second.left + 0.5
                    && first.top < second.bottom - 0.5
                    && first.bottom > second.top + 0.5
                );
                const nodes = Array.from(
                    document.querySelectorAll(".estab-menu-card")
                ).map((card, index) => {
                    const link = card.querySelector("a.estab-menu-link");
                    return {
                        index,
                        key: link
                            ? link.getAttribute("data-estab-nav-key")
                            : null,
                        card,
                        link,
                        cardBounds: bounds(card),
                        linkBounds: bounds(link)
                    };
                });
                const records = nodes.map(node => ({
                    index: node.index,
                    key: node.key,
                    card: node.cardBounds,
                    link: node.linkBounds
                }));
                const containmentViolations = nodes.filter(node => {
                    const card = node.cardBounds;
                    const link = node.linkBounds;
                    return !card || !link
                        || link.left < card.left - 0.5
                        || link.right > card.right + 0.5
                        || link.top < card.top - 0.5
                        || link.bottom > card.bottom + 0.5;
                }).map(node => node.key || `index-${node.index}`);
                const cardOverlaps = [];
                const linkOverlaps = [];
                for (let first = 0; first < nodes.length; first += 1) {
                    for (
                        let second = first + 1;
                        second < nodes.length;
                        second += 1
                    ) {
                        if (intersects(
                            nodes[first].cardBounds,
                            nodes[second].cardBounds
                        )) {
                            cardOverlaps.push([
                                nodes[first].key,
                                nodes[second].key
                            ]);
                        }
                        if (
                            intersects(
                                nodes[first].linkBounds,
                                nodes[second].cardBounds
                            )
                            || intersects(
                                nodes[second].linkBounds,
                                nodes[first].cardBounds
                            )
                        ) {
                            linkOverlaps.push([
                                nodes[first].key,
                                nodes[second].key
                            ]);
                        }
                    }
                }
                const hovered = nodes.filter(
                    node => node.link && node.link.matches(":hover")
                ).map(node => node.key);
                const hoveredNode = nodes.find(
                    node => node.link && node.link.matches(":hover")
                ) || null;
                let hoverVisualOverlaps = [];
                let hoverStyle = null;
                if (hoveredNode) {
                    const style = getComputedStyle(hoveredNode.link);
                    const outlineWidth = Number.parseFloat(style.outlineWidth) || 0;
                    const outlineOffset = Number.parseFloat(style.outlineOffset) || 0;
                    const expansion = Math.max(
                        0,
                        outlineWidth + outlineOffset
                    );
                    const visualBounds = {
                        left: hoveredNode.linkBounds.left - expansion,
                        right: hoveredNode.linkBounds.right + expansion,
                        top: hoveredNode.linkBounds.top - expansion,
                        bottom: hoveredNode.linkBounds.bottom + expansion
                    };
                    hoverVisualOverlaps = nodes.filter(
                        node => node !== hoveredNode
                            && intersects(visualBounds, node.cardBounds)
                    ).map(node => node.key);
                    hoverStyle = {
                        outlineWidth,
                        outlineOffset,
                        backgroundColor: style.backgroundColor
                    };
                }
                return {
                    records,
                    containmentViolations,
                    cardOverlaps,
                    linkOverlaps,
                    hovered,
                    hoverVisualOverlaps,
                    hoverStyle
                };
            })()
        """
        initial = self.cdp.evaluate(layout_expression)
        self._truth(
            isinstance(initial, dict)
            and isinstance(initial.get("records"), list)
            and len(initial["records"]) == len(self.root_card_keys),
            f"Das vollständige Kartenraster fehlt auf der {location}.",
        )
        self._equal(
            initial.get("containmentViolations"),
            [],
            f"Kartenlinks ragen auf der {location} aus ihren Karten heraus",
        )
        self._equal(
            initial.get("cardOverlaps"),
            [],
            f"Karten überschneiden sich auf der {location}",
        )
        self._equal(
            initial.get("linkOverlaps"),
            [],
            f"Klickflächen überschneiden Nachbarkarten auf der {location}",
        )

        hover_position = self.cdp.evaluate(
            """
            (() => {
                const link = document.querySelector(
                    ".estab-menu-card a.estab-menu-link"
                );
                if (!link) return null;
                link.scrollIntoView({block: "center", inline: "center"});
                const rect = link.getBoundingClientRect();
                return {
                    key: link.getAttribute("data-estab-nav-key"),
                    x: rect.left + rect.width / 2,
                    y: rect.top + rect.height / 2
                };
            })()
            """
        )
        self._truth(
            isinstance(hover_position, dict),
            f"Keine Karte konnte auf der {location} per Hover geprüft werden.",
        )
        before_hover = self.cdp.evaluate(layout_expression)
        self.cdp.call(
            "Input.dispatchMouseEvent",
            {
                "type": "mouseMoved",
                "x": hover_position["x"],
                "y": hover_position["y"],
            },
        )
        after_hover = self.cdp.evaluate(layout_expression)
        hover_key = hover_position.get("key")
        self._truth(
            hover_key in after_hover.get("hovered", []),
            f"Der echte Hoverzustand wurde auf der {location} nicht aktiviert.",
        )
        self._equal(
            after_hover.get("containmentViolations"),
            [],
            f"Hover lässt einen Kartenlink auf der {location} herausragen",
        )
        self._equal(
            after_hover.get("linkOverlaps"),
            [],
            f"Hover lässt eine Klickfläche auf der {location} eine Nachbarkarte überdecken",
        )
        self._equal(
            after_hover.get("hoverVisualOverlaps"),
            [],
            f"Die sichtbare Hovermarkierung überdeckt auf der {location} eine Nachbarkarte",
        )
        self._truth(
            isinstance(after_hover.get("hoverStyle"), dict)
            and after_hover["hoverStyle"].get("outlineWidth", 0) > 0,
            f"Eine sichtbare Hovermarkierung fehlt auf der {location}.",
        )

        def geometry_for(
            state: dict[str, Any],
            key: str,
        ) -> dict[str, Any] | None:
            for record in state.get("records", []):
                if isinstance(record, dict) and record.get("key") == key:
                    return record
            return None

        before_geometry = geometry_for(before_hover, str(hover_key))
        after_geometry = geometry_for(after_hover, str(hover_key))
        self._truth(
            isinstance(before_geometry, dict)
            and isinstance(after_geometry, dict),
            f"Hovergeometrie fehlt auf der {location}.",
        )
        for element in ("card", "link"):
            for dimension in ("left", "right", "top", "bottom", "width", "height"):
                before_value = before_geometry[element][dimension]
                after_value = after_geometry[element][dimension]
                self._truth(
                    abs(before_value - after_value) <= 0.5,
                    f"Hover verändert auf der {location} die "
                    f"{element}-{dimension}-Geometrie.",
                )

    def _click_root_card(self, path: str, description: str) -> None:
        selector = f'a.estab-menu-link[href$="{path}"]'
        self._equal(
            self.cdp.evaluate(_visible_count_expression(None, selector)),
            1,
            f"Anzahl sichtbarer Karten für {description}",
        )
        self.cdp.click(None, selector, description)

    def _wait_for_top_level_path(self, path: str, description: str) -> None:
        expected_path = json.dumps(path)
        self.cdp.wait_for(
            f"""
            document.readyState === "complete" &&
            location.pathname.endsWith({expected_path})
            """,
            description,
        )

    def _open_compact_navigation(self, frame_name: str, location: str) -> None:
        disclosure_open = self.cdp.evaluate(
            _frame_expression(
                frame_name,
                """
                const navigation = doc.querySelector("[data-estab-navigation]");
                const disclosure = navigation && navigation.querySelector("details");
                return disclosure ? disclosure.open : null;
                """,
            )
        )
        self._truth(
            disclosure_open is not None,
            f"Kompakte Bereichsauswahl fehlt in {location}.",
        )
        if not disclosure_open:
            self.cdp.click(
                frame_name,
                "[data-estab-navigation] summary",
                f"Bereichsauswahl in {location}",
            )
        self.cdp.wait_for(
            _frame_expression(
                frame_name,
                """
                const navigation = doc.querySelector("[data-estab-navigation]");
                const disclosure = navigation && navigation.querySelector("details");
                if (!navigation || !disclosure || !disclosure.open) return false;
                return Array.from(
                    navigation.querySelectorAll("a[data-estab-nav-key]")
                ).every(link => {
                    const rect = link.getBoundingClientRect();
                    const style = target.getComputedStyle(link);
                    return rect.width > 0 && rect.height > 0 &&
                        style.display !== "none" && style.visibility !== "hidden";
                });
                """,
            ),
            f"Bereichsauswahl in {location} wurde nicht geöffnet",
        )

    def _assert_protected_cards(self) -> None:
        expected_destinations = list(self.protected_card_keys)
        expected_root_cards = list(self.root_card_keys)
        card_state = self.cdp.evaluate(
            """
            (() => {
                const rootCards = Array.from(
                    document.querySelectorAll(".estab-menu-card")
                );
                const all = Array.from(
                    document.querySelectorAll(".estab-menu-card-application")
                );
                const locked = all.filter(card =>
                    card.classList.contains("estab-menu-card-locked")
                );
                return {
                    rootKeys: rootCards.map(card => {
                        const link = card.querySelector(
                            "a.estab-menu-link[data-estab-nav-key]"
                        );
                        return link
                            ? link.getAttribute("data-estab-nav-key")
                            : null;
                    }),
                    total: all.length,
                    locked: locked.map(card => {
                        const link = card.querySelector("a.estab-menu-link");
                        const badge = card.querySelector(".estab-menu-badge");
                        if (!link || !badge) return null;
                        const url = new URL(link.href, location.href);
                        return {
                            href: url.pathname + url.search,
                            key: link.getAttribute("data-estab-nav-key"),
                            target: link.getAttribute("target"),
                            badge: badge.innerText.replace(/\\s+/g, " ").trim()
                        };
                    })
                };
            })()
            """
        )
        self._truth(
            isinstance(card_state, dict)
            and isinstance(card_state.get("rootKeys"), list),
            "Die vollständige Root-Kartenreihenfolge konnte nicht geprüft werden.",
        )
        self._equal(
            card_state.get("rootKeys"),
            expected_root_cards,
            "Reihenfolge und Eindeutigkeit aller Root-Karten",
        )
        if (
            not isinstance(card_state.get("locked"), list)
            or card_state.get("total") != len(card_state["locked"])
            or card_state.get("total") != len(expected_destinations)
        ):
            raise TestFailure(
                "Die geschützten Modulkarten sind anonym nicht vollständig gesperrt."
            )
        actual_destinations = [
            str(card.get("key", "")) if isinstance(card, dict) else ""
            for card in card_state["locked"]
        ]
        self._equal(
            actual_destinations,
            expected_destinations,
            "Reihenfolge und Eindeutigkeit der Post-Login-Ziele "
            "geschützter Modulkarten",
        )
        for card in card_state["locked"]:
            destination = str(card.get("key", ""))
            if (
                not card
                or not str(card.get("href", "")).endswith(
                    "/4fach/index.php?login_flow=existing&next=" + destination
                )
                or card.get("target") is not None
                or card.get("badge") != "Anmeldung erforderlich"
            ):
                raise TestFailure(
                    "Mindestens eine anonyme Modulkarte besitzt keinen eindeutigen Anmeldeschutz."
                )

        redirects_json = json.dumps(
            [
                {
                    "path": path,
                    "destination": destination,
                    "loginPath": login_path,
                }
                for path, destination, login_path in self.protected_redirects
            ]
        )
        results = self.cdp.evaluate(
            f"""
            (async () => {{
                const redirects = {redirects_json};
                return Promise.all(redirects.map(async expected => {{
                    try {{
                        const response = await fetch(
                            new URL(expected.path, location.href),
                            {{
                            credentials: "same-origin",
                            redirect: "follow",
                            cache: "no-store"
                            }}
                        );
                        const finalUrl = new URL(response.url);
                        return {{
                            ...expected,
                            status: response.status,
                            redirected: response.redirected,
                            pathname: finalUrl.pathname,
                            flow: finalUrl.searchParams.get("login_flow"),
                            actualDestination: finalUrl.searchParams.get("next")
                        }};
                    }} catch (_error) {{
                        return {{...expected, status: -1}};
                    }}
                }}));
            }})()
            """
        )
        if not isinstance(results, list):
            raise TestFailure("Status der geschützten Modulkarten konnte nicht geprüft werden.")
        failures = [
            result.get("path", "unbekannt")
            for result in results
            if (
                result.get("status") != 200
                or result.get("redirected") is not True
                or not str(result.get("pathname", "")).endswith(
                    "/" + str(result.get("loginPath", ""))
                )
                or result.get("flow") != "existing"
                or result.get("actualDestination")
                    != result.get("destination")
            )
        ]
        if failures:
            raise TestFailure(
                "Direkte anonyme Modulzugriffe führen nicht vollständig zum "
                "sicheren Bestandslogin: "
                + ", ".join(failures)
            )

    def _wait_for_frame(self, frame_name: str) -> None:
        self.cdp.wait_for(
            _frame_expression(
                frame_name,
                'return doc.readyState === "complete" || doc.readyState === "interactive";',
            ),
            f"Frame {frame_name} wurde nicht geladen",
        )

    def _wait_for_authenticated_frames(self) -> None:
        expected_name = json.dumps(self.config.login_name)
        expected_code = json.dumps(self.config.login_code)
        expected_function = json.dumps(self.config.login_function)
        deadline = time.monotonic() + self.config.timeout
        while time.monotonic() < deadline:
            try:
                content_is_authenticated = self.cdp.evaluate(
                    _frame_expression(
                        "mainframe",
                        """
                        return target.location.pathname.endsWith(
                                "/4fach/mainindex.php"
                            ) &&
                            doc.readyState === "complete" &&
                            Boolean(doc.querySelector(
                                "script[data-estab-mainframe-guard]"
                            )) &&
                            !doc.querySelector(".estab-auth-card") &&
                            !doc.querySelector('input[name="kennwort1"]');
                        """,
                    )
                )
                navigation_identity = self.cdp.evaluate(
                    _frame_expression(
                        "vorgaben",
                        f"""
                        const bars = Array.from(
                            doc.querySelectorAll("aside[data-estab-session-bar]")
                        ).filter(element => {{
                            const rect = element.getBoundingClientRect();
                            const style = target.getComputedStyle(element);
                            return rect.width > 0 && rect.height > 0 &&
                                style.display !== "none" && style.visibility !== "hidden";
                        }});
                        if (bars.length !== 1) return false;
                        const identity = bars[0].querySelector("[data-estab-user-code]");
                        const name = bars[0].querySelector("[data-estab-user-name]");
                        return Boolean(identity &&
                            name &&
                            name.getAttribute("data-estab-user-name") === {expected_name} &&
                            identity.getAttribute("data-estab-user-code") === {expected_code} &&
                            identity.getAttribute("data-estab-user-function") === {expected_function});
                        """,
                    )
                )
                main_bar_count = self.cdp.evaluate(
                    _visible_count_expression(
                        "mainframe", "aside[data-estab-session-bar]"
                    )
                )
                if content_is_authenticated and navigation_identity and main_bar_count == 0:
                    time.sleep(0.3)
                    return
                error_text = self.cdp.evaluate(
                    _text_expression("mainframe", ".estab-auth-error, .error")
                )
            except TestFailure:
                time.sleep(0.1)
                continue
            if error_text:
                raise TestFailure(
                    "Kontoanlage wurde von der Anwendung abgelehnt. "
                    "Bitte ein noch nicht verwendetes ESTAB_TEST_LOGIN_CODE nutzen."
                )
            time.sleep(0.1)
        raise TestFailure("Timeout: Konto wurde nicht angelegt und angemeldet.")

    def _assert_tracking_access_denied_page(self) -> None:
        """Prove a denied specialist view remains a usable application page."""
        print("      gestaltete, navigierbare Berechtigungsseite prüfen")
        self.cdp.navigate(
            self.config.base_url + "/4fach/nachwea.php?nwalle=1"
        )
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            location.pathname.endsWith("/4fach/nachwea.php") &&
            Boolean(document.querySelector(
                '[data-estab-error-page][data-estab-error-status="403"]'
            )) &&
            document.body.innerText.includes(
                "Die Nachweisung ist nur für eine aktive LdF- oder " +
                "Fernmelder-Funktion verfügbar."
            )
            """,
            "Berechtigungsfehler der Nachweisung blieb keine gestaltete Seite",
        )
        self._equal(
            self.cdp.evaluate(
                _visible_count_expression(None, "aside[data-estab-session-bar]")
            ),
            1,
            "sichtbare Session-Leiste auf der Berechtigungsseite",
        )
        details = self.cdp.evaluate(
            """
            (() => {
                const page = document.querySelector("[data-estab-error-page]");
                const bar = document.querySelector("aside[data-estab-session-bar]");
                const identity = bar && bar.querySelector("[data-estab-user-code]");
                const navigation = bar && bar.querySelector(
                    "[data-estab-navigation]"
                );
                const recovery = document.querySelector(
                    "[data-estab-error-recovery] a"
                );
                const recoveryUrl = recovery
                    ? new URL(recovery.href, location.href)
                    : null;
                return {
                    context: page && page.getAttribute(
                        "data-estab-error-context"
                    ),
                    code: identity && identity.getAttribute(
                        "data-estab-user-code"
                    ),
                    navigationKeys: navigation
                        ? Array.from(navigation.querySelectorAll(
                            "a[data-estab-nav-key]"
                        )).map(link => link.getAttribute("data-estab-nav-key"))
                        : [],
                    currentKeys: navigation
                        ? Array.from(navigation.querySelectorAll(
                            '[aria-current="page"]'
                        )).map(link => link.getAttribute("data-estab-nav-key"))
                        : [],
                    hasLogout: Boolean(bar && bar.querySelector(
                        "[data-estab-logout-form]"
                    )),
                    hasProtectedContent: Boolean(document.querySelector(
                        "[data-estab-tracking-overview]"
                    )),
                    recoveryPath: recoveryUrl && recoveryUrl.pathname,
                    recoverySearch: recoveryUrl && recoveryUrl.search,
                    recoveryTarget: recovery && recovery.target
                };
            })()
            """
        )
        self._truth(
            isinstance(details, dict)
            and details.get("context") == "tracking"
            and details.get("code") == self.config.login_code
            and details.get("hasLogout") is True
            and details.get("hasProtectedContent") is False
            and details.get("currentKeys") == []
            and "overview" in details.get("navigationKeys", [])
            and "messages" in details.get("navigationKeys", [])
            and "tracking" not in details.get("navigationKeys", [])
            and str(details.get("recoveryPath", "")).endswith("/")
            and details.get("recoverySearch") == ""
            and details.get("recoveryTarget") == "_top",
            "Berechtigungsseite zeigt keine sichere Identität, Navigation "
            "oder Rückkehraktion.",
        )

        for width, height in ((1440, 1000), (390, 844)):
            self.cdp.call(
                "Emulation.setDeviceMetricsOverride",
                {
                    "width": width,
                    "height": height,
                    "deviceScaleFactor": 1,
                    "mobile": False,
                    "screenWidth": width,
                    "screenHeight": height,
                },
            )
            layout = self.cdp.evaluate(
                """
                (() => {
                    const page = document.querySelector(
                        "[data-estab-error-page]"
                    );
                    const action = document.querySelector(
                        "[data-estab-error-recovery] a"
                    );
                    if (!page || !action) return null;
                    const pageRect = page.getBoundingClientRect();
                    const actionRect = action.getBoundingClientRect();
                    return {
                        noOverflow: document.documentElement.scrollWidth <=
                            window.innerWidth + 1,
                        pageInside: pageRect.left >= -1 &&
                            pageRect.right <= window.innerWidth + 1,
                        actionWidth: actionRect.width,
                        actionHeight: actionRect.height
                    };
                })()
                """
            )
            self._truth(
                isinstance(layout, dict)
                and layout.get("noOverflow") is True
                and layout.get("pageInside") is True
                and float(layout.get("actionWidth", 0)) > 0
                and float(layout.get("actionHeight", 0)) >= 44,
                f"Berechtigungsseite ist bei {width}×{height} px nicht "
                "überlauffrei oder bedienbar.",
            )

        self.cdp.call(
            "Emulation.setDeviceMetricsOverride",
            {
                "width": 1440,
                "height": 1000,
                "deviceScaleFactor": 1,
                "mobile": False,
                "screenWidth": 1440,
                "screenHeight": 1000,
            },
        )
        self.cdp.click(
            None,
            "[data-estab-error-recovery] a",
            "Rückkehr aus der Berechtigungsseite",
        )
        self._wait_for_authenticated_overview(
            "Berechtigungsseite führte nicht zurück zur eStab-Übersicht"
        )

    def _assert_session_bar(
        self,
        frame_name: str | None,
        location: str,
        expected_active_key: str,
    ) -> None:
        self._truth(
            expected_active_key in (*self.navigation_keys, "administration"),
            f"Unbekannter erwarteter Navigationsbereich {expected_active_key!r}.",
        )
        selector = "aside[data-estab-session-bar]"
        expected_navigation_keys = self._authenticated_navigation_keys()
        self._equal(
            self.cdp.evaluate(_visible_count_expression(frame_name, selector)),
            1,
            f"Anzahl sichtbarer Session-Bars in {location}",
        )
        details = self.cdp.evaluate(
            _frame_expression(
                frame_name,
                f"""
                const bar = doc.querySelector({json.dumps(selector)});
                const identity = bar && bar.querySelector("[data-estab-user-code]");
                const name = bar && bar.querySelector("[data-estab-user-name]");
                const sidebarRoot = bar && bar.closest(
                    "[data-estab-sidebar-root]"
                );
                const navigation = bar && (
                    bar.querySelector("[data-estab-navigation]") ||
                    (
                        sidebarRoot &&
                        sidebarRoot.querySelector(
                            ":scope > [data-estab-navigation]"
                        )
                    )
                );
                const sidebarStatus = sidebarRoot && sidebarRoot.querySelector(
                    ":scope > [data-estab-sidebar-status]"
                );
                const sidebarWorkflow = sidebarRoot && sidebarRoot.querySelector(
                    ":scope > [data-estab-workflow-menu]"
                );
                const expectedCoreKeys = new Set(
                    {json.dumps(expected_navigation_keys)}
                );
                const links = navigation
                    ? Array.from(
                        navigation.querySelectorAll("a[data-estab-nav-key]")
                    ).filter(link => expectedCoreKeys.has(
                        link.getAttribute("data-estab-nav-key")
                    ))
                    : [];
                const current = navigation
                    ? Array.from(navigation.querySelectorAll('[aria-current="page"]'))
                    : [];
                const overview = navigation &&
                    navigation.querySelector('a[data-estab-nav-key="overview"]');
                const disclosure = navigation && navigation.querySelector("details");
                const summary = disclosure && disclosure.querySelector("summary");
                const logout = bar && bar.querySelector("[data-estab-logout-form] button");
                if (!bar || !identity || !name || !navigation || !overview || !logout) {{
                    return null;
                }}
                const navigationRect = navigation.getBoundingClientRect();
                const logoutRect = logout.getBoundingClientRect();
                const visible = element => {{
                    const rect = element.getBoundingClientRect();
                    const style = target.getComputedStyle(element);
                    return rect.width > 0 && rect.height > 0 &&
                        style.display !== "none" && style.visibility !== "hidden";
                }};
                return {{
                    name: name.getAttribute("data-estab-user-name"),
                    code: identity.getAttribute("data-estab-user-code"),
                    functionName: identity.getAttribute("data-estab-user-function"),
                    role: identity.getAttribute("data-estab-user-role"),
                    text: bar.innerText.replace(/\\s+/g, " ").trim(),
                    navigationCount:
                        doc.querySelectorAll("[data-estab-navigation]").length,
                    navigationKeys:
                        links.map(link => link.getAttribute("data-estab-nav-key")),
                    allCoreTargetsTop:
                        links.every(link => link.getAttribute("target") === "_top"),
                    activeKeys:
                        current.map(link => link.getAttribute("data-estab-nav-key")),
                    navigationVisible:
                        navigationRect.width > 0 && navigationRect.height > 0,
                    allNavigationLinksVisible: links.every(visible),
                    navigationMode:
                        navigation.getAttribute("data-estab-navigation-mode"),
                    compact: bar.classList.contains("estab-session-bar-compact"),
                    sidebarOrder: !sidebarRoot || (
                        Boolean(
                            sidebarStatus &&
                            sidebarWorkflow &&
                            navigation
                        ) &&
                        (
                            sidebarStatus.compareDocumentPosition(bar) &
                            Node.DOCUMENT_POSITION_FOLLOWING
                        ) !== 0 &&
                        (
                            bar.compareDocumentPosition(sidebarWorkflow) &
                            Node.DOCUMENT_POSITION_FOLLOWING
                        ) !== 0 &&
                        (
                            sidebarWorkflow.compareDocumentPosition(navigation) &
                            Node.DOCUMENT_POSITION_FOLLOWING
                        ) !== 0
                    ),
                    hasDisclosure: Boolean(disclosure),
                    disclosureSummaryVisible: Boolean(summary && visible(summary)),
                    overviewContract:
                        overview.textContent.replace(/\\s+/g, " ").trim() === "Übersicht" &&
                        overview.getAttribute("target") === "_top",
                    logoutVisible: logoutRect.width > 0 && logoutRect.height > 0 &&
                        !logout.disabled
                }};
                """,
            )
        )
        if not details:
            raise TestFailure(f"Session-Informationen fehlen in {location}.")
        self._equal(details["name"], self.config.login_name, f"Benutzername in {location}")
        self._equal(details["code"], self.config.login_code, f"Kürzel in {location}")
        self._equal(
            details["functionName"],
            self.config.login_function,
            f"Funktion in {location}",
        )
        role = details.get("role")
        self._truth(isinstance(role, str) and bool(role.strip()), f"Rolle fehlt in {location}.")
        self._equal(
            details.get("navigationCount"),
            1,
            f"Anzahl gemeinsamer Navigationen in {location}",
        )
        self._equal(
            details.get("navigationKeys"),
            expected_navigation_keys,
            f"Reihenfolge der Kernbereiche in {location}",
        )
        self._truth(
            details.get("allCoreTargetsTop"),
            f"Kernbereich wechselt in {location} nicht durchgehend das Top-Level-Ziel.",
        )
        self._equal(
            details.get("activeKeys"),
            [expected_active_key],
            f"Aktiver Navigationsbereich in {location}",
        )
        self._truth(
            details.get("navigationVisible"),
            f"Gemeinsame Navigation ist in {location} nicht sichtbar.",
        )
        if details.get("navigationMode") == "sidebar":
            self._truth(
                details.get("compact")
                and not details.get("hasDisclosure")
                and details.get("allNavigationLinksVisible")
                and details.get("sidebarOrder"),
                f"Status, Identität, Aktionen und sichtbare Navigation sind "
                f"in {location} nicht korrekt angeordnet.",
            )
        elif details.get("compact"):
            self._truth(
                details.get("hasDisclosure") and details.get("disclosureSummaryVisible"),
                f"Kompakte Bereichsauswahl fehlt in {location}.",
            )
        else:
            self._truth(
                details.get("allNavigationLinksVisible"),
                f"Mindestens ein Kernbereich ist in {location} nicht sichtbar.",
            )
        visible_text = details.get("text", "")
        for expected in (
            "Angemeldet als",
            self.config.login_name,
            self.config.login_code,
            self.config.login_function,
            role,
            "Abmelden",
        ):
            self._truth(expected in visible_text, f"Session-Bar in {location} ist unvollständig.")
        self._truth(details.get("logoutVisible"), f"Abmeldebutton fehlt in {location}.")
        self._truth(details.get("overviewContract"), f"Übersichtslink fehlt in {location}.")

    @staticmethod
    def _equal(actual: Any, expected: Any, description: str) -> None:
        if actual != expected:
            raise TestFailure(
                f"{description}: erwartet {expected!r}, erhalten {actual!r}."
            )

    @staticmethod
    def _truth(condition: Any, message: str) -> None:
        if not condition:
            raise TestFailure(message)


def capture_diagnostics(cdp: CDP | None) -> pathlib.Path | None:
    if cdp is None:
        return None
    configured = os.environ.get("ESTAB_BROWSER_ARTIFACT_DIR")
    try:
        if configured:
            root = pathlib.Path(configured).expanduser()
            root.mkdir(parents=True, exist_ok=True)
            artifact_dir = root / f"failure-{time.strftime('%Y%m%d-%H%M%S')}-{os.getpid()}"
            artifact_dir.mkdir()
        else:
            artifact_dir = pathlib.Path(tempfile.mkdtemp(prefix="estab-browser-failure-"))

        try:
            screenshot = cdp.call("Page.captureScreenshot", {"format": "png"})
            encoded = screenshot.get("data")
            if encoded:
                (artifact_dir / "failure.png").write_bytes(base64.b64decode(encoded))
        except (TestFailure, OSError, ValueError):
            pass

        try:
            state = cdp.evaluate(
                """
                (() => {
                    const frames = [];
                    const collect = root => {
                        for (let index = 0; index < root.frames.length; index += 1) {
                            const child = root.frames[index];
                            try {
                                const navigation = child.document.querySelector(
                                    "[data-estab-navigation]"
                                );
                                frames.push({
                                    name: child.name || "",
                                    url: child.location.href,
                                    sessionBars: child.document.querySelectorAll(
                                        "aside[data-estab-session-bar]"
                                    ).length,
                                    navigationKeys: navigation
                                        ? Array.from(navigation.querySelectorAll(
                                            "a[data-estab-nav-key]"
                                        )).map(link => link.getAttribute(
                                            "data-estab-nav-key"
                                        ))
                                        : [],
                                    activeNavigationKeys: navigation
                                        ? Array.from(navigation.querySelectorAll(
                                            '[aria-current="page"]'
                                        )).map(link => link.getAttribute(
                                            "data-estab-nav-key"
                                        ))
                                        : []
                                });
                                collect(child);
                            } catch (_error) {
                                frames.push({name: "", url: "inaccessible", sessionBars: null});
                            }
                        }
                    };
                    collect(window);
                    const topNavigation = document.querySelector(
                        "[data-estab-navigation]"
                    );
                    return {
                        url: location.href,
                        title: document.title,
                        topSessionBars: document.querySelectorAll(
                            "aside[data-estab-session-bar]"
                        ).length,
                        topNavigationKeys: topNavigation
                            ? Array.from(topNavigation.querySelectorAll(
                                "a[data-estab-nav-key]"
                            )).map(link => link.getAttribute("data-estab-nav-key"))
                            : [],
                        topActiveNavigationKeys: topNavigation
                            ? Array.from(topNavigation.querySelectorAll(
                                '[aria-current="page"]'
                            )).map(link => link.getAttribute("data-estab-nav-key"))
                            : [],
                        frames
                    };
                })()
                """
            )
            (artifact_dir / "state.json").write_text(
                json.dumps(state, ensure_ascii=False, indent=2) + "\n",
                encoding="utf-8",
            )
        except (TestFailure, OSError, TypeError, ValueError):
            pass
        return artifact_dir
    except OSError:
        return None


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Testet ESTAB-Bestandslogin, Session-Anzeige, Navigation und "
            "Logout in einem echten Headless-Chrome."
        )
    )
    parser.add_argument(
        "--check-browser",
        action="store_true",
        help="nur Chrome/Chromium suchen und ohne Anwendungstest beenden",
    )
    parser.add_argument(
        "--overview-only",
        action="store_true",
        help=(
            "nur die anonyme Übersicht und ihr Kartenlayout prüfen, "
            "ohne Anwendungsdaten zu verändern"
        ),
    )
    parser.add_argument(
        "--auth-recovery-only",
        action="store_true",
        help=(
            "nur sichere Direktaufrufe, Login-Abbruch und die "
            "Wiederanmeldung nach einem verworfenen Formular prüfen"
        ),
    )
    parser.add_argument(
        "--export-only",
        action="store_true",
        help=(
            "nur administrative Exportoberfläche und "
            "Matrix-Bestätigungen testen"
        ),
    )
    parser.add_argument(
        "--bos-only",
        action="store_true",
        help="nur den öffentlichen responsiven BOS-Arbeitsbereich testen",
    )
    parser.add_argument(
        "--handbook-only",
        action="store_true",
        help="nur Web-Handbuch, Suche und responsives Layout testen",
    )
    parser.add_argument(
        "--message-suggestions",
        action="store_true",
        help=(
            "nur die einsatzbezogene A/W-Rufnamen-Combobox mit echtem "
            "Fokus und Tastaturbedienung testen"
        ),
    )
    parser.add_argument(
        "--message-overview",
        action="store_true",
        help=(
            "nur die S2-Meldungsübersicht, exakte Betreffdarstellung und "
            "responsives Layout testen"
        ),
    )
    parser.add_argument(
        "--telecom-plan",
        action="store_true",
        help=(
            "nur den versionierten S6-Fernmeldeplan mit Klonen, "
            "Medienfeldern und Veröffentlichung testen"
        ),
    )
    parser.add_argument(
        "--inactive-messenger",
        action="store_true",
        help=(
            "nur die auswählbare inaktive Fernmelder-Funktion und den "
            "sichtbaren Hinweis zur separaten Information testen"
        ),
    )
    return parser.parse_args()


def main() -> int:
    arguments = parse_arguments()
    chrome: ChromeProcess | None = None
    websocket: WebSocket | None = None
    cdp: CDP | None = None
    try:
        binary = find_chrome()
        version = browser_version(binary)
        if arguments.check_browser:
            print(f"Browser verfügbar: {binary} ({version})")
            return 0
        print(f"Browser: {version}")
        if sum(
            (
                arguments.overview_only,
                arguments.auth_recovery_only,
                arguments.export_only,
                arguments.bos_only,
                arguments.handbook_only,
                arguments.message_suggestions,
                arguments.message_overview,
                arguments.telecom_plan,
                arguments.inactive_messenger,
            )
        ) > 1:
            raise TestFailure(
                "--overview-only, --auth-recovery-only, --export-only, "
                "--bos-only, --handbook-only und "
                "--message-suggestions, --message-overview sowie "
                "--telecom-plan und --inactive-messenger "
                "können nicht kombiniert werden."
            )
        config = TestConfig.from_environment(
            require_login_password=not (
                arguments.overview_only
                or arguments.auth_recovery_only
                or arguments.export_only
                or arguments.bos_only
                or arguments.handbook_only
            )
        )
        chrome = ChromeProcess(binary, config.startup_timeout)
        chrome.start()
        if chrome.websocket_url is None:
            raise TestFailure("Chrome hat keine Debugging-Adresse bereitgestellt.")
        websocket = WebSocket(chrome.websocket_url, config.timeout)
        cdp = CDP(websocket, config.timeout)
        acceptance = BrowserAcceptance(cdp, config)
        if arguments.overview_only:
            acceptance.run_overview()
        elif arguments.auth_recovery_only:
            acceptance.run(auth_recovery_only=True)
        elif arguments.bos_only:
            acceptance.run_bos()
        elif arguments.handbook_only:
            acceptance.run_handbook()
        elif arguments.message_suggestions:
            acceptance.run_message_suggestions()
        elif arguments.message_overview:
            acceptance.run_message_overview()
        elif arguments.telecom_plan:
            acceptance.run_telecom_plan()
        elif arguments.inactive_messenger:
            acceptance.run_inactive_messenger()
        elif arguments.export_only:
            if not config.admin_user or not config.admin_password:
                raise TestFailure(
                    "--export-only benötigt ESTAB_TEST_ADMIN_USER und "
                    "ESTAB_TEST_ADMIN_PASSWORD(_FILE)."
                )
            cdp.call("Page.enable")
            cdp.call("Runtime.enable")
            cdp.call("Network.enable")
            acceptance._assert_export_management()
        else:
            acceptance.run()
        print("Headless browser UI: OK")
        return 0
    except KeyboardInterrupt:
        print("Headless browser UI: abgebrochen.", file=sys.stderr)
        return 130
    except TestFailure as exc:
        artifact_dir = capture_diagnostics(cdp)
        print(f"Headless browser UI: FAIL: {exc}", file=sys.stderr)
        if artifact_dir is not None:
            print(f"Diagnose: {artifact_dir}", file=sys.stderr)
        return 1
    finally:
        if websocket is not None:
            websocket.close()
        if chrome is not None:
            chrome.close()


if __name__ == "__main__":
    raise SystemExit(main())
