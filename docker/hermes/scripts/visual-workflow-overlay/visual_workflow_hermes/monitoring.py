"""Fetch, shape, filter and route latest six-factor evaluations in Hermes.

The signed endpoint and Telegram recipient are configured locally. The legacy transform remains
runnable while the staged visual graph records and executes each operation as its own node.
"""
from __future__ import annotations

import concurrent.futures
from contextlib import contextmanager
import hashlib
import hmac
import json
import os
import tempfile
import time
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone
from urllib.parse import urlsplit

from .delivery import send_text
from .runner import StepError, evaluate_condition

PRESET = "umkm-monitoring"
ROUTE = "/api/internal/monitoring/six-factor/latest-finals"
PRIORITY_THRESHOLD = 13
MIN_SCORE = 6
MAX_SCORE = 30
CONNECT_TIMEOUT_SECONDS = 5
TOTAL_TIMEOUT_SECONDS = 10
MAX_RESPONSE_BYTES = 256 * 1024
DEFAULT_TARGET = "telegram"
DEFAULT_LEDGER_PATH = "/opt/data/workflow-runs/monitoring-dedupe.json"
DEFAULT_SNAPSHOT_ENV = "/opt/data/joyumkm-intelligence/state/snapshot.env"
MONTHS = (
    "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli",
    "Agustus", "September", "Oktober", "November", "Desember",
)
try:
    from zoneinfo import ZoneInfo

    JAKARTA = ZoneInfo("Asia/Jakarta")
except Exception:  # no tz database on the host: Jakarta has been a fixed UTC+7 since 1964
    JAKARTA = timezone(timedelta(hours=7))


class MonitoringError(Exception):
    """A monitoring step failure, carrying the machine-readable reason code."""

    def __init__(self, code: str):
        super().__init__(code)
        self.code = code


def now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds")


def _is_int(value) -> bool:
    return type(value) is int


def _reject_constant(value):
    raise ValueError("invalid_json_constant")


def _read_env_file(path: str) -> dict:
    values = {}
    with open(path, encoding="utf-8") as handle:
        for raw in handle:
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, _, value = line.partition("=")
            value = value.strip()
            if len(value) >= 2 and value[0] == value[-1] and value[0] in "'\"":
                value = value[1:-1]
            values[key.strip()] = value
    return values


def load_config(path: str | None = None) -> dict:
    """Origin, Host header and HMAC secret from the shared snapshot env file.

    Only the named keys are read. Nothing from the file is returned in an error or logged.
    """
    try:
        values = _read_env_file(path or os.environ.get("MONITORING_SNAPSHOT_ENV") or DEFAULT_SNAPSHOT_ENV)
    except OSError:
        raise MonitoringError("config_unavailable") from None
    secret = values.get("INTELLIGENCE_SNAPSHOT_SECRET", "")
    base = urlsplit(values.get("INTELLIGENCE_SNAPSHOT_BASE_URL", ""))
    host = values.get("INTELLIGENCE_SNAPSHOT_HTTP_HOST", "")
    if not secret or base.scheme not in ("http", "https") or not base.netloc or not host:
        raise MonitoringError("config_unavailable")
    return {"origin": f"{base.scheme}://{base.netloc}", "host": host, "secret": secret}


def sign(secret: str, timestamp: str) -> str:
    message = f"{timestamp}\nGET\n{ROUTE}".encode("utf-8")
    return hmac.new(secret.encode("utf-8"), message, hashlib.sha256).hexdigest()


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # a 3xx surfaces as an HTTPError instead of being followed


def _read_capped(response) -> bytes:
    data = bytearray()
    while len(data) <= MAX_RESPONSE_BYTES:
        chunk = response.read(MAX_RESPONSE_BYTES + 1 - len(data))
        if not chunk:
            break
        data.extend(chunk)
    return bytes(data)


def urllib_get(url: str, headers: dict, timeout: float):
    """GET with urllib, no redirects. Returns ``(status, body)``; the body is capped."""
    opener = urllib.request.build_opener(_NoRedirect)
    request = urllib.request.Request(url, headers=headers, method="GET")
    try:
        with opener.open(request, timeout=timeout) as response:
            return response.status, _read_capped(response)
    except urllib.error.HTTPError as error:
        try:
            return error.code, b""
        finally:
            error.close()


def fetch_latest_finals(config: dict, transport=None) -> list:
    """The ``data`` list of the signed latest-finals endpoint, or a MonitoringError code.

    The whole call is bounded by ``TOTAL_TIMEOUT_SECONDS``: the transport runs on a worker thread
    and is abandoned, not awaited, past that deadline. ``transport(url, headers, timeout)`` is the
    injection seam; it returns ``(status, body_bytes)`` or raises.
    """
    timestamp = str(int(time.time()))
    headers = {
        "Host": config["host"],
        "Accept": "application/json",
        "X-Intelligence-Snapshot-Timestamp": timestamp,
        "X-Intelligence-Snapshot-Signature": sign(config["secret"], timestamp),
    }
    call = transport or urllib_get
    executor = concurrent.futures.ThreadPoolExecutor(max_workers=1)
    try:
        future = executor.submit(call, config["origin"] + ROUTE, headers, CONNECT_TIMEOUT_SECONDS)
        status, body = future.result(timeout=TOTAL_TIMEOUT_SECONDS)
    except Exception:  # timeout, refused connection, DNS failure: no detail is kept
        raise MonitoringError("http_failure") from None
    finally:
        executor.shutdown(wait=False, cancel_futures=True)
    if status == 401:
        raise MonitoringError("auth_rejected")
    if status != 200:
        raise MonitoringError(f"http_status_{status}")
    if len(body) > MAX_RESPONSE_BYTES:
        raise MonitoringError("response_too_large")
    try:
        payload = json.loads(body.decode("utf-8"), parse_constant=_reject_constant)
    except (UnicodeDecodeError, ValueError, RecursionError):
        raise MonitoringError("invalid_json") from None
    data = payload.get("data") if isinstance(payload, dict) else None
    if not isinstance(data, list):
        raise MonitoringError("missing_fields")
    return data


def read_ledger(path: str) -> dict:
    """The dedupe ledger, or ``{}`` when none exists yet. An unreadable ledger is a hard stop:
    guessing that nothing was sent would re-send alerts."""
    try:
        with open(path, encoding="utf-8") as handle:
            data = json.load(handle)
    except FileNotFoundError:
        return {}
    except (OSError, ValueError):
        raise MonitoringError("ledger_unreadable") from None
    if not isinstance(data, dict):
        raise MonitoringError("ledger_unreadable")
    return data


def write_ledger(path: str, ledger: dict) -> None:
    """Replace the ledger atomically; the temp file is created 0600 and renamed into place."""
    directory = os.path.dirname(path) or "."
    os.makedirs(directory, exist_ok=True)
    fd, temp = tempfile.mkstemp(dir=directory, prefix=".monitoring-dedupe-", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(ledger, handle, ensure_ascii=False, sort_keys=True)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp, 0o600)
        os.replace(temp, path)
    except BaseException:
        try:
            os.unlink(temp)
        except OSError:
            pass
        raise


def _parse_finalized(value, fallback: datetime) -> datetime:
    """``finalized_at`` as an aware datetime. Naive values are read as UTC; a missing or
    unreadable value falls back to the run time, which is only used for the display label."""
    if not isinstance(value, str) or not value.strip():
        return fallback
    text = value.strip()
    if text[-1:] in ("Z", "z"):
        text = text[:-1] + "+00:00"
    try:
        moment = datetime.fromisoformat(text)
    except ValueError:
        return fallback
    return moment.replace(tzinfo=timezone.utc) if moment.tzinfo is None else moment


def format_waktu(moment: datetime) -> str:
    local = moment.astimezone(JAKARTA)
    return f"{local.day} {MONTHS[local.month - 1]} {local.year}, {local.hour:02d}.{local.minute:02d} WIB"


def _shape(item, run_time: datetime) -> dict:
    """The Edit Fields output for one record. Raw values are kept as received (no coercion), so
    an audit shows exactly what Laravel sent."""
    source = item if isinstance(item, dict) else {}
    moment = _parse_finalized(source.get("finalized_at"), run_time)
    return {
        "server_active": source.get("server_active"),
        "umkm_id": source.get("umkm_id"),
        "nama_umkm": source.get("nama_umkm"),
        "evaluation_id": source.get("evaluation_id"),
        "waktu_format": format_waktu(moment),
        "total_skor": source.get("total_skor"),
        "status_message": None,
        "notification_key": None,
    }


def reject_reason(item, shaped: dict):
    """The first failed rule, or None when the record is valid. Order: a missing score is
    ``evaluation_unavailable`` (never treated as zero), then the other rules."""
    if not isinstance(item, dict):
        return "missing_identity"
    total = shaped["total_skor"]
    if total is None:
        return "evaluation_unavailable"
    if shaped["server_active"] is not True:
        return "server_inactive"
    if not (_is_int(shaped["umkm_id"]) and _is_int(shaped["evaluation_id"])):
        return "missing_identity"
    if not _is_int(total):
        return "score_invalid"
    if not MIN_SCORE <= total <= MAX_SCORE:
        return "score_out_of_range"
    return None


def build_message(shaped: dict) -> str:
    name = shaped["nama_umkm"]
    name = "-" if name in (None, "") else str(name)
    return (
        "**PRIORITAS INTERVENSI**\n\n"
        f"Nama UMKM: {name}\n"
        "Jenis Evaluasi: Enam Faktor\n"
        f"Total Skor: {shaped['total_skor']}/{MAX_SCORE}\n"
        f"Waktu: {shaped['waktu_format']}\n\n"
        "Tindakan: Tinjau hasil evaluasi Enam Faktor UMKM.\n\n"
        "JoyUMKM — Periodic Monitoring"
    )


def _send(sender, target: str, text: str) -> dict:
    try:
        result = sender(target, text)
    except Exception as exc:  # the seam is data-returning; this is the last resort
        return {"target": target, "ok": False, "detail": f"send failed: {type(exc).__name__}"}
    if not isinstance(result, dict):
        return {"target": target, "ok": False, "detail": "send returned no receipt"}
    receipt = {"target": target, "ok": result.get("ok") is True, "detail": str(result.get("detail") or "")}
    if receipt["ok"]:
        for key, expected_type in (("message_id", int), ("chat_id", int), ("chat_type", str),
                                   ("bot_username", str), ("telegram_date", int)):
            if type(result.get(key)) is expected_type:
                receipt[key] = result[key]
    return receipt


def _already_sent(ledger: dict, key: str) -> bool:
    entry = ledger.get(key)
    return isinstance(entry, dict) and entry.get("status") == "sent"


def _handle_record(item, context: dict) -> dict:
    shaped = _shape(item, context["run_time"])
    reason = reject_reason(item, shaped)
    shaped.update(valid=reason is None, reject_reason=reason, action="rejected", delivery=None)
    if reason is not None:
        return shaped
    total = shaped["total_skor"]
    shaped["status_message"] = "Prioritas Intervensi" if total < PRIORITY_THRESHOLD else "Monitoring Normal"
    if total >= PRIORITY_THRESHOLD:
        shaped["action"] = "logged"
        return shaped
    return _deliver_record(shaped, context)


def _deliver_record(shaped: dict, context: dict, *, format_message=build_message) -> dict:
    key = f"{shaped['evaluation_id']}:prioritas-intervensi"
    shaped["notification_key"] = key
    if _already_sent(context["ledger"], key):
        shaped["action"] = "skipped_duplicate"
        return shaped
    outcome = _send(context["sender"], context["target"], format_message(shaped))
    shaped["delivery"] = outcome
    if outcome["ok"] is not True:
        shaped["action"] = "failed"
        return shaped
    shaped["action"] = "sent"
    context["ledger"][key] = {"status": "sent", "sent_at": now(), "run_id": context["run_id"]}
    if "message_id" in outcome:
        context["ledger"][key]["message_id"] = outcome["message_id"]
    try:
        write_ledger(context["ledger_path"], context["ledger"])
    except OSError as exc:
        # The alert did go out; only the dedupe record is missing, so say that plainly.
        outcome["detail"] = f"{outcome['detail']}; ledger write failed: {type(exc).__name__}"
    return shaped


def _summary(records: list) -> dict:
    counts = {"processed": len(records), "rejected": 0, "logged": 0, "sent": 0,
              "skipped_duplicate": 0, "failed": 0}
    for record in records:
        counts[record["action"]] += 1
    return counts


def _failed_output(status: str, reason: str) -> dict:
    return {"fetch": {"status": status, "reason": reason}, "records": [], "summary": _summary([])}


def run_step(step: dict, state: dict, send=None, transport=None, snapshot_env=None) -> dict:
    """Run one ``umkm-monitoring`` transform step, mutating *state* in place.

    Raises StepError (with ``kind`` set to the reason code) when the fetch or the ledger fails,
    which fails the run before any record is evaluated. Per-record send failures are data: they
    are recorded on the record and the run continues.
    """
    sid = step["id"]
    target = str(step.get("telegram_target") or DEFAULT_TARGET).strip() or DEFAULT_TARGET
    records = state.setdefault("node_records", {})
    record = {"kind": "transform", "status": "ok", "started_at": now(),
              "input": {"preset": PRESET, "telegram_target": target}, "output": None, "error": None}
    records[sid] = record
    started = time.perf_counter()
    try:
        ledger_path = os.environ.get("MONITORING_LEDGER_PATH") or DEFAULT_LEDGER_PATH
        try:
            ledger = read_ledger(ledger_path)
        except MonitoringError as exc:
            state["outputs"][sid] = _failed_output("not_attempted", exc.code)
            raise _fail(record, exc.code) from None
        try:
            config = load_config(snapshot_env)
            data = fetch_latest_finals(config, transport)
        except MonitoringError as exc:
            state["outputs"][sid] = _failed_output("failed", exc.code)
            raise _fail(record, exc.code) from None
        context = {
            "run_time": datetime.now(timezone.utc), "ledger": ledger, "ledger_path": ledger_path,
            "sender": send or send_text, "target": target, "run_id": str(state.get("run_id", "")),
        }
        results = [_handle_record(item, context) for item in data]
        output = {"fetch": {"status": "ok", "count": len(data)}, "records": results,
                  "summary": _summary(results)}
        state["outputs"][sid] = output
        record["output"] = {"summary": output["summary"]}
        return output
    except StepError:
        raise
    except Exception:
        record.update(status="failed", error="node_execution_failed")
        raise
    finally:
        record.update(finished_at=now(), elapsed_ms=round((time.perf_counter() - started) * 1000, 3))


@contextmanager
def _lock_ledger(path: str):
    """Serialize the read, send and write sequence across cron and interactive processes."""
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    with open(path + ".lock", "ab") as handle:
        if os.name == "nt":
            import msvcrt

            handle.seek(0)
            msvcrt.locking(handle.fileno(), msvcrt.LK_LOCK, 1)
        else:
            import fcntl

            fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        yield


def _send_priority(records, run_id, sender, ledger_path):
    if not records:
        return []
    from .monitoring_telegram import build_telegram_message, send_telegram

    if sender is None:
        sender = send_telegram
    path = os.fspath(ledger_path or os.environ.get("MONITORING_LEDGER_PATH") or DEFAULT_LEDGER_PATH)
    try:
        with _lock_ledger(path):
            context = {
                "ledger": read_ledger(path), "ledger_path": path, "run_id": run_id,
                "target": DEFAULT_TARGET, "sender": lambda _target, text: sender(text),
            }
            return [_deliver_record(dict(row), context, format_message=build_telegram_message)
                    for row in records]
    except OSError:
        raise MonitoringError("ledger_unavailable") from None


def _graph_output(step, state, source, transport, sender, ledger_path):
    stage = step["monitoring_stage"]
    if stage == "schedule":
        trigger = state.get("trigger") or {}
        return {"type": trigger.get("type", "manual"), "schedule": step["schedule"],
                "timezone": step["timezone"], **{key: trigger[key] for key in
                ("job_id", "execution_id", "scheduled_for", "execution_mode") if key in trigger}}
    if stage == "http":
        records = fetch_latest_finals(load_config(), transport)
        return {"status": "ok", "count": len(records), "records": records}
    if stage == "edit":
        run_time = datetime.now(timezone.utc)
        records = []
        for item in source["records"]:
            if not isinstance(item, dict):
                records.append(None)
                continue
            shaped = _shape(item, run_time)
            score = shaped["total_skor"]
            if _is_int(score) and MIN_SCORE <= score <= MAX_SCORE:
                shaped["status_message"] = "Prioritas Intervensi" if score < PRIORITY_THRESHOLD else "Monitoring Normal"
            records.append(shaped)
        return {"records": records}
    if stage == "filter":
        accepted, rejected = [], []
        for shaped in source["records"]:
            reason = reject_reason(shaped, shaped)
            if reason:
                shaped = shaped or _shape(None, datetime.now(timezone.utc))
                rejected.append(dict(shaped, valid=False, reject_reason=reason, action="rejected", delivery=None))
            else:
                accepted.append(dict(shaped, valid=True, reject_reason=None, delivery=None))
        return {"records": accepted, "rejected": rejected}
    if stage == "if":
        parent = step["after"][0]
        true, false = [], []
        for row in source["records"]:
            if evaluate_condition(step["expression"], {parent: row}, (parent,)):
                true.append(row)
            else:
                false.append(row)
        return {"true": true, "false": false, "rejected": source["rejected"]}
    if stage == "telegram":
        records = _send_priority(source["true"], state["run_id"], sender, ledger_path)
        return {"records": records, "summary": _summary(records)}
    if stage == "log":
        records = [dict(row, action="logged") for row in source["false"]] + source["rejected"]
        return {"records": records, "summary": _summary(records)}
    raise MonitoringError("unknown_monitoring_stage")


def run_graph_step(step, state, *, transport=None, sender=None, ledger_path=None):
    """Execute one visual monitoring node and persist its own input/output and timing."""
    sid = step["id"]
    parents = step["after"]
    record = {"kind": step["kind"], "stage": step["monitoring_stage"], "status": "ok",
              "started_at": now(), "input": {"after": parents}, "output": None, "error": None}
    state.setdefault("node_records", {})[sid] = record
    started = time.perf_counter()
    try:
        source = state["outputs"][parents[0]] if parents else None
        output = _graph_output(step, state, source, transport, sender, ledger_path)
        state["outputs"][sid] = output
        record["output"] = output
        if step["monitoring_stage"] == "telegram":
            for row in output["records"]:
                if row["delivery"] is not None:
                    state["deliveries"].append({"node_id": sid, **row["delivery"]})
            if not output["records"]:
                record["status"] = "skipped"
            elif output["summary"]["failed"]:
                record.update(status="failed", error="delivery_failed")
    except MonitoringError as exc:
        raise _fail(record, exc.code) from None
    except Exception:
        raise _fail(record, "node_execution_failed") from None
    finally:
        record.update(finished_at=now(), elapsed_ms=round((time.perf_counter() - started) * 1000, 3))


def _fail(record: dict, code: str) -> StepError:
    record.update(status="failed", error=code)
    error = StepError(f"monitoring step failed: {code}")
    error.kind = code
    return error
