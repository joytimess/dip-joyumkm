"""Deterministic nodes for the signed JoyUMKM prediction workflow."""
from datetime import datetime, timezone
import json
import math
import time
from uuid import uuid4

WORKFLOW = "umkm-predict"
PRESET = "umkm-prediction"
ROUTE = "/predict"
FACTORS = (
    "sumber_daya", "kepemimpinan", "lingkungan_kerja",
    "nilai_organisasi", "stabilitas", "kinerja",
)
RANGES = (
    (12, "Kurang Sehat", "Prioritaskan perbaikan pada faktor dengan skor terendah."),
    (18, "Cukup Sehat", "Tingkatkan faktor yang masih lemah secara bertahap."),
    (24, "Sehat", "Pertahankan kinerja dan tingkatkan faktor yang masih lemah."),
    (30, "Sangat Sehat", "Pertahankan praktik yang sudah berjalan baik."),
)


def now():
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds")


def unique_fields(pairs):
    result = dict(pairs)
    if len(result) != len(pairs):
        raise ValueError("duplicate_fields")
    return result


def reject_constant(value):
    raise ValueError("invalid_json_constant")


def decode(raw):
    try:
        return json.loads(raw.decode("utf-8"), object_pairs_hook=unique_fields,
                          parse_constant=reject_constant), None
    except (ValueError, RecursionError):
        return None, "Isi permintaan harus berupa objek JSON yang valid."


def safe_input(body):
    if not isinstance(body, dict):
        return {"type": type(body).__name__}
    scores = {
        key: value if (type(value) in (int, bool, type(None))
                       or type(value) is float and math.isfinite(value)) else {"type": type(value).__name__}
        for key, value in body.items() if key in FACTORS
    }
    return {"scores": scores, "unknown_fields": len(body.keys() - set(FACTORS))}


def calculate(body, parse_error):
    errors = {}
    if parse_error or not isinstance(body, dict):
        errors["body"] = [parse_error or "Isi permintaan harus berupa objek JSON."]
    else:
        if body.keys() - set(FACTORS):
            errors["body"] = ["Permintaan memuat kolom yang tidak dikenal."]
        for field in FACTORS:
            score = body.get(field)
            if field not in body:
                errors[field] = ["Nilai faktor wajib diisi."]
            elif type(score) is not int or not 1 <= score <= 5:
                errors[field] = ["Nilai faktor harus berupa bilangan bulat 1 sampai 5."]
    if errors:
        return {"success": False, "message": "Periksa nilai keenam faktor.", "errors": errors}

    total = sum(body.values())
    category, recommendation = next((label, advice) for upper, label, advice in RANGES if total <= upper)
    return {"success": True, "total_score": total, "category": category, "recommendation": recommendation}


class PredictionNodes:
    def __init__(self, raw, mode):
        self.raw = raw
        self.mode = mode
        self.body = None
        self.parse_error = None

    def run(self, step, state):
        from .runner import evaluate_condition

        node = step["id"]
        kind = step["kind"]
        start = time.perf_counter()
        record = {"kind": kind, "status": "ok", "started_at": now(),
                  "input": None, "output": None, "error": None}
        state.setdefault("node_records", {})[node] = record
        try:
            if kind == "trigger":
                self.body, self.parse_error = decode(self.raw)
                record["input"] = {"method": "POST", "path": step["path"], "mode": self.mode,
                                   **safe_input(self.body)}
                record["output"] = {**record["input"], "signature": "verified"}
                record["error"] = self.parse_error
            else:
                parent, = step["after"]
                previous = state["outputs"][parent]
                record["input"] = previous
                if kind == "transform":
                    record["output"] = calculate(self.body, self.parse_error)
                    if not record["output"]["success"]:
                        record["status"] = "invalid"
                        record["error"] = "validation_failed"
                elif kind == "condition":
                    selected = evaluate_condition(step["expression"], state["outputs"], set(state["outputs"]))
                    record["input"] = {"expression": step["expression"], "value": previous["success"]}
                    record["output"] = {"branch": selected, "body": previous}
                elif kind == "respond":
                    record["input"] = {"required_branch": step["branch"], "actual_branch": previous["branch"]}
                    if previous["branch"] is not step["branch"]:
                        state["skipped"].append(node)
                        record["status"] = "skipped"
                        return
                    record["output"] = {"status_code": step["status_code"], "body": previous["body"]}
                    state["http_response"] = record["output"]
                    state["http_status"] = step["status_code"]
                else:
                    raise ValueError("unsupported_prediction_node")
            state["outputs"][node] = record["output"]
        except Exception:
            record.update(status="failed", error="node_execution_failed")
            raise
        finally:
            record.update(finished_at=now(), elapsed_ms=round((time.perf_counter() - start) * 1000, 3))


def execute(graph, raw, mode, runs):
    from .convert import build_run_plan
    from .runner import new_state, run_plan

    start = time.perf_counter()
    plan = build_run_plan(graph)
    state = new_state(plan, uuid4().hex[:12])
    state.update(webhook_mode=mode, received_at=now())
    run_plan(plan, state, prediction=PredictionNodes(raw, mode))
    state["elapsed_ms"] = round((time.perf_counter() - start) * 1000, 3)
    runs.save(state)
    return state
