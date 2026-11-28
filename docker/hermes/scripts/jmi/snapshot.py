"""Historical snapshot (P3-01): authenticated pull, strict validation, normalized snapshot, SHA-256 manifest.

Runs only on an explicit ``python -m jmi snapshot <event_id>``. It is never triggered by intake, the model
worker, recovery sweeps or the callback. It never calls the model and never touches job.json or report.json.

Commit protocol (per event directory ``jobs/<event_id>/``):
  1. ``snapshot.json`` is written atomically (temp file + fsync + rename + directory fsync).
  2. ``snapshot_manifest.json`` is written atomically AFTER it. The manifest is the commit marker.
A snapshot without a valid manifest is a partial write and is set aside. A manifest whose sha256 does not
match ``snapshot.json`` is a checksum failure: both files are set aside (kept for forensics) and rebuilt
from the authenticated source. A committed snapshot is never overwritten.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import math
import os
import re
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import urlsplit

from . import callback, parse, store

SCHEMA_VERSION = 1
HISTORY_MONTHS = 12
PERIOD_TIMEZONE = "Asia/Jakarta"
PERIOD_TZ = timezone(timedelta(hours=7))  # WIB (UTC+07:00, tanpa DST)
MAX_PULL_BYTES = 256 * 1024
MIN_SECRET_LENGTH = 32
TIMEOUT_SECONDS = 10
ENV_FILE = "snapshot.env"
SNAPSHOT_FILE = "snapshot.json"
MANIFEST_FILE = "snapshot_manifest.json"
FACTORS = parse.FACTORS
DIMS = parse.DIMS
_UTC_Z = re.compile(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ")
_EMAIL = re.compile(r"[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}")
_SHA256 = re.compile(r"[0-9a-f]{64}")
_TOP_KEYS = {"schema_version", "event_id", "event_type", "event_version", "umkm_id", "evaluation_id", "cutoff",
             "period_timezone", "period_month", "previous_period_month", "history_months", "current", "previous",
             "history", "availability", "source"}
_CUTOFF_KEYS = {"at", "evaluation_id"}
_ROW_KEYS = {"period_month", "six_factor", "three_dimension"}
_SIX_KEYS = {"status", "evaluation_id", "total_score", "category", "finalized_at", "scores"}
_THREE_KEYS = {"status", "evaluation_id", "average_score", "category", "is_complete", "assessed_at", "scores"}
_AVAIL_KEYS = {"six_factor_final_months", "three_dimension_months", "current_six_factor", "previous_six_factor",
               "current_three_dimension", "previous_three_dimension"}
_SOURCE_KEYS = {"six_factor_evaluation_ids", "three_dimension_evaluation_ids"}
_CURRENT_KEYS = {"six_factor", "three_dimension"}

# Fixed, classified codes only. Raw provider or response text is never persisted or logged.
STATUS_CODES = {401: "SNAPSHOT_AUTH_REJECTED", 404: "EVENT_NOT_FOUND", 409: "EVENT_EVALUATION_MISMATCH",
                422: "UNSUPPORTED_EVENT_VERSION", 503: "SNAPSHOT_UNAVAILABLE"}
RETRYABLE_STATUS = {408, 429, 503}


class SnapshotError(Exception):
    def __init__(self, code: str, retryable: bool = False, detail: str = ""):
        super().__init__(f"{code}: {detail}")
        self.code, self.retryable, self.detail = code, retryable, detail


# ----------------------------------------------------------------------------------------------- time and period

def wib_period(instant: datetime) -> str:
    """YYYY-MM of an aware UTC instant in Asia/Jakarta."""
    return instant.astimezone(PERIOD_TZ).strftime("%Y-%m")


def shift_month(period: str, months: int) -> str:
    year, month = int(period[:4]), int(period[5:7])
    index = year * 12 + (month - 1) + months
    return f"{index // 12:04d}-{index % 12 + 1:02d}"


def _utc_z(value, code: str = "PULL_SCHEMA_INVALID") -> datetime:
    if not isinstance(value, str) or not _UTC_Z.fullmatch(value):
        raise SnapshotError(code, detail="timestamp must be UTC ISO8601 with Z")
    try:
        return datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    except ValueError:
        raise SnapshotError(code, detail="invalid timestamp")


def _format_z(instant: datetime) -> str:
    return instant.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _event_instant(evidence: dict) -> datetime:
    return datetime.fromisoformat(evidence["occurred_at"].replace("Z", "+00:00")).astimezone(timezone.utc)


# --------------------------------------------------------------------------------------------------- transport

class HttpTransport:
    """Signed read-only GET to Laravel: ``{base}/{event_id}/snapshot`` (HMAC over timestamp, method and path).
    The socket work lives in ``callback.http_get`` (the only module allowed to speak HTTP, see test_int12)."""

    def __init__(self, base_url: str, secret: bytes, timeout: int = TIMEOUT_SECONDS, clock=time.time, http_host: str = ""):
        self.base_url = base_url.rstrip("/")
        self.secret = secret
        self.timeout = timeout
        self.clock = clock
        self.http_host = http_host

    def fetch(self, event_id: str) -> bytes:
        url = f"{self.base_url}/{event_id}/snapshot"
        timestamp = str(int(self.clock()))
        signature = hmac.new(self.secret, f"{timestamp}\nGET\n{urlsplit(url).path}".encode(), hashlib.sha256).hexdigest()
        headers = callback.with_host({
            "Accept": "application/json",
            "User-Agent": "jmi-snapshot/1",
            "X-Intelligence-Snapshot-Timestamp": timestamp,
            "X-Intelligence-Snapshot-Signature": signature,
        }, self.http_host)
        try:
            status, body = callback.http_get(url, headers, self.timeout, MAX_PULL_BYTES)
        except callback.TransportError:
            raise SnapshotError("TRANSPORT_FAILED", retryable=True, detail="koneksi gagal")
        if status == 200:
            if len(body) > MAX_PULL_BYTES:
                raise SnapshotError("PULL_TOO_LARGE", detail="respons melebihi batas")
            return body
        code = STATUS_CODES.get(status, f"HTTP_{status}")
        raise SnapshotError(code, retryable=status in RETRYABLE_STATUS or status >= 500, detail="respons HTTP")


# ------------------------------------------------------------------------------------------------------ config

def _read_env_file() -> tuple[dict, list]:
    path = store.home() / ENV_FILE
    if not path.exists():
        return {}, []
    if os.stat(path).st_mode & 0o077:
        return {}, ["SNAPSHOT_ENV_FILE_PERMISSIONS"]
    out = {}
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        out[key.strip()] = value.strip().strip("'\"")
    return out, []


def load_config(environ=None) -> tuple:
    """Return ``(Config | None, problems)``. Problems are fixed codes. Fails closed when unset or reused."""
    environ = os.environ if environ is None else environ
    file_vals, problems = _read_env_file() if environ is os.environ else ({}, [])

    def get(name):
        value = environ.get(name)
        return value if value not in (None, "") else file_vals.get(name)

    url = (get("INTELLIGENCE_SNAPSHOT_BASE_URL") or "").strip()
    if not url:
        problems.append("SNAPSHOT_URL_MISSING")
    else:
        try:
            parts = urlsplit(url)
            parts.port  # noqa: B018 - validates the port
            ok = (parts.scheme in ("http", "https") and bool(parts.hostname) and not parts.username
                  and not parts.password and not parts.query and not parts.fragment and parts.path not in ("", "/"))
        except ValueError:
            ok = False
        if not ok:
            problems.append("SNAPSHOT_URL_INVALID")
    secret = get("INTELLIGENCE_SNAPSHOT_SECRET") or ""
    callback_secret = get_callback_secret(environ)
    others = [s for s in [callback_secret, *callback._webhook_secrets(environ)] if s]
    if not secret:
        problems.append("SNAPSHOT_SECRET_MISSING")
    elif len(secret) < MIN_SECRET_LENGTH:
        problems.append("SNAPSHOT_SECRET_TOO_SHORT")
    elif any(hmac.compare_digest(secret.encode(), other.encode()) for other in others):
        problems.append("SNAPSHOT_SECRET_REUSED")
    host = (get("INTELLIGENCE_SNAPSHOT_HTTP_HOST") or "").strip()
    if host and not callback.valid_http_host(host):
        problems.append("SNAPSHOT_HTTP_HOST_INVALID")
    if problems:
        return None, problems
    return {"url": url, "secret": secret.encode(), "http_host": host}, []


def get_callback_secret(environ) -> str:
    value = environ.get("INTELLIGENCE_CALLBACK_SECRET") or ""
    if not value and environ is os.environ:
        value = callback._read_env_file()[0].get("INTELLIGENCE_CALLBACK_SECRET", "")
    return value


# -------------------------------------------------------------------------------------------- strict validation

def _exact(obj, keys: set, code: str) -> None:
    if not isinstance(obj, dict) or set(obj) != keys:
        raise SnapshotError(code, detail="unexpected or missing fields")


def _is_int(value) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def _is_num(value) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value)


def _text_or_none(value, limit: int = 40):
    if value is None:
        return None
    if not isinstance(value, str) or len(value) > limit:
        raise SnapshotError("PULL_SCHEMA_INVALID", detail="text field invalid")
    return value


def _walk_strings(node):
    if isinstance(node, dict):
        for value in node.values():
            yield from _walk_strings(value)
    elif isinstance(node, list):
        for value in node:
            yield from _walk_strings(value)
    elif isinstance(node, str):
        yield node


def _no_contact_data(pull) -> None:
    for text in _walk_strings(pull):
        if _EMAIL.search(text):
            raise SnapshotError("PII_DETECTED", detail="pull payload contains contact data")


def _validate_six(obj, period: str, cutoff: datetime) -> dict:
    if not isinstance(obj, dict) or obj.get("status") not in ("available", "data_gap"):
        raise SnapshotError("SIX_FACTOR_INVALID", detail="status must be available or data_gap")
    _exact(obj, _SIX_KEYS, "SIX_FACTOR_INVALID")
    if obj["status"] == "data_gap":
        if any(obj[key] is not None for key in _SIX_KEYS - {"status"}):
            raise SnapshotError("DATA_GAP_NOT_NULL", detail="gap must not carry values")
        return {key: None for key in _SIX_KEYS} | {"status": "data_gap"}
    evaluation_id = obj["evaluation_id"]
    if not _is_int(evaluation_id) or evaluation_id < 1:
        raise SnapshotError("SIX_FACTOR_INVALID", detail="evaluation_id invalid")
    finalized = _utc_z(obj["finalized_at"])
    if finalized > cutoff:
        raise SnapshotError("AFTER_CUTOFF", detail="six-factor final after event cutoff")
    if wib_period(finalized) != period:
        raise SnapshotError("PERIOD_MISMATCH", detail="finalized_at is outside the row period (WIB)")
    scores_in = obj["scores"]
    _exact(scores_in, set(FACTORS), "SIX_FACTOR_INVALID")
    for factor in FACTORS:
        value = scores_in[factor]
        if not _is_int(value) or not 1 <= value <= 5:
            raise SnapshotError("SCORE_OUT_OF_RANGE", detail="six-factor score must be integer 1-5")
    total = obj["total_score"]
    if not _is_int(total) or not 6 <= total <= 30:
        raise SnapshotError("SCORE_OUT_OF_RANGE", detail="total must be integer 6-30")
    if total != sum(scores_in.values()):
        raise SnapshotError("SCORE_TOTAL_MISMATCH", detail="total differs from sum of factors")
    return {
        "status": "available", "evaluation_id": evaluation_id, "total_score": total,
        "category": _text_or_none(obj["category"]), "finalized_at": _format_z(finalized),
        "scores": {factor: scores_in[factor] for factor in FACTORS},
    }


def _validate_three(obj, period: str, cutoff: datetime) -> dict:
    if not isinstance(obj, dict) or obj.get("status") not in ("available", "data_gap"):
        raise SnapshotError("THREE_DIMENSION_INVALID", detail="status must be available or data_gap")
    _exact(obj, _THREE_KEYS, "THREE_DIMENSION_INVALID")
    if obj["status"] == "data_gap":
        if any(obj[key] is not None for key in _THREE_KEYS - {"status", "is_complete"}) or obj["is_complete"] is not False:
            raise SnapshotError("DATA_GAP_NOT_NULL", detail="gap must not carry values")
        return {key: None for key in _THREE_KEYS} | {"status": "data_gap", "is_complete": False}
    evaluation_id = obj["evaluation_id"]
    if not _is_int(evaluation_id) or evaluation_id < 1:
        raise SnapshotError("THREE_DIMENSION_INVALID", detail="evaluation_id invalid")
    assessed = _utc_z(obj["assessed_at"])
    if assessed > cutoff:
        raise SnapshotError("AFTER_CUTOFF", detail="three-dimension assessment after event cutoff")
    if wib_period(assessed) != period:
        raise SnapshotError("PERIOD_MISMATCH", detail="assessed_at is outside the row period (WIB)")
    average = obj["average_score"]
    if average is not None and (not _is_num(average) or not 0 <= average <= 100):
        raise SnapshotError("SCORE_OUT_OF_RANGE", detail="average must be 0-100 or null")
    _exact(obj["scores"], set(DIMS), "THREE_DIMENSION_INVALID")
    for dim in DIMS:
        value = obj["scores"][dim]
        if value is not None and (not _is_num(value) or not 0 <= value <= 100):
            raise SnapshotError("SCORE_OUT_OF_RANGE", detail="dimension score must be 0-100 or null")
    complete = all(obj["scores"][dim] is not None for dim in DIMS)
    if obj["is_complete"] is not complete:
        raise SnapshotError("COMPLETENESS_MISMATCH", detail="is_complete disagrees with scores")
    return {
        "status": "available", "evaluation_id": evaluation_id, "average_score": average,
        "category": _text_or_none(obj["category"]), "is_complete": complete,
        "assessed_at": _format_z(assessed), "scores": {dim: obj["scores"][dim] for dim in DIMS},
    }


def _availability(rows: list) -> dict:
    return {
        "six_factor_final_months": sum(row["six_factor"]["status"] == "available" for row in rows),
        "three_dimension_months": sum(row["three_dimension"]["status"] == "available" for row in rows),
        "current_six_factor": rows[0]["six_factor"]["status"],
        "previous_six_factor": rows[1]["six_factor"]["status"],
        "current_three_dimension": rows[0]["three_dimension"]["status"],
        "previous_three_dimension": rows[1]["three_dimension"]["status"],
    }


def validate_pull(pull, evidence: dict, raw_payload: dict) -> dict:
    """Strictly validate one authenticated pull against the admitted event. Returns the verified rows."""
    if not isinstance(pull, dict):
        raise SnapshotError("PULL_SCHEMA_INVALID", detail="pull must be an object")
    _no_contact_data(pull)
    _exact(pull, _TOP_KEYS, "PULL_SCHEMA_INVALID")
    if pull["schema_version"] != SCHEMA_VERSION or pull["event_version"] != 2 \
            or pull["event_type"] != "evaluation.finalized":
        raise SnapshotError("PULL_SCHEMA_INVALID", detail="unsupported pull schema or event")
    if pull["event_id"] != evidence["event_id"]:
        raise SnapshotError("EVENT_MISMATCH", detail="event_id differs from admitted event")
    if pull["umkm_id"] != evidence["umkm_id"]:
        raise SnapshotError("UMKM_MISMATCH", detail="umkm_id differs from admitted event")
    anchor_id = evidence["six_factor"]["evaluation_id"]
    if pull["evaluation_id"] != anchor_id:
        raise SnapshotError("EVALUATION_MISMATCH", detail="evaluation_id differs from admitted event")

    _exact(pull["cutoff"], _CUTOFF_KEYS, "CUTOFF_MISMATCH")
    cutoff = _utc_z(pull["cutoff"]["at"], "CUTOFF_MISMATCH")
    if cutoff != _event_instant(evidence) or pull["cutoff"]["evaluation_id"] != anchor_id:
        raise SnapshotError("CUTOFF_MISMATCH", detail="cutoff differs from the event finalization instant")
    if pull["period_timezone"] != PERIOD_TIMEZONE:
        raise SnapshotError("PERIOD_MISMATCH", detail="period timezone must be Asia/Jakarta")
    period = wib_period(cutoff)
    previous = shift_month(period, -1)
    if pull["period_month"] != period or pull["previous_period_month"] != previous:
        raise SnapshotError("PERIOD_MISMATCH", detail="current or previous period differs from WIB cutoff")
    raw_period = (raw_payload.get("data") or {}).get("six_factor", {}).get("period_month")
    if raw_period is not None and raw_period != period:
        raise SnapshotError("PERIOD_MISMATCH", detail="event period_month differs from WIB cutoff")
    if pull["history_months"] != HISTORY_MONTHS or not isinstance(pull["history"], list) \
            or len(pull["history"]) != HISTORY_MONTHS:
        raise SnapshotError("HISTORY_INVALID", detail="history must contain 12 months")

    rows = []
    for index, row in enumerate(pull["history"]):
        _exact(row, _ROW_KEYS, "HISTORY_INVALID")
        expected_period = shift_month(period, -index)
        if row["period_month"] != expected_period:
            raise SnapshotError("HISTORY_INVALID", detail="history must be consecutive, newest first")
        rows.append({
            "period_month": expected_period,
            "six_factor": _validate_six(row["six_factor"], expected_period, cutoff),
            "three_dimension": _validate_three(row["three_dimension"], expected_period, cutoff),
        })

    for key, index in (("current", 0), ("previous", 1)):
        _exact(pull[key], _CURRENT_KEYS, "HISTORY_INVALID")
        if pull[key] != {"six_factor": rows[index]["six_factor"], "three_dimension": rows[index]["three_dimension"]}:
            raise SnapshotError("HISTORY_INVALID", detail=f"{key} differs from history row")

    current_six = rows[0]["six_factor"]
    if current_six["status"] != "available" or current_six["evaluation_id"] != anchor_id \
            or current_six["scores"] != evidence["six_factor"]["scores"] \
            or current_six["total_score"] != evidence["six_factor"]["total_score"]:
        raise SnapshotError("EVENT_PULL_MISMATCH", detail="current six-factor differs from the admitted event")

    availability = _availability(rows)
    _exact(pull["availability"], _AVAIL_KEYS, "AVAILABILITY_MISMATCH")
    if pull["availability"] != availability:
        raise SnapshotError("AVAILABILITY_MISMATCH", detail="availability does not match history")

    _exact(pull["source"], _SOURCE_KEYS, "SOURCE_MISMATCH")
    six_ids = sorted({r["six_factor"]["evaluation_id"] for r in rows if r["six_factor"]["status"] == "available"})
    three_ids = sorted({r["three_dimension"]["evaluation_id"] for r in rows if r["three_dimension"]["status"] == "available"})
    if pull["source"] != {"six_factor_evaluation_ids": six_ids, "three_dimension_evaluation_ids": three_ids}:
        raise SnapshotError("SOURCE_MISMATCH", detail="source identifiers do not match history rows")
    return {"cutoff": cutoff, "period_month": period, "previous_period_month": previous, "rows": rows,
            "availability": availability}


# ------------------------------------------------------------------------------------------ document and manifest

def build_document(evidence: dict, verified: dict, captured_at: str) -> dict:
    rows = verified["rows"]
    gaps = []
    for row in rows:
        if row["six_factor"]["status"] == "data_gap":
            gaps.append({"scope": f"history.{row['period_month']}.six_factor", "reason": "no_final_in_period"})
        if row["three_dimension"]["status"] == "data_gap":
            gaps.append({"scope": f"history.{row['period_month']}.three_dimension", "reason": "no_assessment_in_period"})
    business = evidence.get("business_context")
    profile_gaps = sorted(w.split(":", 1)[1] for w in evidence["data_quality_warnings"]
                          if w.startswith("business_context_missing:"))
    gaps += [{"scope": f"profile.{field}", "reason": "not_provided"} for field in profile_gaps]
    if business is None:
        gaps.append({"scope": "profile", "reason": "business_context_unavailable"})
    event_three = evidence.get("three_dimension")
    if event_three is None:
        gaps.append({"scope": "event.three_dimension", "reason": "unavailable_in_event"})

    six_sources, three_sources = [], []
    for row in rows:
        if row["six_factor"]["status"] == "available":
            six_sources.append({"evaluation_id": row["six_factor"]["evaluation_id"], "period_month": row["period_month"],
                                "finalized_at": row["six_factor"]["finalized_at"]})
        if row["three_dimension"]["status"] == "available":
            three_sources.append({"evaluation_id": row["three_dimension"]["evaluation_id"],
                                  "period_month": row["period_month"], "assessed_at": row["three_dimension"]["assessed_at"]})

    return {
        "schema_version": SCHEMA_VERSION,
        "event": {
            "event_id": evidence["event_id"],
            "event_type": "evaluation.finalized",
            "event_version": evidence["source_event_version"],
            "occurred_at": evidence["occurred_at"],
        },
        "umkm_id": evidence["umkm_id"],
        "evaluation_id": evidence["six_factor"]["evaluation_id"],
        "period_timezone": PERIOD_TIMEZONE,
        "period_month": verified["period_month"],
        "previous_period_month": verified["previous_period_month"],
        "history_months": HISTORY_MONTHS,
        "cutoff": {"at": _format_z(verified["cutoff"]), "evaluation_id": evidence["six_factor"]["evaluation_id"]},
        "captured_at": captured_at,
        "profile": {
            "source": "event_payload.business_context",
            "as_of": "event_occurred_at",
            "historical_as_of_supported": False,
            "business_context": business,
            "data_gaps": profile_gaps,
        },
        "event_snapshot": {"three_dimension": event_three},
        "current": {"six_factor": rows[0]["six_factor"], "three_dimension": rows[0]["three_dimension"]},
        "previous": {"six_factor": rows[1]["six_factor"], "three_dimension": rows[1]["three_dimension"]},
        "history": rows,
        "data_availability": verified["availability"] | {
            "profile_business_context": business is not None,
            "event_three_dimension": event_three is not None,
        },
        "data_gaps": gaps,
        "source": {
            "pull_schema_version": SCHEMA_VERSION,
            "six_factor_evaluations": six_sources,
            "three_dimension_evaluations": three_sources,
        },
    }


def canonical_bytes(document: dict) -> bytes:
    return (json.dumps(document, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n").encode()


def _manifest_for_completed(document: dict, data: bytes, payload_hash: str) -> dict:
    return {
        "schema_version": SCHEMA_VERSION,
        "snapshot_schema_version": SCHEMA_VERSION,
        "snapshot_status": "completed",
        "event_id": document["event"]["event_id"],
        "event_version": document["event"]["event_version"],
        "evaluation_id": document["evaluation_id"],
        "umkm_id": document["umkm_id"],
        "period_month": document["period_month"],
        "previous_period_month": document["previous_period_month"],
        "cutoff_at": document["cutoff"]["at"],
        "captured_at": document["captured_at"],
        "source_identifiers": {
            "cutoff_evaluation_id": document["cutoff"]["evaluation_id"],
            "six_factor_evaluation_ids": sorted(s["evaluation_id"] for s in document["source"]["six_factor_evaluations"]),
            "three_dimension_evaluation_ids": sorted(s["evaluation_id"] for s in document["source"]["three_dimension_evaluations"]),
        },
        "data_availability": document["data_availability"],
        "data_gap_count": len(document["data_gaps"]),
        "snapshot_file": SNAPSHOT_FILE,
        "sha256": hashlib.sha256(data).hexdigest(),
        "size_bytes": len(data),
        "payload_hash": payload_hash,
    }


def _manifest_for_unsupported(evidence: dict, payload_hash: str, captured_at: str) -> dict:
    return {
        "schema_version": SCHEMA_VERSION,
        "snapshot_schema_version": SCHEMA_VERSION,
        "snapshot_status": "unsupported",
        "reason": "LEGACY_EVENT_VERSION",
        "event_id": evidence["event_id"],
        "event_version": evidence["source_event_version"],
        "evaluation_id": evidence["six_factor"]["evaluation_id"],
        "umkm_id": evidence["umkm_id"],
        "captured_at": captured_at,
        "snapshot_file": None,
        "sha256": None,
        "size_bytes": 0,
        "payload_hash": payload_hash,
    }


# ------------------------------------------------------------------------------------------- persistence / state

def _set_aside(directory: Path, name: str) -> bool:
    source = directory / name
    if not source.exists():
        return False
    stem = Path(name).stem
    os.replace(source, directory / f"{stem}.invalid-{time.time_ns()}.json")
    store.fsync_dir(directory)
    return True


def _remove_stale_temp(directory: Path) -> None:
    for temp in list(directory.glob(".snapshot*.tmp")):
        temp.unlink(missing_ok=True)


def _manifest_ok(manifest, eid: str) -> bool:
    if not isinstance(manifest, dict) or manifest.get("event_id") != eid or manifest.get("schema_version") != SCHEMA_VERSION:
        return False
    if manifest.get("snapshot_status") == "unsupported":
        return manifest.get("snapshot_file") is None and manifest.get("sha256") is None
    return (manifest.get("snapshot_status") == "completed" and manifest.get("snapshot_file") == SNAPSHOT_FILE
            and isinstance(manifest.get("sha256"), str) and _SHA256.fullmatch(manifest["sha256"]) is not None
            and isinstance(manifest.get("size_bytes"), int) and not isinstance(manifest.get("size_bytes"), bool))


def committed_state(eid: str, *, repair: bool) -> tuple[str, dict | None, str | None]:
    """Return ``("valid", manifest, None)`` or ``("missing", None, reason)``. ``repair`` sets damaged files aside."""
    directory = store.job_dir(eid)
    manifest_path, snapshot_path = directory / MANIFEST_FILE, directory / SNAPSHOT_FILE
    if not manifest_path.exists():
        if snapshot_path.exists():
            if repair:
                _set_aside(directory, SNAPSHOT_FILE)
            return "missing", None, "partial_write"
        return "missing", None, "absent"
    try:
        manifest = json.loads(manifest_path.read_text())
    except (ValueError, OSError):
        manifest = None
    if not _manifest_ok(manifest, eid):
        if repair:
            _set_aside(directory, MANIFEST_FILE)
            _set_aside(directory, SNAPSHOT_FILE)
        return "missing", None, "manifest_invalid"
    if manifest["snapshot_status"] == "unsupported":
        return "valid", manifest, None
    try:
        data = snapshot_path.read_bytes()
    except OSError:
        data = None
    if data is None or len(data) != manifest["size_bytes"] or hashlib.sha256(data).hexdigest() != manifest["sha256"]:
        if repair:
            _set_aside(directory, MANIFEST_FILE)
            _set_aside(directory, SNAPSHOT_FILE)
        return "missing", None, "checksum_mismatch" if data is not None else "snapshot_file_missing"
    return "valid", manifest, None


def verify(eid: str) -> dict:
    """Read-only integrity check of a committed snapshot. Performs no network call and changes no file."""
    store.check_id(eid)
    if store.read_job(eid) is None:
        return {"event_id": eid, "result": "not_found"}
    state, manifest, reason = committed_state(eid, repair=False)
    if state == "valid":
        return {"event_id": eid, "result": "valid", "snapshot_status": manifest["snapshot_status"]}
    return {"event_id": eid, "result": "invalid", "reason": reason}


def _commit(directory: Path, document: dict, data: bytes, manifest: dict) -> None:
    store.atomic_write(directory / SNAPSHOT_FILE, data)  # 1. payload first
    store.atomic_write(directory / MANIFEST_FILE, manifest)  # 2. manifest last: the commit marker


def run(eid: str, *, transport=None, config_loader=load_config, clock=time.time) -> dict:
    """Create the historical snapshot for one admitted event. Idempotent; never calls the model."""
    if not store.uat_allows(store.read_job(eid)):
        return {"event_id": eid, "result": "blocked", "error_code": "UAT_SCOPE_DENIED"}
    store.check_id(eid)
    with store.flock(f"{eid}.snap"):  # blocking: a concurrent run waits, then reuses the committed manifest
        job = store.read_job(eid)
        if job is None:
            return {"event_id": eid, "result": "not_found"}
        directory = store.job_dir(eid)
        _remove_stale_temp(directory)
        state, manifest, reason = committed_state(eid, repair=True)
        if state == "valid":
            return {"event_id": eid, "result": "exists", "snapshot_status": manifest["snapshot_status"],
                    "sha256": manifest["sha256"]}
        repaired = reason is not None
        evidence = json.loads((directory / "evidence.json").read_text())
        captured_at = store.now()
        if evidence["source_event_version"] != 2:
            manifest = _manifest_for_unsupported(evidence, job["payload_hash"], captured_at)
            store.atomic_write(directory / MANIFEST_FILE, manifest)
            return {"event_id": eid, "result": "unsupported", "snapshot_status": "unsupported", "reason": manifest["reason"]}

        if transport is None:
            config, problems = config_loader()
            if config is None:
                return {"event_id": eid, "result": "failed", "error_code": "SNAPSHOT_CONFIG_MISSING",
                        "problems": problems, "retryable": True}
            transport = HttpTransport(config["url"], config["secret"], clock=clock, http_host=config.get("http_host", ""))
        try:
            body = transport.fetch(eid)
            try:
                pull = parse.strict_loads(body)
            except parse.PayloadError:
                raise SnapshotError("PULL_INVALID_JSON", detail="pull body is not strict JSON")
            raw_payload = json.loads((directory / "payload.raw.json").read_text())
            verified = validate_pull(pull, evidence, raw_payload)
            document = build_document(evidence, verified, captured_at)
            data = canonical_bytes(document)
            manifest = _manifest_for_completed(document, data, job["payload_hash"])
            _commit(directory, document, data, manifest)
        except SnapshotError as error:
            return {"event_id": eid, "result": "failed", "error_code": error.code, "retryable": error.retryable}
        return {"event_id": eid, "result": "created", "snapshot_status": "completed", "sha256": manifest["sha256"],
                "period_month": document["period_month"], "repaired": repaired, "data_gap_count": len(document["data_gaps"])}
