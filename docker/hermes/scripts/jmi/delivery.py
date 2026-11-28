"""P4-05: automatic, durable and recoverable delivery of a Package Ready job to Laravel.

Lifecycle (independent from analysis ``job.status``, pipeline readiness and e-mail):

    callback (report + manifest) -> pdf upload -> xlsx upload -> complete

State lives in ``JMI_HOME/jobs/EVENT_ID/delivery.json`` (0600, atomic, bounded, no secrets/bodies/paths). Each stage has
its own attempt counter (initial attempt + 3 retries, 600/1800/5400 s). A ``.delivery`` lease (``locks/EVENT_ID.delivery``)
allows one delivery attempt per event.

Global lock order: ``.delivery`` -> ``.pipeline`` -> job dir -> ``.proc`` -> ``.snap`` -> ``.lock`` -> pdf -> xlsx.
Delivery never takes ``.pipeline`` and every source/renderer lock is released inside ``transport.prepare_verified_package``
before any HTTP, so no lock other than ``.delivery`` is held during transport (bounded by transport timeouts).

This module imports no network library; the only network primitive is ``callback.http_request`` via ``transport``.
"""
from __future__ import annotations

import os
import time
from datetime import datetime, timezone

from . import artifacts, parse, pipeline, store, transport

SCHEMA_VERSION = 1
MAX_STATE_BYTES = 16384
COOLDOWN = 600
MAX_ATTEMPTS = 4  # initial attempt + 3 automatic retries
MAX_RECONCILES = 2
STAGES = ("callback", "pdf", "xlsx")
STAGE_STATUSES = {"pending", "processing", "acknowledged", "retry_wait", "failed_permanent", "conflict"}
OVERALL = {"waiting_package", "pending", "processing", "partial", "retry_wait", "complete", "failed_permanent", "conflict"}
FINAL = {"complete", "failed_permanent", "conflict"}
PAUSE_FILE = "delivery.pause"


class DeliveryError(Exception):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


# ------------------------------------------------------------------------------------------------------------- time

def _stamp(epoch):
    return datetime.fromtimestamp(epoch, timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def _due(stage, clock):
    return stage["status"] != "retry_wait" or stage.get("next_retry_at", "") <= _stamp(clock())


def _checkpoint(name):
    """Controlled process termination for crash-recovery QA. Inert unless JMI_TEST_MODE=1 and the name matches."""
    if os.environ.get("JMI_TEST_MODE") == "1" and os.environ.get("JMI_DELIVERY_CRASH_AT") == name:
        os._exit(75)


# -------------------------------------------------------------------------------------------------------- rollout

def rollout_enabled(environ=None) -> bool:
    """Runtime gate (kill switch). Durable enrolment is a separate marker set once at first intake."""
    environ = os.environ if environ is None else environ
    if environ.get("JMI_ARTIFACT_DELIVERY_ENABLED") != "1" or environ.get("JMI_ARTIFACT_DELIVERY_PAUSED") == "1":
        return False
    try:
        return not os.path.lexists(store.home() / PAUSE_FILE)
    except OSError:
        return False


# --------------------------------------------------------------------------------------------------------- state

def _new_stage():
    return {"status": "pending", "attempts": 0}


def _new_state(eid, now):
    return {"schema_version": SCHEMA_VERSION, "event_id": eid, "status": "waiting_package", "created_at_utc": now,
            "updated_at_utc": now, "remote_artifact_status": None, "binding": None,
            "callback": _new_stage(), "pdf": _new_stage(), "xlsx": _new_stage()}


def _valid_stage(stage):
    return (isinstance(stage, dict) and stage.get("status") in STAGE_STATUSES and type(stage.get("attempts")) is int
            and 0 <= stage["attempts"] <= MAX_ATTEMPTS)


def load_state(eid, directory):
    path = directory / "delivery.json"
    if not os.path.lexists(path):
        return None
    state = parse.strict_loads(artifacts._read(path, MAX_STATE_BYTES, "DELIVERY_STATE_INVALID"))
    if (not isinstance(state, dict) or state.get("schema_version") != SCHEMA_VERSION or state.get("event_id") != eid
            or state.get("status") not in OVERALL or not all(_valid_stage(state.get(name)) for name in STAGES)):
        raise artifacts.PackageError("DELIVERY_STATE_INVALID")
    state["status"] = _overall(state)  # derived from the stages, never trusted from the file
    return state


def _overall(state):
    stages = [state[name] for name in STAGES]
    statuses = {s["status"] for s in stages}
    if statuses == {"acknowledged"} and state.get("completed_at_utc"):
        return "complete"
    if "conflict" in statuses:
        return "conflict"
    if "failed_permanent" in statuses:
        return "failed_permanent"
    if state["callback"]["status"] == "acknowledged" and (state["pdf"]["status"] == "acknowledged" or state["xlsx"]["status"] == "acknowledged"):
        return "retry_wait" if "retry_wait" in statuses else "partial"
    if "retry_wait" in statuses:
        return "retry_wait"
    if "processing" in statuses:
        return "processing"
    return "partial" if state["callback"]["status"] == "acknowledged" else "pending"


def _save(directory, state, clock):
    state["status"] = _overall(state)
    state["updated_at_utc"] = _stamp(clock())
    store.atomic_write(directory / "delivery.json", state)


# -------------------------------------------------------------------------------------------------------- helpers

def _package_ready(directory, eid):
    try:
        pstate = pipeline._state(directory, eid)
    except (OSError, artifacts.PackageError, parse.PayloadError):
        return False
    return pstate["package_status"] == "ready"


def needs_recovery(job, clock=time.time) -> bool:
    """True when an enrolled, completed job has incomplete delivery work that is due. Read-only, no network."""
    try:
        if not store.delivery_eligible(job) or job.get("status") != "completed" or not rollout_enabled():
            return False
        _, directory, _ = pipeline._context(job["event_id"])
        if not _package_ready(directory, job["event_id"]):
            return False
        state = load_state(job["event_id"], directory)
    except (OSError, ValueError, KeyError, parse.PayloadError, artifacts.PackageError):
        return False
    if state is None:
        return True
    if state["status"] in FINAL:
        return False
    return any(state[name]["status"] in ("pending", "processing") or (state[name]["status"] == "retry_wait" and _due(state[name], clock))
               for name in STAGES)


def _fail_stage(stage, status, code, **extra):
    stage.update(status=status, error_code=code, **extra)
    stage.pop("next_retry_at", None)


def _record(stage, result, clock, cooldown):
    """Fold one TransportResult into a stage (non-ok outcomes only)."""
    stage["last_http_status"] = result.http_status
    code = result.error_code
    if result.result == transport.RETRY:
        if stage["attempts"] >= MAX_ATTEMPTS:
            _fail_stage(stage, "failed_permanent", code, retry_exhausted=True)
        else:
            delay = max(cooldown * 3 ** max(0, stage["attempts"] - 1), result.retry_after or 0)
            stage.update(status="retry_wait", error_code=code, next_retry_at=_stamp(clock() + delay))
    elif result.result == transport.CONFLICT:
        _fail_stage(stage, "conflict", code)
    elif result.result == transport.OPERATOR:
        _fail_stage(stage, "failed_permanent", code, operator_required=True)
    else:
        _fail_stage(stage, "failed_permanent", code)


def _begin(directory, state, stage, clock):
    if stage["status"] != "processing":  # a stranded processing attempt keeps its slot
        stage["attempts"] += 1
    stage.update(status="processing", error_code=None, started_at_utc=_stamp(clock()))
    stage.pop("next_retry_at", None)
    _save(directory, state, clock)


def _bind(state, package):
    identity = {"event_id": package.event_id, "source_report_sha256": package.source_report_sha256,
                "artifact_set_hash": package.artifact_set_hash,
                "transport_artifact_set_hash": package.transport_artifact_set_hash}
    if state["binding"] is None:
        state["binding"] = identity
        return True
    return state["binding"] == identity


def _ack_callback(stage, state, result, clock):
    prior = stage.get("report_id")
    if prior is not None and prior != result.report_id:
        raise DeliveryError("REPORT_ID_CHANGED")
    stage.update(status="acknowledged", error_code=None, report_id=result.report_id, report_hash=result.report_hash,
                 ack_transport_artifact_set_hash=result.transport_artifact_set_hash, ack_status=result.status_text,
                 ack_at_utc=_stamp(clock()), http_status=result.http_status, reconcile=False)
    stage.pop("next_retry_at", None)
    state["remote_artifact_status"] = result.artifact_status


def _ack_artifact(stage, state, result, clock):
    stage.update(status="acknowledged", error_code=None, sha256=result.sha256, size_bytes=result.size_bytes,
                 ack_status=result.status_text, recovered=bool(result.recovered), ack_at_utc=_stamp(clock()),
                 http_status=result.http_status, remote_status=result.artifact_status)
    stage.pop("next_retry_at", None)
    state["remote_artifact_status"] = result.artifact_status


# ----------------------------------------------------------------------------------------------------- orchestration

def advance(eid, *, manual=False, clock=time.time, cooldown=None, config=None, transports=None, environ=None) -> dict:
    """Run every due delivery stage of one event. Safe to call repeatedly and concurrently (one lease per event)."""
    if not store.uat_allows(store.read_job(eid)):
        return {"event_id": eid, "result": "blocked", "error_code": "UAT_SCOPE_DENIED"}
    store.check_id(eid)
    cooldown = float(os.environ.get("JMI_DELIVERY_COOLDOWN", COOLDOWN)) if cooldown is None else cooldown
    try:
        root, directory, job = pipeline._context(eid)
    except (OSError, ValueError, KeyError, parse.PayloadError, artifacts.PackageError) as error:
        return {"event_id": eid, "result": "failed", "error_code": str(error) if isinstance(error, artifacts.PackageError) else "DELIVERY_CONTEXT_INVALID"}
    if not store.delivery_eligible(job):
        return {"event_id": eid, "result": "not_enrolled"}
    if job.get("status") != "completed":
        return {"event_id": eid, "result": "waiting_analysis"}
    if not rollout_enabled(environ):
        return {"event_id": eid, "result": "disabled"}  # kill switch: no new transport, acknowledgments untouched
    try:
        with artifacts._lock(root / "locks" / f"{eid}.delivery", create=True, nonblocking=True):
            return _advance_locked(eid, directory, manual, clock, cooldown, config, transports)
    except artifacts.PackageError as error:
        if str(error) == "PACKAGE_LOCK_BUSY":
            return {"event_id": eid, "result": "skipped_locked"}
        return {"event_id": eid, "result": "failed", "error_code": str(error)}
    except OSError:
        return {"event_id": eid, "result": "failed", "error_code": "DELIVERY_IO_FAILED"}


def _summary(eid, state, **extra):
    return {"event_id": eid, "result": state["status"], "remote_artifact_status": state.get("remote_artifact_status"),
            "stages": {name: {k: state[name].get(k) for k in ("status", "attempts", "error_code", "next_retry_at")} for name in STAGES},
            **extra}


def _requeue_manual(state):
    """Operator resume: failed_permanent stages get a fresh attempt budget. Conflicts and acknowledgments stay."""
    for name in STAGES:
        stage = state[name]
        if stage["status"] == "failed_permanent":
            stage.update(status="pending", attempts=0, operator_requeues=stage.get("operator_requeues", 0) + 1)
            stage.pop("retry_exhausted", None)
            stage.pop("operator_required", None)


def _advance_locked(eid, directory, manual, clock, cooldown, config, transports):
    try:
        state = load_state(eid, directory)
    except (artifacts.PackageError, parse.PayloadError) :
        return {"event_id": eid, "result": "failed", "error_code": "DELIVERY_STATE_INVALID"}
    if state is None:
        if not _package_ready(directory, eid):
            return {"event_id": eid, "result": "waiting_package"}
        state = _new_state(eid, _stamp(clock()))
    if state["status"] == "complete":
        return _summary(eid, state)
    if state["status"] == "conflict":
        return _summary(eid, state)
    if manual:
        _requeue_manual(state)
    elif state["status"] == "failed_permanent":
        return _summary(eid, state)
    if config is None:
        cb_cfg, art_cfg, problems = transport.load_transport()
    else:
        (cb_cfg, art_cfg), problems = config, []
    if problems:
        return {"event_id": eid, "result": "config_invalid", "problems": problems}  # fail closed: no network, no state change
    send = transports or {}

    # Nothing due -> no package read, no network.
    pending = [name for name in STAGES if state[name]["status"] != "acknowledged"]
    if not pending:
        return _finish(eid, directory, state, clock)
    if not any(state[n]["status"] in ("pending", "processing") or (state[n]["status"] == "retry_wait" and _due(state[n], clock))
               for n in pending):
        return _summary(eid, state)

    try:
        package = transport.prepare_verified_package(eid)
    except transport.TransportPackageError as error:
        if error.code in ("PACKAGE_LOCK_BUSY", "PACKAGE_IO_FAILED", "PACKAGE_SOURCE_MISSING") and not manual:
            return {"event_id": eid, "result": "package_busy", "error_code": error.code}
        return _package_failure(eid, directory, state, error.code, clock)
    if not _bind(state, package):
        for name in pending:
            _fail_stage(state[name], "conflict", "BINDING_CONFLICT")
        _save(directory, state, clock)
        return _summary(eid, state)
    _save(directory, state, clock)

    cb = state["callback"]
    if cb["status"] in ("pending", "processing") or (cb["status"] == "retry_wait" and _due(cb, clock)):
        if not store.uat_allows(store.read_job(eid)):
            return _summary(eid, state, error_code="UAT_SCOPE_DENIED")
        _begin(directory, state, cb, clock)
        result = transport.send_manifest_callback(package, cb_cfg, transport=send.get("callback"), clock=clock)
        _checkpoint("callback_after_response")
        if result.result == transport.OK:
            try:
                _ack_callback(cb, state, result, clock)
            except DeliveryError as error:
                _fail_stage(cb, "conflict", error.code)
            _save(directory, state, clock)
            _checkpoint("callback_after_ack")
        else:
            _record(cb, result, clock, cooldown)
            _save(directory, state, clock)
            return _summary(eid, state, http_status=result.http_status)
    if cb["status"] != "acknowledged":
        return _summary(eid, state)

    for kind in transport.KINDS:
        stage = state[kind]
        if stage["status"] == "acknowledged" or not (stage["status"] in ("pending", "processing")
                                                      or (stage["status"] == "retry_wait" and _due(stage, clock))):
            continue
        if not store.uat_allows(store.read_job(eid)):
            return _summary(eid, state, error_code="UAT_SCOPE_DENIED")
        _begin(directory, state, stage, clock)
        result = transport.upload_artifact(package, kind, cb["report_id"], art_cfg, transport=send.get(kind), clock=clock)
        _checkpoint(f"{kind}_after_response")
        if result.result == transport.OK:
            _ack_artifact(stage, state, result, clock)
            _save(directory, state, clock)
            _checkpoint(f"{kind}_after_ack")
            continue
        if result.error_code in ("REPORT_NOT_FOUND", "EVENT_NOT_FOUND") and cb.get("reconciles", 0) < MAX_RECONCILES:
            # Remote report vanished: re-drive the idempotent callback with the same manifest; identity must not change.
            cb.update(status="pending", attempts=0, reconcile=True, reconciles=cb.get("reconciles", 0) + 1)
            stage.update(status="pending", error_code=result.error_code)
            stage["attempts"] = max(0, stage["attempts"] - 1)
        else:
            _record(stage, result, clock, cooldown)
        _save(directory, state, clock)
    return _finish(eid, directory, state, clock)


def _package_failure(eid, directory, state, code, clock):
    """Local package invalid/changed/conflicting: stop (fail closed). Acknowledgments and package files stay untouched."""
    for name in STAGES:
        if state[name]["status"] in ("pending", "processing", "retry_wait"):
            _fail_stage(state[name], "conflict" if code.endswith("_CONFLICT") else "failed_permanent", code)
    _save(directory, state, clock)
    return _summary(eid, state, error_code=code)


def _finish(eid, directory, state, clock):
    if all(state[name]["status"] == "acknowledged" for name in STAGES) and not state.get("completed_at_utc"):
        _checkpoint("before_complete")
        if state["remote_artifact_status"] != "complete":
            _fail_stage(state["xlsx"], "conflict", "REMOTE_STATUS_NOT_COMPLETE")
        else:
            state["completed_at_utc"] = _stamp(clock())
        _save(directory, state, clock)
        _checkpoint("after_complete")
    return _summary(eid, state)


# ------------------------------------------------------------------------------------------------------------ CLI

def status(eid) -> dict:
    """Read-only local status. Never touches the network, the model or any lock."""
    try:
        _, directory, job = pipeline._context(eid)
        state = load_state(eid, directory)
    except (OSError, ValueError, KeyError, parse.PayloadError, artifacts.PackageError) as error:
        return {"event_id": eid, "result": "failed", "error_code": str(error) if isinstance(error, artifacts.PackageError) else "DELIVERY_CONTEXT_INVALID"}
    if not store.delivery_eligible(job):
        return {"event_id": eid, "status": "not_enrolled"}
    if state is None:
        return {"event_id": eid, "status": "waiting_package" if not _package_ready(directory, eid) else "pending"}
    return state


def verify(eid) -> dict:
    """Verify the local package and that durable acknowledgments match it. Remote integrity is NOT claimed."""
    out = {"event_id": eid, "remote_integrity_verified": False}
    state = status(eid)
    if state.get("result") == "failed" or state.get("status") == "not_enrolled":
        return {**out, **state}
    try:
        package = transport.prepare_verified_package(eid)
    except transport.TransportPackageError as error:
        return {**out, "result": "failed", "error_code": error.code}
    out.update(result="valid", package_status="ready", delivery_status=state.get("status"))
    if state.get("binding") is not None:
        same = state["binding"] == {"event_id": eid, "source_report_sha256": package.source_report_sha256,
                                    "artifact_set_hash": package.artifact_set_hash,
                                    "transport_artifact_set_hash": package.transport_artifact_set_hash}
        out["binding_matches_package"] = same
        if not same:
            return {**out, "result": "failed", "error_code": "BINDING_CONFLICT"}
    for kind in transport.KINDS:
        ack = state.get(kind) if isinstance(state, dict) else None
        if ack and ack.get("status") == "acknowledged" and ack.get("sha256") != package.entry(kind)["sha256"]:
            return {**out, "result": "failed", "error_code": "ACK_SHA256_MISMATCH"}
    if state.get("status") == "complete":
        out["note"] = f"complete is a historical receiver acknowledgment at {state.get('completed_at_utc')}; live remote integrity is not verified here"
    return out


def command(eid, cmd):
    if cmd == "delivery-status":
        res = status(eid)
        return res, 3 if res.get("error_code") else 0
    if cmd == "delivery-verify":
        res = verify(eid)
        return res, 3 if res.get("result") == "failed" else 0
    res = advance(eid, manual=True)
    return res, 0 if res.get("result") == "complete" else (3 if res.get("error_code") else 1)
