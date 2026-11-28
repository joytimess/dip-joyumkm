"""Structured registration nodes for the JoyUMKM onboarding workflow."""
from datetime import datetime, timezone
from email.errors import HeaderParseError
from email.headerregistry import Address
import os
import re
from uuid import UUID
from zoneinfo import ZoneInfo

from .onboarding_email import build, deliver

WORKFLOW = "joyumkm-user-onboarding"
EVENT = "user.registered"
ENDPOINT = "/joyumkm-onboarding"
TEMPLATE = "welcome"
MAX_BODY_BYTES = 4096
MONTHS = ("Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli",
          "Agustus", "September", "Oktober", "November", "Desember")
UTC_TIMESTAMP = re.compile(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z")
EMAIL = re.compile(r"[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+")


def now():
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def timestamp(value):
    if not isinstance(value, str) or not UTC_TIMESTAMP.fullmatch(value):
        raise ValueError("invalid_timestamp")
    return datetime.fromisoformat(value)


def validate_event(event):
    if not isinstance(event, dict) or event.keys() != {
        "event_id", "event_type", "event_version", "source", "occurred_at", "data"
    }:
        raise ValueError("invalid_event_shape")
    if (event["event_type"] != EVENT or type(event["event_version"]) is not int
            or event["event_version"] != 1 or event["source"] != "joyumkm"):
        raise ValueError("unsupported_event")
    event_id = event["event_id"]
    if not isinstance(event_id, str) or len(event_id) != 36 or str(UUID(event_id)) != event_id:
        raise ValueError("invalid_event_id")
    data = event["data"]
    if not isinstance(data, dict) or data.keys() != {"user_id", "name", "email", "registered_at"}:
        raise ValueError("invalid_data_shape")
    if type(data["user_id"]) is not int or not 0 < data["user_id"] < 2**64:
        raise ValueError("invalid_user_id")
    name = data["name"]
    if not isinstance(name, str) or len(name) > 120 or not any(c.isprintable() and not c.isspace() for c in name):
        raise ValueError("invalid_name")
    email = data["email"]
    if not isinstance(email, str) or len(email) > 254 or not EMAIL.fullmatch(email):
        raise ValueError("invalid_email")
    try:
        address = Address(addr_spec=email)
    except HeaderParseError as exc:
        raise ValueError("invalid_email") from exc
    if address.addr_spec != email or len(address.username) > 64:
        raise ValueError("invalid_email")
    if timestamp(event["occurred_at"]) != timestamp(data["registered_at"]):
        raise ValueError("registration_timestamp_mismatch")
    return event


def transform(event, processed_at=None):
    data = event["data"]
    local = timestamp(data["registered_at"]).astimezone(ZoneInfo("Asia/Jakarta"))
    name = " ".join("".join(c if c.isprintable() else " " for c in data["name"]).split())
    first_name = name.split(maxsplit=1)[0]
    subject_name = first_name
    if "@" in first_name or first_name.lower().startswith(("www.", "http:", "https:")):
        subject_name = "Pendamping"
    hour = local.hour
    greeting = next((label for start, end, label in (
        (4, 11, "Selamat pagi"), (11, 15, "Selamat siang"), (15, 18, "Selamat sore")
    ) if start <= hour < end), "Selamat malam")
    return {
        "full_name": name,
        "first_name": first_name,
        "greeting": greeting,
        "registered_date": f"{local.day} {MONTHS[local.month - 1]} {local.year}",
        "registered_time": f"{local:%H.%M} WIB",
        "processed_at": (processed_at.astimezone(timezone.utc).isoformat(timespec="seconds")
                         .replace("+00:00", "Z")) if processed_at else now(),
        "account_reference": f"JYM-{data['user_id']:06d}",
        "subject": f"Selamat datang di JoyUMKM, {subject_name}!",
    }


def event_view(event):
    return {**event, "data": {**event["data"], "email": "[redacted]"}}


class RegistrationNodes:
    def __init__(self, event, settings, claim_smtp):
        self.event = event
        self.settings = settings
        self.claim_smtp = claim_smtp

    def eligibility(self):
        if not self.settings["enabled"]:
            return "onboarding_disabled"
        if self.event["data"]["email"].lower() not in self.settings["allowlist"]:
            return "recipient_not_allowlisted"
        if self.settings["mode"] not in {"fake", "live", "dry-run"}:
            return "smtp_mode_disabled"
        if self.settings["mode"] == "live" and (
            os.environ.get("JOYUMKM_ONBOARDING_LIVE_ENABLED") != "1"
            or os.environ.get("JOYUMKM_ONBOARDING_LIVE_RECIPIENT") != self.event["data"]["email"]
        ):
            return "live_recipient_not_authorized"
        return "eligible"

    def run(self, step, state):
        kind = step["kind"]
        event = self.event
        if kind == "trigger":
            validate_event(event)
            return {"status": "ok", "input": event_view(event), "output": {
                "event": event_view(event), "event_id": event["event_id"], "run_id": state["run_id"],
                "signature": "verified", "deduplication": "claimed",
            }}
        parent, = step["after"]
        previous = state["outputs"][parent]
        if kind == "transform":
            return {"status": "ok", "input": previous, "output": transform(event)}
        if kind == "condition":
            from .runner import evaluate_condition

            reason = self.eligibility()
            condition = evaluate_condition(step["expression"], state["outputs"], set(state["outputs"]))
            allowed = condition and reason == "eligible"
            if not condition and reason == "eligible":
                reason = "condition_false"
            return {"status": "ok", "input": {"fields": previous, "expression": step["expression"]},
                    "output": {"eligible": allowed, "reason": reason, "fields": previous}}
        if kind == "email":
            mode = self.settings["mode"]
            reason = self.eligibility() if previous["eligible"] else previous["reason"]
            if reason != "eligible":
                outcome = {"status": "skipped", "reason": reason, "mode": mode,
                           "smtp_accepted": False}
            else:
                recipient = event["data"]["email"]
                message = build(previous["fields"], recipient, self.settings["app_url"], "qa@joyumkm.test")
                if self.claim_smtp():
                    outcome = deliver(message, mode=mode, event_id=event["event_id"])
                else:
                    outcome = {"status": "unknown", "reason": "interrupted_smtp", "mode": mode,
                               "smtp_accepted": False}
            return {"status": outcome["status"], "input": previous, "output": outcome}
        raise ValueError("unsupported_node")
