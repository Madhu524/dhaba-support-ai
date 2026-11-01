import React, { useState, useEffect, useCallback } from 'react';
import axios from 'axios';
import MetricsBar from './components/MetricsBar';
import TicketList from './components/TicketList';
import TicketDetail from './components/TicketDetail';
import TriagePanel from './components/TriagePanel';

const API_BASE_URL = 'http://127.0.0.1:8000';

export default function App() {
  const [tickets, setTickets] = useState([]);
  const [metrics, setMetrics] = useState(null);
  const [selectedTicketId, setSelectedTicketId] = useState(null);
  const [activeTicket, setActiveTicket] = useState(null);
  
  // Filters
  const [searchTerm, setSearchTerm] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('');
  const [severityFilter, setSeverityFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  // Status flags
  const [loadingList, setLoadingList] = useState(true);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [isProcessing, setIsProcessing] = useState(false);
  const [errorMessage, setErrorMessage] = useState(null);
  const [notification, setNotification] = useState(null);

  const showNotification = (msg) => {
    setNotification(msg);
    setTimeout(() => setNotification(null), 4000);
  };

  // Fetch metrics
  const fetchMetrics = useCallback(async () => {
    try {
      const res = await axios.get(`${API_BASE_URL}/metrics`);
      setMetrics(res.data);
    } catch (err) {
      console.error('Failed to fetch metrics:', err);
    }
  }, []);

  // Fetch ticket list
  const fetchTickets = useCallback(async () => {
    try {
      const params = {};
      if (searchTerm) params.search = searchTerm;
      if (categoryFilter) params.category = categoryFilter;
      if (severityFilter) params.severity = severityFilter;
      if (statusFilter) params.status = statusFilter;

      const res = await axios.get(`${API_BASE_URL}/tickets`, { params });
      setTickets(res.data);
      if (!selectedTicketId && res.data.length > 0) {
        setSelectedTicketId(res.data[0].id);
      }
    } catch (err) {
      setErrorMessage('Failed to load tickets from server. Ensure backend is running at http://127.0.0.1:8000');
    } finally {
      setLoadingList(false);
    }
  }, [searchTerm, categoryFilter, severityFilter, statusFilter, selectedTicketId]);

  // Fetch ticket details
  const fetchTicketDetail = useCallback(async (ticketId) => {
    if (!ticketId) return;
    setLoadingDetail(true);
    try {
      const res = await axios.get(`${API_BASE_URL}/tickets/${ticketId}`);
      setActiveTicket(res.data);
    } catch (err) {
      setErrorMessage(`Failed to load details for ${ticketId}`);
    } finally {
      setLoadingDetail(false);
    }
  }, []);

  useEffect(() => {
    fetchTickets();
    fetchMetrics();
    const interval = setInterval(() => {
      fetchMetrics();
    }, 15000);
    return () => clearInterval(interval);
  }, [fetchTickets, fetchMetrics]);

  useEffect(() => {
    if (selectedTicketId) {
      fetchTicketDetail(selectedTicketId);
    }
  }, [selectedTicketId, fetchTicketDetail]);

  // Actions
  const handleApprove = async (ticketId) => {
    setIsProcessing(true);
    try {
      await axios.post(`${API_BASE_URL}/tickets/${ticketId}/approve`, { actor: 'support_agent' });
      showNotification(`Ticket ${ticketId} draft approved and marked ready to send!`);
      await fetchTicketDetail(ticketId);
      await fetchTickets();
      await fetchMetrics();
    } catch (err) {
      alert('Approval failed: ' + (err.response?.data?.detail || err.message));
    } finally {
      setIsProcessing(false);
    }
  };

  const handleEditReply = async (ticketId, newDraft) => {
    setIsProcessing(true);
    try {
      await axios.post(`${API_BASE_URL}/tickets/${ticketId}/edit-reply`, {
        reply_draft: newDraft,
        actor: 'support_agent'
      });
      showNotification(`Ticket ${ticketId} reply draft updated successfully!`);
      await fetchTicketDetail(ticketId);
      await fetchTickets();
      await fetchMetrics();
    } catch (err) {
      alert('Edit failed: ' + (err.response?.data?.detail || err.message));
    } finally {
      setIsProcessing(false);
    }
  };

  const handleEscalate = async (ticketId, reason) => {
    setIsProcessing(true);
    try {
      await axios.post(`${API_BASE_URL}/tickets/${ticketId}/escalate`, {
        reason: reason,
        actor: 'support_agent'
      });
      showNotification(`Ticket ${ticketId} escalated to senior supervisor!`);
      await fetchTicketDetail(ticketId);
      await fetchTickets();
      await fetchMetrics();
    } catch (err) {
      alert('Escalation failed: ' + (err.response?.data?.detail || err.message));
    } finally {
      setIsProcessing(false);
    }
  };

  const handleReplay = async (ticketId) => {
    setIsProcessing(true);
    try {
      await axios.post(`${API_BASE_URL}/tickets/${ticketId}/replay`);
      showNotification(`Re-evaluated AI triage & refund policy for ${ticketId}!`);
      await fetchTicketDetail(ticketId);
      await fetchTickets();
      await fetchMetrics();
    } catch (err) {
      alert('Replay failed: ' + (err.response?.data?.detail || err.message));
    } finally {
      setIsProcessing(false);
    }
  };

  return (
    <div className="app-container">
      {/* Top Header */}
      <header className="app-header">
        <div className="header-brand">
          <span className="brand-logo">🍲</span>
          <span className="brand-title">Dhaba Support AI Triage</span>
          <span className="brand-badge">Propel Spark AI Assessment</span>
        </div>
        <div className="header-actions">
          {notification && (
            <span style={{ fontSize: '12px', background: 'rgba(16, 185, 129, 0.2)', color: '#34d399', padding: '4px 12px', borderRadius: '4px', border: '1px solid rgba(16, 185, 129, 0.4)' }}>
              {notification}
            </span>
          )}
          <button
            className="btn btn-secondary"
            style={{ padding: '6px 12px', fontSize: '12px' }}
            onClick={() => {
              fetchTickets();
              fetchMetrics();
              if (selectedTicketId) fetchTicketDetail(selectedTicketId);
            }}
          >
            🔄 Refresh
          </button>
        </div>
      </header>

      {/* KPI Metrics Dashboard */}
      <MetricsBar metrics={metrics} />

      {/* Error banner */}
      {errorMessage && (
        <div style={{ background: '#ef4444', color: '#fff', padding: '8px 24px', fontSize: '12px', display: 'flex', justifyContent: 'space-between' }}>
          <span>{errorMessage}</span>
          <button onClick={() => setErrorMessage(null)} style={{ background: 'transparent', border: 'none', color: '#fff', cursor: 'pointer', fontWeight: 'bold' }}>✕</button>
        </div>
      )}

      {/* Main Workspace Split Pane */}
      <main className="main-workspace">
        <TicketList
          tickets={tickets}
          selectedId={selectedTicketId}
          onSelectTicket={setSelectedTicketId}
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          categoryFilter={categoryFilter}
          onCategoryChange={setCategoryFilter}
          severityFilter={severityFilter}
          onSeverityChange={setSeverityFilter}
          statusFilter={statusFilter}
          onStatusChange={setStatusFilter}
        />

        <div className="detail-area">
          <TicketDetail
            ticket={activeTicket}
            isLoading={loadingDetail}
          />
          <TriagePanel
            ticket={activeTicket}
            onApprove={handleApprove}
            onEditReply={handleEditReply}
            onEscalate={handleEscalate}
            onReplay={handleReplay}
            isProcessing={isProcessing}
          />
        </div>
      </main>
    </div>
  );
}
