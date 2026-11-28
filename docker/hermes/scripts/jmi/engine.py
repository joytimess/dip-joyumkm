"""Job processing: model call, validation, bounded retry, recovery."""
from __future__ import annotations

import hashlib
import json
import os
import random
import re
import shutil
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

from . import monthly, parse, schema, snapshot, store
from .prompt import PROMPT_VERSION, build_prompt

PKG = Path(__file__).resolve().parent.parent
MODEL = "claude-sonnet-5-5[1m]"
PROVIDER = "claude-subscription-directsdk-experimental"
MAX_CYCLE_ATTEMPTS = int(os.environ.get("JMI_MAX_ATTEMPTS", "4"))
MAX_REPAIRS = 1
MODEL_TIMEOUT = int(os.environ.get("JMI_MODEL_TIMEOUT", "420"))
MAX_AUTO_RETRY_CYCLES = 3
TRANSIENT = {"RATE_LIMITED", "TIMEOUT", "MODEL_ERROR", "EMPTY_RESPONSE"}
# Only classified, fixed text is persisted: raw provider/model diagnostics are never stored.
SAFE_DETAIL = {
    "RATE_LIMITED": "Provider membatasi laju permintaan (429/overloaded).",
    "TIMEOUT": "Model melewati batas waktu.",
    "MODEL_ERROR": "Provider/model mengembalikan error.",
    "EMPTY_RESPONSE": "Respons model kosong.",
    "INVALID_MODEL_JSON": "Keluaran model bukan satu objek JSON yang valid.",
    "SCHEMA_MISMATCH": "Keluaran model tidak sesuai schema laporan.",
    "EVIDENCE_POLICY_VIOLATION": "Keluaran model melanggar aturan bukti/klaim.",
    "INTERNAL_ERROR": "Kesalahan internal pemroses.",
    "MAX_ATTEMPTS_EXCEEDED": "Batas percobaan siklus tercapai.",
}


class ModelError(Exception):
    def __init__(self, code, transient=True, detail=""):
        super().__init__(f"{code}: {detail}")
        self.code, self.transient, self.detail = code, transient, detail


def load_knowledge() -> dict:
    return json.loads((PKG / "knowledge" / "methodology.v1.json").read_text())


def hermes_model_runner(prompt: str) -> str:
    """Real runner: one-shot Hermes call, tools disabled."""
    if os.environ.get("JMI_TEST_MODE") == "1" and os.environ.get("JMI_FAKE_MODEL"):
        try:
            r = subprocess.run([os.environ["JMI_FAKE_MODEL"]], input=prompt, capture_output=True, text=True, timeout=MODEL_TIMEOUT)
        except subprocess.TimeoutExpired:
            raise ModelError("TIMEOUT", True)
        if r.returncode == 29:
            raise ModelError("RATE_LIMITED", True)
        if r.returncode != 0:
            raise ModelError("MODEL_ERROR", True)
        return r.stdout
    exe = shutil.which("hermes") or "/opt/hermes/bin/hermes"
    env = dict(os.environ)
    env.setdefault("HERMES_HOME", "/opt/data")
    try:
        r = subprocess.run([exe, "-z", prompt, "--provider", PROVIDER, "-m", MODEL, "-t", ""],
                           capture_output=True, text=True, timeout=MODEL_TIMEOUT, env=env, stdin=subprocess.DEVNULL)
    except subprocess.TimeoutExpired:
        raise ModelError("TIMEOUT", True)
    if r.returncode != 0:
        low = ((r.stderr or "") + (r.stdout or "")).lower()
        if "429" in low or "rate limit" in low or "rate_limit" in low or "overloaded" in low or "529" in low:
            raise ModelError("RATE_LIMITED", True)
        raise ModelError("MODEL_ERROR", True)
    out = r.stdout.strip()
    if not out:
        raise ModelError("EMPTY_RESPONSE", True)
    return out


_FENCE = re.compile(r"\A```(?:json)?[ \t]*\n(.*)\n```[ \t]*\Z", re.S)


def extract_report(text) -> dict:
    """Whole model response must be exactly one JSON object (optionally in ONE code fence)."""
    if not isinstance(text, str):
        raise schema.ValidationFailure("INVALID_MODEL_JSON", ["response is not text"])
    t = text.strip()
    m = _FENCE.match(t)
    if m:
        t = m.group(1).strip()
    try:
        obj = parse.strict_loads(t)
    except parse.PayloadError as e:
        raise schema.ValidationFailure("INVALID_MODEL_JSON", [e.code])
    if not isinstance(obj, dict):
        raise schema.ValidationFailure("INVALID_MODEL_JSON", ["top-level is not an object"])
    if set(obj) == {"report"} and isinstance(obj["report"], dict):
        obj = obj["report"]
    return obj


def _sysfill(report: dict, evidence: dict) -> None:
    """System-owned fields override model output (integrity of source data). Types checked first."""
    lims = report.get("limitations", [])
    if not isinstance(lims, list) or not all(isinstance(x, str) for x in lims):
        raise schema.ValidationFailure("SCHEMA_MISMATCH", ["limitations must be a list of strings"])
    bc = evidence["business_context"] or {}
    missing = {"name": "nama usaha", "sector": "sektor usaha", "location": "lokasi usaha",
               "started_year": "tahun mulai usaha", "employee_count": "jumlah tenaga kerja",
               "business_capital": "modal usaha", "revenue.amount": "jumlah omzet", "revenue.period": "periode omzet"}
    knowledge = load_knowledge()
    gaps = []
    for warning in evidence["data_quality_warnings"]:
        if warning.startswith("business_context_missing:"):
            label = missing.get(warning.split(":", 1)[1], "data konteks usaha")
            text = f"Data {label} tidak tersedia dalam payload."
        else:
            text = schema.gap_sentence(warning, knowledge)
        if text not in gaps:
            gaps.append(text)
    report["business_profile"] = {"name": bc.get("name"), "sector": bc.get("sector"), "data_gaps": gaps}
    base = list(schema.SYSTEM_LIMITATIONS)
    lims = [x for x in lims if x not in base][:13]  # reserve room: mandatory limitations are never truncated away
    report["limitations"] = lims + base


_REQUIRED = {"attempts": int, "cycle_attempts": int, "attempt_log": list, "payload_hash": str, "created_at": str}


def job_usable(job) -> bool:
    return isinstance(job, dict) and all(isinstance(job.get(k), t) and not isinstance(job.get(k), bool) for k, t in _REQUIRED.items())


def quarantine(eid: str) -> None:
    """Move unusable job.json aside so it can never occupy a recovery slot again (kept for forensics)."""
    d = store.job_dir(eid)
    src = d / "job.json"
    if src.exists():
        os.replace(src, d / f"job.invalid-{int(time.time())}.json")
        store.fsync_dir(d)


def _set_report_aside(eid: str) -> None:
    d = store.job_dir(eid)
    src = d / "report.json"
    if src.exists():
        os.replace(src, d / f"report.invalid-{int(time.time())}.json")
        store.fsync_dir(d)


def _validate_document(doc, evidence, knowledge, job, factual=None):
    if not isinstance(doc, dict):
        raise schema.ValidationFailure("SCHEMA_MISMATCH", ["report document must be an object"])
    core = dict(doc)
    extension = core.pop("monthly_extension", None)
    if job.get("monthly_pipeline"):
        factual = factual if factual is not None else monthly.facts(job["event_id"], job)
        evidence = monthly.current_evidence(evidence, factual)
    schema.validate_document(core, evidence, knowledge, job.get("prompt_version"))
    if job.get("monthly_pipeline"):
        if not isinstance(extension, dict) or extension != monthly.extension(
                factual, extension.get("analysis"), core["report"], evidence):
            raise schema.ValidationFailure("SCHEMA_MISMATCH", ["monthly extension differs from validated data"])
    elif extension is not None:
        raise schema.ValidationFailure("SCHEMA_MISMATCH", ["unexpected monthly extension"])


def report_matches_job(eid: str) -> bool:
    """Full integrity check of a stored report: parseable, identity, prompt-version binding, canonical rendering."""
    try:
        job = store.read_job(eid)
        evidence = json.loads((store.job_dir(eid) / "evidence.json").read_text())
        rep = store.read_report(eid)
        if not isinstance(rep, dict):
            return False
        _validate_document(rep, evidence, load_knowledge(), job)
        return True
    except Exception:
        return False


def spawn_worker(eid: str) -> None:
    """Start a detached worker for one job (idempotent: the .proc lock admits only one real worker)."""
    store.check_id(eid)
    log = store.open_private_append(store.home() / "worker.log")
    subprocess.Popen([sys.executable, "-m", "jmi", "process", eid], cwd=PKG, stdin=subprocess.DEVNULL,
                     stdout=log, stderr=log, start_new_session=True, close_fds=True)
    log.close()


def _fail(eid, code, retryable=True):
    def f(j):
        j.update(status="failed", error_code=code, error_detail=SAFE_DETAIL.get(code, "Gagal."), retryable=retryable, failed_at=store.now())
    return store.update_job(eid, f)


def _accepted_submission(job):
    if "analysis_submission" in job:
        job["analysis_submission"].update(state="accepted", ended_at=store.now())


def _corrective_ready(job) -> bool:
    """Owner-authorized bounded automatic correction: each provider submission consumes one budget unit before the call."""
    auth = job.get("corrective_authorization") if isinstance(job, dict) else None
    return (isinstance(auth, dict) and auth.get("status") == "authorized" and auth.get("event_id") == job.get("event_id")
            and bool(job.get("monthly_pipeline")) and isinstance(job.get("analysis_submission"), dict)
            and int(auth.get("used", 0)) < int(auth.get("max_attempts", 0)))


def _pending_candidate(job):
    """A stored response from an earlier submission that may be normalized and validated without a new model call."""
    candidate = job.get("candidate_response") if isinstance(job, dict) else None
    return candidate if isinstance(candidate, dict) and candidate.get("status") == "pending" else None


def _record_attempt(directory, n: int, document: dict) -> None:
    folder = directory / "attempts"
    folder.mkdir(exist_ok=True)
    store.atomic_write(folder / f"{n:03d}.json", {"attempt": n, **document})


def _analysis_unknown(eid):
    def mark(job):
        job["analysis_submission"].update(state="unknown", ended_at=store.now())
        job.update(status="failed", error_code="ANALYSIS_UNKNOWN", error_detail="Hasil pengiriman analisis tidak diketahui.", retryable=False, failed_at=store.now())
        auth = job.get("corrective_authorization")
        if isinstance(auth, dict) and auth.get("status") == "authorized":
            auth["status"] = "halted_ambiguous"  # ambiguous provider outcome: no automatic retry from here on
    store.update_job(eid, mark)
    return {"event_id": eid, "result": "failed", "error_code": "ANALYSIS_UNKNOWN"}


def process(eid: str, runner=None, sleep=time.sleep) -> dict:
    """Process one job, then (P4-04C) try the Laravel callback of a completed job. The callback never runs the
    model, is best effort and never changes the analysis result returned here."""
    if not store.uat_allows(store.read_job(eid)):
        return {"event_id": eid, "result": "blocked", "error_code": "UAT_SCOPE_DENIED"}
    res = _process(eid, runner, sleep)
    if res.get("result") == "completed" or res.get("error_code") == "FINAL_REPORT_INTEGRITY_FAILED":
        from . import callback
        job = store.read_job(eid)
        if store.artifact_eligible(job) and job["status"] == "completed":
            from . import pipeline
            res["pipeline"] = pipeline.advance(eid)
            if store.delivery_eligible(job):
                from . import delivery
                try:
                    res["delivery"] = delivery.advance(eid)
                except Exception:
                    res["delivery"] = {"result": "error", "error_code": "ARTIFACT_DELIVERY_ERROR"}
            if job.get("email_delivery"):
                from . import email_delivery, email_status_sync
                try:
                    res["email"] = email_delivery.advance(eid)
                except Exception:
                    res["email"] = {"status": "blocked", "error_code": "EMAIL_WORKER_ERROR"}
                res["email_status_sync"] = email_status_sync.run(eid)
        elif res["result"] == "completed":
            callback.after_completed(eid)
    return res


def _process(eid: str, runner=None, sleep=time.sleep) -> dict:
    """Process one job. Safe against concurrent workers (non-blocking proc lock)."""
    if not store.uat_allows(store.read_job(eid)):
        return {"event_id": eid, "result": "blocked", "error_code": "UAT_SCOPE_DENIED"}
    store.check_id(eid)  # before any path/lock construction
    runner = runner or hermes_model_runner
    with store.flock(f"{eid}.proc", blocking=False) as got:
        if not got:
            return {"event_id": eid, "result": "skipped_locked"}
        try:
            job = store.read_job(eid)
        except Exception:
            job = "unreadable"
        if job is None:
            return {"event_id": eid, "result": "not_found"}
        if not job_usable(job) or job.get("event_id") != eid:
            quarantine(eid)
            return {"event_id": eid, "result": "quarantined"}
        from . import callback  # lazy: callback imports this module
        if job.get("monthly_pipeline") and job.get("status") == "completed":
            if not report_matches_job(eid):
                return {"event_id": eid, "result": "failed", "error_code": "FINAL_REPORT_INTEGRITY_FAILED"}
            return {"event_id": eid, "result": "completed"}
        if job.get("status") == "completed" and callback.is_regen_protected(job):
            return {"event_id": eid, "result": "completed"}  # delivered/archived report is terminal: never regenerated
        d = store.job_dir(eid)
        try:
            evidence = json.loads((d / "evidence.json").read_text())
            knowledge = load_knowledge()
        except Exception:  # broken evidence: fail the job (non-retryable) instead of looping on it
            _fail(eid, "INTERNAL_ERROR", retryable=False)
            return {"event_id": eid, "result": "failed", "error_code": "INTERNAL_ERROR"}
        try:
            rep = store.read_report(eid)
        except Exception:  # truncated/corrupt report
            if job.get("monthly_pipeline"):
                _fail(eid, "FINAL_REPORT_INTEGRITY_FAILED", retryable=False)
                return {"event_id": eid, "result": "failed", "error_code": "FINAL_REPORT_INTEGRITY_FAILED"}
            _set_report_aside(eid)
            rep = None
            if job["status"] == "completed":
                job = store.update_job(eid, lambda j: j.update(status="pending", cycle_attempts=0, report_path=None))
        if rep is not None:  # recovery: report durable but job state not updated (crash between writes)
            try:
                _validate_document(rep, evidence, knowledge, job)
                if job["status"] != "completed":
                    state = dict(status="completed", error_code=None, error_detail=None, retryable=None,
                                 failed_at=None, report_path=str(d / "report.json"))
                    if job.get("monthly_pipeline"):
                        try:
                            store.fsync_file(d / "report.json")
                            store.fsync_dir(d)
                        except OSError:
                            _fail(eid, "REPORT_WRITE_FAILED")
                            return {"event_id": eid, "result": "failed", "error_code": "REPORT_WRITE_FAILED"}
                        state["stage"] = "ready"
                    def reconcile(j):
                        _accepted_submission(j)
                        j.update(state)
                    store.update_job(eid, reconcile)
                return {"event_id": eid, "result": "completed"}
            except (OSError, KeyError, TypeError, ValueError, parse.PayloadError, schema.ValidationFailure, snapshot.SnapshotError):
                if job.get("monthly_pipeline"):
                    _fail(eid, "FINAL_REPORT_INTEGRITY_FAILED", retryable=False)
                    return {"event_id": eid, "result": "failed", "error_code": "FINAL_REPORT_INTEGRITY_FAILED"}
                _set_report_aside(eid)  # never leave an invalid/foreign report in place
        if job["status"] == "completed":  # report missing/invalid although state says completed: integrity repair
            job = store.update_job(eid, lambda j: j.update(status="pending", cycle_attempts=0, report_path=None))
        if job["status"] == "failed" and not (
            job.get("analysis_submission") and (d / "report.validated.json").exists()
        ) and not _corrective_ready(job):
            return {"event_id": eid, "result": "failed_requires_retry"}
        factual = None
        validated_path = d / "report.validated.json"
        if job.get("monthly_pipeline"):
            store.update_job(eid, lambda j: j.update(status="processing", stage="snapshot"))
            try:
                result = snapshot.run(eid)
            except OSError:
                result = {"result": "failed", "error_code": "SNAPSHOT_IO_FAILED", "retryable": True}
            if result["result"] not in ("created", "exists"):
                code = result.get("error_code", "SNAPSHOT_INTEGRITY_FAILED")
                _fail(eid, code, result.get("retryable", False))
                return {"event_id": eid, "result": "failed", "error_code": code}
            store.update_job(eid, lambda j: j.update(stage="recap"))
            try:
                factual = monthly.facts(eid, job)
                current_evidence = monthly.current_evidence(evidence, factual)
            except (OSError, ValueError, KeyError, TypeError, parse.PayloadError, snapshot.SnapshotError):
                _fail(eid, "RECAP_FAILED", retryable=False)
                return {"event_id": eid, "result": "failed", "error_code": "RECAP_FAILED"}
            if validated_path.exists():
                try:
                    doc = parse.strict_loads(validated_path.read_bytes())
                    _validate_document(doc, evidence, knowledge, job, factual)
                except (ValueError, TypeError, KeyError, parse.PayloadError, schema.ValidationFailure, snapshot.SnapshotError):
                    _fail(eid, "VALIDATED_REPORT_INVALID", retryable=False)
                    return {"event_id": eid, "result": "failed", "error_code": "VALIDATED_REPORT_INVALID"}
                except OSError:
                    _fail(eid, "REPORT_WRITE_FAILED")
                    return {"event_id": eid, "result": "failed", "error_code": "REPORT_WRITE_FAILED"}
                return _commit_monthly(eid, d, doc)
        corrective = _corrective_ready(job)
        if job.get("analysis_submission") and not corrective:
            return _analysis_unknown(eid)
        report_evidence = current_evidence if factual is not None else evidence
        repairs: list[str] = []
        repairs_used = 0
        candidate = _pending_candidate(job) if factual is not None else None
        use_candidate = candidate is not None
        while True:
            flag = {}

            def begin(j):
                if j["cycle_attempts"] < MAX_CYCLE_ATTEMPTS:
                    j["attempts"] += 1
                    j["cycle_attempts"] += 1
                    if factual is not None:
                        j["stage"] = "analysis"
                    j.update(status="processing", model=MODEL, provider=PROVIDER, prompt_version=PROMPT_VERSION,
                             knowledge_version=knowledge["knowledge_version"], methodology_status=knowledge["methodology_status"])
                    flag["began"] = True
            job = store.update_job(eid, begin)
            if not flag.get("began"):
                job = _fail(eid, job.get("error_code") or "MAX_ATTEMPTS_EXCEEDED")
                return {"event_id": eid, "result": "failed", "error_code": job["error_code"]}
            n, started = job["attempts"], store.now()

            def record(outcome, code=None, **kw):
                def f(j):
                    j["attempt_log"].append({"n": n, "started_at": started, "ended_at": store.now(), "outcome": outcome, "error_code": code})
                    if code:
                        j.update(error_code=code, error_detail=SAFE_DETAIL.get(code, "Gagal."), retryable=True)
                    if outcome == "ok":
                        _accepted_submission(j)
                    j.update(kw)
                return store.update_job(eid, f)
            try:
                prompt = build_prompt(report_evidence, knowledge, repairs or None, monthly=factual is not None)
                if factual is not None:
                    prompt += monthly.instructions(factual)
            except Exception:
                record("internal_error", "INTERNAL_ERROR")
                _fail(eid, "INTERNAL_ERROR")
                return {"event_id": eid, "result": "failed", "error_code": "INTERNAL_ERROR"}

            if not store.uat_allows(job):
                return {"event_id": eid, "result": "blocked", "error_code": "UAT_SCOPE_DENIED"}
            if factual is not None and not use_candidate:
                intent = {
                    "event_id": eid, "attempt": n, "started_at": started, "state": "submitting",
                    "request_sha256": hashlib.sha256(prompt.encode()).hexdigest(),
                    "corrective": corrective,
                }

                def submit(j):
                    if j.get("analysis_submission"):
                        j.setdefault("analysis_submission_history", []).append(j["analysis_submission"])
                    j["analysis_submission"] = intent
                    if corrective:
                        auth = j["corrective_authorization"]
                        auth["used"] = int(auth.get("used", 0)) + 1
                        auth.setdefault("submissions", []).append({"attempt": intent["attempt"], "request_sha256": intent["request_sha256"], "at": store.now()})
                        if auth["used"] >= int(auth["max_attempts"]):
                            auth["status"] = "exhausted"

                store.update_job(eid, submit)  # budget unit consumed and intent durable before the model call
            raw_text, normalizations, was_candidate = None, [], use_candidate
            try:
                if use_candidate:
                    raw_text = (d / candidate["file"]).read_text(encoding="utf-8")
                    store.update_job(eid, lambda j: j["candidate_response"].update(status="consumed", consumed_at=store.now()))
                    use_candidate = False
                else:
                    raw_text = runner(prompt)
                report = extract_report(raw_text)
                analysis = None
                if factual is not None:
                    if set(report) != {"report", "monthly_analysis"} or not isinstance(report["report"], dict):
                        raise schema.ValidationFailure("SCHEMA_MISMATCH", ["report and monthly_analysis required"])
                    analysis, report = report["monthly_analysis"], report["report"]
                    refs, comparable = monthly.analysis_refs(factual)
                    normalizations = monthly.normalize(report, analysis, report_evidence, refs, comparable)
                _sysfill(report, report_evidence)
                schema.finalize_report(report, report_evidence, knowledge)  # system renders facts/gaps/cross text, then validates
                doc = {
                    "schema_version": 1, "event_id": eid, "source_event_version": evidence["source_event_version"],
                    "umkm_id": evidence["umkm_id"], "evaluation_id": evidence["six_factor"]["evaluation_id"],
                    "status": "completed", "model": MODEL, "provider": PROVIDER, "generated_at": store.now(),
                    "methodology_status": knowledge["methodology_status"], "knowledge_version": knowledge["knowledge_version"],
                    "prompt_version": PROMPT_VERSION, "report": report,
                }
                if factual is not None:
                    try:
                        doc["monthly_extension"] = monthly.extension(factual, analysis, report, report_evidence)
                    except snapshot.SnapshotError:
                        raise schema.ValidationFailure("EVIDENCE_POLICY_VIOLATION", ["contact data forbidden"])
                    _validate_document(doc, evidence, knowledge, job, factual)
                    try:
                        store.atomic_write(validated_path, doc)
                    except OSError:
                        return _analysis_unknown(eid)
                    _record_attempt(d, n, {"source": "stored_candidate" if was_candidate else "provider", "outcome": "accepted",
                                           "prompt_version": PROMPT_VERSION if not was_candidate else candidate.get("generated_under"),
                                           "response_sha256": hashlib.sha256(raw_text.encode()).hexdigest(),
                                           "response": raw_text, "normalizations": normalizations, "validation_errors": [],
                                           "usage": None})
                    return _commit_monthly(eid, d, doc, record)
                schema.validate_document(doc, evidence, knowledge)
                store.atomic_write(d / "report.json", doc)  # durable first, then state
                record("ok", status="completed", error_code=None, error_detail=None, retryable=None, failed_at=None, report_path=str(d / "report.json"))
                return {"event_id": eid, "result": "completed", "attempts": n}
            except ModelError as e:
                if factual is not None:
                    return _analysis_unknown(eid)
                job = record("model_error", e.code)
                if factual is not None and not e.transient:
                    _fail(eid, e.code, retryable=False)
                    return {"event_id": eid, "result": "failed", "error_code": e.code}
                if job["cycle_attempts"] < MAX_CYCLE_ATTEMPTS:
                    base = float(os.environ.get("JMI_BACKOFF_BASE", "5"))
                    sleep(min(120.0, base * (3 ** (job["cycle_attempts"] - 1))) * (1 + random.random() * 0.2))
            except schema.ValidationFailure as e:
                if factual is not None:
                    # A rejected response was received, so the outcome is known (not ambiguous): record it and feed the
                    # specific errors back to the model while the authorized budget allows; otherwise stop without retry.
                    _record_attempt(d, n, {"source": "stored_candidate" if was_candidate else "provider", "outcome": "validation_rejected",
                                           "prompt_version": PROMPT_VERSION if not was_candidate else candidate.get("generated_under"),
                                           "response_sha256": hashlib.sha256(raw_text.encode()).hexdigest() if raw_text else None,
                                           "response": raw_text, "normalizations": normalizations,
                                           "validation_code": e.code, "validation_errors": e.errors, "usage": None})
                    record("validation_rejected", e.code, validation_issue_count=len(e.errors))
                    if corrective and _corrective_ready(store.read_job(eid)):
                        repairs = e.errors  # sent to the model as PERBAIKAN in the next authorized attempt
                        continue
                    if corrective:
                        _fail(eid, e.code, retryable=False)
                        return {"event_id": eid, "result": "failed", "error_code": e.code}
                    return _analysis_unknown(eid)
                record("validation_failed", e.code, validation_issue_count=len(e.errors))
                repairs_used += 1
                if repairs_used > MAX_REPAIRS:
                    _fail(eid, e.code)
                    return {"event_id": eid, "result": "failed", "error_code": e.code}
                repairs = e.errors  # in-memory only (never persisted)
            except Exception:  # never strand a job in 'processing'
                if factual is not None:
                    return _analysis_unknown(eid)
                record("internal_error", "INTERNAL_ERROR")
                _fail(eid, "INTERNAL_ERROR")
                return {"event_id": eid, "result": "failed", "error_code": "INTERNAL_ERROR"}


def _commit_monthly(eid, directory, doc, record=None):
    try:
        store.atomic_write(directory / "report.json", doc)
    except OSError:
        _fail(eid, "REPORT_WRITE_FAILED")
        return {"event_id": eid, "result": "failed", "error_code": "REPORT_WRITE_FAILED"}
    state = dict(status="completed", stage="ready", error_code=None, error_detail=None,
                 retryable=None, failed_at=None, report_path=str(directory / "report.json"))
    if record is None:
        def complete(job):
            _accepted_submission(job)
            job.update(state)
        store.update_job(eid, complete)
    else:
        record("ok", **state)
    return {"event_id": eid, "result": "completed"}


def retry(eid: str, auto: bool = False) -> dict:
    """Re-queue a failed job: resets the attempt cycle. `auto` counts toward the auto-retry budget."""
    if not store.uat_allows(store.read_job(eid)):
        return {"event_id": eid, "result": "blocked", "error_code": "UAT_SCOPE_DENIED"}
    store.check_id(eid)
    with store.flock(f"{eid}.lock"):
        job = store.read_job(eid)
        if job is None:
            return {"event_id": eid, "result": "not_found"}
        if job.get("analysis_submission") and job["status"] == "failed":
            return {"event_id": eid, "result": "analysis_retry_blocked"}
        if job["status"] != "failed":
            return {"event_id": eid, "result": "not_failed", "status": job["status"]}
        if auto and not _auto_retry_due(job):
            return {"event_id": eid, "result": "not_due"}
        job.update(status="pending", cycle_attempts=0, error_code=None, error_detail=None, retryable=None)
        if auto:
            job["auto_retry_cycles"] = job.get("auto_retry_cycles", 0) + 1
        store.write_job(job)
    return {"event_id": eid, "result": "requeued"}


def _auto_retry_due(job) -> bool:
    if job["status"] != "failed" or not job.get("retryable") or (
            job.get("error_code") not in TRANSIENT
            and not (job.get("monthly_pipeline") and (job.get("stage") == "snapshot"
                      or job.get("error_code") == "REPORT_WRITE_FAILED"))):
        return False
    cycles = job.get("auto_retry_cycles", 0)
    if cycles >= MAX_AUTO_RETRY_CYCLES or not job.get("failed_at"):
        return False
    cooldown = float(os.environ.get("JMI_AUTO_RETRY_COOLDOWN", "600")) * (3 ** cycles)
    try:
        failed = datetime.strptime(job["failed_at"], "%Y-%m-%dT%H:%M:%S.%fZ").replace(tzinfo=timezone.utc).timestamp()
    except ValueError:
        return False
    return time.time() >= failed + cooldown


def recover(runner=None, sleep=time.sleep, limit=20, spawn=False) -> list:
    """Process pending jobs, jobs left 'processing' by a dead worker (lock free => worker is gone), and
    transient-failed jobs past their cooldown. Jobs whose worker lock is held are skipped WITHOUT consuming
    the `limit`, so busy jobs can never starve later ones. Completed jobs can resume explicitly enrolled artifact stages."""
    out, side = [], []  # `side`: housekeeping results (quarantine) that never consume the work limit
    for job in store.list_jobs():
        if len(out) >= limit:
            break
        eid = job["event_id"]
        try:
            if not job_usable(job):
                quarantine(eid)
                side.append({"event_id": eid, "result": "quarantined"})
                continue
            if job["status"] == "completed":
                from . import callback  # lazy: callback imports this module
                if store.artifact_eligible(job):
                    from . import pipeline
                    from . import delivery
                    if pipeline.needs_recovery(job) or delivery.needs_recovery(job):
                        if spawn:
                            spawn_worker(eid)
                            out.append({"event_id": eid, "result": "spawned"})
                        else:
                            result = pipeline.advance(eid)
                            extra = {"delivery": delivery.advance(eid)} if store.delivery_eligible(job) else {}
                            if result.get("error_code") != "PACKAGE_LOCK_BUSY":
                                out.append({"event_id": eid, "result": "completed", "pipeline": result, **extra})
                    continue
                if job.get("monthly_pipeline") or callback.is_regen_protected(job):
                    continue  # delivered/archived: never reset to pending for regeneration
                if not report_matches_job(eid):  # report missing/corrupt/foreign: requeue for regeneration
                    store.update_job(eid, lambda j: j.update(status="pending", cycle_attempts=0, report_path=None))
                    job = store.read_job(eid)
                else:
                    continue
            if job["status"] == "failed" and _auto_retry_due(job):
                retry(eid, auto=True)  # re-checks eligibility under the metadata lock
                job = store.read_job(eid)
            if job["status"] not in ("pending", "processing"):
                continue
        except Exception:
            continue
        with store.flock(f"{eid}.proc", blocking=False) as free:
            if not free:
                continue
        try:
            if spawn:
                spawn_worker(eid)
                out.append({"event_id": eid, "result": "spawned"})
            else:
                r = process(eid, runner, sleep)
                if r["result"] != "skipped_locked":
                    out.append(r)
        except Exception:  # one bad job must never abort the sweep
            out.append({"event_id": eid, "result": "error"})
    from . import email_delivery, email_status_sync
    return out + side + email_delivery.recover(limit) + email_status_sync.recover(limit)
