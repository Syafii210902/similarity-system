"""
Ekstraksi teks dari berkas pengumpulan (PDF, DOCX, TXT) + statistik isi.

Gambar TIDAK dianalisis (tanpa OCR). Karena itu setiap dokumen juga dihitung jumlah kata,
halaman, dan gambarnya, lalu diberi peringatan bila teksnya terlalu sedikit sehingga
kemungkinan sebagian isinya berupa gambar/hasil scan dan perlu diperiksa manual.
"""

from __future__ import annotations

import io
from typing import Optional, Tuple

import docx
import pypdf

W_NS = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
A_BLIP = "{http://schemas.openxmlformats.org/drawingml/2006/main}blip"
MC_FALLBACK = "{http://schemas.openxmlformats.org/markup-compatibility/2006}Fallback"

# Ambang peringatan (heuristik, bukan dari literatur).
MIN_WORDS_PER_PDF_PAGE = 50     # halaman PDF berisi teks biasanya ratusan kata
MIN_WORDS_PER_IMAGE = 100       # dokumen dengan banyak gambar tetapi sedikit teks
MIN_WORDS_TOTAL = 50


def extract(content: bytes, file_name: str) -> Tuple[str, dict]:
    """Kembalikan (teks, statistik). Statistik: words, pages (PDF saja), images."""
    name = file_name.lower()
    if name.endswith(".pdf"):
        text, pages, images = _pdf(content)
    elif name.endswith(".docx"):
        text, pages, images = _docx(content)
    else:
        text, pages, images = content.decode("utf-8", errors="ignore"), None, 0

    text = text.strip()
    return text, {"words": len(text.split()), "pages": pages, "images": images}


def _pdf(content: bytes) -> Tuple[str, int, int]:
    reader = pypdf.PdfReader(io.BytesIO(content))
    texts, images = [], 0
    for page in reader.pages:
        texts.append(page.extract_text() or "")
        images += _pdf_page_images(page)
    return "\n".join(texts), len(reader.pages), images


def _pdf_page_images(page) -> int:
    """Hitung XObject bergambar pada halaman (tanpa men-decode gambarnya)."""
    try:
        resources = page.get("/Resources")
        resources = resources.get_object() if resources is not None else None
        xobjects = resources.get("/XObject") if resources else None
        if xobjects is None:
            return 0
        return sum(1 for obj in xobjects.get_object().values() if obj.get_object().get("/Subtype") == "/Image")
    except Exception:
        return 0


def _docx(content: bytes) -> Tuple[str, None, int]:
    """Teks seluruh badan dokumen sesuai urutan: paragraf, isi tabel, dan text box.

    Header/footer sengaja tidak diambil karena biasanya berisi identitas/judul yang sama
    untuk semua mahasiswa dan akan menaikkan skor kemiripan secara semu.
    """
    body = docx.Document(io.BytesIO(content)).element.body

    # Kelompokkan teks per paragraf terdekat. Paragraf di dalam tabel dan text box ikut terbaca.
    # Konten di mc:Fallback adalah salinan cadangan text box/gambar untuk Word lama: dilewati agar tidak dobel.
    paragraphs: dict = {}
    order = []
    for node in body.iter(W_NS + "t", W_NS + "tab", W_NS + "br"):
        paragraph, skip = _owner_paragraph(node)
        if skip or paragraph is None:
            continue
        key = id(paragraph)
        if key not in paragraphs:
            paragraphs[key] = []
            order.append(key)
        paragraphs[key].append((node.text or "") if node.tag == W_NS + "t" else " ")

    text = "\n".join(t for t in ("".join(paragraphs[k]).strip() for k in order) if t)
    images = sum(1 for blip in body.iter(A_BLIP) if not _owner_paragraph(blip)[1])
    return text, None, images


def _owner_paragraph(node):
    """(paragraf terdekat, berada di dalam mc:Fallback?)"""
    paragraph = None
    parent = node.getparent()
    while parent is not None:
        if parent.tag == MC_FALLBACK:
            return paragraph, True
        if paragraph is None and parent.tag == W_NS + "p":
            paragraph = parent
        parent = parent.getparent()
    return paragraph, False


def text_warning(stats: dict) -> Optional[str]:
    """Pesan peringatan bila isi dokumen kemungkinan banyak berupa gambar/scan, atau None."""
    words, pages, images = stats["words"], stats.get("pages"), stats.get("images", 0)

    if words == 0:
        return "Tidak ada teks yang dapat dibaca. Kemungkinan hasil scan atau seluruh isinya berupa gambar, sehingga dokumen ini tidak ikut dianalisis."
    if pages and words / pages < MIN_WORDS_PER_PDF_PAGE:
        return f"Teks sangat sedikit (± {round(words / pages)} kata per halaman). Sebagian isi kemungkinan berupa gambar atau hasil scan yang tidak ikut dianalisis."
    if images and words < MIN_WORDS_PER_IMAGE * images:
        return f"Berisi {images} gambar dengan hanya {words} kata. Teks di dalam gambar (mis. screenshot) tidak ikut dianalisis."
    if words < MIN_WORDS_TOTAL:
        return f"Teks sangat pendek ({words} kata), sehingga skor kemiripan kurang dapat diandalkan."
    return None
