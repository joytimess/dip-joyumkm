"""P4-04C: durable Hermes -> Laravel report callback.

Sends the already-stored ``report.json`` of a completed job to Laravel as an HMAC-SHA256 signed POST.
It NEVER calls a model and never regenerates a report. Analysis status (``job.status``) and delivery
status (``job["callback"]["status"]``) are independent.

Delivery state machine (``job["callback"]["status"]``):
    pending -> sending -> delivered | retry_wait | failed_permanent | failed_config | unmatched_event
    retry_wait -> sending (when due) ... -> failed_exhausted after MAX attempts
    archived_unmatched: historical job without a Laravel outbox pair; never sent, never swept.

Safety rules: fail closed on invalid URL/secret (no network), the same body bytes on every attempt of an
event (payload_hash), claim under the per-event metadata lock + a per-event ``.cb`` flock for the whole
send (a free ``.cb`` lock with status ``sending`` means the sender died), and no secret / raw response /
report content in job.json, results or logs.
"""
from __future__ import annotations

import hashlib
import hmac
import http.client
import json
import os
import random
import re
import socket
import subprocess
import sys
import time
import uuid
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit

from . import engine, store

PKG = Path(__file__).resolve().parent.parent
REGISTRY_PATH = Path(__file__).with_name("callback_registry.json")
ENV_FILE = "callback.env"

CALLBACK_SCHEMA_VERSION = 1
MAX_BODY_BYTES = 2 * 1024 * 1024
MAX_RESPONSE_BYTES = 64 * 1024
MIN_SECRET_LENGTH = 32
DEFAULT_TIMEOUT = 15
DEFAULT_MAX_ATTEMPTS = 8
HARD_MAX_ATTEMPTS = 8
BACKOFF_BASE_SECONDS = 60
BACKOFF_MAX_SECONDS = 3600
BACKOFF_JITTER = 0.2

PENDING, SENDING, RETRY_WAIT, DELIVERED = "pending", "sending", "retry_wait", "delivered"
FAILED_PERMANENT, FAILED_CONFIG, UNMATCHED_EVENT = "failed_permanent", "failed_config", "unmatched_event"
FAILED_EXHAUSTED, ARCHIVED = "failed_exhausted", "archived_unmatched"
STATUSES = (PENDING, SENDING, RETRY_WAIT, DELIVERED, FAILED_PERMANENT, FAILED_CONFIG, UNMATCHED_EVENT, FAILED_EXHAUSTED, ARCHIVED)
OPERATOR_ONLY = (FAILED_CONFIG, FAILED_EXHAUSTED, UNMATCHED_EVENT)  # never retried by the sweep; `callback <id>` may re-drive them
TERMINAL = (DELIVERED, ARCHIVED, FAILED_PERMANENT)

REQUIRED_REPORT_KEYS = ("schema_version", "event_id", "source_event_version", "umkm_id", "evaluation_id", "status", "model", "provider",
                        "generated_at", "methodology_status", "knowledge_version", "prompt_version", "report")
KNOWN_RESPONSE_CODES = {"callback_unavailable", "payload_too_large", "unsupported_media_type", "unauthorized", "report_conflict"}
_FIELD_NAME = re.compile(r"^[A-Za-z0-9_.*\-]{1,80}$")


class CallbackPayloadError(Exception):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


class TransportError(Exception):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


# ----------------------------------------------------------------------------------------------------------------- config

@dataclass(frozen=True)
class Config:
    url: str
    secret: bytes = field(repr=False)
    timeout: int = DEFAULT_TIMEOUT
    max_attempts: int = DEFAULT_MAX_ATTEMPTS
    http_host: str = ""  # optional Host header for vhost routing (e.g. Laragon port 80 behind host.docker.internal)


_HTTP_HOST = re.compile(r"[A-Za-z0-9.-]{1,253}(?::[0-9]{1,5})?")


def valid_http_host(value: str) -> bool:
    return _HTTP_HOST.fullmatch(value) is not None


def with_host(headers: dict, host: str) -> dict:
    """Return headers with the Host override applied when configured; the signature never covers Host."""
    return {**headers, "Host": host} if host else headers

    def __repr__(self) -> str:  # never expose the secret, even via repr()/logging
        return f"Config(url={self.url!r}, timeout={self.timeout}, max_attempts={self.max_attempts})"


def _read_env_file(name: str = ENV_FILE) -> tuple[dict, list]:
    """Fallback secret store: ``<JMI_HOME>/callback.env`` (KEY=VALUE, must be private: no group/other bits)."""
    path = store.home() / name
    if not path.exists():
        return {}, []
    if os.stat(path).st_mode & 0o077:
        return {}, ["CALLBACK_ENV_FILE_PERMISSIONS"]
    return _parse_env_text(path.read_text()), []


def _parse_env_text(text):
    out = {}
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip().strip("'\"")
    return out


def _collect_secrets(node, acc: list) -> None:
    if isinstance(node, dict):
        for k, v in node.items():
            if k == "secret" and isinstance(v, str):
                acc.append(v)
            else:
                _collect_secrets(v, acc)
    elif isinstance(node, list):
        for v in node:
            _collect_secrets(v, acc)


def _webhook_secrets(environ) -> list:
    """Outbound-webhook secrets the callback secret must differ from (env + Hermes route subscriptions)."""
    found = [environ["HERMES_WEBHOOK_SECRET"]] if environ.get("HERMES_WEBHOOK_SECRET") else []
    try:
        p = Path(environ.get("HERMES_HOME", "/opt/data")) / "webhook_subscriptions.json"
        if p.exists():
            _collect_secrets(json.loads(p.read_text()), found)
    except Exception:
        pass
    return found


def _int_setting(raw, default, lo, hi, code, problems) -> int:
    if raw in (None, ""):
        return default
    try:
        v = int(str(raw).strip())
    except ValueError:
        problems.append(code)
        return default
    if not lo <= v <= hi:
        problems.append(code)
        return default
    return v


def load_config(environ=None) -> tuple:
    """Return ``(Config | None, problems)``. ``problems`` are fixed codes, never values. Fail closed."""
    environ = os.environ if environ is None else environ
    file_vals, problems = _read_env_file() if environ is os.environ else ({}, [])

    def get(name):
        v = environ.get(name)
        return v if v not in (None, "") else file_vals.get(name)

    url = (get("INTELLIGENCE_CALLBACK_URL") or "").strip()
    if not url:
        problems.append("CALLBACK_URL_MISSING")
    else:
        try:
            parts = urlsplit(url)
            parts.port  # noqa: B018 - validates the port
            ok = (parts.scheme in ("http", "https") and bool(parts.hostname) and not parts.username and not parts.password
                  and not parts.query and not parts.fragment and parts.path not in ("", "/"))
        except ValueError:
            ok = False
        if not ok:
            problems.append("CALLBACK_URL_INVALID")
    secret = get("INTELLIGENCE_CALLBACK_SECRET") or ""
    if not secret:
        problems.append("CALLBACK_SECRET_MISSING")
    elif len(secret) < MIN_SECRET_LENGTH:
        problems.append("CALLBACK_SECRET_TOO_SHORT")
    elif any(hmac.compare_digest(secret.encode(), other.encode()) for other in _webhook_secrets(environ)):
        problems.append("CALLBACK_SECRET_REUSED")
    timeout = _int_setting(get("INTELLIGENCE_CALLBACK_TIMEOUT_SECONDS"), DEFAULT_TIMEOUT, 1, 60, "CALLBACK_TIMEOUT_INVALID", problems)
    attempts = _int_setting(get("INTELLIGENCE_CALLBACK_MAX_ATTEMPTS"), DEFAULT_MAX_ATTEMPTS, 1, HARD_MAX_ATTEMPTS, "CALLBACK_MAX_ATTEMPTS_INVALID", problems)
    host = (get("INTELLIGENCE_CALLBACK_HTTP_HOST") or "").strip()
    if host and not valid_http_host(host):
        problems.append("CALLBACK_HTTP_HOST_INVALID")
    if problems:
        return None, problems
    return Config(url=url, secret=secret.encode(), timeout=timeout, max_attempts=attempts, http_host=host), []


# --------------------------------------------------------------------------------------------------------- time/registry

def _iso(epoch: float) -> str:
    return datetime.fromtimestamp(epoch, timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%f")[:-3] + "Z"


def _epoch(text) -> float | None:
    try:
        return datetime.strptime(text, "%Y-%m-%dT%H:%M:%S.%fZ").replace(tzinfo=timezone.utc).timestamp()
    except (TypeError, ValueError):
        return None


def registry() -> dict:
    """Verified eligibility lists: which historical jobs have a Laravel outbox pair (and which do not)."""
    reg = json.loads(REGISTRY_PATH.read_text())
    return {"legacy_cutoff": reg["legacy_cutoff"], "archived": {x["event_id"]: x for x in reg["archived_unmatched"]},
            "eligible": {x["event_id"]: x for x in reg["verified_eligible"]}, "archive_reason": reg["archive_reason"]}


def _state(status=PENDING, **kw) -> dict:
    cb = {"schema_version": CALLBACK_SCHEMA_VERSION, "status": status, "attempts": 0, "cycle_attempts": 0, "next_attempt_at": None,
          "last_http_status": None, "last_error_code": None, "delivered_at": None, "payload_hash": None}
    cb.update(kw)
    return cb


def _initial_state(job: dict, reg: dict) -> dict:
    eid = job["event_id"]
    if eid in reg["archived"]:
        return _state(ARCHIVED, archived_at=store.now(), archived_reason=reg["archive_reason"])
    if str(job.get("created_at", "")) < reg["legacy_cutoff"] and eid not in reg["eligible"]:
        return _state(UNMATCHED_EVENT, last_error_code="LEGACY_UNVERIFIED")  # old job, no verified outbox pair: operator decides
    return _state(PENDING)


def _valid_state(cb) -> bool:
    return isinstance(cb, dict) and cb.get("status") in STATUSES


def virtual_state(job: dict, reg: dict | None = None) -> dict:
    """Callback state as it is / would be after lazy migration. Pure: never writes."""
    cb = job.get("callback")
    if _valid_state(cb):
        if job["event_id"] in (reg or registry())["archived"] and cb["status"] not in (DELIVERED, ARCHIVED):
            return _initial_state(job, reg or registry())
        return cb
    return _initial_state(job, reg or registry())


def ensure_state(eid: str) -> dict | None:
    """Idempotent lazy migration of a completed job (report/payload untouched). Returns the fresh job."""
    store.check_id(eid)
    reg = registry()
    job = store.read_job(eid)
    if job is None or job.get("status") != "completed":
        return job
    if _valid_state(job.get("callback")) and virtual_state(job, reg) is job["callback"]:
        return job

    def f(j):
        if j.get("status") == "completed":
            j["callback"] = virtual_state(j, reg)
    return store.update_job(eid, f)


def is_regen_protected(job) -> bool:
    """Delivered or archived reports are never reset to pending for regeneration."""
    cb = job.get("callback") if isinstance(job, dict) else None
    return _valid_state(cb) and cb["status"] in (DELIVERED, ARCHIVED)


# ---------------------------------------------------------------------------------------------------------------- payload

@dataclass(frozen=True)
class Payload:
    body: bytes
    sha256: str
    event_id: str
    evaluation_id: int
    umkm_id: int
    source_event_version: int
    prompt_version: str


def canonical_body(report_doc: dict) -> bytes:
    return json.dumps(report_doc, ensure_ascii=False, separators=(",", ":"), allow_nan=False).encode("utf-8")


def build_payload(eid: str, job: dict) -> Payload:
    """Read + validate the stored report and return the exact bytes to sign and send."""
    try:
        doc = store.read_report(eid)
    except Exception:
        raise CallbackPayloadError("REPORT_UNREADABLE")
    if doc is None:
        raise CallbackPayloadError("REPORT_MISSING")
    if not isinstance(doc, dict) or any(k not in doc for k in REQUIRED_REPORT_KEYS) or not isinstance(doc["report"], dict):
        raise CallbackPayloadError("REPORT_MALFORMED")
    if (doc["event_id"] != eid or doc["status"] != "completed" or doc["evaluation_id"] != job.get("evaluation_id")
            or doc["umkm_id"] != job.get("umkm_id") or doc["source_event_version"] != job.get("source_event_version")):
        raise CallbackPayloadError("REPORT_IDENTITY_MISMATCH")
    if not engine.report_matches_job(eid):
        raise CallbackPayloadError("REPORT_INTEGRITY_FAILED")
    try:
        body = canonical_body({key: value for key, value in doc.items() if key != "monthly_extension"})
    except (TypeError, ValueError):
        raise CallbackPayloadError("REPORT_MALFORMED")
    if len(body) > MAX_BODY_BYTES:
        raise CallbackPayloadError("PAYLOAD_TOO_LARGE")
    return Payload(body, hashlib.sha256(body).hexdigest(), eid, doc["evaluation_id"], doc["umkm_id"], doc["source_event_version"], doc["prompt_version"])


def sign(secret: bytes, timestamp: str, body: bytes) -> str:
    return hmac.new(secret, timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()


def build_headers(secret: bytes, body: bytes, clock=time.time) -> dict:
    ts = str(int(clock()))  # fresh on every attempt
    return {"Content-Type": "application/json", "Accept": "application/json", "User-Agent": "jmi-callback/1",
            "X-Intelligence-Timestamp": ts, "X-Intelligence-Signature": sign(secret, ts, body)}


# -------------------------------------------------------------------------------------------------------------- transport

def http_post(url: str, body: bytes, headers: dict, timeout: float) -> tuple:
    """POST once. No redirects followed, bounded response read. Returns ``(status, response_bytes)``."""
    parts = urlsplit(url)
    cls = http.client.HTTPSConnection if parts.scheme == "https" else http.client.HTTPConnection
    conn = cls(parts.hostname, parts.port, timeout=timeout)
    try:
        conn.request("POST", parts.path or "/", body=body, headers=headers)
        resp = conn.getresponse()
        return resp.status, resp.read(MAX_RESPONSE_BYTES)
    except (socket.timeout, TimeoutError):
        raise TransportError("TIMEOUT")
    except (OSError, http.client.HTTPException):
        raise TransportError("NETWORK_ERROR")
    finally:
        conn.close()


def http_request(method: str, url: str, body: bytes | None, headers: dict, timeout: float, max_response: int = MAX_RESPONSE_BYTES) -> tuple:
    """P4-04 single network primitive for the artifact transport (callback with manifest + binary PUT).

    One request, no redirects followed (3xx is returned as-is), TLS certificate verification stays on the
    ``http.client`` default context, no proxy support, response read bounded to ``max_response`` (larger raises
    ``RESPONSE_TOO_LARGE``) and an overall deadline of ``3 * timeout`` seconds. Returns ``(status, bytes, headers)``
    where headers are lower-cased and limited to ``retry-after``/``content-type``."""
    parts = urlsplit(url)
    cls = http.client.HTTPSConnection if parts.scheme == "https" else http.client.HTTPConnection
    deadline = time.monotonic() + 3 * timeout
    conn = cls(parts.hostname, parts.port, timeout=timeout)
    try:
        target = parts.path or "/"
        conn.request(method, target, body=body, headers=headers)
        resp = conn.getresponse()
        chunks, size = [], 0
        while True:
            if time.monotonic() > deadline:
                raise TransportError("TIMEOUT")
            piece = resp.read(min(8192, max_response + 1 - size))
            if not piece:
                break
            chunks.append(piece)
            size += len(piece)
            if size > max_response:
                raise TransportError("RESPONSE_TOO_LARGE")
        keep = {k.lower(): v for k, v in resp.getheaders() if k.lower() in ("retry-after", "content-type")}
        return resp.status, b"".join(chunks), keep
    except TransportError:
        raise
    except (socket.timeout, TimeoutError):
        raise TransportError("TIMEOUT")
    except (OSError, http.client.HTTPException):
        raise TransportError("NETWORK_ERROR")
    finally:
        conn.close()


def http_get(url: str, headers: dict, timeout: float, max_bytes: int) -> tuple:
    """GET once for the P3-01 snapshot pull. Redirects are never followed (a 3xx is returned as-is) and the read is
    bounded to ``max_bytes + 1`` so the caller can detect an oversize response. Returns ``(status, body_bytes)``."""
    parts = urlsplit(url)
    cls = http.client.HTTPSConnection if parts.scheme == "https" else http.client.HTTPConnection
    conn = cls(parts.hostname, parts.port, timeout=timeout)
    try:
        conn.request("GET", parts.path or "/", headers=headers)
        resp = conn.getresponse()
        return resp.status, resp.read(max_bytes + 1)
    except (socket.timeout, TimeoutError):
        raise TransportError("TIMEOUT")
    except (OSError, http.client.HTTPException):
        raise TransportError("NETWORK_ERROR")
    finally:
        conn.close()


def parse_response(data: bytes) -> dict:
    """Extract only whitelisted, safe facts from a response body; never keep raw text."""
    info = {"code": None, "status": None, "event_id": None, "report_id": None, "fields": []}
    try:
        obj = json.loads(data.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        return info
    if not isinstance(obj, dict):
        return info
    if obj.get("code") in KNOWN_RESPONSE_CODES:
        info["code"] = obj["code"]
    if obj.get("status") in ("stored", "duplicate"):
        info["status"] = obj["status"]
    if isinstance(obj.get("event_id"), str):
        info["event_id"] = obj["event_id"].lower()
    if isinstance(obj.get("report_id"), int) and not isinstance(obj["report_id"], bool):
        info["report_id"] = obj["report_id"]
    errors = obj.get("errors")
    if isinstance(errors, dict):
        info["fields"] = sorted(k for k in errors if isinstance(k, str) and _FIELD_NAME.match(k))[:10]
    return info


def classify(http_status: int, info: dict, eid: str) -> tuple:
    """Map an HTTP result to ``(new_status, error_code)``; ``error_code`` is None on success."""
    if http_status in (200, 201):
        if info["status"] in ("stored", "duplicate") and info["event_id"] == eid:
            return DELIVERED, None
        return FAILED_CONFIG, "UNEXPECTED_RESPONSE"  # a 2xx that is not Laravel's callback ack: wrong URL/server
    if http_status == 409:
        return FAILED_PERMANENT, "REPORT_CONFLICT"
    if http_status == 422:
        return FAILED_PERMANENT, "VALIDATION_FAILED"
    if http_status == 401:
        return FAILED_CONFIG, "UNAUTHORIZED"
    if http_status == 413:
        return FAILED_CONFIG, "PAYLOAD_TOO_LARGE"
    if http_status == 415:
        return FAILED_CONFIG, "UNSUPPORTED_MEDIA_TYPE"
    if http_status == 404:
        return UNMATCHED_EVENT, "NOT_FOUND"
    if http_status == 503 and info["code"] == "callback_unavailable":
        return FAILED_CONFIG, "CALLBACK_UNAVAILABLE"  # receiver secret not configured: pause until fixed
    if http_status in (408, 429) or 500 <= http_status <= 599:
        return RETRY_WAIT, f"HTTP_{http_status}"
    if 300 <= http_status <= 399:
        return FAILED_CONFIG, "HTTP_REDIRECT"
    return FAILED_CONFIG, f"HTTP_{http_status}" if 100 <= http_status <= 599 else "HTTP_INVALID"


def backoff_seconds(cycle_attempts: int, rng=random.random) -> float:
    """Exponential from 60s, +0..20% jitter, hard cap 1h."""
    base = min(BACKOFF_MAX_SECONDS, BACKOFF_BASE_SECONDS * (2 ** max(0, cycle_attempts - 1)))
    return min(BACKOFF_MAX_SECONDS, base * (1 + BACKOFF_JITTER * rng()))


# ------------------------------------------------------------------------------------------------------------------ send

def _due(cb: dict, now_e: float) -> bool:
    t = _epoch(cb.get("next_attempt_at"))
    return t is None or t <= now_e


def send(eid: str, *, manual=False, config=None, transport=None, clock=time.time, rng=random.random) -> dict:
    """Attempt one delivery of the stored report. Safe to call concurrently / repeatedly (idempotent)."""
    if not store.uat_allows(store.read_job(eid)):
        return {"event_id": eid, "result": "blocked", "error_code": "UAT_SCOPE_DENIED"}
    store.check_id(eid)
    with store.flock(f"{eid}.cb", blocking=False) as got:
        if not got:
            return {"event_id": eid, "result": "skipped_locked"}
        return _send_locked(eid, manual, config, transport or http_post, clock, rng)


def _send_locked(eid, manual, config, transport, clock, rng) -> dict:
    job = store.read_job(eid)
    if job is None:
        return {"event_id": eid, "result": "not_found"}
    if job.get("status") != "completed":
        return {"event_id": eid, "result": "not_completed", "job_status": job.get("status")}
    if store.artifact_eligible(job):
        from . import pipeline
        readiness = pipeline.verify(eid)
        return {"event_id": eid, "result": "handoff_pending" if readiness["result"] == "valid" else "package_not_ready"}
    job = ensure_state(eid)
    cb = job["callback"]
    st = cb["status"]
    if st == DELIVERED:
        return {"event_id": eid, "result": "already_delivered"}
    if st == ARCHIVED:
        return {"event_id": eid, "result": ARCHIVED}
    if st == FAILED_PERMANENT:
        return {"event_id": eid, "result": "terminal", "status": st, "last_error_code": cb.get("last_error_code")}
    if st in OPERATOR_ONLY and not manual:
        return {"event_id": eid, "result": "needs_operator", "status": st, "last_error_code": cb.get("last_error_code")}
    if st == RETRY_WAIT and not manual and not _due(cb, clock()):
        return {"event_id": eid, "result": "not_due", "next_attempt_at": cb.get("next_attempt_at")}
    cfg, problems = (config, []) if config is not None else load_config()
    if cfg is None:
        return {"event_id": eid, "result": "config_invalid", "problems": problems}  # fail closed: no network, no state change
    try:
        payload = build_payload(eid, job)
    except CallbackPayloadError as e:
        return {"event_id": eid, "result": "payload_invalid", "code": e.code}
    if cb.get("payload_hash") and cb["payload_hash"] != payload.sha256:
        def conflict(j):
            j["callback"].update(status=FAILED_PERMANENT, last_error_code="PAYLOAD_INTEGRITY_CONFLICT", claim_id=None, claimed_at=None, lease_until=None)
        store.update_job(eid, conflict)
        return {"event_id": eid, "result": FAILED_PERMANENT, "last_error_code": "PAYLOAD_INTEGRITY_CONFLICT"}

    claim_id, flag = uuid.uuid4().hex, {}

    def claim(j):
        c = j["callback"]
        s = c["status"]
        now_e = clock()
        if j.get("status") != "completed" or s in TERMINAL or (s in OPERATOR_ONLY and not manual) or (s == RETRY_WAIT and not manual and not _due(c, now_e)):
            flag["refused"] = s
            return
        if s in OPERATOR_ONLY:  # explicit operator re-drive: fresh attempt budget, history kept in `attempts`
            c.update(cycle_attempts=0, operator_requeues=c.get("operator_requeues", 0) + 1)
        if c.get("cycle_attempts", 0) >= cfg.max_attempts:
            c.update(status=FAILED_EXHAUSTED, next_attempt_at=None)
            flag["refused"] = FAILED_EXHAUSTED
            return
        flag["recovered"] = s == SENDING  # we hold .cb, so a `sending` state can only be a dead sender's claim
        c.update(status=SENDING, attempts=c.get("attempts", 0) + 1, cycle_attempts=c.get("cycle_attempts", 0) + 1, claim_id=claim_id,
                 claimed_at=_iso(now_e), lease_until=_iso(now_e + 2 * cfg.timeout + 30), last_attempt_at=_iso(now_e), next_attempt_at=None,
                 payload_hash=c.get("payload_hash") or payload.sha256)
        flag["attempt"] = c["cycle_attempts"]
    if not store.uat_allows(job):
        return {"event_id": eid, "result": "blocked", "error_code": "UAT_SCOPE_DENIED"}
    job = store.update_job(eid, claim)
    if "refused" in flag:
        return {"event_id": eid, "result": "refused", "status": job["callback"]["status"], "reason": flag["refused"]}

    if not store.uat_allows(job):
        return {"event_id": eid, "result": "blocked", "error_code": "UAT_SCOPE_DENIED"}
    http_status, info, transport_code = None, parse_response(b""), None
    try:
        http_status, data = transport(cfg.url, payload.body,
                                      with_host(build_headers(cfg.secret, payload.body, clock), cfg.http_host), cfg.timeout)
        info = parse_response(data)
    except TransportError as e:
        transport_code = e.code
    except Exception:
        transport_code = "INTERNAL_ERROR"
    if transport_code:
        new_status, code = RETRY_WAIT, transport_code
    else:
        new_status, code = classify(http_status, info, eid)

    def record(j):
        c = j["callback"]
        if c.get("claim_id") != claim_id:  # lost the claim (should not happen under .cb): only a real ACK may still land
            if new_status != DELIVERED or c["status"] == DELIVERED:
                flag["ignored"] = True
                return
        now_e = clock()
        c.update(last_http_status=http_status, last_error_code=code, last_response_code=info["code"], claim_id=None, claimed_at=None, lease_until=None)
        if info["fields"] and new_status == FAILED_PERMANENT:
            c["last_error_fields"] = info["fields"]
        if new_status == DELIVERED:
            c.update(status=DELIVERED, delivered_at=_iso(now_e), next_attempt_at=None, last_response_status=info["status"], laravel_report_id=info["report_id"])
        elif new_status == RETRY_WAIT:
            if c.get("cycle_attempts", 0) >= cfg.max_attempts:
                c.update(status=FAILED_EXHAUSTED, next_attempt_at=None)
            else:
                c.update(status=RETRY_WAIT, next_attempt_at=_iso(now_e + backoff_seconds(c.get("cycle_attempts", 1), rng)))
        else:
            c.update(status=new_status, next_attempt_at=None)
    job = store.update_job(eid, record)
    c = job["callback"]
    return {"event_id": eid, "result": c["status"], "http_status": http_status, "last_error_code": c.get("last_error_code"),
            "attempts": c.get("attempts"), "next_attempt_at": c.get("next_attempt_at"), "recovered_claim": bool(flag.get("recovered"))}


def after_completed(eid: str) -> dict | None:
    """Hook for the analysis worker right after ``completed``. Best effort: delivery trouble never fails analysis."""
    try:
        return send(eid)
    except Exception:
        return None


# --------------------------------------------------------------------------------------------------------- sweep / spawn

def spawn_sender(eid: str) -> None:
    store.check_id(eid)
    log = store.open_private_append(store.home() / "worker.log")
    subprocess.Popen([sys.executable, "-m", "jmi", "callback", eid, "--auto"], cwd=PKG, stdin=subprocess.DEVNULL,
                     stdout=log, stderr=log, start_new_session=True, close_fds=True)
    log.close()


def sweep(limit=5, spawn=True, config=None, clock=time.time, transport=None, rng=random.random) -> list:
    """Deliver due callbacks of completed jobs: pending, retry_wait past next_attempt_at, stale `sending`.
    Never touches delivered/archived/terminal/operator-only jobs and never triggers analysis."""
    cfg, _problems = (config, []) if config is not None else load_config()
    if cfg is None:
        return []  # fail closed and silent; `callback-status` reports the configuration problem
    out = []
    for job in store.list_jobs():
        if len(out) >= limit:
            break
        if job["status"] != "completed" or store.artifact_eligible(job):
            continue
        eid = job["event_id"]
        try:
            job = ensure_state(eid)
            st = job["callback"]["status"]
            if st == RETRY_WAIT and not _due(job["callback"], clock()):
                continue
            if st not in (PENDING, RETRY_WAIT, SENDING):
                continue
            with store.flock(f"{eid}.cb", blocking=False) as free:
                if not free:  # a live sender owns it (sending is only stale when this lock is free)
                    continue
            if spawn:
                spawn_sender(eid)
                out.append({"event_id": eid, "result": "spawned"})
            else:
                r = send(eid, config=cfg, clock=clock, transport=transport, rng=rng)
                if r["result"] != "skipped_locked":
                    out.append(r)
        except Exception:
            out.append({"event_id": eid, "result": "error"})
    return out


# ---------------------------------------------------------------------------------------------- operator views (read-only)

def dry_run(eid: str, config=None) -> dict:
    """Validate everything a real send would, without HTTP and without touching job.json."""
    store.check_id(eid)
    job = store.read_job(eid)
    if job is None:
        return {"event_id": eid, "result": "not_found"}
    if job.get("status") != "completed":
        return {"event_id": eid, "result": "not_completed", "job_status": job.get("status")}
    cb = virtual_state(job)
    cfg, problems = (config, []) if config is not None else load_config()
    out = {"event_id": eid, "result": "dry_run", "dry_run": True, "callback_status": cb["status"], "config_ok": cfg is not None,
           "config_problems": problems, "url": cfg.url if cfg else None, "would_send": False}
    try:
        p = build_payload(eid, job)
    except CallbackPayloadError as e:
        out.update(payload_valid=False, payload_error=e.code)
        return out
    out.update(payload_valid=True, payload_hash=p.sha256, payload_bytes=len(p.body), evaluation_id=p.evaluation_id, umkm_id=p.umkm_id,
               source_event_version=p.source_event_version, prompt_version=p.prompt_version)
    if cb.get("payload_hash") and cb["payload_hash"] != p.sha256:
        out.update(payload_valid=False, payload_error="PAYLOAD_INTEGRITY_CONFLICT")
    out["would_send"] = bool(cfg and out["payload_valid"] and cb["status"] in (PENDING, RETRY_WAIT, SENDING))
    return out


def status_summary(config=None, clock=time.time) -> dict:
    """Read-only overview: config health, counts per delivery status, per-job details (no secrets)."""
    cfg, problems = (config, []) if config is not None else load_config()
    reg, now_e = registry(), clock()
    counts, jobs, awaiting = {}, [], 0
    for job in store.list_jobs():
        if job["status"] != "completed":
            awaiting += 1
            continue
        cb = virtual_state(job, reg)
        counts[cb["status"]] = counts.get(cb["status"], 0) + 1
        jobs.append({"event_id": job["event_id"], "evaluation_id": job.get("evaluation_id"), "umkm_id": job.get("umkm_id"), "status": cb["status"],
                     "attempts": cb.get("attempts", 0), "next_attempt_at": cb.get("next_attempt_at"),
                     "retry_due": cb["status"] == RETRY_WAIT and _due(cb, now_e), "last_http_status": cb.get("last_http_status"),
                     "last_error_code": cb.get("last_error_code"), "delivered_at": cb.get("delivered_at"),
                     "migrated": _valid_state(job.get("callback"))})
    return {"config": {"ok": cfg is not None, "problems": problems, "url": cfg.url if cfg else None,
                       "timeout_seconds": cfg.timeout if cfg else None, "max_attempts": cfg.max_attempts if cfg else None},
            "summary": counts, "awaiting_analysis": awaiting, "jobs": jobs}


def migrate_all() -> list:
    """Explicit, idempotent lazy migration of every completed job (archives the historical unmatched ones). Never sends."""
    out = []
    for job in store.list_jobs():
        if job["status"] == "completed":
            before = job["callback"]["status"] if _valid_state(job.get("callback")) else None
            j = ensure_state(job["event_id"])
            out.append({"event_id": job["event_id"], "status": j["callback"]["status"], "changed": before != j["callback"]["status"]})
    return out
