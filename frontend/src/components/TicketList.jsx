import React from 'react';
import RefundBadge from './RefundBadge';

export default function TicketList({
  tickets,
  selectedId,
  onSelectTicket,
  searchTerm,
  onSearchChange,
  categoryFilter,
  onCategoryChange,
  severityFilter,
  onSeverityChange,
  statusFilter,
  onStatusChange
}) {
  return (
    <div className="queue-panel">
      <div className="queue-controls">
        <input
          type="text"
          className="search-input"
          placeholder="Search ticket subject or ID..."
          value={searchTerm}
          onChange={(e) => onSearchChange(e.target.value)}
        />
        <div className="filter-row">
          <select
            className="filter-select"
            value={categoryFilter}
            onChange={(e) => onCategoryChange(e.target.value)}
          >
            <option value="">All Categories</option>
            <option value="billing">Billing</option>
            <option value="technical_issue">Technical</option>
            <option value="cancellation">Cancellation</option>
            <option value="account_management">Account</option>
            <option value="feature_request">Feature Request</option>
            <option value="complaint">Complaint</option>
            <option value="inquiry">Inquiry</option>
          </select>
          <select
            className="filter-select"
            value={severityFilter}
            onChange={(e) => onSeverityChange(e.target.value)}
          >
            <option value="">All Severities</option>
            <option value="urgent">Urgent</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
          </select>
          <select
            className="filter-select"
            value={statusFilter}
            onChange={(e) => onStatusChange(e.target.value)}
          >
            <option value="">All Statuses</option>
            <option value="new">New</option>
            <option value="triaged">Triaged</option>
            <option value="approved">Approved</option>
            <option value="edited">Edited</option>
            <option value="escalated">Escalated</option>
          </select>
        </div>
      </div>

      <div className="ticket-list">
        {tickets.length === 0 ? (
          <div className="empty-box" style={{ padding: '20px' }}>
            <p>No tickets match criteria</p>
          </div>
        ) : (
          tickets.map((t) => {
            const isSelected = t.id === selectedId;
            return (
              <div
                key={t.id}
                className={`ticket-item ${isSelected ? 'active' : ''}`}
                onClick={() => onSelectTicket(t.id)}
              >
                <div className="ticket-item-header">
                  <span className="ticket-id">{t.id}</span>
                  <span className={`badge badge-status ${t.status}`}>
                    {t.status}
                  </span>
                </div>
                <div className="ticket-subject" title={t.subject}>
                  {t.subject}
                </div>
                <div className="ticket-meta">
                  {t.severity && (
                    <span className={`badge badge-${t.severity}`}>
                      {t.severity}
                    </span>
                  )}
                  {t.category && (
                    <span className="badge badge-low" style={{ textTransform: 'capitalize' }}>
                      {t.category.replace('_', ' ')}
                    </span>
                  )}
                  <RefundBadge
                    shouldRefund={t.should_refund}
                    amountInr={t.refund_amount_inr}
                  />
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}
