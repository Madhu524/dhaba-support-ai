import json
import asyncio
from pathlib import Path
from app.database import SessionLocal, init_db
from app.models import Ticket, Purchase, TriageResult
from app.schemas import TicketInput, Category, Severity
from app.services.ai_service import ai_service
from app.policies.refund_policy import evaluate_refund_policy
from app.services.audit_service import audit_service
from datetime import datetime, timezone

async def process_all_tickets():
    init_db()
    db = SessionLocal()
    
    data_file = Path(__file__).resolve().parent.parent / "data" / "tickets.json"
    with open(data_file, "r", encoding="utf-8") as f:
        data = json.load(f)

    tickets = data["tickets"]
    print(f"Processing {len(tickets)} reference tickets into database...")

    for t_data in tickets:
        ticket_id = t_data["id"]
        existing = db.query(Ticket).filter(Ticket.id == ticket_id).first()
        if existing:
            print(f"[-] Ticket {ticket_id} already exists. Skipping.")
            continue

        try:
            received_dt = datetime.fromisoformat(t_data["received_at"].replace("Z", "+00:00"))
        except Exception:
            received_dt = datetime.now(timezone.utc)

        ticket_obj = Ticket(
            id=ticket_id,
            received_at=received_dt,
            subject=t_data["subject"],
            body=t_data["body"],
            app_opens_since_renewal=t_data.get("app_opens_since_renewal", 0),
            status="triaged"
        )
        db.add(ticket_obj)

        for p_data in t_data.get("purchases", []):
            try:
                p_dt = datetime.fromisoformat(p_data["at"].replace("Z", "+00:00"))
            except Exception:
                p_dt = datetime.now(timezone.utc)
            purchase_obj = Purchase(
                id=p_data["id"],
                ticket_id=ticket_id,
                type=p_data["type"],
                amount_inr=p_data["amount_inr"],
                status=p_data["status"],
                purchased_at=p_dt
            )
            db.add(purchase_obj)

        db.flush()

        ticket_in = TicketInput(**t_data)
        ai_out, degraded = await ai_service.triage_ticket(ticket_in)
        refund_out = evaluate_refund_policy(
            ticket_id=ticket_in.id,
            subject=ticket_in.subject,
            body=ticket_in.body,
            purchases=ticket_in.purchases,
            app_opens_since_renewal=ticket_in.app_opens_since_renewal
        )

        needs_human = (
            ai_out.needs_human or
            refund_out.should_refund or
            ai_out.confidence < 0.70 or
            ai_out.severity == Severity.URGENT or
            degraded
        )

        tr = TriageResult(
            ticket_id=ticket_id,
            category=ai_out.category.value,
            severity=ai_out.severity.value,
            confidence=ai_out.confidence,
            should_refund=refund_out.should_refund,
            refund_amount_inr=refund_out.amount_inr,
            refund_reason=refund_out.reason,
            reply_draft=ai_out.reply_draft,
            needs_human=needs_human,
            degraded=degraded
        )
        db.add(tr)

        audit_service.log_event(
            db=db,
            ticket_id=ticket_id,
            event_type="triage_created",
            actor="system",
            metadata={
                "category": ai_out.category.value,
                "severity": ai_out.severity.value,
                "refund_recommended": refund_out.should_refund,
                "refund_amount_inr": refund_out.amount_inr
            }
        )
        db.commit()
        print(f"[+] Processed {ticket_id}: Category={ai_out.category.value}, Severity={ai_out.severity.value}, Refund={refund_out.should_refund} (₹{refund_out.amount_inr})")

    db.close()
    print("All tickets successfully loaded!")

if __name__ == "__main__":
    asyncio.run(process_all_tickets())
