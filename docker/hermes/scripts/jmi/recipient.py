"""Fresh recipient authorization. Raw mailbox and credentials are memory-only."""
from dataclasses import dataclass, field
from email.errors import HeaderParseError
from email.headerregistry import Address
import hashlib
import hmac
import re
import time
from urllib.parse import urlsplit

from . import artifacts, callback, parse, store

PREFIX = "/api/internal/intelligence/recipients/"
REASONS = {"evaluation_not_final", "no_consent", "no_business_email",
           "invalid_email", "suppressed"}
MAX_RESPONSE = 16384


class EmailFailure(Exception):
    def __init__(self, status, code):
        super().__init__(code)
        self.status, self.code = status, code


def private_read(name, limit):
    root = store.home()
    artifacts._private(root.lstat(), directory=True)
    return artifacts._read(root / name, limit, "EMAIL_FILE_TOO_LARGE")


def mailbox(value):
    if not isinstance(value, str) or not value.isascii() or re.search(r"[\x00-\x1f\x7f,]", value):
        raise ValueError
    value = value.strip().lower()
    try:
        address = Address(addr_spec=value)
    except HeaderParseError:
        raise ValueError from None
    if (len(value) > 254 or address.addr_spec != value or not address.username
            or len(address.username) > 64 or not re.fullmatch(
                r"(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}", address.domain)):
        raise ValueError
    return value


def headers(secret, eid, timestamp):
    path = PREFIX + store.check_id(eid)
    signed = f"JMI-RECIPIENT-V1\nGET\n{path}\n{timestamp}".encode()
    return {"Accept": "application/json", "X-Intelligence-Signature-Version": "1",
            "X-Intelligence-Timestamp": str(timestamp),
            "X-Intelligence-Signature": hmac.new(secret, signed, hashlib.sha256).hexdigest()}


@dataclass(frozen=True)
class Authorization:
    address: str = field(repr=False)
    fingerprint: str
    consent_version: str
    expires_at: float

    def check(self, clock):
        if clock() >= self.expires_at:
            raise EmailFailure("failed_retryable", "RECIPIENT_EXPIRED")


def validate(body, eid, umkm_id, now):
    try:
        doc = parse.strict_loads(body)
        if type(doc) is not dict or doc["event_id"] != eid:
            raise ValueError
        if doc["eligible"] is False:
            if set(doc) != {"event_id", "eligible", "reason"} or doc["reason"] not in REASONS:
                raise ValueError
            raise EmailFailure("skipped_ineligible", doc["reason"].upper())
        if (set(doc) != {"event_id", "umkm_id", "eligible", "recipient_email", "recipient_fingerprint",
                         "consent_version", "issued_at", "expires_at"} or doc["eligible"] is not True
                or type(doc["umkm_id"]) is not int or doc["umkm_id"] != umkm_id
                or doc["consent_version"] != "v1" or not artifacts._sha(doc["recipient_fingerprint"])):
            raise ValueError
        issued, expires = (artifacts._utc(doc[key]).timestamp() for key in ("issued_at", "expires_at"))
        if expires - issued != 60 or issued > now + 5:
            raise ValueError
        address = mailbox(doc["recipient_email"])
    except (ValueError, KeyError, TypeError, parse.PayloadError):
        raise EmailFailure("blocked", "RECIPIENT_RESPONSE_INVALID") from None
    if expires <= now:
        raise EmailFailure("failed_retryable", "RECIPIENT_EXPIRED")
    return Authorization(address, doc["recipient_fingerprint"], "v1", expires)


def pull(eid, umkm_id, *, clock=time.time, request=callback.http_request):
    try:
        cfg = callback._parse_env_text(private_read("recipient.env", 8192).decode())
        secret = cfg["HERMES_INTELLIGENCE_RECIPIENT_SECRET"].encode()
        base = cfg["INTELLIGENCE_RECIPIENT_BASE_URL"].rstrip("/")
        host = cfg.get("INTELLIGENCE_RECIPIENT_HTTP_HOST", "")
        origin = urlsplit(base)
        local = origin.netloc == "host.docker.internal" and host == "joyumkm.test"
        if (len(secret) < 32 or origin.username or origin.password or not origin.hostname
                or origin.path or origin.query or origin.fragment or not base.isascii()
                or re.search(r"[\x00-\x20\x7f]", base)
                or not (origin.scheme == "https" and not host or origin.scheme == "http" and local)):
            raise ValueError
        _ = origin.port
    except (OSError, KeyError, ValueError, artifacts.PackageError):
        raise EmailFailure("blocked", "RECIPIENT_CONFIG_INVALID") from None
    signed = headers(secret, eid, int(clock()))
    if host:
        signed["Host"] = host
    try:
        status, body, _ = request("GET", base + PREFIX + eid, None, signed, 5, MAX_RESPONSE)
    except callback.TransportError as exc:
        if exc.code == "RESPONSE_TOO_LARGE":
            raise EmailFailure("blocked", "RECIPIENT_RESPONSE_INVALID") from None
        raise EmailFailure("failed_retryable", "RECIPIENT_UNAVAILABLE") from None
    if status == 200 and len(body) <= MAX_RESPONSE:
        return validate(body, eid, umkm_id, clock())
    if status == 404:
        raise EmailFailure("skipped_ineligible", "EVENT_NOT_FOUND")
    if status == 429 or 500 <= status <= 599:
        raise EmailFailure("failed_retryable", "RECIPIENT_UNAVAILABLE")
    errors = {400: "RECIPIENT_REQUEST_INVALID", 401: "RECIPIENT_UNAUTHORIZED",
              405: "RECIPIENT_METHOD_INVALID", 409: "RECIPIENT_IDENTITY_CONFLICT"}
    raise EmailFailure("blocked", errors.get(status, "RECIPIENT_RESPONSE_INVALID"))
