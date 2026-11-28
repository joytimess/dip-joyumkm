"""Validate designer graphs and the registration workflow contract."""
from __future__ import annotations

import re

from .onboarding import EVENT, TEMPLATE, WORKFLOW
from .monitoring import PRESET as MONITORING_PRESET
from .prediction import PRESET, ROUTE, WORKFLOW as PREDICTION

NODE_KINDS = ("trigger", "agent", "condition", "approval", "output", "transform", "email", "respond", "mysql")
# A sticky note is an annotation for whoever reads the canvas, not a workflow step. It is a
# legal node so the designer JSON round-trips, but it is excluded from everything that makes a
# run: it is not a step kind, it takes no edges in either direction (it has no ports), and the
# converter drops it exactly like a trigger. Its only payload is `text` plus an optional
# `color`; `x`/`y` are the same widget-only layout keys every node carries.
NOTE_KIND = "note"
NOTE_COLORS = ("yellow", "blue", "green", "red")
# Webhook mode is reserved for the signed JoyUMKM receiver.
TRIGGER_MODES = ("manual", "schedule", "webhook", "telegram")
# How an output node hands its payload on: to the platform's home channel ("notification") or
# to an explicit <platform>:<chat> target ("channel"). Absent = collect only.
DELIVER_MODES = ("notification", "channel")
# A paused gate may carry a deadline; without one it waits forever, which the HITL literature
# names as the most common production failure. Three days is the ceiling: longer is a design
# error, not patience.
ON_TIMEOUT = ("reject", "escalate")
MAX_TIMEOUT_MINUTES = 4320
# A transient agent failure is worth re-trying; a step that fails four times is a design defect.
MAX_RETRIES = 3
MAX_NODES = 50

# Conditions must reference upstream outputs in this form:
#   out.<node_id>.<dotted.path> <op> <literal>
# e.g. "out.draft.word_count > 20". Heads other than `out.` are rejected so a broken
# expression fails at design time rather than mid-run. Word operators need whitespace
# around them (`contains`, `startswith`); emptiness is unary (`is [not] empty`).
_WHEN_RE = re.compile(r"^out\.[A-Za-z0-9_.-]+\s*(==|!=|>=|<=|>|<)\s*.+$")
_WORDS_RE = re.compile(r"^out\.[A-Za-z0-9_.-]+\s+(contains|startswith)\s+.+$")
_EMPTY_RE = re.compile(r"^out\.[A-Za-z0-9_.-]+\s+is(\s+not)?\s+empty$")

def _whole_number(value):
    """*value* as an int when it is genuinely whole, else None.

    ``int(2.5)`` truncates and ``int(True)`` is 1, so a plain ``int()`` would silently
    accept a malformed design; bools and non-integral floats are not counts.
    """
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, float):
        return int(value) if value.is_integer() else None
    if isinstance(value, str) and re.fullmatch(r"[+-]?\d+", value.strip()):
        return int(value.strip())
    return None


REQUIRED_FIELDS = {
    "trigger": ("trigger_mode",),
    "agent": ("prompt",),
    "condition": ("expression",),
    "approval": ("question",),
    "output": (),
    "transform": ("preset",),
    "email": ("template",),
    "respond": ("status_code",),
    "mysql": ("credential_ref", "operation"),
}


def validate_designer_graph(graph: dict) -> list[dict]:
    """Return a list of {node, field, msg} defects. Empty list = valid."""
    errors: list[dict] = []
    if not isinstance(graph, dict):
        return [{"node": "", "field": "graph", "msg": "graph must be an object"}]
    nodes = graph.get("nodes", [])
    if not isinstance(nodes, list):
        return [{"node": "", "field": "nodes", "msg": "nodes must be a list"}]
    if not nodes:
        errors.append({"node": "", "field": "nodes", "msg": "workflow needs at least one node"})
        return errors
    if len(nodes) > MAX_NODES:
        errors.append({"node": "", "field": "nodes",
                       "msg": f"design capped at {MAX_NODES} nodes, got {len(nodes)}"})
    # Layout positions x/y are optional and always preserved — the widget round-trips
    # them, the validator ignores them. Never reject on x/y.
    #
    # An id is the graph's identity: every edge and condition names one, so a missing or
    # non-string id is a defect here rather than an ambiguous "node: ''" later. Collecting
    # only valid ids also keeps ``set(ids)`` safe (a list/dict id is unhashable).
    ids: list[str] = []
    for n in nodes:
        if not isinstance(n, dict):
            continue
        node_id = n.get("id")
        if not isinstance(node_id, str) or not node_id.strip():
            errors.append({"node": str(node_id or ""), "field": "id",
                           "msg": "node id must be a non-empty string"})
            continue
        ids.append(node_id)
    if len(set(ids)) != len(ids):
        errors.append({"node": "", "field": "nodes", "msg": "node ids must be unique"})
    note_ids = {n.get("id") for n in nodes
                if isinstance(n, dict) and n.get("type") == NOTE_KIND
                and isinstance(n.get("id"), str)}
    triggers = [n for n in nodes if isinstance(n, dict) and n.get("type") == "trigger"]
    if len(triggers) != 1:
        errors.append({"node": "", "field": "trigger",
                       "msg": f"workflow needs exactly one trigger, found {len(triggers)}"})
    for n in nodes:
        if not isinstance(n, dict):
            errors.append({"node": "", "field": "node", "msg": "node must be an object"})
            continue
        nid = n.get("id") if isinstance(n.get("id"), str) else ""
        kind = n.get("type")
        if kind == NOTE_KIND:
            # Informative only: no required fields, no connectivity, no cycle participation.
            if n.get("after"):
                errors.append({"node": nid, "field": "after",
                               "msg": "a note takes no edges \u2014 it is only information"})
            color = n.get("color")
            if color not in (None, "") \
                    and str(color).strip().lower() not in NOTE_COLORS:
                errors.append({"node": nid, "field": "color",
                               "msg": f"color must be one of {NOTE_COLORS} or empty"})
            continue
        if kind not in NODE_KINDS:
            errors.append({"node": nid, "field": "type",
                           "msg": f"unknown node type {kind!r}, expected one of {NODE_KINDS}"})
            continue
        for field in REQUIRED_FIELDS[kind]:
            if not n.get(field):
                errors.append({"node": nid, "field": field,
                               "msg": f"{kind} node {nid!r} needs non-empty {field!r}"})
        if kind == "trigger" and n.get("trigger_mode") not in TRIGGER_MODES:
            errors.append({"node": nid, "field": "trigger_mode",
                           "msg": f"trigger_mode must be one of {TRIGGER_MODES}"})
        raw_after = n.get("after", []) or []
        if not isinstance(raw_after, list):
            errors.append({"node": nid, "field": "after",
                           "msg": "after must be a list of node ids"})
            raw_after = []
        for dep in raw_after:
            if not isinstance(dep, str):
                errors.append({"node": nid, "field": "after",
                               "msg": f"dependency {dep!r} must be a node id"})
            elif dep in note_ids:
                errors.append({"node": nid, "field": "after",
                               "msg": f"{dep!r} is a note and cannot be a dependency"})
            elif dep not in ids:
                errors.append({"node": nid, "field": "after",
                               "msg": f"unknown dependency {dep!r}"})
            elif dep == nid:
                errors.append({"node": nid, "field": "after",
                               "msg": "node cannot depend on itself"})
        if kind == "mysql":
            if n.get("credential_ref") != "joyumkm-analytics":
                errors.append({"node": nid, "field": "credential_ref", "msg": "unknown MySQL credential reference"})
            operation = n.get("operation")
            if operation not in ("schema", "query"):
                errors.append({"node": nid, "field": "operation", "msg": "operation must be schema or query"})
            elif operation == "query":
                ref = n.get("query_input")
                if ref and (not isinstance(ref, str) or ref not in ids or ref == nid or ref in note_ids):
                    errors.append({"node": nid, "field": "query_input", "msg": "query_input must name an upstream node"})
                if not ref and (not isinstance(n.get("sql"), str) or not n["sql"].strip()):
                    errors.append({"node": nid, "field": "sql", "msg": "provide SQL or an upstream query input"})
                if "params" in n and not isinstance(n["params"], list):
                    errors.append({"node": nid, "field": "params", "msg": "params must be a JSON array"})
            for field, maximum in (("timeout_ms", 5000), ("max_rows", 200)):
                if field in n and (type(n[field]) is not int or not 1 <= n[field] <= maximum):
                    errors.append({"node": nid, "field": field, "msg": f"expected integer 1..{maximum}"})
        if kind == "transform" and n.get("preset") == "analytics_result":
            for field in ("plan_input", "mysql_input"):
                ref = n.get(field)
                if not isinstance(ref, str) or ref not in ids or ref == nid or ref in note_ids:
                    errors.append({"node": nid, "field": field, "msg": "expected an upstream node"})
        if kind == "output":
            deliver = n.get("deliver")
            if deliver not in (None, "") and str(deliver).strip().lower() not in DELIVER_MODES:
                errors.append({"node": nid, "field": "deliver",
                               "msg": f"deliver must be one of {DELIVER_MODES} or empty"})
            elif str(deliver or "").strip().lower() in DELIVER_MODES:
                target = str(n.get("target") or "").strip()
                if not target:
                    errors.append({"node": nid, "field": "target",
                                   "msg": f"deliver={deliver!r} needs a target "
                                          "(e.g. 'telegram' or 'telegram:123456')"})
                elif str(deliver).strip().lower() == "channel" and ":" not in target:
                    errors.append({"node": nid, "field": "target",
                                   "msg": "a channel target needs <platform>:<chat id>"})
        if kind == "agent":
            retries = n.get("retries")
            if retries not in (None, ""):
                count = _whole_number(retries)
                if count is None:
                    errors.append({"node": nid, "field": "retries",
                                   "msg": "retries must be a whole number of extra attempts"})
                elif count < 0 or count > MAX_RETRIES:
                    errors.append({"node": nid, "field": "retries",
                                   "msg": f"retries must be 0..{MAX_RETRIES}"})
        if kind == "approval":
            timeout = n.get("timeout_minutes")
            if timeout not in (None, ""):
                minutes = _whole_number(timeout)
                if minutes is None:
                    errors.append({"node": nid, "field": "timeout_minutes",
                                   "msg": "timeout_minutes must be a whole number of minutes"})
                elif minutes <= 0 or minutes > MAX_TIMEOUT_MINUTES:
                    errors.append({
                        "node": nid, "field": "timeout_minutes",
                        "msg": f"timeout_minutes must be 1..{MAX_TIMEOUT_MINUTES}",
                    })
            policy = n.get("on_timeout")
            if policy not in (None, "") \
                    and str(policy).strip().lower() not in ON_TIMEOUT:
                errors.append({"node": nid, "field": "on_timeout",
                               "msg": f"on_timeout must be one of {ON_TIMEOUT} or empty"})
        if kind == "condition" and n.get("expression"):
            expression = str(n["expression"]).strip()
            if not (_WHEN_RE.match(expression) or _WORDS_RE.match(expression)
                    or _EMPTY_RE.match(expression)):
                errors.append({"node": nid, "field": "expression",
                               "msg": "expression must look like 'out.<node>.<field> <op> "
                                      "<value>' (op: == != > >= < <= contains startswith), "
                                      "'out.<node>.<field> is [not] empty'"})
            else:
                # The expression names an upstream output. A typo is a design defect that
                # would otherwise only surface mid-run; ids may contain dots, so match the
                # longest known id (the runner resolves refs the same way). The split
                # strips symbolic ops, word operators, and the unary emptiness tail.
                target = re.split(r"\s*(?:>=|<=|==|!=|>|<)\s*|\s+(?:contains|startswith)\s+"
                                  r"|\s+is(?:\s+not)?\s+empty$", expression[4:], maxsplit=1)[0]
                ref = next((i for i in sorted(ids, key=len, reverse=True)
                            if target == i or target.startswith(f"{i}.")), None)
                if ref is None:
                    errors.append({"node": nid, "field": "expression",
                                   "msg": f"expression references unknown node in "
                                          f"{target!r}"})
                elif ref in note_ids:
                    errors.append({"node": nid, "field": "expression",
                                   "msg": "a note has no output to test"})
    if not errors:
        # Cycle check (DFS over after-edges). A cycle would hang the runner.
        visiting: set = set()
        done: set = set()

        def _visit(nid, stack):
            if nid in done:
                return None
            if nid in visiting:
                return stack + [nid]
            visiting.add(nid)
            node = next((x for x in nodes if isinstance(x, dict) and x.get("id") == nid), None)
            raw = (node.get("after", []) if node else []) or []
            if not isinstance(raw, list):
                return None
            for dep in raw:
                if not isinstance(dep, str):
                    continue
                cyc = _visit(dep, stack + [nid])
                if cyc:
                    return cyc
            visiting.discard(nid)
            done.add(nid)
            return None

        for nid in ids:
            cyc = _visit(nid, [])
            if cyc:
                errors.append({"node": cyc[0], "field": "after",
                               "msg": f"dependency cycle: {' -> '.join(map(str, cyc))}"})
                break
    if not errors:
        if any("monitoring_stage" in node for node in nodes):
            errors.extend(_monitoring_graph_errors(graph, nodes))
        elif graph.get("name") == PREDICTION or any(
                node["type"] == "respond" or node.get("preset") == PRESET
                or node.get("path") == ROUTE for node in nodes):
            errors.extend(_prediction_errors(graph, nodes))
        elif any(isinstance(node, dict) and node.get("preset") == MONITORING_PRESET for node in nodes):
            errors.extend(_monitoring_errors(graph, nodes))
        else:
            errors.extend(_onboarding_errors(graph, nodes))
    return errors


def _onboarding_errors(graph, nodes):
    steps = [node for node in nodes if node["type"] != NOTE_KIND]
    modes = {node.get("trigger_mode") for node in steps if node["type"] == "trigger"}
    onboarding = "webhook" in modes or any(node["type"] == "email" or (node["type"] == "transform" and node.get("preset") != "analytics_result") for node in steps)
    if not onboarding and graph.get("name") != WORKFLOW:
        return []
    by_kind = {node["type"]: node for node in steps}
    kinds = ("trigger", "transform", "condition", "email")
    valid = (graph.get("name") == WORKFLOW and len(steps) == len(kinds)
             and set(by_kind) == set(kinds) and modes == {"webhook"})
    if valid:
        trigger, transform, condition, email = (by_kind[kind] for kind in kinds)
        valid = (not trigger.get("after") and trigger.get("event_type") == EVENT
                 and transform.get("preset") == "registration"
                 and email.get("template") == TEMPLATE
                 and all(re.fullmatch(r"[A-Za-z0-9_-]+", step["id"]) for step in steps)
                 and all(by_kind[kind].get("after") == [by_kind[parent]["id"]]
                         for parent, kind in zip(kinds, kinds[1:])))
        if valid:
            expression = str(condition["expression"]).strip()
            valid = expression.startswith(f"out.{transform['id']}.")
    return [] if valid else [{"node": "", "field": "onboarding", "msg":
        "Save as joyumkm-user-onboarding and connect Webhook Trigger, Transform Fields, Condition, "
        "and Email Output in order. The condition must read the transform output."}]


def _monitoring_errors(graph, nodes):
    """A monitoring design is one scheduled or manual trigger, one monitoring transform after
    it, and optionally one output that reads the transform. No webhook, no other node kinds."""
    steps = [node for node in nodes if node["type"] != NOTE_KIND]
    kinds = {node["type"] for node in steps}
    valid = kinds <= {"trigger", "transform", "output"} and len(steps) in (2, 3)
    if valid:
        triggers = [node for node in steps if node["type"] == "trigger"]
        transforms = [node for node in steps if node["type"] == "transform"]
        outputs = [node for node in steps if node["type"] == "output"]
        valid = len(triggers) == 1 and len(transforms) == 1 and len(outputs) <= 1
    if valid:
        trigger, transform = triggers[0], transforms[0]
        telegram_target = transform.get("telegram_target")
        target_ok = telegram_target in (None, "") or (
            isinstance(telegram_target, str) and telegram_target.strip() != "")
        valid = (
            trigger.get("trigger_mode") in ("manual", "schedule") and not trigger.get("after")
            and transform.get("preset") == MONITORING_PRESET and transform.get("after") == [trigger["id"]]
            and all(re.fullmatch(r"[A-Za-z0-9_-]+", node["id"]) for node in steps)
            and target_ok
        )
        if valid and outputs:
            valid = outputs[0].get("after") == [transform["id"]]
    return [] if valid else [{"node": "", "field": "monitoring", "msg":
        "Connect a manual or scheduled trigger to one Monitoring transform (umkm-monitoring), "
        "and optionally one Output after it."}]


def _monitoring_graph_errors(graph, nodes):
    expected = {
        "schedule": ("trigger", None),
        "http": ("transform", "schedule"),
        "edit": ("transform", "http"),
        "filter": ("transform", "edit"),
        "if": ("condition", "filter"),
        "telegram": ("output", "if"),
        "log": ("output", "if"),
    }
    steps = [node for node in nodes if node["type"] != NOTE_KIND]
    by_stage = {node["monitoring_stage"]: node for node in steps
                if isinstance(node.get("monitoring_stage"), str)}
    valid = (
        graph.get("name") == MONITORING_PRESET and len(steps) == len(expected)
        and by_stage.keys() == expected.keys()
    )
    if valid:
        for stage, (kind, parent) in expected.items():
            node = by_stage[stage]
            parents = [by_stage[parent]["id"]] if parent else []
            if (node["type"] != kind or not re.fullmatch(r"[A-Za-z0-9_-]+", node["id"])
                    or (node.get("after") or []) != parents
                    or (kind == "transform" and node.get("preset") != MONITORING_PRESET)
                    or any(key in node for key in ("deliver", "target", "telegram_target", "url"))):
                valid = False
                break
        trigger, condition = by_stage["schedule"], by_stage["if"]
        valid = (valid and trigger.get("trigger_mode") == "schedule"
                 and isinstance(trigger.get("schedule"), str) and bool(trigger["schedule"].strip())
                 and trigger.get("timezone") == "Asia/Jakarta"
                 and str(condition.get("expression") or "").strip() == f"out.{by_stage['filter']['id']}.total_skor < 13")
    return [] if valid else [{"node": "", "field": "monitoring", "msg":
        "The monitoring workflow must connect Schedule Trigger, HTTP Request, Edit Fields, "
        "Filter and IF, then branch to Telegram and Log. Telegram uses the configured recipient."}]


def _prediction_errors(graph, nodes):
    steps = [node for node in nodes if node["type"] != NOTE_KIND]
    kinds = ("trigger", "transform", "condition", "respond", "respond")
    by_kind = {kind: [step for step in steps if step["type"] == kind] for kind in set(kinds)}
    valid = (graph.get("name") == PREDICTION and len(steps) == len(kinds)
             and all(len(by_kind[kind]) == kinds.count(kind) for kind in by_kind))
    if valid:
        trigger, = by_kind["trigger"]
        transform, = by_kind["transform"]
        condition, = by_kind["condition"]
        valid = (
            trigger["trigger_mode"] == "webhook" and not trigger.get("after")
            and trigger.get("method") == "POST" and trigger.get("path") == ROUTE
            and trigger.get("synchronous") is True and type(trigger.get("active")) is bool
            and transform.get("preset") == PRESET and transform.get("after") == [trigger["id"]]
            and condition.get("after") == [transform["id"]]
            and condition["expression"].strip() == f"out.{transform['id']}.success == true"
            and all(re.fullmatch(r"[A-Za-z0-9_-]+", node["id"]) for node in steps)
            and all(node.get("after") == [condition["id"]] for node in by_kind["respond"])
            and all(any(node.get("branch") is branch and type(node["status_code"]) is int
                        and node["status_code"] == status for node in by_kind["respond"])
                    for branch, status in ((True, 200), (False, 400)))
        )
    return [] if valid else [{"node": "", "field": "prediction", "msg":
        "Connect the signed POST /predict trigger, Validate & Calculate, Condition, "
        "and the TRUE 200 / FALSE 400 responses in umkm-predict."}]
