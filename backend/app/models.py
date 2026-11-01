from datetime import datetime, timezone
from sqlalchemy import Column, String, Integer, Float, Boolean, Text, DateTime, ForeignKey, UniqueConstraint
from sqlalchemy.orm import relationship
from app.database import Base

def utc_now():
    return datetime.now(timezone.utc)

class Ticket(Base):
    __tablename__ = "tickets"

    id = Column(String, primary_key=True, index=True)
    received_at = Column(DateTime, nullable=False, default=utc_now)
    subject = Column(String(255), nullable=False)
    body = Column(Text, nullable=False)
    app_opens_since_renewal = Column(Integer, default=0, nullable=False)
    status = Column(String(50), default="new", nullable=False)  # new, triaged, approved, edited, escalated, closed
    created_at = Column(DateTime, default=utc_now, nullable=False)
    updated_at = Column(DateTime, default=utc_now, onupdate=utc_now, nullable=False)

    purchases = relationship("Purchase", back_populates="ticket", cascade="all, delete-orphan")
    triage_result = relationship("TriageResult", back_populates="ticket", uselist=False, cascade="all, delete-orphan")
    audit_events = relationship("AuditEvent", back_populates="ticket", cascade="all, delete-orphan")

class Purchase(Base):
    __tablename__ = "purchases"

    id = Column(String, primary_key=True, index=True)
    ticket_id = Column(String, ForeignKey("tickets.id", ondelete="CASCADE"), nullable=False, index=True)
    type = Column(String(50), nullable=False)  # trial, renewal
    amount_inr = Column(Integer, nullable=False)
    status = Column(String(50), nullable=False)  # initiated, failed, successful
    purchased_at = Column(DateTime, nullable=True)

    ticket = relationship("Ticket", back_populates="purchases")

class TriageResult(Base):
    __tablename__ = "triage_results"

    id = Column(Integer, primary_key=True, index=True, autoincrement=True)
    ticket_id = Column(String, ForeignKey("tickets.id", ondelete="CASCADE"), nullable=False, unique=True, index=True)
    category = Column(String(50), nullable=False)
    severity = Column(String(50), nullable=False)
    confidence = Column(Float, nullable=False)
    should_refund = Column(Boolean, default=False, nullable=False)
    refund_amount_inr = Column(Integer, default=0, nullable=False)
    refund_reason = Column(Text, nullable=False)
    reply_draft = Column(Text, nullable=False)
    needs_human = Column(Boolean, default=False, nullable=False)
    degraded = Column(Boolean, default=False, nullable=False)
    created_at = Column(DateTime, default=utc_now, nullable=False)

    ticket = relationship("Ticket", back_populates="triage_result")

class AuditEvent(Base):
    __tablename__ = "audit_events"

    id = Column(Integer, primary_key=True, index=True, autoincrement=True)
    ticket_id = Column(String, ForeignKey("tickets.id", ondelete="CASCADE"), nullable=False, index=True)
    event_type = Column(String(100), nullable=False)
    actor = Column(String(100), default="system", nullable=False)
    metadata_json = Column(Text, nullable=True)
    created_at = Column(DateTime, default=utc_now, nullable=False)

    ticket = relationship("Ticket", back_populates="audit_events")
