import React from 'react';

export default function RefundBadge({ shouldRefund, amountInr }) {
  if (shouldRefund) {
    return (
      <span className="badge badge-refund-rec" title="Deterministic policy recommendation - pending human review">
        Refund Rec: ₹{amountInr} (Pending Review)
      </span>
    );
  }
  return null;
}
