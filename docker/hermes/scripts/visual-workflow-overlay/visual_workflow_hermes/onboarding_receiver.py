"""Loopback-only signed ingress and durable admission for the existing Visual Workflow runner."""
from contextlib import closing
from hashlib import sha256
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import sqlite3
from threading import Event, Thread
from urllib.parse import urlsplit
import uuid

from .convert import build_run_plan
from .onboarding import ENDPOINT, MAX_BODY_BYTES, WORKFLOW, RegistrationNodes, now, validate_event
from .runner import new_state, run_plan
from .schema import validate_designer_graph
from .store import DesignStore, RunStore


def unique_object(pairs):
    result = dict(pairs)
    if len(result) != len(pairs):
        raise ValueError("duplicate_json_key")
    return result


class Receiver:
    def __init__(self, home, settings):
        self.settings = settings
        url = urlsplit(settings["app_url"])
        local_dev = url.scheme == "http" and url.hostname in {"localhost", "127.0.0.1"}
        if (not url.hostname or url.username or url.password or
                (url.scheme != "https" and not local_dev)):
            raise ValueError("invalid_application_url")
        secret = settings["secret"]
        if not isinstance(secret, str) or not secret.isascii() or len(secret) < 32:
            raise ValueError("invalid_webhook_secret")
        self.secret = secret.encode()
        self.settings["allowlist"] = {email.lower() for email in settings["allowlist"]}
        self.settings["enabled"] = settings.get("enabled") is True
        root = home / "joyumkm-onboarding"
        self.db = root / "receipts.sqlite3"
        self.designs = DesignStore(root=home / "workflow-designs")
        self.runs = RunStore(root=home / "workflow-runs")
        self.wake = Event()
        with closing(sqlite3.connect(self.db)) as conn, conn:
            conn.execute("""CREATE TABLE IF NOT EXISTS receipts (
                event_id TEXT PRIMARY KEY, body_hash TEXT NOT NULL, run_id TEXT NOT NULL UNIQUE,
                received_at TEXT NOT NULL, event TEXT, plan TEXT, smtp_started INTEGER NOT NULL DEFAULT 0
            )""")

    def admit(self, body, signature):
        if not isinstance(signature, str) or not re.fullmatch(r"sha256=[a-f0-9]{64}", signature):
            return 401, {"error": "invalid_signature"}
        expected = "sha256=" + hmac.new(self.secret, body, sha256).hexdigest()
        if not hmac.compare_digest(expected, signature):
            return 401, {"error": "invalid_signature"}
        try:
            event = validate_event(json.loads(body.decode("utf-8"), object_pairs_hook=unique_object))
        except (ValueError, RecursionError):
            return 422, {"error": "invalid_registration_event"}
        event_id = event["event_id"]
        body_hash = sha256(body).hexdigest()
        with closing(sqlite3.connect(self.db)) as conn, conn:
            conn.execute("BEGIN IMMEDIATE")
            prior = conn.execute("SELECT run_id, body_hash FROM receipts WHERE event_id = ?",
                                 (event_id,)).fetchone()
            if prior:
                run_id, previous_hash = prior
                if not hmac.compare_digest(previous_hash, body_hash):
                    return 409, {"error": "conflicting_event_id"}
                return 200, {"status": "duplicate", "event_id": event_id, "run_id": run_id}
            try:
                designer = self.designs.load(WORKFLOW)
                if validate_designer_graph(designer) or designer.get("name") != WORKFLOW:
                    raise ValueError("invalid_onboarding_workflow")
                plan = build_run_plan(designer)
            except (ValueError, FileNotFoundError):
                return 503, {"error": "workflow_unavailable"}
            run_id = uuid.uuid4().hex[:12]
            conn.execute("""INSERT INTO receipts (event_id, body_hash, run_id, received_at, event, plan)
                         VALUES (?, ?, ?, ?, ?, ?)""",
                         (event_id, body_hash, run_id, now(), json.dumps(event), json.dumps(plan)))
        self.wake.set()
        return 202, {"status": "accepted", "event_id": event_id, "run_id": run_id}

    def claim_smtp(self, event_id):
        with closing(sqlite3.connect(self.db)) as conn, conn:
            update = conn.execute("""UPDATE receipts SET smtp_started = 1
                WHERE event_id = ? AND smtp_started = 0""", (event_id,))
            return update.rowcount == 1

    def work_once(self):
        with closing(sqlite3.connect(self.db)) as conn:
            pending = conn.execute("""SELECT event_id, run_id, received_at, event, plan FROM receipts
                WHERE event IS NOT NULL ORDER BY rowid LIMIT 1""").fetchone()
        if not pending:
            return False
        event_id, run_id, received_at, payload, serialized_plan = pending
        event, plan = json.loads(payload), json.loads(serialized_plan)
        try:
            state = self.runs.load(run_id)
        except FileNotFoundError:
            state = new_state(plan, run_id)
            state.update(registration_event_id=event_id, received_at=received_at, node_records={})
            self.runs.save(state)
        if state["status"] == "running":
            run_plan(plan, state, on_step=self.runs.save,
                     registration=RegistrationNodes(event, self.settings, lambda: self.claim_smtp(event_id)))
        with closing(sqlite3.connect(self.db)) as conn, conn:
            conn.execute("PRAGMA secure_delete = ON")
            conn.execute("UPDATE receipts SET event = NULL, plan = NULL WHERE event_id = ?", (event_id,))
        return True

    def work(self):
        while True:
            try:
                if self.work_once():
                    continue
            except (OSError, sqlite3.Error, ValueError):
                print("Onboarding storage temporarily unavailable.", flush=True)
            self.wake.wait(timeout=1)
            self.wake.clear()


class Handler(BaseHTTPRequestHandler):
    def setup(self):
        super().setup()
        self.connection.settimeout(3)

    def log_message(self, *_args):
        pass

    def reply(self, status, result):
        body = json.dumps(result).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/health":
            self.reply(200, {"status": "ok"})
        else:
            self.reply(404, {"error": "not_found"})

    def do_POST(self):
        if self.path != ENDPOINT:
            return self.reply(404, {"error": "not_found"})
        lengths = self.headers.get_all("Content-Length") or []
        signatures = self.headers.get_all("X-JoyUMKM-Signature") or []
        if self.headers.get("Transfer-Encoding") or len(lengths) != 1:
            return self.reply(400, {"error": "invalid_request"})
        length = lengths[0]
        if not re.fullmatch(r"[0-9]{1,7}", length) or not 0 < int(length) <= MAX_BODY_BYTES:
            return self.reply(413, {"error": "invalid_body_size"})
        if self.headers.get_content_type() != "application/json":
            return self.reply(415, {"error": "expected_json"})
        if len(signatures) != 1:
            return self.reply(401, {"error": "invalid_signature"})
        try:
            body = self.rfile.read(int(length))
        except TimeoutError:
            return self.reply(408, {"error": "request_timeout"})
        if len(body) != int(length):
            return self.reply(400, {"error": "incomplete_body"})
        try:
            status, result = self.server.receiver.admit(body, signatures[0])
        except (OSError, sqlite3.Error):
            status, result = 503, {"error": "storage_unavailable"}
        self.reply(status, result)


def main():
    import fcntl

    os.umask(0o077)
    home = Path(os.environ.get("HERMES_HOME") or Path.home() / ".hermes")
    root = home / "joyumkm-onboarding"
    with (root / "receiver.lock").open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        settings = json.loads((root / "settings.json").read_text(encoding="utf-8"))
        receiver = Receiver(home, settings)
        with ThreadingHTTPServer(("0.0.0.0", 8650), Handler) as server:
            server.receiver = receiver
            Thread(target=receiver.work, daemon=True).start()
            server.serve_forever()


if __name__ == "__main__":
    main()
