"""Deterministic Hermes cron entry for the saved Jobsheet 5 workflow."""

from __future__ import annotations

import json
import sys
import uuid
from pathlib import Path

DESIGN = "umkm-monitoring"


def run_monitoring(hermes_home: Path) -> dict:
    from visual_workflow_hermes.convert import build_run_plan
    from visual_workflow_hermes.runner import new_state, run_plan
    from visual_workflow_hermes.schema import validate_designer_graph
    from visual_workflow_hermes.store import DesignStore, RunStore

    designer = DesignStore(hermes_home / "workflow-designs").load(DESIGN)
    if (
        designer.get("name") != DESIGN
        or validate_designer_graph(designer)
        or not any(node.get("monitoring_stage") == "schedule" for node in designer["nodes"])
    ):
        raise ValueError("expected the deterministic monitoring graph")

    plan = build_run_plan(designer)
    telegram_id = next(step["id"] for step in plan["steps"] if step["monitoring_stage"] == "telegram")
    state = new_state(plan, uuid.uuid4().hex[:12])
    state["trigger"] = {"type": "schedule", "execution_mode": "no_agent"}
    runs = RunStore(hermes_home / "workflow-runs")
    runs.save(state)
    state = run_plan(plan, state, on_step=runs.save)
    failed = state["outputs"].get(telegram_id, {}).get("summary", {}).get("failed", 0)
    return {
        "workflow": DESIGN,
        "run_id": state["run_id"],
        "status": "failed" if failed else state["status"],
        "failed_deliveries": failed,
    }


def main() -> int:
    hermes_home = Path(__file__).resolve().parents[1]
    sys.path.insert(0, str(hermes_home / "plugins" / "visual-workflow"))
    try:
        result = run_monitoring(hermes_home)
    except Exception as exc:
        result = {"workflow": DESIGN, "status": "failed", "error_type": type(exc).__name__}
    # Hermes saves stdout in cron history. Workflow records retain the node details.
    print(json.dumps(result))
    return 0 if result["status"] == "done" else 1


if __name__ == "__main__":
    sys.exit(main())
