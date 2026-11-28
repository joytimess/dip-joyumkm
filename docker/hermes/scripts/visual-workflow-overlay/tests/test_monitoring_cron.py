import json

import pytest

from visual_workflow_hermes import runner
from visual_workflow_hermes.store import RunStore


@pytest.fixture
def monitoring_design(tmp_path):
    design_store = tmp_path / "workflow-designs"
    design_store.mkdir()
    nodes = [
        {"id": stage, "monitoring_stage": stage, "type": kind, "after": after, **options}
        for stage, kind, after, options in [
            ("schedule", "trigger", [], {"trigger_mode": "schedule", "schedule": "*/10 * * * *",
                                        "timezone": "Asia/Jakarta"}),
            ("http", "transform", ["schedule"], {"preset": "umkm-monitoring"}),
            ("edit", "transform", ["http"], {"preset": "umkm-monitoring"}),
            ("filter", "transform", ["edit"], {"preset": "umkm-monitoring"}),
            ("if", "condition", ["filter"], {"expression": "out.filter.total_skor < 13"}),
            ("telegram", "output", ["if"], {}),
            ("log", "output", ["if"], {}),
        ]
    ]
    design_path = design_store / "umkm-monitoring.json"
    design_path.write_text(json.dumps({"name": "umkm-monitoring", "nodes": nodes}), encoding="utf-8")
    return design_path


@pytest.mark.parametrize("delivery_failures", [0, 1])
def test_cron_runs_the_saved_plan_without_spawn_and_reports_delivery_failure(
    tmp_path, monkeypatch, monitoring_design, delivery_failures,
):
    from umkm_monitoring_cron import run_monitoring

    def fake_run(plan, state, *, on_step):
        assert [step["id"] for step in plan["steps"]][-2:] == ["telegram", "log"]
        assert state["trigger"] == {"type": "schedule", "execution_mode": "no_agent"}
        assert RunStore(tmp_path / "workflow-runs").load(state["run_id"])["status"] == "running"
        state["outputs"]["telegram"] = {"summary": {"failed": delivery_failures},
                                    "fixture_detail": "should never appear on stdout"}
        state["status"] = "done"
        on_step(state)
        return state

    monkeypatch.setattr(runner, "run_plan", fake_run)
    result = run_monitoring(tmp_path)
    assert RunStore(tmp_path / "workflow-runs").load(result["run_id"])["status"] == "done"
    assert result == {
        "workflow": "umkm-monitoring", "run_id": result["run_id"],
        "status": "failed" if delivery_failures else "done", "failed_deliveries": delivery_failures,
    }


def test_cron_rejects_a_replaced_design_before_running_any_node(tmp_path, monitoring_design):
    from umkm_monitoring_cron import run_monitoring

    design = json.loads(monitoring_design.read_text(encoding="utf-8"))
    design["nodes"][1]["type"] = "agent"
    monitoring_design.write_text(json.dumps(design), encoding="utf-8")
    with pytest.raises(ValueError, match="deterministic monitoring"):
        run_monitoring(tmp_path)
    assert not (tmp_path / "workflow-runs").exists()
