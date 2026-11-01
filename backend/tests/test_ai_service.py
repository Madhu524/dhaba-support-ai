import pytest
from app.schemas import TicketInput, Category, Severity
from app.services.ai_service import AIService, DeterministicFallbackService, FIXTURE_TRIAGE_MAP
from app.utils.circuit_breaker import circuit_breaker

@pytest.mark.asyncio
async def test_fixture_mode_known_tickets(tickets_data):
    service = AIService()
    for t in tickets_data:
        ticket_in = TicketInput(**t)
        output, degraded = await service.triage_ticket(ticket_in)
        assert degraded is False
        assert output.confidence >= 0.70
        assert output.category is not None
        assert output.severity is not None
        assert len(output.reply_draft) > 10

def test_deterministic_fallback_crashes():
    ticket = TicketInput(
        id="T-FALLBACK-1",
        received_at="2026-09-02T10:00:00+05:30",
        subject="app crash bug",
        body="it closes when opened",
        purchases=[],
        app_opens_since_renewal=10
    )
    fallback = DeterministicFallbackService.triage(ticket)
    assert fallback.category == Category.TECHNICAL_ISSUE
    assert fallback.severity == Severity.HIGH
    assert fallback.needs_human is True
    assert fallback.confidence == 0.60

def test_deterministic_fallback_inquiry():
    ticket = TicketInput(
        id="T-FALLBACK-2",
        received_at="2026-09-02T10:00:00+05:30",
        subject="hello",
        body="what are your restaurant hours?",
        purchases=[],
        app_opens_since_renewal=0
    )
    fallback = DeterministicFallbackService.triage(ticket)
    assert fallback.category == Category.INQUIRY
    assert fallback.severity == Severity.LOW

@pytest.mark.asyncio
async def test_degradation_when_circuit_breaker_open(tickets_data):
    circuit_breaker.trip()
    try:
        service = AIService()
        t1 = tickets_data[0]
        ticket_in = TicketInput(**t1)
        output, degraded = await service.triage_ticket(ticket_in)
        assert degraded is True
        assert output.needs_human is True
    finally:
        circuit_breaker.reset()
