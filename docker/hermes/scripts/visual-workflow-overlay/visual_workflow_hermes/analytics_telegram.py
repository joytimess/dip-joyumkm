"""Authorize /analytics in the existing Telegram update and reply path.

Delivers formatted Telegram HTML reports and deterministic PNG charts via
Telegram Bot API using parse_mode=HTML.
"""
from __future__ import annotations

from collections import deque
import http.client
import json
import logging
import re
from threading import Lock
import time
from urllib.parse import urlencode
from uuid import uuid4

from .monitoring_telegram import _read_config

logger = logging.getLogger(__name__)
_TELEGRAM_ID = re.compile(r"[1-9][0-9]{0,19}")
_BOT_TOKEN = re.compile(r"[0-9]+:[A-Za-z0-9_-]{20,}")
_ERROR = "Analitik belum dapat diproses. Silakan coba lagi."
_RATE_SECONDS = 5
_MAX_QUESTION = 1000
_MAX_REPLY = 4000
_MAX_CAPTION = 1024


def send_telegram_html(text: str, *, config: dict | None = None) -> dict:
    """Send a Telegram message with parse_mode=HTML over direct TLS."""
    cfg = config if config is not None else _read_config()
    token = cfg.get("TELEGRAM_BOT_TOKEN", "")
    chat = cfg.get("TELEGRAM_HOME_CHANNEL", "")
    if (not _BOT_TOKEN.fullmatch(token)
            or not _TELEGRAM_ID.fullmatch(chat)
            or cfg.get("TELEGRAM_ALLOWED_USERS") != chat):
        return {"ok": False, "detail": "telegram_config_unavailable"}

    connection = http.client.HTTPSConnection("api.telegram.org", timeout=15)
    try:
        body = urlencode({"chat_id": chat, "text": text[:_MAX_REPLY], "parse_mode": "HTML"})
        connection.request(
            "POST",
            f"/bot{token}/sendMessage",
            body,
            {"Content-Type": "application/x-www-form-urlencoded"},
        )
        response = connection.getresponse()
        raw = response.read(65536)
        if response.status != 200:
            return {"ok": False, "detail": f"telegram_http_{response.status}"}
        payload = json.loads(raw)
    except (OSError, ValueError, http.client.HTTPException) as error:
        return {"ok": False, "detail": f"telegram_transport_{type(error).__name__}"}
    finally:
        connection.close()

    result = payload.get("result") if isinstance(payload, dict) and payload.get("ok") is True else None
    if not isinstance(result, dict):
        return {"ok": False, "detail": "telegram_receipt_missing"}
    return {
        "ok": True,
        "detail": "telegram_accepted",
        "message_id": result.get("message_id"),
        "chat_id": (result.get("chat") or {}).get("id"),
    }


def send_telegram_photo(png_bytes: bytes, caption: str = "", *, config: dict | None = None) -> dict:
    """Send a PNG chart photo with HTML caption over direct TLS multipart/form-data."""
    cfg = config if config is not None else _read_config()
    token = cfg.get("TELEGRAM_BOT_TOKEN", "")
    chat = cfg.get("TELEGRAM_HOME_CHANNEL", "")
    if (not _BOT_TOKEN.fullmatch(token)
            or not _TELEGRAM_ID.fullmatch(chat)
            or cfg.get("TELEGRAM_ALLOWED_USERS") != chat):
        return {"ok": False, "detail": "telegram_config_unavailable"}

    boundary = f"----JoyUMKMChart{uuid4().hex}"
    parts: list[bytes] = []

    def add_field(name: str, val: str) -> None:
        parts.append(
            f"--{boundary}\r\n"
            f'Content-Disposition: form-data; name="{name}"\r\n\r\n'
            f"{val}\r\n".encode("utf-8")
        )

    add_field("chat_id", chat)
    if caption:
        add_field("caption", caption[:_MAX_CAPTION])
        add_field("parse_mode", "HTML")

    parts.append(
        f"--{boundary}\r\n"
        f'Content-Disposition: form-data; name="photo"; filename="analytics_chart.png"\r\n'
        f"Content-Type: image/png\r\n\r\n".encode("utf-8")
        + png_bytes
        + b"\r\n"
    )
    parts.append(f"--{boundary}--\r\n".encode("utf-8"))
    body = b"".join(parts)

    connection = http.client.HTTPSConnection("api.telegram.org", timeout=20)
    try:
        connection.request(
            "POST",
            f"/bot{token}/sendPhoto",
            body,
            {
                "Content-Type": f"multipart/form-data; boundary={boundary}",
                "Content-Length": str(len(body)),
            },
        )
        response = connection.getresponse()
        raw = response.read(65536)
        if response.status != 200:
            return {"ok": False, "detail": f"telegram_http_{response.status}"}
        payload = json.loads(raw)
    except (OSError, ValueError, http.client.HTTPException) as error:
        return {"ok": False, "detail": f"telegram_transport_{type(error).__name__}"}
    finally:
        connection.close()

    result = payload.get("result") if isinstance(payload, dict) and payload.get("ok") is True else None
    if not isinstance(result, dict):
        return {"ok": False, "detail": "telegram_receipt_missing"}
    return {
        "ok": True,
        "detail": "telegram_accepted",
        "message_id": result.get("message_id"),
        "chat_id": (result.get("chat") or {}).get("id"),
    }


def deliver_analytics_reply(
    payload: dict | str,
    *,
    config: dict,
    send_html=send_telegram_html,
    send_photo=send_telegram_photo,
) -> dict:
    """Deliver chart PNG + HTML report via Telegram Bot API."""
    if isinstance(payload, str):
        text = payload.strip()
        chart_png = None
        caption = ""
    else:
        text = str(payload.get("text") or "").strip()
        chart_png = payload.get("chart_png")
        caption = str(payload.get("caption") or "").strip()

    if not text:
        return {"ok": False, "detail": "empty_reply"}

    if chart_png:
        if len(text) <= _MAX_CAPTION:
            photo_res = send_photo(chart_png, text, config=config)
            if photo_res.get("ok"):
                return {"ok": True, "mode": "photo_with_full_caption", "photo": photo_res}
            # Fallback to text-only HTML if photo fails
            msg_res = send_html(text, config=config)
            return {"ok": bool(msg_res.get("ok")), "mode": "html_fallback", "message": msg_res}
        photo_res = send_photo(chart_png, caption, config=config)
        msg_res = send_html(text, config=config)
        return {
            "ok": bool(msg_res.get("ok") or photo_res.get("ok")),
            "mode": "photo_and_html",
            "photo": photo_res,
            "message": msg_res,
        }

    msg_res = send_html(text, config=config)
    return {"ok": bool(msg_res.get("ok")), "mode": "html_only", "message": msg_res}


class AnalyticsTelegramCommand:
    def __init__(
        self,
        run_question,
        *,
        read_config=_read_config,
        clock=time.monotonic,
        send_html=send_telegram_html,
        send_photo=send_telegram_photo,
    ):
        self.run_question = run_question
        self.read_config = read_config
        self.clock = clock
        self.send_html = send_html
        self.send_photo = send_photo
        self.lock = Lock()
        self.recent_messages = deque(maxlen=1024)
        self.last_started = float("-inf")
        self.active = False

    def __call__(self, raw_args: str, *, source=None) -> str | None:
        platform = getattr(source, "platform", None)
        if (getattr(platform, "value", platform) != "telegram"
                or source.chat_type != "dm" or source.is_bot or source.delivered_via_upstream_relay):
            return None

        config = self.read_config()
        user = config.get("TELEGRAM_ALLOWED_USERS", "")
        chat = config.get("TELEGRAM_HOME_CHANNEL", "")
        message = source.message_id or ""
        if (not _TELEGRAM_ID.fullmatch(user) or user != chat
                or source.user_id != user or source.chat_id != chat
                or not _TELEGRAM_ID.fullmatch(message)):
            return None

        # Hermes deduplicates the raw update ID. The command also claims its message ID,
        # so an edit cannot run the workflow again under a different platform update ID.
        with self.lock:
            message_key = (chat, message)
            if message_key in self.recent_messages:
                return None
            self.recent_messages.append(message_key)
            question = raw_args.strip()
            if not question:
                return "Ketik /analytics diikuti pertanyaan tentang evaluasi UMKM."
            if len(question) > _MAX_QUESTION:
                return f"Persingkat pertanyaan menjadi maksimal {_MAX_QUESTION} karakter."
            now = self.clock()
            if self.active or now - self.last_started < _RATE_SECONDS:
                return "Mohon tunggu sebelum mengirim pertanyaan berikutnya."
            self.last_started = now
            self.active = True

        correlation_id = uuid4().hex
        try:
            reply = self.run_question(question, correlation_id)
            if isinstance(reply, dict):
                text = str(reply.get("text") or "").strip()
            elif isinstance(reply, str):
                text = reply.strip()
            else:
                return _ERROR
            if not text:
                return _ERROR

            if _BOT_TOKEN.fullmatch(config.get("TELEGRAM_BOT_TOKEN", "")):
                delivery = deliver_analytics_reply(
                    reply,
                    config=config,
                    send_html=self.send_html,
                    send_photo=self.send_photo,
                )
                if delivery.get("ok"):
                    return None
            return text[:_MAX_REPLY]
        except Exception:
            logger.warning("Analytics workflow failed (correlation=%s)", correlation_id)
            return _ERROR
        finally:
            with self.lock:
                self.active = False


def run_question(question: str, correlation_id: str) -> dict:
    from .analytics_workflow import run_question_rich as run_workflow

    return run_workflow(question, correlation_id)
