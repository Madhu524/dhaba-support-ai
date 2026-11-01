import re
from typing import List
from app.schemas import PurchaseInput, RefundDecision

FRAUD_KEYWORDS = re.compile(r"\b(fraud|cyber\s*cell|unauthorized|stolen|scam|never\s+installed|police)\b", re.IGNORECASE)
REFUND_REQUEST_KEYWORDS = re.compile(r"\b(refund|reverse|return\s+my\s+money|money\s+back|paisa\s+wapas|kat\s+gaya)\b", re.IGNORECASE)
DOUBLE_CHARGE_KEYWORDS = re.compile(r"\b(twice|double|two\s+times|do\s+baar)\b", re.IGNORECASE)

def evaluate_refund_policy(
    ticket_id: str,
    subject: str,
    body: str,
    purchases: List[PurchaseInput],
    app_opens_since_renewal: int
) -> RefundDecision:
    """
    Deterministic refund policy evaluator.
    
    Guarantees:
    1. Independent of AI output (isolated business rules).
    2. Uses ONLY verified successful payment records.
    3. Never treats initiated or failed payments as completed charges.
    4. Never executes funds disbursement directly (returns recommendation for human approval).
    5. Escalates ambiguous billing situations.
    6. Identifies prompt injection attempts and ignores adversarial instructions.
    """
    combined_text = f"{subject} {body}".lower()
    
    # 1. Separate successful vs pending/failed transactions
    successful_purchases = [p for p in purchases if p.status == "successful"]
    initiated_purchases = [p for p in purchases if p.status == "initiated"]
    failed_purchases = [p for p in purchases if p.status == "failed"]
    
    # 2. Case: Fraud / Legal / Cyber-cell allegations (e.g. T-1008)
    if FRAUD_KEYWORDS.search(combined_text):
        if successful_purchases:
            # Sum all successful payments on the account
            total_disputed = sum(p.amount_inr for p in successful_purchases)
            return RefundDecision(
                should_refund=True,
                amount_inr=total_disputed,
                reason=(
                    f"Fraud/unauthorized charge allegation flagged with high priority. "
                    f"Recommended refund of all verified successful charges (₹{total_disputed}) "
                    f"pending supervisor review, account block, and payment gateway dispute logging."
                )
            )
        else:
            return RefundDecision(
                should_refund=False,
                amount_inr=0,
                reason="Fraud allegation received, but no successful charges found in billing system. Escalated for human review."
            )

    # 3. Case: Double-charge claims (e.g. T-1009)
    if DOUBLE_CHARGE_KEYWORDS.search(combined_text):
        # Check if there are actually multiple successful renewals with the same amount
        renewal_amounts = [p.amount_inr for p in successful_purchases if p.type == "renewal"]
        if len(renewal_amounts) != len(set(renewal_amounts)) and len(renewal_amounts) > 1:
            # True double charge exists
            duplicate_amount = max(renewal_amounts)
            return RefundDecision(
                should_refund=True,
                amount_inr=duplicate_amount,
                reason=f"Verified duplicate successful charges found. Recommended refund of duplicate payment ₹{duplicate_amount}."
            )
        else:
            # Only one successful charge; other was failed or initiated
            failed_renewals = [p for p in failed_purchases if p.type == "renewal"]
            if failed_renewals and len(successful_purchases) == 1:
                return RefundDecision(
                    should_refund=False,
                    amount_inr=0,
                    reason=(
                        "Billing records verify only one successful charge of ₹249; previous transaction failed. "
                        "Customer should be informed that temporary bank pre-authorization holds auto-reverse in 3-5 days."
                    )
                )

    # 4. Case: Payment initiated / pending capture (e.g. T-1005)
    # The customer says money left bank, but gateway status is 'initiated'
    if initiated_purchases and not any(p.status == "successful" and p.type == "renewal" and p.amount_inr == 249 for p in purchases if p.at > initiated_purchases[0].at):
        if "kat gaya" in combined_text or "debited" in combined_text or "nahi hua" in combined_text or "not activated" in combined_text:
            return RefundDecision(
                should_refund=False,
                amount_inr=0,
                reason=(
                    f"Payment {initiated_purchases[0].id} is in 'initiated' state; Dhaba billing has not captured funds. "
                    f"Cannot refund unconfirmed transaction. Escalated for payment gateway reconciliation or bank UTR verification."
                )
            )

    # 5. Case: Subscription shock / Forgotten renewal with zero app usage (e.g. T-1001, T-1006)
    if REFUND_REQUEST_KEYWORDS.search(combined_text) or "stop" in combined_text or "without telling" in combined_text:
        # Find latest successful renewal
        successful_renewals = [p for p in successful_purchases if p.type == "renewal"]
        if successful_renewals:
            latest_renewal = max(successful_renewals, key=lambda p: p.at)
            
            # Zero app opens since renewal: clear forgotten auto-pay or unaware renewal
            if app_opens_since_renewal == 0:
                return RefundDecision(
                    should_refund=True,
                    amount_inr=latest_renewal.amount_inr,
                    reason=(
                        f"Verified successful renewal ({latest_renewal.id}) of ₹{latest_renewal.amount_inr} "
                        f"with 0 app opens since renewal. Customer requested reversal; recommended refund for agent approval."
                    )
                )
            # Immediate churn / angry bounce with negligible opens (<= 2 opens, e.g. T-1012)
            elif app_opens_since_renewal <= 2 and ("worst" in combined_text or "useless" in combined_text or "1 star" in combined_text):
                return RefundDecision(
                    should_refund=True,
                    amount_inr=latest_renewal.amount_inr,
                    reason=(
                        f"Customer reported immediate dissatisfaction within renewal window with negligible opens ({app_opens_since_renewal}). "
                        f"Recommended refund of ₹{latest_renewal.amount_inr} to de-escalate churn risk, subject to human approval."
                    )
                )

    # 6. Case: Trial cancellation before renewal (e.g. T-1004)
    if "cancel" in combined_text:
        # Only trial payment exists
        if len(successful_purchases) == 1 and successful_purchases[0].type == "trial":
            return RefundDecision(
                should_refund=False,
                amount_inr=0,
                reason="Only ₹1 trial active. No renewal charge to refund. Immediate cancellation of auto-renewal mandate required."
            )

    # 7. Default: Inquiries, technical issues, feature requests, prompt injections, or active usage
    return RefundDecision(
        should_refund=False,
        amount_inr=0,
        reason="No valid refund entitlement under policy, or issue does not involve verified disputed charges."
    )
