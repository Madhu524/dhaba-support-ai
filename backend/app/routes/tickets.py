from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy.orm import Session
from typing import List, Optional
from datetime import datetime, timezone

from app.database import get_db
from app.models import Ticket, Purchase, TriageResult, AuditEvent
from app.schemas import (
    TicketSummaryResponse, TicketDetailResponse, TriageResponse,
    EditReplyRequest, EscalateRequest, ApproveRequest,
    Category, Severity, RefundDecision, TicketInput, PurchaseInput
)
from app.services.ai_service import ai_service
from app.services.audit_service import audit_service
from app.policies.refund_policy import evaluate_refund_policy
from app.utils.logger import logger

router = APIRouter(tags=["Tickets"])

@router.get("/health")
def health_check():
    """Service health check endpoint."""
    return {
        "status": "healthy",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "service": "dhaba-support-ai"
    }

@router.get("/tickets", response_model=List[TicketSummaryResponse])
def list_tickets(
    status: Optional[str] = Query(None, description="Filter by status: new, triaged, approved, edited, escalated, closed"),
    category: Optional[str] = Query(None, description="Filter by category"),
    severity: Optional[str] = Query(None, description="Filter by severity"),
    search: Optional[str] = Query(None, description="Search subject or ID"),
    skip: int = Query(0, ge=0),
    limit: int = Query(50, ge=1, le=100),
    db: Session = Depends(get_db)
):
    """Lists tickets with optional filters and pagination."""
    query = db.query(Ticket).outerjoin(TriageResult)

    if status:
        query = query.filter(Ticket.status == status.lower())
    if category:
        query = query.filter(TriageResult.category == category.lower())
    if severity:
        query = query.filter(TriageResult.severity == severity.lower())
    if search:
        search_filter = f"%{search}%"
        query = query.filter(Ticket.subject.ilike(search_filter) | Ticket.id.ilike(search_filter))

    tickets = query.order_by(Ticket.received_at.desc()).offset(skip).limit(limit).all()

    results = []
    for t in tickets:
        tr = t.triage_result
        results.append(TicketSummaryResponse(
            id=t.id,
            received_at=t.received_at,
            subject=t.subject,
            app_opens_since_renewal=t.app_opens_since_renewal,
            status=t.status,
            category=tr.category if tr else None,
            severity=tr.severity if tr else None,
            confidence=tr.confidence if tr else None,
            should_refund=tr.should_refund if tr else None,
            refund_amount_inr=tr.refund_amount_inr if tr else None,
            needs_human=tr.needs_human if tr else None,
            degraded=tr.degraded if tr else None,
            created_at=t.created_at
        ))
    return results

@router.get("/tickets/{ticket_id}", response_model=TicketDetailResponse)
def get_ticket_detail(ticket_id: str, db: Session = Depends(get_db)):
    """Retrieves full details of a specific ticket including purchase history, triage, and audit events."""
    ticket = db.query(Ticket).filter(Ticket.id == ticket_id).first()
    if not ticket:
        raise HTTPException(status_code=404, detail=f"Ticket {ticket_id} not found")
    
    triage_dto = None
    if ticket.triage_result:
        tr = ticket.triage_result
        triage_dto = TriageResponse(
            ticket_id=ticket.id,
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

    return TicketDetailResponse(
        id=ticket.id,
        received_at=ticket.received_at,
        subject=ticket.subject,
        body=ticket.body,
        app_opens_since_renewal=ticket.app_opens_since_renewal,
        status=ticket.status,
        created_at=ticket.created_at,
        updated_at=ticket.updated_at,
        purchases=ticket.purchases,
        triage=triage_dto,
        audit_events=ticket.audit_events
    )

@router.get("/tickets/{ticket_id}/triage", response_model=TriageResponse)
def get_ticket_triage(ticket_id: str, db: Session = Depends(get_db)):
    """Retrieves just the triage result for a ticket."""
    ticket = db.query(Ticket).filter(Ticket.id == ticket_id).first()
    if not ticket or not ticket.triage_result:
        raise HTTPException(status_code=404, detail=f"Triage result for {ticket_id} not found")
    
    tr = ticket.triage_result
    return TriageResponse(
        ticket_id=ticket.id,
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

@router.post("/tickets/{ticket_id}/approve")
def approve_ticket_reply(ticket_id: str, payload: ApproveRequest = ApproveRequest(), db: Session = Depends(get_db)):
    """Human support agent approves the drafted reply for transmission."""
    ticket = db.query(Ticket).filter(Ticket.id == ticket_id).first()
    if not ticket:
        raise HTTPException(status_code=404, detail=f"Ticket {ticket_id} not found")
    
    ticket.status = "approved"
    if ticket.triage_result:
        ticket.triage_result.needs_human = False
    
    audit_service.log_event(
        db=db,
        ticket_id=ticket.id,
        event_type="reply_approved",
        actor=payload.actor or "support_agent",
        metadata={"previous_status": "triaged", "new_status": "approved"}
    )
    db.commit()
    return {"message": f"Ticket {ticket_id} draft approved successfully", "status": ticket.status}

@router.post("/tickets/{ticket_id}/edit-reply")
def edit_ticket_reply(ticket_id: str, payload: EditReplyRequest, db: Session = Depends(get_db)):
    """Human support agent edits the reply draft before sending."""
    ticket = db.query(Ticket).filter(Ticket.id == ticket_id).first()
    if not ticket or not ticket.triage_result:
        raise HTTPException(status_code=404, detail=f"Ticket {ticket_id} or triage not found")
    
    previous_draft_length = len(ticket.triage_result.reply_draft)
    ticket.triage_result.reply_draft = payload.reply_draft
    ticket.status = "edited"
    
    audit_service.log_event(
        db=db,
        ticket_id=ticket.id,
        event_type="reply_edited",
        actor=payload.actor or "support_agent",
        metadata={
            "previous_draft_length": previous_draft_length,
            "new_draft_length": len(payload.reply_draft)
        }
    )
    db.commit()
    return {"message": f"Draft updated for ticket {ticket_id}", "status": ticket.status}

@router.post("/tickets/{ticket_id}/escalate")
def escalate_ticket(ticket_id: str, payload: EscalateRequest, db: Session = Depends(get_db)):
    """Escalates a ticket for manual supervisor/tier-2 intervention."""
    ticket = db.query(Ticket).filter(Ticket.id == ticket_id).first()
    if not ticket:
        raise HTTPException(status_code=404, detail=f"Ticket {ticket_id} not found")
    
    ticket.status = "escalated"
    if ticket.triage_result:
        ticket.triage_result.needs_human = True
        ticket.triage_result.severity = Severity.URGENT.value
    
    audit_service.log_event(
        db=db,
        ticket_id=ticket.id,
        event_type="ticket_escalated",
        actor=payload.actor or "support_agent",
        metadata={"escalation_reason": payload.reason}
    )
    db.commit()
    return {"message": f"Ticket {ticket_id} escalated", "status": ticket.status}

@router.post("/tickets/{ticket_id}/replay", response_model=TriageResponse)
async def replay_ticket(ticket_id: str, db: Session = Depends(get_db)):
    """Forces re-evaluation of triage & refund rules on an existing ticket."""
    ticket = db.query(Ticket).filter(Ticket.id == ticket_id).first()
    if not ticket:
        raise HTTPException(status_code=404, detail=f"Ticket {ticket_id} not found")
    
    # Construct TicketInput from DB
    purchases_input = [
        PurchaseInput(
            id=p.id,
            type=p.type,
            amount_inr=p.amount_inr,
            status=p.status,
            at=p.purchased_at.isoformat() if p.purchased_at else datetime.now(timezone.utc).isoformat()
        )
        for p in ticket.purchases
    ]
    ticket_in = TicketInput(
        id=ticket.id,
        received_at=ticket.received_at.isoformat(),
        subject=ticket.subject,
        body=ticket.body,
        purchases=purchases_input,
        app_opens_since_renewal=ticket.app_opens_since_renewal
    )

    ai_output, degraded = await ai_service.triage_ticket(ticket_in)
    refund_decision = evaluate_refund_policy(
        ticket_id=ticket_in.id,
        subject=ticket_in.subject,
        body=ticket_in.body,
        purchases=ticket_in.purchases,
        app_opens_since_renewal=ticket_in.app_opens_since_renewal
    )

    needs_human = (
        ai_output.needs_human or
        refund_decision.should_refund or
        ai_output.confidence < 0.70 or
        ai_output.severity == Severity.URGENT or
        degraded
    )

    # Update triage record
    tr = ticket.triage_result
    if tr:
        tr.category = ai_output.category.value
        tr.severity = ai_output.severity.value
        tr.confidence = ai_output.confidence
        tr.should_refund = refund_decision.should_refund
        tr.refund_amount_inr = refund_decision.amount_inr
        tr.refund_reason = refund_decision.reason
        tr.reply_draft = ai_output.reply_draft
        tr.needs_human = needs_human
        tr.degraded = degraded
    else:
        tr = TriageResult(
            ticket_id=ticket.id,
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
        db.add(tr)

    audit_service.log_event(
        db=db,
        ticket_id=ticket.id,
        event_type="ticket_replayed",
        actor="system",
        metadata={"category": tr.category, "severity": tr.severity}
    )
    db.commit()

    return TriageResponse(
        ticket_id=ticket.id,
        category=ai_output.category,
        severity=ai_output.severity,
        refund=refund_decision,
        reply_draft=ai_output.reply_draft,
        needs_human=needs_human,
        confidence=ai_output.confidence,
        degraded=degraded
    )
