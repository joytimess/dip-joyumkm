"""Persistent file-based job/report store: atomic writes, flock guards, private permissions."""
from __future__ import annotations

import contextlib
import fcntl
import json
import os
import re
import uuid
from datetime import datetime, timezone
from pathlib import Path

from . import parse

DEFAULT_HOME = "/opt/data/joyumkm-intelligence/state"
_SECRET = re.compile(r"(sk-[A-Za-z0-9_\-]{8,}|Bearer\s+[A-Za-z0-9._\-]{8,}|(secret|token|password|api[_-]?key)\s*[=:]\s*\S+)", re.I)


def now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%f")[:-3] + "Z"


def safe_text(s, n=200) -> str:
    return _SECRET.sub("[REDACTED]", str(s))[:n]


def home() -> Path:
    p = Path(os.environ.get("JMI_HOME", DEFAULT_HOME))
    p.mkdir(parents=True, exist_ok=True, mode=0o700)
    for sub in ("jobs", "locks", "rejects"):
        (p / sub).mkdir(exist_ok=True, mode=0o700)
    return p


def harden_permissions() -> int:
    """chmod state tree: dirs 0700, files 0600. Returns number of entries changed."""
    n = 0
    root = home()
    for dp, dns, fns in os.walk(root):
        for name, mode in [(d, 0o700) for d in dns] + [(f, 0o600) for f in fns]:
            fp = os.path.join(dp, name)
            if (os.stat(fp).st_mode & 0o777) != mode:
                os.chmod(fp, mode)
                n += 1
    if (os.stat(root).st_mode & 0o777) != 0o700:
        os.chmod(root, 0o700)
        n += 1
    return n


def open_private_append(path: Path):
    return os.fdopen(os.open(path, os.O_CREAT | os.O_WRONLY | os.O_APPEND, 0o600), "ab")


def atomic_write(path: Path, data) -> None:
    if not isinstance(data, (bytes, bytearray)):
        data = json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True).encode()
    tmp = path.with_name(f".{path.name}.{os.getpid()}.{uuid.uuid4().hex[:8]}.tmp")
    fd = os.open(tmp, os.O_CREAT | os.O_WRONLY | os.O_EXCL, 0o600)
    with os.fdopen(fd, "wb") as f:
        f.write(data)
        f.flush()
        os.fsync(f.fileno())
    os.replace(tmp, path)
    fsync_dir(path.parent)  # errors propagate: a write is not durable (and must not be ACKed) if this fails


def fsync_file(path) -> None:
    fd = os.open(path, os.O_RDONLY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def fsync_dir(path) -> None:
    dfd = os.open(path, os.O_DIRECTORY)
    try:
        os.fsync(dfd)
    finally:
        os.close(dfd)


def check_id(eid) -> str:
    """Canonical lowercase UUID or PayloadError. MUST run before any path/lock construction."""
    try:
        if not isinstance(eid, str) or str(uuid.UUID(eid)) != eid:
            raise ValueError
    except ValueError:
        raise parse.PayloadError("INVALID_EVENT_ID", "not a canonical UUID")
    return eid


def job_dir(eid: str) -> Path:
    return home() / "jobs" / check_id(eid)


@contextlib.contextmanager
def flock(name: str, blocking=True):
    """Yield True if lock acquired (False if non-blocking and busy). Released on exit/process death."""
    if not re.fullmatch(r"[0-9a-f\-]{36}\.(lock|proc|cb|snap)", name):
        raise parse.PayloadError("INVALID_EVENT_ID", "bad lock name")
    path = home() / "locks" / name
    fd = os.open(path, os.O_CREAT | os.O_RDWR, 0o600)
    try:
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | (0 if blocking else fcntl.LOCK_NB))
        except BlockingIOError:
            yield False
            return
        yield True
    finally:
        os.close(fd)


def read_job(eid: str):
    p = job_dir(eid) / "job.json"
    return json.loads(p.read_text()) if p.exists() else None


def write_job(job: dict) -> None:
    job["updated_at"] = now()
    atomic_write(job_dir(job["event_id"]) / "job.json", job)


def update_job(eid: str, fn) -> dict:
    """Read-modify-write job.json under the short-lived per-event metadata lock.

    Every mutation after intake goes through here so concurrent writers (intake recording a
    conflict, worker recording an attempt) can never overwrite each other with a stale snapshot.
    `fn(job)` mutates in place; the fresh job is returned.
    """
    with flock(f"{check_id(eid)}.lock"):
        job = read_job(eid)
        if job is None:
            raise FileNotFoundError(eid)
        fn(job)
        write_job(job)
        return job


def read_report(eid: str):
    p = job_dir(eid) / "report.json"
    return json.loads(p.read_text()) if p.exists() else None


def _reject(code, detail, h, retryable=False):
    rec = {"rejected_at": now(), "code": code, "detail": safe_text(detail), "payload_hash": h,
           "classification": "retryable" if retryable else "permanent", "retryable": retryable}
    atomic_write(home() / "rejects" / f"{h[:32]}.json", rec)
    return {"action": "rejected", "code": code, "detail": safe_text(detail), "retryable": retryable}


def artifact_eligible(job):
    return (isinstance(job, dict) and type(job.get("artifact_pipeline")) is int
            and job["artifact_pipeline"] == 1 and job.get("monthly_pipeline") == 1
            and job.get("source_event_version") == 2
            and (job.get("callback") or {}).get("status") != "archived_unmatched")


def delivery_eligible(job):
    """P4-05: only jobs enrolled in BOTH the artifact pipeline and Laravel delivery at their first intake."""
    return artifact_eligible(job) and type(job.get("artifact_delivery")) is int and job["artifact_delivery"] == 1


def uat_scope_requested():
    """P2-06: scoped UAT is requested by the explicit env flag OR by an existing uat-scope.json in JMI_HOME.

    A present-but-invalid/expired/closed pin file still counts as requested, so uat_allows() then denies:
    a broken UAT configuration never falls back to unrestricted processing. Rename the file to resume
    ordinary normal operation.
    """
    if os.environ.get("JMI_UAT_SCOPE_ENABLED") == "1":
        return True
    try:
        return (Path(os.environ.get("JMI_HOME", DEFAULT_HOME)) / "uat-scope.json").exists()
    except OSError:
        return True


def uat_allows(job):
    if not uat_scope_requested():
        return True
    try:
        path = Path(os.environ.get("JMI_HOME", DEFAULT_HOME)) / "uat-scope.json"
        pin = parse.strict_loads(path.read_bytes())
        return (
            isinstance(job, dict) and isinstance(pin, dict) and pin.get("mode") == "pinned"
            and re.fullmatch(r"[0-9]{8}T[0-9]{6}Z", pin["run_id"]) is not None
            and type(pin["expires_at"]) is int
            and pin["expires_at"] > datetime.now(timezone.utc).timestamp()
            and check_id(pin["event_id"]) == job.get("event_id")
            and job.get("source_event_version") == 2
            and all(type(pin[k]) is int and pin[k] > 0 and pin[k] == job.get(k)
                    for k in ("evaluation_id", "umkm_id"))
        )
    except (OSError, TypeError, ValueError, KeyError, parse.PayloadError):
        return False


def intake(raw) -> dict:
    """Validate + persist a received webhook payload. Idempotent on event_id. Never calls the model.

    `raw` is bytes/str (strict, duplicate-key rejecting parse) or an already-parsed dict (gateway path).
    Returns {action: created|duplicate|conflict|rejected, event_id, status, ...}. `created`/`duplicate`
    mean a durable job exists for exactly this payload; anything else means NO job exists for it.
    """
    try:
        if isinstance(raw, (bytes, bytearray, str)):
            envelope = parse.strict_loads(raw)
            raw_bytes = raw if isinstance(raw, (bytes, bytearray)) else raw.encode()
        else:
            envelope = raw
            raw_bytes = json.dumps(raw, ensure_ascii=False, sort_keys=True).encode()
        evidence = parse.normalize(envelope)
    except parse.PayloadError as e:
        h = parse.canonical_hash(raw if isinstance(raw, dict) else str(raw)[:2000])
        return _reject(e.code, e.detail, h)
    identity = dict(evidence, evaluation_id=evidence["six_factor"]["evaluation_id"])
    if not uat_allows(identity):
        return {"action": "rejected", "code": "UAT_SCOPE_DENIED", "retryable": False}
    eid = evidence["event_id"]
    phash = parse.canonical_hash(envelope)
    d = job_dir(eid)
    with flock(f"{eid}.lock"):
        if not uat_allows(identity):
            return {"action": "rejected", "code": "UAT_SCOPE_DENIED", "retryable": False}
        job = read_job(eid)
        if job is not None:
            if job["payload_hash"] == phash:
                # Never report success for a job whose durability is unconfirmed (first write may have failed at the
                # final directory sync): re-sync file + job dir + parent before every duplicate ACK.
                fsync_file(d / "job.json"); fsync_file(d / "evidence.json"); fsync_dir(d); fsync_dir(d.parent)
                return {"action": "duplicate", "event_id": eid, "status": job["status"]}
            cf = d / f"conflict-{phash[:16]}.json"
            if not cf.exists():
                atomic_write(cf, {"received_at": now(), "payload_hash": phash, "envelope": envelope})
            if not any(c["payload_hash"] == phash for c in job.setdefault("integrity_conflicts", [])):
                job["integrity_conflicts"].append({"at": now(), "payload_hash": phash, "file": cf.name})
                write_job(job)  # we hold .lock and read job fresh above: no stale snapshot
            return {"action": "conflict", "event_id": eid, "status": job["status"], "code": "EVENT_ID_CONFLICT"}
        d.mkdir(parents=True, exist_ok=True, mode=0o700)
        atomic_write(d / "payload.raw.json", raw_bytes)
        atomic_write(d / "evidence.json", evidence)
        job = {
            "event_id": eid, "umkm_id": evidence["umkm_id"], "evaluation_id": evidence["six_factor"]["evaluation_id"],
            "source_event_version": evidence["source_event_version"], "status": "pending", "payload_hash": phash,
            "created_at": now(), "attempts": 0, "cycle_attempts": 0, "auto_retry_cycles": 0, "attempt_log": [],
            "error_code": None, "error_detail": None, "retryable": None, "failed_at": None, "model": None, "provider": None,
            "prompt_version": None, "knowledge_version": None, "methodology_status": None, "report_path": None,
            "integrity_conflicts": [], "laravel_ack_independent": True, "data_quality_warnings": evidence["data_quality_warnings"],
        }
        if evidence["source_event_version"] == 2:
            job["monthly_pipeline"] = 1
            if os.environ.get("JMI_ARTIFACT_PIPELINE_ENABLED") == "1":
                job["artifact_pipeline"] = 1
                from . import email_delivery
                email_delivery.enroll(job, d)
                if os.environ.get("JMI_ARTIFACT_DELIVERY_ENABLED") == "1":
                    job["artifact_delivery"] = 1  # P4-05 durable enrollment, decided once at first intake
        write_job(job)
        fsync_dir(d.parent)  # make the new job directory entry itself crash-durable before reporting success
        return {"action": "created", "event_id": eid, "status": "pending"}


def list_jobs():
    """Valid job records only (shape-checked), oldest first. Malformed metadata is skipped, never fatal."""
    out = []
    for p in (home() / "jobs").iterdir():
        jp = p / "job.json"
        if not jp.exists():
            continue
        try:
            j = json.loads(jp.read_text())
            check_id(j["event_id"])
            if (not isinstance(j, dict) or j["event_id"] != p.name or j.get("status") not in ("pending", "processing", "completed", "failed")
                    or not isinstance(j.get("created_at"), str)):
                continue
        except (ValueError, KeyError, TypeError, parse.PayloadError):
            continue
        if uat_allows(j):
            out.append(j)
    return sorted(out, key=lambda j: j["created_at"])
