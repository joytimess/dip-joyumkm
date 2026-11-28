"""Post-analysis orchestration for jobs enrolled at intake."""
import errno
import os
import time
from contextlib import ExitStack
from datetime import datetime, timezone
from pathlib import Path

from . import artifacts, parse, pdf, store, xlsx

STAGES = {"pdf": (pdf, pdf.PdfError), "xlsx": (xlsx, xlsx.XlsxError)}
TERMINAL = {"invalid", "conflict", "failed_permanent"}
RETRYABLE = {"PDF_RENDER_FAILED", "XLSX_RENDER_FAILED", "PACKAGE_LOCK_BUSY", "PACKAGE_IO_FAILED"}
MAX_STATE_BYTES = 8192
COOLDOWN = 600
MAX_ATTEMPTS = 4


def _context(eid):
    store.check_id(eid)
    root = Path(os.environ.get("JMI_HOME", store.DEFAULT_HOME)).absolute()
    directory = root / "jobs" / eid
    if directory.resolve() != directory:
        raise artifacts.PackageError("PACKAGE_UNSAFE_PATH")
    for path in (root, directory.parent, directory, root / "locks"):
        artifacts._private(path.lstat(), directory=True)
    job = parse.strict_loads(artifacts._read(directory / "job.json", parse.MAX_RAW_BYTES, "PACKAGE_SOURCE_INVALID"))
    if not store.artifact_eligible(job) or job.get("event_id") != eid:
        raise artifacts.PackageError("PIPELINE_INELIGIBLE")
    return root, directory, job


def _state(directory, eid):
    path = directory / "pipeline.json"
    if os.path.lexists(path):
        state = parse.strict_loads(artifacts._read(path, MAX_STATE_BYTES, "PIPELINE_STATE_INVALID"))
        if (not isinstance(state, dict) or state.get("event_id") != eid or state.get("schema_version") != 1
                or state.get("package_status") not in TERMINAL | {"pending", "processing", "retry_wait", "ready"}
                or not isinstance(state.get("stages"), dict) or set(state["stages"]) != {"pdf", "xlsx", "manifest"}):
            raise artifacts.PackageError("PIPELINE_STATE_INVALID")
        for stage in state["stages"].values():
            if (not isinstance(stage, dict) or stage.get("status") not in
                    {"pending", "processing", "ready", "retry_wait", "conflict", "failed_permanent", "invalid"}
                    or type(stage.get("attempts")) is not int or not 0 <= stage["attempts"] <= MAX_ATTEMPTS
                    or (stage["status"] == "retry_wait" and not isinstance(stage.get("next_retry_at"), str))):
                raise artifacts.PackageError("PIPELINE_STATE_INVALID")
        return state
    return dict(schema_version=1, event_id=eid, package_status="pending",
                stages={name: dict(status="pending", attempts=0) for name in (*STAGES, "manifest")})


def _error(eid, error):
    if isinstance(error, parse.PayloadError):
        code = "INVALID_EVENT_ID" if error.code == "INVALID_EVENT_ID" else "PIPELINE_STATE_INVALID"
    elif isinstance(error, artifacts.PackageError):
        code = str(error)
    elif isinstance(error, FileNotFoundError):
        code = "PACKAGE_SOURCE_MISSING"
    else:
        code = "PACKAGE_IO_FAILED"
    package = ("conflict" if code.endswith("_CONFLICT") else
               "invalid" if code.endswith(("_INVALID", "_UNSAFE_PATH", "_CORRUPT")) else "not_ready")
    return dict(event_id=eid, result="failed", error_code=code, package_status=package)


def status(eid):
    try:
        _, directory, job = _context(eid)
        return dict(_state(directory, eid), analysis_status=job["status"],
                    callback_status=(job.get("callback") or {}).get("status", "pending"))
    except (OSError, ValueError, KeyError, parse.PayloadError, artifacts.PackageError) as error:
        return _error(eid, error)


def get_ready_package(eid):
    """Return the transport projection only after current on-disk verification."""
    _, _, job = _context(eid)
    if job["status"] != "completed":
        raise artifacts.PackageError("ANALYSIS_NOT_COMPLETED")
    return artifacts.get_transport_manifest(eid, nonblocking=True)


def verify(eid):
    try:
        return dict(event_id=eid, result="valid", package_status="ready", **get_ready_package(eid))
    except (OSError, parse.PayloadError, artifacts.PackageError) as error:
        return _error(eid, error)


def _timestamp(epoch):
    return datetime.fromtimestamp(epoch, timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def _due(stage, clock):
    return stage["status"] != "retry_wait" or stage["next_retry_at"] <= _timestamp(clock())


def needs_recovery(job, clock=time.time):
    if not store.artifact_eligible(job):
        return False
    state = status(job["event_id"])
    if state.get("error_code") or state["package_status"] in TERMINAL | {"ready"}:
        return False
    stages = state["stages"]
    return any(stage["status"] in {"processing", "retry_wait"} and _due(stage, clock)
               for stage in stages.values()) or (
                   state["package_status"] != "retry_wait" and
                   any(stage["status"] == "pending" for stage in stages.values()))


def _record_failure(stage, code, clock, cooldown):
    stage.pop("next_retry_at", None)
    stage["error_code"] = code
    if code.endswith("_CONFLICT"):
        stage["status"] = "conflict"
    elif code in RETRYABLE:
        stage["status"] = "retry_wait" if stage["attempts"] < MAX_ATTEMPTS else "failed_permanent"
        if stage["status"] == "retry_wait":
            stage["next_retry_at"] = _timestamp(clock() + cooldown * 3 ** max(0, stage["attempts"] - 1))
        else:
            stage["retry_exhausted"] = True
    else:
        stage["status"] = "failed_permanent"


def _save(directory, state, status):
    state["package_status"] = status
    store.atomic_write(directory / "pipeline.json", state)


def _begin(directory, state, stage):
    # A stranded processing attempt owns its existing slot until reconciled.
    if stage["status"] != "processing":
        stage["attempts"] += 1
        stage["started_at"] = store.now()
    stage.update(status="processing", error_code=None)
    stage.pop("next_retry_at", None)
    _save(directory, state, "processing")


def _ready(stage, **identity):
    if stage["status"] != "ready":
        stage["completed_at"] = store.now()
    stage.update(status="ready", error_code=None, **identity)
    stage.pop("next_retry_at", None)


def _run_renderer(eid, directory, state, name, clock, cooldown):
    module, error_type = STAGES[name]
    stage = state["stages"][name]
    if stage["status"] in TERMINAL or not _due(stage, clock):
        return
    prefix = name.upper()
    try:
        try:
            result = module.operate(eid, verify=True, nonblocking=True)
            expected = {"valid"}
        except error_type as error:
            if str(error) not in {prefix + "_NOT_READY", prefix + "_CORRUPT"}:
                raise
            if stage["status"] == "ready":
                stage["attempts"] = 0
            _begin(directory, state, stage)
            result = module.operate(eid, nonblocking=True)
            expected = {"created", "reused", "recovered"}
        if result.get("result") not in expected:
            raise error_type(prefix + "_VALIDATION_FAILED")
        if result["source_report_sha256"] != state["source_report_sha256"]:
            raise error_type(prefix + "_SOURCE_CONFLICT")
        _ready(stage, sha256=result[f"{name}_sha256"])
    except (OSError, error_type) as error:
        if stage["status"] != "processing":
            if stage["status"] == "ready":
                stage["attempts"] = 0
            _begin(directory, state, stage)
        if isinstance(error, OSError):
            code = ("PACKAGE_LOCK_BUSY" if error.errno in (errno.EAGAIN, errno.EWOULDBLOCK)
                    else "PACKAGE_UNSAFE_PATH" if error.errno in (errno.ELOOP, errno.ENOTDIR, errno.EACCES)
                    else "PACKAGE_IO_FAILED")
        else:
            code = str(error)
        _record_failure(stage, code, clock, cooldown)
    _save(directory, state, "processing")


def _manifest(eid, directory, state, clock, cooldown):
    stage = state["stages"]["manifest"]
    committed = os.path.lexists(directory / "manifest.json")
    if not _due(stage, clock) or (not committed and stage["status"] in TERMINAL):
        return
    if not committed:
        _begin(directory, state, stage)
        result = artifacts.build_manifest(eid, nonblocking=True)
        if result["result"] in {"created", "reused"} and result["package_status"] == "ready":
            committed = True
    if committed:
        result = artifacts.verify_manifest(eid, nonblocking=True)
        if result["result"] == "valid" and result["package_status"] == "ready":
            manifest = result["manifest"]
            if state.get("source_report_sha256", manifest["source_report_sha256"]) == manifest["source_report_sha256"]:
                state["source_report_sha256"] = manifest["source_report_sha256"]
                for entry in manifest["artifacts"]:
                    _ready(state["stages"][entry["kind"]], sha256=entry["sha256"])
                _ready(stage, artifact_set_hash=manifest["artifact_set_hash"],
                       transport_artifact_set_hash=manifest["transport_artifact_set_hash"])
                _save(directory, state, "ready")
                return
            result = {"error_code": "SOURCE_CONFLICT", "package_status": "conflict"}
    code = result["error_code"]
    if committed and code not in RETRYABLE:
        stage.update(status="conflict" if result["package_status"] == "conflict" else "invalid", error_code=code)
    else:
        if stage["status"] in TERMINAL or not _due(stage, clock):
            return
        if stage["status"] == "ready":
            stage["attempts"] = 0
        if stage["status"] != "processing":
            _begin(directory, state, stage)
        _record_failure(stage, code, clock, cooldown)


def _advance(eid, root, directory, clock, cooldown):
    with artifacts._lock(root / "locks" / f"{eid}.pipeline", create=True, nonblocking=True):
        state = _state(directory, eid)
        stage = state["stages"]["manifest"]
        if os.path.lexists(directory / "manifest.json"):
            _manifest(eid, directory, state, clock, cooldown)
        elif stage["status"] == "ready" or "artifact_set_hash" in stage:
            stage.update(status="invalid", error_code="MANIFEST_MISSING")
        elif state["package_status"] not in TERMINAL:
            # Shared directory lock prevents manual manifest publication during rendering.
            with ExitStack() as locks:
                try:
                    locks.enter_context(artifacts._lock(directory, directory=True, shared=True, nonblocking=True))
                    if not os.path.lexists(directory / "manifest.json"):
                        for suffix in ("proc", "snap", "lock"):
                            locks.enter_context(artifacts._lock(root / "locks" / f"{eid}.{suffix}",
                                                               shared=True, nonblocking=True))
                        stamps = {directory / name: artifacts._stamp(directory / name) for name in artifacts.SOURCE}
                        identity = artifacts._source(directory, eid, None)
                        source = identity["source_report_sha256"]
                        if state.setdefault("source_report_sha256", source) != source:
                            raise artifacts.PackageError("SOURCE_CONFLICT")
                        for name in STAGES:
                            artifacts._unchanged(stamps)
                            _run_renderer(eid, directory, state, name, clock, cooldown)
                            if state["stages"][name]["status"] in TERMINAL:
                                break
                        artifacts._unchanged(stamps)
                except (artifacts.PackageError, OSError) as error:
                    code = (str(error) if isinstance(error, artifacts.PackageError) else
                            "PACKAGE_SOURCE_MISSING" if isinstance(error, FileNotFoundError) else "PACKAGE_IO_FAILED")
                    if code not in RETRYABLE:
                        stage.update(status="conflict" if code.endswith("_CONFLICT") else "failed_permanent",
                                     error_code=code)
                    else:
                        # No renderer ran. Its own retry budget is independent of source lock contention.
                        return _error(eid, artifacts.PackageError(code))
            if (os.path.lexists(directory / "manifest.json") or
                    (stage["status"] not in TERMINAL and
                     all(state["stages"][name]["status"] == "ready" for name in STAGES))):
                _manifest(eid, directory, state, clock, cooldown)
        if state["package_status"] != "ready" or stage["status"] != "ready":
            statuses = {item["status"] for item in state["stages"].values()}
            package = next((value for value in ("conflict", "invalid", "failed_permanent", "retry_wait")
                            if value in statuses), "pending")
            _save(directory, state, package)
        return state


def advance(eid, *, clock=time.time, cooldown=COOLDOWN):
    if not store.uat_allows(store.read_job(eid)):
        return {"event_id": eid, "result": "blocked", "error_code": "UAT_SCOPE_DENIED"}
    try:
        root, directory, job = _context(eid)
        if job["status"] != "completed":
            raise artifacts.PackageError("ANALYSIS_NOT_COMPLETED")
        return _advance(eid, root, directory, clock, cooldown)
    except (OSError, ValueError, KeyError, parse.PayloadError, artifacts.PackageError) as error:
        return _error(eid, error)
