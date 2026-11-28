"""Evidence-bound claim gate for MODEL-AUTHORED prose (prompt r1-prompt-5 and later).

Design (fail closed, not a phrase deny-list):
  * Every clause that mentions a financial subject (omzet, modal, biaya, laba, kas, ...) must belong to a
    NON-ASSERTIVE form (instruction, caution about assuming, hedge/data-gap statement, measurement noun phrase,
    conditional) or be a recognised claim that is VERIFIED against the deterministic payload evidence.
  * Anything else is rejected as unverifiable. Facts therefore only reach the final report through
    (a) system-rendered `fact` findings built from evidence paths, or (b) a recognised claim that matches evidence.
  * Contradictions (positive / zero / null / different period) are reported with a specific code.

What this proves: a financial/monetary clause is only accepted when it has a recognised shape.
What it cannot prove: that an instruction or hedge is wise or that free prose never smuggles a fact through a
shape the grammar accepts (e.g. an imperative that embeds a false premise in an unusual wording). Pure lexical
grammar over Indonesian; see CHANGELOG-V3.md for residual limits.
"""
from __future__ import annotations

import re

_FIN = re.compile(r"\b(?:omzet|pendapatan|penjualan|penghasilan|pemasukan|modal|biaya|pengeluaran|ongkos|laba|untung|rugi|"
                  r"keuntungan|margin|profit|kas|uang|utang|hutang|pinjaman|gaji|hpp|roi)\b")
_REVENUE = r"(?:omzet|pendapatan|penjualan|penghasilan|pemasukan)"
_COST = r"(?:biaya|pengeluaran|ongkos)"
_CAPITAL = r"modal"
_PERIOD = {"bulanan": "monthly", "tahunan": "annual"}

DIRECTIVES = set("""
catat mencatat pantau memantau tinjau meninjau bandingkan membandingkan kumpulkan mengumpulkan siapkan menyiapkan
pisahkan memisahkan periksa memeriksa rekap merekap buat membuat susun menyusun evaluasi mengevaluasi mulai memulai
hitung menghitung tentukan menentukan gunakan menggunakan simpan menyimpan tulis menulis cek mengecek pastikan memastikan
identifikasi mengidentifikasi dokumentasikan mendokumentasikan tetapkan menetapkan kelola mengelola kaji mengkaji
lanjutkan melanjutkan sesuaikan menyesuaikan putuskan memutuskan perbarui memperbarui amati mengamati diskusikan
mendiskusikan tanyakan menanyakan pertimbangkan mempertimbangkan konsolidasikan mengonsolidasikan ukur mengukur
rapikan merapikan laporkan melaporkan ringkas meringkas uji menguji coba mencoba lakukan melakukan tingkatkan
meningkatkan tambah menambah kurangi mengurangi tekan menekan jaga menjaga pertahankan mempertahankan kembangkan
mengembangkan optimalkan mengoptimalkan batasi membatasi bakukan membakukan petakan memetakan pilih memilih
lengkapi melengkapi sepakati menyepakati jadwalkan menjadwalkan rancang merancang tandai menandai bahas membahas
analisis menganalisis telusuri menelusuri verifikasi memverifikasi konfirmasi mengonfirmasi minta meminta
libatkan melibatkan sederhanakan menyederhanakan perbaiki memperbaiki hentikan menghentikan terapkan menerapkan
review beri berikan memberi atur mengatur alokasikan mengalokasikan gabungkan menggabungkan pisah catatkan isi mengisi
masukkan memasukkan jalankan menjalankan tawarkan menawarkan perhatikan memperhatikan mendata kirim mengirim hubungi menghubungi
sampaikan menyampaikan pelajari mempelajari gali menggali lacak melacak ikuti mengikuti pakai memakai jual menjual
""".split())
SOFT_PREFIX = re.compile(r"^(?:(?:pemilik|pengelola)(?:\s+usaha)?\s+)?(?:sebaiknya|disarankan|dianjurkan|perlu|dapat|bisa|boleh|lebih\s+baik|mulai|harus|wajib)\s+(?:segera\s+)?")
_SUBJECT = re.compile(r"^(?:pemilik|pengelola|pelaku\s+usaha)(?:\s+usaha)?\s+")
# Structural (not lexical) imperative / scope rules, added in candidate-v4 (see DIAGNOSIS-EVAL-V3.md).
_LEAD = {"dan", "serta", "atau", "lalu", "kemudian", "lantas", "selanjutnya", "juga", "itu"}  # discourse lead-ins, no content
_NOT_IMPERATIVE = re.compile(r"^(?:me|di|ter|ber|pe)")
_SCOPE_BREAK = {"dan", "serta", "atau", "maupun", "menjadi", "ke", "sebelum", "sesudah", "setelah", "menurut", "berdasarkan", "dengan"}
_COPULA = re.compile(r"^(?:langkah|tindakan|saran|rekomendasi|prioritas)(?:\s+\w+){0,2}?\s+(?:adalah|ialah)\s+(.+)$")
_ELLIPSIS = re.compile(r"^(?:begitu|demikian)\s+pula\s+(.+)$")
_REQUIREMENT = re.compile(r"^yang\s+(?:butuh|perlu|memerlukan|membutuhkan|dibutuhkan|diperlukan)\s+")
_DATA_GAP_TAIL = re.compile(r"\b(?:belum|tidak)\s+(?:tersedia|ada|diketahui|lengkap)\b")
# Narrow hedges (r1-prompt-6 false positives): a recommendation to review a financial topic, or a classification of a record,
# is accepted only when the clause carries no figure and no state word in the financial scope.
_REVIEW_HEDGE = re.compile(r"\b(?:layak|perlu|sebaiknya|patut)\s+(?:di)?(?:tinjau|periksa|cek|pantau|verifikasi|kaji|evaluasi|validasi|tanyakan)\b")
_NUMERIC = re.compile(r"\d|\b(?:rp|persen|juta|ribu|miliar|jt)\b")


def _dev_hedges() -> bool:
    """The narrow hedges below are enabled only by the DEV route scripts; production keeps the original gate."""
    import os
    return os.environ.get("JMI_VALIDATOR_DEV_HEDGES") == "1"
ASSUME_VERBS = set("anggap menganggap simpulkan menyimpulkan asumsikan mengasumsikan kira mengira berasumsi samakan menyamakan "
                   "artikan mengartikan tafsirkan menafsirkan".split())
CAUTION_STARTS = {"jangan", "janganlah", "hindari", "menghindari"}
MEASURE_HEADS = set("""pencatatan catatan rekap rekapitulasi kelengkapan frekuensi proporsi jumlah ketersediaan konsistensi
ketepatan kebiasaan laporan data daftar rincian ringkasan perbandingan perbedaan selisih penggunaan pemisahan pembukuan
kemajuan kemampuan keputusan penyesuaian peninjauan tinjauan hasil kejelasan kerapian kerutinan""".split())
STATE_VALUES = set("nol nihil kosong gratis positif negatif tinggi rendah besar kecil stabil sehat buruk baik minus surplus defisit lancar macet".split())
STATIVE = set("""nol nihil kosong gratis naik turun meningkat menurun positif negatif tinggi rendah besar kecil stabil sehat buruk baik
cukup kurang lebih melebihi menghasilkan memperoleh mendapat mendapatkan memiliki mencapai menyentuh sekitar rata
bertambah berkurang minus surplus defisit lancar macet bocor boros hemat""".split())
_HEDGE = [
    re.compile(r"^(?:belum|tidak)\s+(?:dapat|bisa)\s+(?:di)?(?:simpulkan|pastikan|ketahui|tentukan|nilai|hitung|pastikan|katakan)\b"),
    re.compile(r"^(?:belum|tidak)\s+diketahui\b"),
    re.compile(r"^apakah\b"),
    re.compile(r"^(?:belum|tidak)\s+ada\s+(?:data|informasi|catatan|rincian|bukti|dasar)\b"),
    re.compile(r"^(?:data|informasi|catatan|rincian|detail|bukti)\s+.*\b(?:belum|tidak)\s+(?:tersedia|ada|lengkap|diketahui|cukup)\b"),
    re.compile(r"^(?:proyeksi|estimasi|perkiraan)\b.*\bbukan\b.*\b(?:aktual|realisasi|angka)\b"),
    re.compile(r"^(?:perlu|sebaiknya)\s+(?:di)?(?:cek|periksa|pastikan|verifikasi|konfirmasi|tinjau|tanyakan|validasi|kaji)\b"),
]
_AVAIL_POS = re.compile(rf"^(?:{_REVENUE}|{_CAPITAL})(?:\s+usaha)?(?:\s+(?:bulanan|tahunan))?\s+(?:sudah\s+|telah\s+)?(?:dilaporkan|tercatat|tersedia|diketahui)$")
_AVAIL_NEG = re.compile(rf"^(?:{_REVENUE}|{_CAPITAL})(?:\s+usaha)?(?:\s+(?:bulanan|tahunan))?\s+(?:belum|tidak)\s+(?:dilaporkan|tercatat|tersedia|diketahui)$")
_NOCROSS = r"(?!(?:dan|serta|atau|maupun|pada|dari|untuk|dengan|di|ke|sebelum|setelah)\b)\w+"
_ZERO = re.compile(rf"\b({_REVENUE}|{_CAPITAL}|{_COST})\b(?:\s+{_NOCROSS}){{0,3}}?\s+(?:nol|nihil|kosong|gratis)\b")
_ABSENT = re.compile(rf"\b(?:tidak|belum)\s+(?:ada|memperoleh|menghasilkan|mendapat|mendapatkan|memiliki|punya|mencatat)\s+(?:\w+\s+){{0,2}}?\b({_REVENUE}|{_CAPITAL}|{_COST})\b")
_ABSENT2 = re.compile(rf"\b(?:tidak|belum)\s+(?:ada|memperoleh|menghasilkan|mendapat|mendapatkan|memiliki|punya)\b.*\b({_REVENUE}|{_CAPITAL}|{_COST})\b")
_ABSENT3 = re.compile(rf"\b({_REVENUE}|{_CAPITAL}|{_COST})\b(?:\s+\w+){{0,2}}?\s+(?:tidak|belum)\s+(?:ada|masuk|muncul|ditemukan)\b")
_EXISTENCE = re.compile(rf"\b({_REVENUE}|{_CAPITAL})\b(?:\s+\w+){{0,2}}?\s+(?:ada|dilaporkan|tercatat)\b")
_SPLIT = re.compile(r"(\b(?:bahwa|karena|sebab|sehingga|padahal|tetapi|namun|sedangkan|sementara|meskipun|walaupun|jika|bila|apabila|kalau|"
                    r"seandainya|agar|supaya|untuk|yaitu|yakni|artinya)\b|[,;:])")
_PURPOSE = {"agar", "supaya", "untuk", "yaitu", "yakni"}
_COND = {"jika", "bila", "apabila", "kalau", "seandainya"}

# domain-specific practice/diagnosis terms: allowed only when marked as an example / conditional
_PRACTICE = re.compile(r"\b(?:higien\w*|sanitasi|kebersihan|keamanan\s+pangan|kedaluwarsa|haccp|bahan\s+baku\s+basi|daftar\s+periksa\s+kebersihan)\b")
_EXAMPLE_MARK = re.compile(r"\b(?:contoh|misalnya|seperti|salah\s+satu\s+(?:praktik|cara|opsi|contoh)|opsional|sebagai\s+praktik|jika|bila|apabila|kalau|apakah|umumnya\s+di\s+sektor)\b")
_PROBLEM = re.compile(r"\b(?:masalah|rentan|berisiko|risiko|bermasalah|lemah|buruk|rawan|bahaya|pelanggaran|kurang\s+\w+|belum\s+memenuhi|tidak\s+memenuhi|tidak\s+\w*higien\w*|kotor)\b")
_SECTOR_WORDS = re.compile(r"\b(?:sektor|kuliner|makanan|minuman|jasa|dagang|retail|fesyen|fashion|pertanian|kerajinan|bidang\s+\w+)\b")
_ASSUMED_OUTCOME = re.compile(r"\bakan\s+(?:pasti\s+)?(?:me)?(?:ningkat|naik|berhasil|bertambah|menambah|menurun|turun|menaikkan|meningkatkan|menurunkan|mengurangi|menghasilkan|membuat\s+\w+\s+(?:naik|meningkat))\b")


def _norm(s: str) -> str:
    return re.sub(r"\s+", " ", re.sub(r"[^\w\s]", " ", s.lower())).strip()


def _clauses(sentence: str):
    """Yield (marker, raw_text) pairs; marker = the splitter that introduced the clause ('' for the first)."""
    marker, cur, out = "", [], []
    for piece in _SPLIT.split(sentence.lower()):
        if piece is None:
            continue
        if _SPLIT.fullmatch(piece):
            out.append((marker, " ".join(cur)))
            marker, cur = piece, []
        else:
            cur.append(piece)
    out.append((marker, " ".join(cur)))
    return [(m, _norm(t)) for m, t in out if _norm(t)]


def _lead(t: str) -> str:
    toks = t.split()
    while toks and toks[0] in _LEAD:
        toks = toks[1:]
    return " ".join(toks)


def _is_imperative(tok: str) -> bool:
    """Bare imperative verb: listed directive, or regular transitive V-kan form (no active/passive/noun prefix)."""
    if tok in DIRECTIVES:
        return True
    return len(tok) >= 6 and tok.endswith("kan") and not _NOT_IMPERATIVE.match(tok)


def _state_in_fin_scope(toks, words) -> bool:
    """True when a state/zero/sign word sits in the same scope segment as a financial subject (e.g. 'omzet nol').
    Scope breaks at coordinators/prepositions, so 'pengeluaran dan hari kosong' is not 'pengeluaran kosong'."""
    seg: list[str] = []
    for tk in toks + [None]:
        if tk is None or tk in _SCOPE_BREAK:
            if any(_FIN.fullmatch(x) for x in seg) and (set(seg) & words):
                return True
            seg = []
        else:
            seg.append(tk)
    return False


def _strip(t: str) -> str:
    t = _lead(t)
    t = _SUBJECT.sub("", t)
    prev = None
    while prev != t:
        prev = t
        t = SOFT_PREFIX.sub("", t)
        t = _SUBJECT.sub("", t)
        t = _lead(t)
    return t


def _kind(t: str) -> str:
    """Classify a clause that mentions a financial subject: directive|caution|hedge|measure|plain."""
    lead_toks = _SUBJECT.sub("", _lead(t)).split()
    if lead_toks and lead_toks[0] in ("mulai", "memulai") and not _state_in_fin_scope(lead_toks, STATE_VALUES):
        return "directive"  # "Mulai buku catatan ... untuk pemasukan" is a recommendation, not a state of the business
    s = _strip(t)
    toks = s.split()
    if not toks:
        return "plain"
    if toks[0] in CAUTION_STARTS:
        return "caution" if len(toks) > 1 and toks[1] in ASSUME_VERBS else "plain"
    if re.search(r"\b(?:tidak|bukan)\s+(?:menjanjikan|menjamin|merupakan\s+(?:jaminan|janji))\b", t):
        return "hedge"
    if any(rx.match(t) or rx.match(s) for rx in _HEDGE):
        return "hedge"
    if _dev_hedges() and _REVIEW_HEDGE.search(t) and not _NUMERIC.search(t) and not _state_in_fin_scope(t.split(), STATIVE | STATE_VALUES):
        return "hedge"  # "... layak ditinjau dengan catatan nyata": recommendation to review, no figure, no state claim
    cop = _COPULA.match(s)  # "langkah pertama adalah mulai mencatat ..." = instruction in identification form
    if cop:
        s = cop.group(1)
        s = SOFT_PREFIX.sub("", s)
        toks = s.split()
    if toks[0] in ("tanpa",) and len(toks) > 1 and toks[1] in MEASURE_HEADS and not _state_in_fin_scope(toks, STATIVE | STATE_VALUES):
        return "measure"  # "tanpa catatan biaya, ..." = conditional adjunct on records (not 'tanpa biaya')
    if _is_imperative(toks[0]) and not _state_in_fin_scope(toks, STATE_VALUES):
        return "directive"
    if toks[0] in MEASURE_HEADS and not _state_in_fin_scope(toks, STATIVE | STATE_VALUES):
        return "measure"
    if _dev_hedges() and toks[0] == "kategori" and not _NUMERIC.search(t) and not _state_in_fin_scope(toks, STATIVE | STATE_VALUES):
        return "measure"  # classification of a financial record ("kategori pengeluaran yang sudah terdefinisi"), no figure or state
    return "plain"


def _verify(clause: str, evidence: dict, refs):
    """Return None when a recognised claim is verified against evidence, else (code, reason). `refs` is the
    evidence_refs list of the owning finding/priority or None when the field has no refs (summary fields)."""
    rev = (evidence.get("business_context") or {}).get("revenue") or {}
    bc = evidence.get("business_context") or {}
    period_claim = next((v for k, v in _PERIOD.items() if k in clause.split()), None)

    def need(path):
        if refs is not None and path not in refs:
            return ("financial_claim_without_reference", f"claim needs evidence_ref {path}")
        return None

    m = _ZERO.search(clause) or _ABSENT.search(clause) or _ABSENT2.search(clause) or _ABSENT3.search(clause)
    if m:
        subject = m.group(1)
        if re.fullmatch(_COST, subject):
            return ("financial_claim_unverifiable", "zero/absent cost claim: payload has no cost data")
        if re.fullmatch(_CAPITAL, subject):
            val, path = bc.get("business_capital"), "business_context.business_capital"
        else:
            val, path = rev.get("amount"), "business_context.revenue.amount"
        if val is None:
            return ("financial_claim_contradicts_evidence", "payload value is null (not zero)")
        if val != 0:
            return ("financial_claim_contradicts_evidence", "payload value is positive")
        if path.endswith("amount") and period_claim and rev.get("period") != period_claim:
            return ("financial_claim_contradicts_evidence", "period differs from payload")
        return need(path)
    m = _AVAIL_POS.match(clause) or _EXISTENCE.search(clause)
    neg = _AVAIL_NEG.match(clause)
    if neg:
        subject_cap = clause.startswith("modal")
        val = bc.get("business_capital") if subject_cap else rev.get("amount")
        if val is not None:
            return ("financial_claim_contradicts_evidence", "payload value is available")
        return None
    if m:
        subject_cap = clause.startswith("modal") or (m.lastindex and m.group(1) == "modal")
        val = bc.get("business_capital") if subject_cap else rev.get("amount")
        if val is None:
            return ("financial_claim_contradicts_evidence", "payload value is null")
        path = "business_context.business_capital" if subject_cap else "business_context.revenue.amount"
        if period_claim and not subject_cap and rev.get("period") != period_claim:
            return ("financial_claim_contradicts_evidence", "period differs from payload")
        return need(path)
    return ("financial_claim_unverifiable", "financial statement without a verifiable shape")


def check_text(text: str, evidence: dict, refs=None, sector: str | None = None, safe=frozenset()) -> list[tuple[str, str]]:
    """Return [(code, reason)] for one model-authored text. Empty list = acceptable."""
    problems: list[tuple[str, str]] = []
    for sentence in re.split(r"(?<=[.!?\n])\s+", text):
        if not sentence.strip() or _norm(sentence) in safe:
            continue
        prev_kind, prev_ok, prev_fin = "plain", True, False
        for marker, clause in _clauses(sentence):
            kind = _kind(clause)
            toks = [w for w in clause.split() if w not in ("dan", "serta", "atau")]
            if kind == "plain" and marker in (",", "untuk", "agar", "supaya") and prev_kind != "plain" and 0 < len(toks) <= 4 \
                    and not (set(toks) & STATIVE):
                kind = prev_kind  # list fragment / purpose tail of an accepted instruction
            if _dev_hedges() and kind == "plain" and marker in _PURPOSE and toks and all(_FIN.fullmatch(w) for w in toks) \
                    and not _NUMERIC.search(clause):
                kind = "measure"  # "satu lembar harian untuk pemasukan dan pengeluaran": bare financial topic, no claim
            has_fin = bool(_FIN.search(clause))
            ok = True
            lead = _lead(clause)
            if has_fin and kind == "plain":
                core = _ELLIPSIS.match(lead)
                if core and prev_kind == "hedge" and core.group(1).split()[0] in ("data", "informasi", "catatan", "rincian", "detail") \
                        and not _state_in_fin_scope(core.group(1).split(), STATIVE | STATE_VALUES):
                    kind = "hedge"  # "..., begitu pula data laba": ellipsis of an accepted data-gap statement
                elif marker == "," and _REQUIREMENT.match(clause) and prev_kind in ("directive", "measure", "hedge", "caution") \
                        and not _state_in_fin_scope(clause.split(), STATIVE | STATE_VALUES):
                    kind = prev_kind  # relative clause stating what a method needs, not a state of the business
                elif marker == "," and clause.split()[0] in ("dan", "serta", "atau") and prev_kind in ("measure", "hedge") \
                        and _DATA_GAP_TAIL.search(clause) and not re.search(rf"\b(?:{_REVENUE}|{_CAPITAL})\b", clause):
                    kind = "hedge"  # list tail of a data-gap statement ("Data biaya, laba, serta utang belum tersedia")
                elif marker == "," and clause.split()[0] in ("dan", "serta", "atau") and not prev_fin and len(toks) <= 4 \
                        and not (set(toks) & (STATIVE | STATE_VALUES)):
                    kind = "measure"  # bare noun list item after a financial-free clause ("indikator mencakup NIB, dan sumber modal")
            if has_fin:
                if kind in ("directive", "caution", "hedge", "measure"):
                    ok = True
                elif marker in _COND:
                    ok = True
                elif marker in _PURPOSE and prev_kind in ("directive", "caution", "hedge", "measure"):
                    ok = True
                elif marker == "bahwa" and prev_kind in ("caution", "hedge"):
                    ok = True
                else:
                    err = _verify(clause, evidence, refs)
                    if err:
                        problems.append(err + (clause[:80],))
                        ok = False
            elif marker in _COND or marker in _PURPOSE:
                kind = "hedge" if marker in _COND else prev_kind
            if _ASSUMED_OUTCOME.search(clause):
                problems.append(("assumed_outcome", "outcome stated as certain; use 'dapat'/'berpotensi' and a way to check", clause[:80]))
            if _PRACTICE.search(clause) and not _EXAMPLE_MARK.search(clause) and not _EXAMPLE_MARK.search(_norm(sentence)):
                problems.append(("sector_practice_not_example", "sector-specific practice must be framed as an example/option, not as the need", clause[:80]))
            sec = sector and sector.lower() in clause
            if (sec or _SECTOR_WORDS.search(clause)) and _PROBLEM.search(clause) and not _EXAMPLE_MARK.search(clause):
                problems.append(("sector_based_diagnosis", "problem attributed from sector without payload evidence", clause[:80]))
            prev_kind, prev_ok, prev_fin = kind, ok, has_fin
    return problems


def check_plan_progression(plan: dict) -> list[str]:
    """Distinctness and phase progression for action_plan (deterministic, lexical markers)."""
    out: list[str] = []
    start = {"mulai", "memulai", "catat", "mencatat", "siapkan", "menyiapkan", "kumpulkan", "mengumpulkan", "buat", "membuat", "susun", "menyusun",
             "tetapkan", "menetapkan", "pilih", "memilih", "pisahkan", "memisahkan", "uji", "coba", "mencoba", "identifikasi", "mengidentifikasi",
             "rancang", "merancang", "tentukan", "menentukan", "petakan", "memetakan", "lakukan", "melakukan", "tanyakan", "menanyakan", "rekap",
             "pasang", "memasang", "kenali", "mengenali", "inventarisasi", "mendata", "data", "tulis", "menulis"}
    review = {"tinjau", "meninjau", "tinjauan", "peninjauan", "review", "evaluasi", "mengevaluasi", "bandingkan", "membandingkan", "periksa",
              "memeriksa", "cek", "mengecek", "sesuaikan", "menyesuaikan", "penyesuaian", "perbaiki", "memperbaiki", "kaji", "mengkaji", "ukur",
              "mengukur", "amati", "mengamati", "telaah", "menelaah", "refleksi", "revisi", "merevisi", "sederhanakan", "menyederhanakan"}
    consolidate = {"konsolidasi", "konsolidasikan", "mengonsolidasikan", "putuskan", "memutuskan", "keputusan", "tetapkan", "menetapkan", "lanjutkan",
                   "melanjutkan", "bakukan", "membakukan", "standarisasi", "jadikan", "menjadikan", "rutinkan", "merutinkan", "hentikan", "menghentikan",
                   "pertahankan", "mempertahankan", "rencanakan", "merencanakan", "langkah", "berikutnya", "dokumentasikan", "mendokumentasikan",
                   "ringkas", "meringkas", "simpulkan", "menyimpulkan", "gabungkan", "menggabungkan", "tentukan", "menentukan", "ambil", "mengambil",
                   "kembangkan", "mengembangkan", "perluas", "memperluas", "serah", "alihkan"}
    marks = {"days_1_30": ("start", start), "days_31_60": ("review/adjust", review), "days_61_90": ("consolidate/decide", consolidate)}
    seen_actions, seen_metrics = {}, {}
    for ph, (label, words) in marks.items():
        items = plan.get(ph, [])
        if items and not any(set(_norm(i["action"]).split()) & words for i in items):
            out.append(f"action_plan.{ph}: no action shows the expected phase step ({label})")
        for i, it in enumerate(items):
            for kind, store_, text in (("action", seen_actions, it["action"]), ("metric", seen_metrics, it["metric"])):
                key = _norm(text)
                if key in store_:
                    out.append(f"action_plan.{ph}[{i}].{kind}: identical to {store_[key]}")
                else:
                    store_[key] = f"action_plan.{ph}[{i}].{kind}"
                for other, where in store_.items():
                    if other != key and _jaccard(other, key) >= 0.8:
                        out.append(f"action_plan.{ph}[{i}].{kind}: near-duplicate of {where}")
                        break
    return out


def _jaccard(a: str, b: str) -> float:
    x, y = set(a.split()), set(b.split())
    return len(x & y) / len(x | y) if x and y else 0.0


def check_priority_texts(priorities: list[dict]) -> list[str]:
    """Same action text must not appear under different priorities (or twice in one)."""
    out, seen = [], {}
    for i, p in enumerate(priorities):
        for j, a in enumerate(p["actions"]):
            key = _norm(a)
            if key in seen:
                out.append(f"top_priorities[{i}].actions[{j}]: identical to {seen[key]}")
            else:
                seen[key] = f"top_priorities[{i}].actions[{j}]"
                for other, where in list(seen.items()):
                    if other != key and _jaccard(other, key) >= 0.8:
                        out.append(f"top_priorities[{i}].actions[{j}]: near-duplicate of {where}")
                        break
    return out
