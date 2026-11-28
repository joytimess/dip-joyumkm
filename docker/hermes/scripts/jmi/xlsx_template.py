"""Editable JoyUMKM workbook, rendered from the validated monthly report."""
import io
import re
import textwrap
from datetime import datetime, timezone

import xlsxwriter
from xlsxwriter.utility import xl_col_to_name

from .pdf_template import KEYS, PRIORITY, REDACT, Report

SHEETS = ("Ringkasan", "Enam Faktor", "Tiga Dimensi", "Histori", "Rekomendasi")
NAVY, BLUE, CYAN, CORAL = "#101E40", "#0207CA", "#0297DE", "#FC734D"
TREND = {"improved": "Membaik", "declined": "Menurun", "unchanged": "Tetap",
         "insufficient_data": "Belum sebanding"}
GAP = "Data belum tersedia"
METRICS = ("Indikator", "Maksimum", "Bulan sebelumnya", "Bulan berjalan", "Selisih absolut", "Arah perubahan")
INSTRUMENTS = ("six_factor", "three_dimension")
TIMES = ("finalized_at", "assessed_at")
MARKUP = re.compile(r"(?m)^\s{0,3}(?:#{1,6}\s+|>\s+)|\*\*|__|"+r"\x60")
CONTROLS = re.compile(r"[\x00-\x09\x0b-\x1f\x7f-\x9f\u202a-\u202e\u2066-\u2069]")


def clean(value):
    return MARKUP.sub("", CONTROLS.sub(" ", REDACT.sub("[disamarkan]", value)))


def date(value):
    return datetime.fromisoformat(value.replace("Z", "+00:00")).astimezone(timezone.utc).replace(tzinfo=None) if value else None


def month(value):
    return datetime.fromisoformat(value + "-01")


class Sheet:
    def __init__(self, book, name, widths):
        self.book = book
        self.ws = book.add_worksheet(name)
        self.widths = widths
        self.row = 0
        self.formats = {}
        for col, width in enumerate(widths):
            self.ws.set_column(col, col, width)
        self.ws.hide_gridlines(2)
        self.ws.set_tab_color(BLUE)
        self.ws.set_landscape()
        self.ws.set_paper(8 if sum(widths) > 190 else 9)
        self.ws.fit_to_pages(1, 0)
        self.ws.set_margins(.25, .25, .35, .35)
        self.ws.set_footer("&LJoyUMKM Intelligence&R&P / &N")

    def fmt(self, role="text", band=False):
        if (role, band) not in self.formats:
            style = {"font_name": "Calibri", "font_size": 11, "font_color": NAVY,
                     "valign": "top", "text_wrap": True}
            if band:
                style["bg_color"] = "#F0F5FA"
            style.update({
                "title": {"font_name": "Poppins", "bold": True, "font_size": 22, "font_color": "#FFFFFF", "bg_color": NAVY},
                "section": {"bold": True, "font_size": 13, "font_color": BLUE, "bg_color": "#EAF3FA"},
                "label": {"bold": True},
                "header": {"bold": True, "font_color": "#FFFFFF", "bg_color": NAVY},
                "trend": {"indent": 1, "align": "left"},
                "integer": {"num_format": "0", "align": "right"},
                "number": {"num_format": "#,##0.######", "align": "right"},
                "month": {"num_format": "mmm yyyy"},
                "date": {"num_format": 'dd mmm yyyy hh:mm:ss "UTC"'},
            }.get(role, {}))
            self.formats[role, band] = self.book.add_format(style)
        return self.formats[role, band]

    def heading(self, text, role="section"):
        if self.row:
            self.row += 1
        self.ws.merge_range(self.row, 0, self.row, len(self.widths) - 1, "", self.fmt(role))
        self.ws.write_string(self.row, 0, clean(text), self.fmt(role))
        self.ws.set_row(self.row, 37 if role == "title" else 24)
        self.row += 1

    def note(self, text):
        if not text:
            return
        clean_text = clean(text)
        width = max(56, int(sum(self.widths) * .73))
        lines = [line for paragraph in clean_text.split("\n")
                 for line in (textwrap.wrap(paragraph, width=width) or [""])]
        for first in range(0, len(lines), 18):
            chunk = lines[first:first + 18]
            self.ws.merge_range(self.row, 0, self.row, len(self.widths) - 1, "", self.fmt())
            self.ws.write_string(self.row, 0, "\n".join(chunk), self.fmt())
            self.ws.set_row(self.row, max(21, 16 * len(chunk) + 6))
            self.row += 1

    def cell(self, row, col, value, band=False, role=None):
        if isinstance(value, str):
            self.ws.write_string(row, col, clean(value), self.fmt(role or "text", band))
        elif isinstance(value, datetime):
            self.ws.write_datetime(row, col, value, self.fmt(role or "date", band))
        elif value is not None:
            self.ws.write_number(row, col, value, self.fmt(role or "number", band))
        else:
            self.ws.write_blank(row, col, None, self.fmt("text", band))

    def table(self, headers, rows, *, first=False, month_cols=(), metrics=None):
        head = self.row
        if not rows:
            self.note(GAP)
            return head, head
        end = head + len(rows)
        if self.ws.add_table(head, 0, end, len(headers) - 1, {
            "style": "Table Style Medium 2",
            "columns": [{"header": h, "header_format": self.fmt("header")} for h in headers],
        }):
            raise ValueError("table")
        self.ws.set_row(head, 32)
        if first:
            self.ws.freeze_panes(head + 1, 1)
            self.ws.repeat_rows(0, head)
        for offset, values in enumerate(rows, 1):
            row = head + offset
            lines = 1
            for col, value in enumerate(values):
                role = "month" if col in month_cols else "trend" if metrics and col == metrics[-1] else None
                self.cell(row, col, value, offset % 2 == 0, role)
                if isinstance(value, str):
                    width = max(6, int(self.widths[col] * .73))
                    lines = max(lines, sum(max(1, len(textwrap.wrap(p, width))) for p in clean(value).split("\n")))
            self.ws.set_row(row, min(409, max(24, lines * 15 + 7)))
        if metrics is not None:
            prev, curr, delta, trend = metrics
            for row, values in enumerate(rows, head + 1):
                if values[delta] is None:
                    continue
                left, right = (xl_col_to_name(col) + str(row + 1) for col in (prev, curr))
                formula = f'=IF(COUNT({left}:{right})=2,ROUND(ABS({right}-{left}),10),"")'
                self.ws.write_formula(row, delta, formula, self.fmt("number", (row - head) % 2 == 0), values[delta])
            for label, color in zip(TREND.values(), ("#DDF2E5", "#FBE2E1", "#EDF1F6", "#E6E8EC")):
                self.ws.conditional_format(head + 1, trend, end, trend, {
                    "type": "cell", "criteria": "==", "value": '"' + label + '"',
                    "format": self.book.add_format({"bg_color": color, "font_color": NAVY}),
                })
        self.row = end + 1
        return head + 1, end

    def finish(self):
        self.ws.print_area(0, 0, self.row - 1, len(self.widths) - 1)


def metric(data):
    return (data["maximum"], data["previous"], data["current"], data["absolute_difference"], TREND[data["trend"]])


def findings(sheet, model, items):
    for finding in items:
        sheet.note(finding["statement"])
        if finding["evidence_refs"]:
            sheet.note("Bukti · " + model.refs(finding["evidence_refs"]))


def chart(sheet, title, bounds, category, series, maximum, *, history=False):
    first, last = bounds
    graphic = sheet.book.add_chart({"type": "line" if history else "column"})
    for index, (name, col, color) in enumerate(series):
        marker = ("circle", "square", "diamond")[index % 3]
        graphic.add_series({
            "name": name, "categories": [sheet.ws.name, first, category, last, category],
            "values": [sheet.ws.name, first, col, last, col],
            **({"line": {"color": color, "width": 2},
                "marker": {"type": marker, "size": 6, "fill": {"color": color},
                           "border": {"color": color}}} if history else {"fill": {"color": color},
                "data_labels": {"value": True, "num_format": "0.##"}}),
        })
    graphic.show_blanks_as("gap")
    graphic.set_title({"name": title})
    graphic.set_legend({"position": "bottom"})
    graphic.set_x_axis({"name": "Periode WIB" if history else "Indikator", **(
        {"date_axis": True, "num_format": "mmm yy", "major_unit": 1, "major_unit_type": "months"} if history else {})})
    graphic.set_y_axis({"name": "Skor", "min": 0, "max": maximum})
    graphic.set_size({"width": 720, "height": 304})
    sheet.ws.insert_chart(sheet.row, 0, graphic)
    for row in range(sheet.row, sheet.row + 17):
        sheet.ws.set_row(row, 15)
    sheet.row += 17


def render(doc):
    model = Report(doc)
    output = io.BytesIO()
    with xlsxwriter.Workbook(output, {"in_memory": True, "strings_to_formulas": False,
                                     "strings_to_urls": False, "strings_to_numbers": False}) as book:
        summary = Sheet(book, SHEETS[0], [29, 22, 21, 21, 20, 24])
        summary.heading("JoyUMKM Intelligence", "title")
        summary.note("Laporan Evaluasi dan Perkembangan UMKM")
        period = model.period
        name = model.profile.get("name") or model.report["business_profile"].get("name")
        identity = [("Nama UMKM", name), ("Sektor usaha", model.profile.get("sector")),
                    ("Nomor referensi", model.reference), ("Periode berjalan (WIB)", month(period["current"])),
                    ("Periode sebelumnya (WIB)", month(period["previous"])),
                    ("Tanggal penerbitan", date(doc["generated_at"])),
                    ("Cutoff penilaian", date(period["cutoff"]["at"]))]
        for key, label in (("location", "Lokasi"), ("started_year", "Tahun mulai"),
                           ("employee_count", "Jumlah pekerja"), ("business_capital", "Modal usaha (Rp)")):
            if model.profile.get(key) is not None:
                identity.append((label, model.profile[key]))
        revenue = model.profile.get("revenue") or {}
        if revenue.get("amount") is not None:
            label = {"monthly": "bulanan", "annual": "tahunan", "yearly": "tahunan", "daily": "harian"}.get(revenue.get("period"), "")
            identity.append((f"Omzet {label} (Rp)", revenue["amount"]))
        for label, value in identity:
            summary.cell(summary.row, 0, label, role="label")
            if isinstance(value, str):
                summary.ws.merge_range(summary.row, 1, summary.row, 5, "", summary.fmt())
                summary.cell(summary.row, 1, value)
                summary.ws.set_row(summary.row, min(409, max(22, 16 * len(textwrap.wrap(clean(value), 75)) + 6)))
            else:
                summary.cell(summary.row, 1, value, role="month" if label.startswith("Periode") else "integer" if label == "Tahun mulai" else None)
                summary.ws.set_row(summary.row, 22)
            summary.row += 1
        summary.note("Bulan berjalan mencakup penilaian hingga cutoff. Profil berlaku pada saat peristiwa penilaian.")
        summary.heading("Metrik penilaian")
        six, three = [model.instruments[key]["metrics"] for key in INSTRUMENTS]
        summary.table(METRICS, [(item["label"], *metric(item)) for item in
                                [six["total_score"], *(three[k] for k in KEYS["three_dimension"])]],
                      metrics=(2, 3, 4, 5))
        summary.ws.freeze_panes(2, 0)
        summary.ws.repeat_rows(0, 1)
        summary.heading("Ringkasan eksekutif")
        for body in (model.report["executive_summary"], model.analysis["business_overview"],
                     model.analysis["monthly_development"]):
            summary.note(body)
        summary.heading("Temuan utama")
        for instrument in INSTRUMENTS:
            findings(summary, model, model.report[instrument + "_analysis"]["findings"])
        summary.heading("Prioritas perbaikan")
        for item in model.report["top_priorities"][:3]:
            summary.note(f"{item['id']} · {item['title']}")
        summary.heading("Keterbatasan data dan metodologi")
        for note in model.report["limitations"]:
            summary.note(note)
        summary.note("Sel kosong menunjukkan data belum tersedia. Kedua instrumen menggunakan skala masing-masing. Periode mengikuti kalender WIB dan tanggal sumber ditampilkan dalam UTC.")
        summary.finish()

        for instrument, name, time_key in zip(INSTRUMENTS, SHEETS[1:3], TIMES):
            sheet = Sheet(book, name, [18, 24, 12, 16, 16, 16, 20, 20, 15, 27, 20, 15, 27])
            sheet.heading(name, "title")
            data = model.instruments[instrument]
            current, previous = data["current"], data["previous"]
            sheet.note("Bulan berjalan hingga cutoff penilaian. Kategori dan tanggal sumber ditampilkan per periode.")
            if current["status"] == "data_gap":
                sheet.note(f"Data {name} belum tersedia pada periode berjalan hingga cutoff penilaian.")
            block = model.report[instrument + "_analysis"]
            rows = []
            for key in KEYS[instrument]:
                item = data["metrics"][key]
                rows.append((key, item["label"], *metric(item),
                             current["category"], current["evaluation_id"], date(current[time_key]),
                             previous["category"], previous["evaluation_id"], date(previous[time_key])))
            headers = ("ID indikator", *METRICS, "Kategori berjalan", "ID berjalan", "Tanggal berjalan (UTC)",
                       "Kategori sebelumnya", "ID sebelumnya", "Tanggal sebelumnya (UTC)")
            six_factor = instrument == "six_factor"
            start, end = sheet.table(headers, rows[:-1] if six_factor else rows, first=True, metrics=(3, 4, 5, 6))
            if six_factor:
                sheet.heading("Total Enam Faktor")
                row, _ = sheet.table(headers, rows[-1:], metrics=(3, 4, 5, 6))
                for col in (3, 4):
                    if rows[-1][col] is not None:
                        letter = xl_col_to_name(col)
                        span = f"{letter}{start + 1}:{letter}{end + 1}"
                        formula = f'=IF(COUNT({span})=6,SUM({span}),"")'
                        sheet.ws.write_formula(row, col, formula, sheet.fmt("number"), rows[-1][col])
            sheet.heading("Interpretasi dan temuan")
            sheet.note(block["summary"])
            for ref, body in model.analysis["indicators"].items():
                if ref.startswith(instrument + "."):
                    sheet.note(f"{model.refs([ref])} · {body}")
            findings(sheet, model, block["findings"])
            if instrument == "six_factor":
                for key, title in (("strengths", "Kekuatan"), ("weaknesses", "Kelemahan")):
                    sheet.heading(title)
                    for ref, body in model.analysis[key].items():
                        sheet.note(f"{model.refs([ref])} · {body}")
            else:
                sheet.heading("Keterbatasan data")
                if current.get("is_complete") is False:
                    sheet.note("Penilaian Tiga Dimensi pada periode berjalan belum lengkap.")
                for note in model.report["limitations"]:
                    sheet.note(note)
            sheet.finish()

        history = Sheet(book, SHEETS[3], [22, 16, 27] + [20] * 6 + [16, 14, 23])
        history.heading("Histori Penilaian", "title")
        history.note("Periode kalender WIB. Bulan berjalan berakhir pada cutoff penilaian. Sel skor kosong menunjukkan ketiadaan pengamatan.")
        for index, (instrument, time_key, name) in enumerate(zip(INSTRUMENTS, TIMES, SHEETS[1:3])):
            if index:
                history.ws.set_h_pagebreaks([history.row])
            history.heading("Histori " + name)
            keys = KEYS[instrument]
            metrics = model.instruments[instrument]["metrics"]
            rows = []
            for entry in reversed(model.extension["history"]):
                source = entry[instrument]
                scores = {**(source["scores"] or {})}
                if not index:
                    scores["total_score"] = source["total_score"]
                rows.append((month(entry["period_month"]), source["evaluation_id"], date(source[time_key]),
                             *(scores.get(k) for k in keys),
                             *((metrics["total_score"]["maximum"],) if not index else ()),
                             "Tersedia" if source["status"] == "available" else GAP))
            bounds = history.table(("Periode (WIB)", "ID penilaian", "Tanggal sumber (UTC)",
                                    *(metrics[k]["label"] for k in keys), *(("Maksimum total",) if not index else ()),
                                    "Ketersediaan"), rows, first=index == 0, month_cols=(0,))
            comparison_keys = keys if index else ("total_score",)
            maximum = metrics[comparison_keys[0]]["maximum"]
            comparison = [(metrics[k]["label"], metrics[k]["previous"], metrics[k]["current"])
                          for k in comparison_keys]
            history.heading("Perbandingan " + name)
            pair = history.table(("Indikator", period["previous"], period["current"] + " hingga cutoff"), comparison)
            if any(previous is not None and current is not None for _, previous, current in comparison):
                chart(history, "Perbandingan " + name, pair, 0,
                      [(period["previous"], 1, CYAN), (period["current"], 2, BLUE)], maximum)
            else:
                history.note("Grafik perbandingan tidak tersedia karena periode belum memiliki pembanding yang sepadan.")
            history.heading("Perkembangan " + name)
            series = [(metrics[k]["label"], 3 + keys.index(k), color)
                      for k, color in zip(comparison_keys, (BLUE, CYAN, CORAL))]
            series = [item for item in series if any(row[item[1]] is not None for row in rows)]
            if any(sum(row[col] is not None for row in rows) >= 2 for _, col, _ in series):
                chart(history, "Perkembangan " + name, bounds, 0, series, maximum, history=True)
            else:
                history.note("Grafik perkembangan memerlukan sedikitnya dua periode pengamatan.")
        history.ws.repeat_rows(0, 1)
        history.ws.set_print_scale(60)
        history.finish()

        action = Sheet(book, SHEETS[4], [14, 15, 35, 31, 39, 36, 35, 30])
        action.heading("Rekomendasi dan Rencana Tindakan", "title")
        action.heading("Prioritas perbaikan")
        priorities = [(item["id"], PRIORITY[item["priority"]], item["title"] + "\n" + item["rationale"],
                       model.refs(item["evidence_refs"]), "\n".join(item["actions"]),
                       model.analysis["expected_outcomes"][item["id"]], "\n".join(item["success_metrics"]),
                       "\n".join(item["assumptions"])) for item in model.report["top_priorities"][:3]]
        action.table(("ID prioritas", "Tingkat", "Permasalahan", "Indikator pendukung", "Rekomendasi",
                      "Hasil yang diharapkan", "Indikator keberhasilan", "Asumsi"), priorities, first=True)
        for phase, title in (("days_1_30", "30 hari · Tindakan awal"),
                             ("days_31_60", "60 hari · Pengembangan dan pemantauan"),
                             ("days_61_90", "90 hari · Evaluasi dan tindak lanjut")):
            action.heading(title)
            rows = [(identity["id"], item["priority_id"], identity["time_horizon_days"], item["action"], item["metric"])
                    for item, identity in zip(model.report["action_plan"][phase], model.extension["action_identity"][phase])]
            action.table(("ID tindakan", "ID prioritas", "Horizon (hari)", "Tindakan", "Indikator keberhasilan"), rows)
        action.finish()
    return output.getvalue()

