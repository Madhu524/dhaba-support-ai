import json
from typing import Optional, Dict, Any
from sqlalchemy.orm import Session
from app.models import AuditEvent

class AuditService:
    @staticmethod
    def log_event(
        db: Session,
        ticket_id: str,
        event_type: str,
        actor: str = "system",
        metadata: Optional[Dict[str, Any]] = None
    ) -> AuditEvent:
        """Records an immutable audit event for compliance, tracing, and 3am debugging."""
        metadata_str = json.dumps(metadata) if metadata else None
        event = AuditEvent(
            ticket_id=ticket_id,
            event_type=event_type,
            actor=actor,
            metadata_json=metadata_str
        )
        db.add(event)
        db.commit()
        db.refresh(event)
        return event

audit_service = AuditService()
