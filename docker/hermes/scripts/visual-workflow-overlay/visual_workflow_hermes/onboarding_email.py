"""Render and submit the JoyUMKM onboarding email."""
import json
import os
import re
import smtplib
import ssl
import stat
import time
from datetime import datetime, timezone
from email import policy
from email.errors import HeaderParseError
from email.headerregistry import Address
from email.message import EmailMessage
from email.utils import format_datetime
from html import escape
from urllib.parse import urlsplit
from uuid import UUID

_GMAIL_CONFIG = "/opt/data/joyumkm-intelligence/state/email.env"
_STEPS = (
    "Tambahkan UMKM.",
    "Lengkapi profil usaha.",
    "Lakukan penilaian Enam Faktor.",
    "Pantau perkembangan melalui dashboard.",
)
_SECURITY = "Jika Anda merasa tidak pernah membuat akun ini, abaikan email ini dan hubungi tim JoyUMKM melalui kanal resmi."


def build(fields: dict, recipient: str, app_url: str, sender: str) -> EmailMessage:
    url = urlsplit(app_url)
    if (url.scheme not in {"http", "https"} or not url.hostname or url.username is not None
            or url.query or url.fragment or re.search(r"[\x00-\x20\\]", app_url)):
        raise ValueError("Invalid configured onboarding URL")

    greeting = f'{fields["greeting"]}, {fields["full_name"]}.'
    registered = f'{fields["registered_date"]}, {fields["registered_time"]}'
    reference = fields["account_reference"]
    subject = fields["subject"]
    if not subject or not subject.isprintable() or re.search(r"(?i)@|https?://|www\.", subject):
        subject = "Selamat datang di JoyUMKM, Pendamping!"
    welcome = "Akun berhasil dibuat. Anda sudah bisa memulai pendampingan UMKM di JoyUMKM."
    steps = "\n".join(f"{index}. {step}" for index, step in enumerate(_STEPS, 1))
    plain = (
        "JoyUMKM | UMKM Health Dashboard\nACCOUNT ONBOARDING\n\n"
        "Mulai perjalanan pendampingan Anda.\n\n"
        f"{greeting}\n\n{welcome}\n\n"
        f"Terdaftar pada {registered}\nReferensi akun {reference}\nStatus akun Aktif\n\n"
        f"Panduan memulai\n{steps}\n\nMulai Pendampingan\n{app_url}\n\n"
        f"{_SECURITY}\n\nSalam,\nTim JoyUMKM\n\nEmail ini dikirim secara otomatis."
    )
    html_steps = "".join(f'<li style="margin:0 0 8px">{step}</li>' for step in _STEPS)
    html = f"""<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Selamat datang di JoyUMKM</title></head>
<body bgcolor="#F4F7FC" style="margin:0;background:#F4F7FC;color:#101E40;font-family:Poppins,Arial,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F7FC">
<tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
style="max-width:608px;text-align:left;background:#FFFFFF;border:1px solid #E8EDF6;border-radius:16px">
<tr><td bgcolor="#101E40" style="background:#101E40;color:#FFFFFF;padding:24px;border-radius:16px 16px 0 0;border-bottom:4px solid #0297DE">
<table role="presentation" cellpadding="0" cellspacing="0" style="font-family:Poppins,Arial,sans-serif;color:#FFFFFF"><tr>
<td align="center" width="40" height="40" bgcolor="#0207CA" aria-hidden="true"
style="background:#0207CA;border-radius:12px;color:#FFFFFF;font-size:24px;font-weight:700;line-height:1">J<span style="vertical-align:top;color:#FC734D;font-size:12px;line-height:12px">&#9679;</span></td>
<td style="padding-left:12px"><strong style="font-size:20px;line-height:1.2">JoyUMKM</strong><br>
<span style="font-size:12px;line-height:1.5">UMKM Health Dashboard</span></td>
</tr></table>
<p style="font-size:12px;font-weight:600;letter-spacing:1px;margin:16px 0 0">ACCOUNT ONBOARDING</p>
</td></tr>
<tr><td style="padding:24px;font-size:14px;line-height:1.6;overflow-wrap:anywhere">
<h1 style="font-size:28px;line-height:1.25;margin:0 0 16px">Mulai perjalanan pendampingan Anda.</h1>
<p style="margin:0 0 8px">{escape(greeting)}</p>
<p style="margin:0 0 24px">{welcome}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
style="background:#F4F7FC;border:1px solid #E8EDF6;border-radius:16px;font-size:14px;line-height:1.6">
<tr><td style="padding:16px">
<p style="margin:0 0 8px"><span style="color:#566781">Terdaftar pada</span><br><strong>{escape(registered)}</strong></p>
<p style="margin:0 0 8px"><span style="color:#566781">Referensi akun</span><br><strong>{escape(reference)}</strong></p>
<p style="margin:0"><span style="color:#566781">Status akun</span><br><strong style="color:#147D5B">Aktif</strong></p>
</td></tr></table>
<h2 style="font-size:20px;line-height:1.25;margin:24px 0 12px">Panduan memulai</h2>
<ol style="margin:0 0 24px;padding-left:24px">{html_steps}</ol>
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="#0207CA"
style="background:#0207CA;border-radius:12px">
<a href="{escape(app_url, quote=True)}" style="display:inline-block;background:#0207CA;border-radius:12px;
color:#FFFFFF;text-decoration:none;font-size:14px;font-weight:600;line-height:20px;padding:12px 20px">Mulai Pendampingan</a>
</td></tr></table>
</td></tr>
<tr><td style="border-top:1px solid #E8EDF6;padding:20px 24px 24px;color:#566781;font-size:12px;line-height:1.6">
<p style="margin:0 0 12px">{_SECURITY}</p>
<p style="margin:0 0 8px">Salam,<br><strong style="color:#101E40">Tim JoyUMKM</strong></p>
<p style="margin:0">Email ini dikirim secara otomatis.</p>
</td></tr>
</table></td></tr></table></body></html>"""

    message = EmailMessage(policy=policy.SMTP.clone(cte_type="7bit"))
    message["Subject"] = subject
    message["From"] = Address("JoyUMKM", addr_spec=sender)
    message["To"] = Address(addr_spec=recipient)
    message["Date"] = format_datetime(datetime.now(timezone.utc))
    message.set_content(plain, charset="utf-8")
    message.add_alternative(html, subtype="html", charset="utf-8")
    return message


def _gmail_auth() -> tuple[str, str]:
    keys = {"MAIL_HOST", "MAIL_PORT", "MAIL_ENCRYPTION", "MAIL_USERNAME", "MAIL_PASSWORD", "MAIL_FROM_ADDRESS"}
    settings = {}
    fd = os.open(_GMAIL_CONFIG, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as file:
        info = os.fstat(file.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
            raise ValueError("Expected private Gmail settings")
        raw = file.read(8193)
    if len(raw) > 8192:
        raise ValueError("Gmail settings exceed max size")

    for line in raw.decode("utf-8").splitlines():
        key, separator, value = line.partition("=")
        if key in keys and separator:
            if key in settings:
                raise ValueError("Duplicate Gmail setting")
            settings[key] = json.loads(value) if value.startswith('"') else value
    if settings.keys() != keys or not all(isinstance(value, str) for value in settings.values()):
        raise ValueError("Incomplete Gmail settings")

    username = settings["MAIL_USERNAME"]
    password = settings["MAIL_PASSWORD"]
    if (settings["MAIL_HOST"] != "smtp.gmail.com" or settings["MAIL_PORT"] != "465"
            or settings["MAIL_ENCRYPTION"] != "ssl" or username != settings["MAIL_FROM_ADDRESS"]
            or not username.isascii() or not password.isascii() or not password.strip()
            or not password.isprintable() or Address(addr_spec=username).addr_spec != username):
        raise ValueError("Invalid Gmail settings")
    return username, password


def deliver(message: EmailMessage, *, mode: str, event_id: str) -> dict:
    """Issue DATA once and classify the reply without exposing SMTP response text."""
    def result(status, reason, code=None):
        outcome = {
            "status": status, "reason": reason, "mode": mode,
            "smtp_code": code, "smtp_accepted": status == "sent",
        }
        if status == "sent":
            outcome["accepted_at"] = datetime.now(timezone.utc).isoformat()
        return outcome

    if mode == "dry-run":
        return result("skipped", "dry_run")
    if mode not in {"fake", "live"}:
        return result("failed", "invalid_transport_mode")

    recipient = message["To"].addresses[0].addr_spec
    sender = message["From"].addresses[0].addr_spec
    if not recipient.isascii() or not sender.isascii():
        return result("failed", "unsupported_envelope_address")
    if mode == "live" and (
        os.environ.get("JOYUMKM_ONBOARDING_LIVE_ENABLED") != "1"
        or os.environ.get("JOYUMKM_ONBOARDING_LIVE_RECIPIENT") != recipient
    ):
        return result("skipped", "live_recipient_not_authorized")

    client = None
    phase = "prepare"
    deadline = time.monotonic() + 12

    def step(method, *args):
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError
        if client.sock:
            client.sock.settimeout(remaining)
        return method(*args)

    def expect(reply, *codes):
        if reply[0] not in codes:
            raise smtplib.SMTPResponseException(reply[0], b"")

    try:
        if mode == "live":
            try:
                sender, password = _gmail_auth()
                message.replace_header("From", Address("JoyUMKM", addr_spec=sender))
            except (ValueError, OSError, AttributeError, HeaderParseError):
                return result("failed", "gmail_configuration_invalid")
            client = smtplib.SMTP_SSL(timeout=12, context=ssl.create_default_context())
            host, port = "smtp.gmail.com", 465
        else:
            client = smtplib.SMTP(timeout=12)
            host, port = "127.0.0.1", 2525

        message["Message-ID"] = f"<{UUID(event_id)}@joyumkm.invalid>"
        source = message.as_bytes()
        wire = re.sub(rb"(?m)^\.", b"..", source) + b".\r\n"
        phase = "connect"
        expect(step(client.connect, host, port), 220)
        expect(step(client.ehlo), 250)
        if mode == "live":
            phase = "auth"
            step(client.login, sender, password)
        phase = "envelope"
        expect(step(client.mail, sender), 250)
        expect(step(client.rcpt, recipient), 250, 251)
        phase = "data_command"
        expect(step(client.docmd, "DATA"), 354)
        phase = "data"
        step(client.send, wire)
        code, _ = step(client.getreply)
        if code == 250:
            return result("sent", "smtp_data_accepted", code)
        if 400 <= code < 600:
            return result("failed", "smtp_data_rejected", code)
        return result("unknown", "smtp_data_unexpected_reply", code)
    except smtplib.SMTPResponseException as exc:
        if phase == "data":
            return result("unknown", "smtp_data_ack_unavailable")
        return result("failed", f"smtp_{phase}_rejected", exc.smtp_code)
    except (ValueError, OSError, smtplib.SMTPException):
        if phase == "data":
            return result("unknown", "smtp_data_ack_unavailable")
        return result("failed", f"smtp_{phase}_failed")
    finally:
        if client is not None:
            try:
                client.close()
            except OSError:
                pass
