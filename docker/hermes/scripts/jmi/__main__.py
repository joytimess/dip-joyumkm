"""CLI: python -m jmi {intake|process|status|report|retry|recover|list|harden|callback|callback-status|callback-sweep|callback-migrate|snapshot|snapshot-verify} ..."""
import json
import sys

from . import callback, engine, parse, snapshot, store

USAGE = ("usage: jmi intake|process <id>|status <id>|report <id>|retry <id>|recover [--inline]|list|harden"
         "|callback <id> [--dry-run] [--auto]|callback-status|callback-sweep [--inline]|callback-migrate"
         "|snapshot <id>|snapshot-verify <id>|render-pdf <id>|pdf-verify <id>|render-xlsx <id>|xlsx-verify <id>|build-manifest <id>|manifest-verify <id>"
         "|pipeline-status <id>|pipeline-resume <id>|pipeline-verify <id>|delivery-status <id>|delivery-resume <id>|delivery-verify <id>|email-status <id>|email-status-sync-resume <id>")
CALLBACK_OK = {"delivered", "already_delivered", "archived_unmatched", "dry_run", "skipped_locked", "not_due"}
SNAPSHOT_OK = {"created", "exists", "unsupported"}


def main(argv):
    if len(argv) < 2:
        print(USAGE, file=sys.stderr)
        return 2
    cmd = argv[1]
    arg = argv[2] if len(argv) > 2 and not argv[2].startswith("--") else None
    code = 0
    try:
        if cmd == "intake":
            res = store.intake(sys.stdin.buffer.read())
        elif cmd == "process":
            res = engine.process(arg)
        elif cmd == "status":
            res = store.read_job(store.check_id(arg)) or {"result": "not_found"}
        elif cmd == "report":
            eid = store.check_id(arg)
            res = store.read_report(eid) or {"result": "not_found"}
            if "report" in res and not engine.report_matches_job(eid):
                print(json.dumps({"error": "REPORT_INTEGRITY_FAILED", "event_id": eid}), file=sys.stderr)
                return 3
        elif cmd == "retry":
            res = engine.retry(arg)
            if res.get("result") == "requeued":
                res["processed"] = engine.process(arg)
        elif cmd == "recover":
            res = engine.recover(spawn="--inline" not in argv)
        elif cmd == "list":
            res = [{k: j[k] for k in ("event_id", "umkm_id", "status", "attempts", "error_code")} for j in store.list_jobs()]
        elif cmd == "harden":
            res = {"changed": store.harden_permissions()}
        elif cmd == "callback":
            eid = store.check_id(arg)
            if "--dry-run" in argv:
                res = callback.dry_run(eid)
            else:
                res = callback.send(eid, manual="--auto" not in argv)  # operator runs are explicit; sweep passes --auto
            code = 0 if res.get("result") in CALLBACK_OK else 1
        elif cmd == "callback-status":
            res = callback.status_summary()
        elif cmd == "callback-sweep":
            res = callback.sweep(spawn="--inline" not in argv)
        elif cmd == "callback-migrate":
            res = callback.migrate_all()
        elif cmd in ("render-pdf", "pdf-verify"):
            from . import pdf
            res, code = pdf.command(arg, verify=cmd == "pdf-verify")
        elif cmd in ("render-xlsx", "xlsx-verify"):
            from . import xlsx
            res, code = xlsx.command(arg, verify=cmd == "xlsx-verify")
        elif cmd in ("build-manifest", "manifest-verify"):
            from . import artifacts
            res, code = artifacts.command(arg, verify=cmd == "manifest-verify")
        elif cmd in ("pipeline-status", "pipeline-resume", "pipeline-verify"):
            from . import pipeline
            eid = store.check_id(arg)
            operation = {"pipeline-status": pipeline.status, "pipeline-resume": pipeline.advance,
                         "pipeline-verify": pipeline.verify}[cmd]
            res = operation(eid)
            code = (3 if res.get("error_code") else 0 if cmd == "pipeline-status" or
                    res.get("package_status") == "ready" else 1)
        elif cmd in ("delivery-status", "delivery-resume", "delivery-verify"):
            from . import delivery
            res, code = delivery.command(store.check_id(arg), cmd)
        elif cmd == "email-status-sync-resume":
            from . import email_status_sync
            eid = store.check_id(arg)
            outcome = email_status_sync.run(eid, resume=True)
            res = dict(event_id=eid, status=outcome or "not_resumable")
            code = 0 if outcome == "accepted" else 1
        elif cmd == "email-status":
            from . import email_delivery
            res = email_delivery.status(store.check_id(arg))
            code = 3 if res.get("error_code") and res["status"] == "blocked" else 0
        elif cmd == "snapshot":
            res = snapshot.run(store.check_id(arg))
            code = 0 if res.get("result") in SNAPSHOT_OK else (1 if res.get("retryable") else 3)
        elif cmd == "snapshot-verify":
            res = snapshot.verify(store.check_id(arg))
            code = 0 if res.get("result") == "valid" else 1
        else:
            print("unknown command", file=sys.stderr)
            return 2
    except parse.PayloadError as e:
        print(json.dumps({"error": e.code}), file=sys.stderr)
        return 2
    print(json.dumps(res, ensure_ascii=False, indent=2))
    return code


if __name__ == "__main__":
    sys.exit(main(sys.argv))
