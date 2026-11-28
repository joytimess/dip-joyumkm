"""Deterministic presentation layer (Telegram HTML formatter + PNG chart generator)
for the umkm-analytics-assistant workflow.

All numbers and chart values are derived strictly from SQL query results.
"""
from __future__ import annotations

from html import escape
import io
import math
import re
from typing import Any

from PIL import Image, ImageDraw, ImageFont

SYNTHETIC_LABEL = "Data sintetis JoyUMKM"
NO_DATA_TEXT = "Tidak ada data sintetis yang cocok dengan pertanyaan dan periode tersebut."
CLARIFY_TEXT = "Mohon sebutkan jenis evaluasi, periode, atau metrik yang ingin dianalisis."

_EVAL_LABELS = {
    "six_factor": "Enam Faktor (skala 6-30 untuk total)",
    "three_dimension": "Tiga Dimensi (skala 0-100)",
    "umkm": "UMKM",
}

_LABEL_MAP = {
    "sektor": "Sektor",
    "sector": "Sektor",
    "kota": "Kota",
    "city": "Kota",
    "provinsi": "Provinsi",
    "province": "Provinsi",
    "nama": "Nama UMKM",
    "name": "Nama UMKM",
    "umkm": "UMKM",
    "period_month": "Periode",
    "periode": "Periode",
    "bulan": "Bulan",
    "observasi": "Observasi",
    "jumlah_umkm": "Jumlah UMKM",
    "total_umkm": "Total UMKM",
    "rata_ins": "Rata-rata INS",
    "rata_ect": "Rata-rata ECT",
    "rata_ops": "Rata-rata OPS",
    "rata_rata": "Rata-rata",
    "rata_skor": "Rata-rata Skor",
    "rata_total": "Rata-rata Total",
    "avg_score": "Rata-rata Skor",
    "average_score": "Skor Rata-rata",
    "ins_score": "Skor INS",
    "ect_score": "Skor ECT",
    "ops_score": "Skor OPS",
    "total_score": "Total Skor",
    "sumber_daya": "Sumber Daya",
    "kepemimpinan": "Kepemimpinan",
    "lingkungan_kerja": "Lingkungan Kerja",
    "nilai_organisasi": "Nilai Organisasi",
    "stabilitas": "Stabilitas",
    "kinerja": "Kinerja",
}

_UPPER_TOKENS = {"ins", "ect", "ops", "umkm", "id", "wib"}
_PERIOD_RE = re.compile(r"^[0-9]{4}-(?:0[1-9]|1[0-2])$")
_UNSAFE_ANALYSIS_RE = re.compile(r"[\d\r\n]|(?:select|password|credential|api.key)\b", re.I)

_PALETTE = [
    (37, 99, 235),   # Blue
    (13, 148, 136),  # Teal
    (217, 119, 6),   # Amber
    (225, 29, 72),   # Rose
    (124, 58, 237),  # Violet
    (5, 150, 105),   # Emerald
]


def humanize_label(key: str) -> str:
    """Convert SQL column alias into a clean, readable Indonesian label without mangling."""
    raw = str(key).strip()
    lower = raw.lower()
    if lower in _LABEL_MAP:
        return _LABEL_MAP[lower]
    if lower.startswith("rata_"):
        rest = lower[5:]
        if rest == "rata":
            return "Rata-rata"
        return f"Rata-rata {_humanize_tokens(rest)}"
    return _humanize_tokens(raw)


def _humanize_tokens(text: str) -> str:
    parts = [p for p in re.split(r"[_\-\s]+", text) if p]
    out = []
    for part in parts:
        if part.lower() in _UPPER_TOKENS:
            out.append(part.upper())
        else:
            out.append(part.capitalize())
    return " ".join(out) if out else text


def _to_float(value: Any) -> float | None:
    if isinstance(value, bool) or value is None:
        return None
    if isinstance(value, (int, float)):
        return float(value) if math.isfinite(float(value)) else None
    if isinstance(value, str):
        text = value.strip()
        if not text:
            return None
        try:
            num = float(text)
            return num if math.isfinite(num) else None
        except ValueError:
            return None
    return None


def _cell_text(value: Any) -> str:
    if value is None:
        return "tidak tersedia"
    return str(value).replace("\r", " ").replace("\n", " ").strip()


def _classify_columns(rows: list[dict]) -> tuple[list[str], list[str], list[str]]:
    """Split columns into (dimension_cols, metric_cols, obs_cols) based on SQL row values."""
    if not rows:
        return [], [], []
    keys = list(rows[0].keys())
    dim_cols: list[str] = []
    metric_cols: list[str] = []
    obs_cols: list[str] = []
    for k in keys:
        kl = k.lower()
        vals = [r.get(k) for r in rows if r.get(k) is not None]
        if kl in {"period_month", "periode", "bulan"} or (vals and all(isinstance(v, str) and _PERIOD_RE.match(v.strip()) for v in vals)):
            dim_cols.append(k)
            continue
        if vals and all(_to_float(v) is not None for v in vals):
            if kl in {"observasi", "count", "jumlah_observasi"}:
                obs_cols.append(k)
            else:
                metric_cols.append(k)
        else:
            dim_cols.append(k)
    if not metric_cols and obs_cols:
        metric_cols = obs_cols
        obs_cols = []
    return dim_cols, metric_cols, obs_cols


def detect_report_type(rows: list[dict], intent: dict | None = None, *, has_data: bool = True) -> str:
    """Detect one of: empty, summary, trend, ranking, comparison."""
    if not has_data or not rows:
        return "empty"
    intent = intent or {}
    dim_cols, metric_cols, _ = _classify_columns(rows)
    if len(rows) == 1 and not dim_cols:
        return "summary"
    if len(rows) == 1 and len(metric_cols) >= 1:
        group_by = str(intent.get("group_by") or "").strip().lower()
        if group_by in {"", "tidak ada", "none", "keseluruhan", "semua", "-"}:
            return "summary"
    # Trend: period_month / YYYY-MM dimension with >= 2 rows
    if dim_cols:
        first_dim = dim_cols[0]
        vals = [str(r.get(first_dim) or "").strip() for r in rows]
        if first_dim.lower() in {"period_month", "periode", "bulan"} or (
            len(rows) >= 2 and all(_PERIOD_RE.match(v) for v in vals if v)
        ):
            return "trend"
    # Comparison vs Ranking
    sort_str = str(intent.get("sort") or "").lower()
    limit = intent.get("limit")
    if len(metric_cols) >= 2 and len(rows) >= 2:
        return "comparison"
    if any(tok in sort_str for tok in ("desc", "tertinggi", "terendah", "top", "bottom", "ranking", "peringkat", "menurun")):
        return "ranking"
    if isinstance(limit, int) and 1 < limit <= 20 and len(rows) >= 2:
        return "ranking"
    if len(rows) >= 2:
        return "comparison"
    return "summary"


def _report_title(report_type: str, evaluation_type: str) -> str:
    eval_short = {
        "six_factor": "Enam Faktor",
        "three_dimension": "Tiga Dimensi",
        "umkm": "UMKM",
    }.get(evaluation_type, "UMKM")
    titles = {
        "ranking": f"Peringkat Evaluasi {eval_short}",
        "comparison": f"Perbandingan Evaluasi {eval_short}",
        "trend": f"Tren Bulanan Evaluasi {eval_short}",
        "summary": f"Ringkasan Agregasi {eval_short}",
        "empty": f"Hasil Analitik {eval_short}",
    }
    return titles.get(report_type, f"Laporan Analitik {eval_short}")


def _safe_analysis_text(analysis: dict, key: str) -> str | None:
    value = analysis.get(key)
    if (
        isinstance(value, str)
        and value.strip()
        and len(value.strip()) <= 260
        and not _UNSAFE_ANALYSIS_RE.search(value)
    ):
        return value.strip()
    return None


def format_html_report(outputs: dict) -> dict[str, Any]:
    """Build a Telegram HTML report strictly separating factual SQL data,
    interpretation, and recommendation."""
    if outputs.get("plan", {}).get("intent", {}).get("clarification_needed"):
        html = (
            f"<b>Klarifikasi Diperlukan</b>\n"
            f"<i>{escape(SYNTHETIC_LABEL)}</i>\n\n"
            f"{escape(CLARIFY_TEXT)}"
        )
        return {
            "html": html,
            "report_type": "clarify",
            "title": "Klarifikasi Diperlukan",
            "has_data": False,
            "is_clarify": True,
        }

    data = outputs["filter"]
    eval_type = data.get("evaluation_type", "umkm")
    period = _cell_text(data.get("period", "Seluruh periode cohort"))
    rows = data.get("rows") or []
    has_data = bool(data.get("has_data"))
    intent = outputs.get("plan", {}).get("intent") or data.get("intent") or {}
    report_type = detect_report_type(rows, intent, has_data=has_data)
    title = _report_title(report_type, eval_type)

    if report_type == "empty":
        html = (
            f"<b>{escape(title)}</b>\n"
            f"<i>{escape(SYNTHETIC_LABEL)}</i>\n"
            f"• Jenis: {escape(_EVAL_LABELS.get(eval_type, eval_type))}\n"
            f"• Periode: {escape(period)}\n\n"
            f"<b>Data Faktual (SQL)</b>\n"
            f"{escape(NO_DATA_TEXT)}"
        )
        return {
            "html": html,
            "report_type": "empty",
            "title": title,
            "has_data": False,
            "is_clarify": False,
        }

    dim_cols, metric_cols, obs_cols = _classify_columns(rows)
    lines = [
        f"<b>{escape(title)}</b>",
        f"<i>{escape(SYNTHETIC_LABEL)}</i>",
        f"• Jenis: {escape(_EVAL_LABELS.get(eval_type, eval_type))}",
        f"• Periode: {escape(period)}",
        f"• Baris hasil: <code>{escape(str(data['row_count']))}</code>",
    ]
    if data.get("truncated"):
        lines.append(f"<i>Pratinjau {len(rows)} baris. Hasil ditampilkan sebagian.</i>")

    lines.append("")
    lines.append("<b>Data Faktual (SQL)</b>")

    if report_type == "summary":
        for row in rows:
            for k, v in row.items():
                lines.append(f"• {escape(humanize_label(k))}: <b>{escape(_cell_text(v))}</b>")
    elif report_type == "ranking":
        for idx, row in enumerate(rows, start=1):
            dim_text = " — ".join(escape(_cell_text(row.get(c))) for c in dim_cols) if dim_cols else f"Baris {idx}"
            metric_parts = [f"{escape(humanize_label(c))}: <b>{escape(_cell_text(row.get(c)))}</b>" for c in metric_cols]
            obs_parts = [f"{escape(humanize_label(c))}: {escape(_cell_text(row.get(c)))}" for c in obs_cols]
            detail = " | ".join(metric_parts + obs_parts)
            lines.append(f"{idx}. <b>{dim_text}</b> — {detail}")
    elif report_type == "trend":
        for row in rows:
            period_val = escape(_cell_text(row.get(dim_cols[0]))) if dim_cols else "-"
            extra_dims = [f"{escape(humanize_label(c))}: {escape(_cell_text(row.get(c)))}" for c in dim_cols[1:]]
            metric_parts = [f"{escape(humanize_label(c))}: <b>{escape(_cell_text(row.get(c)))}</b>" for c in metric_cols]
            obs_parts = [f"{escape(humanize_label(c))}: {escape(_cell_text(row.get(c)))}" for c in obs_cols]
            detail = " | ".join(extra_dims + metric_parts + obs_parts)
            lines.append(f"• <code>{period_val}</code> — {detail}")
    else:  # comparison
        for row in rows:
            dim_text = " — ".join(escape(_cell_text(row.get(c))) for c in dim_cols) if dim_cols else "Kelompok"
            metric_parts = [f"{escape(humanize_label(c))}: <b>{escape(_cell_text(row.get(c)))}</b>" for c in metric_cols]
            obs_parts = [f"{escape(humanize_label(c))}: {escape(_cell_text(row.get(c)))}" for c in obs_cols]
            lines.append(f"• <b>{dim_text}</b>")
            lines.append(f"  {' | '.join(metric_parts + obs_parts)}")

    analysis = outputs.get("analysis") or {}
    interp = _safe_analysis_text(analysis, "interpretation")
    rec = _safe_analysis_text(analysis, "recommendation")
    if interp:
        lines.append("")
        lines.append("<b>Interpretasi</b>")
        lines.append(escape(interp))
    if rec:
        lines.append("")
        lines.append("<b>Rekomendasi</b>")
        lines.append(escape(rec))

    return {
        "html": "\n".join(lines),
        "report_type": report_type,
        "title": title,
        "has_data": True,
        "is_clarify": False,
    }


def _load_font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    path = (
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
        if bold
        else "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
    )
    try:
        return ImageFont.truetype(path, size)
    except OSError:
        return ImageFont.load_default()


def _truncate_label(text: str, max_chars: int = 22) -> str:
    return text if len(text) <= max_chars else text[: max_chars - 1] + "…"


def generate_chart_png(
    rows: list[dict],
    *,
    report_type: str,
    title: str,
    period: str,
    evaluation_type: str = "umkm",
) -> bytes | None:
    """Deterministically render a PNG chart from SQL rows using Pillow."""
    if not rows or report_type in {"empty", "clarify"}:
        return None
    dim_cols, metric_cols, _ = _classify_columns(rows)
    if not metric_cols:
        return None

    plot_rows = rows[:10]
    plot_metrics = metric_cols[:4]

    width = 920
    header_h = 84
    footer_h = 36

    font_title = _load_font(22, bold=True)
    font_sub = _load_font(13, bold=False)
    font_label = _load_font(13, bold=False)
    font_val = _load_font(12, bold=True)
    font_small = _load_font(11, bold=False)

    if report_type == "summary":
        # Vertical bar chart of summary metrics
        height = 420
        img = Image.new("RGB", (width, height), (255, 255, 255))
        draw = ImageDraw.Draw(img)
        _draw_header(draw, width, title, f"{SYNTHETIC_LABEL}  •  Periode: {period}", font_title, font_sub)
        row = plot_rows[0]
        items = [(humanize_label(m), _to_float(row.get(m)), _cell_text(row.get(m))) for m in plot_metrics]
        items = [(lbl, val, raw) for lbl, val, raw in items if val is not None]
        if not items:
            return None
        _draw_vertical_bars(draw, width, header_h + 18, height - footer_h - 46, items, font_label, font_val, font_small)
        _draw_footer(draw, width, height, SYNTHETIC_LABEL, font_small)
    elif report_type == "trend" and len(plot_rows) >= 2:
        height = 460
        img = Image.new("RGB", (width, height), (255, 255, 255))
        draw = ImageDraw.Draw(img)
        _draw_header(draw, width, title, f"{SYNTHETIC_LABEL}  •  Periode: {period}", font_title, font_sub)
        x_labels = [_cell_text(r.get(dim_cols[0])) if dim_cols else str(i + 1) for i, r in enumerate(plot_rows)]
        series = []
        for m in plot_metrics[:3]:
            pts = [(_to_float(r.get(m)), _cell_text(r.get(m))) for r in plot_rows]
            series.append((humanize_label(m), pts))
        _draw_line_chart(draw, width, header_h + 24, height - footer_h - 54, x_labels, series, font_label, font_val, font_small)
        _draw_footer(draw, width, height, SYNTHETIC_LABEL, font_small)
    else:
        # Horizontal bar chart (single or grouped for ranking / comparison)
        n_groups = len(plot_rows)
        n_metrics = len(plot_metrics)
        bar_h = 22 if n_metrics == 1 else 15
        group_h = n_metrics * (bar_h + 4) + 18
        legend_h = 28 if n_metrics > 1 else 0
        height = max(320, header_h + legend_h + n_groups * group_h + footer_h + 36)
        img = Image.new("RGB", (width, height), (255, 255, 255))
        draw = ImageDraw.Draw(img)
        _draw_header(draw, width, title, f"{SYNTHETIC_LABEL}  •  Periode: {period}", font_title, font_sub)
        y_start = header_h + 10
        if n_metrics > 1:
            _draw_legend(draw, 40, y_start, [humanize_label(m) for m in plot_metrics], font_small)
            y_start += legend_h
        labels = [
            _truncate_label(" — ".join(_cell_text(r.get(c)) for c in dim_cols) if dim_cols else f"Baris {i+1}")
            for i, r in enumerate(plot_rows)
        ]
        all_vals = [
            v for r in plot_rows for m in plot_metrics
            if (v := _to_float(r.get(m))) is not None
        ]
        if not all_vals:
            return None
        max_val = max(all_vals)
        if max_val <= 0:
            max_val = 1.0
        left_x = 220
        right_x = width - 85
        chart_w = right_x - left_x
        # Gridlines
        for step in range(5):
            gx = left_x + int(chart_w * step / 4)
            draw.line([(gx, y_start), (gx, height - footer_h - 18)], fill=(229, 231, 235), width=1)
            tick = max_val * step / 4
            draw.text((gx - 12, height - footer_h - 14), f"{tick:.1f}", fill=(107, 114, 128), font=font_small)

        cur_y = y_start + 6
        for idx, row in enumerate(plot_rows):
            label_y = cur_y + (n_metrics * (bar_h + 4)) // 2 - 8
            draw.text((28, label_y), labels[idx], fill=(31, 41, 55), font=font_label)
            for m_idx, m in enumerate(plot_metrics):
                val = _to_float(row.get(m))
                raw_txt = _cell_text(row.get(m))
                by = cur_y + m_idx * (bar_h + 4)
                if val is not None:
                    bw = max(2, int((val / max_val) * chart_w))
                    color = _PALETTE[m_idx % len(_PALETTE)]
                    draw.rounded_rectangle([left_x, by, left_x + bw, by + bar_h], radius=3, fill=color)
                    draw.text((left_x + bw + 6, by + 2), raw_txt, fill=(17, 24, 39), font=font_val)
            cur_y += group_h
        _draw_footer(draw, width, height, SYNTHETIC_LABEL, font_small)

    buf = io.BytesIO()
    img.save(buf, format="PNG", optimize=True)
    return buf.getvalue()


def _draw_header(draw: ImageDraw.ImageDraw, width: int, title: str, subtitle: str, font_title, font_sub) -> None:
    draw.rectangle([0, 0, width, 76], fill=(15, 23, 42))
    draw.text((28, 16), _truncate_label(title, 54), fill=(255, 255, 255), font=font_title)
    draw.text((28, 48), _truncate_label(subtitle, 90), fill=(148, 163, 184), font=font_sub)


def _draw_footer(draw: ImageDraw.ImageDraw, width: int, height: int, text: str, font_small) -> None:
    draw.line([(24, height - 28), (width - 24, height - 28)], fill=(229, 231, 235), width=1)
    draw.text((28, height - 21), f"{text} • Dihasilkan deterministik dari hasil SQL", fill=(107, 114, 128), font=font_small)


def _draw_legend(draw: ImageDraw.ImageDraw, x: int, y: int, labels: list[str], font_small) -> None:
    cur_x = x
    for i, lbl in enumerate(labels):
        color = _PALETTE[i % len(_PALETTE)]
        draw.rectangle([cur_x, y + 3, cur_x + 12, y + 15], fill=color)
        draw.text((cur_x + 17, y + 2), lbl, fill=(55, 65, 81), font=font_small)
        cur_x += max(110, len(lbl) * 7 + 34)


def _draw_vertical_bars(draw, width, top_y, bottom_y, items, font_label, font_val, font_small) -> None:
    left_x, right_x = 70, width - 40
    chart_h = bottom_y - top_y
    max_val = max(v for _, v, _ in items) or 1.0
    for step in range(5):
        gy = bottom_y - int(chart_h * step / 4)
        draw.line([(left_x, gy), (right_x, gy)], fill=(229, 231, 235), width=1)
        draw.text((16, gy - 7), f"{max_val * step / 4:.1f}", fill=(107, 114, 128), font=font_small)
    n = len(items)
    slot_w = (right_x - left_x) // max(1, n)
    bar_w = min(90, max(36, slot_w - 36))
    for i, (lbl, val, raw_txt) in enumerate(items):
        cx = left_x + i * slot_w + slot_w // 2
        bh = max(4, int((val / max_val) * (chart_h - 24)))
        x0, x1 = cx - bar_w // 2, cx + bar_w // 2
        y0 = bottom_y - bh
        draw.rounded_rectangle([x0, y0, x1, bottom_y], radius=4, fill=_PALETTE[i % len(_PALETTE)])
        draw.text((x0 + 4, y0 - 18), raw_txt, fill=(17, 24, 39), font=font_val)
        draw.text((max(left_x, cx - len(lbl) * 3), bottom_y + 8), _truncate_label(lbl, 18), fill=(31, 41, 55), font=font_label)


def _draw_line_chart(draw, width, top_y, bottom_y, x_labels, series, font_label, font_val, font_small) -> None:
    left_x, right_x = 74, width - 44
    if len(series) > 1:
        _draw_legend(draw, left_x, top_y - 16, [name for name, _ in series], font_small)
        top_y += 14
    chart_h = bottom_y - top_y
    chart_w = right_x - left_x
    all_vals = [v for _, pts in series for v, _ in pts if v is not None]
    if not all_vals:
        return
    min_v = min(all_vals)
    max_v = max(all_vals)
    pad = (max_v - min_v) * 0.15 if max_v > min_v else max(1.0, abs(max_v) * 0.1)
    lo, hi = max(0.0, min_v - pad), max_v + pad
    span = hi - lo if hi > lo else 1.0

    for step in range(5):
        gy = bottom_y - int(chart_h * step / 4)
        draw.line([(left_x, gy), (right_x, gy)], fill=(229, 231, 235), width=1)
        draw.text((14, gy - 7), f"{lo + span * step / 4:.1f}", fill=(107, 114, 128), font=font_small)

    n = len(x_labels)
    xs = [left_x + int(chart_w * i / max(1, n - 1)) for i in range(n)]
    for i, xl in enumerate(x_labels):
        draw.text((xs[i] - 24, bottom_y + 10), _truncate_label(xl, 10), fill=(55, 65, 81), font=font_small)

    for s_idx, (_, pts) in enumerate(series):
        color = _PALETTE[s_idx % len(_PALETTE)]
        coords = []
        for i, (val, raw_txt) in enumerate(pts):
            if val is not None:
                py = bottom_y - int(((val - lo) / span) * chart_h)
                coords.append((xs[i], py, raw_txt))
        for a, b in zip(coords, coords[1:]):
            draw.line([(a[0], a[1]), (b[0], b[1])], fill=color, width=3)
        for px, py, raw_txt in coords:
            draw.ellipse([px - 4, py - 4, px + 4, py + 4], fill=color, outline=(255, 255, 255), width=1)
            if len(series) == 1:
                draw.text((px - 14, py - 18), raw_txt, fill=(17, 24, 39), font=font_val)
