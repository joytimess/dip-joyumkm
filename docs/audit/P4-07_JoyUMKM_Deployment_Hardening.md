# P4-07 — Deployment hardening

Verdict: **P4-07 FULL COMPLETION — PASS** for the Laragon handoff. nginx/PHP-FPM hosting verification is a deployment prerequisite and was not run.

## PDF validation and hosting dependency

Install QPDF 12.4.2 and Python 3 with the standard library. Deploy `app/Services/validate-intelligence-pdf.py` alongside the PHP validator. Set `INTELLIGENCE_PDF_QPDF` and `INTELLIGENCE_PDF_PYTHON` to executable paths visible to the PHP service account. PHP requires `proc_open` and permission to start both processes. Laragon FastCGI uses a restricted PATH, so the local configuration uses absolute paths. Keep the executable and helper locations protected from web writes.

QPDF inspects the JSON v2 object graph, including compressed objects and canonical PDF names. The validator rejects encryption and active content such as actions, JavaScript and attachments, and requires a clean QPDF structural check with recovery disabled. It rejects a failed invocation, warnings, or an exceeded bound. QPDF gets three seconds and at most 8 MiB of combined output per invocation. The PHP watchdog allows eight seconds total. Windows uses a Job Object with a 384 MiB commit limit and at most two processes. Linux uses a 384 MiB address-space limit and a six-second CPU limit inherited by the child.

Before nginx/PHP-FPM deployment, verify PHP can invoke the helper as the actual pool user, enforce private storage permissions for that user, and repeat the representative upload and download checks with the actual request-body limits and worker configuration. Laragon mod_fcgid was verified locally. No substitute hosting stack was created.

## ACL and operation

Apache and PHP FastCGI run as `S0010AX\Wijaya`. The ACL on `storage/app/private/intelligence` is protected from inheritance and grants access to that account, SYSTEM, and Administrators. The pre-change DACL backup is `%LOCALAPPDATA%\joyumkm-intelligence-acl-before-20261124.txt`. To restore it, use `icacls <absolute storage/app/private parent> /restore <absolute backup path>`. AccessChk found full access for Wijaya and no effective permissions for `CodexSandboxOffline`, `CodexSandboxOnline` or `BUILTIN\Users` on a PDF inheriting the restricted ACL.

Windows Task Scheduler contains one disabled interactive-logon task each for `JoyUMKM Hermes Webhook Worker` and `JoyUMKM Laravel Scheduler`. Both use the project working directory and IgnoreNew. The worker targets `database:hermes-webhooks` only. At verification the queue contained zero pending, delayed or reserved jobs. `schedule:list` showed the minute scan and a direct invocation exited through its disabled branch. The tasks were not started.

Hermes already has one scheduled, no-agent `jmi-recover` cron job (ID `5fa98346fe3a`, last status `ok`). Its existing recovery and callback sweep were inspected; there was no manual run. Artifact rollout is off. `JMI_ARTIFACT_DELIVERY_PAUSED=1` or the presence of `state/delivery.pause` blocks artifact delivery independently of the enable setting. The runtime flag guard was verified without dispatch. `HERMES_WEBHOOK_ENABLED=false` remains in effect. No backlog was processed by this QA.

The previous checkpoint recorded the already-applied manifest migration, distinct matching artifact secret, and Hermes transport/config verification. They were reused. No migration, secret provisioning, or Hermes deployment was repeated.

