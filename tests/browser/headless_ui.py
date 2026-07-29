#!/usr/bin/env python3
"""Dependency-free ESTAB browser acceptance test using Chrome DevTools Protocol."""

from __future__ import annotations

import argparse
import base64
import dataclasses
import hashlib
import json
import os
import pathlib
import re
import secrets
import shutil
import socket
import ssl
import struct
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request
from typing import Any


class TestFailure(RuntimeError):
    """Expected test or environment failure with a user-facing message."""


@dataclasses.dataclass(frozen=True)
class TestConfig:
    base_url: str
    login_name: str
    login_code: str
    login_function: str
    login_password: str = dataclasses.field(repr=False)
    admin_user: str | None = None
    admin_password: str | None = dataclasses.field(default=None, repr=False)
    timeout: float = 25.0
    startup_timeout: float = 15.0

    @classmethod
    def from_environment(cls) -> "TestConfig":
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
            password = secrets.token_urlsafe(32)
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
        self._log: Any = None
        self.process: subprocess.Popen[bytes] | None = None
        self.websocket_url: str | None = None

    def start(self) -> None:
        self._profile = tempfile.TemporaryDirectory(prefix="estab-chrome-profile-")
        profile_path = pathlib.Path(self._profile.name)
        self._log = tempfile.TemporaryFile(mode="w+b")
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
        if process is not None and process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=3)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=3)
        if self._log is not None:
            self._log.close()
            self._log = None
        if self._profile is not None:
            self._profile.cleanup()
            self._profile = None

    def __enter__(self) -> "ChromeProcess":
        self.start()
        return self

    def __exit__(self, _exc_type: Any, _exc: Any, _traceback: Any) -> None:
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

    def __enter__(self) -> "WebSocket":
        return self

    def __exit__(self, _exc_type: Any, _exc: Any, _traceback: Any) -> None:
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
        if result.get("exceptionDetails"):
            raise TestFailure("JavaScript-Auswertung im Browser ist fehlgeschlagen.")
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
            let x = updated.left + updated.width / 2;
            let y = updated.top + updated.height / 2;
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
        "message-overview",
        "forms",
        "incident-log",
        "technical-log",
        "tracking",
        "bos-info",
    )
    protected_card_keys = (
        "messages",
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
    protected_paths = (
        "4fach/vordrucke.php",
        "4fueltg/ue_ltg.php",
        "stabetb/etb.php",
        "fmtbb/tbb.php",
        "4fach/nachwea.php?nwalle",
    )

    def __init__(self, cdp: CDP, config: TestConfig) -> None:
        self.cdp = cdp
        self.config = config

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
        self.cdp.click(
            "status",
            'a[href$="Buchstabier.html"][target="mainframe"]',
            "öffentlicher BOS-Inhaltslink",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                return target.location.pathname.endsWith(
                    "/stabinfo/Buchstabier.html"
                ) && doc.readyState === "complete";
                """,
            ),
            "öffentliches BOS-Dokument wurde nicht geladen",
        )
        self._assert_mobile_bos_navigation(
            "öffentliche BOS-Infosammlung bei 390×844 px"
        )

    def run(self) -> None:
        self.cdp.call("Page.enable")
        self.cdp.call("Runtime.enable")
        self.cdp.call("Network.enable")
        self.cdp.navigate(self.config.base_url + "/")

        print("[1/9] Anonyme Übersicht, Bestandslogin und gesperrte Module")
        self._assert_anonymous_overview()
        self._assert_protected_cards()
        self._assert_root_card_layout("anonyme Übersicht bei 1440 px")

        print("[2/9] Bestehenden Konto-Flow über das Frameset öffnen")
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
        print("[3/9] Gesperrte ETB-Karte mit erhaltenem Anmeldeziel öffnen")
        self.cdp.click(
            None,
            'a.estab-menu-link[data-estab-nav-key="incident-log"]',
            "gesperrte Root-Karte für das Einsatztagebuch",
        )
        self.cdp.wait_for(
            """
            document.readyState === "complete" &&
            location.pathname.endsWith("/4fach/index.php") &&
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
                const existing = doc.querySelector(
                    'button[name="login_flow"][value="existing"]'
                );
                return target.location.pathname.endsWith("/4fach/mainindex.php") &&
                    query.get("next") === "incident-log" &&
                    Boolean(existing) &&
                    !doc.querySelector('button[name="login_flow"][value="new"]');
                """,
            ),
            "Anmeldeauswahl hat das angeforderte ETB-Ziel nicht übernommen",
        )
        self.cdp.click(
            "mainframe",
            'button[name="login_flow"][value="existing"]',
            "Bestandskonto für das angeforderte ETB anmelden",
        )
        self.cdp.wait_for(
            _frame_expression(
                "mainframe",
                """
                const destination = doc.querySelector(
                    'input[name="next"][value="incident-log"]'
                );
                return Array.from(doc.querySelectorAll("h1, h2")).some(
                    heading => heading.innerText.includes(
                        "Mit bestehendem Konto anmelden"
                    )
                ) && Boolean(destination) && new target.URLSearchParams(
                    target.top.location.search
                ).get("next") === "incident-log";
                """,
            ),
            "Bestandskonto-Formular mit erhaltenem ETB-Ziel fehlt",
        )

        print("[4/9] Provisioniertes Konto anmelden und Einsatztagebuch öffnen")
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
            "Einsatztagebuch wurde nach der Anmeldung nicht als angefordertes Ziel geöffnet",
        )
        self._assert_session_bar(
            None,
            "Einsatztagebuch nach Bestandslogin",
            "incident-log",
        )

        self.cdp.click(
            None,
            '[data-estab-navigation] a[data-estab-nav-key="messages"]',
            "Nachrichtenvordruck aus dem angeforderten Einsatztagebuch",
        )
        self._wait_for_authenticated_frames()

        print("[5/9] Ungespeicherte fachliche Eingaben schützen den Bereichswechsel")
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

        self._assert_dirty_navigation_guard()
        self._wait_for_authenticated_overview(
            "angemeldete Übersicht wurde nach bestätigtem Bereichswechsel nicht geöffnet"
        )

        print("[6/9] Navigation über Übersicht, BOS und Einsatztagebuch")
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
        self._assert_generated_forms_tool()
        self._assert_attachment_upload_form()
        if self.config.admin_user and self.config.admin_password:
            self._assert_authenticated_administration_session_chrome()
        else:
            print(
                "      übersprungen: authentifizierte Admin-Sitzungsleisten "
                "ohne Admin-Testzugangsdaten"
            )

        print("[7/9] Logout aus dem Einsatztagebuch und Rückkehr in den anonymen Zustand")
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

        print("[8/9] Kartenraster in Desktop-, Zwischen- und Schmalansicht")
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

        print("[9/9] Adminübersicht, Exportverwaltung und Matrix-Bestätigungen")
        if self.config.admin_user and self.config.admin_password:
            self._assert_export_management()
        else:
            print("      übersprungen: keine Admin-Testzugangsdaten gesetzt")

    def _assert_export_management(self) -> None:
        assert self.config.admin_user is not None
        assert self.config.admin_password is not None
        credentials = (
            f"{self.config.admin_user}:{self.config.admin_password}".encode("utf-8")
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
                document.querySelectorAll("[data-estab-admin-card]").length === 8 &&
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
            f"{self.config.admin_user}:{self.config.admin_password}".encode(
                "utf-8"
            )
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
            int(state.get("targetCount", 0)) >= 1,
            f"{description}: Keine mindestens 44 px großen Aktionen gefunden.",
        )

    def _assert_anonymous_overview(self) -> None:
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
        self._equal(
            self.cdp.evaluate(_visible_count_expression(None, "#estab-register")),
            0,
            "unerwarteter Selbstregistrierungsbutton auf der anonymen Übersicht",
        )
        flow_urls = self.cdp.evaluate(
            """
            (() => {
                const existing = document.querySelector("#estab-login");
                const fresh = document.querySelector("#estab-register");
                if (!existing || fresh) return null;
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
        copy_is_clear = self.cdp.evaluate(
            """
            (() => {
                const text = document.body.innerText.replace(/\\s+/g, " ");
                return text.includes("Bestehendes Konto") &&
                    text.includes("nicht selbst angelegt") &&
                    text.includes("Administration") &&
                    text.includes("Benutzerverwaltung");
            })()
            """
        )
        self._truth(
            copy_is_clear,
            "Übersicht erklärt Bestandslogin und administrative Kontoanlage nicht klar.",
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
            6,
            "Anzahl anmeldepflichtiger Bereiche in der anonymen Navigation",
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
        if inner_width <= 672:
            self._truth(
                isinstance(sidebar_frame, dict)
                and isinstance(content_frame, dict)
                and abs(float(sidebar_frame.get("left", -1))) <= 0.5
                and abs(float(content_frame.get("left", -1))) <= 0.5
                and abs(float(sidebar_frame.get("top", -1))) <= 0.5
                and abs(float(sidebar_frame.get("bottom", -1)) - inner_height)
                <= 0.5
                and abs(float(content_frame.get("top", -1)) - inner_height)
                <= 0.5
                and abs(
                    float(content_frame.get("bottom", -1))
                    - (2 * inner_height)
                )
                <= 0.5
                and abs(float(sidebar_frame.get("width", 0)) - inner_width)
                <= 0.5
                and abs(float(content_frame.get("width", 0)) - inner_width)
                <= 0.5
                and abs(
                    float(workspace.get("scrollHeight", 0))
                    - (2 * inner_height)
                )
                <= 1,
                f"Sidebar und Inhalt bilden in {location} keine zwei vollen "
                "Viewport-Zeilen.",
            )
        else:
            self._truth(
                isinstance(sidebar_frame, dict)
                and isinstance(content_frame, dict)
                and abs(float(sidebar_frame.get("top", -1))) <= 0.5
                and abs(
                    float(sidebar_frame.get("bottom", -1)) - inner_height
                )
                <= 0.5
                and abs(float(content_frame.get("top", -1))) <= 0.5
                and abs(float(content_frame.get("bottom", -1)) - inner_height)
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
        self._equal(state.get("linkCount"), 10, f"Bereichslinks in {location}")
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
            and stale.get("navigationCount") == 10
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
        blocked = self.cdp.wait_for(
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
        inner_height = float(workspace.get("innerHeight", 0))
        self._truth(
            float(workspace.get("scrollWidth", 0)) <= inner_width + 1,
            f"BOS-Arbeitsbereich erzeugt in {location} horizontales Scrolling.",
        )
        if inner_width <= 672:
            self._truth(
                isinstance(sidebar_frame, dict)
                and isinstance(content_frame, dict)
                and abs(float(sidebar_frame.get("left", -1))) <= 0.5
                and abs(float(content_frame.get("left", -1))) <= 0.5
                and abs(float(sidebar_frame.get("top", -1))) <= 0.5
                and abs(float(sidebar_frame.get("bottom", -1)) - inner_height)
                <= 0.5
                and abs(float(content_frame.get("top", -1)) - inner_height)
                <= 0.5
                and abs(
                    float(content_frame.get("bottom", -1))
                    - (2 * inner_height)
                )
                <= 0.5
                and abs(float(sidebar_frame.get("width", 0)) - inner_width)
                <= 0.5
                and abs(float(content_frame.get("width", 0)) - inner_width)
                <= 0.5
                and abs(
                    float(workspace.get("scrollHeight", 0))
                    - (2 * inner_height)
                )
                <= 1,
                f"BOS-Navigation und Inhalt bilden in {location} keine "
                "zwei vollen mobilen Ansichten.",
            )
        else:
            self._truth(
                isinstance(sidebar_frame, dict)
                and isinstance(content_frame, dict)
                and abs(float(sidebar_frame.get("top", -1))) <= 0.5
                and abs(
                    float(sidebar_frame.get("bottom", -1)) - inner_height
                )
                <= 0.5
                and abs(float(content_frame.get("top", -1))) <= 0.5
                and abs(float(content_frame.get("bottom", -1)) - inner_height)
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
                const root = doc.scrollingElement;
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

    def _assert_dirty_navigation_guard(self) -> None:
        field_selector = (
            'form[name="4fach"][data-estab-dirty-guard] '
            'input#f_08_befhinweis'
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
        self.cdp.set_value(
            "mainframe",
            field_selector,
            dirty_value,
            "ungespeicherter Beförderungshinweis",
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
                    "/4fach/index.php?next=" + destination
                )
                or card.get("target") is not None
                or card.get("badge") != "Anmeldung erforderlich"
            ):
                raise TestFailure(
                    "Mindestens eine anonyme Modulkarte besitzt keinen eindeutigen Anmeldeschutz."
                )

        paths_json = json.dumps(self.protected_paths)
        results = self.cdp.evaluate(
            f"""
            (async () => {{
                const paths = {paths_json};
                return Promise.all(paths.map(async path => {{
                    let status = 0;
                    try {{
                        const response = await fetch(new URL(path, location.href), {{
                            credentials: "same-origin",
                            redirect: "manual",
                            cache: "no-store"
                        }});
                        status = response.status;
                    }} catch (_error) {{
                        status = -1;
                    }}
                    return {{path, status}};
                }}));
            }})()
            """
        )
        if not isinstance(results, list):
            raise TestFailure("Status der geschützten Modulkarten konnte nicht geprüft werden.")
        failures = [
            result.get("path", "unbekannt")
            for result in results
            if result.get("status") != 403
        ]
        if failures:
            raise TestFailure(
                "Direkte anonyme Modulzugriffe sind nicht vollständig mit HTTP 403 gesperrt: "
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
        expected_navigation_keys = list(self.navigation_keys)
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
            "Testet ESTAB-Anmeldung, Kontoanlage, Session-Anzeige und Logout "
            "in einem echten Headless-Chrome."
        )
    )
    parser.add_argument(
        "--check-browser",
        action="store_true",
        help="nur Chrome/Chromium suchen und ohne Anwendungstest beenden",
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
        config = TestConfig.from_environment()
        chrome = ChromeProcess(binary, config.startup_timeout)
        chrome.start()
        if chrome.websocket_url is None:
            raise TestFailure("Chrome hat keine Debugging-Adresse bereitgestellt.")
        websocket = WebSocket(chrome.websocket_url, config.timeout)
        cdp = CDP(websocket, config.timeout)
        acceptance = BrowserAcceptance(cdp, config)
        if arguments.export_only and arguments.bos_only:
            raise TestFailure(
                "--export-only und --bos-only können nicht kombiniert werden."
            )
        if arguments.bos_only:
            acceptance.run_bos()
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
