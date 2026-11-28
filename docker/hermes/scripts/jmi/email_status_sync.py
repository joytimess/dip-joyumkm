"""Project durable email outcomes to Laravel without entering the SMTP path."""
from hashlib import sha256
from urllib.parse import urlsplit
import hmac
import json
import os
import re
import time

from . import artifacts, callback, email_delivery, parse, pipeline, recipient, store

PATH = "/api/internal/intelligence/deliveries"
FILE = "email_status_sync.json"
LIMIT = 16384
FIELDS = ("schema_version", "event_id", "channel", "status", "attempt_no",
          "recipient_fingerprint", "consent_version", "idempotency_key", "message_id",
          "sent_at", "occurred_at", "next_retry_at", "error_code", *email_delivery.HASHES)
MAPPED = {"waiting_package": "queued", "processing": "queued", "sending": "queued",
          "recipient_changed": "skipped_ineligible"}
ENABLED = "JMI_EMAIL_STATUS_SYNC_ENABLED"
PAUSED = "JMI_EMAIL_STATUS_SYNC_PAUSED"


def settings():
    doc = callback._parse_env_text(recipient.private_read("delivery-status.env", 8192).decode())
    if (doc.get(ENABLED) != "1" or os.environ.get(ENABLED, "1") != "1"
            or doc.get(PAUSED) != "0" or os.environ.get(PAUSED, "0") != "0"
            or os.path.lexists(store.home() / "email-status-sync.pause")):
        return None
    secret = doc["HERMES_INTELLIGENCE_DELIVERY_STATUS_SECRET"].encode()
    base = doc["INTELLIGENCE_DELIVERY_STATUS_BASE_URL"].rstrip("/")
    host = doc.get("INTELLIGENCE_DELIVERY_STATUS_HTTP_HOST", "")
    origin = urlsplit(base)
    local = origin.netloc == "host.docker.internal" and host == "joyumkm.test"
    if (len(secret.strip()) < 32 or (origin.scheme != "https" or host) and (origin.scheme != "http" or not local)
            or origin.username or origin.password or not origin.hostname or origin.path or origin.query
            or origin.fragment or not base.isascii() or re.search(r"[\x00-\x20\x7f]", base)
            or doc.get("HERMES_INTELLIGENCE_DELIVERY_STATUS_TOLERANCE_SECONDS", "300") != "300"):
        raise ValueError
    _ = origin.port
    return secret, base, host


def sign(secret, timestamp, body):
    canonical = f"JMI-DELIVERY-STATUS-V1\nPOST\n{PATH}\n{timestamp}\n{sha256(body).hexdigest()}"
    return hmac.digest(secret, canonical.encode(), "sha256").hex()


def enroll(job, directory):
    token = job["email_delivery"]
    if os.path.lexists(directory / FILE):
        raise ValueError
    store.atomic_write(directory / FILE, dict(enrollment_id=token, revision=0, acked_revision=0,
        snapshot=None, digest=None, attempts=0, due=0, status="pending", resumed=False,
        result=None, acked_at=None))
    job["email_status_sync"] = token


def load(directory, job):
    data = parse.strict_loads(artifacts._read(directory / FILE, LIMIT, "STATUS_SYNC_STATE_INVALID"))
    if (type(data) is not dict or set(data) != {"enrollment_id", "revision", "acked_revision",
            "snapshot", "digest", "attempts", "due", "status", "resumed", "result", "acked_at"}
            or data["enrollment_id"] != job["email_status_sync"] or type(data["revision"]) is not int
            or not 0 <= data["revision"] <= 9007199254740991 or type(data["acked_revision"]) is not int
            or not 0 <= data["acked_revision"] <= data["revision"] or type(data["attempts"]) is not int
            or not 0 <= data["attempts"] <= 4 or type(data["due"]) is not int or data["due"] < 0
            or type(data["resumed"]) is not bool or data["status"] not in {"pending", "synced", "blocked"}
            or data["result"] not in {None, "accepted", "transient", "report_not_ready", "exhausted",
                                       "remote_conflict", "invalid_response", "configuration", "rejected"}
            or (data["acked_at"] is not None and type(data["acked_at"]) is not int)):
        raise ValueError
    if data["revision"]:
        body = data["snapshot"].encode()
        snapshot = parse.strict_loads(body)
        if (set(snapshot) != {*FIELDS, "revision"} or snapshot["revision"] != data["revision"]
                or snapshot["event_id"] != job["event_id"] or data["digest"] != sha256(body).hexdigest()):
            raise ValueError
    elif data["snapshot"] is not None or data["digest"] is not None:
        raise ValueError
    return data


def eligible(job):
    return (email_delivery.enrolled(job) and job.get("status") == "completed"
            and job.get("email_status_sync") == job.get("email_delivery"))


def projection(directory, job):
    if not eligible(job):
        return {"status": "not_enrolled"}
    try:
        state = load(directory, job)
        return {key: state[key] for key in ("status", "revision", "acked_revision", "digest",
                                            "attempts", "due", "result", "acked_at")}
    except email_delivery.INVALID:
        return {"status": "blocked", "result": "state_invalid"}


def snapshot(source, revision):
    return json.dumps({**{key: source[key] for key in FIELDS}, "revision": revision},
                      sort_keys=True, separators=(",", ":"), allow_nan=False)


def exchange(cfg, document, digest, clock, request):
    secret, base, host = cfg
    body = document.encode()
    stamp = int(clock())
    headers = {"Accept": "application/json", "Content-Type": "application/json",
               "Content-Length": str(len(body)), "X-Intelligence-Signature-Version": "1",
               "X-Intelligence-Timestamp": str(stamp), "X-Intelligence-Signature": sign(secret, stamp, body)}
    if host:
        headers["Host"] = host
    try:
        status, raw, _ = request("POST", base + PATH, body, headers, 5, LIMIT)
    except callback.TransportError as error:
        return "invalid_response" if error.code == "RESPONSE_TOO_LARGE" else "transient"
    if status == 429 or 500 <= status <= 599:
        return "configuration" if status == 503 and raw == b'{"code":"delivery_config_invalid"}' else "transient"
    if status == 401:
        return "configuration"
    if status not in {200, 201, 409}:
        return "rejected"
    try:
        actual = parse.strict_loads(raw)
        if type(actual) is not dict:
            return "invalid_response"
        if status == 409:
            return "report_not_ready" if actual == {"code": "report_not_ready"} else "remote_conflict"
        if actual.get("result") == "stale":
            return "remote_conflict"
        sent = parse.strict_loads(body)
        expected = dict(schema_version=1, event_id=sent["event_id"], channel="email",
                        accepted_revision=sent["revision"], snapshot_sha256=digest,
                        status=MAPPED.get(sent["status"], sent["status"]), attempt_no=sent["attempt_no"],
                        report_hash=sent["report_hash"], transport_artifact_set_hash=sent["transport_artifact_set_hash"])
        result = actual.pop("result", None)
        if (result in ({"stored"} if status == 201 else {"updated", "duplicate"})
                and actual == expected and all(type(actual[k]) is int for k in
                    ("schema_version", "accepted_revision", "attempt_no"))):
            return "accepted"
    except (ValueError, TypeError, parse.PayloadError):
        pass
    return "invalid_response"


def advance(eid, *, cfg, clock=time.time, request=callback.http_request, resume=False):
    if not store.uat_allows(store.read_job(eid)):
        return {"event_id": eid, "result": "blocked", "error_code": "UAT_SCOPE_DENIED"}
    try:
        root, directory, job = pipeline._context(eid)
        if not eligible(job):
            return None
        path = directory / FILE
        lock = root / "locks" / f"{eid}.email_delivery"
        # Sync lease serializes HTTP. Email lease only protects local source capture and ack.
        with artifacts._lock(root / "locks" / f"{eid}.email_status_sync", create=True, nonblocking=True):
            with artifacts._lock(lock, create=True, nonblocking=True):
                source = email_delivery.load(directory, job)
                sync = load(directory, job)
                if source["artifact_set_hash"] is None:
                    return None
                current = snapshot(source, sync["revision"])
                if current != sync["snapshot"]:
                    if resume:
                        return None
                    revision = sync["revision"] + 1
                    document = snapshot(source, revision)
                    sync.update(revision=revision, snapshot=document, digest=sha256(document.encode()).hexdigest(),
                                attempts=0, due=0, status="pending", resumed=False, result=None)
                if resume:
                    if sync["status"] != "blocked" or sync["result"] != "exhausted" or sync["resumed"]:
                        return None
                    sync.update(attempts=0, due=0, status="pending", resumed=True)
                if sync["status"] != "pending" or clock() < sync["due"]:
                    return None
                attempts = sync["attempts"] + 1
                if attempts > 4:
                    sync.update(status="blocked", result="exhausted")
                    store.atomic_write(path, sync)
                    return "exhausted"
                due = int(clock()) + (email_delivery.RETRY[attempts - 1] if attempts < 4 else 60)
                sync.update(attempts=attempts, due=due)
                store.atomic_write(path, sync)
            if not store.uat_allows(job):
                return "scope_denied"
            result = exchange(cfg, sync["snapshot"], sync["digest"], clock, request)
            with artifacts._lock(lock, create=True):
                latest = load(directory, job)
                if (latest["revision"], latest["digest"]) != (sync["revision"], sync["digest"]):
                    return "state_changed"
                source = email_delivery.load(directory, job)
                if snapshot(source, latest["revision"]) != latest["snapshot"]:
                    return "state_changed"
                latest["result"] = result
                if result == "accepted":
                    latest.update(status="synced", acked_revision=latest["revision"], acked_at=int(clock()), due=0)
                elif result not in {"transient", "report_not_ready"} or attempts == 4:
                    latest.update(status="blocked", due=0,
                                  result="exhausted" if result in {"transient", "report_not_ready"} else result)
                store.atomic_write(path, latest)
            return result
    except email_delivery.INVALID as error:
        if isinstance(error, artifacts.PackageError) and str(error) == "PACKAGE_LOCK_BUSY":
            return None
        return "state_invalid"


def run(eid, *, resume=False):
    try:
        cfg = settings()
    except email_delivery.INVALID:
        return "configuration"
    return advance(eid, cfg=cfg, resume=resume) if cfg else None


def recover(limit):
    try:
        cfg = settings()
    except email_delivery.INVALID:
        return []
    if not cfg or limit <= 0:
        return []
    result = []
    for job in store.list_jobs():
        if eligible(job):
            outcome = advance(job["event_id"], cfg=cfg)
            if outcome is not None:
                result.append(dict(event_id=job["event_id"], result="email_status_sync", status=outcome))
        if len(result) >= limit:
            break
    return result
