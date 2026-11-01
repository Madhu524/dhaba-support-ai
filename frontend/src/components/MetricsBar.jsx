import React from 'react';

export default function MetricsBar({ metrics }) {
  if (!metrics) return null;

  const isHealthy = metrics.circuit_breaker_state === 'CLOSED';

  return (
    <div className="metrics-bar">
      <div className="metric-card">
        <span className="metric-label">Total Tickets</span>
        <span className="metric-value">{metrics.total_tickets}</span>
      </div>
      <div className="metric-card">
        <span className="metric-label">Triaged</span>
        <span className="metric-value">{metrics.total_triaged}</span>
      </div>
      <div className="metric-card">
        <span className="metric-label">Approved</span>
        <span className="metric-value success">{metrics.total_approved}</span>
      </div>
      <div className="metric-card">
        <span className="metric-label">Escalated</span>
        <span className="metric-value" style={{ color: metrics.total_escalated > 0 ? '#f87171' : 'inherit' }}>
          {metrics.total_escalated}
        </span>
      </div>
      <div className="metric-card">
        <span className="metric-label">Refunds Under Review</span>
        <span className="metric-value highlight">
          {metrics.refund_recommended_count} (₹{metrics.refund_recommended_amount_inr})
        </span>
      </div>
      <div className="metric-card">
        <span className="metric-label">Circuit Breaker</span>
        <span className={`metric-value ${isHealthy ? 'pulse-green' : ''}`} style={{ color: isHealthy ? '#10b981' : '#f59e0b' }}>
          {isHealthy && <span className="pulse-dot"></span>}
          {metrics.circuit_breaker_state}
        </span>
      </div>
      <div className="metric-card">
        <span className="metric-label">Avg Confidence</span>
        <span className="metric-value">
          {Math.round(metrics.average_confidence * 100)}%
        </span>
      </div>
    </div>
  );
}
