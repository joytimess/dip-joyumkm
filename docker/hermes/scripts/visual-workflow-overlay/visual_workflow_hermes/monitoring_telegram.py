"""Telegram transport for the Jobsheet 5 notification node."""

from __future__ import annotations

import http.client
import json
import re
from html import escape
from urllib.parse import urlencode

from .monitoring import MAX_SCORE, _read_env_file

BOT_USERNAME = "joyumkm_monitoring_bot"
ENV_PATH = "/opt/data/.env"


def _read_config() -> dict:
    try:
        return _read_env_file(ENV_PATH)
    except OSError:
        return {}


def build_telegram_message(record: dict) -> str:
    name = record["nama_umkm"]
    name = "-" if name in (None, "") else escape(str(name))
    score = escape(str(record["total_skor"]))
    formatted_time = escape(str(record["waktu_format"]))
    return (
        "🚨 <b>PRIORITAS INTERVENSI</b>\n\n"
        "<b>UMKM</b>\n"
        f"• Nama: <b>{name}</b>\n"
        "• Jenis Evaluasi: Enam Faktor\n\n"
        "<b>Hasil</b>\n"
        f"• Total Skor: <b>{score}/{MAX_SCORE}</b>\n"
        f"• Waktu: {formatted_time}\n\n"
        "<b>Tindakan</b>\n"
        "Segera tinjau hasil evaluasi Enam Faktor UMKM ini.\n\n"
        "<i>JoyUMKM — Periodic Monitoring</i>"
    )


def send_telegram(text: str) -> dict:
    config = _read_config()
    token = config.get("TELEGRAM_BOT_TOKEN", "")
    chat = config.get("TELEGRAM_HOME_CHANNEL", "")
    if (not re.fullmatch(r"[0-9]+:[A-Za-z0-9_-]{20,}", token)
            or not re.fullmatch(r"[0-9]+", chat)
            or config.get("TELEGRAM_ALLOWED_USERS") != chat):
        return {"ok": False, "detail": "telegram_config_unavailable"}

    # Direct TLS keeps the token URL out of HTTP library debug logs.
    connection = http.client.HTTPSConnection("api.telegram.org", timeout=10)
    try:
        connection.request("POST", f"/bot{token}/sendMessage",
                           urlencode({"chat_id": chat, "text": text, "parse_mode": "HTML"}),
                           {"Content-Type": "application/x-www-form-urlencoded"})
        response = connection.getresponse()
        if response.status != 200:
            return {"ok": False, "detail": f"telegram_http_{response.status}"}
        payload = json.loads(response.read(65536))
    except (OSError, ValueError, http.client.HTTPException) as error:
        return {"ok": False, "detail": f"telegram_transport_{type(error).__name__}"}
    finally:
        connection.close()

    result = payload.get("result") if isinstance(payload, dict) and payload.get("ok") is True else None
    if not isinstance(result, dict):
        return {"ok": False, "detail": "telegram_receipt_missing"}
    recipient = result.get("chat") or {}
    bot = result.get("from") or {}
    if (recipient.get("id") != int(chat) or recipient.get("type") != "private"
            or bot.get("is_bot") is not True or bot.get("username", "").lower() != BOT_USERNAME
            or type(result.get("message_id")) is not int or type(result.get("date")) is not int):
        return {"ok": False, "detail": "telegram_receipt_mismatch"}
    return {"ok": True, "detail": "telegram_accepted", "message_id": result["message_id"],
            "chat_id": recipient["id"], "chat_type": "private", "bot_username": BOT_USERNAME,
            "telegram_date": result["date"]}
