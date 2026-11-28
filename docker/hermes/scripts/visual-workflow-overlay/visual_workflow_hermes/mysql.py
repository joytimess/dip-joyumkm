from __future__ import annotations

from datetime import date, datetime, time
from decimal import Decimal
from pathlib import Path
import json
import math
import re
import stat
import sys
from time import monotonic
from zoneinfo import ZoneInfo

CREDENTIAL_REF = "joyumkm-analytics"
BASE = Path("/opt/data/joyumkm-analytics")
VIEWS = {
    "analytics_umkms": "id name sector province city",
    "analytics_evaluations": "id umkm_id ins_score ect_score ops_score average_score submitted_at period_month",
    "analytics_six_factor_evaluations": (
        "id umkm_id sumber_daya kepemimpinan lingkungan_kerja nilai_organisasi stabilitas "
        "kinerja total_score finalized_at period_month"
    ),
}
NODES = frozenset("""
    Select Subquery Column Alias Limit From Join Where Group Order Having Ordered Identifier
    Count Round Literal Table TableAlias EQ NEQ LT LTE GT GTE Between Star Avg Sum Min Max
    Placeholder Distinct And Or Not Paren In Is Null Boolean Neg Abs Add Sub Mul Div Like
""".split())
IDENTIFIER = re.compile(r"[A-Za-z_][A-Za-z0-9_]{0,63}\Z")


class MysqlError(ValueError):
    """Redacted database or validation error."""


def _dependencies():
    directory = str(BASE / "dependencies")
    if directory not in sys.path:
        sys.path.append(directory)
    try:
        import pymysql
        import sqlglot
    except ImportError:
        raise MysqlError("mysql_dependencies_unavailable") from None
    return pymysql, sqlglot


def schema_context():
    return {
        "views": {name: columns.split() for name, columns in VIEWS.items()},
        "rules": [
            "Synthetic cohort only. All assessment records are final.",
            "Join assessment.umkm_id to analytics_umkms.id; no raw history-to-history joins.",
            "Tiga Dimensi scores use 0-100. Six factor scores use 1-5 and total_score uses 6-30.",
            "period_month is YYYY-MM, interpreted in WIB. Latest uses timestamp then id.",
            "Use positional ? for strings and user input. The query limit is at most 200.",
        ],
    }


def _validate_select(select, exp):
    if any(value for key, value in select.args.items()
           if key not in {"expressions", "from", "joins", "where", "group", "having", "order", "limit", "distinct"}):
        raise MysqlError("sql_select_clause_denied")
    source = select.args.get("from")
    if source is None or not isinstance(source.this, exp.Table):
        raise MysqlError("sql_table_required")
    joins = select.args.get("joins") or []
    if len(joins) > 1:
        raise MysqlError("sql_join_limit")
    tables = [source.this, *(join.this for join in joins)]
    aliases = {}
    for table in tables:
        if (not isinstance(table, exp.Table) or not isinstance(table.this, exp.Identifier)
                or table.db or table.catalog or table.name not in VIEWS
                or any(value for key, value in table.args.items() if key not in {"this", "alias"})):
            raise MysqlError("sql_table_denied")
        alias = table.alias_or_name.lower()
        if alias in aliases or (table.args.get("alias") and table.args["alias"].args.get("columns")):
            raise MysqlError("sql_table_alias_denied")
        aliases[alias] = table.name
    if joins:
        join = joins[0]
        on = join.args.get("on")
        if (any(value for key, value in join.args.items() if key not in {"this", "on", "kind"})
                or join.kind not in ("", "INNER")
                or not isinstance(on, exp.EQ)
                or not isinstance(on.this, exp.Column) or not isinstance(on.expression, exp.Column)):
            raise MysqlError("sql_join_denied")
        columns = {(aliases.get(col.table.lower()), col.name.lower()) for col in (on.this, on.expression)}
        expected = {("analytics_umkms", "id")}
        expected.update((table.name, "umkm_id") for table in tables if table.name != "analytics_umkms")
        if columns != expected or len(expected) != 2:
            raise MysqlError("sql_join_key_denied")
    projected = [item.alias.lower() for item in select.expressions if isinstance(item, exp.Alias)]
    if len(set(projected)) != len(projected):
        raise MysqlError("sql_projection_alias_denied")
    for column in select.find_all(exp.Column):
        if column.find_ancestor(exp.Select) is not select:
            continue
        name = column.name.lower()
        if column.db or column.catalog:
            raise MysqlError("sql_column_denied")
        if column.table:
            target = aliases.get(column.table.lower())
            if target and name in VIEWS[target].split():
                continue
        elif sum(name in VIEWS[target].split() for target in aliases.values()) == 1:
            continue
        elif name in projected and any(
            select.args.get(key) is not None and column.find_ancestor(clause) is select.args[key]
            for clause, key in ((exp.Order, "order"), (exp.Having, "having"), (exp.Group, "group"))
        ):
            continue
        raise MysqlError("sql_column_denied")


def validate_query(sql, params, max_rows=200):
    _, sqlglot = _dependencies()
    exp = sqlglot.exp
    if not isinstance(sql, str) or not 1 <= len(sql) <= 8000:
        raise MysqlError("sql_input_invalid")
    if (not isinstance(params, list) or len(params) > 30 or any(
        value is not None and (type(value) not in (str, int, float, bool)
                               or isinstance(value, str) and len(value) > 256
                               or type(value) is float and not math.isfinite(value)
                               or type(value) is int and not -(2**63) <= value < 2**63)
        for value in params
    )):
        raise MysqlError("sql_parameters_invalid")
    if type(max_rows) is not int or not 1 <= max_rows <= 200:
        raise MysqlError("mysql_max_rows_invalid")
    try:
        parts = sqlglot.parse(sql, read="mysql")
    except (sqlglot.errors.SqlglotError, RecursionError):
        raise MysqlError("sql_parse_failed") from None
    if len(parts) != 1 or not isinstance(parts[0], exp.Select):
        raise MysqlError("sql_select_only")
    query = parts[0]
    nodes = list(query.walk())
    if len(nodes) > 180:
        raise MysqlError("sql_complexity_limit")
    selects = list(query.find_all(exp.Select))
    if len(selects) > 2:
        raise MysqlError("sql_subquery_limit")
    for node in nodes:
        if type(node).__name__ not in NODES or node.comments:
            raise MysqlError("sql_expression_denied")
        if isinstance(node, exp.Identifier) and not IDENTIFIER.fullmatch(node.name):
            raise MysqlError("sql_identifier_denied")
        if isinstance(node, exp.Star) and (
            not isinstance(node.parent, exp.Count) or node.parent.this is not node or any(node.args.values())
        ):
            raise MysqlError("sql_star_denied")
        if isinstance(node, exp.Literal) and (
            node.is_string or not re.fullmatch(r"\d{1,12}(?:\.\d{1,8})?", node.this)
        ):
            raise MysqlError("sql_use_bound_parameters")
        if isinstance(node, exp.Placeholder) and node.args:
            raise MysqlError("sql_position_parameters_only")
        if isinstance(node, exp.Subquery) and (
            not isinstance(node.this, exp.Select) or node.alias
            or len(node.this.expressions) != 1
            or node.this.args.get("joins") or node.this.args.get("group")
            or not isinstance(node.this.expressions[0], (exp.Max, exp.Min))
        ):
            raise MysqlError("sql_subquery_denied")
        if isinstance(node, exp.Limit):
            value = node.expression
            if not isinstance(value, exp.Literal) or value.is_string or not value.this.isdigit():
                raise MysqlError("sql_limit_invalid")
    for select in selects:
        _validate_select(select, exp)
    cap = max_rows + 1
    limit = query.args.get("limit")
    if limit is None or int(limit.expression.this) > cap:
        query = query.limit(cap)
    canonical = query.sql(dialect="mysql", comments=False)
    if canonical.count("?") != len(params):
        raise MysqlError("sql_parameter_count_mismatch")
    return canonical


def _json_value(value):
    if isinstance(value, Decimal):
        return str(value)
    if isinstance(value, (date, datetime, time)):
        return value.isoformat()
    return value


def _credential(credential_ref):
    if credential_ref != CREDENTIAL_REF:
        raise MysqlError("mysql_credential_reference_denied")
    path = BASE / "mysql.json"
    try:
        if stat.S_IMODE(path.stat().st_mode) & 0o077:
            raise MysqlError("mysql_credential_permissions")
        cfg = json.loads(path.read_text(encoding="utf-8"))
        return {
            "host": cfg["host"], "port": int(cfg["port"]), "user": cfg["user"],
            "password": cfg["password"], "database": cfg["database"],
            "ssl": {"ca": cfg["ssl_ca"], "verify_mode": True,
                    "check_hostname": cfg.get("ssl_verify_identity", True)},
        }
    except (OSError, KeyError, AttributeError, TypeError, ValueError) as exc:
        if isinstance(exc, MysqlError):
            raise
        raise MysqlError("mysql_credential_unavailable") from None


def execute_query(sql, params, credential_ref=CREDENTIAL_REF, timeout_ms=5000, max_rows=200):
    if type(timeout_ms) is not int or not 1 <= timeout_ms <= 5000:
        raise MysqlError("mysql_timeout_invalid")
    canonical = validate_query(sql, params, max_rows)
    pymysql, _ = _dependencies()
    settings = _credential(credential_ref)
    started = monotonic()
    connection = None
    try:
        connection = pymysql.connect(
            **settings, autocommit=True, charset="utf8mb4",
            connect_timeout=3, read_timeout=timeout_ms / 1000 + 1, write_timeout=3,
            cursorclass=pymysql.cursors.Cursor,
        )
        if not connection._secure:
            raise MysqlError("mysql_tls_required")
        with connection.cursor() as cursor:
            cursor.execute("SET SESSION MAX_EXECUTION_TIME = %s", (timeout_ms,))
            cursor.execute("START TRANSACTION READ ONLY")
            # The canonical AST emits no string literals and uses '?' solely for placeholders.
            cursor.execute(canonical.replace("%", "%%").replace("?", "%s"), tuple(params))
            labels = [column[0] for column in cursor.description]
            if len(set(labels)) != len(labels):
                raise MysqlError("mysql_duplicate_result_columns")
            raw = cursor.fetchmany(max_rows + 1)
            rows = [dict(zip(labels, map(_json_value, row))) for row in raw[:max_rows]]
            return {
                "status": "ok", "rows": rows, "row_count": len(rows),
                "truncated": len(raw) > max_rows, "scope": "synthetic-cohort",
                "duration_ms": round((monotonic() - started) * 1000), "validated_sql": canonical,
            }
    except pymysql.MySQLError as exc:
        code = exc.args[0] if exc.args else None
        raise MysqlError("mysql_timeout" if code in (1205, 3024) else "mysql_query_failed") from None
    except (OSError, TypeError, ValueError) as exc:
        if isinstance(exc, MysqlError):
            raise
        raise MysqlError("mysql_connection_configuration_failed") from None
    finally:
        if connection is not None:
            connection.close()


def run_step(step, outputs):
    if step.get("credential_ref") != CREDENTIAL_REF:
        raise MysqlError("mysql_credential_reference_denied")
    if step.get("operation") == "schema":
        context = schema_context()
        context["current_month_wib"] = datetime.now(ZoneInfo("Asia/Jakarta")).strftime("%Y-%m")
        context["periods"] = {}
        for view in ("analytics_evaluations", "analytics_six_factor_evaluations"):
            result = execute_query(
                f"SELECT MIN(period_month) AS first_period, MAX(period_month) AS last_period FROM {view}",
                [], step["credential_ref"], step.get("timeout_ms", 5000),
            )
            context["periods"][view] = result["rows"][0]
        return context
    if step.get("operation") != "query":
        raise MysqlError("mysql_operation_invalid")
    reference = step.get("query_input")
    query = outputs.get(reference) if reference else step
    if not isinstance(query, dict):
        raise MysqlError("mysql_query_input_invalid")
    intent = query.get("intent")
    if isinstance(intent, dict) and intent.get("clarification_needed") is True:
        return {"status": "clarification_needed", "rows": [], "row_count": 0, "truncated": False}
    return execute_query(query.get("sql"), query.get("params", []), step["credential_ref"],
                         step.get("timeout_ms", 5000), step.get("max_rows", 200))
