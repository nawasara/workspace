"""
Generate the 14 built-in Kisara templates (T-21, FSD FR-15) into
resources/templates/.

Reproducible: run it twice and every file is byte-identical, because the
document dates and every zip entry timestamp are pinned (FIXED_DATE). That
matters: BuiltinTemplateSeeder compares hashes, so a generator that stamped
"now" would make every seeder run re-upload all 14 files.

Requirements: Python 3.11, python-docx, openpyxl, python-pptx.

    python tools/templates/generate.py            # write the files
    python tools/templates/generate.py --check    # also round-trip + zip check

Content rules (FR-15 AC-2): generic skeletons only, square-bracket
placeholders ([NAMA OPD], [Nama Lengkap], ...), never real names, NIPs or
numbers. Every file is marked "Kisara (bawaan)" in its document properties
and in a small footer.
"""

from __future__ import annotations

import datetime as dt
import hashlib
import io
import re
import sys
import zipfile
from pathlib import Path

from docx import Document
from docx.enum.section import WD_ORIENT
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor
from openpyxl import Workbook
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter
from openpyxl.worksheet.datavalidation import DataValidation
from pptx import Presentation
from pptx.dml.color import RGBColor as PRGB
from pptx.enum.shapes import MSO_SHAPE
from pptx.enum.text import PP_ALIGN
from pptx.util import Emu, Inches
from pptx.util import Pt as PPt

OUT = Path(__file__).resolve().parents[2] / "resources" / "templates"
FIXED_DATE = dt.datetime(2026, 11, 17, 0, 0, 0)
MARK = "Kisara (bawaan)"
FOOTER = "Templat Kisara (bawaan)"
KEYWORDS = "Kisara (bawaan); templat"
COMMENT = "Templat bawaan Kisara Workspace. Kerangka umum tanpa data; ganti teks dalam [kurung siku]."

PEMKAB = "PEMERINTAH KABUPATEN PONOROGO"
OPD = "[NAMA OPD]"
ALAMAT = "[Alamat OPD], Telepon [nomor telepon], Laman [alamat laman], Pos-el [alamat pos-el]"


# ════════════════════════════════════════════════════════════════════
# Docs (.docx)
# ════════════════════════════════════════════════════════════════════

def doc_new(title: str) -> Document:
    d = Document()
    sec = d.sections[0]
    sec.page_width, sec.page_height = Cm(21), Cm(29.7)  # A4
    sec.orientation = WD_ORIENT.PORTRAIT
    sec.top_margin, sec.bottom_margin = Cm(2), Cm(2)
    sec.left_margin, sec.right_margin = Cm(2.5), Cm(2)

    normal = d.styles["Normal"]
    normal.font.name = "Arial"
    normal.font.size = Pt(12)
    normal.element.rPr.rFonts.set(qn("w:eastAsia"), "Arial")
    normal.paragraph_format.space_after = Pt(0)
    normal.paragraph_format.line_spacing = 1.15

    fp = sec.footer.paragraphs[0]
    fp.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    r = fp.add_run(FOOTER)
    r.font.size = Pt(8)
    r.font.color.rgb = RGBColor(0x80, 0x80, 0x80)

    cp = d.core_properties
    cp.title = title
    cp.subject = f"Templat {title}"
    cp.author = MARK
    cp.last_modified_by = MARK
    cp.keywords = KEYWORDS
    cp.comments = COMMENT
    cp.category = "Templat Kisara"
    cp.created = FIXED_DATE
    cp.modified = FIXED_DATE
    cp.revision = 1
    return d


def para(d, text="", bold=False, size=None, align=None, italic=False, space_after=0, indent_cm=None):
    p = d.add_paragraph()
    if text:
        r = p.add_run(text)
        r.bold = bold
        r.italic = italic
        if size:
            r.font.size = Pt(size)
    if align:
        p.alignment = align
    p.paragraph_format.space_after = Pt(space_after)
    if indent_cm is not None:
        p.paragraph_format.left_indent = Cm(indent_cm)
    return p


def bottom_rule(p, size=12):
    """A double rule under the letterhead, as on printed kop surat."""
    pPr = p._p.get_or_add_pPr()
    bdr = OxmlElement("w:pBdr")
    b = OxmlElement("w:bottom")
    b.set(qn("w:val"), "thickThinSmallGap")
    b.set(qn("w:sz"), str(size))
    b.set(qn("w:space"), "1")
    b.set(qn("w:color"), "000000")
    bdr.append(b)
    pPr.append(bdr)


def kop(d):
    para(d, PEMKAB, bold=True, size=14, align=WD_ALIGN_PARAGRAPH.CENTER)
    para(d, OPD, bold=True, size=16, align=WD_ALIGN_PARAGRAPH.CENTER)
    p = para(d, ALAMAT, size=9, align=WD_ALIGN_PARAGRAPH.CENTER)
    p2 = para(d, "PONOROGO [kode pos]", bold=True, size=10, align=WD_ALIGN_PARAGRAPH.CENTER, space_after=12)
    bottom_rule(p2)
    return p


def plain_table(d, rows, widths_cm, bold_first=False):
    """Borderless label table (Kepada / Dari / Hal ...)."""
    t = d.add_table(rows=0, cols=len(widths_cm))
    t.alignment = WD_TABLE_ALIGNMENT.LEFT
    for row in rows:
        cells = t.add_row().cells
        for i, txt in enumerate(row):
            cells[i].width = Cm(widths_cm[i])
            cells[i].paragraphs[0].add_run(txt).bold = bold_first and i == 0
    return t


def grid_table(d, headers, n_rows, widths_cm, first_col_numbers=True):
    t = d.add_table(rows=1, cols=len(headers))
    t.style = "Table Grid"
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    for i, h in enumerate(headers):
        c = t.rows[0].cells[i]
        c.width = Cm(widths_cm[i])
        r = c.paragraphs[0].add_run(h)
        r.bold = True
        r.font.size = Pt(11)
        c.paragraphs[0].alignment = WD_ALIGN_PARAGRAPH.CENTER
        shade(c, "D9E2F3")
    for n in range(n_rows):
        cells = t.add_row().cells
        for i in range(len(headers)):
            cells[i].width = Cm(widths_cm[i])
        if first_col_numbers:
            cells[0].paragraphs[0].add_run(f"{n + 1}.")
            cells[0].paragraphs[0].alignment = WD_ALIGN_PARAGRAPH.CENTER
    return t


def shade(cell, hex_fill):
    tcPr = cell._tc.get_or_add_tcPr()
    s = OxmlElement("w:shd")
    s.set(qn("w:val"), "clear")
    s.set(qn("w:color"), "auto")
    s.set(qn("w:fill"), hex_fill)
    tcPr.append(s)


def signature(d, jabatan="[Jabatan Penanda Tangan]", left=None):
    """Signature block on the right, optionally a second one on the left."""
    para(d)
    t = d.add_table(rows=1, cols=2)
    l, r = t.rows[0].cells
    l.width, r.width = Cm(8), Cm(8.5)
    for cell, who in ((l, left), (r, jabatan)):
        if who is None:
            continue
        p = cell.paragraphs[0]
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        p.add_run(who)
        for _ in range(3):
            cell.add_paragraph()
        q = cell.add_paragraph()
        q.alignment = WD_ALIGN_PARAGRAPH.CENTER
        rr = q.add_run("[Nama Lengkap]")
        rr.bold = True
        rr.underline = True
        q2 = cell.add_paragraph("[Pangkat / Golongan]")
        q2.alignment = WD_ALIGN_PARAGRAPH.CENTER
        q3 = cell.add_paragraph("NIP [NIP]")
        q3.alignment = WD_ALIGN_PARAGRAPH.CENTER


def heading(d, text, size=12, space_before=10):
    p = para(d, text, bold=True, size=size, space_after=4)
    p.paragraph_format.space_before = Pt(space_before)
    return p


def placeholder(d, text, indent_cm=0.75):
    p = para(d, text, space_after=6, indent_cm=indent_cm)
    p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
    return p


def doc_nota_dinas():
    d = doc_new("Nota Dinas")
    kop(d)
    para(d, "NOTA DINAS", bold=True, size=13, align=WD_ALIGN_PARAGRAPH.CENTER)
    para(d, "NOMOR: [kode klasifikasi]/[nomor urut]/[kode unit]/[tahun]", align=WD_ALIGN_PARAGRAPH.CENTER, space_after=12)
    plain_table(d, [
        ("Yth.", ":", "[Jabatan Penerima]"),
        ("Dari", ":", "[Jabatan Pengirim]"),
        ("Tembusan", ":", "[Jabatan Tembusan, bila ada]"),
        ("Tanggal", ":", "[tanggal bulan tahun]"),
        ("Sifat", ":", "[Biasa / Segera / Sangat Segera]"),
        ("Lampiran", ":", "[jumlah] berkas"),
        ("Hal", ":", "[Pokok persoalan]"),
    ], [3, 0.5, 13])
    bottom_rule(para(d, space_after=10), size=6)
    placeholder(d, "[Paragraf pembuka: dasar atau latar belakang nota dinas ini.]", 0)
    placeholder(d, "[Paragraf isi: hal yang diberitahukan, diminta, atau dilaporkan. Uraikan secara singkat dan jelas.]", 0)
    placeholder(d, "Demikian, atas perhatian [Bapak/Ibu] disampaikan terima kasih.", 0)
    signature(d)
    return d


def doc_surat_undangan():
    d = doc_new("Surat Undangan")
    kop(d)
    para(d, "Ponorogo, [tanggal bulan tahun]", align=WD_ALIGN_PARAGRAPH.RIGHT, space_after=8)
    plain_table(d, [
        ("Nomor", ":", "[kode klasifikasi]/[nomor urut]/[kode unit]/[tahun]"),
        ("Sifat", ":", "[Biasa / Segera]"),
        ("Lampiran", ":", "[jumlah] lembar"),
        ("Hal", ":", "Undangan [nama kegiatan]"),
    ], [3, 0.5, 13])
    para(d, space_after=8)
    para(d, "Yth. [Jabatan / Nama Penerima]")
    para(d, "di")
    para(d, "Tempat", space_after=10, indent_cm=1)
    placeholder(d, "Dalam rangka [maksud kegiatan], dengan hormat kami mengundang [Bapak/Ibu/Saudara] untuk hadir pada:", 0)
    plain_table(d, [
        ("Hari, tanggal", ":", "[hari], [tanggal bulan tahun]"),
        ("Waktu", ":", "Pukul [00.00] WIB s.d. selesai"),
        ("Tempat", ":", "[nama ruang / gedung, alamat]"),
        ("Acara", ":", "[acara]"),
        ("Pakaian", ":", "[pakaian dinas]"),
    ], [3, 0.5, 13]).rows[0].cells[0].paragraphs[0].paragraph_format.left_indent = Cm(1)
    para(d, space_after=6)
    placeholder(d, "[Catatan tambahan, mis. konfirmasi kehadiran kepada [nama petugas], telepon [nomor].]", 0)
    placeholder(d, "Demikian, atas perhatian dan kehadirannya disampaikan terima kasih.", 0)
    signature(d)
    para(d)
    para(d, "Tembusan:", size=10)
    para(d, "1. [Jabatan];", size=10)
    para(d, "2. Arsip.", size=10)
    return d


def doc_notulen_rapat():
    d = doc_new("Notulen Rapat")
    kop(d)
    para(d, "NOTULEN RAPAT", bold=True, size=13, align=WD_ALIGN_PARAGRAPH.CENTER)
    para(d, "[Nama / Topik Rapat]", align=WD_ALIGN_PARAGRAPH.CENTER, space_after=12)
    plain_table(d, [
        ("Hari, tanggal", ":", "[hari], [tanggal bulan tahun]"),
        ("Waktu", ":", "Pukul [00.00] s.d. [00.00] WIB"),
        ("Tempat", ":", "[tempat rapat / tautan rapat daring]"),
        ("Pimpinan Rapat", ":", "[Jabatan]"),
        ("Notulis", ":", "[Jabatan]"),
        ("Peserta", ":", "[jumlah] orang (daftar hadir terlampir)"),
        ("Agenda", ":", "[agenda rapat]"),
    ], [4, 0.5, 12])
    heading(d, "A. Pembukaan")
    placeholder(d, "[Ringkasan pembukaan oleh pimpinan rapat.]")
    heading(d, "B. Pembahasan")
    grid_table(d, ["No", "Pokok Bahasan", "Uraian / Tanggapan", "Tindak Lanjut", "Penanggung Jawab"], 5, [1.2, 3.5, 5.5, 3.5, 3])
    heading(d, "C. Kesimpulan / Keputusan")
    for i in range(1, 4):
        placeholder(d, f"{i}. [Kesimpulan atau keputusan rapat.]")
    heading(d, "D. Penutup")
    placeholder(d, "Rapat ditutup pukul [00.00] WIB.")
    signature(d, jabatan="Notulis,", left="Pimpinan Rapat,")
    return d


def doc_laporan_kegiatan():
    d = doc_new("Laporan Kegiatan")
    kop(d)
    para(d, "LAPORAN KEGIATAN", bold=True, size=13, align=WD_ALIGN_PARAGRAPH.CENTER)
    para(d, "[NAMA KEGIATAN]", bold=True, align=WD_ALIGN_PARAGRAPH.CENTER, space_after=12)
    heading(d, "I. PENDAHULUAN")
    for sub, txt in (
        ("A. Latar Belakang", "[Alasan kegiatan dilaksanakan.]"),
        ("B. Maksud dan Tujuan", "[Maksud dan tujuan kegiatan.]"),
        ("C. Dasar Hukum", "1. [Peraturan / keputusan yang menjadi dasar kegiatan.]"),
        ("D. Ruang Lingkup", "[Cakupan kegiatan.]"),
    ):
        para(d, sub, bold=True, indent_cm=0.5)
        placeholder(d, txt, 1)
    heading(d, "II. PELAKSANAAN KEGIATAN")
    para(d, "A. Waktu dan Tempat", bold=True, indent_cm=0.5)
    placeholder(d, "[hari], [tanggal bulan tahun], pukul [00.00] WIB, di [tempat].", 1)
    para(d, "B. Peserta", bold=True, indent_cm=0.5)
    placeholder(d, "[Jumlah dan unsur peserta.]", 1)
    para(d, "C. Susunan Acara", bold=True, indent_cm=0.5)
    grid_table(d, ["No", "Waktu", "Acara", "Pengisi / Narasumber"], 4, [1.2, 3, 7.5, 5])
    para(d, "D. Anggaran", bold=True, indent_cm=0.5)
    placeholder(d, "[Sumber dana dan realisasi anggaran, bila ada.]", 1)
    heading(d, "III. HASIL KEGIATAN")
    placeholder(d, "[Hasil yang dicapai, keluaran, dan capaian terhadap tujuan.]")
    heading(d, "IV. KENDALA DAN TINDAK LANJUT")
    placeholder(d, "[Kendala yang dihadapi dan rencana tindak lanjut.]")
    heading(d, "V. PENUTUP")
    placeholder(d, "Demikian laporan ini disusun sebagai bahan [pertanggungjawaban / evaluasi].")
    signature(d)
    heading(d, "LAMPIRAN")
    placeholder(d, "1. Daftar hadir peserta\n2. Dokumentasi kegiatan\n3. [Lampiran lain]")
    return d


def doc_telaahan_staf():
    d = doc_new("Telaahan Staf")
    kop(d)
    para(d, "TELAAHAN STAF", bold=True, size=13, align=WD_ALIGN_PARAGRAPH.CENTER, space_after=12)
    plain_table(d, [
        ("Kepada", ":", "Yth. [Jabatan Pimpinan]"),
        ("Dari", ":", "[Jabatan Penelaah]"),
        ("Tanggal", ":", "[tanggal bulan tahun]"),
        ("Nomor", ":", "[nomor]"),
        ("Lampiran", ":", "[jumlah] berkas"),
        ("Hal", ":", "[Pokok persoalan yang ditelaah]"),
    ], [3, 0.5, 13])
    bottom_rule(para(d, space_after=10), size=6)
    sections = (
        ("1. Persoalan", "[Rumusan persoalan secara singkat, dalam bentuk pernyataan yang akan dipecahkan.]"),
        ("2. Praanggapan", "[Dugaan yang beralasan tentang keadaan yang mungkin terjadi, bila fakta belum lengkap.]"),
        ("3. Fakta-fakta yang Mempengaruhi", "a. [Fakta yang berkaitan dengan persoalan.]\nb. [Fakta lain.]"),
        ("4. Analisis", "[Pembahasan fakta dan praanggapan; kemungkinan pemecahan beserta untung ruginya.]"),
        ("5. Kesimpulan", "[Pemecahan terbaik dari hasil analisis.]"),
        ("6. Saran / Tindakan yang Disarankan", "[Tindakan yang diusulkan kepada pimpinan untuk diputuskan.]"),
    )
    for h, txt in sections:
        heading(d, h, space_before=6)
        placeholder(d, txt)
    signature(d, jabatan="[Jabatan Penelaah],")
    return d


# ════════════════════════════════════════════════════════════════════
# Sheets (.xlsx)
# ════════════════════════════════════════════════════════════════════

THIN = Side(style="thin", color="808080")
BOX = Border(left=THIN, right=THIN, top=THIN, bottom=THIN)
HEAD_FILL = PatternFill("solid", fgColor="D9E2F3")
TOTAL_FILL = PatternFill("solid", fgColor="F2F2F2")
RP = '#,##0;[Red]-#,##0;"-"'
CENTER = Alignment(horizontal="center", vertical="center", wrap_text=True)


def wb_new(title: str):
    wb = Workbook()
    ws = wb.active
    ws.title = title[:31]
    p = wb.properties
    p.title = title
    p.subject = f"Templat {title}"
    p.creator = MARK
    p.lastModifiedBy = MARK
    p.keywords = KEYWORDS
    p.description = COMMENT
    p.category = "Templat Kisara"
    p.created = FIXED_DATE
    p.modified = FIXED_DATE
    ws.oddFooter.left.text = FOOTER
    ws.oddFooter.left.size = 8
    ws.oddFooter.right.text = "Halaman &P dari &N"
    ws.oddFooter.right.size = 8
    ws.page_setup.paperSize = ws.PAPERSIZE_A4
    ws.page_setup.fitToWidth = 1
    ws.page_setup.fitToHeight = 0
    ws.sheet_properties.pageSetUpPr.fitToPage = True
    ws.sheet_view.showGridLines = False
    return wb, ws


def xl_kop(ws, last_col: int, title: str, subtitle: str | None = None) -> int:
    """Rows 1-4: kop + title. Returns the next free row."""
    end = get_column_letter(last_col)
    rows = [(PEMKAB, 12, True), (OPD, 13, True), (title, 12, True)]
    if subtitle:
        rows.append((subtitle, 11, False))
    for i, (txt, size, bold) in enumerate(rows, start=1):
        ws.merge_cells(f"A{i}:{end}{i}")
        c = ws[f"A{i}"]
        c.value = txt
        c.font = Font(name="Arial", size=size, bold=bold)
        c.alignment = Alignment(horizontal="center")
    return len(rows) + 2


def xl_info(ws, row: int, items) -> int:
    for label, val in items:
        ws.cell(row=row, column=1, value=label).font = Font(name="Arial", size=10)
        ws.cell(row=row, column=3, value=": " + val).font = Font(name="Arial", size=10)
        row += 1
    return row + 1


def xl_header(ws, row: int, headers, widths):
    for i, (h, w) in enumerate(zip(headers, widths), start=1):
        c = ws.cell(row=row, column=i, value=h)
        c.font = Font(name="Arial", size=10, bold=True)
        c.fill = HEAD_FILL
        c.alignment = CENTER
        c.border = BOX
        ws.column_dimensions[get_column_letter(i)].width = w
    ws.row_dimensions[row].height = 30


def xl_body_cell(ws, row, col, value=None, fmt=None, align=None):
    c = ws.cell(row=row, column=col, value=value)
    c.border = BOX
    c.font = Font(name="Arial", size=10)
    if fmt:
        c.number_format = fmt
    if align:
        c.alignment = align
    return c


def xl_signature(ws, row: int, col: int, jabatan="[Jabatan Penanda Tangan]"):
    lines = ["Ponorogo, [tanggal bulan tahun]", jabatan, "", "", "", "[Nama Lengkap]", "NIP [NIP]"]
    for i, txt in enumerate(lines):
        c = ws.cell(row=row + i, column=col, value=txt or None)
        c.font = Font(name="Arial", size=10, bold=(txt == "[Nama Lengkap]"), underline="single" if txt == "[Nama Lengkap]" else None)
    return row + len(lines)


def xl_mark(ws, row: int):
    c = ws.cell(row=row, column=1, value=FOOTER)
    c.font = Font(name="Arial", size=8, italic=True, color="808080")


def xlsx_anggaran():
    wb, ws = wb_new("Anggaran Kegiatan")
    headers = ["No", "Kode Rekening", "Uraian Belanja", "Volume", "Satuan", "Harga Satuan (Rp)", "Jumlah (Rp)", "Keterangan"]
    widths = [5, 18, 40, 9, 10, 18, 18, 22]
    r = xl_kop(ws, len(headers), "RENCANA ANGGARAN KEGIATAN", "Tahun Anggaran [tahun]")
    r = xl_info(ws, r, [("Program", "[nama program]"), ("Kegiatan", "[nama kegiatan]"),
                        ("Sub Kegiatan", "[nama sub kegiatan]"), ("Sumber Dana", "[APBD / lainnya]")])
    xl_header(ws, r, headers, widths)
    first = r + 1
    n = 15
    for i in range(n):
        row = first + i
        xl_body_cell(ws, row, 1, i + 1, align=Alignment(horizontal="center"))
        for col in (2, 3, 5, 8):
            xl_body_cell(ws, row, col)
        xl_body_cell(ws, row, 4, None, "#,##0.##")
        xl_body_cell(ws, row, 6, None, RP)
        xl_body_cell(ws, row, 7, f'=IF(OR(D{row}="",F{row}=""),"",D{row}*F{row})', RP)
    last = first + n - 1
    tot = last + 1
    ws.merge_cells(start_row=tot, start_column=1, end_row=tot, end_column=6)
    c = xl_body_cell(ws, tot, 1, "JUMLAH TOTAL", align=Alignment(horizontal="right"))
    c.font = Font(name="Arial", size=10, bold=True)
    for col in range(2, 7):
        ws.cell(row=tot, column=col).border = BOX
    t = xl_body_cell(ws, tot, 7, f"=SUM(G{first}:G{last})", RP)
    t.font = Font(name="Arial", size=10, bold=True)
    xl_body_cell(ws, tot, 8)
    for col in range(1, 9):
        ws.cell(row=tot, column=col).fill = TOTAL_FILL
    ws.freeze_panes = ws.cell(row=first, column=1)
    ws.page_setup.orientation = "landscape"
    ws.print_title_rows = f"{r}:{r}"
    end = xl_signature(ws, tot + 2, 7)
    xl_mark(ws, end + 1)
    return wb


def xlsx_daftar_hadir():
    wb, ws = wb_new("Daftar Hadir")
    headers = ["No", "Nama", "NIP", "Jabatan", "Instansi / Unit Kerja", "Nomor HP", "Tanda Tangan"]
    widths = [5, 30, 22, 24, 26, 16, 18]
    r = xl_kop(ws, len(headers), "DAFTAR HADIR")
    r = xl_info(ws, r, [("Kegiatan", "[nama kegiatan / rapat]"), ("Hari, tanggal", "[hari], [tanggal bulan tahun]"),
                        ("Waktu", "Pukul [00.00] WIB"), ("Tempat", "[tempat]")])
    xl_header(ws, r, headers, widths)
    first = r + 1
    n = 30
    for i in range(n):
        row = first + i
        ws.row_dimensions[row].height = 28
        xl_body_cell(ws, row, 1, i + 1, align=Alignment(horizontal="center", vertical="center"))
        for col in range(2, 7):
            xl_body_cell(ws, row, col, None, "@" if col in (3, 6) else None, Alignment(vertical="center"))
        # Signatures zig-zag left/right so neighbours do not sign over each other.
        xl_body_cell(ws, row, 7, f"{i + 1}.", align=Alignment(horizontal="left" if i % 2 == 0 else "right", vertical="top"))
    last = first + n - 1
    ws.cell(row=last + 2, column=1, value="Jumlah hadir").font = Font(name="Arial", size=10, bold=True)
    c = ws.cell(row=last + 2, column=3, value=f'=COUNTA(B{first}:B{last})&" orang"')
    c.font = Font(name="Arial", size=10, bold=True)
    ws.freeze_panes = ws.cell(row=first, column=1)
    ws.print_title_rows = f"{r}:{r}"
    end = xl_signature(ws, last + 4, 5)
    xl_mark(ws, end + 1)
    return wb


def xlsx_rekap_absensi():
    wb, ws = wb_new("Rekap Absensi")
    days = 31
    codes = ["H", "S", "I", "C", "DL", "TK"]
    headers = ["No", "Nama", "NIP"] + [str(d) for d in range(1, days + 1)] + codes + ["% Hadir"]
    widths = [5, 28, 21] + [4.2] * days + [5] * len(codes) + [9]
    r = xl_kop(ws, len(headers), "REKAP ABSENSI PEGAWAI", "Bulan [bulan] Tahun [tahun]")
    info_row = r
    r = xl_info(ws, r, [("Unit Kerja", "[nama unit kerja]")])
    # Working days drive the attendance percentage; the user fills it in.
    ws.cell(row=info_row + 1, column=1, value="Hari kerja").font = Font(name="Arial", size=10)
    hk = ws.cell(row=info_row + 1, column=3, value=None)
    hk.border = BOX
    hk.number_format = "0"
    hk_ref = f"$C${info_row + 1}"
    r += 1
    xl_header(ws, r, headers, widths)
    first = r + 1
    n = 25
    day_start, day_end = 4, 3 + days
    code_col = {code: day_end + 1 + i for i, code in enumerate(codes)}
    pct_col = day_end + len(codes) + 1
    dv = DataValidation(type="list", formula1='"' + ",".join(codes) + '"', allow_blank=True,
                        showErrorMessage=True, errorTitle="Kode tidak dikenal",
                        error="Isi dengan H, S, I, C, DL, atau TK.")
    ws.add_data_validation(dv)
    for i in range(n):
        row = first + i
        xl_body_cell(ws, row, 1, i + 1, align=Alignment(horizontal="center"))
        xl_body_cell(ws, row, 2)
        xl_body_cell(ws, row, 3, None, "@")
        span = f"{get_column_letter(day_start)}{row}:{get_column_letter(day_end)}{row}"
        for col in range(day_start, day_end + 1):
            xl_body_cell(ws, row, col, align=Alignment(horizontal="center"))
        dv.add(span)
        for code, col in code_col.items():
            xl_body_cell(ws, row, col, f'=COUNTIF({span},"{code}")', "0", Alignment(horizontal="center"))
        h = f"{get_column_letter(code_col['H'])}{row}"
        dl = f"{get_column_letter(code_col['DL'])}{row}"
        xl_body_cell(ws, row, pct_col, f'=IF(OR({hk_ref}="",{hk_ref}=0,B{row}=""),"",({h}+{dl})/{hk_ref})', "0%",
                     Alignment(horizontal="center"))
    last = first + n - 1
    ws.freeze_panes = ws.cell(row=first, column=day_start)
    ws.page_setup.orientation = "landscape"
    ws.print_title_rows = f"{r}:{r}"
    leg = last + 2
    ws.cell(row=leg, column=1, value="Keterangan:").font = Font(name="Arial", size=10, bold=True)
    legend = ["H = Hadir", "S = Sakit", "I = Izin", "C = Cuti", "DL = Dinas Luar", "TK = Tanpa Keterangan",
              "% Hadir = (H + DL) / hari kerja"]
    for i, txt in enumerate(legend, start=1):
        ws.cell(row=leg + i, column=2, value=txt).font = Font(name="Arial", size=10)
    end = xl_signature(ws, leg, 25)
    xl_mark(ws, max(end, leg + len(legend)) + 2)
    return wb


def xlsx_inventaris():
    wb, ws = wb_new("Inventaris Aset")
    headers = ["No", "Kode Barang", "Nama Barang", "Merek / Tipe", "Nomor Register", "Tahun Perolehan",
               "Jumlah", "Satuan", "Harga Satuan (Rp)", "Nilai Perolehan (Rp)", "Kondisi", "Lokasi / Ruang", "Keterangan"]
    widths = [5, 18, 28, 20, 14, 11, 8, 9, 17, 19, 9, 20, 20]
    r = xl_kop(ws, len(headers), "DAFTAR INVENTARIS ASET", "Per [tanggal bulan tahun]")
    r = xl_info(ws, r, [("Unit Kerja", "[nama unit kerja]"), ("Pengurus Barang", "[Jabatan Pengurus Barang]")])
    xl_header(ws, r, headers, widths)
    first = r + 1
    n = 30
    dv = DataValidation(type="list", formula1='"B,RR,RB"', allow_blank=True, showErrorMessage=True,
                        errorTitle="Kondisi tidak dikenal", error="Isi B (baik), RR (rusak ringan), atau RB (rusak berat).")
    ws.add_data_validation(dv)
    for i in range(n):
        row = first + i
        xl_body_cell(ws, row, 1, i + 1, align=Alignment(horizontal="center"))
        for col in (2, 3, 4, 5, 8, 12, 13):
            xl_body_cell(ws, row, col, None, "@" if col in (2, 5) else None)
        xl_body_cell(ws, row, 6, None, "0", Alignment(horizontal="center"))
        xl_body_cell(ws, row, 7, None, "#,##0", Alignment(horizontal="center"))
        xl_body_cell(ws, row, 9, None, RP)
        xl_body_cell(ws, row, 10, f'=IF(OR(G{row}="",I{row}=""),"",G{row}*I{row})', RP)
        xl_body_cell(ws, row, 11, None, None, Alignment(horizontal="center"))
    last = first + n - 1
    dv.add(f"K{first}:K{last}")
    tot = last + 1
    ws.merge_cells(start_row=tot, start_column=1, end_row=tot, end_column=6)
    c = xl_body_cell(ws, tot, 1, "JUMLAH", align=Alignment(horizontal="right"))
    c.font = Font(name="Arial", size=10, bold=True)
    for col in range(2, 14):
        ws.cell(row=tot, column=col).border = BOX
        ws.cell(row=tot, column=col).fill = TOTAL_FILL
    ws.cell(row=tot, column=1).fill = TOTAL_FILL
    q = xl_body_cell(ws, tot, 7, f"=SUM(G{first}:G{last})", "#,##0", Alignment(horizontal="center"))
    q.font = Font(name="Arial", size=10, bold=True)
    q.fill = TOTAL_FILL
    v = xl_body_cell(ws, tot, 10, f"=SUM(J{first}:J{last})", RP)
    v.font = Font(name="Arial", size=10, bold=True)
    v.fill = TOTAL_FILL
    ws.freeze_panes = ws.cell(row=first, column=1)
    ws.page_setup.orientation = "landscape"
    ws.print_title_rows = f"{r}:{r}"
    ws.cell(row=tot + 2, column=2, value="Kondisi: B = Baik, RR = Rusak Ringan, RB = Rusak Berat").font = Font(name="Arial", size=9)
    end = xl_signature(ws, tot + 4, 10, "Pengurus Barang,")
    xl_mark(ws, end + 1)
    return wb


def xlsx_jadwal_piket():
    wb, ws = wb_new("Jadwal Piket")
    headers = ["No", "Tanggal", "Hari", "Nama Petugas", "NIP", "Shift", "Paraf", "Keterangan"]
    widths = [5, 13, 11, 30, 22, 10, 12, 24]
    r = xl_kop(ws, len(headers), "JADWAL PIKET", "Bulan [bulan] Tahun [tahun]")
    r = xl_info(ws, r, [("Unit Kerja", "[nama unit kerja]"), ("Lokasi", "[ruang / pos piket]")])
    xl_header(ws, r, headers, widths)
    first = r + 1
    n = 31
    dv = DataValidation(type="list", formula1='"Pagi,Siang,Malam"', allow_blank=True)
    ws.add_data_validation(dv)
    for i in range(n):
        row = first + i
        xl_body_cell(ws, row, 1, i + 1, align=Alignment(horizontal="center"))
        xl_body_cell(ws, row, 2, None, "dd/mm/yyyy", Alignment(horizontal="center"))
        # Day name follows the date the user types in column B.
        xl_body_cell(ws, row, 3,
                     f'=IF(B{row}="","",CHOOSE(WEEKDAY(B{row},2),"Senin","Selasa","Rabu","Kamis","Jumat","Sabtu","Minggu"))',
                     None, Alignment(horizontal="center"))
        for col in (4, 7, 8):
            xl_body_cell(ws, row, col)
        xl_body_cell(ws, row, 5, None, "@")
        xl_body_cell(ws, row, 6, None, None, Alignment(horizontal="center"))
    last = first + n - 1
    dv.add(f"F{first}:F{last}")
    ws.freeze_panes = ws.cell(row=first, column=1)
    ws.print_title_rows = f"{r}:{r}"
    ws.cell(row=last + 2, column=2, value="Hari terisi otomatis dari kolom Tanggal.").font = Font(name="Arial", size=9, italic=True)
    end = xl_signature(ws, last + 4, 7, "[Jabatan Atasan],")
    xl_mark(ws, end + 1)
    return wb


# ════════════════════════════════════════════════════════════════════
# Slides (.pptx), 16:9
# ════════════════════════════════════════════════════════════════════

NAVY = PRGB(0x1F, 0x3A, 0x5F)
GOLD = PRGB(0xC9, 0xA2, 0x27)
GREY = PRGB(0x80, 0x80, 0x80)
WHITE = PRGB(0xFF, 0xFF, 0xFF)
INK = PRGB(0x26, 0x26, 0x26)
W, H = Inches(13.333), Inches(7.5)


def prs_new(title: str) -> Presentation:
    p = Presentation()
    p.slide_width, p.slide_height = W, H
    cp = p.core_properties
    cp.title = title
    cp.subject = f"Templat {title}"
    cp.author = MARK
    cp.last_modified_by = MARK
    cp.keywords = KEYWORDS
    cp.comments = COMMENT
    cp.category = "Templat Kisara"
    cp.created = FIXED_DATE
    cp.modified = FIXED_DATE
    cp.revision = 1
    return p


def blank(p):
    return p.slides.add_slide(p.slide_layouts[6])


def box(slide, x, y, w, h, text, size=18, bold=False, color=INK, align=PP_ALIGN.LEFT, font="Arial"):
    tb = slide.shapes.add_textbox(x, y, w, h)
    tf = tb.text_frame
    tf.word_wrap = True
    lines = text.split("\n") if isinstance(text, str) else text
    for i, line in enumerate(lines):
        para_ = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        para_.alignment = align
        run = para_.add_run()
        run.text = line
        run.font.size = PPt(size)
        run.font.bold = bold
        run.font.name = font
        run.font.color.rgb = color
    return tb


def rect(slide, x, y, w, h, fill):
    s = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, x, y, w, h)
    s.fill.solid()
    s.fill.fore_color.rgb = fill
    s.line.fill.background()
    return s


def footer(slide, n=None):
    box(slide, Inches(0.5), Inches(7.0), Inches(8), Inches(0.35), f"{FOOTER}  |  {OPD}", 9, color=GREY)
    if n is not None:
        box(slide, Inches(11.8), Inches(7.0), Inches(1), Inches(0.35), str(n), 9, color=GREY, align=PP_ALIGN.RIGHT)


def cover(p, title, subtitle):
    s = blank(p)
    rect(s, 0, 0, W, H, NAVY)
    rect(s, Inches(0.8), Inches(3.55), Inches(1.6), Emu(54000), GOLD)
    box(s, Inches(0.8), Inches(0.7), Inches(11), Inches(0.5), PEMKAB, 16, True, WHITE)
    box(s, Inches(0.8), Inches(1.15), Inches(11), Inches(0.5), OPD, 14, False, WHITE)
    box(s, Inches(0.8), Inches(2.2), Inches(11.5), Inches(1.3), title, 40, True, WHITE)
    box(s, Inches(0.8), Inches(3.8), Inches(11.5), Inches(0.8), subtitle, 20, False, WHITE)
    box(s, Inches(0.8), Inches(5.6), Inches(11.5), Inches(0.9),
        "[Nama Penyaji]  |  [Jabatan]\nPonorogo, [tanggal bulan tahun]", 14, False, WHITE)
    box(s, Inches(0.8), Inches(7.0), Inches(8), Inches(0.35), FOOTER, 9, color=PRGB(0xBF, 0xBF, 0xBF))
    return s


def titled(p, title, n):
    s = blank(p)
    rect(s, 0, 0, W, Inches(1.1), NAVY)
    rect(s, 0, Inches(1.1), W, Emu(45720), GOLD)
    box(s, Inches(0.5), Inches(0.22), Inches(12.3), Inches(0.7), title, 28, True, WHITE)
    footer(s, n)
    return s


def bullets(p, title, items, n):
    s = titled(p, title, n)
    tb = s.shapes.add_textbox(Inches(0.7), Inches(1.5), Inches(11.9), Inches(5.2))
    tf = tb.text_frame
    tf.word_wrap = True
    for i, item in enumerate(items):
        level = 1 if item.startswith("  ") else 0
        pr = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        pr.level = level
        pr.space_after = PPt(10)
        run = pr.add_run()
        run.text = ("• " if level == 0 else "– ") + item.strip()
        run.font.size = PPt(22 if level == 0 else 18)
        run.font.name = "Arial"
        run.font.color.rgb = INK
    return s


def table_slide(p, title, headers, rows, n, widths=None, note=None):
    s = titled(p, title, n)
    top = Inches(1.5)
    height = Inches(0.5) * (len(rows) + 1)
    shape = s.shapes.add_table(len(rows) + 1, len(headers), Inches(0.6), top, Inches(12.1), height)
    t = shape.table
    if widths:
        total = sum(widths)
        for i, w in enumerate(widths):
            t.columns[i].width = int(Inches(12.1) * w / total)
    for c, h in enumerate(headers):
        cell = t.cell(0, c)
        cell.text = h
        cell.fill.solid()
        cell.fill.fore_color.rgb = NAVY
        para_ = cell.text_frame.paragraphs[0]
        para_.runs[0].font.size = PPt(14)
        para_.runs[0].font.bold = True
        para_.runs[0].font.color.rgb = WHITE
        para_.runs[0].font.name = "Arial"
    for r, row in enumerate(rows, start=1):
        for c, val in enumerate(row):
            cell = t.cell(r, c)
            cell.text = val
            run = cell.text_frame.paragraphs[0].runs[0] if cell.text_frame.paragraphs[0].runs else None
            if run:
                run.font.size = PPt(13)
                run.font.name = "Arial"
                run.font.color.rgb = INK
    if note:
        box(s, Inches(0.6), top + height + Inches(0.2), Inches(12), Inches(0.5), note, 12, color=GREY)
    return s


def steps_slide(p, title, steps, n):
    s = titled(p, title, n)
    count = len(steps)
    gap = Inches(0.25)
    w = int((Inches(12.1) - gap * (count - 1)) / count)
    for i, (head, body) in enumerate(steps):
        x = Inches(0.6) + (w + gap) * i
        card = rect(s, x, Inches(2.0), w, Inches(3.6), PRGB(0xEE, 0xF2, 0xF8))
        card.line.color.rgb = NAVY
        num = s.shapes.add_shape(MSO_SHAPE.OVAL, x + Inches(0.2), Inches(2.2), Inches(0.7), Inches(0.7))
        num.fill.solid()
        num.fill.fore_color.rgb = GOLD
        num.line.fill.background()
        num.text_frame.text = str(i + 1)
        r = num.text_frame.paragraphs[0].runs[0]
        r.font.size, r.font.bold, r.font.color.rgb = PPt(18), True, WHITE
        num.text_frame.paragraphs[0].alignment = PP_ALIGN.CENTER
        box(s, x + Inches(0.2), Inches(3.05), w - Inches(0.4), Inches(0.6), head, 17, True, NAVY)
        box(s, x + Inches(0.2), Inches(3.7), w - Inches(0.4), Inches(1.8), body, 13, False, INK)
    return s


def closing(p, title="Terima Kasih", sub="[Kontak: nama layanan, telepon, alamat pos-el]"):
    s = blank(p)
    rect(s, 0, 0, W, H, NAVY)
    box(s, Inches(0.8), Inches(2.6), Inches(11.7), Inches(1.2), title, 44, True, WHITE, PP_ALIGN.CENTER)
    box(s, Inches(0.8), Inches(3.9), Inches(11.7), Inches(0.8), sub, 18, False, WHITE, PP_ALIGN.CENTER)
    box(s, Inches(0.8), Inches(7.0), Inches(8), Inches(0.35), FOOTER, 9, color=PRGB(0xBF, 0xBF, 0xBF))
    return s


def pptx_paparan_pemkab():
    p = prs_new("Paparan Pemkab")
    cover(p, "[JUDUL PAPARAN]", "[Subjudul atau acara, mis. Rapat Koordinasi ...]")
    bullets(p, "Agenda", ["Latar belakang", "Kondisi saat ini", "Program dan strategi", "Rencana tindak lanjut"], 2)
    bullets(p, "Latar Belakang", ["[Dasar kebijakan / regulasi]", "[Permasalahan utama]", "  [Rincian permasalahan]",
                                 "[Sasaran yang ingin dicapai]"], 3)
    table_slide(p, "Kondisi Saat Ini", ["No", "Indikator", "Target", "Realisasi", "Capaian (%)"],
                [[f"{i}.", "[indikator]", "[target]", "[realisasi]", "[%]"] for i in range(1, 6)], 4,
                [1, 6, 2, 2, 2], "Sumber: [sumber data], [periode]")
    bullets(p, "Program dan Strategi", ["[Program 1]: [ringkasan]", "[Program 2]: [ringkasan]",
                                       "[Program 3]: [ringkasan]"], 5)
    table_slide(p, "Rencana Tindak Lanjut", ["No", "Kegiatan", "Penanggung Jawab", "Target Waktu"],
                [[f"{i}.", "[kegiatan]", "[OPD / bidang]", "[bulan tahun]"] for i in range(1, 5)], 6, [1, 7, 3, 2.5])
    closing(p)
    return p


def pptx_laporan_bulanan():
    p = prs_new("Laporan Bulanan")
    cover(p, "LAPORAN BULANAN", "Bulan [bulan] Tahun [tahun]")
    bullets(p, "Ringkasan", ["[Capaian utama bulan ini]", "[Hal yang perlu perhatian pimpinan]",
                             "[Keputusan yang dibutuhkan]"], 2)
    table_slide(p, "Capaian Kinerja", ["No", "Indikator Kinerja", "Target", "Realisasi", "Capaian (%)", "Keterangan"],
                [[f"{i}.", "[indikator]", "[target]", "[realisasi]", "[%]", "[keterangan]"] for i in range(1, 6)], 3,
                [1, 5, 2, 2, 2, 3])
    table_slide(p, "Realisasi Anggaran", ["No", "Program / Kegiatan", "Pagu (Rp)", "Realisasi (Rp)", "Realisasi (%)"],
                [[f"{i}.", "[program / kegiatan]", "[pagu]", "[realisasi]", "[%]"] for i in range(1, 5)]
                + [["", "JUMLAH", "[total pagu]", "[total realisasi]", "[%]"]], 4, [1, 6, 2.5, 2.5, 2])
    bullets(p, "Kegiatan Utama", ["[Kegiatan 1]: [tanggal], [hasil]", "[Kegiatan 2]: [tanggal], [hasil]",
                                 "[Kegiatan 3]: [tanggal], [hasil]"], 5)
    table_slide(p, "Kendala dan Solusi", ["No", "Kendala", "Solusi / Tindak Lanjut", "Dukungan yang Dibutuhkan"],
                [[f"{i}.", "[kendala]", "[solusi]", "[dukungan]"] for i in range(1, 4)], 6, [1, 4, 4, 4])
    bullets(p, "Rencana Bulan Depan", ["[Rencana 1]", "[Rencana 2]", "[Rencana 3]"], 7)
    closing(p)
    return p


def pptx_sosialisasi():
    p = prs_new("Sosialisasi")
    cover(p, "SOSIALISASI\n[NAMA PROGRAM / KEBIJAKAN]", "[Sasaran peserta], [tempat]")
    bullets(p, "Latar Belakang", ["[Mengapa program / kebijakan ini ada]", "[Masalah yang ingin diselesaikan]"], 2)
    bullets(p, "Dasar Hukum", ["[Undang-undang / peraturan pemerintah]", "[Peraturan daerah / peraturan bupati]",
                               "[Keputusan / surat edaran terkait]"], 3)
    bullets(p, "Tujuan", ["[Tujuan 1]", "[Tujuan 2]", "[Manfaat bagi masyarakat / pegawai]"], 4)
    bullets(p, "Materi Pokok", ["[Pokok materi 1]", "  [rincian]", "[Pokok materi 2]", "  [rincian]",
                                "[Pokok materi 3]"], 5)
    steps_slide(p, "Alur / Tahapan", [("[Tahap 1]", "[Uraian singkat langkah pertama]"),
                                      ("[Tahap 2]", "[Uraian singkat langkah kedua]"),
                                      ("[Tahap 3]", "[Uraian singkat langkah ketiga]"),
                                      ("[Tahap 4]", "[Uraian singkat langkah keempat]")], 6)
    bullets(p, "Layanan dan Kontak", ["Layanan: [nama layanan / laman]", "Telepon / WhatsApp: [nomor layanan]",
                                     "Alamat: [alamat kantor]"], 7)
    closing(p, "Tanya Jawab", "Terima kasih atas perhatiannya")
    return p


def pptx_rapat_pimpinan():
    p = prs_new("Rapat Pimpinan")
    cover(p, "RAPAT PIMPINAN", "[Hari], [tanggal bulan tahun]")
    bullets(p, "Agenda Rapat", ["Tindak lanjut rapat sebelumnya", "Laporan bidang", "Isu strategis",
                                "Usulan keputusan", "Arahan pimpinan"], 2)
    table_slide(p, "Tindak Lanjut Rapat Sebelumnya", ["No", "Arahan / Keputusan", "Penanggung Jawab", "Status", "Keterangan"],
                [[f"{i}.", "[arahan]", "[bidang]", "[Selesai / Proses / Belum]", "[keterangan]"] for i in range(1, 6)], 3,
                [1, 5, 2.5, 2.5, 3])
    table_slide(p, "Laporan Bidang", ["Bidang", "Capaian", "Kendala", "Rencana"],
                [["[Bidang 1]", "[capaian]", "[kendala]", "[rencana]"], ["[Bidang 2]", "[capaian]", "[kendala]", "[rencana]"],
                 ["[Bidang 3]", "[capaian]", "[kendala]", "[rencana]"], ["[Sekretariat]", "[capaian]", "[kendala]", "[rencana]"]],
                4, [2.5, 3.5, 3.5, 3.5])
    bullets(p, "Isu Strategis", ["[Isu 1]: [dampak dan urgensi]", "[Isu 2]: [dampak dan urgensi]"], 5)
    table_slide(p, "Usulan Keputusan", ["No", "Usulan", "Pengusul", "Keputusan"],
                [[f"{i}.", "[usulan]", "[bidang]", "[disetujui / ditunda / ditolak]"] for i in range(1, 4)], 6, [1, 6, 2.5, 3.5])
    bullets(p, "Arahan Pimpinan", ["[Arahan 1]", "[Arahan 2]", "[Arahan 3]"], 7)
    closing(p, "Rapat Ditutup", "Notulen dan tindak lanjut dibagikan kepada peserta")
    return p


# ════════════════════════════════════════════════════════════════════
# Output: pinned dates, normalized zip, checks
# ════════════════════════════════════════════════════════════════════

TEMPLATES = [
    ("Nota Dinas.docx", doc_nota_dinas),
    ("Surat Undangan.docx", doc_surat_undangan),
    ("Notulen Rapat.docx", doc_notulen_rapat),
    ("Laporan Kegiatan.docx", doc_laporan_kegiatan),
    ("Telaahan Staf.docx", doc_telaahan_staf),
    ("Anggaran Kegiatan.xlsx", xlsx_anggaran),
    ("Daftar Hadir.xlsx", xlsx_daftar_hadir),
    ("Rekap Absensi.xlsx", xlsx_rekap_absensi),
    ("Inventaris Aset.xlsx", xlsx_inventaris),
    ("Jadwal Piket.xlsx", xlsx_jadwal_piket),
    ("Paparan Pemkab.pptx", pptx_paparan_pemkab),
    ("Laporan Bulanan.pptx", pptx_laporan_bulanan),
    ("Sosialisasi.pptx", pptx_sosialisasi),
    ("Rapat Pimpinan.pptx", pptx_rapat_pimpinan),
]

ISO = FIXED_DATE.strftime("%Y-%m-%dT%H:%M:%SZ")


def normalize(raw: bytes) -> bytes:
    """
    Rewrite the OOXML zip deterministically: fixed entry timestamps, fixed
    document dates (openpyxl stamps `modified` with the save time whatever
    the property says), same entry order, [Content_Types].xml first.
    """
    src = zipfile.ZipFile(io.BytesIO(raw))
    names = src.namelist()
    names.sort(key=lambda n: (n != "[Content_Types].xml",))  # stable: keeps the rest in order
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for name in names:
            data = src.read(name)
            if name == "docProps/core.xml":
                text = data.decode("utf-8")
                text = re.sub(r"(<dcterms:(?:created|modified)[^>]*>)[^<]*(</dcterms:)", rf"\g<1>{ISO}\g<2>", text)
                data = text.encode("utf-8")
            info = zipfile.ZipInfo(name, date_time=FIXED_DATE.timetuple()[:6])
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            z.writestr(info, data, compresslevel=9)
    return out.getvalue()


def save(obj) -> bytes:
    buf = io.BytesIO()
    obj.save(buf)
    return normalize(buf.getvalue())


def check(path: Path) -> list[str]:
    """Round-trip with the same library + zip sanity. Returns problems."""
    problems = []
    with zipfile.ZipFile(path) as z:
        bad = z.testzip()
        if bad:
            problems.append(f"zip CRC error in {bad}")
        names = set(z.namelist())
        if "[Content_Types].xml" not in names or "docProps/core.xml" not in names:
            problems.append("missing [Content_Types].xml or docProps/core.xml")
        core = z.read("docProps/core.xml").decode("utf-8")
        if MARK not in core:
            problems.append("core properties lack the Kisara (bawaan) mark")
        main = {".docx": "word/document.xml", ".xlsx": "xl/workbook.xml", ".pptx": "ppt/presentation.xml"}[path.suffix]
        if main not in names:
            problems.append(f"missing {main}")
        blob = b"".join(z.read(n) for n in names if n.endswith(".xml"))
        if FOOTER.encode() not in blob:
            problems.append("no visible 'Templat Kisara (bawaan)' footer text")
        if re.search(rb"\b\d{18}\b", blob):
            problems.append("contains an 18-digit number (NIP?)")
    if path.suffix == ".docx":
        d = Document(str(path))
        if not d.paragraphs or d.core_properties.author != MARK:
            problems.append("docx round-trip: no paragraphs or wrong author")
    elif path.suffix == ".xlsx":
        from openpyxl import load_workbook
        wb = load_workbook(str(path))
        if wb.properties.creator != MARK or wb.active.max_row < 10:
            problems.append("xlsx round-trip: wrong creator or empty sheet")
    else:
        p = Presentation(str(path))
        if len(p.slides) < 5 or p.core_properties.author != MARK:
            problems.append("pptx round-trip: too few slides or wrong author")
    return problems


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    failed = 0
    for name, build in TEMPLATES:
        data = save(build())
        path = OUT / name
        path.write_bytes(data)
        line = f"{name:<26} {len(data):>7} B  sha256 {hashlib.sha256(data).hexdigest()[:16]}"
        if "--check" in sys.argv:
            problems = check(path)
            line += "  OK" if not problems else "  GAGAL: " + "; ".join(problems)
            failed += bool(problems)
        print(line)
    print(f"{len(TEMPLATES)} berkas di {OUT}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
