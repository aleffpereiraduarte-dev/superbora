<?php
/**
 * Admin Styles - OneMundo Mercado
 * Estilos CSS para páginas administrativas
 */
?>
<style>
:root {
    --bg: #f8fafc;
    --card: #ffffff;
    --card-alt: #f1f5f9;
    --border: #e2e8f0;
    --text: #1e293b;
    --text-muted: #64748b;
    --primary: #3b82f6;
    --primary-dark: #2563eb;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --purple: #8b5cf6;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.card {
    background: var(--card);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid var(--border);
}

.card-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: var(--primary-dark);
}

.btn-success {
    background: var(--success);
    color: white;
}

.btn-danger {
    background: var(--danger);
    color: white;
}

.btn-secondary {
    background: var(--card-alt);
    color: var(--text);
    border: 1px solid var(--border);
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
    margin-bottom: 6px;
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary);
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
}

.table th {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}

.badge-success {
    background: rgba(16,185,129,0.1);
    color: var(--success);
}

.badge-warning {
    background: rgba(245,158,11,0.1);
    color: var(--warning);
}

.badge-danger {
    background: rgba(239,68,68,0.1);
    color: var(--danger);
}

.alert {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: 14px;
}

.alert-success {
    background: rgba(16,185,129,0.1);
    color: var(--success);
    border: 1px solid rgba(16,185,129,0.2);
}

.alert-warning {
    background: rgba(245,158,11,0.1);
    color: var(--warning);
    border: 1px solid rgba(245,158,11,0.2);
}

.alert-danger {
    background: rgba(239,68,68,0.1);
    color: var(--danger);
    border: 1px solid rgba(239,68,68,0.2);
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.stat-card {
    background: var(--card);
    border-radius: 12px;
    padding: 20px;
    border: 1px solid var(--border);
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--text);
}

.stat-label {
    font-size: 13px;
    color: var(--text-muted);
    margin-top: 4px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.header h1 {
    font-size: 24px;
    font-weight: 600;
}

@media (max-width: 768px) {
    .container { padding: 16px; }
    .card { padding: 16px; }
    .header { flex-direction: column; gap: 12px; align-items: flex-start; }
}
</style>
