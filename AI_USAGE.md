# AI Usage Disclosure — Dhaba Support AI Triage

This document provides transparent disclosure regarding how AI assistance was used during the development of the Dhaba Support AI Triage system for the Propel Spark Full-Stack AI Engineer assessment.

---

### 1. AI Tools Used
- **Antigravity (Google Gemini 3.8 Flash / Claude 3.5 Sonnet / Advanced Agentic Assistant)**: Used for rapid architecture scaffolding, Pydantic schema validation generation, FastAPI endpoint structure, React component scaffolding, and test generation.
- **Local Heuristic / Fixture Mode**: Implemented without external API dependency so evaluators can run deterministic triage on all 12 tickets without API keys or token costs.

### 2. Approximate Share of Code Generated
- **AI-Generated / Scaffolding**: ~65% (FastAPI routing boilerplate, initial Pydantic models, Tailwind/CSS variables, SQLite table declarations, Postman JSON structure).
- **Human / Manually Engineered & Hardened**: ~35% (Deterministic refund gate rules, prompt injection sanitization patterns, regex PII scrubbing without word-boundary false positives, circuit breaker state machine transitions, SQLite in-memory static connection pooling for pytest).

---

### 3. Best Prompt Used

```text
Act as a Principal Systems Engineer reviewing a high-throughput customer support triage microservice.
We need to design a deterministic refund policy module (refund_policy.py) that strictly adheres to:
1. Complete independence from LLM decisions (the AI must NEVER have write access to financial fields).
2. Verification against confirmed billing records only (never treat 'initiated' or 'failed' as completed).
3. Zero app opens after auto-renewal treated as forgotten subscription shock vs active app usage.
4. Defense against prompt injection where user embeds system overrides (e.g. VIP tags, prompt leak requests).
5. Escalate all financial recommendations to human supervisors (the service never authorizes disbursements directly).
Produce a clean, testable pure function with exhaustive edge case handling.
```

---

### 4. One Specific AI-Generated Mistake

- **The Mistake**:
  When initially generating the phone number PII redaction regular expression in `app/utils/logger.py`, the AI generated:
  ```python
  PHONE_PATTERN = re.compile(r"\b(?:\+91[\-\s]?)?[6-9]\d{9}\b")
  ```
- **How It Was Identified**:
  During test suite execution (`pytest tests/test_security.py`), the test `test_pii_sanitization` failed:
  ```text
  AssertionError: assert '+919876543210' not in 'Contact John Doe... phone +919876543210 card [REDACTED_CARD]'
  ```
  In standard regular expressions, `\b` asserts a word boundary between a word character (`\w`) and a non-word character (`\W`). Because `+` is a non-word character, `\b+91...` fails to match when preceded by whitespace or beginning of text.
- **What Was Corrected**:
  The pattern was rewritten to properly handle optional `+91` prefixes without an erroneous leading `\b`:
  ```python
  PHONE_PATTERN = re.compile(r"(?:\+?91[\-\s]?|[0]?)?[6-9]\d{9}\b")
  ```
  Additionally, the AI initially attempted to use `sqlite:///:memory:` across distinct session fixtures in `conftest.py`, which caused SQLite to recreate empty databases for each connection. This was corrected by using SQLAlchemy's `StaticPool`.

---

### 5. Which Part Was Written Manually & Why
- **Deterministic Refund Policy (`app/policies/refund_policy.py`)**:
  - Directing an LLM to balance business nuances (e.g., distinguishing an initiated webhook lag in T-1005 from a failed UPI attempt in T-1009) required exact domain logic that cannot rely on probabilistic model output.
  - Writing the financial gate deterministically by hand guarantees zero hallucination risk and absolute idempotency.
- **Prompt Injection Defense & PII Redactor**:
  - Handcrafted boundary isolation (`<customer_ticket_content>` tags) and regex tokenization for GSTIN, emails, credit cards, and company names were tuned manually to ensure no false negatives or customer data leaks.

---
*Note: This disclosure document has been filled out completely based on the actual implementation steps and test runs.*
