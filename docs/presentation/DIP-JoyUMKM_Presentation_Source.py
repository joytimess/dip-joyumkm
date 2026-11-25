"""Generate the editable 16:9 deck with Python 3 and python-pptx 1.0.2."""
from pathlib import Path

from pptx import Presentation
from pptx.dml.color import RGBColor
from pptx.enum.shapes import MSO_AUTO_SHAPE_TYPE as Shape, MSO_CONNECTOR
from pptx.enum.text import MSO_ANCHOR, PP_ALIGN
from pptx.oxml.xmlchemy import OxmlElement
from pptx.util import Inches as I, Pt

OUT = Path(__file__).resolve().parent
P = Presentation()
P.slide_width, P.slide_height = I(13.333333), I(7.5)
P.core_properties.title = "DIP-JoyUMKM — Academic Presentation"
NAVY, BLUE, SKY, CORAL = "101E40", "0207CA", "0297DE", "FC734D"
BG, WHITE, LINE, MUTED, PALE = "F4F7FD", "FFFFFF", "D5DFF1", "465678", "E8EFFF"
FONT = "Arial"
REV = "main 9fa9ccb • checkout lokal; deploy-prod 804738b sebagai pembanding"
P6 = "docs/audit/P6_JoyUMKM_Full_Autonomous_UAT_Completion.md"
HANDOFF = "docs/audit/JoyUMKM_Hermes_FINAL_HANDOFF.md"
FOOT = "DIP-JoyUMKM: Penilaian UMKM & Otomasi Hermes Agent"


def color(value):
    return RGBColor.from_string(value)


def rect(s, x, y, w, h, fill, border=None, kind=Shape.RECTANGLE):
    sh = s.shapes.add_shape(kind, I(x), I(y), I(w), I(h))
    style = sh._element.find('{http://schemas.openxmlformats.org/presentationml/2006/main}style')
    if style is not None:
        sh._element.remove(style)
    sh.fill.solid()
    sh.fill.fore_color.rgb = color(fill)
    if border:
        sh.line.color.rgb = color(border)
    else:
        sh.line.fill.background()
    if kind == Shape.ROUNDED_RECTANGLE:
        sh.adjustments[0] = .12
    return sh


def fmt(frame, value, size, rgb, bold=False, alignment=PP_ALIGN.LEFT, middle=False):
    frame.clear()
    frame.word_wrap = True
    frame.margin_left = frame.margin_right = frame.margin_top = frame.margin_bottom = 0
    if middle:
        frame.vertical_anchor = MSO_ANCHOR.MIDDLE
    for idx, line in enumerate(value.split("\n")):
        p = frame.paragraphs[0] if idx == 0 else frame.add_paragraph()
        p.text, p.alignment = line, alignment
        p.space_before, p.space_after, p.line_spacing = Pt(0), Pt(0), 1.08
        p.font.name, p.font.size, p.font.bold = FONT, Pt(size), bold
        p.font.color.rgb = color(rgb)


def tx(s, x, y, w, h, value, size=18, rgb=NAVY, bold=False, center=False, middle=False):
    sh = s.shapes.add_textbox(I(x), I(y), I(w), I(h))
    fmt(sh.text_frame, value, size, rgb, bold, PP_ALIGN.CENTER if center else PP_ALIGN.LEFT, middle)
    return sh


def node(s, x, y, w, h, value, fill=WHITE, rgb=NAVY, size=16):
    sh = rect(s, x, y, w, h, fill, None if fill != WHITE else LINE, Shape.ROUNDED_RECTANGLE)
    fmt(sh.text_frame, value, size, rgb, True, PP_ALIGN.CENTER, True)
    sh.text_frame.margin_left = sh.text_frame.margin_right = I(.08)
    return sh


def edge(s, x1, y1, x2, y2, rgb=BLUE, arrow=True, width=2, source=None, target=None, ports=(3,1)):
    sh = s.shapes.add_connector(MSO_CONNECTOR.STRAIGHT, I(x1), I(y1), I(x2), I(y2))
    style = sh._element.find('{http://schemas.openxmlformats.org/presentationml/2006/main}style')
    if style is not None:
        sh._element.remove(style)
    sh.line.color.rgb = color(rgb)
    sh.line.width = Pt(width)
    if arrow:
        tip = OxmlElement("a:tailEnd")
        tip.set("type", "triangle")
        sh.line._get_or_add_ln().append(tip)
    for tag, shape, idx in zip(("stCxn", "endCxn"), (source, target), ports):
        if shape is not None:
            link = OxmlElement("a:" + tag)
            link.set("id", str(shape.shape_id))
            link.set("idx", str(idx))
            sh._element.nvCxnSpPr.cNvCxnSpPr.append(link)
    return sh


def new(title=None, section=None, dark=False):
    s = P.slides.add_slide(P.slide_layouts[6])
    bg = s.background.fill
    bg.solid()
    bg.fore_color.rgb = color(NAVY if dark else BG)
    ink = WHITE if dark else NAVY
    foot = "C9D8FF" if dark else MUTED
    if title:
        tx(s, .65, .39, 12, .24, section.upper(), 11, "A7DAFF" if dark else BLUE, True)
        tx(s, .65, .77, 12.03, .58, title, 34, ink, True)
    edge(s, .65, 7.02, 12.68, 7.02, "52658F" if dark else LINE, False, 1)
    tx(s, .65, 7.12, 11, .21, FOOT, 10, foot)
    tx(s, 11.87, 7.10, .81, .26, f"{len(P.slides):02d} / 14", 10, foot, True, True)
    return s


def notes(s, prose, *refs):
    s.notes_slide.notes_text_frame.text = prose + "\n\n" + "\n".join(
        f"Repository: {path}\nEvidence: {proof}\nRevision: {REV}"
        for path, proof in refs
    )


def card(s, x, y, w, h, title, body, accent=BLUE, size=18):
    rect(s, x, y, w, h, WHITE, LINE, Shape.ROUNDED_RECTANGLE)
    rect(s, x+.22, y+.24, .37, .058, accent)
    tx(s, x+.22, y+.44, w-.44, .42, title, 22, NAVY, True)
    tx(s, x+.22, y+1, w-.44, h-1.12, body, size, MUTED)


def band(s, text, y=6.36, fill=PALE, ink=NAVY, size=17, h=.44):
    rect(s, .65, y, 12.03, h, fill)
    tx(s, .82, y+.02, 11.68, h-.04, text, size, ink, True, middle=True)


def table(s, x, y, widths, heights, data, size=16):
    obj = s.shapes.add_table(len(data), len(widths), I(x), I(y), I(sum(widths)), I(sum(heights))).table
    for c, w in zip(obj.columns, widths):
        c.width = I(w)
    for r, (height, values) in enumerate(zip(heights, data)):
        obj.rows[r].height = I(height)
        for c, value in enumerate(values):
            cell = obj.cell(r, c)
            cell.fill.solid()
            cell.fill.fore_color.rgb = color(NAVY if r == 0 else (WHITE if r % 2 else PALE))
            fmt(cell.text_frame, value, size, WHITE if r == 0 else NAVY, r == 0 or c == 0, middle=True)
            cell.vertical_anchor = MSO_ANCHOR.MIDDLE
            cell.margin_left = cell.margin_right = I(.12)
            cell.margin_top = cell.margin_bottom = I(.025)
    return obj


s = new(dark=True)
rect(s, .69, 1.22, .48, .075, CORAL)
tx(s, .68, 1.53, 7.3, 1, "DIP-JoyUMKM", 57, WHITE, True)
tx(s, .72, 2.64, 6.65, 1.4, "Sistem Penilaian UMKM dan Otomasi AI Intelligence Reporting", 28, "DCE8FF")
tx(s, .73, 5.05, 6.6, .94, "[Nama Mahasiswa · NIM]\n[Mata Kuliah]\n[Institusi]", 17, "C9D8FF")
rect(s, 8.12, 1.20, 4.51, 4.95, BLUE, kind=Shape.ROUNDED_RECTANGLE)
rect(s, 8.61, 1.75, 2.92, 2.37, WHITE, kind=Shape.ROUNDED_RECTANGLE)
for i, height in enumerate((.32, .57, .44, .81, .66, 1.03)):
    rect(s, 8.87+i*.39, 3.65-height, .24, height, CORAL if i == 5 else SKY)
edge(s, 8.86, 3.71, 11.12, 3.71, LINE, False, 1)
edge(s, 10.09, 4.12, 10.09, 5.06, WHITE, False)
edge(s, 10.09, 5.06, 10.69, 5.06, WHITE)
rect(s, 10.69, 4.57, 1.45, 1.04, PALE, kind=Shape.ROUNDED_RECTANGLE)
for i in range(3):
    rect(s, 10.91, 4.82+i*.17, .64+i*.10, .055, BLUE)
notes(s, "Presentasi ini membahas implementasi DIP-JoyUMKM dan hasil uji yang tercatat. Fokusnya adalah hubungan penilaian internal dengan laporan yang dihasilkan lewat otomasi.", ("README.md", "Ruang lingkup dashboard dan alur API"), (HANDOFF, "Handoff final P6"))

s = new("Latar Belakang", "Konteks proyek")
tx(s, .65, 1.61, 12, .50, "Owner UMKM membutuhkan cara menilai kondisi usaha dan menentukan tindak lanjut.", 21)
for i, (title, body) in enumerate((
    ("Penilaian kurang terstruktur", "Indikator belum ditata dalam kerangka yang konsisten."),
    ("Persepsi subjektif", "Penilaian bergantung pada kesan pribadi."),
    ("Hasil perlu ditafsirkan", "Skor perlu diterjemahkan menjadi prioritas perbaikan."),
    ("Laporan manual", "Penyusunan laporan menambah waktu dan usaha."),
)):
    card(s, .65+(i%2)*6.14, 2.26+(i//2)*2.08, 5.89, 1.81, title, body, CORAL if i == 3 else BLUE)
notes(s, "Empat masalah ini merupakan rumusan kebutuhan proyek. Instrumen yang sama membantu owner meninjau penilaian lintas waktu, sementara rekomendasi memberi konteks tambahan untuk memilih tindakan. Besaran masalah belum diukur dengan studi pengguna.", ("docs/prd/JoyUMKM_V1_PRD_Brief_P0.md", "Baseline dan konteks produk"), ("README.md", "Tujuan dashboard kesehatan UMKM"))

s = new("Tujuan dan Solusi", "Konteks proyek")
for i, (title, text) in enumerate((
    ("Assessment", "Enam Faktor\nSumber Daya\nKepemimpinan\nLingkungan Kerja\nNilai Organisasi\nStabilitas · Kinerja"),
    ("Insight", "Dashboard dan histori Final\nTiga Dimensi\nSumber Daya Kelembagaan (INS)\nKonektivitas Ekonomi (ECT)\nStabilitas Operasional (OPS)"),
    ("Action", "Intelligence Reporting\nAnalisis dan rekomendasi AI\nLaporan PDF dan Excel\nDistribusi email dengan persetujuan"),
)):
    x = .65+i*4.11
    rect(s, x, 2.00, 3.81, 3.88, WHITE, LINE, Shape.ROUNDED_RECTANGLE)
    rect(s, x, 2.00, 3.81, .67, BLUE if i < 2 else NAVY)
    tx(s, x+.18, 2.10, 3.44, .38, title, 24, WHITE, True)
    tx(s, x+.18, 2.93, 3.44, 2.72, text, 18)
    if i < 2:
        edge(s, x+3.81, 2.36, x+4.11, 2.36)
band(s, "Survei anonim owner–karyawan dan perbandingan persepsi masih konseptual.", size=16)
notes(s, "Enam Faktor memakai skor per faktor 1–5. Tiga Dimensi berasal dari alur evaluasi tersendiri dan dapat tidak tersedia dalam laporan tertentu. Audit rute dan model tidak menemukan alur survei anonim karyawan yang aktif.", ("resources/js/six-factor-draft.js", "sixFactors dan valid"), ("resources/js/three-dimension-health-data.js", "dimensionNames"), ("routes/api.php", "Rute Enam Faktor, Tiga Dimensi dan report"), (P6, "Contoh Tiga Dimensi unavailable"))

s = new("System Architecture", "Implementasi")
rect(s, 2.09, 1.65, 5.08, 4.69, WHITE, LINE, Shape.ROUNDED_RECTANGLE)
rect(s, 9.12, 1.65, 3.56, 4.69, PALE, LINE, Shape.ROUNDED_RECTANGLE)
tx(s, 2.29, 1.85, 4.5, .35, "Laravel", 22, BLUE, True)
tx(s, 9.30, 1.85, 3.18, .35, "Hermes Agent · Docker", 20, BLUE, True)
user = node(s, .65, 2.43, 1.21, .73, "User")
web = node(s, 2.30, 2.43, 1.76, .73, "Web Interface")
api = node(s, 5.53, 2.43, 1.43, .73, "Laravel API", BLUE, WHITE)
svc = node(s, 5.53, 4.03, 1.43, .67, "Services")
db = node(s, 5.53, 5.20, 1.43, .65, "MySQL")
intake = node(s, 9.34, 2.43, 3.12, .73, "Intake webhook + snapshot", NAVY, WHITE)
ai = node(s, 9.34, 3.68, 3.12, .86, "Analisis AI\nPenyusunan rekomendasi")
package = node(s, 9.34, 5.10, 3.12, .87, "Generator PDF & Excel\nManifest artefak")
edge(s, 1.86, 2.80, 2.30, 2.80, source=user, target=web)
edge(s, 4.06, 2.80, 5.53, 2.80, source=web, target=api)
edge(s, 6.24, 3.16, 6.24, 4.03, source=api, target=svc, ports=(2,0))
edge(s, 6.24, 4.70, 6.24, 5.20, source=svc, target=db, ports=(2,0))
edge(s, 10.90, 3.16, 10.90, 3.68, source=intake, target=ai, ports=(2,0))
edge(s, 10.90, 4.54, 10.90, 5.10, source=ai, target=package, ports=(2,0))
edge(s, 6.96, 2.58, 9.34, 2.58)
tx(s, 7.20, 2.29, 1.94, .27, "Webhook HMAC", 13, BLUE, True, True)
edge(s, 9.34, 3.02, 6.96, 3.02, SKY)
tx(s, 7.17, 3.13, 1.98, .58, "GET snapshot\nbertanda tangan", 13, NAVY, True, True)
edge(s, 9.34, 5.53, 8.07, 5.53, SKY, False)
edge(s, 8.07, 5.53, 8.07, 3.82, SKY, False)
edge(s, 8.07, 3.82, 6.76, 3.82, SKY, False)
edge(s, 6.76, 3.82, 6.76, 3.16, SKY)
tx(s, 7.19, 4.08, .82, 1.15, "Callback\n+ paket\n(HMAC)", 12, NAVY, True, True)
email = node(s, 8.34, 6.42, 2.07, .45, "Email · SMTP", CORAL, NAVY, 14)
owner = node(s, 10.77, 6.42, 1.91, .45, "Owner UMKM", WHITE, NAVY, 14)
edge(s, 9.75, 5.97, 9.75, 6.42, BLUE)
edge(s, 10.41, 6.64, 10.77, 6.64, source=email, target=owner)
notes(s, "Laravel menyimpan data dan memberi Hermes akses snapshot via API. Panah GET menunjukkan arah permintaan Hermes, dengan data kembali melalui respons HTTP. Jalur callback mencakup upload biner, callback report dan sinkronisasi status email yang ditandatangani secara terpisah. Dua keluaran memakai paket lokal tervalidasi, dan jalur email juga memerlukan persetujuan serta pemeriksaan kelayakan penerima.", ("routes/api.php", "Rute internal HMAC snapshot, callback, upload, recipient dan status"), ("app/Services/HermesWebhookSender.php", "Pengiriman webhook bertanda tangan"), ("Hermes Docker /opt/data/joyumkm-intelligence/jmi/", "pipeline.py, delivery.py, email_delivery.py, snapshot.py"), (P6, "Bukti UAT bounded sampai output"))

s = new("Technology Stack", "Implementasi")
for i, (label, detail) in enumerate((
    ("Presentation", "Blade · JavaScript · Tailwind CSS 4 · ApexCharts"),
    ("Application", "Laravel 13 API-first · Form Request · API Resource · Service\nSanctum Bearer Token · pemeriksaan kepemilikan UMKM"),
    ("Persistence", "MySQL · profil UMKM · evaluasi dan histori\nMetadata laporan, artefak dan status pengiriman"),
    ("Automation", "Hermes Agent di Docker · HMAC · snapshot · AI\nPDF dan Excel · email SMTP · callback"),
    ("Reliability & QA", "Transactional outbox · idempotensi · retry dan recovery\nValidasi hash/manifest · PHPUnit · Playwright · UAT"),
)):
    y = 1.65+i*1.02
    rect(s, .65, y, 12.03, .87, WHITE if i%2 == 0 else PALE)
    rect(s, .65, y, .055, .87, CORAL if i == 4 else BLUE)
    tx(s, .87, y+.08, 3.1, .70, label, 21, BLUE, True, middle=True)
    tx(s, 4.06, y+.08, 8.35, .70, detail, 17, middle=True)
notes(s, "Blade menyajikan halaman dan JavaScript mengambil data dari API. Form Request memvalidasi input, Service menjalankan aturan bisnis, sedangkan API Resource menyusun respons. MySQL adalah basis data pada implementasi lokal dan UAT. Audit hosting D0 mencatat jalur kompatibilitas MariaDB yang bersyarat.", ("composer.json / composer.lock / package.json / package-lock.json", "Dependency Laravel, Sanctum, Tailwind dan ApexCharts"), ("app/Http/Requests, app/Http/Resources, app/Services dan database/migrations", "Layer aplikasi, kepemilikan, persistensi"), ("docs/audit/D0_JoyUMKM_Deployment_Compatibility_Report.md", "Verifikasi hosting tersisa"))

s = new("Why Hermes Agent?", "Keputusan arsitektur")
for i, (title, body) in enumerate((
    ("Trigger dari finalisasi", "Webhook memulai pekerjaan ketika evaluasi menjadi Final."),
    ("Analisis berbasis bukti", "Snapshot memberi AI skor, histori, dan konteks yang tersedia."),
    ("Satu paket laporan", "Pipeline membangun PDF dan Excel dari laporan kanonis."),
    ("Proses terisolasi", "Docker memisahkan kerja AI, render dan distribusi dari API utama."),
)):
    card(s, .65+(i%2)*6.14, 1.70+(i//2)*2.00, 5.89, 1.75, title, body, CORAL if i == 0 else BLUE)
band(s, "Hermes menjalankan agen AI. Integrasi JoyUMKM mengatur event, render, pengiriman, dan recovery.", size=15)
notes(s, "Pemilihan Hermes mengikuti kebutuhan Intelligence Reporting. Hermes menyediakan runtime agen dan akses model. Modul JMI buatan proyek mengatur intake, validasi keluaran, idempotensi, retry, recovery dan perpindahan artefak. n8n adalah referensi modul perkuliahan, dan presentasi ini tidak membuat peringkat antartool.", (HANDOFF, "Penerimaan fase P0–P6"), ("docs/audit/P3-06_JoyUMKM_Pipeline_Integration_Recovery.md", "Pipeline render dan recovery"), ("Hermes Docker /opt/data/joyumkm-intelligence/jmi/", "engine.py, pipeline.py, email_delivery.py"))

s = new("Laravel vs Hermes Agent", "Pembagian tanggung jawab")
for i, (title, sub, entries) in enumerate((
    ("Laravel", "System of Record", ("Autentikasi dan otorisasi", "Validasi input dan skor deterministik", "Penyimpanan evaluasi dan histori", "API dan snapshot bertanda tangan", "Penerimaan callback dan artefak", "Dashboard dan unduhan owner")),
    ("Hermes Agent", "Intelligence and Automation", ("Intake event dan pengambilan snapshot", "Analisis AI dan rekomendasi", "Perbandingan histori jika tersedia", "Pembuatan PDF, Excel, dan manifest", "Email untuk penerima yang memenuhi syarat", "Pengiriman artefak dan status ke Laravel")),
)):
    x = .65+i*6.14
    rect(s, x, 1.69, 5.89, 4.98, WHITE, LINE)
    rect(s, x, 1.69, 5.89, 1.18, BLUE if i == 0 else NAVY)
    tx(s, x+.22, 1.83, 5.4, .43, title, 25, WHITE, True)
    tx(s, x+.22, 2.27, 5.4, .34, sub, 16, "DCE8FF")
    for n, item in enumerate(entries):
        y = 3.14+n*.52
        rect(s, x+.22, y+.12, .075, .075, CORAL if i else BLUE)
        tx(s, x+.43, y, 5.16, .40, item, 17)
notes(s, "Skor dan sumber data mengikuti aturan deterministik Laravel. Mesin analisis AI menyusun interpretasi dan prioritas dari snapshot, termasuk tren jika pembanding tersedia. Laravel menampilkan hasil dan status melalui API yang membatasi akses ke pemilik UMKM.", ("app/Services/SixFactorEvaluationService.php", "finalize, lockOwnedUmkm dan finalHistory"), ("app/Services/IntelligenceReportService.php", "Pemilikan dan penyajian laporan"), ("Hermes Docker /opt/data/joyumkm-intelligence/jmi/", "engine.py, monthly.py, pdf.py, xlsx.py, delivery.py"))

s = new("Skenario 1 · Webhook Event-Driven", "Otomasi utama")
tx(s, .65, 1.56, 12, .46, "Finalisasi memicu pekerjaan saat data baru tersedia.", 21)
stages = ("Owner\nfinalisasi", "Validasi, skor\ndan simpan", "Outbox dan\nwebhook HMAC", "Ambil snapshot\ndan histori", "Analisis AI dan\nrekomendasi", "PDF, Excel\ndan manifest")
nodes = []
for i, stage in enumerate(stages):
    x = .65+i*2.04
    node(s, x+.64, 2.29, .52, .45, str(i+1), CORAL if i==0 else BLUE, NAVY if i==0 else WHITE, 15)
    nodes.append(node(s, x, 2.94, 1.82, 1.03, stage))
    if i:
        edge(s, x-.22, 3.46, x, 3.46, source=nodes[i-1], target=nodes[i])
edge(s, 11.76, 3.97, 11.76, 4.38, BLUE, False)
edge(s, 11.76, 4.38, 6.56, 4.38, BLUE, False)
edge(s, 6.56, 4.38, 6.56, 4.80)
edge(s, 11.76, 4.38, 11.76, 4.80)
node(s, 4.11, 4.80, 4.82, 1.01, "Email SMTP → Owner\nSetelah persetujuan dan cek kelayakan", PALE, NAVY, 16)
node(s, 9.15, 4.80, 3.53, 1.01, "Artefak + callback → Laravel\nLaporan, riwayat dan status", NAVY, WHITE, 15)
band(s, "Outbox menyimpan event evaluation.finalized bersama perubahan status Final.", size=16)
notes(s, "Service memvalidasi draft, menetapkan skor Final, dan menyimpan outbox dalam transaksi yang sama. Dispatch terjadi melalui jalur outbox ketika gate terbuka. Kedua cabang memakai paket lokal yang telah diverifikasi. Status SMTP disinkronkan ke Laravel melalui callback status tersendiri.", ("app/Services/SixFactorEvaluationService.php", "finalize dan transition"), ("app/Services/HermesWebhookOutbox.php", "recordFinalization, evaluation.finalized, dispatch dan retry"), ("Hermes Docker /opt/data/joyumkm-intelligence/jmi/", "pipeline.py, delivery.py, email_delivery.py dan email_status_sync.py"), (P6, "ACK, signed snapshot, artefak, SMTP dan status"))

s = new("Skenario 3 · Webhook Request-Response", "Fungsi pendukung · kesesuaian sebagian")
table(s, .65, 1.59, [3.58, 4.62], [.39]+[.385]*8, [
    ("Kebutuhan modul", "Implementasi DIP-JoyUMKM"),
    ("Menerima input penilaian", "Laravel API"),
    ("Validasi input", "Laravel"),
    ("Skor Enam Faktor", "Laravel · skor deterministik"),
    ("Skor Tiga Dimensi", "Evaluasi terpisah · bisa belum tersedia"),
    ("Penyimpanan hasil", "MySQL"),
    ("Analisis dan rekomendasi AI", "Hermes Agent"),
    ("Respons validasi", "HTTP Response Laravel"),
    ("Pemrosesan laporan", "Asinkron melalui Hermes Agent"),
], 15)
tx(s, 9.07, 1.57, 3.60, .25, "SINKRON", 11, BLUE, True)
for i, value in enumerate(("User → Laravel API", "Validation", "Score Calculation", "HTTP Response")):
    y = 1.94+i*.52
    node(s, 9.07, y, 3.60, .38, value, WHITE, NAVY, 15)
    if i:
        edge(s, 10.87, y-.14, 10.87, y, BLUE, True, 1.2)
rect(s, 9.07, 4.08, 3.60, .72, PALE)
tx(s, 9.20, 4.18, 3.34, .52, "ASINKRON · Final → Outbox\n→ Intelligence Reporting", 15, NAVY, True)
band(s, "DIP-JoyUMKM menggunakan Laravel API untuk validasi dan perhitungan skor. Endpoint webhook /predict pada Hermes Agent tidak digunakan seperti pada skenario modul, sehingga kesesuaiannya bersifat sebagian.", y=5.39, size=17, h=1.21)
notes(s, "Permintaan HTTP biasa belum memenuhi definisi webhook. Skor Enam Faktor dan hasil validasi dapat dikembalikan dalam respons API, sementara Intelligence Reporting dilanjutkan otomatis dari outbox. Tiga Dimensi punya alur evaluasi sendiri dan tercatat tidak tersedia pada event UAT yang ditinjau.", ("routes/api.php", "Rute assessment dan endpoint internal Intelligence"), ("app/Services/ScoringService.php dan app/Services/SixFactorEvaluationService.php", "Perhitungan deterministik alur penilaian"), (P6, "Tiga Dimensi unavailable pada event UAT"))

s = new("Skenario 5 · Manual Workflow Testing", "Metode pengujian")
for i, (title, body) in enumerate((
    ("Integration Testing", "Webhook sintetis, HMAC, snapshot dan callback."),
    ("Automation & Reliability", "Pipeline Hermes, idempotensi, retry dan recovery."),
    ("Document & Delivery", "PDF/XLSX, pencocokan hash, dan penerimaan SMTP uji."),
    ("UI Testing & UAT", "Playwright terautentikasi, tab Intelligence, riwayat dan unduhan."),
)):
    card(s, .65+(i%2)*6.14, 1.64+(i//2)*2.03, 5.89, 1.78, title, body, CORAL if i==3 else BLUE)
band(s, "Uji dipicu secara sengaja. Addendum P6-05 mencatat PASS untuk validasi output terbatas.", size=16)
notes(s, "Skenario 5 mencakup uji yang sengaja dimulai sebelum aktivasi, termasuk event sintetis, regresi otomatis, dan UAT terkontrol. Bukti Playwright terautentikasi di addendum P6-05 menyusul catatan PARTIAL lama dan membuktikan dua unduhan melalui UI cocok dengan manifest. Suite status mencatat 12 tes dan 116 asersi pada run terdokumentasi. Verifikasi inbox tidak dijalankan dan beberapa regresi suite lama tetap tercatat.", ("tests/Feature/HermesWebhookOutboxTest.php", "Outbox, failure dan retry"), ("tests/Unit/HermesWebhookSignatureTest.php", "Uji HMAC"), ("docs/audit/P3-07_JoyUMKM_Integrated_QA.md", "Bukti artefak dan recovery"), ("docs/audit/P5-07_JoyUMKM_Integrated_QA_Completion.md", "Regresi integrasi pengiriman"), (P6, "Addendum P6-05, SMTP dan Playwright"))

s = new("Automation Scenario Summary", "Pemetaan ke modul")
table(s, .65, 1.73, [1.35, 2.55, 4.84, 3.29], [.57,.83,.83,.83], [
    ("Skenario", "Trigger", "Implementasi", "Kesesuaian"),
    ("1", "Webhook", "Intelligence Reporting dan Email Delivery", "Penuh secara konsep"),
    ("3", "Webhook / API Request", "Validasi dan perhitungan skor", "Sebagian"),
    ("5", "Manual", "Workflow Testing dan UAT", "Penuh secara konsep"),
], 16)
rect(s, .65, 5.04, 12.03, 1.60, NAVY)
tx(s, .87, 5.25, 4.42, .42, "Rollout  OFF / PAUSED", 23, WHITE, True)
tx(s, .88, 5.84, 11.54, .38, "UAT terbatas · server SMTP menerima email uji · inbox belum diperiksa", 18, "DFE9FF")
notes(s, "Pemetaan ini membahas tiga dari lima skenario modul berdasarkan brief. Handoff final menutup P6-04, P6-05 dan P6-06 dengan PASS pada checkpoint tersebut. Status OFF/PAUSED tercatat di handoff dan cocok dengan flag dispatch Laravel serta gate email dan sinkronisasi di Docker lokal. [PERLU DIVERIFIKASI] Runtime hosting eksternal tidak diakses. Kotak masuk penerima tidak diperiksa karena akses tidak tersedia.", (HANDOFF, "Handoff final 25 November 2026"), (P6, "Addendum P6-05 dan cleanup P6-06"), (".env lokal dan Hermes state/email.env, state/delivery-status.env", "Flag dispatch, email dan status saja"))

journeys = (
    (
        ("01  Awareness", "Mendengar dari rekan atau media sosial", "Tersedia · landing page\nKonsep · rujukan dan media sosial", "Belum punya cara menilai usaha", "Jelaskan manfaat penilaian per faktor dan perspektif internal", "Penasaran"),
        ("02  Consideration", "Mempelajari manfaat aplikasi", "Tersedia · landing page\nKonsep · contoh hasil interaktif", "Khawatir proses rumit dan data usaha kurang aman", "Perlihatkan contoh hasil dan cara data dikelola", "Ragu, tertarik"),
        ("03  Registration", "Daftar akun dan isi profil UMKM", "Tersedia · registrasi dan wizard profil", "Formulir dapat terasa panjang", "Ringkas isian dan bagi proses menjadi langkah", "Sedikit khawatir"),
    ),
    (
        ("04  Onboarding", "Siapkan penilaian dan rencanakan undangan", "Tersedia · dashboard, penilaian\nKonsep · undangan, notifikasi", "Karyawan bisa lambat merespons atau bingung", "Panduan dan pengingat otomatis", "Berharap"),
        ("05  Usage", "Isi penilaian dan finalisasi", "Tersedia · formulir, progres\nKonsep · survei anonim", "Sulit memahami indikator dan memantau progres anonim", "Panduan indikator dan progres agregat", "Fokus, waspada"),
        ("06  Retention", "Baca hasil, pilih tindakan, pantau perubahan", "Tersedia · hasil, riwayat, Intelligence, PDF/XLSX\nSMTP · terbukti pada UAT", "Sulit menentukan langkah dari skor", "Prioritaskan rekomendasi dan pembanding periode", "Lega, termotivasi"),
    ),
)
for page, rows in enumerate(journeys):
    s = new("User Journey Map", "Awareness → Registration" if page==0 else "Onboarding → Retention")
    tx(s, .65, 1.48, 12.03, .30, "Owner UMKM · Tersedia = fitur aktual   |   Konsep = touchpoint hipotesis   |   Peluang = pengembangan", 13, MUTED)
    values = [("", *[stage[0] for stage in rows])]
    for i, label in enumerate(("Aksi", "Kanal", "Kendala", "Peluang"), 1):
        values.append((label, *[stage[i] for stage in rows]))
    table(s, .65, 1.97, [1.24,3.60,3.60,3.59], [.46,.68,.88,.75,.76], values, 15)
    tx(s, .65, 5.70, 1.28, .73, "Kurva emosi\nkonseptual", 12, MUTED, True)
    xs = (3.69, 7.29, 10.89)
    ys = (5.94, 6.12, 6.21) if page == 0 else (5.90, 6.08, 5.71)
    edge(s, 1.91, 6.41, 12.68, 6.41, LINE, False, 1)
    edge(s, 1.91, 6.23 if page == 0 else 6.21, xs[0], ys[0], SKY, False, 2.6)
    edge(s, xs[0], ys[0], xs[1], ys[1], SKY, False, 2.6)
    edge(s, xs[1], ys[1], xs[2], ys[2], SKY, False, 2.6)
    if page==0:
        edge(s, xs[2], ys[2], 12.60, 6.21, SKY)
    for x,y,row in zip(xs,ys,rows):
        rect(s, x-.055, y-.055, .11,.11, CORAL, kind=Shape.OVAL)
        tx(s, x-1.20, 6.52, 2.40,.25,row[5],14,NAVY,True,True)
    if page == 0:
        notes(s, "Kurva emosi menggambarkan hipotesis pengalaman owner. Rekomendasi rekan, media sosial, dan contoh hasil adalah konteks perjalanan. Registrasi dan pembuatan profil merupakan langkah terpisah. [PERLU DIVERIFIKASI] Tidak ditemukan studi pengguna yang mengukur kurva emosi.", ("routes/web.php", "Landing, registrasi dan workspace"), ("resources/js/pages/umkms.js", "Jalur pembuatan profil"), ("docs/prd/PRD_JoyUMKM_Tambah_UMKM_Wizard_OpenWA.md", "Pengisian profil bertahap"))
    else:
        notes(s, "Karyawan adalah persona pendukung konseptual. Undangan, survei anonim dan pengingat otomatis diposisikan sebagai peluang. Pada UAT, owner membuka tab Intelligence, melihat status SMTP dan mengunduh dokumen. Histori pembanding dibatasi data Final yang tersedia.", ("routes/api.php", "Draft, Final, riwayat, reports, download"), ("resources/js/pages/intelligence-report-data.js", "Presentasi data Intelligence"), (P6, "Playwright dan SMTP pada satu event"))

s = new("Conclusion", "Ringkasan", dark=True)
tx(s, .65, 1.63, 11.98, .73, "Penilaian terstruktur membantu owner memilih prioritas.", 26, WHITE, True)
for i,(label,body) in enumerate((
    ("Laravel", "Validasi, skor deterministik, dan histori."),
    ("Hermes Agent", "Analisis AI, rekomendasi, laporan, dan distribusi."),
    ("Skenario modul", "1 · otomasi utama     3 · fungsi pendukung     5 · pengujian"),
    ("Bukti kesiapan", "UAT terbatas tervalidasi · rollout tetap OFF / PAUSED."),
)):
    y = 2.62+i*.73
    edge(s, .65,y-.10,12.68,y-.10,"52658F",False,1)
    tx(s,.65,y,3.16,.48,label,19,"A7DAFF",True)
    tx(s,3.89,y,8.79,.48,body,20,WHITE)
band(s, "DIP-JoyUMKM mengubah skor penilaian usaha menjadi bahan untuk mengambil keputusan.", y=6.17, fill=CORAL, size=18, h=.58)
notes(s, "Laravel menjaga kebenaran skor dan histori, sedangkan Hermes mengolah konteks menjadi rekomendasi dan dokumen. Cakupan UAT yang tercatat mencakup validasi laporan, unduhan, penerimaan SMTP dan penghentian rollout. Bukti inbox dan aktivasi produksi tidak tersedia pada audit ini.", (HANDOFF, "Handoff akhir lokal terkini"), (P6, "P6-04, P6-05 dan P6-06"))

assert len(P.slides) == 14 and all(slide.has_notes_slide for slide in P.slides)
P.save(OUT / "DIP-JoyUMKM_Academic_Presentation.pptx")
