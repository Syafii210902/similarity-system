"""
Lapisan LLM (Tier 2 dan deteksi AI): satu fungsi `call_llm_json` yang memanggil Google Gemini.

Model dicoba berurutan sesuai GEMINI_MODELS (model kedua sebagai cadangan). Jeda antar-panggilan
dan penanganan rate limit (429) diatur lewat .env karena kuota akun gratis dibatasi per menit.
"""

from __future__ import annotations

import json
import os
import re
import time
from typing import Optional

from google import genai
from google.genai import types

GEMINI_API_KEY = os.getenv("GEMINI_API_KEY", "")
LLM_PROVIDER = "gemini"

GEMINI_MODELS = [m.strip() for m in os.getenv("GEMINI_MODELS", "gemini-3.6-flash,gemini-3.5-flash").split(",") if m.strip()]

# Kuota Gemini gratis dibatasi per menit (RPM). Jeda antar-panggilan diatur dari .env;
# mis. batas 5 RPM -> GEMINI_DELAY_SECONDS=12. Saat terkena 429, tunggu lalu coba lagi.
GEMINI_DELAY_SECONDS = float(os.getenv("GEMINI_DELAY_SECONDS", "1.0"))
GEMINI_RATE_LIMIT_RETRIES = int(os.getenv("GEMINI_RATE_LIMIT_RETRIES", "3"))
GEMINI_RATE_LIMIT_WAIT = float(os.getenv("GEMINI_RATE_LIMIT_WAIT", "30"))


def active_model() -> str:
    return ",".join(GEMINI_MODELS)


def pause_between_calls() -> None:
    """Jeda antar-panggilan Gemini agar tidak melampaui batas RPM."""
    time.sleep(GEMINI_DELAY_SECONDS)


def call_llm_json(prompt: str, schema: dict, label: str, usage: Optional[dict] = None) -> Optional[dict]:
    """Kirim prompt dan kembalikan dict JSON, atau None jika semua percobaan gagal.

    `schema` mendokumentasikan bentuk jawaban yang diharapkan (prompt sudah memuat contohnya).
    `usage` (opsional) diakumulasi: calls, input_tokens, output_tokens, failures.
    """
    if usage is not None:
        usage["calls"] = usage.get("calls", 0) + 1

    data = _call_gemini(prompt, label, usage)

    if data is None and usage is not None:
        usage["failures"] = usage.get("failures", 0) + 1
    return data


def _clean_json_response(raw_text: str) -> str:
    """Abaikan pembungkus markdown ```json agar parsing json.loads tidak error."""
    text = raw_text.strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[-1]
    if text.endswith("```"):
        text = text.rsplit("\n", 1)[0]
    if text.startswith("json"):
        text = text[4:].strip()
    return text


def _rate_limit_wait(error: Exception, attempt: int) -> Optional[float]:
    """Lama tunggu bila error berupa 429/RESOURCE_EXHAUSTED, selain itu None.

    Pakai retryDelay dari respons Google bila ada; jika tidak, backoff linear dari GEMINI_RATE_LIMIT_WAIT.
    """
    message = str(error)
    if getattr(error, "code", None) != 429 and "429" not in message and "RESOURCE_EXHAUSTED" not in message:
        return None
    match = re.search(r"retryDelay['\"]?\s*:\s*['\"]?(\d+(?:\.\d+)?)s", message)
    wait = float(match.group(1)) if match else GEMINI_RATE_LIMIT_WAIT * attempt
    return min(max(wait, GEMINI_DELAY_SECONDS), 120.0)


def _call_gemini(prompt: str, label: str, usage: Optional[dict] = None) -> Optional[dict]:
    client = genai.Client(api_key=GEMINI_API_KEY)

    for model_name in GEMINI_MODELS:
        attempt, rate_limited = 0, 0
        while attempt < 2:
            try:
                print(f"[Gemini] {label} ({model_name}) - attempt {attempt + 1}", flush=True)
                response = client.models.generate_content(
                    model=model_name,
                    contents=prompt,
                    config=types.GenerateContentConfig(response_mime_type="application/json", temperature=0.1),
                )
                meta = getattr(response, "usage_metadata", None)
                if usage is not None and meta is not None:
                    usage["input_tokens"] = usage.get("input_tokens", 0) + (meta.prompt_token_count or 0)
                    usage["output_tokens"] = usage.get("output_tokens", 0) + (meta.candidates_token_count or 0) + (meta.thoughts_token_count or 0)
                if response and response.text:
                    data = json.loads(_clean_json_response(response.text))
                    if isinstance(data, dict):
                        return data
                attempt += 1
            except Exception as e:
                wait = _rate_limit_wait(e, rate_limited + 1)
                if wait is not None and rate_limited < GEMINI_RATE_LIMIT_RETRIES:
                    # Kena batas kuota per menit: tunggu tanpa menghabiskan jatah percobaan biasa.
                    rate_limited += 1
                    print(f"[Gemini] {label} ({model_name}) rate limit, tunggu {wait:.0f} detik "
                          f"({rate_limited}/{GEMINI_RATE_LIMIT_RETRIES})", flush=True)
                    time.sleep(wait)
                    continue
                print(f"[Gemini] {label} ({model_name}) error: {e}", flush=True)
                attempt += 1
                time.sleep(2)

    return None


# ---------------------------------------------------------------------------
# Skema JSON jawaban LLM. Nilai enum harus sinkron dengan Laravel.
# ---------------------------------------------------------------------------

SIMILARITY_SCHEMA = {
    "type": "object",
    "properties": {
        "verdict": {"type": "string"},
        "action_recommendation": {"type": "string", "enum": ["PERIKSA_MANUAL", "AMAN"]},
        "summary": {"type": "string"},
        "matched_segments": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "text_doc_a": {"type": "string"},
                    "text_doc_b": {"type": "string"},
                    "match_type": {"type": "string", "enum": ["VERBATIM_COPY", "PARAPHRASED"]},
                },
                "required": ["text_doc_a", "text_doc_b", "match_type"],
                "additionalProperties": False,
            },
        },
    },
    "required": ["verdict", "action_recommendation", "summary", "matched_segments"],
    "additionalProperties": False,
}

AI_DETECTION_SCHEMA = {
    "type": "object",
    "properties": {
        "ai_probability": {"type": "number"},
        "verdict": {"type": "string", "enum": ["LIKELY_AI", "MIXED_AI", "HUMAN_WRITTEN"]},
        "analysis_summary": {"type": "string"},
        "flagged_patterns": {"type": "array", "items": {"type": "string"}},
    },
    "required": ["ai_probability", "verdict", "analysis_summary", "flagged_patterns"],
    "additionalProperties": False,
}
