"""
Lapisan LLM (Tier 2): satu fungsi `call_llm_json` untuk dua provider.

  - anthropic : Claude via Anthropic API (butuh ANTHROPIC_API_KEY dari console.anthropic.com;
                langganan Claude Pro/claude.ai TIDAK mencakup akses API).
  - gemini    : Google Gemini (perilaku lama).

Provider dipilih lewat LLM_PROVIDER. Jika tidak diset: Claude bila ANTHROPIC_API_KEY ada, selain itu Gemini.

Untuk Claude dipakai structured outputs (JSON Schema), sehingga respons dijamin JSON valid sesuai
skema dan tidak perlu membersihkan pembungkus markdown.
"""

from __future__ import annotations

import json
import os
import time
from typing import Optional

import anthropic
from google import genai
from google.genai import types

ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY", "")
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY", "")

LLM_PROVIDER = (os.getenv("LLM_PROVIDER") or ("anthropic" if ANTHROPIC_API_KEY else "gemini")).strip().lower()

# Claude Opus 5.5 pada effort "low": model terkuat, tetapi berpikir seperlunya sehingga token
# (dan biaya) per panggilan tetap kecil. Tugas Tier 2 di sini berupa klasifikasi/ekstraksi pendek.
CLAUDE_MODEL = os.getenv("CLAUDE_MODEL", "claude-opus-5-5")
LLM_EFFORT = os.getenv("LLM_EFFORT", "low")          # low | medium | high
LLM_MAX_TOKENS = int(os.getenv("LLM_MAX_TOKENS", "16000"))

# Parameter yang tidak didukung semua model (mis. Haiku 4.5 menolak `effort` dengan 400).
EFFORT_MODELS = {"claude-opus-5-5", "claude-opus-5", "claude-sonnet-5-5", "claude-sonnet-5", "claude-opus-4-8", "claude-opus-4-7", "claude-opus-4-6", "claude-sonnet-4-6"}
FALLBACK_MODELS = {"claude-opus-5-5", "claude-opus-5", "claude-sonnet-5-5"}

GEMINI_MODELS = [m.strip() for m in os.getenv("GEMINI_MODELS", "gemini-3.6-flash,gemini-3.5-flash").split(",") if m.strip()]

_anthropic_client: Optional[anthropic.Anthropic] = None


def active_model() -> str:
    return CLAUDE_MODEL if LLM_PROVIDER == "anthropic" else ",".join(GEMINI_MODELS)


def _claude() -> anthropic.Anthropic:
    global _anthropic_client
    if _anthropic_client is None:
        # SDK otomatis retry untuk 408/409/429/5xx & error koneksi dengan exponential backoff.
        _anthropic_client = anthropic.Anthropic(api_key=ANTHROPIC_API_KEY or None, max_retries=4, timeout=180.0)
    return _anthropic_client


def call_llm_json(prompt: str, schema: dict, label: str, usage: Optional[dict] = None) -> Optional[dict]:
    """Kirim prompt dan kembalikan dict JSON, atau None jika semua percobaan gagal.

    `usage` (opsional) diakumulasi: calls, input_tokens, output_tokens, failures.
    """
    if usage is not None:
        usage["calls"] = usage.get("calls", 0) + 1

    data = _call_claude(prompt, schema, label, usage) if LLM_PROVIDER == "anthropic" else _call_gemini(prompt, label)

    if data is None and usage is not None:
        usage["failures"] = usage.get("failures", 0) + 1
    return data


def _call_claude(prompt: str, schema: dict, label: str, usage: Optional[dict]) -> Optional[dict]:
    if not ANTHROPIC_API_KEY:
        print(f"[Claude] {label}: ANTHROPIC_API_KEY belum diset", flush=True)
        return None

    output_config: dict = {"format": {"type": "json_schema", "schema": schema}}
    extra: dict = {}
    if CLAUDE_MODEL in EFFORT_MODELS:
        output_config["effort"] = LLM_EFFORT
    if CLAUDE_MODEL in FALLBACK_MODELS:
        # Jika model menolak (safety classifier), API otomatis mencoba model cadangan yang sesuai.
        extra = {"betas": ["server-side-fallback-2026-07-01"], "fallbacks": "default"}

    try:
        response = _claude().beta.messages.create(
            model=CLAUDE_MODEL,
            max_tokens=LLM_MAX_TOKENS,
            output_config=output_config,
            messages=[{"role": "user", "content": prompt}],
            **extra,
        )
    except anthropic.AuthenticationError:
        print(f"[Claude] {label}: API key tidak valid", flush=True)
        return None
    except anthropic.RateLimitError as e:
        print(f"[Claude] {label}: rate limit/kuota habis setelah retry ({e.message})", flush=True)
        return None
    except anthropic.APIStatusError as e:
        print(f"[Claude] {label}: API error {e.status_code}: {e.message}", flush=True)
        return None
    except anthropic.APIConnectionError as e:
        print(f"[Claude] {label}: koneksi gagal ({e})", flush=True)
        return None

    if usage is not None:
        usage["input_tokens"] = usage.get("input_tokens", 0) + response.usage.input_tokens
        usage["output_tokens"] = usage.get("output_tokens", 0) + response.usage.output_tokens

    if response.stop_reason == "refusal":
        print(f"[Claude] {label}: permintaan ditolak model ({response.stop_details})", flush=True)
        return None
    if response.stop_reason == "max_tokens":
        print(f"[Claude] {label}: output terpotong (max_tokens); naikkan LLM_MAX_TOKENS", flush=True)
        return None

    text = next((b.text for b in response.content if b.type == "text"), "")
    try:
        data = json.loads(text)
    except json.JSONDecodeError as e:
        print(f"[Claude] {label}: JSON tidak valid ({e})", flush=True)
        return None

    print(f"[Claude] {label}: ok ({response.model}, in={response.usage.input_tokens}, out={response.usage.output_tokens})", flush=True)
    return data if isinstance(data, dict) else None


def _clean_json_response(raw_text: str) -> str:
    """Abaikan pembungkus markdown ```json agar parsing json.loads tidak error (khusus Gemini)."""
    text = raw_text.strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[-1]
    if text.endswith("```"):
        text = text.rsplit("\n", 1)[0]
    if text.startswith("json"):
        text = text[4:].strip()
    return text


def _call_gemini(prompt: str, label: str) -> Optional[dict]:
    client = genai.Client(api_key=GEMINI_API_KEY)

    for model_name in GEMINI_MODELS:
        for attempt in range(2):
            try:
                print(f"[Gemini] {label} ({model_name}) - attempt {attempt + 1}", flush=True)
                response = client.models.generate_content(
                    model=model_name,
                    contents=prompt,
                    config=types.GenerateContentConfig(response_mime_type="application/json", temperature=0.1),
                )
                if response and response.text:
                    data = json.loads(_clean_json_response(response.text))
                    if isinstance(data, dict):
                        return data
            except Exception as e:
                print(f"[Gemini] {label} ({model_name}) error: {e}", flush=True)
                time.sleep(2)

    return None


# ---------------------------------------------------------------------------
# Skema JSON (structured outputs). Nilai enum harus sinkron dengan Laravel.
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
