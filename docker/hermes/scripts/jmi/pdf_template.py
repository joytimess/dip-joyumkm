"""A4 JoyUMKM template. All scores and prose come from the validated document."""
import io
import re
from datetime import datetime
from decimal import Decimal
from pathlib import Path
from xml.sax.saxutils import escape

from reportlab.graphics.shapes import Circle, Drawing, Line, Rect, String
from reportlab.lib import colors
from reportlab.lib.enums import TA_RIGHT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import LongTable, PageBreak, Paragraph, SimpleDocTemplate, Spacer, TableStyle

from . import monthly

NAVY = colors.HexColor("#101E40")
BLUE = colors.HexColor("#0207CA")
CYAN = colors.HexColor("#0297DE")
CORAL = colors.HexColor("#FC734D")
INK = colors.HexColor("#283143")
RULE = colors.HexColor("#C8D1DF")
PALE = colors.HexColor("#F1F5FA")
MARGIN = 48
WIDTH = A4[0] - 2 * (MARGIN + 6)
FONT = "JoyPoppins"
BOLD = "JoyPoppins-Semibold"
FALLBACK = "JoyDejaVu"
MONTHS = ("Januari Februari Maret April Mei Juni Juli Agustus September Oktober November Desember").split()
INSTRUMENTS = {"six_factor": "Enam Faktor", "three_dimension": "Tiga Dimensi"}
KEYS = {"six_factor": (*monthly.INSTRUMENTS["six_factor"],), "three_dimension": ("ins", "ect", "ops")}
DIRECTION = {"improved": "Naik", "declined": "Turun", "unchanged": "Tetap", "insufficient_data": "Belum sebanding"}
PRIORITY = {"high": "Tinggi", "medium": "Sedang", "low": "Rendah"}
UNAVAILABLE = "Data belum tersedia"
COMPARISON_GAP = "Data pembanding belum tersedia"
REDACT = re.compile(
    r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}|\b(?:https?|file)://[^\s<>]+"
    r"|(?:\b[A-Za-z]:[\\/]|(?<![\w\d])/(?:opt|home|tmp|var|etc|workspace|Users|mnt)/)[^\s<>]+"
    r"|\b(?:Bearer\s+|sk-)[\w.-]+"
    r"|\b(?:secret|token|password|api[_ -]?key|(?:recipient[_ -]?)?fingerprint|credential)\s*[=:]\s*[^\s<>]+",
    re.I,
)


def fonts():
    assets = Path(__file__).with_name("pdf_assets")
    for name, file in ((FONT, "Poppins-Regular.ttf"), (BOLD, "Poppins-SemiBold.ttf"),
                       (FALLBACK, "DejaVuSans.ttf")):
        if name not in pdfmetrics.getRegisteredFontNames():
            pdfmetrics.registerFont(TTFont(name, str(assets / file)))


def markup(value, font):
    clean = REDACT.sub("[disamarkan]", str(value))
    face = pdfmetrics.getFont(font).face.charToGlyph
    fallback = pdfmetrics.getFont(FALLBACK).face.charToGlyph
    out = []
    for char in clean:
        if char == "\n":
            out.append("<br/>")
        elif ord(char) in face:
            out.append(escape(char))
        elif ord(char) in fallback and ord(char) >= 32 and ord(char) < 0x1F000:
            out.append(f'<font name="{FALLBACK}">{escape(char)}</font>')
        elif char.isspace():
            out.append(" ")
    return "".join(out)


def number(value):
    return "—" if value is None else format(Decimal(str(value)).normalize(), "f").replace(".", ",")


def month(value, short=False):
    name = MONTHS[int(value[5:7]) - 1]
    return f"{name[:3] if short else name} {value[:4]}"


def timestamp(value):
    if not value:
        return UNAVAILABLE
    date = datetime.fromisoformat(value.replace("Z", "+00:00"))
    return f"{date.day} {month(date.strftime('%Y-%m'))}, {date:%H:%M:%S} UTC"


def comparison_chart(groups, maximum, captions, history=False):
    """Vector charts with markers and print-safe fill for each series."""
    height = 156
    chart = Drawing(WIDTH, height)
    left, bottom, plot_width, plot_height = 37, 48, WIDTH - 54, 92
    steps = 3 if maximum == 30 else 4

    def label(x, y, text, anchor="start", size=8):
        chart.add(String(x, y, text, fontName=FONT, fontSize=size, fillColor=INK, textAnchor=anchor))

    for i in range(steps + 1):
        y = bottom + i * plot_height / steps
        label(left - 8, y - 2, number(maximum * i / steps), "end")
        chart.add(Line(left, y, left + plot_width, y, strokeColor=RULE, strokeWidth=0.5))

    for series, caption in enumerate(captions):
        x = left + series * 115
        fill = (BLUE, NAVY, CYAN)[series]
        if history and series != 1:
            chart.add(Circle(x + 4, 11, 3, fillColor=fill if series == 0 else colors.white, strokeColor=fill))
        else:
            chart.add(Rect(x, 7, 8, 8, fillColor=fill if not series else colors.white, strokeColor=fill))
        label(x + 14, 8, caption)

    previous = [None] * len(captions)
    slot = plot_width / len(groups)
    for index, (title, series_values) in enumerate(groups):
        center = left + slot * (index + 0.5)
        label(center, bottom - 16, title, "middle", 8.5 if history else 9)
        for series, value in enumerate(series_values):
            if value is None:
                previous[series] = None
                continue
            fill = (BLUE, NAVY, CYAN)[series]
            y = bottom + plot_height * value / maximum
            if history:
                if previous[series] is not None:
                    x0, y0 = previous[series]
                    chart.add(Line(x0, y0, center, y, strokeColor=fill,
                                   strokeDashArray=([4, 2] if series == 1 else [1, 2] if series == 2 else None)))
                previous[series] = (center, y)
                marker = Rect(center - 3, y - 3, 6, 6, fillColor=colors.white, strokeColor=fill) if series == 1 else Circle(
                    center, y, 3, fillColor=fill if series == 0 else colors.white, strokeColor=fill)
                chart.add(marker)
            else:
                x = center + (-23 if not series else 23)
                chart.add(Rect(x - 15, bottom, 30, max(0.7, y - bottom),
                               fillColor=fill if not series else colors.white, strokeColor=fill))
                label(x, y + 4, number(value), "middle")
    return chart


class Report:
    def __init__(self, doc):
        self.doc = doc
        self.report = doc["report"]
        self.extension = doc["monthly_extension"]
        self.analysis = self.extension["analysis"]
        self.profile = self.extension["business_profile"]["business_context"] or {}
        recap = self.extension["monthly_recap"]
        self.period = recap["assessment_period"]
        self.instruments = recap["instruments"]
        self.reference = "JMI-" + doc["event_id"].upper()
        self.story = []
        self.body = ParagraphStyle("body", fontName=FONT, fontSize=10, leading=16, textColor=INK,
                                   spaceAfter=8, allowWidows=0, allowOrphans=0)
        self.small = ParagraphStyle("small", parent=self.body, fontSize=8, leading=12, spaceAfter=4)
        self.heading = ParagraphStyle("heading", parent=self.body, fontName=BOLD, fontSize=20, leading=28,
                                      textColor=NAVY, spaceBefore=16, spaceAfter=9, keepWithNext=True)
        self.subheading = ParagraphStyle("subheading", parent=self.heading, fontSize=11, leading=16,
                                         spaceBefore=9, spaceAfter=5)
        self.title = ParagraphStyle("title", parent=self.heading, fontSize=32, leading=41, spaceAfter=22,
                                    keepWithNext=False)
        self.evidenced = ParagraphStyle("evidenced", parent=self.body, spaceAfter=3, keepWithNext=True)
        self.column = ParagraphStyle("column", parent=self.small, fontName=BOLD, textColor=colors.white)
        self.numeric = ParagraphStyle("numeric", parent=self.small, alignment=TA_RIGHT)

    def paragraph(self, value, style=None):
        style = style or self.body
        return Paragraph(markup(value, style.fontName), style)

    def add(self, value, style=None):
        if value:
            self.story.append(self.paragraph(value, style))

    def label(self, title, content):
        self.add(title, self.subheading)
        self.add(content or UNAVAILABLE)

    def section(self, index, title):
        self.add(f"{index:02d}  {title}", self.heading)

    def table(self, columns, rows, widths, numeric=()):
        cells = [[self.paragraph(value, self.column) for value in columns]]
        cells.extend([self.paragraph(value, self.numeric if i in numeric else self.small)
                      for i, value in enumerate(row)] for row in rows)
        table = LongTable(cells, colWidths=[WIDTH * ratio for ratio in widths], repeatRows=1, splitInRow=1,
                          style=TableStyle([
                              ("BACKGROUND", (0, 0), (-1, 0), NAVY),
                              ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, PALE]),
                              ("LINEBELOW", (0, 0), (-1, 0), 1.2, CYAN),
                              ("LINEBELOW", (0, -1), (-1, -1), 0.5, RULE),
                              ("VALIGN", (0, 0), (-1, -1), "TOP"),
                              ("TOPPADDING", (0, 0), (-1, -1), 7),
                              ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
                          ]))
        self.story.extend((table, Spacer(1, 9)))

    def refs(self, references):
        labels = {}
        for instrument, metrics in monthly.INSTRUMENTS.items():
            for key, label in metrics.items():
                labels[f"{instrument}.{key}"] = label
                labels[f"{instrument}.scores.{key}"] = label
        labels.update({"three_dimension.average_score": "Rata-rata Tiga Dimensi",
                       "six_factor.category": "Kategori Enam Faktor", "three_dimension.category": "Kategori Tiga Dimensi"})
        return ", ".join(dict.fromkeys(labels.get(ref, self.reference_label(ref)) for ref in references))

    @staticmethod
    def reference_label(ref):
        prefix, _, field = ref.partition(".")
        fields = {"name": "nama usaha", "sector": "sektor", "location": "lokasi", "employee_count": "jumlah pekerja",
                  "started_year": "tahun mulai", "business_capital": "modal usaha", "amount": "omzet",
                  "period": "periode omzet", "evaluation_id": "nomor penilaian", "finalized_at": "tanggal finalisasi",
                  "assessed_at": "tanggal penilaian", "is_complete": "kelengkapan penilaian"}
        if prefix == "business_context":
            return "Profil usaha · " + fields.get(field.split(".")[-1], "konteks usaha")
        if ref.startswith("data_gap:"):
            name = ref.split(":", 1)[1]
            return "Keterbatasan · " + name.replace("_", " ") if re.fullmatch(r"[a-z_]+", name) else "Keterbatasan data"
        return INSTRUMENTS.get(prefix, "Data penilaian") + (" · " + fields[field] if field in fields else "")

    def findings(self, findings):
        for finding in findings:
            self.add(finding["statement"], self.evidenced if finding.get("evidence_refs") else self.body)
            if finding.get("evidence_refs"):
                self.add("Bukti · " + self.refs(finding["evidence_refs"]), self.small)

    def interpretations(self, section, empty=UNAVAILABLE):
        if not section:
            self.add(empty)
        for ref, text in section.items():
            self.add(f"{self.refs([ref])} — {text}")

    def limitations(self, *, compact=False):
        notes = list(dict.fromkeys(self.report["limitations"]))
        for note in ([" ".join(notes)] if compact else notes):
            self.add(note)
        if not compact and self.extension["data_gaps"]:
            self.add("Tanda — menunjukkan nilai yang belum tersedia. Histori memuat periode yang tercakup dalam paket penilaian.")
        if self.extension["business_profile"]["data_gaps"]:
            for field in self.extension["business_profile"]["data_gaps"]:
                if isinstance(field, str):
                    self.add("Profil · " + self.reference_label(f"data_gap:{field}"), self.small)

    def cover(self):
        self.story.append(Spacer(1, 70))
        self.add("JoyUMKM Intelligence", self.subheading)
        self.add("Laporan Evaluasi dan Perkembangan UMKM", self.title)
        self.add(self.profile.get("name") or self.report["business_profile"].get("name") or UNAVAILABLE, self.heading)
        self.add(self.profile.get("sector"))
        self.story.append(Spacer(1, 25))
        self.label("Periode penilaian", month(self.period["current"]))
        self.label("Tanggal penerbitan laporan", timestamp(self.doc["generated_at"]))
        self.label("Nomor referensi", self.reference)
        self.story.append(PageBreak())

    def summary(self):
        self.section(2, "Ringkasan Eksekutif")
        self.add(self.report["executive_summary"])
        self.add(self.analysis["business_overview"])
        rows = []
        for instrument, keys in (("six_factor", ("total_score",)), ("three_dimension", KEYS["three_dimension"])):
            for key in keys:
                k = self.instruments[instrument]["metrics"][key]
                delta = k["absolute_difference"]
                change = DIRECTION[k["trend"]]
                if delta:
                    change += " " + number(delta)
                rows.append((k["label"], f"{number(k['current'])} / {k['maximum']}" if k["current"] is not None else "—",
                             number(k["previous"]), change))
        self.table(("Indikator", "Nilai berjalan", "Bulan lalu", "Perubahan"), rows, (.34, .21, .18, .27), (1, 2))
        if any(row[2] == "—" for row in rows):
            self.add(COMPARISON_GAP + " untuk indikator bertanda — pada kolom bulan lalu.")
        self.add("Temuan utama", self.subheading)
        statements = dict.fromkeys(f["statement"] for key in ("six_factor_analysis", "three_dimension_analysis")
                                   for f in self.report[key]["findings"])
        for statement in list(statements)[:3]:
            self.add(statement)
        self.add("Prioritas perbaikan", self.subheading)
        for priority in self.report["top_priorities"][:3]:
            self.add(f"{PRIORITY[priority['priority']]} · {priority['title']}")
        self.add("Keterbatasan data", self.subheading)
        self.limitations()

    def details(self):
        self.section(3, "Profil UMKM")
        fields = {"name": "Nama usaha", "sector": "Sektor", "location": "Lokasi",
                  "started_year": "Tahun mulai", "employee_count": "Jumlah pekerja", "business_capital": "Modal usaha"}
        rows = [(label, "Rp " + number(self.profile[key]) if key == "business_capital" else self.profile[key])
                for key, label in fields.items() if self.profile.get(key) is not None]
        revenue = self.profile.get("revenue") or {}
        if revenue.get("amount") is not None:
            periods = {"monthly": "bulanan", "yearly": "tahunan", "annual": "tahunan", "daily": "harian"}
            rows.append(("Omzet " + periods.get(revenue.get("period"), ""), "Rp " + number(revenue["amount"])))
        if rows:
            self.table(("Informasi", "Keterangan"), rows, (.36, .64))
        else:
            self.add(UNAVAILABLE)
        self.add("Profil bersumber dari konteks usaha saat peristiwa penilaian.", self.small)
        for index, (instrument, title) in enumerate(INSTRUMENTS.items(), 4):
            self.section(index, title)
            data = self.instruments[instrument]
            current = data["current"]
            self.add(self.report[instrument + "_analysis"]["summary"])
            if current["status"] == "data_gap":
                self.add(f"Data {title} belum tersedia pada periode berjalan hingga cutoff penilaian.")
            else:
                self.add(f"Kategori · {current.get('category') or UNAVAILABLE}")
                self.add("Tanggal penilaian · " + timestamp(current.get("finalized_at", current.get("assessed_at"))))
                if current.get("is_complete") is False:
                    self.add("Penilaian Tiga Dimensi belum lengkap.")
            rows = []
            for key in KEYS[instrument]:
                k = data["metrics"][key]
                scale = f"{0 if instrument == 'three_dimension' else 6 if key == 'total_score' else 1}–{k['maximum']}"
                rows.append((k["label"], number(k["current"]), scale))
            self.table(("Indikator", "Skor", "Skala"), rows, (.58, .21, .21), (1, 2))
            interpretations = {ref: text for ref, text in self.analysis["indicators"].items()
                               if ref.startswith(instrument + ".")}
            if interpretations:
                self.add("Interpretasi indikator", self.subheading)
                self.interpretations(interpretations)
            self.findings(self.report[instrument + "_analysis"]["findings"])
            if instrument == "six_factor":
                for key, label in (("strengths", "Kekuatan usaha"), ("weaknesses", "Kelemahan usaha")):
                    self.add(label, self.subheading)
                    self.interpretations(self.analysis[key])

    def history(self):
        self.section(6, "Perkembangan Historis")
        self.add(f"{month(self.period['current'])} dihitung sampai cutoff penilaian "
                 f"{timestamp(self.period['cutoff']['at'])}. Periode menggunakan kalender WIB (Asia/Jakarta).")
        periods = [month(self.period[k], True) for k in ("previous", "current")]
        for instrument, title in INSTRUMENTS.items():
            self.add(title, self.subheading)
            data = self.instruments[instrument]
            rows = []
            for key in KEYS[instrument]:
                k = data["metrics"][key]
                rows.append((k["label"], number(k["previous"]), number(k["current"]), number(k["absolute_difference"]),
                             DIRECTION[k["trend"]]))
            self.table(("Indikator", *periods, "Selisih absolut", "Arah"), rows, (.28, .14, .14, .17, .27), (1, 2, 3))
            for caption, key in zip(periods, ("previous", "current")):
                row = data[key]
                self.add(caption + " · " + timestamp(row.get("finalized_at", row.get("assessed_at"))), self.small)
            keys = ("total_score",) if instrument == "six_factor" else KEYS[instrument]
            groups = [(data["metrics"][k]["label"], [data["metrics"][k][p] for p in ("previous", "current")]) for k in keys]
            if any(all(v is not None for v in values) for _, values in groups):
                self.add("Perbandingan antarperiode", self.subheading)
                self.story.append(comparison_chart(groups, data["metrics"][keys[0]]["maximum"], periods))
            else:
                self.add(COMPARISON_GAP + ".")
        history = list(reversed(self.extension["history"][-12:]))
        for instrument, title in INSTRUMENTS.items():
            self.add("Riwayat bulanan · " + title, self.subheading)
            six = instrument == "six_factor"
            keys = ("total_score",) if six else KEYS[instrument]
            captions = [self.instruments[instrument]["metrics"][k]["label"] for k in keys]
            rows, groups = [], []
            for row in history:
                record = row[instrument]
                scores = [record.get("total_score") if six else (record.get("scores") or {}).get(key) for key in keys]
                groups.append((month(row["period_month"], True).replace(" 20", " "), scores))
                rows.append((month(row["period_month"], True), *(number(v) for v in scores),
                             timestamp(record.get("finalized_at", record.get("assessed_at")))))
            if any(sum(values[i] is not None for _, values in groups) >= 2 for i in range(len(keys))):
                self.story.append(comparison_chart(groups, 30 if six else 100, ["Total Enam Faktor"] if six else captions, history=True))
            else:
                self.add("Observasi belum cukup untuk grafik perkembangan bulanan.")
            self.table(("Periode", *captions, "Tanggal sumber"), rows,
                       (.22, .25, .53) if six else (.18, .12, .12, .12, .46), tuple(range(1, len(keys) + 1)))

    def analysis_sections(self):
        self.section(7, "Analisis Intelligence")
        self.label("Gambaran kondisi usaha", self.analysis["business_overview"])
        perspectives = {"business_development": "Pengembangan usaha", "finance": "Keuangan",
                        "marketing_sales": "Pemasaran dan penjualan", "operations_people": "Operasional dan tenaga kerja"}
        for key, heading in perspectives.items():
            data = self.report["perspectives"][key]
            self.add(heading, self.subheading)
            self.add(data.get("abstain_reason"))
            self.findings(data["findings"])
        for item in self.report["cross_instrument_insights"]:
            self.add("Pengamatan antar-instrumen", self.subheading)
            self.add(item["observation"])
            self.add(item["caveat"])
            self.add("Bukti · " + self.refs(item["evidence_refs"]), self.small)
        self.label("Perkembangan bulanan", self.analysis["monthly_development"])
        for key, title in (("historical_trends", "Interpretasi histori"), ("strengths", "Kekuatan"),
                           ("weaknesses", "Kelemahan"), ("identified_issues", "Masalah yang perlu diperiksa")):
            self.add(title, self.subheading)
            self.interpretations(self.analysis[key])
        self.add("Keterbatasan data", self.subheading)
        self.limitations()

        self.section(8, "Prioritas dan Rekomendasi")
        for item in self.report["top_priorities"][:3]:
            self.add(f"{item['id']} · Prioritas {PRIORITY[item['priority']].lower()} · {item['title']}", self.subheading)
            self.add("Permasalahan dan alasan · " + item["rationale"])
            for ref in item["evidence_refs"]:
                self.add("Indikator pendukung · " + self.refs([ref]), self.small)
            for action in item["actions"]:
                self.add("Rekomendasi · " + action)
            self.add("Hasil yang diharapkan · " + self.analysis["expected_outcomes"][item["id"]])
            for metric in item["success_metrics"]:
                self.add("Indikator keberhasilan · " + metric)
            for assumption in item["assumptions"]:
                self.add("Asumsi · " + assumption, self.small)

        self.section(9, "Rencana Tindakan")
        phases = {"days_1_30": "30 HARI · Tindakan awal", "days_31_60": "60 HARI · Pengembangan dan pemantauan",
                  "days_61_90": "90 HARI · Evaluasi dan tindak lanjut"}
        for phase, title in phases.items():
            self.add(title, self.subheading)
            for item, identity in zip(self.report["action_plan"][phase], self.extension["action_identity"][phase]):
                self.add(f"{identity['id']} · {item['priority_id']} · {item['action']}")
                self.add("Indikator keberhasilan · " + item["metric"])

    def methodology(self):
        self.section(10, "Metodologi dan Referensi Data")
        self.add("JoyUMKM Intelligence menghasilkan laporan ini untuk tinjauan usaha dan perencanaan tindak lanjut.")
        self.add("Instrumen penilaian terdiri atas Enam Faktor dan Tiga Dimensi, yang disajikan terpisah sesuai skala sumber.")
        self.add(f"Periode · {month(self.period['previous'])} dan {month(self.period['current'])}")
        self.add("Cutoff penilaian · " + timestamp(self.period["cutoff"]["at"]))
        rows = []
        for instrument, title in INSTRUMENTS.items():
            for item in self.extension["source_provenance"][instrument + "_evaluations"]:
                rows.append((title, month(item["period_month"], True), str(item["evaluation_id"]),
                             timestamp(item.get("finalized_at", item.get("assessed_at")))))
        self.table(("Instrumen", "Periode", "No. penilaian", "Tanggal sumber"), rows, (.23, .18, .20, .39))
        self.add("Keterbatasan data", self.subheading)
        self.limitations(compact=True)

    def page(self, canvas, doc):
        width, height = A4
        canvas.setStrokeColor(BLUE if doc.page == 1 else RULE)
        canvas.setLineWidth(2.5 if doc.page == 1 else 0.6)
        canvas.line(MARGIN, height - 44, width - MARGIN, height - 44)
        if doc.page == 1:
            mark = Path(__file__).with_name("pdf_assets") / "joyumkm-mark.png"
            canvas.drawImage(str(mark), MARGIN + 6, height - 104, width=34, height=34, mask="auto")
            canvas.setStrokeColor(CORAL)
            canvas.line(MARGIN, height - 44, MARGIN + 38, height - 44)
        else:
            canvas.setFont(BOLD, 8)
            canvas.setFillColor(NAVY)
            canvas.drawString(MARGIN, height - 34, "JoyUMKM Intelligence")
            canvas.setFont(FONT, 8)
            canvas.drawRightString(width - MARGIN, height - 34, month(self.period["current"]))
        canvas.setStrokeColor(RULE)
        canvas.setLineWidth(0.6)
        canvas.line(MARGIN, 45, width - MARGIN, 45)
        canvas.setFillColor(NAVY)
        canvas.setFont(FONT, 7.5)
        canvas.drawString(MARGIN, 31, self.reference)
        canvas.drawRightString(width - MARGIN, 31, str(doc.page))


def render(document):
    fonts()
    report = Report(document)
    report.cover()
    report.summary()
    report.details()
    report.history()
    report.analysis_sections()
    report.methodology()
    output = io.BytesIO()
    doc = SimpleDocTemplate(output, pagesize=A4, leftMargin=MARGIN, rightMargin=MARGIN,
                            topMargin=57, bottomMargin=58, title="Laporan Evaluasi dan Perkembangan UMKM",
                            author="JoyUMKM Intelligence")
    doc.build(report.story, onFirstPage=report.page, onLaterPages=report.page)
    return output.getvalue()

