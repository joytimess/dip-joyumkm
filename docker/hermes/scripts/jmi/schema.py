"""Formal JSON Schemas + evidence/policy validation for BI reports."""
from __future__ import annotations

import re

import jsonschema

from . import claims

PERSPECTIVES = ["business_development", "marketing_sales", "finance", "operations_people"]
S = {"type": "string", "minLength": 1, "maxLength": 2000}
SHORT = {"type": "string", "minLength": 1, "maxLength": 300}
REFS = {"type": "array", "items": {"type": "string", "maxLength": 120}, "maxItems": 12}

FINDING = {
    "type": "object", "additionalProperties": False,
    "required": ["type", "statement", "evidence_refs"],
    "properties": {"type": {"enum": ["fact", "interpretation", "hypothesis", "data_gap"]}, "statement": {"type": "string", "maxLength": 2000}, "evidence_refs": REFS},
}
FINDINGS = {"type": "array", "items": FINDING, "maxItems": 8}
PERSPECTIVE = {
    "type": "object", "additionalProperties": False,
    "required": ["status", "abstain_reason", "findings"],
    "properties": {"status": {"enum": ["analyzed", "abstained"]}, "abstain_reason": {"type": ["string", "null"], "maxLength": 500}, "findings": FINDINGS},
}
PRIORITY = {
    "type": "object", "additionalProperties": False,
    "required": ["id", "title", "perspective", "priority", "evidence_refs", "rationale", "actions", "success_metrics", "assumptions", "confidence"],
    "properties": {
        "id": {"type": "string", "pattern": "^P[1-3]$"}, "title": SHORT, "perspective": {"enum": PERSPECTIVES},
        "priority": {"enum": ["high", "medium", "low"]}, "evidence_refs": {**REFS, "minItems": 1}, "rationale": S,
        "actions": {"type": "array", "items": S, "minItems": 1, "maxItems": 6},
        "success_metrics": {"type": "array", "items": S, "minItems": 1, "maxItems": 6},
        "assumptions": {"type": "array", "items": S, "maxItems": 6},
        "confidence": {"enum": ["low", "medium", "high"]},
    },
}
PLAN_ITEM = {
    "type": "object", "additionalProperties": False, "required": ["priority_id", "action", "metric"],
    "properties": {"priority_id": {"type": "string", "pattern": "^P[1-3]$"}, "action": S, "metric": S},
}
PLAN = {"type": "array", "items": PLAN_ITEM, "maxItems": 6}
CROSS = {
    "type": "object", "additionalProperties": False, "required": ["observation", "evidence_refs", "caveat"],
    "properties": {"observation": S, "evidence_refs": {**REFS, "minItems": 2}, "caveat": S},
}

# What the MODEL must produce (the `report` object).
REPORT_SCHEMA = {
    "$schema": "https://json-schema.org/draft/2020-12/schema", "type": "object", "additionalProperties": False,
    "required": ["executive_summary", "business_profile", "six_factor_analysis", "three_dimension_analysis",
                 "cross_instrument_insights", "perspectives", "top_priorities", "action_plan", "limitations"],
    "properties": {
        "executive_summary": S,
        "business_profile": {"type": "object", "additionalProperties": False, "required": ["name", "sector", "data_gaps"],
                             "properties": {"name": {"type": ["string", "null"], "maxLength": 200}, "sector": {"type": ["string", "null"], "maxLength": 100},
                                            "data_gaps": {"type": "array", "items": {"type": "string", "maxLength": 200}, "maxItems": 40}}},
        "six_factor_analysis": {"type": "object", "additionalProperties": False, "required": ["summary", "findings"], "properties": {"summary": S, "findings": FINDINGS}},
        "three_dimension_analysis": {"type": "object", "additionalProperties": False, "required": ["available", "summary", "findings"],
                                     "properties": {"available": {"type": "boolean"}, "summary": S, "findings": FINDINGS}},
        "cross_instrument_insights": {"type": "array", "items": CROSS, "maxItems": 4},
        "perspectives": {"type": "object", "additionalProperties": False, "required": PERSPECTIVES, "properties": {p: PERSPECTIVE for p in PERSPECTIVES}},
        "top_priorities": {"type": "array", "items": PRIORITY, "minItems": 1, "maxItems": 3},
        "action_plan": {"type": "object", "additionalProperties": False, "required": ["days_1_30", "days_31_60", "days_61_90"],
                        "properties": {"days_1_30": {**PLAN, "minItems": 1}, "days_31_60": PLAN, "days_61_90": PLAN}},
        "limitations": {"type": "array", "items": S, "minItems": 1, "maxItems": 15},
    },
}

# Full stored document (system envelope + report). Nullable fields explicit.
DOCUMENT_SCHEMA = {
    "$schema": "https://json-schema.org/draft/2020-12/schema", "type": "object", "additionalProperties": False,
    "required": ["schema_version", "event_id", "source_event_version", "umkm_id", "evaluation_id", "status", "model", "provider",
                 "generated_at", "methodology_status", "knowledge_version", "prompt_version", "report"],
    "properties": {
        "schema_version": {"const": 1}, "event_id": {"type": "string", "format": "uuid"}, "source_event_version": {"enum": [1, 2]},
        "umkm_id": {"type": "integer", "minimum": 1}, "evaluation_id": {"type": "integer", "minimum": 1},
        "status": {"const": "completed"}, "model": {"type": "string"}, "provider": {"type": "string"},
        "generated_at": {"type": "string", "pattern": r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?Z$"},
        "methodology_status": {"enum": ["complete", "partial"]}, "knowledge_version": {"type": "string"}, "prompt_version": {"type": "string"},
        "report": REPORT_SCHEMA,
    },
}

JOB_STATUSES = ["pending", "processing", "completed", "failed"]


# ---------------------------------------------------------------------------------------------------------------
# Claim control by construction (design: Advisor Astra, B+C):
#  * `fact`, `data_gap` and cross-instrument texts are RENDERED BY THE SYSTEM from evidence refs - the model only
#    selects refs. Numbers, money, units and periods therefore can never be mis-stated by the model.
#  * Model-authored prose may not contain digits or currency (qualitative only) and passes a small deny-list of
#    high-risk phrases (no negation parser; only whole-sentence allowlisted caveats are exempt).
# ---------------------------------------------------------------------------------------------------------------
INSTR_PREFIX = {"six_factor_analysis": "six_factor.", "three_dimension_analysis": "three_dimension."}
FACTOR_LABEL = {"sumber_daya": "Sumber Daya", "kepemimpinan": "Kepemimpinan", "lingkungan_kerja": "Lingkungan Kerja",
                "nilai_organisasi": "Nilai Organisasi", "stabilitas": "Stabilitas", "kinerja": "Kinerja"}
CROSS_CAVEAT = ("Dua instrumen berbeda skala dan konstruk; keduanya disandingkan hanya sebagai pengamatan, "
                "bukan hubungan sebab-akibat, dan tidak digabung menjadi satu skor.")

_MONEY_WORDS = re.compile(r"%|\b(?:rp|idr|usd)\b|\b(?:juta|miliar|triliun)\b", re.I)


class _DigitsOrMoney:
    """NFKC-normalised ban on any numeral (incl. fullwidth/Arabic-Indic/superscript), currency symbols and money words."""
    @staticmethod
    def search(text: str):
        import unicodedata
        t = unicodedata.normalize("NFKC", text)
        for ch in t:
            cat = unicodedata.category(ch)
            if cat in ("Nd", "No", "Nl") or cat == "Sc" or ch.isdigit() or ch.isnumeric():
                return True
        return bool(_MONEY_WORDS.search(t))


_DIGITS_OR_MONEY = _DigitsOrMoney()
_ZERO_COST = re.compile(r"\b(?:biaya|pengeluaran|ongkos)\b(?:\s+\w+){0,3}\s+(?:nol|nihil|gratis)\b", re.I)
_NO_COST = re.compile(r"\btidak\s+ada\s+(?:biaya|pengeluaran|ongkos)\b", re.I)
_ZERO_REVENUE = re.compile(r"\b(?:omzet|pendapatan)\b(?:\s+\w+){0,3}\s+(?:nol|nihil)\b", re.I)
_NO_REVENUE_EARNED = re.compile(r"\btidak\s+memperoleh\s+(?:omzet|pendapatan)\s+sama\s+sekali\b", re.I)
_REVENUE_CAUTION = re.compile(r"^jangan\s+(?:anggap|menganggap)\s+(?:omzet|pendapatan)(?:\s+(?:bulanan|tahunan))?\s+(?:nol|nihil)[.!]?$", re.I)
_COST_CAUTION = re.compile(r"^jangan\s+anggap\s+(?:biaya|pengeluaran|ongkos)\s+(?:usaha\s+)?(?:nol|nihil|gratis)[.!]?$", re.I)
_PHRASES = [
    (re.compile(r"layak\s+(?:kredit|pinjam|dibiayai)|kelayakan\s+kredit", re.I), "credit_eligibility_claim"),
    (re.compile(r"\b(?:laba|margin|roi|keuntungan)\b(?:\s+\w+){0,3}\s+(?:naik|turun|meningkat|menurun|positif|negatif|sehat|baik|buruk|tinggi|rendah|stabil)\b", re.I), "profit_conclusion"),
    (re.compile(r"\barus\s+kas\b(?:\s+\w+){0,3}\s+(?:sehat|stabil|lancar|positif|negatif|tinggi|rendah|baik|buruk)\b", re.I), "cashflow_conclusion"),
    (re.compile(r"dijamin|menjamin|garansi\s+(?:kenaikan|hasil|keuntungan|omzet|laba)|pasti\s+(?:naik|meningkat|berhasil|untung)|dipastikan\s+(?:naik|meningkat)", re.I), "guaranteed_outcome_claim"),
    (re.compile(r"skor\s+(?:gabungan|komposit)|gabungan\s+skor", re.I), "composite_score_claim"),
    (re.compile(r"disebabkan|menyebabkan|mengakibatkan|berkorelasi|korelasi|akibat\s+dari|karena\s+(?:rendah|tinggi)nya", re.I), "causal_claim"),
    (re.compile(r"\bbenchmark\b|rata-rata\s+(?:sektor|industri)|tren\s+(?:pasar|sektor|industri)", re.I), "unsupported_market_claim"),
]
# Whole-sentence caveats that are known-safe (normalised: lowercase, no punctuation). Exemption is per sentence only.
_SAFE_SENTENCES = {
    "laba margin arus kas dan kelayakan kredit tidak dapat disimpulkan dari omzet dan modal",
    "kelayakan kredit tidak dapat disimpulkan dari omzet dan modal",
    "laba dan margin tidak dapat disimpulkan tanpa data biaya",
    "belum diketahui apakah pola penjualan berada di atas benchmark sektor",
    "tidak ada benchmark sektor dalam payload",
    "tidak ada skor gabungan",
}


# System-authored mandatory limitations (engine._sysfill): never subject to model-prose claim checks.
SYSTEM_LIMITATIONS = ("Metodologi parsial: definisi/indikator resmi Enam Faktor tidak tersedia; kategori dari Laravel dipakai apa adanya.",
                      "Laporan bersifat saran (advisory) dan belum divalidasi lapangan; tidak menjanjikan kenaikan omzet atau laba.")


class ValidationFailure(Exception):
    def __init__(self, code: str, errors: list[str]):
        super().__init__(f"{code}: {errors[:5]}")
        self.code, self.errors = code, errors


def _norm_sentence(x: str) -> str:
    return re.sub(r"\s+", " ", re.sub(r"[^\w\s]", " ", x.lower())).strip()


def phrase_violations(text: str) -> set[str]:
    found = set()
    for sent in re.split(r"(?<=[.!?;\n])\s+", text):
        if not sent.strip() or _norm_sentence(sent) in _SAFE_SENTENCES:
            continue
        for rx, code in _PHRASES:
            if rx.search(sent):
                found.add(code)
    return found


# ---- rendering -------------------------------------------------------------------------------------------------
def _rp(v) -> str:
    return "Rp" + f"{int(v):,}".replace(",", ".")


def _dec(v, historical_signed_zero=False) -> str:
    from decimal import Decimal
    value = Decimal(str(v))
    if value.is_zero() and not historical_signed_zero:
        return "0"
    text = format(value, "f")
    return (text.rstrip("0").rstrip(".") if "." in text else text).replace(".", ",")


def _get(evidence, path):
    cur = evidence
    for part in path.split("."):
        if not isinstance(cur, dict) or part not in cur:
            return None
        cur = cur[part]
    return cur


def render_ref(path: str, evidence: dict, legacy_decimal: bool = False, historical_signed_zero: bool = False) -> str | None:
    """Canonical Indonesian sentence for one evidence path, or None if the path is not renderable/available."""
    try:
        return _render_ref(path, evidence, legacy_decimal, historical_signed_zero)
    except (ValueError, TypeError):
        return None


def _render_ref(path: str, evidence: dict, legacy_decimal: bool = False, historical_signed_zero: bool = False) -> str | None:
    def dec(v):
        return _dec(v, historical_signed_zero)
    def decimal(v):
        if legacy_decimal and isinstance(v, float) and not v.is_integer():
            return f"{v:.4f}".rstrip("0").rstrip(".").replace(".", ",")
        return dec(v)
    v = _get(evidence, path)
    if v is None:
        return None
    parts = path.split(".")
    root = parts[0]
    if root == "six_factor":
        if parts[1] == "scores" and len(parts) == 3 and parts[2] in FACTOR_LABEL:
            return f"{FACTOR_LABEL[parts[2]]}: skor {dec(v)} dari skala 1-5."
        if parts[1] == "total_score":
            return f"Total skor Enam Faktor: {dec(v)} dari skala 6-30."
        if parts[1] == "category":
            return f"Kategori Enam Faktor (dari Laravel): {v}."
        if parts[1] == "status":
            return f"Status penilaian Enam Faktor: {v}."
        if parts[1] == "evaluation_id":
            return f"ID penilaian Enam Faktor: {dec(v)}."
    if root == "three_dimension":
        if parts[1] == "scores" and len(parts) == 3 and parts[2] in ("ins", "ect", "ops"):
            return f"{parts[2].upper()}: skor {decimal(v)} dari skala 0-100."
        if parts[1] == "average_score":
            return f"Rata-rata Tiga Dimensi: {decimal(v)} dari skala 0-100."
        if parts[1] == "category":
            return f"Kategori Tiga Dimensi (dari Laravel): {v}."
        if parts[1] == "status":
            return f"Status penilaian Tiga Dimensi: {v}."
        if parts[1] == "is_complete":
            return "Penilaian Tiga Dimensi: " + ("lengkap." if v else "tidak lengkap.")
        if parts[1] == "assessed_at":
            return f"Waktu penilaian Tiga Dimensi: {v}."
        if parts[1] == "evaluation_id":
            return f"ID penilaian Tiga Dimensi: {dec(v)}."
    if root == "business_context":
        rev = (evidence.get("business_context") or {}).get("revenue") or {}
        period = rev.get("period")
        simple = {"sector": lambda: f"Sektor usaha: {v}.", "location": lambda: f"Lokasi usaha: {v}.", "name": lambda: f"Nama usaha: {v}.",
                  "started_year": lambda: f"Tahun mulai usaha: {v}.", "employee_count": lambda: f"Jumlah tenaga kerja: {dec(v)} orang.",
                  "business_capital": lambda: f"Modal usaha: {_rp(v)}." if isinstance(v, int) else None}
        if len(parts) == 2 and parts[1] in simple:
            return simple[parts[1]]()
        if parts[1:] == ["revenue", "period"]:
            return {"monthly": "Periode omzet: bulanan.", "annual": "Periode omzet: tahunan."}.get(v)
        if parts[1:] == ["revenue", "amount"]:
            label = {"monthly": "Omzet bulanan", "annual": "Omzet tahunan (dilaporkan)"}.get(period, "Omzet (periode tidak diketahui)")
            return f"{label}: {_rp(v)}."
        if parts[1:] in (["revenue", "estimated_annual_projection"], ["revenue", "estimated_annual_projection_label"]):
            proj = rev.get("estimated_annual_projection")
            if period == "monthly" and proj is not None:
                return f"Estimasi omzet tahunan: {_rp(proj)} (omzet bulanan x12; bukan omzet tahunan aktual)."
    return None


def gap_sentence(gid: str, knowledge: dict, legacy: bool = False) -> str:
    vocab = knowledge.get("data_gap_vocabulary", {})
    if isinstance(vocab, dict) and gid in vocab:
        return vocab[gid]
    if legacy:
        return f"Data {gid.replace('_', ' ')} tidak tersedia dalam payload."
    warnings = {
        "six_factor_total_differs_from_sum_of_scores_not_recomputed": "Total Enam Faktor berbeda dari jumlah skor; total yang dilaporkan tidak dihitung ulang.",
        "six_factor_partial_scores": "Sebagian skor Enam Faktor tidak tersedia.",
        "three_dimension_incomplete": "Penilaian Tiga Dimensi belum lengkap.",
        "business_context_unavailable": "Konteks usaha tidak tersedia dalam payload.",
    }
    return warnings.get(gid, "Ada peringatan kualitas data yang perlu diperiksa pada sumbernya.")


def allowed_gap_ids(evidence, knowledge) -> set[str]:
    vocab = knowledge.get("data_gap_vocabulary", {})
    ids = set(vocab if isinstance(vocab, (dict, list)) else [])
    ids |= {re.sub(r"[^a-z0-9_]+", "_", w.lower()).strip("_") for w in evidence.get("data_quality_warnings", [])}
    return ids


def _load_knowledge():
    import json
    from pathlib import Path
    return json.loads((Path(__file__).resolve().parent.parent / "knowledge" / "methodology.v1.json").read_text())


_POS_WORD = {"low": "termasuk yang terendah", "high": "termasuk yang tertinggi", "mid": "berada di tengah", "flat": "sama dengan skor lain"}


def _position(ref: str, evidence: dict):
    """Relative position of one score inside ITS OWN instrument (never compared across scales)."""
    parts = ref.split(".")
    if len(parts) != 3 or parts[1] != "scores":
        return None
    scores = {k: v for k, v in ((evidence.get(parts[0]) or {}).get("scores") or {}).items() if v is not None}
    if parts[2] not in scores or len(scores) < 2:
        return None
    v, vals = scores[parts[2]], sorted(scores.values())
    if vals[0] == vals[-1]:
        return "flat"
    return "low" if v == vals[0] else "high" if v == vals[-1] else "mid"


def cross_relevance(refs: list, evidence: dict) -> str:
    """System-rendered practical reading of two instruments side by side. Deterministic; no correlation or cause."""
    pos = {}
    for r in refs:
        w = _position(r, evidence)
        if w:
            pos.setdefault("six_factor" if r.startswith("six_factor.") else "three_dimension", []).append((r, w))
    bits = []
    for inst, label in (("six_factor", "Enam Faktor"), ("three_dimension", "Tiga Dimensi")):
        for r, w in pos.get(inst, [])[:2]:
            name = FACTOR_LABEL.get(r.split(".")[-1], r.split(".")[-1].upper())
            bits.append(f"dalam {label}, {name} {_POS_WORD[w]} di antara skor instrumennya sendiri")
    s = ("Posisi relatif: " + "; ".join(bits) + ". ") if bits else ""
    kinds = {w for v in pos.values() for _, w in v}
    sides = {i: {w for _, w in v} for i, v in pos.items()}
    if len(sides) == 2 and sides["six_factor"] == sides["three_dimension"] and kinds <= {"low", "high"} and len(kinds) == 1:
        s += ("Makna praktis: kedua instrumen menempatkan butir yang disandingkan pada sisi yang sama; pemilik dapat memeriksa lebih dulu "
              "apakah ada kondisi lapangan yang selaras, tanpa menganggap salah satu menjelaskan yang lain.")
    elif len(sides) == 2:
        s += ("Makna praktis: posisi relatifnya tidak searah; ini wajar karena instrumen berbeda dan tidak perlu dipaksa selaras, "
              "sehingga masing-masing ditinjau sendiri terhadap keadaan usaha.")
    else:
        s += ("Makna praktis: pengamatan ini hanya sudut pandang tambahan; belum cukup untuk menarik kesimpulan, "
              "dan hasilnya perlu dicocokkan dengan keadaan usaha oleh pemilik.")
    return s


def render_report(report: dict, evidence: dict, knowledge: dict | None = None, legacy_decimal: bool = False, legacy_render: bool = False, historical_signed_zero: bool = False, cross_v5: bool = False) -> None:
    """Fill system-owned text (fact / data_gap findings, cross-instrument insights) from evidence refs, in place.

    Raises ValidationFailure when the model selected something the system cannot render (unknown/null ref).
    """
    knowledge = knowledge or _load_knowledge()
    problems = []
    try:
        avail = set(evidence["available_paths"])
        gaps = allowed_gap_ids(evidence, knowledge)
        finding_lists = [("six_factor_analysis", report["six_factor_analysis"]["findings"]), ("three_dimension_analysis", report["three_dimension_analysis"]["findings"])]
        finding_lists += [(f"perspectives.{k}", v["findings"]) for k, v in report["perspectives"].items()]
        for where, lst in finding_lists:
            for i, f in enumerate(lst):
                refs = f["evidence_refs"]
                if f["type"] == "fact":
                    sents = []
                    for r in refs:
                        t = render_ref(r, evidence, legacy_decimal, historical_signed_zero) if r in avail else None
                        if t is None:
                            problems.append(f"{where}[{i}]: fact ref {r!r} is not an available, renderable payload field")
                        else:
                            sents.append(t)
                    f["statement"] = " ".join(sents if legacy_render else dict.fromkeys(sents))
                elif f["type"] == "data_gap":
                    sents = []
                    for r in refs:
                        if r.startswith("data_gap:") and r[9:] in gaps:
                            sents.append(gap_sentence(r[9:], knowledge, legacy_render))
                        else:
                            problems.append(f"{where}[{i}]: data_gap ref {r!r} is not a valid data_gap id")
                    f["statement"] = " ".join(sents if legacy_render else dict.fromkeys(sents))
        for i, c in enumerate(report["cross_instrument_insights"]):
            sents = []
            for r in c["evidence_refs"]:
                t = render_ref(r, evidence, legacy_decimal, historical_signed_zero) if r in avail else None
                if t is None:
                    problems.append(f"cross[{i}]: ref {r!r} is not an available, renderable payload field")
                else:
                    sents.append(t)
            c["observation"] = "Pengamatan berdampingan: " + " ".join(sents if legacy_render else dict.fromkeys(sents))
            if cross_v5 and not problems:
                c["observation"] += " " + cross_relevance(c["evidence_refs"], evidence)
            c["caveat"] = CROSS_CAVEAT
    except (KeyError, TypeError, AttributeError):
        raise ValidationFailure("SCHEMA_MISMATCH", ["malformed findings structure"])
    if problems:
        raise ValidationFailure("EVIDENCE_POLICY_VIOLATION", problems[:15])


def _prose_refs(report):
    """path -> evidence_refs of the owning finding/priority (None for fields that cannot carry refs)."""
    out = {}
    for sect in ("six_factor_analysis", "three_dimension_analysis"):
        for i, f in enumerate(report[sect]["findings"]):
            out[f"{sect}.findings[{i}]"] = f["evidence_refs"]
    for k, pv in report["perspectives"].items():
        for i, f in enumerate(pv["findings"]):
            out[f"perspectives.{k}[{i}]"] = f["evidence_refs"]
    for i, p in enumerate(report["top_priorities"]):
        for k in ("title", "rationale", "actions", "success_metrics", "assumptions"):
            out[f"top_priorities[{i}].{k}"] = p["evidence_refs"]
            for j in range(len(p[k]) if isinstance(p[k], list) else 0):
                out[f"top_priorities[{i}].{k}[{j}]"] = p["evidence_refs"]
    return out


def _prose(report):
    """(path, text) of every MODEL-AUTHORED prose field (system-rendered texts and ids excluded)."""
    yield "executive_summary", report["executive_summary"]
    for sect in ("six_factor_analysis", "three_dimension_analysis"):
        yield f"{sect}.summary", report[sect]["summary"]
        for i, f in enumerate(report[sect]["findings"]):
            if f["type"] in ("interpretation", "hypothesis"):
                yield f"{sect}.findings[{i}]", f["statement"]
    for k, pv in report["perspectives"].items():
        if pv["abstain_reason"]:
            yield f"perspectives.{k}.abstain_reason", pv["abstain_reason"]
        for i, f in enumerate(pv["findings"]):
            if f["type"] in ("interpretation", "hypothesis"):
                yield f"perspectives.{k}[{i}]", f["statement"]
    for i, p in enumerate(report["top_priorities"]):
        for k in ("title", "rationale"):
            yield f"top_priorities[{i}].{k}", p[k]
        for k in ("actions", "success_metrics", "assumptions"):
            for j, t in enumerate(p[k]):
                yield f"top_priorities[{i}].{k}[{j}]", t
    for ph, lst in report["action_plan"].items():
        for i, it in enumerate(lst):
            yield f"action_plan.{ph}[{i}].action", it["action"]
            yield f"action_plan.{ph}[{i}].metric", it["metric"]
    for i, t in enumerate(report["limitations"]):
        yield f"limitations[{i}]", t


def validate_report(report: dict, evidence: dict, knowledge: dict | None = None, mode: str = "final") -> None:
    """Schema + evidence + policy validation. mode='final' (new reports) also enforces rendered-text equality and
    prose rules; mode='legacy' (reports from older prompt versions) checks structure and refs only."""
    if not isinstance(report, dict):
        raise ValidationFailure("SCHEMA_MISMATCH", ["report is not an object"])
    errs = sorted(jsonschema.Draft202012Validator(REPORT_SCHEMA).iter_errors(report), key=lambda e: list(e.absolute_path))
    if errs:
        raise ValidationFailure("SCHEMA_MISMATCH", [f"{'/'.join(map(str, e.absolute_path))}: {e.message[:120]}" for e in errs[:10]])
    knowledge = knowledge or _load_knowledge()
    legacy = mode == "legacy"
    problems: list[str] = []
    avail = set(evidence["available_paths"])
    gaps = allowed_gap_ids(evidence, knowledge)

    def is_gap(r):
        return r.startswith("data_gap:") and (legacy or r[9:] in gaps)

    def check_finding(f, where, prefix=None):
        refs, t = f["evidence_refs"], f["type"]
        for r in refs:
            if r not in avail and not is_gap(r):
                problems.append(f"{where}: unknown evidence_ref {r!r}")
        if legacy:
            return
        real = [r for r in refs if r in avail]
        gap = [r for r in refs if is_gap(r)]
        if t in ("fact", "interpretation"):
            if not real:
                problems.append(f"{where}: {t} needs at least one real payload evidence_ref")
            if gap:
                problems.append(f"{where}: {t} must not cite data_gap refs (use type data_gap)")
        if t == "data_gap" and (not gap or real):
            problems.append(f"{where}: data_gap finding must cite only data_gap ids")
        if t in ("interpretation", "hypothesis", "data_gap") and not f["statement"].strip():
            problems.append(f"{where}: empty statement")
        if prefix:
            for r in real:
                if not r.startswith(prefix):
                    problems.append(f"{where}: evidence_ref {r!r} outside instrument {prefix[:-1]}")

    for sect, prefix in INSTR_PREFIX.items():
        for i, f in enumerate(report[sect]["findings"]):
            check_finding(f, f"{sect}.findings[{i}]", prefix)
    for p, pv in report["perspectives"].items():
        if pv["status"] == "abstained":
            if pv["findings"] or not (pv["abstain_reason"] or "").strip():
                problems.append(f"perspectives.{p}: abstained requires reason and no findings")
        elif not pv["findings"]:
            problems.append(f"perspectives.{p}: analyzed requires findings")
        for i, f in enumerate(pv["findings"]):
            check_finding(f, f"perspectives.{p}[{i}]")
    has_td = evidence["three_dimension"] is not None
    tda = report["three_dimension_analysis"]
    if tda["available"] != has_td:
        problems.append("three_dimension_analysis.available mismatches payload")
    if not has_td and (tda["findings"] or report["cross_instrument_insights"]):
        problems.append("three_dimension unavailable: findings/cross insights must be empty")
    for i, c in enumerate(report["cross_instrument_insights"]):
        for r in c["evidence_refs"]:
            if r not in avail:
                problems.append(f"cross[{i}]: unknown/non-payload evidence_ref {r!r}")
        if not any(r.startswith("six_factor.") for r in c["evidence_refs"]) or not any(r.startswith("three_dimension.") for r in c["evidence_refs"]):
            problems.append(f"cross[{i}]: must cite both instruments")
    ids = []
    priority_titles = set()
    for i, p in enumerate(report["top_priorities"]):
        ids.append(p["id"])
        title = _norm_sentence(p["title"])
        if mode in ("final", "r1_v4", "r1_v3", "r1_v2") and title in priority_titles:
            problems.append(f"top_priorities[{i}]: duplicate priority title")
        priority_titles.add(title)
        real = [r for r in p["evidence_refs"] if r in avail]
        for r in p["evidence_refs"]:
            if r not in avail and not is_gap(r):
                problems.append(f"top_priorities[{i}]: unknown evidence_ref {r!r}")
        if not legacy and not real and p["confidence"] == "high":
            problems.append(f"top_priorities[{i}]: confidence high requires real payload evidence")
        if mode == "final" and p["confidence"] == "high":
            direct = [r for r in real if r.split(".")[0] in ("six_factor", "three_dimension") or r.startswith("business_context.revenue.")
                      or r == "business_context.business_capital"]
            if len(set(real)) < 2 or not direct or any(is_gap(r) for r in p["evidence_refs"]):
                problems.append(f"top_priorities[{i}]: confidence high needs at least two direct payload refs (score/category/revenue/capital) and no data_gap ref")
    if len(set(ids)) != len(ids):
        problems.append("duplicate priority ids")
    used = set()
    for ph in ("days_1_30", "days_31_60", "days_61_90"):
        if mode in ("final", "r1_v4", "r1_v3", "r1_v2") and not report["action_plan"][ph]:
            problems.append(f"action_plan.{ph}: phase must contain an action and metric")
        for i, it in enumerate(report["action_plan"][ph]):
            if it["priority_id"] not in ids:
                problems.append(f"action_plan.{ph}[{i}]: unknown priority_id {it['priority_id']}")
            used.add(it["priority_id"])
    for pid in ids:
        if pid not in used:
            problems.append(f"priority {pid} absent from action_plan")
    if mode == "final":
        problems += claims.check_priority_texts(report["top_priorities"])
        problems += claims.check_plan_progression(report["action_plan"])
    if not legacy:
        # rendered text must be exactly what the system renders from the refs (model cannot edit facts)
        import copy
        expected = copy.deepcopy(report)
        try:
            render_report(expected, evidence, knowledge, legacy_decimal=mode in ("previous", "r1_previous"), legacy_render=mode not in ("final", "r1_v4"), historical_signed_zero=mode == "r1_v2", cross_v5=mode == "final")
        except ValidationFailure as e:
            problems += e.errors
        else:
            if expected != report:
                problems.append("system-rendered text (fact/data_gap/cross) differs from canonical rendering")
        prose_refs = _prose_refs(report)
        sector = ((evidence.get("business_context") or {}).get("sector") or "").strip() or None
        for path, text in _prose(report):
            if mode in ("final", "r1_v4", "r1_v3", "r1_v2", "r1_previous") and not text.strip():
                problems.append(f"{path}: text must not be blank")
            if mode == "r1_v2" and _ZERO_COST.search(text):
                problems.append(f"{path}: unsupported zero-cost claim")
            if mode in ("r1_v4", "r1_v3"):
                for sentence in re.split(r"(?<=[.!?;\n])\s+", text):
                    if (_ZERO_COST.search(sentence) and not _COST_CAUTION.fullmatch(sentence.strip())) or _NO_COST.search(sentence):
                        problems.append(f"{path}: unsupported zero-cost claim")
                    revenue = (evidence.get("business_context") or {}).get("revenue") or {}
                    tokens = _norm_sentence(sentence).split()
                    absence = mode == "r1_v4" and (any(tokens[i:i+3] in (["tidak", "ada", "omzet"], ["tidak", "ada", "pendapatan"]) for i in range(len(tokens)-2)) or _NO_REVENUE_EARNED.search(sentence))
                    zero_claim = _ZERO_REVENUE.search(sentence) and not (mode == "r1_v4" and _REVENUE_CAUTION.fullmatch(sentence.strip()))
                    if (revenue.get("amount") is None if mode == "r1_v3" else revenue.get("amount") != 0) and (zero_claim or absence):
                        problems.append(f"{path}: unsupported zero-revenue claim")
            if mode == "final" and text not in SYSTEM_LIMITATIONS:
                for code, reason, *clause in claims.check_text(text, evidence, prose_refs.get(path), sector, _SAFE_SENTENCES):
                    problems.append(f"{code} in {path}: {reason}" + (f" [{clause[0]}]" if clause else ""))
            if _DIGITS_OR_MONEY.search(text):
                problems.append(f"{path}: digits/currency are not allowed in model prose (state qualitatively; figures come from facts)")
            for code in phrase_violations(text):
                problems.append(f"prohibited_claim:{code} in {path}")
    if problems:
        raise ValidationFailure("EVIDENCE_POLICY_VIOLATION", sorted(set(problems))[:15])


def finalize_report(report: dict, evidence: dict, knowledge: dict | None = None) -> None:
    """Render system-owned text, then validate (the engine path). Raises ValidationFailure."""
    knowledge = knowledge or _load_knowledge()
    render_report(report, evidence, knowledge, cross_v5=True)
    validate_report(report, evidence, knowledge, "final")


def validate_document(doc: dict, evidence: dict | None = None, knowledge: dict | None = None, expected_prompt_version: str | None = None) -> None:
    errs = list(jsonschema.Draft202012Validator(DOCUMENT_SCHEMA, format_checker=jsonschema.FormatChecker()).iter_errors(doc))
    if errs:
        raise ValidationFailure("SCHEMA_MISMATCH", [f"{'/'.join(map(str, e.absolute_path))}: {e.message[:120]}" for e in errs[:10]])
    if evidence is not None:
        for k in ("event_id", "umkm_id", "source_event_version"):
            if doc[k] != evidence[k]:
                raise ValidationFailure("IDENTITY_MISMATCH", [f"{k} differs from job evidence"])
        if doc["evaluation_id"] != evidence["six_factor"]["evaluation_id"]:
            raise ValidationFailure("IDENTITY_MISMATCH", ["evaluation_id differs from job evidence"])
        if expected_prompt_version is not None and doc.get("prompt_version") != expected_prompt_version:
            raise ValidationFailure("IDENTITY_MISMATCH", ["report prompt_version differs from job record (downgrade/tamper)"])
        from .prompt import PROMPT_VERSION  # local import: prompt imports this module
        mode = {PROMPT_VERSION: "final", "r1-prompt-5": "final", "r1-prompt-4": "r1_v4", "r1-prompt-3": "r1_v3", "r1-prompt-2": "r1_v2", "r1-prompt-1": "r1_previous", "p4-03b-prompt-4": "previous"}.get(doc.get("prompt_version"), "legacy")
        validate_report(doc["report"], evidence, knowledge, mode)
