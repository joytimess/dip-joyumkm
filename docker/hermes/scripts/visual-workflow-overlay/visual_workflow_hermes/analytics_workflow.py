from __future__ import annotations

from html import escape
import json
import logging
import re
from uuid import uuid4

from .analytics_presentation import (
    CLARIFY_TEXT,
    NO_DATA_TEXT,
    SYNTHETIC_LABEL,
    format_html_report,
    generate_chart_png,
)
from .runner import StepError, new_state, run_plan
from .spawn import PLUGIN_ID
from .store import DesignStore, RunStore

WORKFLOW = "umkm-analytics-assistant"
TASK = "umkm_analytics"
MODEL = "gpt-6-luna"
PROVIDER = "openai-codex"
FILTER_PRESET = "analytics_result"
NO_DATA = NO_DATA_TEXT
RUN_FAILED = "Analisis belum dapat dijalankan. Coba lagi atau hubungi operator."
CLARIFY = CLARIFY_TEXT
logger = logging.getLogger(__name__)


def register_analytics_task(ctx) -> None:
    ctx.register_auxiliary_task(
        TASK,
        display_name="JoyUMKM Analytics",
        description="Memahami pertanyaan dan menganalisis evaluasi UMKM sintetis",
        defaults={"provider": PROVIDER, "model": MODEL, "timeout": 35},
    )


def _validate_plan(value):
    if not isinstance(value, dict) or set(value) != {"sql", "params", "intent"}:
        raise ValueError("invalid analytics plan")
    intent = value["intent"]
    if not isinstance(intent, dict) or set(intent) != {
        "metric", "evaluation_type", "filters", "period", "group_by", "sort", "limit",
        "clarification_needed",
    }:
        raise ValueError("invalid analytics intent")
    if type(intent["clarification_needed"]) is not bool:
        raise ValueError("invalid analytics clarification flag")
    if intent["clarification_needed"]:
        return {"sql": "", "params": [], "intent": intent}
    if intent["evaluation_type"] not in {"three_dimension", "six_factor", "umkm"}:
        raise ValueError("invalid evaluation type")
    if not isinstance(value["sql"], str) or not value["sql"] or len(value["sql"]) > 4000:
        raise ValueError("invalid SQL field")
    if not isinstance(value["params"], list) or len(value["params"]) > 24:
        raise ValueError("invalid SQL parameters")
    if not all(isinstance(intent[k], str) and len(intent[k]) <= 120
               for k in ("metric", "filters", "period", "group_by", "sort")):
        raise ValueError("invalid intent field")
    if type(intent["limit"]) is not int:
        raise ValueError("invalid intent result limit")
    if intent["limit"] == 0:
        intent = {**intent, "limit": 1}
        value = {**value, "intent": intent}
    if not 1 <= intent["limit"] <= 200:
        raise ValueError("invalid intent result limit")
    return value


def filter_result(step: dict, state: dict) -> None:
    outputs = state["outputs"]
    raw = outputs[step["mysql_input"]]
    rows = raw.get("rows")
    if not isinstance(rows, list) or not all(isinstance(row, dict) for row in rows):
        raise StepError("invalid analytics result")
    # Aggregate before display. Keep complete rows within the provider and Telegram budgets.
    excerpt = []
    for row in rows[:12]:
        if len(json.dumps(excerpt + [row], ensure_ascii=False, separators=(",", ":"))) > 2300:
            break
        excerpt.append(row)
    has_data = any(
        row.get("observasi") != 0 and any(value is not None for value in row.values())
        for row in rows
    )
    if has_data and not excerpt:
        raise StepError("analytics result too large")
    intent = outputs[step["plan_input"]]["intent"]
    outputs[step["id"]] = {
        "rows": excerpt,
        "row_count": raw["row_count"],
        "truncated": bool(raw["truncated"] or len(excerpt) < len(rows)),
        "has_data": has_data,
        "evaluation_type": intent["evaluation_type"],
        "period": _period_label(intent["period"]),
        "intent": intent,
    }


def _period_label(value: str) -> str:
    months = re.findall(r"(?<![0-9])[0-9]{4}-(?:0[1-9]|1[0-2])(?![0-9])", value)
    if len(months) in (1, 2):
        return " sampai ".join(dict.fromkeys(months)) + " (WIB)"
    return "Seluruh periode cohort" if not months else "Lihat periode pada hasil"


def _cell(value):
    return "tidak tersedia" if value is None else str(value).replace("\r", " ").replace("\n", " ")


def _format_reply(outputs: dict) -> str:
    return format_html_report(outputs)["html"]


def build_presentation(outputs: dict) -> dict:
    report = format_html_report(outputs)
    chart_png = None
    caption = ""
    if report["has_data"]:
        data = outputs["filter"]
        period = _cell(data.get("period", "Seluruh periode cohort"))
        eval_type = data.get("evaluation_type", "umkm")
        chart_png = generate_chart_png(
            data.get("rows") or [],
            report_type=report["report_type"],
            title=report["title"],
            period=period,
            evaluation_type=eval_type,
        )
        caption = (
            f"<b>{escape(report['title'])}</b>\n"
            f"<i>{escape(SYNTHETIC_LABEL)}</i>\n"
            f"Periode: {escape(period)}"
        )
    return {
        "text": report["html"],
        "chart_png": chart_png,
        "caption": caption,
        "report_type": report["report_type"],
        "title": report["title"],
        "has_data": report["has_data"],
    }


def _expected_attribution(task: str = TASK) -> tuple[str, str]:
    from agent.auxiliary_client import _resolve_task_provider_model

    provider, model, *_ = _resolve_task_provider_model(task=task)
    provider = (provider or "").strip()
    model = (model or "").strip()
    if not provider or provider.lower() == "auto" or not model:
        raise ValueError("incomplete analytics auxiliary model configuration")
    return provider, model


def _spawn(llm, question: str):
    def invoke(step, outputs, state):
        node_id = step["id"]
        if node_id == "plan":
            data = {"question": question, "schema": outputs["schema"]}
        elif node_id == "analysis":
            data = {"question": question, "dataset": outputs["filter"]}
        else:
            return {"error": "unexpected analytics agent step"}
        try:
            expected_provider, expected_model = _expected_attribution(TASK)
            result = llm.complete_structured(
                task=TASK,
                purpose=f"workflow:{state['run_id']}:{node_id}",
                instructions=step["prompt"],
                input=[{"type": "text", "text": json.dumps(data, ensure_ascii=False)}],
                json_schema=step["schema"],
                max_tokens=1800 if node_id == "plan" else 480,
                timeout=step["timeout"],
            )
            state.setdefault("models", {})[node_id] = {
                "model": result.model,
                "provider": result.provider,
            }
            actual_provider = (result.provider or "").strip()
            actual_model = (result.model or "").strip()
            if actual_model != expected_model or actual_provider != expected_provider:
                return {"error": "unexpected analytics model attribution"}
            value = result.parsed
            if node_id == "plan":
                value = _validate_plan(value)
            elif not isinstance(value, dict):
                return {"error": "invalid analytics interpretation"}
            return {"structured": value}
        except Exception as exc:
            logger.warning("Analytics model step failed (%s, %s)", state["run_id"], type(exc).__name__)
            return {"error": f"analytics model request failed: {type(exc).__name__}"}
    return invoke


def run_question_rich(question: str, correlation_id: str, *, llm=None, source: str = "telegram") -> dict:
    """Run the saved graph and return Telegram HTML text + deterministic PNG chart."""
    from .convert import build_run_plan

    question = question.strip()
    if not 1 <= len(question) <= 1200:
        return {"text": CLARIFY, "chart_png": None, "caption": "", "report_type": "clarify", "has_data": False}
    if llm is None:
        from agent.plugin_llm import PluginLlm
        llm = PluginLlm(plugin_id=PLUGIN_ID)
    design = DesignStore().load(WORKFLOW)
    if design.get("name") != WORKFLOW:
        raise ValueError("invalid saved analytics workflow")
    from .schema import validate_designer_graph
    if validate_designer_graph(design):
        raise ValueError("invalid saved analytics workflow")
    plan = build_run_plan(design)
    state = new_state(plan, uuid4().hex[:12])
    state["correlation_id"] = correlation_id
    state["analytics_source"] = source
    runs = RunStore()
    runs.save(state)
    state = run_plan(plan, state, spawn=_spawn(llm, question), on_step=runs.save)
    if state["status"] != "done":
        return {"text": RUN_FAILED, "chart_png": None, "caption": "", "report_type": "error", "has_data": False}
    presentation = build_presentation(state["outputs"])
    state["outputs"]["reply"] = {
        "text": presentation["text"],
        "parse_mode": "HTML",
        "report_type": presentation["report_type"],
        "has_chart": presentation["chart_png"] is not None,
    }
    runs.save(state)
    return presentation


def run_question(question: str, correlation_id: str, *, llm=None, source: str = "telegram") -> str:
    """Run the saved graph after the Telegram router has authorized the request."""
    return run_question_rich(question, correlation_id, llm=llm, source=source)["text"]
