from enum import Enum
from typing import List, Optional, Any, Dict
from pydantic import BaseModel, Field, field_validator, ConfigDict
from datetime import datetime

class Category(str, Enum):
    BILLING = "billing"
    TECHNICAL_ISSUE = "technical_issue"
    CANCELLATION = "cancellation"
    ACCOUNT_MANAGEMENT = "account_management"
    FEATURE_REQUEST = "feature_request"
    COMPLAINT = "complaint"
    INQUIRY = "inquiry"
    OTHER = "other"

class Severity(str, Enum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    URGENT = "urgent"

class TicketStatus(str, Enum):
    NEW = "new"
    TRIAGED = "triaged"
    APPROVED = "approved"
    EDITED = "edited"
    ESCALATED = "escalated"
    CLOSED = "closed"

class PurchaseType(str, Enum):
    TRIAL = "trial"
    RENEWAL = "renewal"

class PurchaseStatus(str, Enum):
    INITIATED = "initiated"
    FAILED = "failed"
    SUCCESSFUL = "successful"

class PurchaseInput(BaseModel):
    id: str = Field(..., description="Unique payment identifier")
    type: str = Field(..., description="trial or renewal")
    amount_inr: int = Field(..., ge=0, description="Payment amount in INR")
    status: str = Field(..., description="initiated, failed, or successful")
    at: str = Field(..., description="ISO 8601 timestamp string")

    @field_validator("status")
    @classmethod
    def validate_status(cls, v: str) -> str:
        clean = v.strip().lower()
        if clean not in ["initiated", "failed", "successful"]:
            raise ValueError(f"Invalid purchase status: {v}. Must be initiated, failed, or successful.")
        return clean

class TicketInput(BaseModel):
    id: str = Field(..., min_length=1, description="Ticket ID e.g. T-1001")
    received_at: str = Field(..., description="Timestamp ticket was received")
    subject: str = Field(..., min_length=1, description="Ticket subject line")
    body: str = Field(..., min_length=1, description="Customer message body")
    purchases: List[PurchaseInput] = Field(default_factory=list, description="Customer purchases list")
    app_opens_since_renewal: int = Field(0, ge=0, description="App open count since last renewal")

class RefundDecision(BaseModel):
    should_refund: bool = Field(..., description="Whether refund is recommended")
    amount_inr: int = Field(..., ge=0, description="Recommended refund amount in INR")
    reason: str = Field(..., description="Explanation for refund recommendation")

class TriageResponse(BaseModel):
    ticket_id: str
    category: Category
    severity: Severity
    refund: RefundDecision
    reply_draft: str
    needs_human: bool
    confidence: float = Field(..., ge=0.0, le=1.0)
    degraded: bool = False

class EditReplyRequest(BaseModel):
    reply_draft: str = Field(..., min_length=1)
    actor: Optional[str] = "support_agent"

class EscalateRequest(BaseModel):
    reason: str = Field(..., min_length=1)
    actor: Optional[str] = "support_agent"

class ApproveRequest(BaseModel):
    actor: Optional[str] = "support_agent"

class PurchaseResponse(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: str
    ticket_id: str
    type: str
    amount_inr: int
    status: str
    purchased_at: Optional[datetime] = None

class AuditEventResponse(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    ticket_id: str
    event_type: str
    actor: str
    metadata_json: Optional[str] = None
    created_at: datetime

class TicketSummaryResponse(BaseModel):
    id: str
    received_at: datetime
    subject: str
    app_opens_since_renewal: int
    status: str
    category: Optional[str] = None
    severity: Optional[str] = None
    confidence: Optional[float] = None
    should_refund: Optional[bool] = None
    refund_amount_inr: Optional[int] = None
    needs_human: Optional[bool] = None
    degraded: Optional[bool] = None
    created_at: datetime

class TicketDetailResponse(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: str
    received_at: datetime
    subject: str
    body: str
    app_opens_since_renewal: int
    status: str
    created_at: datetime
    updated_at: datetime
    purchases: List[PurchaseResponse] = []
    triage: Optional[TriageResponse] = None
    audit_events: List[AuditEventResponse] = []

class MetricsResponse(BaseModel):
    total_tickets: int
    total_triaged: int
    total_approved: int
    total_escalated: int
    total_degraded: int
    refund_recommended_count: int
    refund_recommended_amount_inr: int
    category_counts: Dict[str, int]
    severity_counts: Dict[str, int]
    circuit_breaker_state: str
    average_confidence: float
