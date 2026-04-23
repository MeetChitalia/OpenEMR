#!/usr/bin/env python3
"""
Lightweight Jacki Python bot service.

Routes only:
- conversational generation
- AI draft generation for knowledge suggestions

Grounded live OpenEMR lookups remain in PHP/OpenEMR.
"""

from __future__ import annotations

import json
import os
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


HOST = os.getenv("JACKI_PYTHON_BOT_HOST", "127.0.0.1")
PORT = int(os.getenv("JACKI_PYTHON_BOT_PORT", "8011"))
TOKEN = os.getenv("JACKI_PYTHON_BOT_TOKEN", "").strip()
OPENAI_API_KEY = os.getenv("JACKI_OPENAI_API_KEY") or os.getenv("OPENAI_API_KEY", "")
OPENAI_MODEL = os.getenv("JACKI_OPENAI_MODEL") or os.getenv("OPENAI_MODEL", "gpt-4o")
OPENAI_TIMEOUT = int(os.getenv("JACKI_PYTHON_BOT_OPENAI_TIMEOUT_SECONDS", os.getenv("JACKI_OPENAI_TIMEOUT_SECONDS", "20")))
OPENAI_ENDPOINT = "https://api.openai.com/v1/responses"


def normalize_space(value: str) -> str:
    return " ".join((value or "").strip().split())


def deidentify(value: str) -> str:
    # Keep this intentionally lightweight because PHP already de-identifies before draft generation.
    return normalize_space(value)


def build_context_lines(context: dict[str, Any]) -> str:
    if not isinstance(context, dict):
        return ""

    parts: list[str] = []
    area = normalize_space(str(context.get("area", "")))
    title = normalize_space(str(context.get("title", "")))
    url = normalize_space(str(context.get("url", "")))
    patient_id = context.get("patient_id")
    report_name = normalize_space(str(context.get("report_name", "")))
    pos_state = normalize_space(str(context.get("pos_state", "")))

    if area:
        parts.append(f"Current area: {area}")
    if title:
        parts.append(f"Page title: {title}")
    if url:
        parts.append(f"Page url: {url}")
    if patient_id:
        parts.append(f"Context patient id: {patient_id}")
    if report_name:
        parts.append(f"Context report: {report_name}")
    if pos_state:
        parts.append(f"POS state: {pos_state}")

    return "\n".join(parts)


def build_conversation_transcript(history: list[dict[str, Any]]) -> str:
    lines: list[str] = []
    for entry in history or []:
        if not isinstance(entry, dict):
            continue
        role = str(entry.get("role", "")).strip()
        content = normalize_space(str(entry.get("content", "")))
        if role not in {"user", "assistant"} or not content:
            continue
        speaker = "jacki" if role == "assistant" else "User"
        lines.append(f"{speaker}: {content}")
    return "\n".join(lines)


def build_conversation_task(payload: dict[str, Any]) -> tuple[str, str]:
    mode = "patient" if str(payload.get("mode", "staff")) == "patient" else "staff"
    message = normalize_space(str(payload.get("message", "")))
    history = payload.get("history") if isinstance(payload.get("history"), list) else []
    context = payload.get("context") if isinstance(payload.get("context"), dict) else {}

    if mode == "patient":
        instructions = (
            "You are jacki, a patient-facing OpenEMR support assistant. "
            "Be warm, concise, and realistic. "
            "Do not give diagnosis, emergency triage, medication dosing, or private account-specific details. "
            "If the situation could be urgent, clearly tell the user to contact their clinician or emergency services. "
            "Offer practical next steps for appointments, billing questions, office contact, or portal help. "
            "Write like a polished chat assistant, not like a robotic FAQ."
        )
    else:
        instructions = (
            "You are jacki, an internal workflow assistant for OpenEMR staff. "
            "Be concise, practical, and confident. "
            "Help with operational workflow questions. "
            "Do not invent patient records, appointment data, inventory facts, revenue facts, or report values. "
            "If specific live data is unavailable, say so clearly and give the next best OpenEMR workflow step. "
            "For greetings, acknowledgements, or casual conversation, reply naturally in one or two short sentences and then offer one concrete thing you can help with."
        )

    prompt = f"Current message:\n{message}"
    transcript = build_conversation_transcript(history)
    if transcript:
        prompt += f"\n\nRecent conversation:\n{transcript}"
    context_text = build_context_lines(context)
    if context_text:
        prompt += f"\n\nOpenEMR page context:\n{context_text}"

    return instructions, prompt


def build_knowledge_draft_task(payload: dict[str, Any]) -> tuple[str, str]:
    mode = "patient" if str(payload.get("mode", "staff")) == "patient" else "staff"
    sample_message = deidentify(str(payload.get("sample_message", "")))
    latest_reply = deidentify(str(payload.get("latest_reply", "")))
    fallback_pattern = normalize_space(str(payload.get("fallback_pattern", "")))

    instructions = (
        "You are helping prepare reviewed knowledge drafts for an internal OpenEMR assistant. "
        "Given a de-identified user question and the assistant's latest weak or incomplete reply, produce a safe reusable trigger pattern and a concise draft answer. "
        "Do not include PHI, names, phone numbers, IDs, dates of birth, addresses, or anything patient-specific. "
        "Do not invent live financial, patient, scheduling, or inventory data. "
        "The pattern should be a short reusable phrase that could match similar future questions. "
        'The answer should be practical, neutral, and safe for staff review. Return strict JSON only with keys "pattern" and "answer".'
    )
    if mode == "patient":
        instructions += " Patient-mode drafts must stay general, non-diagnostic, and non-sensitive."
    else:
        instructions += " Staff-mode drafts may describe workflow guidance, but must not pretend to know live records."

    prompt = (
        f"Question:\n{sample_message}\n\n"
        f"Current weak reply:\n{latest_reply}\n\n"
        f"Fallback pattern:\n{fallback_pattern}"
    )
    return instructions, prompt


def call_openai(instructions: str, prompt: str) -> dict[str, Any]:
    if not OPENAI_API_KEY:
        raise RuntimeError("Missing OpenAI API key for Python bot service")

    request_payload = json.dumps(
        {
            "model": OPENAI_MODEL,
            "instructions": instructions,
            "input": prompt,
        }
    ).encode("utf-8")

    request = Request(
        OPENAI_ENDPOINT,
        data=request_payload,
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {OPENAI_API_KEY}",
        },
        method="POST",
    )

    with urlopen(request, timeout=OPENAI_TIMEOUT) as response:
        raw = response.read().decode("utf-8")
    parsed = json.loads(raw)
    if not isinstance(parsed, dict):
        raise RuntimeError("Invalid OpenAI response payload")
    return parsed


def extract_output_text(response_data: dict[str, Any]) -> str:
    output_text = response_data.get("output_text")
    if isinstance(output_text, str) and output_text.strip():
        return output_text.strip()

    output = response_data.get("output")
    if not isinstance(output, list):
        return ""

    for item in output:
        if not isinstance(item, dict) or item.get("type") != "message":
            continue
        content = item.get("content")
        if not isinstance(content, list):
            continue
        for part in content:
            if not isinstance(part, dict):
                continue
            if part.get("type") == "output_text" and isinstance(part.get("text"), str):
                return part["text"].strip()
            if part.get("type") == "refusal" and isinstance(part.get("refusal"), str):
                return part["refusal"].strip()
    return ""


def parse_json_object(text: str) -> dict[str, Any]:
    text = text.strip()
    if not text:
        return {}
    try:
        parsed = json.loads(text)
        return parsed if isinstance(parsed, dict) else {}
    except json.JSONDecodeError:
        start = text.find("{")
        end = text.rfind("}")
        if start >= 0 and end > start:
            try:
                parsed = json.loads(text[start : end + 1])
                return parsed if isinstance(parsed, dict) else {}
            except json.JSONDecodeError:
                return {}
    return {}


class JackiBotHandler(BaseHTTPRequestHandler):
    server_version = "JackiPythonBot/0.1"

    def do_GET(self) -> None:
        if self.path.rstrip("/") == "/health":
            self._write_json(200, {"ok": True, "service": "jacki_python_bot"})
            return
        self._write_json(404, {"success": False, "error": "Not found"})

    def do_POST(self) -> None:
        if self.path.rstrip("/") != "/task":
            self._write_json(404, {"success": False, "error": "Not found"})
            return

        if TOKEN:
            provided = self.headers.get("X-Jacki-Token", "")
            if provided != TOKEN:
                self._write_json(403, {"success": False, "error": "Forbidden"})
                return

        length = int(self.headers.get("Content-Length", "0") or 0)
        raw = self.rfile.read(length) if length > 0 else b"{}"
        try:
            payload = json.loads(raw.decode("utf-8"))
        except json.JSONDecodeError:
            self._write_json(400, {"success": False, "error": "Invalid JSON"})
            return

        task = str(payload.get("task", "")).strip()
        try:
            if task == "conversation":
                instructions, prompt = build_conversation_task(payload)
                response_data = call_openai(instructions, prompt)
                reply = extract_output_text(response_data)
                if not reply:
                    raise RuntimeError("Empty conversation reply")
                self._write_json(200, {"success": True, "reply": reply})
                return

            if task == "knowledge_draft":
                instructions, prompt = build_knowledge_draft_task(payload)
                response_data = call_openai(instructions, prompt)
                draft = parse_json_object(extract_output_text(response_data))
                self._write_json(
                    200,
                    {
                        "success": True,
                        "pattern": normalize_space(str(draft.get("pattern", ""))),
                        "answer": normalize_space(str(draft.get("answer", ""))),
                    },
                )
                return

            self._write_json(400, {"success": False, "error": "Unknown task"})
        except (RuntimeError, HTTPError, URLError, TimeoutError, OSError) as exc:
            self._write_json(502, {"success": False, "error": str(exc)})

    def log_message(self, format: str, *args: Any) -> None:
        sys.stdout.write("%s - - [%s] %s\n" % (self.address_string(), self.log_date_time_string(), format % args))

    def _write_json(self, status: int, payload: dict[str, Any]) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def main() -> None:
    server = ThreadingHTTPServer((HOST, PORT), JackiBotHandler)
    print(f"Jacki Python bot listening on http://{HOST}:{PORT}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
