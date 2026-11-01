from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session
from datetime import datetime
import time

from app.database import get_db
from app.models import Ticket, Purchase, TriageResult
from app.schemas import TicketInput, TriageResponse, RefundDecision, Category, Severity
from app.services.ai_service import ai_service
from app.services.audit_service import audit_service
from app.policies.refund_policy import evaluate_refund_policy
from app.utils.logger import logger

router = APIRouter(tags=["Triage"])

@router.post("/triage", response_model=TriageResponse)
async def triage_ticket_endpoint(ticket_in: TicketInput, db: Session = Depends(get_db)):
    """
    Main triage endpoint.
    Accepts customer support ticket with purchase history and app telemetry.
    Returns categorized intent, severity, deterministic refund recommendation, draft reply, and confidence.
    Guarantees idempotency: running twice safely returns the recorded state without duplicating actions.
    """
    start_time = time.time()
    
    # Check for existing ticket to guarantee idempotency
    existing_ticket = db.query(Ticket).filter(Ticket.id == ticket_in.id).first()
    if existing_ticket and existing_ticket.triage_result:
        tr = existing_ticket.triage_result
        logger.info(
            f"Idempotent hit for ticket {ticket_in.id}. Returning existing triage result.",
            extra={"ticket_id": ticket_in.id, "event": "triage_idempotent_hit"}
        )
        return TriageResponse(
            ticket_id=existing_ticket.id,
            category=Category(tr.category),
            severity=Severity(tr.severity),
            refund=RefundDecision(
                should_refund=tr.should_refund,
                amount_inr=tr.refund_amount_inr,
                reason=tr.refund_reason
            ),
            reply_draft=tr.reply_draft,
            needs_human=tr.needs_human,
            confidence=tr.confidence,
            degraded=tr.degraded
        )

    # Parse received_at safely
    try:
        received_dt = datetime.fromisoformat(ticket_in.received_at.replace("Z", "+00:00"))
    except Exception:
        received_dt = datetime.utcnow()

    # Create ticket record if not exists
    if not existing_ticket:
        ticket_record = Ticket(
            id=ticket_in.id,
            received_at=received_dt,
            subject=ticket_in.subject,
            body=ticket_in.body,
            app_opens_since_renewal=ticket_in.app_opens_since_renewal,
            status="triaged"
        )
        db.add(ticket_record)
        
        # Add purchases
        for p in ticket_in.purchases:
            try:
                p_dt = datetime.fromisoformat(p.at.replace("Z", "+00:00"))
            except Exception:
                p_dt = datetime.utcnow()
            purchase_record = Purchase(
                id=p.id,
                ticket_id=ticket_in.id,
                type=p.type,
                amount_inr=p.amount_inr,
                status=p.status,
                purchased_at=p_dt
            )
            db.add(purchase_record)
        db.flush()
    else:
        ticket_record = existing_ticket
        ticket_record.status = "triaged"

    # Step 1: AI Service analyzes customer intent, category, severity, draft reply
    ai_output, degraded = await ai_service.triage_ticket(ticket_in)

    # Step 2: Deterministic Refund Policy (Independent from AI)
    refund_decision = evaluate_refund_policy(
        ticket_id=ticket_in.id,
        subject=ticket_in.subject,
        body=ticket_in.body,
        purchases=ticket_in.purchases,
        app_opens_since_renewal=ticket_in.app_opens_since_renewal
    )

    # Step 3: Human Escalation Rules
    # - If refund is recommended, human review is MANDATORY (AI cannot dispense money)
    # - If AI confidence is low (< 0.70)
    # - If severity is URGENT (e.g. fraud)
    needs_human = (
        ai_output.needs_human or
        refund_decision.should_refund or
        ai_output.confidence < 0.70 or
        ai_output.severity == Severity.URGENT or
        degraded
    )

    # Step 4: Persist Triage Result
    triage_record = TriageResult(
        ticket_id=ticket_record.id,
        category=ai_output.category.value,
        severity=ai_output.severity.value,
        confidence=ai_output.confidence,
        should_refund=refund_decision.should_refund,
        refund_amount_inr=refund_decision.amount_inr,
        refund_reason=refund_decision.reason,
        reply_draft=ai_output.reply_draft,
        needs_human=needs_human,
        degraded=degraded
    )
    db.add(triage_record)
    
    # Step 5: Audit Event
    audit_service.log_event(
        db=db,
        ticket_id=ticket_record.id,
        event_type="triage_created",
        actor="system",
        metadata={
            "category": ai_output.category.value,
            "severity": ai_output.severity.value,
            "confidence": ai_output.confidence,
            "should_refund": refund_decision.should_refund,
            "refund_amount_inr": refund_decision.amount_inr,
            "degraded": degraded
        }
    )
    db.commit()

    latency_ms = int((time.time() - start_time) * 1000)
    logger.info(
        f"Ticket {ticket_in.id} triaged successfully.",
        extra={
            "ticket_id": ticket_in.id,
            "category": ai_output.category.value,
            "severity": ai_output.severity.value,
            "latency_ms": latency_ms,
            "event": "ticket_triaged"
        }
    )

    return TriageResponse(
        ticket_id=ticket_record.id,
        category=ai_output.category,
        severity=ai_output.severity,
        refund=refund_decision,
        reply_draft=ai_output.reply_draft,
        needs_human=needs_human,
        confidence=ai_output.confidence,
        degraded=degraded
    )
