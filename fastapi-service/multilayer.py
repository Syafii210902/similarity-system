"""
Multi-Layer Semantic Vector Embedding (Tier 1).

Model embedding hanya membaca ~128 token pertama dari input, sehingga dokumen utuh
tidak boleh di-encode sekaligus. Dokumen dipecah menjadi tiga lapis:

  1. Kalimat  - untuk mendeteksi salinan (hampir) persis.
  2. Passage  - gabungan kalimat berurutan (<= PASSAGE_MAX_WORDS kata, muat dalam 128 token)
                untuk menangkap kesamaan gagasan/parafrase.
  3. Dokumen  - rata-rata embedding passage (gambaran topik keseluruhan).

Skor per pasangan dokumen:
  - document : cosine(embedding dokumen A, embedding dokumen B)
  - passage  : rata-rata simetris dari kecocokan terbaik tiap passage ke dokumen lawan
  - sentence : proporsi kalimat (simetris) yang memiliki pasangan dengan cosine >= SENTENCE_MATCH_THRESHOLD
  - combined : w_doc*document + w_passage*passage + w_sentence*sentence

Lapis dokumen cenderung tinggi untuk semua pasangan pada tugas bertopik sama; lapis kalimat
& passage yang membedakan penyalinan dari sekadar kesamaan topik.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from typing import List, Tuple

import torch
from sentence_transformers import SentenceTransformer

SENTENCE_BOUNDARY = re.compile(r'(?<=[.!?])\s+(?=[A-Z0-9"“‘(\[])')
HYPHEN_LINEBREAK = re.compile(r'([a-z])-\s+([a-z])')
WHITESPACE = re.compile(r'\s+')


@dataclass
class DocumentLayers:
    sentences: List[str]
    passages: List[str]
    sentence_emb: torch.Tensor   # (S, d), ter-normalisasi
    passage_emb: torch.Tensor    # (P, d), ter-normalisasi
    document_emb: torch.Tensor   # (d,), ter-normalisasi


def normalize_text(text: str) -> str:
    """Satukan spasi/baris baru dan perbaiki pemenggalan kata akibat ekstraksi PDF ('iden- tified')."""
    text = HYPHEN_LINEBREAK.sub(r'\1\2', text)
    return WHITESPACE.sub(' ', text).strip()


def split_sentences(text: str, min_words: int = 4) -> List[str]:
    """Pisahkan kalimat; potongan yang terlalu pendek (judul, nomor halaman) digabung ke kalimat berikutnya."""
    raw = [s.strip() for s in SENTENCE_BOUNDARY.split(normalize_text(text)) if s.strip()]
    sentences: List[str] = []
    carry = ''
    for piece in raw:
        piece = f'{carry} {piece}'.strip() if carry else piece
        if len(piece.split()) < min_words:
            carry = piece
            continue
        sentences.append(piece)
        carry = ''
    if carry:
        if sentences:
            sentences[-1] = f'{sentences[-1]} {carry}'
        else:
            sentences.append(carry)
    return sentences


def build_passages(sentences: List[str], max_words: int) -> List[str]:
    """Gabungkan kalimat berurutan menjadi passage <= max_words kata; kalimat yang terlalu panjang dipotong per kata."""
    passages: List[str] = []
    current: List[str] = []
    count = 0
    for sentence in sentences:
        words = sentence.split()
        if len(words) > max_words:
            if current:
                passages.append(' '.join(current))
                current, count = [], 0
            for start in range(0, len(words), max_words):
                passages.append(' '.join(words[start:start + max_words]))
            continue
        if count + len(words) > max_words and current:
            passages.append(' '.join(current))
            current, count = [], 0
        current.append(sentence)
        count += len(words)
    if current:
        passages.append(' '.join(current))
    return passages


def encode_document(model: SentenceTransformer, text: str, passage_max_words: int) -> DocumentLayers:
    sentences = split_sentences(text) or [normalize_text(text)[:1000]]
    passages = build_passages(sentences, passage_max_words) or sentences[:1]

    embeddings = model.encode(
        sentences + passages,
        convert_to_tensor=True,
        normalize_embeddings=True,
        batch_size=64,
        show_progress_bar=False,
    )
    sentence_emb = embeddings[:len(sentences)]
    passage_emb = embeddings[len(sentences):]
    document_emb = torch.nn.functional.normalize(passage_emb.mean(dim=0), dim=0)

    return DocumentLayers(sentences, passages, sentence_emb, passage_emb, document_emb)


def pair_scores(
    a: DocumentLayers,
    b: DocumentLayers,
    sentence_match_threshold: float,
    evidence_count: int = 5,
) -> Tuple[dict, List[Tuple[str, str, float]]]:
    """Skor tiap lapis + passage-passage paling mirip (bukti untuk Tier 2)."""
    document = float(a.document_emb @ b.document_emb)

    passage_sim = a.passage_emb @ b.passage_emb.T
    passage = float((passage_sim.max(dim=1).values.mean() + passage_sim.max(dim=0).values.mean()) / 2)

    sentence_sim = a.sentence_emb @ b.sentence_emb.T
    coverage_a = (sentence_sim.max(dim=1).values >= sentence_match_threshold).float().mean()
    coverage_b = (sentence_sim.max(dim=0).values >= sentence_match_threshold).float().mean()
    sentence = float((coverage_a + coverage_b) / 2)

    # Bukti: pasangan passage dengan cosine tertinggi (tanpa memakai passage yang sama dua kali).
    evidence: List[Tuple[str, str, float]] = []
    used_a, used_b = set(), set()
    flat = passage_sim.flatten()
    order = torch.argsort(flat, descending=True)
    cols = passage_sim.shape[1]
    for index in order.tolist():
        i, j = divmod(index, cols)
        if i in used_a or j in used_b:
            continue
        evidence.append((a.passages[i], b.passages[j], round(float(flat[index]), 4)))
        used_a.add(i)
        used_b.add(j)
        if len(evidence) >= evidence_count:
            break

    return {
        'document': round(document, 4),
        'passage': round(passage, 4),
        'sentence': round(sentence, 4),
    }, evidence


def combine(scores: dict, weights: Tuple[float, float, float]) -> float:
    w_doc, w_passage, w_sentence = weights
    total = w_doc + w_passage + w_sentence
    value = (w_doc * scores['document'] + w_passage * scores['passage'] + w_sentence * scores['sentence']) / total
    return round(value, 4)


def sample_excerpt(layers: DocumentLayers, max_chars: int = 3000) -> str:
    """Cuplikan merata dari awal, tengah, hingga akhir dokumen (untuk deteksi AI)."""
    passages = layers.passages
    if not passages:
        return ''
    average = max(1, sum(len(p) for p in passages) // len(passages))
    take = max(1, min(len(passages), max_chars // average))
    if take >= len(passages):
        picked = passages
    else:
        step = (len(passages) - 1) / max(1, take - 1)
        picked = [passages[round(k * step)] for k in range(take)]
    return '\n[...]\n'.join(picked)[:max_chars]
