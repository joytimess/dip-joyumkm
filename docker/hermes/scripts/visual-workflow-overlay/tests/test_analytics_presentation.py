"""Targeted tests for the umkm-analytics-assistant presentation layer."""
from __future__ import annotations

import io
from unittest import TestCase
from PIL import Image

from visual_workflow_hermes.analytics_presentation import (
    NO_DATA_TEXT,
    SYNTHETIC_LABEL,
    detect_report_type,
    format_html_report,
    generate_chart_png,
    humanize_label,
)
from visual_workflow_hermes.analytics_workflow import (
    _validate_plan,
    build_presentation,
    filter_result,
)


def _intent(**changes):
    return {
        "metric": "Rata-rata skor",
        "evaluation_type": "three_dimension",
        "filters": "",
        "period": "2025-11 sampai 2026-10",
        "group_by": "sektor",
        "sort": "sektor ASC",
        "limit": 200,
        "clarification_needed": False,
        **changes,
    }


class AnalyticsPresentationTest(TestCase):
    def test_humanize_label_fixes_underscores_without_question_marks(self):
        self.assertEqual(humanize_label("rata_ins"), "Rata-rata INS")
        self.assertEqual(humanize_label("rata_ect"), "Rata-rata ECT")
        self.assertEqual(humanize_label("rata_ops"), "Rata-rata OPS")
        self.assertEqual(humanize_label("rata_rata"), "Rata-rata")
        self.assertNotIn("?", humanize_label("rata_ins"))
        self.assertNotIn("?", humanize_label("rata_rata"))

    def test_comparison_report_html_and_png(self):
        rows = [
            {"sektor": "Fashion & Kriya", "observasi": 225, "rata_ins": "50.19", "rata_ect": "49.81", "rata_ops": "50.22"},
            {"sektor": "Pertanian", "observasi": 144, "rata_ins": "53.76", "rata_ect": "53.08", "rata_ops": "53.91"},
        ]
        state = {"outputs": {
            "plan": {"intent": _intent()},
            "mysql": {"rows": rows, "row_count": 2, "truncated": False},
        }}
        filter_result({"id": "filter", "mysql_input": "mysql", "plan_input": "plan"}, state)
        state["outputs"]["analysis"] = {
            "interpretation": "Pertanian unggul pada ketiga dimensi.",
            "recommendation": "Tinjau praktik sektor Pertanian.",
        }
        pres = build_presentation(state["outputs"])
        self.assertEqual(pres["report_type"], "comparison")
        self.assertIn(SYNTHETIC_LABEL, pres["text"])
        self.assertIn("<b>Data Faktual (SQL)</b>", pres["text"])
        self.assertIn("<b>Interpretasi</b>", pres["text"])
        self.assertIn("<b>Rekomendasi</b>", pres["text"])
        self.assertIn("Fashion &amp; Kriya", pres["text"])
        self.assertIn("Rata-rata INS", pres["text"])
        self.assertNotIn("rata?ins", pres["text"])
        self.assertIsNotNone(pres["chart_png"])
        img = Image.open(io.BytesIO(pres["chart_png"]))
        self.assertEqual(img.format, "PNG")
        self.assertGreater(img.width, 400)

    def test_ranking_report_html_and_png(self):
        rows = [
            {"kota": "Malang", "observasi": 40, "rata_total": "24.50"},
            {"kota": "Surabaya", "observasi": 35, "rata_total": "22.10"},
            {"kota": "Bandung", "observasi": 30, "rata_total": "20.80"},
        ]
        state = {"outputs": {
            "plan": {"intent": _intent(evaluation_type="six_factor", group_by="kota", sort="DESC", limit=5)},
            "mysql": {"rows": rows, "row_count": 3, "truncated": False},
        }}
        filter_result({"id": "filter", "mysql_input": "mysql", "plan_input": "plan"}, state)
        pres = build_presentation(state["outputs"])
        self.assertEqual(pres["report_type"], "ranking")
        self.assertIn("1. <b>Malang</b>", pres["text"])
        self.assertIn("24.50", pres["text"])
        self.assertIsNotNone(pres["chart_png"])
        self.assertEqual(Image.open(io.BytesIO(pres["chart_png"])).format, "PNG")

    def test_trend_report_html_and_png(self):
        rows = [
            {"period_month": "2026-08", "observasi": 100, "rata_rata": "51.20"},
            {"period_month": "2026-09", "observasi": 105, "rata_rata": "52.40"},
            {"period_month": "2026-10", "observasi": 110, "rata_rata": "53.10"},
        ]
        state = {"outputs": {
            "plan": {"intent": _intent(group_by="period_month", sort="period_month ASC")},
            "mysql": {"rows": rows, "row_count": 3, "truncated": False},
        }}
        filter_result({"id": "filter", "mysql_input": "mysql", "plan_input": "plan"}, state)
        pres = build_presentation(state["outputs"])
        self.assertEqual(pres["report_type"], "trend")
        self.assertIn("<code>2026-08</code>", pres["text"])
        self.assertIn("Rata-rata", pres["text"])
        self.assertIsNotNone(pres["chart_png"])
        self.assertEqual(Image.open(io.BytesIO(pres["chart_png"])).format, "PNG")

    def test_summary_ungrouped_aggregation_html_and_png(self):
        # Validate ungrouped aggregation with group_by="" and limit=0 normalized to 1
        plan = _validate_plan({
            "sql": "SELECT COUNT(*) AS observasi, ROUND(AVG(ins_score), 2) AS rata_ins, ROUND(AVG(ect_score), 2) AS rata_ect, ROUND(AVG(ops_score), 2) AS rata_ops FROM analytics_evaluations",
            "params": [],
            "intent": _intent(group_by="", sort="", limit=0),
        })
        self.assertEqual(plan["intent"]["limit"], 1)
        rows = [{"observasi": 1284, "rata_ins": "49.88", "rata_ect": "49.62", "rata_ops": "49.94"}]
        state = {"outputs": {
            "plan": plan,
            "mysql": {"rows": rows, "row_count": 1, "truncated": False},
        }}
        filter_result({"id": "filter", "mysql_input": "mysql", "plan_input": "plan"}, state)
        pres = build_presentation(state["outputs"])
        self.assertEqual(pres["report_type"], "summary")
        self.assertIn("Ringkasan Agregasi", pres["text"])
        self.assertIn("49.88", pres["text"])
        self.assertIn(SYNTHETIC_LABEL, pres["text"])
        self.assertIsNotNone(pres["chart_png"])
        self.assertEqual(Image.open(io.BytesIO(pres["chart_png"])).format, "PNG")

    def test_empty_result_preserves_synthetic_label_and_no_chart(self):
        state = {"outputs": {
            "plan": {"intent": _intent(period="2026-11")},
            "mysql": {"rows": [{"observasi": 0, "rata_ins": None}], "row_count": 1, "truncated": False},
        }}
        filter_result({"id": "filter", "mysql_input": "mysql", "plan_input": "plan"}, state)
        pres = build_presentation(state["outputs"])
        self.assertEqual(pres["report_type"], "empty")
        self.assertIn(SYNTHETIC_LABEL, pres["text"])
        self.assertIn(NO_DATA_TEXT, pres["text"])
        self.assertIsNone(pres["chart_png"])

    def test_invented_number_in_analysis_is_filtered_out(self):
        rows = [{"kota": "Malang", "observasi": 2, "rata_total": "17.47"}]
        state = {"outputs": {
            "plan": {"intent": _intent(evaluation_type="six_factor")},
            "mysql": {"rows": rows, "row_count": 1, "truncated": False},
        }}
        filter_result({"id": "filter", "mysql_input": "mysql", "plan_input": "plan"}, state)
        state["outputs"]["analysis"] = {
            "interpretation": "Nilai rata-rata 20.",
            "recommendation": "Tinjau tren bulanan.",
        }
        report = format_html_report(state["outputs"])
        self.assertIn("17.47", report["html"])
        self.assertNotIn("Nilai rata-rata 20.", report["html"])
        self.assertIn("Tinjau tren bulanan.", report["html"])
