from types import SimpleNamespace

import pytest

from visual_workflow_hermes.analytics_workflow import (
    CLARIFY, MODEL, NO_DATA, PROVIDER, TASK, _format_reply, _spawn, _validate_plan, filter_result,
)

def _intent(**changes):
    return {
        "metric": "average", "evaluation_type": "six_factor", "filters": "", "period": "2026-09",
        "group_by": "city", "sort": "descending", "limit": 5, "clarification_needed": False,
        **changes,
    }

def test_ambiguity_discards_generated_sql():
    result = _validate_plan({"sql": "irrelevant", "params": [1], "intent": _intent(clarification_needed=True)})
    assert result["sql"] == ""
    assert result["params"] == []
    assert _format_reply({"plan": result}) == CLARIFY

def test_aggregate_with_zero_observations_is_no_data():
    state = {"outputs": {
        "plan": {"intent": _intent()},
        "query": {"rows": [{"observasi": 0, "score": None}], "row_count": 1, "truncated": False},
    }}
    filter_result({"id": "filter", "mysql_input": "query", "plan_input": "plan"}, state)
    assert _format_reply(state["outputs"]) == NO_DATA

def test_formatting_preserves_sql_decimal_and_ignores_invented_number():
    state = {"outputs": {
        "plan": {"intent": _intent()},
        "query": {"rows": [{"city": "Malang", "score": "17.47000000000001", "observasi": 2}],
                  "row_count": 1, "truncated": False},
    }}
    filter_result({"id": "filter", "mysql_input": "query", "plan_input": "plan"}, state)
    state["outputs"]["analysis"] = {"interpretation": "Nilai rata-rata 20.", "recommendation": "Tinjau tren bulanan."}
    reply = _format_reply(state["outputs"])
    assert "17.47000000000001" in reply
    assert "observasi: 2" in reply
    assert "Nilai rata-rata 20." not in reply
    assert "Tinjau tren bulanan." in reply

@pytest.mark.parametrize("node_id", ["plan", "analysis"])
@pytest.mark.parametrize("provider,model", [("other", "gemini-4-hydrogen"), ("gemini", "other")])
def test_model_fallback_fails_closed(monkeypatch, node_id, provider, model):
    monkeypatch.setattr(
        "visual_workflow_hermes.analytics_workflow._expected_attribution",
        lambda task=TASK: ("gemini", "gemini-4-hydrogen"),
    )
    def structured(**kwargs):
        assert kwargs["task"] == TASK
        assert "question" in kwargs["input"][0]["text"]
        return SimpleNamespace(provider=provider, model=model, parsed={})
    invoke = _spawn(SimpleNamespace(complete_structured=structured), "Berapa rata-ratanya?")
    state = {"run_id": "abcd"}
    outputs = {"schema": {}, "filter": {"rows": []}}
    result = invoke({"id": node_id, "prompt": "use schema", "schema": {}, "timeout": 30}, outputs, state)
    assert result == {"error": "unexpected analytics model attribution"}
    assert state["models"][node_id] == {"provider": provider, "model": model}


@pytest.mark.parametrize(
    "expected_provider,expected_model",
    [("gemini", "gemini-4-hydrogen"), ("openai-codex", "gpt-6-luna")],
)
def test_dynamic_attribution_accepts_configured_provider_and_model(monkeypatch, expected_provider, expected_model):
    monkeypatch.setattr(
        "visual_workflow_hermes.analytics_workflow._expected_attribution",
        lambda task=TASK: (expected_provider, expected_model),
    )
    valid_plan = {"sql": "SELECT 1", "params": [], "intent": _intent()}
    valid_analysis = {"interpretation": "Stabil.", "recommendation": "Pertahankan."}

    def structured(**kwargs):
        purpose = kwargs["purpose"]
        parsed = valid_plan if purpose.endswith(":plan") else valid_analysis
        return SimpleNamespace(provider=expected_provider, model=expected_model, parsed=parsed)

    invoke = _spawn(SimpleNamespace(complete_structured=structured), "Berapa rata-ratanya?")
    state = {"run_id": "abcd"}
    outputs = {"schema": {}, "filter": {"rows": []}}

    plan_res = invoke({"id": "plan", "prompt": "use schema", "schema": {}, "timeout": 30}, outputs, state)
    assert plan_res == {"structured": valid_plan}
    assert state["models"]["plan"] == {"provider": expected_provider, "model": expected_model}

    analysis_res = invoke({"id": "analysis", "prompt": "analyze", "schema": {}, "timeout": 30}, outputs, state)
    assert analysis_res == {"structured": valid_analysis}
    assert state["models"]["analysis"] == {"provider": expected_provider, "model": expected_model}
