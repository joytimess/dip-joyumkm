"""Loopback-published ingress for the umkm-predict Visual Workflow."""
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import re
import time

from .prediction import ROUTE, WORKFLOW, execute
from .schema import validate_designer_graph
from .store import DesignStore, RunStore

PATHS = {"/webhook-test" + ROUTE: "test", "/webhook" + ROUTE: "active"}
MAX_BYTES = 4096
TIMESTAMP = "X-JoyUMKM-Prediction-Timestamp"
SIGNATURE = "X-JoyUMKM-Prediction-Signature"


def signed(secret, stamp, signature, path, body):
    if not re.fullmatch(r"[0-9]{10}", stamp) or abs(time.time() - int(stamp)) > 60:
        return False
    if not re.fullmatch(r"[a-f0-9]{64}", signature):
        return False
    message = stamp.encode() + b"\n" + path.encode() + b"\n" + body
    return hmac.compare_digest(signature, hmac.digest(secret, message, hashlib.sha256).hex())


class PredictionHandler(BaseHTTPRequestHandler):
    def setup(self):
        super().setup()
        self.connection.settimeout(3)

    def reply(self, status, body, run_id=None):
        encoded = json.dumps(body, ensure_ascii=False, allow_nan=False).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(encoded)))
        self.send_header("Cache-Control", "no-store")
        if run_id:
            self.send_header("X-JoyUMKM-Prediction-Run", run_id)
        self.end_headers()
        self.wfile.write(encoded)

    def do_GET(self):
        self.reply(405 if self.path in PATHS else 404, {"success": False})

    def do_POST(self):
        mode = PATHS.get(self.path)
        if not mode:
            return self.reply(404, {"success": False})
        length = self.headers.get("Content-Length", "")
        if self.headers.get("Transfer-Encoding") or not re.fullmatch(r"[0-9]{1,10}", length):
            return self.reply(400, {"success": False, "message": "Content-Length tidak valid."})
        size = int(length)
        if size > MAX_BYTES:
            return self.reply(413, {"success": False, "message": "Permintaan terlalu besar."})
        stamp = self.headers.get_all(TIMESTAMP, [])
        signature = self.headers.get_all(SIGNATURE, [])
        if not stamp or not signature:
            return self.reply(401, {"success": False, "message": "Autentikasi diperlukan."})
        body = self.rfile.read(size)
        if (len(body) != size or len(stamp) != 1 or len(signature) != 1
                or not signed(self.server.secret, stamp[0], signature[0], self.path, body)):
            return self.reply(403, {"success": False, "message": "Tanda tangan tidak valid."})

        try:
            graph = self.server.designs.load(WORKFLOW)
            if validate_designer_graph(graph):
                raise ValueError("invalid_prediction_design")
            trigger = next(node for node in graph["nodes"] if node["type"] == "trigger")
            if mode == "active" and not trigger["active"]:
                return self.reply(404, {"success": False, "message": "Workflow belum aktif."})
            state = execute(graph, body, mode, self.server.runs)
        except (OSError, ValueError):
            return self.reply(503, {"success": False, "message": "Workflow tidak tersedia."})

        result = state.get("http_response")
        if not result or state["status"] != "done":
            return self.reply(503, {"success": False, "message": "Eksekusi workflow gagal."}, state["run_id"])
        self.reply(result["status_code"], result["body"], state["run_id"])


def main():
    secret = Path("/opt/data/joyumkm-prediction/secret").read_bytes().strip()
    if len(secret) < 32:
        raise ValueError("prediction secret is missing or too short")
    with ThreadingHTTPServer(("0.0.0.0", 8651), PredictionHandler) as server:
        server.secret = secret
        server.designs = DesignStore()
        server.runs = RunStore()
        server.serve_forever()


if __name__ == "__main__":
    main()
