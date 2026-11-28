"""Read-only Hermes runtime canary for JoyUMKM (no model, no writes, no outbound).

Run inside the live container, as the Hermes user, with the script on stdin:

    docker exec -i -u hermes -e CHECK_OVERLAY=<comma paths> -e EXPECT_HMAC_SHA256=<hex> hermes-agent python3 - < canary.py

Prints exactly one JSON object. It never prints credentials, secrets, recipients, payloads or full config values;
only booleans, counts, key names, file hashes and the handful of non-secret flags named in FLAG_KEYS.
"""
import glob
import hashlib
import json
import os
import re
import stat
import sys

sys.path.insert(0, "/opt/data/plugins/visual-workflow")

DATA = "/opt/data"
JMI = DATA + "/joyumkm-intelligence"
VIEWS = ("analytics_umkms", "analytics_evaluations", "analytics_six_factor_evaluations")
FORBIDDEN_COLUMNS = re.compile(r"(?i)^(user_id|email|phone|contact.*|password.*|token.*|secret.*)$")
FLAG_KEYS = ("JMI_EMAIL_DELIVERY_ENABLED", "JMI_EMAIL_DELIVERY_PAUSED", "JMI_EMAIL_STATUS_SYNC_ENABLED",
             "JMI_EMAIL_STATUS_SYNC_PAUSED", "JMI_DEV_OPERATION_MODE")
out = {"errors": []}


def sha(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def safe(label, fn):
    try:
        fn()
    except Exception as exc:  # report the class only, never the message (it can echo credentials)
        out["errors"].append(label + ":" + type(exc).__name__)


def analytics():
    from visual_workflow_hermes import mysql as m

    pymysql, _ = m._dependencies()
    settings = m._credential(m.CREDENTIAL_REF)
    cfg = {"host_is_container_gateway": settings["host"] == "host.docker.internal", "port": settings["port"],
           "ca_present": os.path.isfile(settings["ssl"]["ca"]), "ssl_verify_identity": bool(settings["ssl"]["check_hostname"])}
    conn = pymysql.connect(**settings, autocommit=True, charset="utf8mb4", connect_timeout=3, read_timeout=6, write_timeout=3)
    try:
        cur = conn.cursor()
        cur.execute("SELECT DATABASE(), CURRENT_USER()")
        db, user = cur.fetchone()
        cur.execute("SHOW STATUS LIKE 'Ssl_cipher'")
        row = cur.fetchone()
        cfg.update({"database": db, "principal": user, "tls_active": bool(row and row[1])})
        counts, columns_ok = {}, True
        for view in VIEWS:
            cur.execute("SELECT COUNT(*) FROM `%s`" % view)
            counts[view] = cur.fetchone()[0]
            cur.execute("SELECT * FROM `%s` LIMIT 0" % view)
            if any(FORBIDDEN_COLUMNS.match(c[0]) for c in cur.description):
                columns_ok = False
        cur.execute("SHOW GRANTS FOR CURRENT_USER()")
        grants = [g[0] for g in cur.fetchall()]
        objects = []
        for g in grants:
            match = re.match(r"GRANT (.+?) ON (.+?) TO ", g)
            if match and match.group(1) != "USAGE":
                objects.append((match.group(1), match.group(2).replace("`", "")))
        cfg["counts"] = counts
        cfg["no_identity_columns"] = columns_ok
        cfg["grants"] = ["%s ON %s" % o for o in objects]
        cfg["grants_views_only"] = bool(objects) and all(
            o[0] == "SELECT" and o[1] in ["%s.%s" % (db, v) for v in VIEWS] for o in objects)
    finally:
        conn.close()
    out["analytics"] = cfg


def files():
    st = os.stat(DATA + "/joyumkm-analytics/mysql.json")
    out["mysql_json"] = {"mode": oct(stat.S_IMODE(st.st_mode))[-3:], "uid": st.st_uid}
    out["workflows"] = {os.path.basename(p): sha(p) for p in sorted(glob.glob(DATA + "/workflow-designs/*.json"))}
    jmi = {}
    for p in sorted(glob.glob(JMI + "/jmi/**/*", recursive=True)):
        if os.path.isfile(p) and "__pycache__" not in p and not p.endswith("callback_registry.json"):
            jmi[os.path.relpath(p, JMI + "/jmi").replace("\\", "/")] = sha(p)
    out["jmi"] = jmi
    overlay = {}
    for rel in filter(None, os.environ.get("CHECK_OVERLAY", "").split(",")):
        p = DATA + "/plugins/visual-workflow/" + rel
        overlay[rel] = sha(p) if os.path.isfile(p) else None
    out["overlay"] = overlay
    out["plugin_version"] = re.search(r'version:\s*"?([\d.]+)', open(DATA + "/plugins/visual-workflow/plugin.yaml").read()).group(1)


def state():
    flags = {}
    for p in glob.glob(JMI + "/state/*.env"):
        for line in open(p, encoding="utf-8", errors="ignore"):
            k, _, v = line.strip().partition("=")
            if k in FLAG_KEYS:
                flags[k] = v.strip('"')
    out["flags"] = flags
    jobs = json.load(open(DATA + "/cron/jobs.json"))
    jobs = jobs["jobs"] if isinstance(jobs, dict) else jobs
    out["cron"] = [{"name": j.get("name"), "enabled": j.get("enabled"), "state": j.get("state")} for j in jobs]
    pins = [p for d in (JMI + "/state", DATA + "/joyumkm-intelligence-prod/state") for p in glob.glob(d + "/uat-scope.json")]
    out["uat_pin_active"] = bool(pins)
    subs = json.load(open(DATA + "/webhook_subscriptions.json"))
    want = os.environ.get("EXPECT_HMAC_SHA256", "")
    out["webhook_routes"] = {name: {"events": v.get("events"), "enabled": v.get("enabled"),
                                    "hmac_matches_laravel": bool(want) and hashlib.sha256(str(v.get("secret", "")).encode()).hexdigest() == want}
                             for name, v in subs.items()}
    onboarding = json.load(open(DATA + "/joyumkm-onboarding/settings.json"))
    out["onboarding"] = {"enabled": onboarding.get("enabled"), "mode": onboarding.get("mode")}


safe("analytics", analytics)
safe("files", files)
safe("state", state)
print(json.dumps(out, sort_keys=True))
