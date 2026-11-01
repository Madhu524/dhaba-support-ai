import re
import json
import logging
import sys
from datetime import datetime, timezone
from typing import Any, Dict

# Regex patterns for sensitive PII/financial data
GSTIN_PATTERN = re.compile(r"\b[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}\b", re.IGNORECASE)
EMAIL_PATTERN = re.compile(r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b")
PHONE_PATTERN = re.compile(r"(?:\+?91[\-\s]?|[0]?)?[6-9]\d{9}\b")
CARD_PATTERN = re.compile(r"\b(?:\d[ -]*?){13,16}\b")
KNOWN_ENTITIES_PATTERN = re.compile(r"\b(Acme\s+Interiors)\b", re.IGNORECASE)

def sanitize_text(text: str) -> str:
    """Redact sensitive PII and financial patterns from string text."""
    if not text:
        return ""
    text = GSTIN_PATTERN.sub("[REDACTED_GSTIN]", text)
    text = EMAIL_PATTERN.sub("[REDACTED_EMAIL]", text)
    text = PHONE_PATTERN.sub("[REDACTED_PHONE]", text)
    text = CARD_PATTERN.sub("[REDACTED_CARD]", text)
    text = KNOWN_ENTITIES_PATTERN.sub("[REDACTED_COMPANY]", text)
    return text

class SafeJsonFormatter(logging.Formatter):
    """Structured JSON formatter with automated PII redaction."""
    def format(self, record: logging.LogRecord) -> str:
        log_payload = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "level": record.levelname,
            "logger": record.name,
            "message": sanitize_text(record.getMessage()),
        }
        
        # Include extra attributes if present, sanitizing strings
        if hasattr(record, "ticket_id"):
            log_payload["ticket_id"] = record.ticket_id
        if hasattr(record, "category"):
            log_payload["category"] = record.category
        if hasattr(record, "severity"):
            log_payload["severity"] = record.severity
        if hasattr(record, "status_code"):
            log_payload["status_code"] = record.status_code
        if hasattr(record, "latency_ms"):
            log_payload["latency_ms"] = record.latency_ms
        if hasattr(record, "event"):
            log_payload["event"] = record.event
        if hasattr(record, "extra_data") and isinstance(record.extra_data, dict):
            sanitized_extra = {}
            for k, v in record.extra_data.items():
                if k in ["body", "raw_prompt", "full_ticket"]:
                    sanitized_extra[f"{k}_length"] = len(str(v)) if v else 0
                elif isinstance(v, str):
                    sanitized_extra[k] = sanitize_text(v)
                else:
                    sanitized_extra[k] = v
            log_payload["data"] = sanitized_extra

        return json.dumps(log_payload)

def setup_logger(name: str = "dhaba_support") -> logging.Logger:
    logger = logging.getLogger(name)
    if not logger.handlers:
        handler = logging.StreamHandler(sys.stdout)
        handler.setFormatter(SafeJsonFormatter())
        logger.addHandler(handler)
        logger.setLevel(logging.INFO)
    logger.propagate = False
    return logger

logger = setup_logger()
