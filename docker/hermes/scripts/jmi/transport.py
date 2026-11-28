"""P4-04: Hermes -> Laravel artifact transport client.

Pure transport primitives. This module never renders, never calls a model, never builds a replacement manifest,
never sends e-mail and never reads recipient data. Durable orchestration (state, retries, recovery) is ``delivery.py``.

* ``load_transport`` - operator configured destination + dedicated artifact secret (fail closed).
* ``sign_upload`` / ``upload_headers`` - JMI-ARTIFACT-V1 HMAC signer, byte compatible with Laravel P4-01.
* ``prepare_verified_package`` - verified immutable bytes of one package (locks released before any network).
* ``send_manifest_callback`` - signed JSON callback carrying the P3-05 transport projection.
* ``upload_artifact`` - signed binary PUT of one artifact.

The only network primitive is ``callback.http_request`` (callback.py stays the single network module).
"""
from __future__ import annotations

import hashlib
import hmac
import ipaddress
import json
import os
import re
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from urllib.parse import urlsplit

from . import artifacts, callback, parse, snapshot, store

SIGNATURE_VERSION = "JMI-ARTIFACT-V1"
UPLOAD_PATH = "/api/internal/intelligence/artifacts/{event_id}/{kind}"
ENV_FILE = "artifact.env"
MIN_SECRET_LENGTH = 32
DEFAULT_TIMEOUT = 60
MAX_ARTIFACT_BYTES = artifacts.MAX_ARTIFACT_BYTES
MAX_RESPONSE_BYTES = 64 * 1024
MAX_RETRY_AFTER = 5400
MIMES = artifacts.MIMES
KINDS = tuple(MIMES)
ARTIFACT_STATUSES = ("pending", "partial", "complete")
SHA = re.compile(r"[a-f0-9]{64}")
HASHED_METADATA = ("event_id", "evaluation_id", "umkm_id", "source_event_version", "schema_version", "prompt_version",
                   "knowledge_version", "methodology_status", "provider", "model")

OK, RETRY, PERMANENT, CONFLICT, OPERATOR = "ok", "retry", "permanent", "conflict", "operator"


# ---------------------------------------------------------------------------------------------------- configuration

@dataclass(frozen=True)
class ArtifactConfig:
    base_url: str
    secret: bytes = field(repr=False)
    timeout: int = DEFAULT_TIMEOUT
    allow_http: bool = False
    http_host: str = ""

    def __repr__(self) -> str:  # never expose the secret
        return f"ArtifactConfig(base_url={self.base_url!r}, timeout={self.timeout}, allow_http={self.allow_http})"

    def upload_url(self, event_id: str, kind: str) -> str:
        return self.base_url.rstrip("/") + UPLOAD_PATH.format(event_id=event_id, kind=kind)


def _local_host(host: str) -> bool:
    """Plain HTTP is only tolerated towards loopback/private/single-label (container) hosts."""
    if host in ("localhost", "host.docker.internal") or "." not in host:
        return True
    try:
        ip = ipaddress.ip_address(host)
        return ip.is_loopback or ip.is_private
    except ValueError:
        return False


def _origin(url: str) -> tuple:
    p = urlsplit(url)
    return p.scheme, (p.hostname or "").lower(), p.port or (443 if p.scheme == "https" else 80)


def _other_secrets(environ, get) -> list:
    snap = get("INTELLIGENCE_SNAPSHOT_SECRET")
    if not snap and environ is os.environ:
        snap = snapshot._read_env_file()[0].get("INTELLIGENCE_SNAPSHOT_SECRET", "")
    callback_secret = snapshot.get_callback_secret(environ)
    return [s for s in (callback_secret, snap, *callback._webhook_secrets(environ)) if s]


def load_artifact_config(environ=None, callback_config=None) -> tuple:
    """Return ``(ArtifactConfig | None, problems)``. Problems are fixed codes, never values. Fail closed."""
    environ = os.environ if environ is None else environ
    file_vals, problems = callback._read_env_file(ENV_FILE) if environ is os.environ else ({}, [])
    problems = [p.replace("CALLBACK", "ARTIFACT") for p in problems]

    def get(name):
        v = environ.get(name)
        return v if v not in (None, "") else file_vals.get(name)

    allow_http = (get("INTELLIGENCE_ARTIFACT_ALLOW_HTTP") or "") == "1"
    url = (get("INTELLIGENCE_ARTIFACT_BASE_URL") or "").strip()
    if not url:
        problems.append("ARTIFACT_URL_MISSING")
    else:
        try:
            p = urlsplit(url)
            p.port  # noqa: B018 - validates the port
            ok = (p.scheme in ("http", "https") and bool(p.hostname) and not p.username and not p.password
                  and not p.query and not p.fragment and p.path in ("", "/"))
            if ok and p.scheme == "http" and not (allow_http and _local_host(p.hostname)):
                problems.append("ARTIFACT_URL_INSECURE")
                ok = True
        except ValueError:
            ok = False
        if not ok:
            problems.append("ARTIFACT_URL_INVALID")
        elif callback_config is not None and _origin(url) != _origin(callback_config.url):
            problems.append("ARTIFACT_URL_ORIGIN_MISMATCH")
    secret = get("INTELLIGENCE_ARTIFACT_SECRET") or ""
    if not secret:
        problems.append("ARTIFACT_SECRET_MISSING")
    elif len(secret.encode()) < MIN_SECRET_LENGTH:
        problems.append("ARTIFACT_SECRET_TOO_SHORT")
    elif any(hmac.compare_digest(secret.encode(), other.encode()) for other in _other_secrets(environ, get)):
        problems.append("ARTIFACT_SECRET_REUSED")
    timeout = callback._int_setting(get("INTELLIGENCE_ARTIFACT_TIMEOUT_SECONDS"), DEFAULT_TIMEOUT, 1, 120,
                                    "ARTIFACT_TIMEOUT_INVALID", problems)
    if problems:
        return None, problems
    host = (get("INTELLIGENCE_ARTIFACT_HTTP_HOST") or "").strip()
    if host and not callback.valid_http_host(host):
        problems.append("ARTIFACT_HTTP_HOST_INVALID")
    if problems:
        return None, problems
    return ArtifactConfig(base_url=url, secret=secret.encode(), timeout=timeout, allow_http=allow_http, http_host=host), []


def load_transport(environ=None) -> tuple:
    """``(callback.Config, ArtifactConfig, problems)``; both configs are None unless everything is valid."""
    cb, cb_problems = callback.load_config(environ)
    ac, ac_problems = load_artifact_config(environ, cb)
    problems = cb_problems + ac_problems
    return (None, None, problems) if problems else (cb, ac, [])


# --------------------------------------------------------------------------------------------------------- signer

def canonical_string(timestamp, event_id: str, kind: str, content_type: str, content_length: int, body_sha256: str) -> str:
    path = UPLOAD_PATH.format(event_id=event_id, kind=kind)
    return "\n".join((SIGNATURE_VERSION, "PUT", path, str(timestamp), event_id, kind, content_type,
                      str(content_length), body_sha256))


def sign_upload(secret: bytes, timestamp, event_id: str, kind: str, content_type: str, content_length: int,
                body_sha256: str) -> str:
    canonical = canonical_string(timestamp, event_id, kind, content_type, content_length, body_sha256)
    return hmac.new(secret, canonical.encode("utf-8"), hashlib.sha256).hexdigest()


def upload_headers(secret: bytes, event_id: str, kind: str, body: bytes, clock=time.time) -> dict:
    """Headers for one PUT attempt. The timestamp is the real current time, fresh on every attempt."""
    content_type = MIMES[kind]
    digest = hashlib.sha256(body).hexdigest()
    ts = str(int(clock()))
    return {"Content-Type": content_type, "Content-Length": str(len(body)), "Accept": "application/json",
            "User-Agent": "jmi-artifact/1", "X-Intelligence-Signature-Version": "1", "X-Intelligence-Timestamp": ts,
            "X-Intelligence-Content-Sha256": digest,
            "X-Intelligence-Signature": sign_upload(secret, ts, event_id, kind, content_type, len(body), digest)}


# ----------------------------------------------------------------------------------------------- semantic report hash

def _canonicalize(value):
    if isinstance(value, dict):
        return {k: _canonicalize(value[k]) for k in sorted(value)}
    if isinstance(value, list):
        return [_canonicalize(v) for v in value]
    return value


def _php_json(value) -> str:
    # PHP JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES still escapes U+2028/U+2029.
    return (json.dumps(value, ensure_ascii=False, separators=(",", ":"), allow_nan=False)
            .replace("\u2028", "\\u2028").replace("\u2029", "\\u2029"))


def semantic_report_hash(doc: dict) -> str:
    """Python mirror of ``IntelligenceCallbackPayload::hash()``; verified against the real receiver in P4-05 QA."""
    when = datetime.fromisoformat(str(doc["generated_at"]).replace("Z", "+00:00"))
    when = when.replace(tzinfo=timezone.utc) if when.tzinfo is None else when.astimezone(timezone.utc)
    semantic = {"generated_at": when.strftime("%Y-%m-%dT%H:%M:%SZ")}
    for name in HASHED_METADATA:
        semantic[name] = doc[name].lower() if name == "event_id" else doc[name]
    semantic["report"] = doc["report"]
    return hashlib.sha256(_php_json(_canonicalize(semantic)).encode("utf-8")).hexdigest()


# ---------------------------------------------------------------------------------------------------- package

class TransportPackageError(Exception):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


@dataclass(frozen=True)
class VerifiedPackage:
    """Immutable verified package: exact artifact bytes, callback body and the four hashes (never conflated)."""
    event_id: str
    source_report_sha256: str
    artifact_set_hash: str
    transport_artifact_set_hash: str
    transport_artifacts: tuple
    blobs: dict = field(repr=False)
    callback_body: bytes = field(repr=False)
    callback_sha256: str = ""
    report_hash: str = ""
    document: dict = field(default_factory=dict, repr=False, compare=False)

    def entry(self, kind):
        return next(e for e in self.transport_artifacts if e["kind"] == kind)


def prepare_verified_package(eid: str) -> VerifiedPackage:
    """Verify the committed package and capture the exact bytes that will be sent.

    Raises ``TransportPackageError(code)`` (safe codes from the P3 contract). Source/renderer locks are released
    before this returns, so no lock is ever held during HTTP."""
    store.check_id(eid)
    try:
        job = store.read_job(eid)
        if not store.artifact_eligible(job) or job.get("event_id") != eid:
            raise TransportPackageError("PIPELINE_INELIGIBLE")
        if job.get("status") != "completed":
            raise TransportPackageError("ANALYSIS_NOT_COMPLETED")
        manifest, blobs = artifacts.read_package(eid, nonblocking=True)
        doc = parse.strict_loads(blobs["report"])
        if (not isinstance(doc, dict) or any(k not in doc for k in callback.REQUIRED_REPORT_KEYS)
                or not isinstance(doc["report"], dict)):
            raise TransportPackageError("REPORT_MALFORMED")
        if (doc["event_id"] != eid or doc["status"] != "completed" or doc["evaluation_id"] != job.get("evaluation_id")
                or doc["umkm_id"] != job.get("umkm_id") or doc["source_event_version"] != job.get("source_event_version")
                or manifest["event_id"] != eid or manifest["evaluation_id"] != doc["evaluation_id"]
                or manifest["umkm_id"] != doc["umkm_id"]):
            raise TransportPackageError("REPORT_IDENTITY_MISMATCH")
        projection = tuple(artifacts._projection(manifest["artifacts"]))
        body_doc = {k: v for k, v in doc.items() if k != "monthly_extension"}
        body_doc["artifacts"] = list(projection)
        body = callback.canonical_body(body_doc)
        if len(body) > callback.MAX_BODY_BYTES:
            raise TransportPackageError("PAYLOAD_TOO_LARGE")
        report_hash = semantic_report_hash(doc)
    except TransportPackageError:
        raise
    except artifacts.PackageError as error:
        raise TransportPackageError(str(error)) from None
    except (OSError, ValueError, KeyError, TypeError, parse.PayloadError):
        raise TransportPackageError("PACKAGE_IO_FAILED") from None
    return VerifiedPackage(eid, manifest["source_report_sha256"], manifest["artifact_set_hash"],
                           manifest["transport_artifact_set_hash"], projection,
                           {k: blobs[k] for k in KINDS}, body, hashlib.sha256(body).hexdigest(), report_hash, doc)


# ------------------------------------------------------------------------------------------------- result handling

@dataclass(frozen=True)
class TransportResult:
    result: str  # ok | retry | permanent | conflict | operator
    http_status: int | None = None
    error_code: str | None = None
    event_id: str | None = None
    report_id: int | None = None
    kind: str | None = None
    sha256: str | None = None
    size_bytes: int | None = None
    report_hash: str | None = None
    transport_artifact_set_hash: str | None = None
    artifact_status: str | None = None
    status_text: str | None = None
    recovered: bool | None = None
    retry_after: int | None = None
    attempts_on_wire: int = 1

    def to_dict(self) -> dict:
        return {k: v for k, v in self.__dict__.items() if v is not None}


def _info(data: bytes):
    try:
        obj = json.loads(data.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        return None
    return obj if isinstance(obj, dict) else None


def _retry_after(headers) -> int | None:
    raw = (headers or {}).get("retry-after")
    if raw is None or not re.fullmatch(r"[0-9]{1,6}", str(raw).strip()):
        return None
    return min(int(str(raw).strip()), MAX_RETRY_AFTER)


KNOWN_CODES = {"missing_authentication", "invalid_signature", "expired_signature", "event_not_found", "report_not_found",
               "manifest_required", "artifact_conflict", "report_conflict", "artifact_too_large", "unsupported_media_type",
               "invalid_artifact", "checksum_mismatch", "size_mismatch", "manifest_mismatch", "temporary_storage_failure",
               "artifact_receiver_unavailable", "callback_unavailable", "payload_too_large", "unauthorized"}


def classify_error(status: int, info: dict | None, headers=None) -> TransportResult:
    """Map a non-2xx response to a retry classification. Only whitelisted machine codes are ever kept."""
    code = info.get("code") if isinstance(info, dict) and info.get("code") in KNOWN_CODES else None
    base = dict(http_status=status, retry_after=_retry_after(headers), status_text=code)

    def res(kind, error):
        return TransportResult(kind, error_code=error, **base)
    if status == 401:
        return res(OPERATOR, "EXPIRED_SIGNATURE" if code == "expired_signature" else "UNAUTHORIZED")
    if status == 404:
        return res(RETRY, "REPORT_NOT_FOUND" if code == "report_not_found" else "EVENT_NOT_FOUND")
    if status == 409:
        return res(CONFLICT, {"artifact_conflict": "ARTIFACT_CONFLICT", "manifest_required": "MANIFEST_REQUIRED"}.get(code, "REPORT_CONFLICT"))
    if status == 413:
        return res(PERMANENT, "ARTIFACT_TOO_LARGE")
    if status == 415:
        return res(PERMANENT, "UNSUPPORTED_MEDIA_TYPE")
    if status == 422:
        if code in ("checksum_mismatch", "size_mismatch"):
            return res(RETRY, code.upper())  # possible transit corruption: bounded resend of verified identical bytes
        return res(PERMANENT, (code or "VALIDATION_FAILED").upper())
    if status == 503 and code == "callback_unavailable":
        return res(OPERATOR, "CALLBACK_UNAVAILABLE")
    if status in (408, 429) or 500 <= status <= 599:
        return res(RETRY, f"HTTP_{status}")
    if 300 <= status <= 399:
        return res(OPERATOR, "HTTP_REDIRECT")
    return res(PERMANENT, f"HTTP_{status}" if 100 <= status <= 599 else "HTTP_INVALID")


def _malformed(status, why) -> TransportResult:
    return TransportResult(RETRY, http_status=status, error_code=why)


def _exchange(send, validate, *, resign=True) -> TransportResult:
    """Run one logical request. ``send()`` must build fresh headers each call. A 401 expired_signature is re-signed once."""
    wire = 0
    for attempt in range(2 if resign else 1):
        wire += 1
        try:
            status, data, headers = send()
        except callback.TransportError as error:
            return TransportResult(RETRY, error_code=error.code, attempts_on_wire=wire)
        except Exception:
            return TransportResult(RETRY, error_code="INTERNAL_ERROR", attempts_on_wire=wire)
        info = _info(data)
        if status in (200, 201):
            out = validate(status, info)
        else:
            out = classify_error(status, info, headers)
            if out.error_code == "EXPIRED_SIGNATURE" and attempt == 0 and resign:
                continue
        return TransportResult(**{**out.__dict__, "attempts_on_wire": wire})
    return out


def _positive_int(value) -> bool:
    return type(value) is int and value > 0


# ------------------------------------------------------------------------------------------------------ callback

def _validate_callback(package: VerifiedPackage):
    def check(status, info):
        pair = {201: "stored", 200: "duplicate"}[status]
        if (info is None or info.get("status") != pair or not isinstance(info.get("event_id"), str)
                or not _positive_int(info.get("report_id")) or not isinstance(info.get("report_hash"), str)
                or not SHA.fullmatch(info["report_hash"]) or info.get("artifact_status") not in ARTIFACT_STATUSES
                or "transport_artifact_set_hash" not in info):
            return _malformed(status, "ACK_MALFORMED")
        base = dict(http_status=status, event_id=info["event_id"], report_id=info["report_id"], report_hash=info["report_hash"],
                    artifact_status=info["artifact_status"], status_text=pair,
                    transport_artifact_set_hash=info["transport_artifact_set_hash"])
        if info["event_id"] != package.event_id:
            return TransportResult(CONFLICT, error_code="ACK_EVENT_MISMATCH", **base)
        if info["report_hash"] != package.report_hash:
            return TransportResult(CONFLICT, error_code="ACK_REPORT_HASH_MISMATCH", **base)
        remote = info["transport_artifact_set_hash"]
        if remote is None:
            return TransportResult(CONFLICT, error_code="LEGACY_BINDING", **base)  # never upgraded, never uploaded
        if not isinstance(remote, str) or remote != package.transport_artifact_set_hash:
            return TransportResult(CONFLICT, error_code="ACK_TRANSPORT_HASH_MISMATCH", **base)
        return TransportResult(OK, **base)
    return check


def send_manifest_callback(package: VerifiedPackage, cfg, *, transport=None, clock=time.time) -> TransportResult:
    """POST the signed callback (report + transport projection) with exactly ``package.callback_body``."""
    send_fn = transport or callback.http_request

    def send():
        return send_fn("POST", cfg.url, package.callback_body,
                       callback.with_host(callback.build_headers(cfg.secret, package.callback_body, clock), cfg.http_host),
                       cfg.timeout, MAX_RESPONSE_BYTES)
    return _exchange(send, _validate_callback(package))


# -------------------------------------------------------------------------------------------------------- upload

def _validate_upload(package: VerifiedPackage, kind: str, report_id: int):
    entry = package.entry(kind)

    def check(status, info):
        pair = {201: "stored", 200: "duplicate"}[status]
        if (info is None or info.get("result") != pair or not isinstance(info.get("event_id"), str)
                or not _positive_int(info.get("report_id")) or not isinstance(info.get("kind"), str)
                or not isinstance(info.get("sha256"), str) or not SHA.fullmatch(info["sha256"])
                or not _positive_int(info.get("size_bytes")) or info.get("artifact_status") not in ARTIFACT_STATUSES
                or type(info.get("recovered")) is not bool):
            return _malformed(status, "ACK_MALFORMED")
        base = dict(http_status=status, event_id=info["event_id"], report_id=info["report_id"], kind=info["kind"],
                    sha256=info["sha256"], size_bytes=info["size_bytes"], artifact_status=info["artifact_status"],
                    status_text=pair, recovered=info["recovered"])
        if info["event_id"] != package.event_id:
            return TransportResult(CONFLICT, error_code="ACK_EVENT_MISMATCH", **base)
        if info["report_id"] != report_id:
            return TransportResult(CONFLICT, error_code="ACK_REPORT_ID_MISMATCH", **base)
        if info["kind"] != kind:
            return TransportResult(CONFLICT, error_code="ACK_KIND_MISMATCH", **base)
        if info["sha256"] != entry["sha256"]:
            return TransportResult(CONFLICT, error_code="ACK_SHA256_MISMATCH", **base)
        if info["size_bytes"] != entry["size_bytes"]:
            return TransportResult(CONFLICT, error_code="ACK_SIZE_MISMATCH", **base)
        return TransportResult(OK, **base)
    return check


def upload_artifact(package: VerifiedPackage, kind: str, report_id: int, cfg: ArtifactConfig, *, transport=None,
                    clock=time.time) -> TransportResult:
    """PUT the exact verified bytes of one artifact. Requires a durable report binding (``report_id``)."""
    if kind not in KINDS or not _positive_int(report_id):
        return TransportResult(PERMANENT, error_code="UPLOAD_INVALID_REQUEST")
    body = package.blobs[kind]
    entry = package.entry(kind)
    if (not 1 <= len(body) <= MAX_ARTIFACT_BYTES or len(body) != entry["size_bytes"]
            or hashlib.sha256(body).hexdigest() != entry["sha256"]):
        return TransportResult(CONFLICT, error_code="PACKAGE_CHANGED")
    send_fn = transport or callback.http_request
    url = cfg.upload_url(package.event_id, kind)

    def send():
        return send_fn("PUT", url, body,
                       callback.with_host(upload_headers(cfg.secret, package.event_id, kind, body, clock), cfg.http_host),
                       cfg.timeout, MAX_RESPONSE_BYTES)
    out = _exchange(send, _validate_upload(package, kind, report_id))
    return TransportResult(**{**out.__dict__, "kind": kind})
