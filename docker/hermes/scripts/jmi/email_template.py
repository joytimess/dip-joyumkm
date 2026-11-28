"""Deterministic JoyUMKM email from an immutable, verified package."""
from datetime import datetime, timedelta, timezone
from email import policy
from email.headerregistry import Address
from email.message import EmailMessage
from email.utils import format_datetime
from html import escape
import re

from . import artifacts

MONTHS = ("Januari", "Februari", "Maret", "April", "Mei", "Juni",
          "Juli", "Agustus", "September", "Oktober", "November", "Desember")
DIRECTIONS = {"improved": "naik", "declined": "turun", "unchanged": "tetap"}


def _text(value, limit):
    value = re.sub(r"(?i)\b(?:https?://|www\.)\S+|\S+@\S+", "", value or "")
    return " ".join("".join(char for char in value if char.isprintable()).split())[:limit].strip()


def _score(value, maximum):
    return "Belum tersedia" if value is None else f"{value}/{maximum}"


def build(package):
    doc = package.document
    extension = doc["monthly_extension"]
    recap = extension["monthly_recap"]
    period = recap["assessment_period"]["current"]
    year, month = period.split("-")
    label = f"{MONTHS[int(month) - 1]} {year}"
    name = _text(doc["report"]["business_profile"]["name"], 120)
    greeting = f"Yth. Pengelola {name or 'UMKM'},"
    intro = f"Kami menyampaikan laporan evaluasi dan perkembangan usaha berdasarkan penilaian JoyUMKM untuk periode {label}."
    cutoff = artifacts._utc(recap["assessment_period"]["cutoff"]["at"]).astimezone(
        timezone(timedelta(hours=7)))
    dating = f"Data hingga {cutoff.day} {MONTHS[cutoff.month - 1]} {cutoff.year}, {cutoff:%H:%M} WIB"
    rows = []
    for instrument, keys in (("six_factor", ("total_score",)), ("three_dimension", ("ins", "ect", "ops"))):
        metrics = recap["instruments"][instrument]["metrics"]
        for code in keys:
            item = metrics[code]
            delta = item["absolute_difference"]
            change = ("Belum tersedia" if delta is None
                      else f"{DIRECTIONS[item['trend']]} ({delta:g} poin)")
            rows.append(("Enam Faktor" if code == "total_score" else code.upper(),
                         _score(item["previous"], item["maximum"]), _score(item["current"], item["maximum"]), change))
    narrative = _text(extension["analysis"]["monthly_development"], 2000)
    closing = ("Laporan PDF memuat analisis dan rencana tindak lanjut 30/60/90 hari. "
               "Workbook XLSX memuat rincian data dan rekap penilaian.")
    footer = "Laporan otomatis berdasarkan data yang tersedia."
    body = "\n\n".join(("JoyUMKM | INTELLIGENCE REPORTING", f"Periode {label}\n{dating}", greeting, intro,
                        "Ringkasan penilaian\n" + "\n".join(
                            f"{k} · Sebelumnya {old} · Berjalan {new} · Perubahan {change}"
                            for k, old, new, change in rows),
                        f"Ringkasan analisis\n{narrative}", closing, "Hormat kami,\nJoyUMKM Intelligence", footer))
    html_rows = "".join(
        "<tr>" + "".join(f'<td style="padding:8px;border-bottom:1px solid #e4e8f0">{escape(cell)}</td>'
                         for cell in row) + "</tr>" for row in rows)
    html = f"""<!doctype html><html lang="id"><body style="margin:0;background:#f4f6fa;color:#101E40;font-family:Arial,sans-serif">
<table role="presentation" width="100%"><tr><td align="center">
<table role="presentation" width="100%" style="max-width:640px;background:white;text-align:left">
<tr><td style="background:#101E40;color:white;padding:24px;border-bottom:4px solid #0297DE">
<strong>JoyUMKM</strong><br><small>INTELLIGENCE REPORTING</small></td></tr>
<tr><td style="padding:20px">
<h1 style="font-size:22px;color:#0207CA">Laporan Evaluasi dan Perkembangan UMKM</h1>
<p>Periode {escape(label)}<br>{escape(dating)}</p><p>{escape(greeting)}</p><p>{escape(intro)}</p>
<h2 style="font-size:17px">Ringkasan penilaian</h2>
<table width="100%" style="font-size:13px;border-collapse:collapse;text-align:left;overflow-wrap:anywhere">
<tr><th scope="col">Indikator</th><th scope="col">Sebelumnya</th><th scope="col">Berjalan</th><th scope="col">Perubahan</th></tr>
{html_rows}</table>
<h2 style="font-size:17px">Ringkasan analisis</h2><p>{escape(narrative)}</p><p>{escape(closing)}</p>
<p>Hormat kami,<br>JoyUMKM Intelligence</p><p><small>{escape(footer)}</small></p>
</td></tr></table></td></tr></table></body></html>"""
    msg = EmailMessage(policy=policy.SMTP.clone(cte_type="7bit"))
    msg["Subject"] = f"Laporan Evaluasi dan Perkembangan UMKM — {label}"
    msg.set_content(body)
    msg.add_alternative(html, subtype="html")
    for kind, mime in artifacts.MIMES.items():
        main, subtype = mime.split("/")
        msg.add_attachment(package.blobs[kind], maintype=main, subtype=subtype,
                           filename=f"JoyUMKM_Report_{period}.{kind}")
    return msg


def serialize(message, config, authorization, message_id, now):
    message["From"] = Address(config.name, addr_spec=config.sender)
    message["To"] = Address(addr_spec=authorization.address)
    message["Message-ID"] = message_id
    message["Date"] = format_datetime(datetime.fromtimestamp(now, timezone(timedelta(hours=7))))
    opaque = message_id[1:].split("@", 1)[0]
    message.set_boundary("jmi-mixed-" + opaque)
    next(message.iter_parts()).set_boundary("jmi-alternative-" + opaque)
    return message.as_bytes()
