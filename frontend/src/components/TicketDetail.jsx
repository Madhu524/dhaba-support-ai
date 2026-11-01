import React from 'react';

export default function TicketDetail({ ticket, isLoading }) {
  if (isLoading) {
    return (
      <div className="detail-main">
        <div className="loading-box">
          <div className="spinner"></div>
          <p>Loading ticket details...</p>
        </div>
      </div>
    );
  }

  if (!ticket) {
    return (
      <div className="detail-main">
        <div className="empty-box">
          <h3>Select a Ticket</h3>
          <p>Choose a ticket from the queue on the left to inspect customer history and triage decisions.</p>
        </div>
      </div>
    );
  }

  return (
    <div className="detail-main">
      {/* Header Info */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '4px' }}>
            <span className="ticket-id" style={{ fontSize: '15px' }}>{ticket.id}</span>
            <span className={`badge badge-status ${ticket.status}`}>
              {ticket.status}
            </span>
            {ticket.triage?.needs_human && (
              <span className="badge badge-urgent">Needs Human Review</span>
            )}
          </div>
          <h2 style={{ fontSize: '18px', fontWeight: '700', color: '#fff' }}>
            {ticket.subject}
          </h2>
          <span style={{ fontSize: '12px', color: 'var(--text-muted)' }}>
            Received: {new Date(ticket.received_at).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' })}
          </span>
        </div>
      </div>

      {/* Customer Message */}
      <div className="section-card">
        <div className="section-title">Customer Message</div>
        <div className="ticket-body-box">
          {ticket.body}
        </div>
      </div>

      {/* Usage Telemetry */}
      <div className="section-card">
        <div className="section-title">Telemetry & Usage Context</div>
        <div className="telemetry-grid">
          <div className="telemetry-item">
            <label>App Opens Since Renewal</label>
            <span style={{ color: ticket.app_opens_since_renewal === 0 ? '#fbbf24' : '#fff' }}>
              {ticket.app_opens_since_renewal} {ticket.app_opens_since_renewal === 0 ? '(Zero Usage Shock)' : 'opens'}
            </span>
          </div>
          <div className="telemetry-item">
            <label>Purchases on Record</label>
            <span>{ticket.purchases?.length || 0} transaction(s)</span>
          </div>
        </div>
      </div>

      {/* Purchase History */}
      <div className="section-card">
        <div className="section-title">Billing Records (Purchases)</div>
        {(!ticket.purchases || ticket.purchases.length === 0) ? (
          <p style={{ color: 'var(--text-muted)', fontSize: '13px' }}>
            No purchase records found for this account (Pre-subscription or free user).
          </p>
        ) : (
          <table className="purchases-table">
            <thead>
              <tr>
                <th>Payment ID</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Timestamp</th>
              </tr>
            </thead>
            <tbody>
              {ticket.purchases.map((p) => (
                <tr key={p.id}>
                  <td style={{ fontFamily: 'var(--font-mono)' }}>{p.id}</td>
                  <td style={{ textTransform: 'capitalize' }}>{p.type}</td>
                  <td>₹{p.amount_inr}</td>
                  <td>
                    <span className={`status-${p.status}`}>
                      {p.status}
                    </span>
                  </td>
                  <td style={{ color: 'var(--text-muted)' }}>
                    {p.purchased_at ? new Date(p.purchased_at).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' }) : 'N/A'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Audit Trail */}
      <div className="section-card">
        <div className="section-title">Immutable Audit Trail (3AM Debugging)</div>
        {(!ticket.audit_events || ticket.audit_events.length === 0) ? (
          <p style={{ color: 'var(--text-muted)', fontSize: '13px' }}>No audit events recorded yet.</p>
        ) : (
          <div className="audit-list">
            {ticket.audit_events.map((ev) => (
              <div key={ev.id} className="audit-item">
                <div className="audit-meta">
                  <span className="audit-type">{ev.event_type}</span>
                  <span>{new Date(ev.created_at).toLocaleTimeString()} ({ev.actor})</span>
                </div>
                {ev.metadata_json && (
                  <div style={{ fontFamily: 'var(--font-mono)', fontSize: '10px', color: 'var(--text-secondary)' }}>
                    {ev.metadata_json}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
