"""Gmail TLS transport with a durable checkpoint before DATA."""
from dataclasses import dataclass, field
import json
import os
import re
import smtplib
import ssl
import time

from . import artifacts, store
from .recipient import EmailFailure, mailbox, private_read

MAX_MESSAGE = 24_000_000
ENABLED = "JMI_EMAIL_DELIVERY_ENABLED"
PAUSED = "JMI_EMAIL_DELIVERY_PAUSED"


def read_settings():
    result = {}
    for line in private_read("email.env", 8192).decode().splitlines():
        if not line or line.startswith("#"):
            continue
        key, value = line.split("=", 1)
        if key in result or not re.fullmatch(r"[A-Z_]+", key):
            raise ValueError
        result[key] = json.loads(value) if value.startswith('"') else value
        if not isinstance(result[key], str):
            raise ValueError
    return result


def _gate(settings, key):
    values = (settings.get(key), os.environ.get(key, settings.get(key)))
    expected = "1" if key == ENABLED else "0"
    return all(value == expected for value in values)


def active():
    try:
        settings = read_settings()
        return (_gate(settings, ENABLED) and _gate(settings, PAUSED)
                and not os.path.lexists(store.home() / "email.pause"))
    except (OSError, ValueError, artifacts.PackageError):
        return False


@dataclass(frozen=True)
class Config:
    port: int
    username: str = field(repr=False)
    password: str = field(repr=False)
    sender: str = field(repr=False)
    name: str
    timeout: int
    size: int

    @classmethod
    def from_settings(cls, cfg):
        port = int(cfg["MAIL_PORT"])
        if (cfg["MAIL_HOST"] != "smtp.gmail.com" or (port, cfg["MAIL_ENCRYPTION"]) not in
                {(587, "tls"), (465, "ssl")}):
            raise ValueError
        username = mailbox(cfg["MAIL_USERNAME"])
        sender = mailbox(cfg["MAIL_FROM_ADDRESS"])
        password, name = cfg["MAIL_PASSWORD"], cfg["MAIL_FROM_NAME"]
        if (sender != username or not password.strip() or not password.isascii() or not name.strip()
                or len(name) > 100 or re.search(r"[\x00-\x1f\x7f]", password + name)):
            raise ValueError
        timeout = int(cfg.get("MAIL_TIMEOUT_SECONDS", "30"))
        size = int(cfg.get("JMI_EMAIL_MAX_BYTES", str(MAX_MESSAGE)))
        if not 1 <= timeout <= 30 or not 1 <= size <= MAX_MESSAGE:
            raise ValueError
        return cls(port, username, password, sender, name, timeout, size)


@dataclass(frozen=True)
class Outcome:
    status: str
    code: str | None
    phase: str
    accepted_at: float | None = None


def _reply(code, phase):
    if phase == "auth":
        return Outcome("failed_permanent", "SMTP_AUTH_REJECTED", phase)
    if 400 <= code < 500:
        return Outcome("failed_retryable", "SMTP_TEMPORARY_REJECTION", phase)
    if 500 <= code < 600:
        return Outcome("failed_permanent", "SMTP_PERMANENT_REJECTION", phase)
    return Outcome("delivery_unknown" if phase == "data" else "blocked", "SMTP_UNEXPECTED_REPLY", phase)


def submit(cfg, authorization, message, before_data, record, *, clock=time.time,
           monotonic=time.monotonic, smtp=smtplib.SMTP, smtp_ssl=smtplib.SMTP_SSL, guard=lambda: None):
    """Record outcome before closing the connection. Never issue a second DATA."""
    phase, client = "connect", None
    deadline = monotonic() + cfg.timeout

    def step(method, *args, **kwargs):
        remaining = deadline - monotonic()
        if remaining <= 0:
            raise TimeoutError
        if client.sock is not None:
            client.sock.settimeout(remaining)
        return method(*args, **kwargs)

    def expect(result, *codes):
        if result[0] not in codes:
            raise smtplib.SMTPResponseException(result[0], b"")

    try:
        if len(message) > cfg.size:
            raise EmailFailure("blocked", "EMAIL_TOO_LARGE")
        context = ssl.create_default_context()
        constructor = smtp if cfg.port == 587 else smtp_ssl
        options = {} if cfg.port == 587 else {"context": context}
        client = constructor(timeout=cfg.timeout, **options)
        expect(step(client.connect, "smtp.gmail.com", cfg.port), 220)
        phase = "tls"
        expect(step(client.ehlo), 250)
        if cfg.port == 587:
            step(client.starttls, context=context)
            expect(step(client.ehlo), 250)
        phase = "auth"
        step(client.login, cfg.username, cfg.password)
        advertised = client.esmtp_features.get("size")
        if advertised and (not advertised.isascii() or not advertised.isdecimal()
                           or len(message) > int(advertised)):
            raise EmailFailure("blocked", "EMAIL_TOO_LARGE")
        guard()
        authorization.check(clock)
        phase = "envelope"
        size_options = [f"size={len(message)}"] if advertised else []
        expect(step(client.mail, cfg.sender, size_options), 250)
        expect(step(client.rcpt, authorization.address), 250, 251)
        wire = re.sub(rb"(?m)^\.", b"..", message) + b".\r\n"
        authorization.check(clock)
        before_data()
        phase = "data_command"
        expect(step(client.docmd, "DATA"), 354)
        # A delayed 354 can exhaust the authorization. Close without sending the message bytes.
        authorization.check(clock)
        guard()
        phase = "data"
        step(client.send, wire)
        code, _ = step(client.getreply)
        result = Outcome("sent", None, phase, clock()) if code == 250 else _reply(code, phase)
    except EmailFailure as exc:
        result = Outcome(exc.status, exc.code, phase)
    except smtplib.SMTPResponseException as exc:
        result = _reply(exc.smtp_code, phase)
    except (ssl.SSLError, smtplib.SMTPNotSupportedError):
        result = Outcome("delivery_unknown" if phase == "data" else "blocked", "SMTP_TLS_OR_PROTOCOL", phase)
    except (OSError, smtplib.SMTPException):
        result = Outcome("delivery_unknown" if phase == "data" else "failed_retryable", "SMTP_CONNECTION_LOST", phase)
    finally:
        try:
            if "result" in locals():
                record(result)
        finally:
            if client is not None:
                try:
                    client.close()
                except OSError:
                    pass
