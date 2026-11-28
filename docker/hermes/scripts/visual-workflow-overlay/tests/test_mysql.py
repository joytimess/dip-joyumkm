from copy import deepcopy
import json
import os

import pytest

from visual_workflow_hermes.convert import build_run_plan
from visual_workflow_hermes.mysql import MysqlError, execute_query, validate_query
from visual_workflow_hermes.runner import StepError, run_plan
from visual_workflow_hermes.schema import validate_designer_graph


RANKING = """
SELECT u.city, COUNT(*) AS observations, ROUND(AVG(e.total_score), 2) AS score
FROM analytics_six_factor_evaluations e JOIN analytics_umkms u ON e.umkm_id = u.id
WHERE e.period_month BETWEEN ? AND ?
GROUP BY u.city HAVING COUNT(*) > 0 ORDER BY AVG(e.total_score) DESC, u.city LIMIT 5
"""


@pytest.mark.parametrize("query", [
    "UPDATE analytics_umkms SET name = ?",
    "SELECT id FROM analytics_umkms; SELECT id FROM analytics_umkms",
    "SELECT id FROM analytics_umkms FOR UPDATE",
    "SELECT id INTO OUTFILE ? FROM analytics_umkms",
    "SELECT COUNT(*) FROM information_schema.tables",
    "SELECT COUNT(*) FROM mysql.user",
    "SELECT user_id FROM analytics_umkms",
    "SELECT id FROM users",
    "SELECT u.id FROM analytics_umkms u JOIN users s ON s.id=u.id",
    "SELECT u.id FROM analytics_umkms u JOIN analytics_evaluations e ON u.id=e.id",
    "SELECT u.id FROM analytics_umkms u JOIN analytics_evaluations e ON 1=1",
    "SELECT city FROM analytics_umkms WHERE id = (SELECT MAX(id) FROM users)",
    "SELECT city FROM analytics_umkms WHERE id = (SELECT MAX(u.id) FROM analytics_evaluations e)",
    "SELECT SLEEP(1), id FROM analytics_umkms",
    "SELECT BENCHMARK(1000, id) FROM analytics_umkms",
    "SELECT LOAD_FILE(?) FROM analytics_umkms",
    "SELECT @x := id FROM analytics_umkms",
    "SELECT /*+ MAX_EXECUTION_TIME(0) */ id FROM analytics_umkms",
    "SELECT /*!50000 SQL_NO_CACHE */ id FROM analytics_umkms",
    "SELECT id FROM analytics_umkms WHERE id = :value",
    "SELECT id FROM analytics_umkms WHERE city = 'Bandung'",
    "SELECT * FROM analytics_umkms",
])
def test_sql_policy_denies_unapproved_statement(query):
    with pytest.raises(MysqlError):
        validate_query(query, [])


def test_validator_allows_grouped_parameterized_query_and_caps_output():
    query = validate_query(RANKING, ["2026-08", "2026-10"])
    assert query.endswith("LIMIT 5")
    assert query.count("?") == 2
    assert validate_query("SELECT id FROM analytics_umkms", [], 2).endswith("LIMIT 3")
    assert "MAX(period_month)" in validate_query(
        "SELECT id FROM analytics_six_factor_evaluations WHERE period_month = "
        "(SELECT MAX(period_month) FROM analytics_six_factor_evaluations)", [],
    )
    with pytest.raises(MysqlError, match="sql_parameter_count_mismatch"):
        validate_query(RANKING, [])


def test_dashboard_graph_round_trip_and_telegram_mode():
    graph = {"name": "mysql-test", "nodes": [
        {"id": "entry", "type": "trigger", "trigger_mode": "manual"},
        {"id": "sql", "type": "mysql", "after": ["entry"],
         "credential_ref": "joyumkm-analytics", "operation": "query",
         "sql": "SELECT id FROM analytics_umkms WHERE city = ?", "params": ["Bandung"],
         "timeout_ms": 2500, "max_rows": 100},
    ]}
    roundtrip = json.loads(json.dumps(graph))
    assert validate_designer_graph(roundtrip) == []
    step = build_run_plan(roundtrip)["steps"][0]
    assert step["kind"] == "mysql"
    assert step["params"] == ["Bandung"]
    assert (step["timeout_ms"], step["max_rows"]) == (2500, 100)
    telegram = deepcopy(roundtrip)
    telegram["nodes"][0]["trigger_mode"] = "telegram"
    assert validate_designer_graph(telegram) == []
    with pytest.raises(StepError, match="authorized Telegram"):
        run_plan(build_run_plan(telegram), {})


@pytest.mark.skipif(os.environ.get("HERMES_ANALYTICS_TEST_LIVE") != "1", reason="requires scoped QA DB")
def test_live_read_only_and_bound_input():
    actual = execute_query(RANKING, ["2026-08", "2026-10"])
    assert actual["rows"][0] == {"city": "Tangerang Selatan", "observations": 29, "score": "18.76"}
    assert actual["row_count"] == 5 and not actual["truncated"]
    empty = execute_query("SELECT id FROM analytics_umkms WHERE city = ?", ["' OR 1=1 -- "])
    assert empty["rows"] == []
    limited = execute_query("SELECT id FROM analytics_umkms ORDER BY id", [], max_rows=2)
    assert limited["row_count"] == 2 and limited["truncated"]
    with pytest.raises(MysqlError, match="sql_table_denied"):
        execute_query("SELECT id FROM users", [])
