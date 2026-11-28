import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from visual_workflow_hermes.convert import build_run_plan
from visual_workflow_hermes.schema import validate_designer_graph


class PredictionTest(unittest.TestCase):
    def test_webhook_stores_the_selected_native_response(self):
        from visual_workflow_hermes.prediction import FACTORS, execute
        from visual_workflow_hermes.store import RunStore

        design = Path(__file__).resolve().parents[1] / "examples" / "umkm-predict.designer.json"
        graph = json.loads(design.read_text(encoding="utf-8"))
        self.assertEqual(validate_designer_graph(graph), [])
        self.assertEqual(len(build_run_plan(graph)["steps"]), 5)
        with TemporaryDirectory() as directory:
            runs = RunStore(Path(directory))
            for body, status, selected, skipped in (
                (dict(zip(FACTORS, (5, 4, 5, 5, 4, 5))), 200, "ok", "invalid"),
                (dict.fromkeys(FACTORS, True), 400, "invalid", "ok"),
                (dict.fromkeys(FACTORS, 1.0), 400, "invalid", "ok"),
            ):
                state = execute(graph, json.dumps(body).encode(), "test", runs)
                self.assertEqual(state, runs.load(state["run_id"]))
                self.assertEqual(state["http_status"], status)
                self.assertEqual(state["skipped"], [skipped])
                self.assertEqual(state["outputs"][selected]["status_code"], status)
                self.assertNotIn(skipped, state["outputs"])
                self.assertEqual(state["node_records"][skipped]["status"], "skipped")
                self.assertEqual(set(state["node_records"]), {"webhook", "validate", "condition", "ok", "invalid"})
                if status == 200:
                    self.assertEqual(state["outputs"][selected]["body"]["category"], "Sangat Sehat")


if __name__ == "__main__":
    unittest.main()
