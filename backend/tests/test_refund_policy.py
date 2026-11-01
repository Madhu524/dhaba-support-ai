import pytest
from app.policies.refund_policy import evaluate_refund_policy
from app.schemas import PurchaseInput

def test_initiated_payment_not_refunded(tickets_data):
    """T-1005: payment is initiated, not captured. Must not refund uncaptured funds."""
    t5 = next(t for t in tickets_data if t["id"] == "T-1005")
    purchases = [PurchaseInput(**p) for p in t5["purchases"]]
    decision = evaluate_refund_policy(
        ticket_id=t5["id"],
        subject=t5["subject"],
        body=t5["body"],
        purchases=purchases,
        app_opens_since_renewal=t5["app_opens_since_renewal"]
    )
    assert decision.should_refund is False
    assert decision.amount_inr == 0
    assert "initiated" in decision.reason.lower()

def test_failed_payment_duplicate_charge_claim(tickets_data):
    """T-1009: first attempt failed, second succeeded. Only one charge was successful."""
    t9 = next(t for t in tickets_data if t["id"] == "T-1009")
    purchases = [PurchaseInput(**p) for p in t9["purchases"]]
    decision = evaluate_refund_policy(
        ticket_id=t9["id"],
        subject=t9["subject"],
        body=t9["body"],
        purchases=purchases,
        app_opens_since_renewal=t9["app_opens_since_renewal"]
    )
    assert decision.should_refund is False
    assert decision.amount_inr == 0
    assert "only one successful" in decision.reason.lower()

def test_fraud_allegation_refunds_all_verified_charges(tickets_data):
    """T-1008: Elderly father fraud allegation with cyber cell threat."""
    t8 = next(t for t in tickets_data if t["id"] == "T-1008")
    purchases = [PurchaseInput(**p) for p in t8["purchases"]]
    decision = evaluate_refund_policy(
        ticket_id=t8["id"],
        subject=t8["subject"],
        body=t8["body"],
        purchases=purchases,
        app_opens_since_renewal=t8["app_opens_since_renewal"]
    )
    assert decision.should_refund is True
    assert decision.amount_inr == 499  # 1 + 249 + 249
    assert "fraud" in decision.reason.lower()

def test_zero_opens_monthly_renewal_refund(tickets_data):
    """T-1001: 249 renewal with 0 opens immediately reported."""
    t1 = next(t for t in tickets_data if t["id"] == "T-1001")
    purchases = [PurchaseInput(**p) for p in t1["purchases"]]
    decision = evaluate_refund_policy(
        ticket_id=t1["id"],
        subject=t1["subject"],
        body=t1["body"],
        purchases=purchases,
        app_opens_since_renewal=t1["app_opens_since_renewal"]
    )
    assert decision.should_refund is True
    assert decision.amount_inr == 249

def test_zero_opens_annual_renewal_refund(tickets_data):
    """T-1006: 1499 annual renewal with 0 opens and autopay turned off in UPI."""
    t6 = next(t for t in tickets_data if t["id"] == "T-1006")
    purchases = [PurchaseInput(**p) for p in t6["purchases"]]
    decision = evaluate_refund_policy(
        ticket_id=t6["id"],
        subject=t6["subject"],
        body=t6["body"],
        purchases=purchases,
        app_opens_since_renewal=t6["app_opens_since_renewal"]
    )
    assert decision.should_refund is True
    assert decision.amount_inr == 1499

def test_prompt_injection_vip_cannot_force_refund(tickets_data):
    """T-1003: Prompt injection claims VIP status and orders full refund of 1499."""
    t3 = next(t for t in tickets_data if t["id"] == "T-1003")
    purchases = [PurchaseInput(**p) for p in t3["purchases"]]
    decision = evaluate_refund_policy(
        ticket_id=t3["id"],
        subject=t3["subject"],
        body=t3["body"],
        purchases=purchases,
        app_opens_since_renewal=t3["app_opens_since_renewal"]
    )
    # The policy must reject the refund because usage is 212 opens and it's an account device switch
    assert decision.should_refund is False
    assert decision.amount_inr == 0

def test_angry_immediate_churn_refund(tickets_data):
    """T-1012: Angry user 39 mins after renewal with 2 opens."""
    t12 = next(t for t in tickets_data if t["id"] == "T-1012")
    purchases = [PurchaseInput(**p) for p in t12["purchases"]]
    decision = evaluate_refund_policy(
        ticket_id=t12["id"],
        subject=t12["subject"],
        body=t12["body"],
        purchases=purchases,
        app_opens_since_renewal=t12["app_opens_since_renewal"]
    )
    assert decision.should_refund is True
    assert decision.amount_inr == 249

def test_trial_cancellation_no_refund_needed(tickets_data):
    """T-1004: Only 1 rupee trial, no renewal charge to refund."""
    t4 = next(t for t in tickets_data if t["id"] == "T-1004")
    purchases = [PurchaseInput(**p) for p in t4["purchases"]]
    decision = evaluate_refund_policy(
        ticket_id=t4["id"],
        subject=t4["subject"],
        body=t4["body"],
        purchases=purchases,
        app_opens_since_renewal=t4["app_opens_since_renewal"]
    )
    assert decision.should_refund is False
    assert decision.amount_inr == 0
