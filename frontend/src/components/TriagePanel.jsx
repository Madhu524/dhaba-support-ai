import React, { useState, useEffect } from 'react';

export default function TriagePanel({
  ticket,
  onApprove,
  onEditReply,
  onEscalate,
  onReplay,
  isProcessing
}) {
  const triage = ticket?.triage;
  const [draftText, setDraftText] = useState('');
  const [isEditing, setIsEditing] = useState(false);
  const [escalateReason, setEscalateReason] = useState('');
  const [showEscalatePrompt, setShowEscalatePrompt] = useState(false);

  useEffect(() => {
    if (triage?.reply_draft) {
      setDraftText(triage.reply_draft);
      setIsEditing(false);
      setShowEscalatePrompt(false);
    }
  }, [ticket?.id, triage?.reply_draft]);

  if (!triage) {
    return (
      <div className="section-card">
        <div className="section-title">AI Triage Status</div>
        <p style={{ color: 'var(--text-muted)', fontSize: '13px' }}>
          This ticket has not been triaged yet.
        </p>
      </div>
    );
  }

  const handleSaveEdit = () => {
    onEditReply(ticket.id, draftText);
    setIsEditing(false);
  };

  const handleEscalateSubmit = () => {
    if (!escalateReason.trim()) return;
    onEscalate(ticket.id, escalateReason);
    setShowEscalatePrompt(false);
    setEscalateReason('');
  };

  return (
    <div className="detail-sidebar">
      {/* Triage Summary */}
      <div className="section-card">
        <div className="section-title">
          <span>AI Triage Analysis</span>
          {triage.degraded && (
            <span className="badge badge-urgent" title="Triaged using deterministic fallback due to service outage/circuit breaker">
              Degraded Mode
            </span>
          )}
        </div>
        <div className="triage-grid">
          <div className="triage-field">
            <label>Category</label>
            <span>{triage.category?.replace('_', ' ')}</span>
          </div>
          <div className="triage-field">
            <label>Severity</label>
            <span className={`badge badge-${triage.severity}`}>
              {triage.severity}
            </span>
          </div>
          <div className="triage-field">
            <label>Confidence</label>
            <span style={{ color: triage.confidence > 0.8 ? '#34d399' : '#fbbf24' }}>
              {Math.round(triage.confidence * 100)}%
            </span>
          </div>
          <div className="triage-field">
            <label>Human Review</label>
            <span style={{ color: triage.needs_human ? '#f87171' : '#34d399' }}>
              {triage.needs_human ? 'Required' : 'Optional'}
            </span>
          </div>
        </div>
      </div>

      {/* Deterministic Refund Policy Recommendation */}
      <div className="section-card">
        <div className="section-title">
          <span>Deterministic Refund Gate</span>
          <span style={{ fontSize: '10px', color: 'var(--text-muted)' }}>Rules-based, isolated from AI</span>
        </div>
        <div className={`refund-callout ${triage.refund?.should_refund ? 'recommended' : 'none'}`}>
          <div className="refund-title">
            <span>
              {triage.refund?.should_refund
                ? `⚠️ Recommend Refund: ₹${triage.refund.amount_inr}`
                : '✅ No Refund Recommended'}
            </span>
            {triage.refund?.should_refund && (
              <span className="badge badge-urgent" style={{ fontSize: '10px' }}>
                Pending Approval
              </span>
            )}
          </div>
          <div className="refund-reason">
            {triage.refund?.reason}
          </div>
          {triage.refund?.should_refund && (
            <p style={{ fontSize: '11px', color: '#fbbf24', marginTop: '4px', fontStyle: 'italic' }}>
              * Financial safety: This refund will not be disbursed until a human supervisor confirms it.
            </p>
          )}
        </div>
      </div>

      {/* Draft Reply Editor */}
      <div className="section-card">
        <div className="section-title">
          <span>Customer Draft Reply</span>
          {isEditing && (
            <span style={{ fontSize: '11px', color: '#a78bfa' }}>Unsaved changes</span>
          )}
        </div>
        <textarea
          className="reply-editor"
          value={draftText}
          onChange={(e) => {
            setDraftText(e.target.value);
            setIsEditing(true);
          }}
          placeholder="Draft reply to customer..."
        />
        
        <div className="action-buttons-group">
          {isEditing ? (
            <button
              className="btn btn-secondary"
              onClick={handleSaveEdit}
              disabled={isProcessing}
            >
              💾 Save Edited Draft
            </button>
          ) : null}

          <button
            className="btn btn-primary"
            onClick={() => onApprove(ticket.id)}
            disabled={isProcessing || ticket.status === 'approved'}
          >
            {ticket.status === 'approved' ? '✓ Approved' : '🚀 Approve & Send Reply'}
          </button>

          {!showEscalatePrompt ? (
            <button
              className="btn btn-danger"
              onClick={() => setShowEscalatePrompt(true)}
              disabled={isProcessing || ticket.status === 'escalated'}
            >
              ⚠️ Escalate to Supervisor
            </button>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', background: 'var(--bg-tertiary)', padding: '10px', borderRadius: '6px' }}>
              <input
                type="text"
                className="search-input"
                placeholder="Reason for escalation..."
                value={escalateReason}
                onChange={(e) => setEscalateReason(e.target.value)}
              />
              <div style={{ display: 'flex', gap: '6px' }}>
                <button
                  className="btn btn-danger"
                  style={{ flex: 1, padding: '6px' }}
                  onClick={handleEscalateSubmit}
                  disabled={!escalateReason.trim()}
                >
                  Confirm Escalate
                </button>
                <button
                  className="btn btn-secondary"
                  style={{ padding: '6px 12px' }}
                  onClick={() => setShowEscalatePrompt(false)}
                >
                  Cancel
                </button>
              </div>
            </div>
          )}

          <button
            className="btn btn-secondary"
            onClick={() => onReplay(ticket.id)}
            disabled={isProcessing}
            title="Re-run AI triage and refund policy from scratch"
          >
            🔄 Replay Triage Rules
          </button>
        </div>
      </div>
    </div>
  );
}
