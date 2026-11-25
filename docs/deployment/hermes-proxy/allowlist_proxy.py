#!/usr/bin/env python3
"""Path-allowlist reverse proxy for the Hermes webhook listener (D2).

Replaces the earlier socat relay, which forwarded every path. Only the production webhook route is forwarded:
    POST /webhooks/joyumkm-events
Everything else returns 404 without contacting Hermes. The request body is forwarded byte-for-byte because the
HMAC signature is computed over it. Chunked request bodies are refused (411) so the body length is always known.
Logs contain method, path, status and size only: never headers, bodies or secrets.
"""
import http.client
import os
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

UPSTREAM_HOST = os.environ.get("UPSTREAM_HOST", "hermes-agent")
UPSTREAM_PORT = int(os.environ.get("UPSTREAM_PORT", "8644"))
LISTEN_PORT = int(os.environ.get("LISTEN_PORT", "8644"))
ALLOWED = {("POST", "/webhooks/joyumkm-events"), ("POST", "/webhooks/joyumkm-events-dev")}
MAX_BODY_BYTES = 1024 * 1024
UPSTREAM_TIMEOUT_SECONDS = 30
HOP_BY_HOP = {
    "connection", "keep-alive", "proxy-authenticate", "proxy-authorization", "proxy-connection",
    "te", "trailer", "transfer-encoding", "upgrade", "host", "content-length",
}


class AllowlistProxy(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "joyumkm-hermes-proxy"

    def log_message(self, fmt, *args):
        sys.stdout.write("%s %s\n" % (self.address_string(), fmt % args))
        sys.stdout.flush()

    def _path_only(self) -> str:
        return self.path.split("?", 1)[0]

    def _deny(self, status: int) -> None:
        self.send_response(status)
        self.send_header("Content-Length", "0")
        self.send_header("Connection", "close")
        self.end_headers()
        self.close_connection = True

    def _forward(self) -> None:
        method = self.command
        path = self._path_only()
        if (method, path) not in ALLOWED:
            self._deny(404)
            return
        if "chunked" in self.headers.get("Transfer-Encoding", "").lower():
            self._deny(411)
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            self._deny(400)
            return
        if length < 0 or length > MAX_BODY_BYTES:
            self._deny(413)
            return
        body = self.rfile.read(length) if length else b""

        headers = {k: v for k, v in self.headers.items() if k.lower() not in HOP_BY_HOP}
        headers["Host"] = f"{UPSTREAM_HOST}:{UPSTREAM_PORT}"
        headers["Content-Length"] = str(len(body))
        headers["Connection"] = "close"

        conn = http.client.HTTPConnection(UPSTREAM_HOST, UPSTREAM_PORT, timeout=UPSTREAM_TIMEOUT_SECONDS)
        try:
            conn.request(method, self.path, body=body, headers=headers)
            upstream = conn.getresponse()
            payload = upstream.read()
        except OSError:
            self._deny(502)
            return
        finally:
            conn.close()

        self.send_response(upstream.status)
        for key, value in upstream.getheaders():
            if key.lower() not in HOP_BY_HOP:
                self.send_header(key, value)
        self.send_header("Content-Length", str(len(payload)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(payload)
        self.close_connection = True

    do_POST = _forward
    do_GET = _forward
    do_PUT = _forward
    do_DELETE = _forward
    do_PATCH = _forward
    do_HEAD = _forward
    do_OPTIONS = _forward


def main() -> None:
    server = ThreadingHTTPServer(("0.0.0.0", LISTEN_PORT), AllowlistProxy)
    sys.stdout.write(f"allowlist proxy listening on :{LISTEN_PORT} -> {UPSTREAM_HOST}:{UPSTREAM_PORT}\n")
    sys.stdout.flush()
    server.serve_forever()


if __name__ == "__main__":
    main()
