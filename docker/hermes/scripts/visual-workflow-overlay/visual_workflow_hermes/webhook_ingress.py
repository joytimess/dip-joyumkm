"""Shared hardened workflow ingress for Hermes Visual Workflow webhooks.

Hosts:
- Port 8650: JoyUMKM onboarding receiver (POST /joyumkm-onboarding, GET /health)
- Port 8651: JoyUMKM prediction receiver (POST /webhook/predict, POST /webhook-test/predict)
             plus dynamic extensible webhook routes (POST /webhook/<slug>, POST /webhook-test/<slug>)
             resolved from /opt/data/workflow-designs/*.json without creating new containers.
"""
import fcntl
import hashlib
import hmac
from http.server import ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import signal
import sys
from threading import Thread
import time
import uuid

from .convert import build_run_plan
from .onboarding_receiver import Handler as OnboardingHandler, Receiver as OnboardingReceiver
from .prediction_receiver import PATHS as PREDICTION_PATHS, PredictionHandler
from .runner import new_state, run_plan
from .schema import validate_designer_graph
from .store import DesignStore, RunStore


def _find_dynamic_webhook(designs: DesignStore, path: str):
    """Match /webhook/<slug> or /webhook-test/<slug> to a Visual Workflow design."""
    if path.startswith("/webhook-test/"):
        mode, slug = "test", path[len("/webhook-test/"):]
    elif path.startswith("/webhook/"):
        mode, slug = "active", path[len("/webhook/"):]
    else:
        return None, None
    if not slug or "/" in slug or not re.fullmatch(r"[A-Za-z0-9._-]+", slug):
        return None, None
    for fp in sorted(designs.root.glob("*.json")):
        try:
            graph = json.loads(fp.read_text(encoding="utf-8"))
            name = graph.get("name", fp.stem)
        except (OSError, ValueError):
            continue
        for node in graph.get("nodes", []):
            tmode = node.get("trigger_mode") or node.get("mode")
            if node.get("type") == "trigger" and (tmode == "webhook" or node.get("webhook_route")):
                route = str(node.get("webhook_route") or node.get("path") or node.get("route") or "").lstrip("/")
                if route == slug or name == slug:
                    return mode, graph
    return None, None


class SharedWebhookHandler(PredictionHandler):
    """Preserves PredictionHandler 1:1 for /webhook/predict and extends to dynamic webhook routes."""

    def _dynamic_auth_ok(self, trigger: dict, body: bytes) -> bool:
        auth_mode = trigger.get("auth", "none")
        if auth_mode == "hmac_sha256":
            secret_file = Path("/opt/data/joyumkm-prediction/secret")
            if not secret_file.exists():
                return False
            secret = secret_file.read_bytes().strip()
            sig = self.headers.get("X-Hermes-Signature", "")
            expected = "sha256=" + hmac.new(secret, body, hashlib.sha256).hexdigest()
            return bool(sig) and hmac.compare_digest(expected, sig)
        return True

    def do_POST(self):
        if self.path in PREDICTION_PATHS:
            return super().do_POST()
        mode, graph = _find_dynamic_webhook(self.server.designs, self.path)
        if not graph:
            return self.reply(404, {"success": False})
        length = self.headers.get("Content-Length", "")
        if self.headers.get("Transfer-Encoding") or not re.fullmatch(r"[0-9]{1,7}", length):
            return self.reply(400, {"success": False, "message": "Invalid Content-Length."})
        size = int(length)
        if size > 65536:
            return self.reply(413, {"success": False, "message": "Payload too large."})
        body = self.rfile.read(size) if size else b""
        if len(body) != size:
            return self.reply(400, {"success": False, "message": "Incomplete body."})
        if validate_designer_graph(graph):
            return self.reply(503, {"success": False, "message": "Invalid workflow design."})
        trigger = next((n for n in graph.get("nodes", []) if n.get("type") == "trigger"), {})
        if not self._dynamic_auth_ok(trigger, body):
            return self.reply(401, {"success": False, "message": "Unauthorized."})
        if mode == "active" and trigger.get("active") is False:
            return self.reply(404, {"success": False, "message": "Workflow inactive."})
        try:
            payload = json.loads(body.decode("utf-8")) if body else {}
        except ValueError:
            return self.reply(422, {"success": False, "message": "Invalid JSON."})
        plan = build_run_plan(graph)
        run_id = uuid.uuid4().hex[:12]
        state = new_state(plan, run_id)
        state["webhook_mode"] = mode
        state["webhook_payload"] = payload
        self.server.runs.save(state)
        run_plan(plan, state, on_step=self.server.runs.save)
        return self.reply(
            200 if state.get("status") == "done" else 500,
            {
                "success": state.get("status") == "done",
                "workflow": graph.get("name"),
                "run_id": run_id,
                "status": state.get("status"),
                "outputs": state.get("outputs", {}),
            },
            run_id,
        )


def main():
    os.umask(0o077)
    home = Path(os.environ.get("HERMES_HOME") or Path.home() / ".hermes")
    onboarding_root = home / "joyumkm-onboarding"
    pred_secret_path = home / "joyumkm-prediction" / "secret"
    pred_secret = pred_secret_path.read_bytes().strip()
    if len(pred_secret) < 32:
        raise ValueError("prediction secret is missing or too short")

    with (onboarding_root / "receiver.lock").open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        settings = json.loads((onboarding_root / "settings.json").read_text(encoding="utf-8"))
        onboarding_receiver = OnboardingReceiver(home, settings)

        onboarding_server = ThreadingHTTPServer(("0.0.0.0", 8650), OnboardingHandler)
        onboarding_server.receiver = onboarding_receiver

        prediction_server = ThreadingHTTPServer(("0.0.0.0", 8651), SharedWebhookHandler)
        prediction_server.secret = pred_secret
        prediction_server.designs = DesignStore(root=home / "workflow-designs")
        prediction_server.runs = RunStore(root=home / "workflow-runs")

        def _shutdown(_signum, _frame):
            Thread(target=onboarding_server.shutdown, daemon=True).start()
            Thread(target=prediction_server.shutdown, daemon=True).start()

        signal.signal(signal.SIGTERM, _shutdown)
        signal.signal(signal.SIGINT, _shutdown)

        Thread(target=onboarding_receiver.work, daemon=True).start()
        t_onb = Thread(target=onboarding_server.serve_forever, daemon=True)
        t_onb.start()
        print("hermes-workflow-ingress listening on :8650 (onboarding) and :8651 (prediction + dynamic webhooks)", flush=True)
        try:
            prediction_server.serve_forever()
        finally:
            onboarding_server.server_close()
            prediction_server.server_close()


if __name__ == "__main__":
    main()