"""Independent, per-event email enrollment, attempt and recovery state."""
from datetime import datetime, timezone
from hashlib import sha256
import os
import re
import time
from uuid import uuid4

from . import artifacts, callback, email_template, email_transport, parse, pipeline, recipient, store, transport

FILE = "email_delivery.json"
RETRY = (600, 1800, 5400)
TERMINAL = {"sent", "failed_permanent", "skipped_ineligible", "delivery_unknown", "blocked", "recipient_changed"}
OPEN = {"waiting_package", "processing", "sending", "failed_retryable"}
HASHES = ("artifact_set_hash", "transport_artifact_set_hash", "source_report_sha256", "report_hash")
INVALID = (OSError, ValueError, KeyError, TypeError, OverflowError, parse.PayloadError, artifacts.PackageError)


def stamp(epoch):
    return datetime.fromtimestamp(epoch, timezone.utc).isoformat().replace("+00:00", "Z")


def enrolled(job):
    return (store.artifact_eligible(job) and artifacts._sha(job.get("email_delivery"))
            and callback.virtual_state(job)["status"] not in (callback.ARCHIVED, callback.UNMATCHED_EVENT))


def enroll(job, directory):
    try:
        settings = email_transport.read_settings()
        if not email_transport._gate(settings, email_transport.ENABLED):
            return
        if callback.virtual_state(job)["status"] in (callback.ARCHIVED, callback.UNMATCHED_EVENT):
            return
    except INVALID:
        return
    if os.path.lexists(directory / FILE):
        raise artifacts.PackageError("EMAIL_ENROLLMENT_CONFLICT")
    for path in (store.home(), directory.parent, directory):
        artifacts._private(path.lstat(), directory=True)
    token = sha256(uuid4().bytes).hexdigest()
    state = dict(schema_version=1, event_id=job["event_id"], channel="email", enrollment_id=token,
                 status="waiting_package", attempt_no=0, recipient_fingerprint=None, consent_version=None,
                 idempotency_key=None, message_id=None, sent_at=None, next_retry_at=None, error_code=None,
                 smtp_phase=None, status_sync=None, occurred_at=store.now(), **dict.fromkeys(HASHES))
    store.atomic_write(directory / FILE, state)
    job["email_delivery"] = token
    from . import email_status_sync
    email_status_sync.enroll(job, directory)


def load(directory, job):
    state = parse.strict_loads(artifacts._read(directory / FILE, 8192, "EMAIL_STATE_INVALID"))
    if (type(state) is not dict or set(state) != {"schema_version", "event_id", "channel", "enrollment_id",
            "status", "attempt_no", "recipient_fingerprint", "consent_version", "idempotency_key",
            "message_id", "sent_at", "next_retry_at", "error_code", "smtp_phase", "status_sync",
            "occurred_at", *HASHES} or type(state["schema_version"]) is not int or state["schema_version"] != 1
            or state["event_id"] != job["event_id"] or state["channel"] != "email"
            or state["enrollment_id"] != job["email_delivery"] or state["status"] not in OPEN | TERMINAL
            or type(state["attempt_no"]) is not int or not 0 <= state["attempt_no"] <= 4):
        raise ValueError
    status, attempt = state["status"], state["attempt_no"]
    fingerprints = state["recipient_fingerprint"], state["consent_version"], state["idempotency_key"], state["message_id"]
    if any(value is not None for value in fingerprints):
        fp, version, key, msg = fingerprints
        if (not artifacts._sha(fp) or version != "v1" or key != identity(state)
                or not isinstance(msg, str) or not re.fullmatch(rf"<{key}@[a-z0-9.-]+>", msg)):
            raise ValueError
    if (any(state[k] is not None and not artifacts._sha(state[k]) for k in HASHES)
            or bool(attempt) != all(state[k] is not None for k in HASHES)
            or (status == "waiting_package" and attempt)
            or (status == "failed_retryable" and attempt >= 4)
            or state["occurred_at"] is None
            or (status in {"processing", "sending", "failed_retryable", "sent", "delivery_unknown"} and not attempt)
            or (status in {"sending", "sent", "delivery_unknown"} and fingerprints[0] is None)
            or (status == "failed_retryable") != (state["next_retry_at"] is not None)
            or (status == "sent") != (state["sent_at"] is not None)
            or state["status_sync"] not in (None, "pending")
            or state["smtp_phase"] not in (None, "connect", "tls", "auth", "envelope", "data_command", "data")
            or (state["error_code"] is not None and (not isinstance(state["error_code"], str)
                or not re.fullmatch(r"[A-Z_]{1,64}", state["error_code"])))):
        raise ValueError
    for key in ("occurred_at", "sent_at", "next_retry_at"):
        if state[key] is not None:
            artifacts._utc(state[key])
    return state


def identity(state):
    return sha256("\n".join((state["event_id"], "email", state["recipient_fingerprint"],
                            state["artifact_set_hash"])).encode()).hexdigest()


def status(eid):
    try:
        if not os.path.lexists(store.job_dir(eid)):
            return dict(event_id=eid, status="not_found")
        _, directory, job = pipeline._context(eid)
        if enrolled(job):
            state = load(directory, job)
            from . import email_status_sync
            public = {k: v for k, v in state.items() if k not in {"enrollment_id", "recipient_fingerprint", "idempotency_key", "message_id"}}
            public["status_sync"] = email_status_sync.projection(directory, job)
            return public
        return dict(event_id=eid, status="not_enrolled")
    except INVALID as error:
        if isinstance(error, artifacts.PackageError) and str(error) == "PIPELINE_INELIGIBLE":
            return dict(event_id=eid, status="not_enrolled")
        return dict(event_id=eid, status="blocked", error_code="EMAIL_STATE_INVALID")


def advance(eid, *, clock=time.time, pull=recipient.pull, submit=email_transport.submit):
    if not store.uat_allows(store.read_job(eid)):
        return {"event_id": eid, "status": "blocked", "error_code": "UAT_SCOPE_DENIED"}
    if not email_transport.active():
        return dict(event_id=eid, status="paused")
    try:
        _, directory, job = pipeline._context(eid)
        if not enrolled(job) or job["status"] != "completed":
            return dict(event_id=eid, status="not_ready")
        with artifacts._lock(store.home() / "locks" / f"{eid}.email_delivery", create=True, nonblocking=True):
            state = load(directory, job)
            return _advance(eid, directory, job, state, clock, pull, submit)
    except INVALID as error:
        if isinstance(error, artifacts.PackageError) and str(error) == "PACKAGE_LOCK_BUSY":
            return dict(event_id=eid, status="locked")
        return dict(event_id=eid, status="blocked", error_code="EMAIL_STATE_INVALID")


def _advance(eid, directory, job, state, clock, pull, submit):
    def save(status, **changes):
        state.update(status=status, occurred_at=stamp(clock()), status_sync="pending", **changes)
        store.atomic_write(directory / FILE, state)

    def record(outcome):
        status, code = outcome.status, outcome.code
        retry_at = None
        attempt = state["attempt_no"]
        if status == "failed_retryable":
            if attempt >= 4:
                status, code = "failed_permanent", "EMAIL_RETRY_EXHAUSTED"
            else:
                retry_at = stamp(clock() + RETRY[attempt - 1])
        save(status, error_code=code, next_retry_at=retry_at, smtp_phase=outcome.phase,
             sent_at=stamp(outcome.accepted_at) if outcome.accepted_at is not None else None)

    def summary():
        return {k: v for k, v in state.items() if k != "enrollment_id"}

    current = state["status"]
    if current in TERMINAL:
        return summary()
    if current == "sending":
        record(email_transport.Outcome("delivery_unknown", "EMAIL_INTERRUPTED_DATA", state["smtp_phase"]))
        return summary()
    if current == "processing":
        record(email_transport.Outcome("failed_retryable", "EMAIL_INTERRUPTED_BEFORE_DATA", None))
        return summary()
    if state["attempt_no"] >= 4:
        save("failed_permanent", error_code="EMAIL_RETRY_EXHAUSTED", next_retry_at=None)
        return summary()
    if state["next_retry_at"] and clock() < artifacts._utc(state["next_retry_at"]).timestamp():
        return summary()
    try:
        if pipeline._state(directory, eid)["package_status"] != "ready":
            return summary()
        package = transport.prepare_verified_package(eid)
        hashes = {key: getattr(package, key) for key in HASHES}
        if any(state[key] is not None and state[key] != digest for key, digest in hashes.items()):
            save("blocked", error_code="EMAIL_PACKAGE_CHANGED", next_retry_at=None)
            return summary()
        cfg = email_transport.Config.from_settings(email_transport.read_settings())
        message = email_template.build(package)
    except transport.TransportPackageError as error:
        if error.code == "PACKAGE_LOCK_BUSY":
            return dict(event_id=eid, status="locked")
        save("blocked", error_code="EMAIL_PACKAGE_INVALID", next_retry_at=None)
        return summary()
    except INVALID:
        save("blocked", error_code="EMAIL_CONFIG_OR_PACKAGE_INVALID", next_retry_at=None)
        return summary()

    if not email_transport.active():
        return dict(event_id=eid, status="paused")
    save("processing", attempt_no=state["attempt_no"] + 1, next_retry_at=None, error_code=None,
         smtp_phase=None, **hashes)
    try:
        auth = pull(eid, job["umkm_id"], clock=clock)
        auth.check(clock)
        if state["recipient_fingerprint"] is not None and (
                auth.fingerprint != state["recipient_fingerprint"] or auth.consent_version != state["consent_version"]):
            raise recipient.EmailFailure("recipient_changed", "RECIPIENT_CHANGED")
        if state["recipient_fingerprint"] is None:
            state.update(recipient_fingerprint=auth.fingerprint, consent_version=auth.consent_version)
            key = identity(state)
            save("processing", idempotency_key=key, message_id=f"<{key}@{cfg.sender.rsplit('@', 1)[1]}>")
        raw = email_template.serialize(message, cfg, auth, state["message_id"], clock())
        if len(raw) > cfg.size:
            raise recipient.EmailFailure("blocked", "EMAIL_TOO_LARGE")

        def guard():
            if not store.uat_allows(job):
                raise recipient.EmailFailure("failed_retryable", "UAT_SCOPE_DENIED")
            if not email_transport.active():
                raise recipient.EmailFailure("failed_retryable", "EMAIL_PAUSED")
            auth.check(clock)

        def before_data():
            guard()
            save("sending", smtp_phase="data")
            guard()

        guard()
        submit(cfg, auth, raw, before_data, record, clock=clock, guard=guard)
    except recipient.EmailFailure as error:
        record(email_transport.Outcome(error.status, error.code, None))
    return summary()


def recover(limit, clock=time.time):
    if limit <= 0 or not email_transport.active():
        return []
    results = []
    for job in store.list_jobs():
        if len(results) >= limit:
            break
        eid = job["event_id"]
        try:
            if not store.uat_allows(job) or not enrolled(job) or job.get("status") != "completed":
                continue
            _, directory, current = pipeline._context(eid)
            state = load(directory, current)
            if state["status"] in TERMINAL or (state["next_retry_at"]
                    and clock() < artifacts._utc(state["next_retry_at"]).timestamp()):
                continue
            if state["status"] not in {"sending", "processing"} and pipeline._state(directory, eid)["package_status"] != "ready":
                continue
            outcome = advance(eid, clock=clock)
            if outcome["status"] != "locked":
                results.append({"event_id": eid, "result": "email_recovery", "email": outcome})
        except INVALID:
            continue
    return results
