import pytest

def test_health_check(client):
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json()["status"] == "healthy"

def test_triage_valid_ticket(client, tickets_data):
    ticket_1 = tickets_data[0]  # T-1001
    response = client.post("/triage", json=ticket_1)
    assert response.status_code == 200
    data = response.json()
    assert data["ticket_id"] == "T-1001"
    assert data["category"] == "billing"
    assert data["severity"] == "high"
    assert data["refund"]["should_refund"] is True
    assert data["refund"]["amount_inr"] == 249
    assert data["needs_human"] is True
    assert data["confidence"] >= 0.90
    assert data["degraded"] is False

def test_triage_missing_ticket_id(client):
    invalid_ticket = {
        "received_at": "2026-09-02T09:14:00+05:30",
        "subject": "no id ticket",
        "body": "missing ticket id",
        "purchases": [],
        "app_opens_since_renewal": 0
    }
    response = client.post("/triage", json=invalid_ticket)
    assert response.status_code == 422  # Unprocessable Entity

def test_triage_invalid_purchase_status(client):
    ticket = {
        "id": "T-INVALID-STATUS",
        "received_at": "2026-09-02T09:14:00+05:30",
        "subject": "bad purchase",
        "body": "test body",
        "purchases": [
            {"id": "p1", "type": "trial", "amount_inr": 1, "status": "unknown_status", "at": "2026-08-31T20:02:00+05:30"}
        ],
        "app_opens_since_renewal": 0
    }
    response = client.post("/triage", json=ticket)
    assert response.status_code == 422

def test_triage_idempotency_same_ticket_twice(client, tickets_data):
    ticket_1 = tickets_data[0]
    # First post
    res1 = client.post("/triage", json=ticket_1)
    assert res1.status_code == 200
    data1 = res1.json()

    # Second post of the exact same ticket
    res2 = client.post("/triage", json=ticket_1)
    assert res2.status_code == 200
    data2 = res2.json()

    assert data1["ticket_id"] == data2["ticket_id"]
    assert data1["refund"]["should_refund"] == data2["refund"]["should_refund"]
    assert data1["refund"]["amount_inr"] == data2["refund"]["amount_inr"]

    # Verify only one ticket exists in DB
    list_res = client.get("/tickets")
    assert list_res.status_code == 200
    assert len(list_res.json()) == 1

def test_ticket_lifecycle_approve_edit_escalate_replay(client, tickets_data):
    ticket_4 = tickets_data[3]  # T-1004
    client.post("/triage", json=ticket_4)

    # 1. Get detail
    detail_res = client.get("/tickets/T-1004")
    assert detail_res.status_code == 200
    detail = detail_res.json()
    assert detail["id"] == "T-1004"
    assert detail["status"] == "triaged"

    # 2. Edit reply
    new_reply = "Updated cancellation instructions: Please visit Account > Subscriptions."
    edit_res = client.post("/tickets/T-1004/edit-reply", json={"reply_draft": new_reply})
    assert edit_res.status_code == 200
    assert edit_res.json()["status"] == "edited"

    # Verify updated draft
    detail_res2 = client.get("/tickets/T-1004")
    assert detail_res2.json()["triage"]["reply_draft"] == new_reply

    # 3. Approve reply
    approve_res = client.post("/tickets/T-1004/approve", json={"actor": "lead_agent"})
    assert approve_res.status_code == 200
    assert approve_res.json()["status"] == "approved"

    # 4. Escalate ticket
    esc_res = client.post("/tickets/T-1004/escalate", json={"reason": "Customer needs custom escalation"})
    assert esc_res.status_code == 200
    assert esc_res.json()["status"] == "escalated"

    # 5. Replay ticket
    replay_res = client.post("/tickets/T-1004/replay")
    assert replay_res.status_code == 200
    assert replay_res.json()["ticket_id"] == "T-1004"

def test_all_twelve_tickets_batch_integration(client, tickets_data):
    """Processes all 12 tickets through POST /triage and verifies valid responses."""
    assert len(tickets_data) == 12
    for t in tickets_data:
        res = client.post("/triage", json=t)
        assert res.status_code == 200
        data = res.json()
        assert data["ticket_id"] == t["id"]
        assert "category" in data
        assert "severity" in data
        assert "refund" in data
        assert "should_refund" in data["refund"]
        assert "amount_inr" in data["refund"]
        assert "reply_draft" in data
        assert "confidence" in data
        assert isinstance(data["confidence"], float)

    # Check metrics endpoint
    metrics_res = client.get("/metrics")
    assert metrics_res.status_code == 200
    m = metrics_res.json()
    assert m["total_tickets"] == 12
    assert m["total_triaged"] == 12
    assert m["refund_recommended_count"] >= 3
