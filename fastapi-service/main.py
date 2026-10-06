from fastapi import FastAPI, BackgroundTasks, Header, HTTPException, Request, status
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field
from typing import List, Optional, Tuple
import hmac
import requests
import pypdf
import docx
import io
import os
import time
from sentence_transformers import SentenceTransformer

import llm
import multilayer

app = FastAPI(title="Campus Plagiarism & AI Detector Engine")


@app.middleware("http")
async def require_engine_key(request: Request, call_next):
    """Semua endpoint /api/ wajib membawa X-API-Key yang benar, dicek sebelum validasi body."""
    if request.url.path.startswith("/api/"):
        key = request.headers.get("x-api-key") or ""
        if not ENGINE_API_KEY or not hmac.compare_digest(key, ENGINE_API_KEY):
            return JSONResponse(status_code=status.HTTP_401_UNAUTHORIZED, content={"detail": "Invalid X-API-Key Header"})
    return await call_next(request)

# Load Sentence Transformer Model untuk Similarity Fast Check (Tier 1).
# Model multilingual agar teks berbahasa Indonesia terwakili dengan baik.
# max_seq_length model ini 128 token, sehingga dokumen di-encode per kalimat & passage (lihat multilayer.py).
EMBEDDING_MODEL_NAME = os.getenv("EMBEDDING_MODEL_NAME", "paraphrase-multilingual-MiniLM-L12-v2")
model = SentenceTransformer(EMBEDDING_MODEL_NAME)

# Parameter Multi-Layer Embedding (dikirim balik di metrics agar eksperimen dapat direproduksi).
PASSAGE_MAX_WORDS = int(os.getenv("PASSAGE_MAX_WORDS", "60"))
SENTENCE_MATCH_THRESHOLD = float(os.getenv("SENTENCE_MATCH_THRESHOLD", "0.85"))
LAYER_WEIGHTS = tuple(float(w) for w in os.getenv("LAYER_WEIGHTS", "0.2,0.4,0.4").split(","))  # dokumen, passage, kalimat
EVIDENCE_PASSAGES = int(os.getenv("EVIDENCE_PASSAGES", "5"))

# Kunci antar-service (diset lewat docker-compose dari .env root).
# ENGINE_API_KEY  : dicek pada request masuk dari Laravel.
# WEBHOOK_API_KEY : dikirim saat callback ke Laravel.
ENGINE_API_KEY = os.getenv("ENGINE_API_KEY", "")
WEBHOOK_API_KEY = os.getenv("WEBHOOK_API_KEY", "")

# Nilai yang dikenali Laravel (harus sinkron dengan enum di database).
MATCH_TYPES = {"VERBATIM_COPY", "PARAPHRASED"}
ACTION_RECOMMENDATIONS = {"PERIKSA_MANUAL", "SKIP_KOREKSI", "AMAN"}


class DocumentItem(BaseModel):
    submission_id: int
    student_name: str
    file_url: str


class RunConfig(BaseModel):
    """Parameter per run (Lab Pengujian). Nilai kosong = pakai default dari env."""
    passage_max_words: Optional[int] = Field(None, ge=10, le=100)
    sentence_match_threshold: Optional[float] = Field(None, ge=0.5, le=1.0)
    layer_weights: Optional[List[float]] = Field(None, min_length=3, max_length=3)  # dokumen, passage, kalimat
    evidence_passages: Optional[int] = Field(None, ge=1, le=10)
    run_tier2: bool = True
    run_ai_detection: bool = True


class AnalysisRequest(BaseModel):
    # assignment_id kosong untuk eksperimen Lab Pengujian; analysis_run_id = id run di Laravel.
    assignment_id: Optional[int] = None
    analysis_run_id: Optional[int] = None
    callback_url: str
    threshold: float = 0.70
    documents: List[DocumentItem]
    config: Optional[RunConfig] = None


class CompareRequest(BaseModel):
    text_a: str = Field(..., min_length=20, max_length=200_000)
    text_b: str = Field(..., min_length=20, max_length=200_000)
    config: Optional[RunConfig] = None


def effective_params(config: Optional[RunConfig]) -> dict:
    """Gabungkan konfigurasi per run dengan default env."""
    config = config or RunConfig()
    weights = tuple(config.layer_weights) if config.layer_weights else LAYER_WEIGHTS
    if sum(weights) <= 0 or any(w < 0 for w in weights):
        raise ValueError("layer_weights harus non-negatif dan berjumlah > 0")
    return {
        "passage_max_words": config.passage_max_words or PASSAGE_MAX_WORDS,
        "sentence_match_threshold": config.sentence_match_threshold or SENTENCE_MATCH_THRESHOLD,
        "weights": weights,
        "evidence_passages": config.evidence_passages or EVIDENCE_PASSAGES,
        "run_tier2": config.run_tier2,
        "run_ai_detection": config.run_ai_detection,
    }


def download_and_extract_text(file_url: str) -> str:
    """Mengunduh file dari URL dan mengekstrak teksnya (PDF, DOCX, TXT)."""
    try:
        response = requests.get(file_url, timeout=15)
        response.raise_for_status()
        file_bytes = io.BytesIO(response.content)

        if file_url.lower().endswith('.pdf'):
            reader = pypdf.PdfReader(file_bytes)
            raw_text = "\n".join([page.extract_text() or "" for page in reader.pages])
        elif file_url.lower().endswith('.docx'):
            doc = docx.Document(file_bytes)
            raw_text = "\n".join([p.text for p in doc.paragraphs if p.text])
        else:
            raw_text = response.content.decode('utf-8', errors='ignore')

        return raw_text.strip()
    except Exception as e:
        print(f"Error downloading/extracting {file_url}: {e}", flush=True)
        return ""


def normalize_match_type(value: object) -> str:
    """Petakan variasi label dari LLM ke VERBATIM_COPY / PARAPHRASED."""
    text = str(value or "").strip().upper().replace("-", "_").replace(" ", "_")
    if text in MATCH_TYPES:
        return text
    if any(key in text for key in ("VERBATIM", "COPY", "EXACT", "IDENTIK", "SALIN")):
        return "VERBATIM_COPY"
    return "PARAPHRASED"


def analyze_similarity_with_llm(
    doc_a_name: str, doc_b_name: str, evidence: List[Tuple[str, str, float]], usage: Optional[dict] = None
) -> dict:
    """Tier 2: bedakan segmen salinan langsung (VERBATIM_COPY) dan parafrase (PARAPHRASED) antar 2 dokumen.

    LLM menerima pasangan passage paling mirip hasil Tier 1 (dari seluruh isi dokumen),
    bukan hanya bagian awal dokumen.
    """
    evidence_text = "\n\n".join(
        f"[PASANGAN {i}] (cosine {score:.2f})\nA: {text_a}\nB: {text_b}"
        for i, (text_a, text_b, score) in enumerate(evidence, start=1)
    )
    prompt = f"""
Anda adalah mesin analisis plagiarisme.
Berikut pasangan potongan teks yang paling mirip antara Dokumen A ({doc_a_name}) dan Dokumen B ({doc_b_name}),
dipilih otomatis dari seluruh isi kedua dokumen. Nilai cosine hanya petunjuk, bukan keputusan.

{evidence_text}

Tugas Anda: tentukan apakah ada plagiarisme, dan untuk setiap bagian yang benar-benar mirip
kutip potongan teks dari A dan B lalu klasifikasikan jenisnya. Abaikan kemiripan yang hanya
karena topik tugas yang sama (istilah umum, definisi baku, judul tugas).

Aturan:
- "match_type" WAJIB salah satu dari: "VERBATIM_COPY" (kalimat disalin sama persis atau hampir sama persis)
  atau "PARAPHRASED" (gagasan sama, susunan kata berbeda).
- "action_recommendation" WAJIB salah satu dari: "PERIKSA_MANUAL" atau "AMAN".
- Kembalikan HANYA JSON murni tanpa pembungkus markdown dan tanpa teks lain.

Format JSON:
{{
  "verdict": "Indikasi Plagiarisme Tinggi",
  "action_recommendation": "PERIKSA_MANUAL",
  "summary": "Ringkasan bukti kemiripan...",
  "matched_segments": [
    {{
      "segment_id": 1,
      "text_doc_a": "potongan teks A...",
      "text_doc_b": "potongan teks B...",
      "match_type": "VERBATIM_COPY"
    }}
  ]
}}
"""
    data = llm.call_llm_json(prompt, llm.SIMILARITY_SCHEMA, f"similarity {doc_a_name} vs {doc_b_name}", usage)

    if data is None:
        return {
            "llm_error": True,
            "verdict": "Analisis LLM gagal",
            "action_recommendation": "PERIKSA_MANUAL",
            "summary": "Layanan LLM tidak merespons setelah beberapa percobaan. Pasangan ini perlu diperiksa manual.",
            "matched_segments": []
        }

    segments = []
    for index, seg in enumerate(data.get("matched_segments") or [], start=1):
        if not isinstance(seg, dict):
            continue
        segments.append({
            "segment_id": index,
            "text_doc_a": str(seg.get("text_doc_a") or ""),
            "text_doc_b": str(seg.get("text_doc_b") or ""),
            "match_type": normalize_match_type(seg.get("match_type")),
        })

    action = str(data.get("action_recommendation") or "").strip().upper()
    return {
        "llm_error": False,
        "verdict": str(data.get("verdict") or "Tidak ada keterangan")[:255],
        "action_recommendation": action if action in ACTION_RECOMMENDATIONS else "PERIKSA_MANUAL",
        "summary": str(data.get("summary") or ""),
        "matched_segments": segments,
    }


def verdict_from_probability(probability: float) -> str:
    """Ambang verdict deteksi AI (sama dengan instruksi di prompt)."""
    if probability >= 0.70:
        return "LIKELY_AI"
    if probability >= 0.35:
        return "MIXED_AI"
    return "HUMAN_WRITTEN"


def detect_ai_generated_text(doc_name: str, text: str, usage: Optional[dict] = None) -> dict:
    """Menganalisis indikasi teks buatan AI untuk 1 dokumen secara independen."""
    prompt = f"""
Anda adalah pakar Analisis Forensik Teks & AI Content Detection.
Tugas Anda adalah mengevaluasi apakah dokumen berikut ditulis oleh manusia atau dihasilkan oleh Large Language Model (seperti ChatGPT, Gemini, Claude).

[DOKUMEN: {doc_name}] (cuplikan dari awal, tengah, dan akhir dokumen; "[...]" menandai bagian yang dilewati):
{text}

Petunjuk Analisis:
1. Periksa kekakuannya (pola kalimat berulang, struktur paragraf berimbang sempurna, nada bicara sangat formal).
2. Tentukan 'ai_probability' dalam nilai desimal antara 0.0 sampai 1.0 (Contoh: 0.85 untuk 85%).
3. Tentukan 'verdict':
   - "LIKELY_AI" (jika ai_probability >= 0.70)
   - "MIXED_AI" (jika ai_probability antara 0.35 - 0.69)
   - "HUMAN_WRITTEN" (jika ai_probability < 0.35)
4. Berikan 'analysis_summary' maksimal 2 kalimat.
5. Sebutkan beberapa 'flagged_patterns' (potongan kalimat khas AI jika ada).

Kembalikan respon HANYA dalam format JSON murni tanpa markdown formatting:
{{
  "ai_probability": 0.85,
  "verdict": "LIKELY_AI",
  "analysis_summary": "Teks menunjukkan variasi panjang kalimat yang sangat rendah serta pengulangan frasa transisi khas LLM.",
  "flagged_patterns": [
    "Penting untuk dicatat bahwa...",
    "Secara keseluruhan, dapat disimpulkan..."
  ]
}}
"""
    data = llm.call_llm_json(prompt, llm.AI_DETECTION_SCHEMA, f"AI detection {doc_name}", usage)

    try:
        if data is None:
            raise ValueError("layanan LLM tidak merespons setelah beberapa percobaan")
        probability = min(max(float(data["ai_probability"]), 0.0), 1.0)
    except (KeyError, TypeError, ValueError) as e:
        # Jangan menyamarkan kegagalan sebagai HUMAN_WRITTEN: tandai ERROR agar tidak mencemari data penelitian.
        return {
            "ai_probability": None,
            "verdict": "ERROR",
            "analysis_summary": f"Deteksi AI gagal: {e}",
            "flagged_patterns": []
        }

    patterns = data.get("flagged_patterns")
    return {
        "ai_probability": round(probability, 4),
        # Verdict diturunkan dari probabilitas agar konsisten, tidak bergantung pada label LLM.
        "verdict": verdict_from_probability(probability),
        "analysis_summary": str(data.get("analysis_summary") or ""),
        "flagged_patterns": [str(p) for p in patterns if p][:20] if isinstance(patterns, list) else []
    }


def send_callback(payload: AnalysisRequest, body: dict) -> None:
    """Kirim hasil (atau kegagalan) ke webhook Laravel. Error di sini hanya dicatat ke log."""
    body = {"assignment_id": payload.assignment_id, "analysis_run_id": payload.analysis_run_id, **body}
    print(f"Sending Webhook Callback to Laravel: {payload.callback_url} (status={body.get('status')}) ...", flush=True)
    try:
        res = requests.post(
            payload.callback_url,
            json=body,
            headers={"X-API-Key": WEBHOOK_API_KEY},
            timeout=60
        )
        print(f"Laravel Webhook Response Status: {res.status_code}", flush=True)
    except Exception as e:
        print(f"[CALLBACK FAILED] Failed to send webhook to Laravel: {e}", flush=True)


def run_analysis(payload: AnalysisRequest, metrics: dict) -> dict:
    params = effective_params(payload.config)
    """Tier 1 (embedding + cosine) lalu Tier 2 (Gemini) untuk pasangan ter-flag + deteksi AI per dokumen."""
    # 0. EKSTRAKSI TEKS
    t0 = time.perf_counter()
    extracted_docs = []
    failed_extractions = []
    for doc in payload.documents:
        print(f"Downloading file: {doc.file_url}", flush=True)
        text = download_and_extract_text(doc.file_url)
        if len(text.strip()) > 5:
            extracted_docs.append({
                "submission_id": doc.submission_id,
                "name": doc.student_name,
                "text": text
            })
        else:
            failed_extractions.append(doc.submission_id)
    metrics["extraction_seconds"] = round(time.perf_counter() - t0, 3)
    metrics["failed_extractions"] = failed_extractions

    if len(extracted_docs) < 2:
        raise ValueError(
            f"Hanya {len(extracted_docs)} dari {len(payload.documents)} dokumen yang teksnya dapat diekstrak; "
            "minimal 2 dokumen dibutuhkan untuk perbandingan."
        )

    # 1. TIER 1: MULTI-LAYER EMBEDDING (kalimat, passage, dokumen)
    print("Encoding documents (multi-layer)...", flush=True)
    t1 = time.perf_counter()
    layers = [multilayer.encode_document(model, d["text"], params["passage_max_words"]) for d in extracted_docs]

    pairs = []
    for i in range(len(extracted_docs)):
        for j in range(i + 1, len(extracted_docs)):
            layer_scores, evidence = multilayer.pair_scores(
                layers[i], layers[j], params["sentence_match_threshold"], params["evidence_passages"]
            )
            combined = multilayer.combine(layer_scores, params["weights"])
            print(
                f"Similarity ({extracted_docs[i]['name']} vs {extracted_docs[j]['name']}): "
                f"combined={combined:.4f} {layer_scores}",
                flush=True,
            )
            pairs.append((i, j, combined, layer_scores, evidence))
    metrics["tier1_seconds"] = round(time.perf_counter() - t1, 3)
    metrics["multilayer"] = {
        "passage_max_words": params["passage_max_words"],
        "sentence_match_threshold": params["sentence_match_threshold"],
        "weights": {"document": params["weights"][0], "passage": params["weights"][1], "sentence": params["weights"][2]},
        "run_tier2": params["run_tier2"],
        "run_ai_detection": params["run_ai_detection"],
        "avg_sentences": round(sum(len(l.sentences) for l in layers) / len(layers), 1),
        "avg_passages": round(sum(len(l.passages) for l in layers) / len(layers), 1),
    }

    # 2. TIER 2: VERIFIKASI LLM untuk pasangan >= threshold
    t2 = time.perf_counter()
    results = []
    flagged_count = 0
    for i, j, combined, layer_scores, evidence in pairs:
        doc_a = extracted_docs[i]
        doc_b = extracted_docs[j]
        is_flagged = combined >= payload.threshold
        llm_res = None

        if is_flagged and params["run_tier2"]:
            flagged_count += 1
            print("Score >= Threshold! Triggering Gemini LLM...", flush=True)
            llm_res = analyze_similarity_with_llm(doc_a['name'], doc_b['name'], evidence, metrics["llm_usage"])
            if llm.LLM_PROVIDER == "gemini":
                time.sleep(1.0)  # Jeda manual untuk Gemini; SDK Anthropic menangani rate limit sendiri

        results.append({
            "doc_a": {"submission_id": doc_a["submission_id"], "student_name": doc_a["name"]},
            "doc_b": {"submission_id": doc_b["submission_id"], "student_name": doc_b["name"]},
            "similarity_score": combined,
            "layer_scores": layer_scores,
            "is_flagged": is_flagged,
            "llm_analysis": llm_res
        })
    metrics["tier2_similarity_seconds"] = round(time.perf_counter() - t2, 3)
    metrics["llm_similarity_calls"] = flagged_count
    metrics["llm_similarity_calls_avoided"] = len(pairs) - flagged_count
    metrics["llm_similarity_errors"] = sum(1 for r in results if r["llm_analysis"] and r["llm_analysis"].get("llm_error"))

    # 3. DETEKSI TEKS AI (per dokumen)
    t3 = time.perf_counter()
    ai_detection_list = []
    for doc, doc_layers in zip(extracted_docs, layers):
        if not params["run_ai_detection"]:
            break
        ai_res = detect_ai_generated_text(doc['name'], multilayer.sample_excerpt(doc_layers), metrics["llm_usage"])
        ai_detection_list.append({"submission_id": doc["submission_id"], **ai_res})
        if llm.LLM_PROVIDER == "gemini":
            time.sleep(1.0)
    metrics["ai_detection_seconds"] = round(time.perf_counter() - t3, 3)
    metrics["llm_ai_detection_calls"] = len(ai_detection_list)
    metrics["ai_detection_errors"] = sum(1 for d in ai_detection_list if d["verdict"] == "ERROR")

    return {
        "status": "completed",
        "embedding_model": EMBEDDING_MODEL_NAME,
        "total_documents": len(extracted_docs),
        "total_pairs_evaluated": len(results),
        "flagged_count": flagged_count,
        "results": results,                     # Masuk ke tabel similarity_results
        "ai_detections": ai_detection_list      # Masuk ke tabel ai_detection_results
    }


def process_similarity_task(payload: AnalysisRequest):
    """Background Task: selalu mengirim callback ke Laravel, baik berhasil maupun gagal."""
    print("==========================================", flush=True)
    print(f"[TASK STARTED] Assignment ID: {payload.assignment_id}, Run ID: {payload.analysis_run_id}", flush=True)
    print(f"Total documents received: {len(payload.documents)}", flush=True)
    print("==========================================", flush=True)

    started = time.perf_counter()
    metrics: dict = {
        "threshold": payload.threshold,
        "documents_received": len(payload.documents),
        # Dicatat agar setiap run dapat direproduksi & biaya LLM dapat dipantau.
        "llm": {"provider": llm.LLM_PROVIDER, "model": llm.active_model(), "effort": llm.LLM_EFFORT if llm.LLM_PROVIDER == "anthropic" else None},
        "llm_usage": {"calls": 0, "failures": 0, "input_tokens": 0, "output_tokens": 0},
    }

    try:
        body = run_analysis(payload, metrics)
        print("[TASK COMPLETED SUCCESSFULLY]", flush=True)
    except Exception as e:
        print(f"[TASK FAILED] {type(e).__name__}: {e}", flush=True)
        body = {"status": "failed", "error": str(e) or type(e).__name__}

    metrics["total_seconds"] = round(time.perf_counter() - started, 3)
    send_callback(payload, {**body, "metrics": metrics})


@app.post("/api/v1/analyze-similarity", status_code=202)
def analyze_similarity(
    payload: AnalysisRequest,
    background_tasks: BackgroundTasks,
    x_api_key: Optional[str] = Header(None)
):
    """Endpoint yang dipanggil oleh Laravel secara asynchronous."""
    verify_engine_key(x_api_key)
    try:
        effective_params(payload.config)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))

    background_tasks.add_task(process_similarity_task, payload)
    return {
        "status": "accepted",
        "message": f"Analysis queued (assignment {payload.assignment_id}, run {payload.analysis_run_id})"
    }


def verify_engine_key(x_api_key: Optional[str]) -> None:
    if not ENGINE_API_KEY or not hmac.compare_digest(x_api_key or "", ENGINE_API_KEY):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid X-API-Key Header")


@app.post("/api/v1/compare-texts")
def compare_texts(payload: CompareRequest, x_api_key: Optional[str] = Header(None)):
    """Uji cepat dua teks (sinkron): skor per lapis, passage bukti, dan (opsional) analisis LLM."""
    verify_engine_key(x_api_key)
    try:
        params = effective_params(payload.config)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))

    timings: dict = {}
    usage: dict = {"calls": 0, "failures": 0, "input_tokens": 0, "output_tokens": 0}

    t0 = time.perf_counter()
    layer_a = multilayer.encode_document(model, payload.text_a, params["passage_max_words"])
    layer_b = multilayer.encode_document(model, payload.text_b, params["passage_max_words"])
    layer_scores, evidence = multilayer.pair_scores(
        layer_a, layer_b, params["sentence_match_threshold"], params["evidence_passages"]
    )
    combined = multilayer.combine(layer_scores, params["weights"])
    timings["tier1_seconds"] = round(time.perf_counter() - t0, 3)

    llm_result = None
    if params["run_tier2"]:
        t1 = time.perf_counter()
        llm_result = analyze_similarity_with_llm("Teks A", "Teks B", evidence, usage)
        timings["tier2_seconds"] = round(time.perf_counter() - t1, 3)

    return {
        "embedding_model": EMBEDDING_MODEL_NAME,
        "llm": {"provider": llm.LLM_PROVIDER, "model": llm.active_model()} if params["run_tier2"] else None,
        "params": {**params, "weights": list(params["weights"])},
        "counts": {
            "sentences_a": len(layer_a.sentences), "passages_a": len(layer_a.passages),
            "sentences_b": len(layer_b.sentences), "passages_b": len(layer_b.passages),
        },
        "layer_scores": layer_scores,
        "combined": combined,
        "evidence": [{"text_a": a, "text_b": b, "score": s} for a, b, s in evidence],
        "llm_analysis": llm_result,
        "llm_usage": usage,
        "timings": timings,
    }


@app.get("/")
def read_root():
    return {"service": "Campus Plagiarism & AI Detector Engine", "status": "running"}
