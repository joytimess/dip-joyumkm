"""Strict parsing + normalization of evaluation.finalized payloads (V1/V2)."""
from __future__ import annotations

import hashlib
import json
import re
import uuid
from datetime import datetime, timezone

MAX_RAW_BYTES = 256 * 1024
FACTORS = ["sumber_daya", "kepemimpinan", "lingkungan_kerja", "nilai_organisasi", "stabilitas", "kinerja"]
DIMS = ["ins", "ect", "ops"]
MAX_STR = 255  # superset of Laravel: name/city max 120, DB varchar <=255


class PayloadError(Exception):
    def __init__(self, code: str, detail: str = ""):
        super().__init__(f"{code}: {detail}")
        self.code, self.detail = code, detail


def _no_dupes(pairs):
    d = {}
    for k, v in pairs:
        if k in d:
            raise PayloadError("DUPLICATE_KEY", "duplicate object key")
        d[k] = v
    return d


def _bad_const(x):
    raise PayloadError("INVALID_JSON", f"non-finite number {x}")


def strict_loads(raw):
    """Parse raw JSON bytes/str; reject oversize, invalid JSON, duplicate keys, NaN/Infinity."""
    if isinstance(raw, str):
        raw = raw.encode("utf-8")
    if len(raw) > MAX_RAW_BYTES:
        raise PayloadError("PAYLOAD_TOO_LARGE", f"{len(raw)} bytes")
    try:
        text = raw.decode("utf-8")
        return json.loads(text, object_pairs_hook=_no_dupes, parse_constant=_bad_const)
    except PayloadError:
        raise
    except (ValueError, UnicodeDecodeError, RecursionError) as e:
        raise PayloadError("INVALID_JSON", "malformed JSON")


def canonical_hash(obj) -> str:
    return hashlib.sha256(json.dumps(obj, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()).hexdigest()


def clean_str(v, field, maxlen=MAX_STR):
    if v is None:
        return None
    if not isinstance(v, str):
        raise PayloadError("INVALID_FIELD", f"{field} must be string or null")
    v = "".join(ch for ch in v if ch == "\n" or ch >= " " and ch != "\x7f").strip()
    if len(v) > maxlen:
        raise PayloadError("INVALID_FIELD", f"{field} longer than {maxlen}")
    return v


def _int(v, field, lo, hi, nullable=True):
    if v is None:
        if nullable:
            return None
        raise PayloadError("INVALID_FIELD", f"{field} required")
    if isinstance(v, bool) or not isinstance(v, int):
        raise PayloadError("INVALID_FIELD", f"{field} must be integer")
    if not lo <= v <= hi:
        raise PayloadError("INVALID_FIELD", f"{field} out of range {lo}..{hi}")
    return v


def _num(v, field, lo, hi):
    if v is None:
        return None
    if isinstance(v, bool) or not isinstance(v, (int, float)):
        raise PayloadError("INVALID_FIELD", f"{field} must be number or null")
    if not lo <= v <= hi:
        raise PayloadError("INVALID_FIELD", f"{field} out of range {lo}..{hi}")
    return v


def _obj(v, field, nullable=False):
    if v is None and nullable:
        return None
    if not isinstance(v, dict):
        raise PayloadError("INVALID_FIELD", f"{field} must be object")
    return v


def _utc(v, field):
    if not isinstance(v, str) or not re.fullmatch(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?(Z|\+00:00)", v):
        raise PayloadError("INVALID_ENVELOPE", f"{field} must be UTC ISO8601")
    try:
        datetime.fromisoformat(v.replace("Z", "+00:00"))
    except ValueError:
        raise PayloadError("INVALID_ENVELOPE", f"{field} invalid date")
    return v


def _six_factor(sf, legacy):
    sf = _obj(sf, "six_factor")
    scores_in = _obj(sf.get("scores"), "six_factor.scores")
    scores = {f: _int(scores_in.get(f), f"six_factor.scores.{f}", 1, 5) for f in FACTORS}
    total = _int(sf.get("total_score"), "six_factor.total_score", 6, 30)
    status = clean_str(sf.get("status"), "six_factor.status", 40)
    category = clean_str(sf.get("category"), "six_factor.category", 40)
    warnings = []
    present = [v for v in scores.values() if v is not None]
    if len(present) < len(FACTORS):
        warnings.append("six_factor_partial_scores")
    elif total is not None and sum(present) != total:
        # Laravel is authoritative: never recompute/override; only flag.
        warnings.append("six_factor_total_differs_from_sum_of_scores_not_recomputed")
    return {
        "evaluation_id": _int(sf.get("evaluation_id"), "six_factor.evaluation_id", 1, 2**53),
        "status": status, "category": category, "total_score": total, "scores": scores,
    }, warnings


def _three_dim(td):
    if td is None:
        return None, ["three_dimension_unavailable"]
    td = _obj(td, "three_dimension")
    sc = _obj(td.get("scores"), "three_dimension.scores")
    scores = {d: _num(sc.get(d), f"three_dimension.scores.{d}", 0, 100) for d in DIMS}
    ic = td.get("is_complete")
    if ic is not None and not isinstance(ic, bool):
        raise PayloadError("INVALID_FIELD", "three_dimension.is_complete must be boolean")
    warnings = []
    if ic is False or any(v is None for v in scores.values()):
        warnings.append("three_dimension_incomplete")
    return {
        "evaluation_id": _int(td.get("evaluation_id"), "three_dimension.evaluation_id", 1, 2**53),
        "status": clean_str(td.get("status"), "three_dimension.status", 40),
        "assessed_at": clean_str(td.get("assessed_at"), "three_dimension.assessed_at", 40),
        "average_score": _num(td.get("average_score"), "three_dimension.average_score", 0, 100),
        "category": clean_str(td.get("category"), "three_dimension.category", 40),
        "is_complete": ic, "scores": scores,
    }, warnings


def _business(bc, year_now):
    if bc is None:
        return None, ["business_context_unavailable"]
    bc = _obj(bc, "business_context")
    rev = bc.get("revenue")
    rev = _obj(rev, "business_context.revenue", nullable=True) or {}
    period = rev.get("period")
    if period not in (None, "monthly", "annual"):
        raise PayloadError("INVALID_FIELD", "revenue.period must be monthly|annual|null")
    out = {
        "name": clean_str(bc.get("name"), "business_context.name"),
        "sector": clean_str(bc.get("sector"), "business_context.sector", 255),
        "location": clean_str(bc.get("location"), "business_context.location", 255),
        "started_year": _int(bc.get("started_year"), "business_context.started_year", 1800, year_now + 1),
        "employee_count": _int(bc.get("employee_count"), "business_context.employee_count", 0, 4294967295),
        "business_capital": _int(bc.get("business_capital"), "business_context.business_capital", 0, 9007199254740991),
        "revenue": {"amount": _int(rev.get("amount"), "business_context.revenue.amount", 0, 9007199254740991), "period": period},
    }
    gaps = [k for k in ("name", "sector", "location", "started_year", "employee_count", "business_capital") if out[k] is None]
    if out["revenue"]["amount"] is None:
        gaps.append("revenue.amount")
    if out["revenue"]["period"] is None:
        gaps.append("revenue.period")
    if out["revenue"]["amount"] is not None and period == "monthly":
        out["revenue"]["estimated_annual_projection"] = out["revenue"]["amount"] * 12
        out["revenue"]["estimated_annual_projection_label"] = "ESTIMASI (omzet bulanan x12), bukan omzet tahunan aktual"
    return out, [f"business_context_missing:{g}" for g in gaps]


def _paths(prefix, obj, acc):
    if isinstance(obj, dict):
        for k, v in obj.items():
            _paths(f"{prefix}.{k}" if prefix else k, v, acc)
    elif obj is not None:
        acc.append(prefix)


def normalize(envelope: dict, now_year: int | None = None) -> dict:
    """Validate envelope + data and return normalized evidence. Raises PayloadError."""
    now_year = now_year or datetime.now(timezone.utc).year
    env = _obj(envelope, "envelope")
    try:
        eid = str(uuid.UUID(str(env.get("event_id"))))
    except ValueError:
        raise PayloadError("INVALID_ENVELOPE", "event_id must be UUID")
    if eid != str(env.get("event_id")).lower():
        raise PayloadError("INVALID_ENVELOPE", "event_id must be canonical lowercase UUID")
    if env.get("event_type") != "evaluation.finalized":
        raise PayloadError("INVALID_ENVELOPE", "event_type unsupported")
    ver = env.get("event_version")
    if isinstance(ver, bool) or ver not in (1, 2):
        raise PayloadError("INVALID_ENVELOPE", "event_version must be 1 or 2")
    if env.get("source") != "joyumkm":
        raise PayloadError("INVALID_ENVELOPE", "source must be joyumkm")
    occurred = _utc(env.get("occurred_at"), "occurred_at")
    data = _obj(env.get("data"), "data")
    umkm_id = _int(data.get("umkm_id"), "data.umkm_id", 1, 2**53, nullable=False)

    warnings: list[str] = []
    if ver == 1:
        sf, w = _six_factor(data, legacy=True)
        warnings += w + ["legacy_v1_payload", "business_context_unavailable", "three_dimension_unavailable"]
        bc, td = None, None
    else:
        if "six_factor" not in data:
            raise PayloadError("INVALID_FIELD", "six_factor missing in V2 payload")
        sf, w = _six_factor(data.get("six_factor"), legacy=False)
        warnings += w
        td, w = _three_dim(data.get("three_dimension"))
        warnings += w
        bc, w = _business(data.get("business_context"), now_year)
        warnings += w
    if sf["evaluation_id"] is None:
        raise PayloadError("INVALID_FIELD", "six_factor.evaluation_id required")

    evidence = {
        "event_id": eid, "source_event_version": ver, "occurred_at": occurred, "umkm_id": umkm_id,
        "business_context": bc, "six_factor": sf, "three_dimension": td,
        "data_quality_warnings": warnings,
    }
    avail: list[str] = []
    for key in ("business_context", "six_factor", "three_dimension"):
        if evidence[key] is not None:
            _paths(key, evidence[key], avail)
    evidence["available_paths"] = sorted(avail)
    return evidence
