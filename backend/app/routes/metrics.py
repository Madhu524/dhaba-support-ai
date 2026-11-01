from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session
from sqlalchemy import func
from app.database import get_db
from app.models import Ticket, TriageResult
from app.schemas import MetricsResponse
from app.utils.circuit_breaker import circuit_breaker

router = APIRouter(tags=["Metrics"])

@router.get("/metrics", response_model=MetricsResponse)
def get_system_metrics(db: Session = Depends(get_db)):
    """Provides operational metrics for system observability, reliability, and support dashboard."""
    total_tickets = db.query(Ticket).count()
    total_triaged = db.query(Ticket).filter(Ticket.status == "triaged").count()
    total_approved = db.query(Ticket).filter(Ticket.status == "approved").count()
    total_escalated = db.query(Ticket).filter(Ticket.status == "escalated").count()

    triage_records = db.query(TriageResult).all()
    total_degraded = sum(1 for tr in triage_records if tr.degraded)
    refund_recommended_count = sum(1 for tr in triage_records if tr.should_refund)
    refund_recommended_amount_inr = sum(tr.refund_amount_inr for tr in triage_records if tr.should_refund)

    category_counts = {}
    severity_counts = {}
    confidence_sum = 0.0

    for tr in triage_records:
        category_counts[tr.category] = category_counts.get(tr.category, 0) + 1
        severity_counts[tr.severity] = severity_counts.get(tr.severity, 0) + 1
        confidence_sum += tr.confidence

    avg_confidence = round(confidence_sum / len(triage_records), 4) if triage_records else 1.0

    return MetricsResponse(
        total_tickets=total_tickets,
        total_triaged=total_triaged,
        total_approved=total_approved,
        total_escalated=total_escalated,
        total_degraded=total_degraded,
        refund_recommended_count=refund_recommended_count,
        refund_recommended_amount_inr=refund_recommended_amount_inr,
        category_counts=category_counts,
        severity_counts=severity_counts,
        circuit_breaker_state=circuit_breaker.get_state(),
        average_confidence=avg_confidence
    )
