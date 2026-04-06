<?php
/**
 * GET /api/mercado/docs-ui.php
 * Interactive API documentation UI
 *
 * Renders a searchable, filterable HTML page from docs.php data.
 * No auth required — public documentation page.
 */
date_default_timezone_set('America/Sao_Paulo');
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: public, max-age=300");
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SuperBora API Documentation</title>
<style>
:root {
    --bg-primary: #0d1117;
    --bg-secondary: #161b22;
    --bg-tertiary: #21262d;
    --bg-hover: #30363d;
    --border: #30363d;
    --text-primary: #e6edf3;
    --text-secondary: #8b949e;
    --text-muted: #6e7681;
    --accent: #58a6ff;
    --accent-hover: #79c0ff;
    --get: #3fb950;
    --get-bg: #0d2818;
    --post: #58a6ff;
    --post-bg: #0d1b2e;
    --put: #d29922;
    --put-bg: #2d2006;
    --patch: #db8b0b;
    --patch-bg: #2d2006;
    --delete: #f85149;
    --delete-bg: #2d0b0b;
    --auth-required: #f0883e;
    --auth-optional: #8b949e;
    --auth-admin: #f85149;
    --auth-partner: #d29922;
    --auth-customer: #58a6ff;
    --auth-webhook: #a371f7;
    --shadow: 0 1px 3px rgba(0,0,0,0.3);
    --radius: 8px;
    --radius-sm: 4px;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
    background: var(--bg-primary);
    color: var(--text-primary);
    line-height: 1.6;
    min-height: 100vh;
}

/* Header */
.header {
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border);
    padding: 20px 24px;
    position: sticky;
    top: 0;
    z-index: 100;
}

.header-inner {
    max-width: 1400px;
    margin: 0 auto;
}

.header h1 {
    font-size: 24px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.header h1 span {
    color: var(--accent);
}

.header-meta {
    display: flex;
    gap: 16px;
    font-size: 13px;
    color: var(--text-secondary);
    flex-wrap: wrap;
    align-items: center;
}

.header-meta .stat {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.header-meta .stat strong {
    color: var(--text-primary);
}

/* Search and Filters */
.filters {
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border);
    padding: 12px 24px;
    position: sticky;
    top: 88px;
    z-index: 99;
}

.filters-inner {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

.search-box {
    flex: 1;
    min-width: 250px;
    position: relative;
}

.search-box input {
    width: 100%;
    padding: 8px 12px 8px 36px;
    background: var(--bg-tertiary);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text-primary);
    font-size: 14px;
    outline: none;
    transition: border-color 0.2s;
}

.search-box input:focus {
    border-color: var(--accent);
}

.search-box input::placeholder {
    color: var(--text-muted);
}

.search-box .search-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 14px;
    pointer-events: none;
}

.filter-select {
    padding: 8px 12px;
    background: var(--bg-tertiary);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text-primary);
    font-size: 13px;
    outline: none;
    cursor: pointer;
    min-width: 140px;
}

.filter-select:focus {
    border-color: var(--accent);
}

.filter-select option {
    background: var(--bg-secondary);
    color: var(--text-primary);
}

.results-count {
    font-size: 13px;
    color: var(--text-secondary);
    white-space: nowrap;
}

/* Layout */
.layout {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    gap: 0;
}

/* Sidebar */
.sidebar {
    width: 260px;
    min-width: 260px;
    background: var(--bg-secondary);
    border-right: 1px solid var(--border);
    padding: 16px 0;
    position: sticky;
    top: 132px;
    height: calc(100vh - 132px);
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--bg-hover) transparent;
}

.sidebar-title {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    padding: 4px 16px 8px;
}

.sidebar a {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 16px;
    text-decoration: none;
    color: var(--text-secondary);
    font-size: 13px;
    border-left: 2px solid transparent;
    transition: all 0.15s;
}

.sidebar a:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
}

.sidebar a.active {
    background: var(--bg-tertiary);
    color: var(--accent);
    border-left-color: var(--accent);
}

.sidebar .count-badge {
    font-size: 11px;
    color: var(--text-muted);
    background: var(--bg-tertiary);
    padding: 1px 6px;
    border-radius: 10px;
    min-width: 22px;
    text-align: center;
}

.sidebar a.active .count-badge {
    background: var(--bg-hover);
    color: var(--accent);
}

/* Main content */
.main {
    flex: 1;
    padding: 24px;
    min-width: 0;
}

/* Category section */
.category-section {
    margin-bottom: 32px;
}

.category-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 0;
    cursor: pointer;
    border-bottom: 1px solid var(--border);
    margin-bottom: 12px;
    user-select: none;
}

.category-header:hover .category-name {
    color: var(--accent);
}

.category-toggle {
    font-size: 12px;
    color: var(--text-muted);
    transition: transform 0.2s;
    width: 16px;
    text-align: center;
}

.category-toggle.collapsed {
    transform: rotate(-90deg);
}

.category-name {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    transition: color 0.15s;
}

.category-count {
    font-size: 12px;
    color: var(--text-muted);
    background: var(--bg-tertiary);
    padding: 2px 8px;
    border-radius: 10px;
}

.category-body {
    overflow: hidden;
    transition: max-height 0.3s ease;
}

.category-body.collapsed {
    max-height: 0 !important;
}

/* Endpoint card */
.endpoint {
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 8px;
    overflow: hidden;
    transition: border-color 0.15s;
}

.endpoint:hover {
    border-color: var(--accent);
}

.endpoint-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    cursor: pointer;
    flex-wrap: wrap;
}

.method-badge {
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    flex-shrink: 0;
}

.method-GET    { color: var(--get); background: var(--get-bg); border: 1px solid var(--get); }
.method-POST   { color: var(--post); background: var(--post-bg); border: 1px solid var(--post); }
.method-PUT    { color: var(--put); background: var(--put-bg); border: 1px solid var(--put); }
.method-PATCH  { color: var(--patch); background: var(--patch-bg); border: 1px solid var(--patch); }
.method-DELETE { color: var(--delete); background: var(--delete-bg); border: 1px solid var(--delete); }

.endpoint-path {
    font-family: 'SFMono-Regular', 'Consolas', 'Liberation Mono', monospace;
    font-size: 13px;
    color: var(--text-primary);
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.auth-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    flex-shrink: 0;
}

.auth-admin    { color: var(--auth-admin); background: rgba(248,81,73,0.1); border: 1px solid rgba(248,81,73,0.3); }
.auth-partner  { color: var(--auth-partner); background: rgba(210,153,34,0.1); border: 1px solid rgba(210,153,34,0.3); }
.auth-customer { color: var(--auth-customer); background: rgba(88,166,255,0.1); border: 1px solid rgba(88,166,255,0.3); }
.auth-optional { color: var(--auth-optional); background: rgba(139,148,158,0.1); border: 1px solid rgba(139,148,158,0.3); }
.auth-webhook  { color: var(--auth-webhook); background: rgba(163,113,247,0.1); border: 1px solid rgba(163,113,247,0.3); }
.auth-none     { display: none; }

.expand-icon {
    color: var(--text-muted);
    font-size: 10px;
    flex-shrink: 0;
    transition: transform 0.2s;
}

.endpoint.expanded .expand-icon {
    transform: rotate(90deg);
}

.endpoint-detail {
    display: none;
    padding: 0 14px 14px;
    border-top: 1px solid var(--border);
}

.endpoint.expanded .endpoint-detail {
    display: block;
}

.detail-row {
    margin-top: 10px;
}

.detail-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 4px;
}

.detail-value {
    font-size: 13px;
    color: var(--text-secondary);
    line-height: 1.5;
}

.detail-value code {
    font-family: 'SFMono-Regular', 'Consolas', monospace;
    font-size: 12px;
    background: var(--bg-tertiary);
    padding: 1px 5px;
    border-radius: 3px;
    color: var(--accent);
}

.param-list {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.param-tag {
    font-family: 'SFMono-Regular', 'Consolas', monospace;
    font-size: 11px;
    background: var(--bg-tertiary);
    color: var(--text-secondary);
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
}

.full-url {
    font-family: 'SFMono-Regular', 'Consolas', monospace;
    font-size: 12px;
    background: var(--bg-primary);
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    color: var(--text-secondary);
    word-break: break-all;
    cursor: pointer;
    position: relative;
    border: 1px solid var(--border);
}

.full-url:hover {
    border-color: var(--accent);
}

.full-url .copy-hint {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 10px;
    color: var(--text-muted);
}

.toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: var(--accent);
    color: #000;
    padding: 8px 16px;
    border-radius: var(--radius);
    font-size: 13px;
    font-weight: 600;
    opacity: 0;
    transform: translateY(10px);
    transition: all 0.3s;
    z-index: 1000;
    pointer-events: none;
}

.toast.show {
    opacity: 1;
    transform: translateY(0);
}

/* Loading state */
.loading {
    display: flex;
    justify-content: center;
    align-items: center;
    height: 50vh;
    color: var(--text-muted);
}

.loading-spinner {
    width: 24px;
    height: 24px;
    border: 3px solid var(--border);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-right: 12px;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* Empty state */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}

.empty-state .icon {
    font-size: 48px;
    margin-bottom: 16px;
}

/* Responsive */
@media (max-width: 900px) {
    .sidebar { display: none; }
    .layout { flex-direction: column; }
    .main { padding: 16px; }
    .filters-inner { flex-direction: column; }
    .search-box { min-width: 100%; }
}

/* Scrollbar */
::-webkit-scrollbar { width: 8px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--bg-hover); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }
</style>
</head>
<body>

<div class="header">
    <div class="header-inner">
        <h1>SuperBora <span>API</span></h1>
        <div class="header-meta" id="headerMeta">
            <span class="stat">Loading...</span>
        </div>
    </div>
</div>

<div class="filters">
    <div class="filters-inner">
        <div class="search-box">
            <span class="search-icon">&#128269;</span>
            <input type="text" id="searchInput" placeholder="Search endpoints by path, description, or body fields..." autocomplete="off">
        </div>
        <select class="filter-select" id="methodFilter">
            <option value="">All Methods</option>
            <option value="GET">GET</option>
            <option value="POST">POST</option>
            <option value="PUT">PUT</option>
            <option value="PATCH">PATCH</option>
            <option value="DELETE">DELETE</option>
        </select>
        <select class="filter-select" id="authFilter">
            <option value="">All Auth</option>
            <option value="required">Auth Required</option>
            <option value="none">No Auth</option>
            <option value="admin">Admin Only</option>
            <option value="partner">Partner Only</option>
            <option value="customer">Customer Only</option>
        </select>
        <select class="filter-select" id="categoryFilter">
            <option value="">All Categories</option>
        </select>
        <span class="results-count" id="resultsCount"></span>
    </div>
</div>

<div class="layout">
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-title">Categories</div>
    </nav>
    <main class="main" id="mainContent">
        <div class="loading"><div class="loading-spinner"></div>Loading API documentation...</div>
    </main>
</div>

<div class="toast" id="toast">Copied!</div>

<script>
(function() {
    'use strict';

    let apiData = null;
    let allEndpoints = [];
    const BASE_URL = 'https://superbora.com.br/api/mercado';

    // Fetch API docs data
    fetch('docs.php')
        .then(r => r.json())
        .then(data => {
            apiData = data.data;
            init();
        })
        .catch(err => {
            document.getElementById('mainContent').innerHTML =
                '<div class="empty-state"><div class="icon">&#9888;</div><p>Failed to load API documentation.</p><p style="margin-top:8px;font-size:13px">' + err.message + '</p></div>';
        });

    function init() {
        // Flatten all endpoints with category info
        allEndpoints = [];
        for (const [catKey, catData] of Object.entries(apiData.categories)) {
            for (const ep of catData.endpoints) {
                allEndpoints.push({
                    ...ep,
                    category: catKey,
                    categoryName: catData.name
                });
            }
        }

        // Update header
        document.getElementById('headerMeta').innerHTML =
            '<span class="stat"><strong>' + apiData.total_endpoints + '</strong> endpoints</span>' +
            '<span class="stat"><strong>' + apiData.total_categories + '</strong> categories</span>' +
            '<span class="stat">Base: <code style="font-family:monospace;color:var(--accent);font-size:12px">' + apiData.base_url + '</code></span>' +
            '<span class="stat">Generated: ' + new Date(apiData.generated_at).toLocaleString('pt-BR') + '</span>';

        // Populate category filter
        const catFilter = document.getElementById('categoryFilter');
        for (const [key, cat] of Object.entries(apiData.categories)) {
            const opt = document.createElement('option');
            opt.value = key;
            opt.textContent = cat.name + ' (' + cat.count + ')';
            catFilter.appendChild(opt);
        }

        // Build sidebar
        buildSidebar();

        // Initial render
        render();

        // Event listeners
        document.getElementById('searchInput').addEventListener('input', debounce(render, 200));
        document.getElementById('methodFilter').addEventListener('change', render);
        document.getElementById('authFilter').addEventListener('change', render);
        document.getElementById('categoryFilter').addEventListener('change', function() {
            render();
            // Also highlight sidebar
            document.querySelectorAll('.sidebar a').forEach(a => a.classList.remove('active'));
            if (this.value) {
                const link = document.querySelector('.sidebar a[data-cat="' + this.value + '"]');
                if (link) link.classList.add('active');
            }
        });
    }

    function buildSidebar() {
        const sidebar = document.getElementById('sidebar');
        sidebar.innerHTML = '<div class="sidebar-title">Categories</div>';
        for (const [key, cat] of Object.entries(apiData.categories)) {
            const a = document.createElement('a');
            a.href = '#cat-' + key;
            a.dataset.cat = key;
            a.innerHTML = '<span>' + escHtml(cat.name) + '</span><span class="count-badge">' + cat.count + '</span>';
            a.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('categoryFilter').value = key;
                render();
                document.querySelectorAll('.sidebar a').forEach(x => x.classList.remove('active'));
                this.classList.add('active');
                // Scroll to top of main
                document.getElementById('mainContent').scrollIntoView({ behavior: 'smooth' });
            });
            sidebar.appendChild(a);
        }

        // "All" link
        const allLink = document.createElement('a');
        allLink.href = '#';
        allLink.style.borderTop = '1px solid var(--border)';
        allLink.style.marginTop = '8px';
        allLink.style.paddingTop = '12px';
        allLink.innerHTML = '<span>Show All</span><span class="count-badge">' + apiData.total_endpoints + '</span>';
        allLink.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('categoryFilter').value = '';
            document.getElementById('searchInput').value = '';
            document.getElementById('methodFilter').value = '';
            document.getElementById('authFilter').value = '';
            render();
            document.querySelectorAll('.sidebar a').forEach(x => x.classList.remove('active'));
        });
        sidebar.appendChild(allLink);
    }

    function render() {
        const search = document.getElementById('searchInput').value.toLowerCase().trim();
        const method = document.getElementById('methodFilter').value;
        const auth = document.getElementById('authFilter').value;
        const category = document.getElementById('categoryFilter').value;

        // Filter endpoints
        let filtered = allEndpoints.filter(ep => {
            if (category && ep.category !== category) return false;
            if (method && !ep.methods.includes(method)) return false;
            if (auth === 'required' && !ep.auth_required) return false;
            if (auth === 'none' && ep.auth_required) return false;
            if (auth === 'admin' && ep.auth_type !== 'admin') return false;
            if (auth === 'partner' && ep.auth_type !== 'partner') return false;
            if (auth === 'customer' && ep.auth_type !== 'customer') return false;
            if (search) {
                const haystack = (ep.path + ' ' + (ep.description || '') + ' ' + (ep.body_fields || []).join(' ') + ' ' + (ep.query_params || []).join(' ')).toLowerCase();
                // Support multiple search terms (AND)
                const terms = search.split(/\s+/);
                for (const t of terms) {
                    if (!haystack.includes(t)) return false;
                }
            }
            return true;
        });

        // Update results count
        document.getElementById('resultsCount').textContent = filtered.length + ' of ' + allEndpoints.length + ' endpoints';

        // Group by category
        const grouped = {};
        for (const ep of filtered) {
            if (!grouped[ep.category]) {
                grouped[ep.category] = {
                    name: ep.categoryName,
                    endpoints: []
                };
            }
            grouped[ep.category].endpoints.push(ep);
        }

        // Render
        const main = document.getElementById('mainContent');

        if (filtered.length === 0) {
            main.innerHTML = '<div class="empty-state"><div class="icon">&#128270;</div><p>No endpoints match your filters.</p><p style="margin-top:8px;font-size:13px;color:var(--text-muted)">Try adjusting your search or filter criteria.</p></div>';
            return;
        }

        let html = '';
        for (const [catKey, catData] of Object.entries(grouped)) {
            html += '<div class="category-section" id="cat-' + catKey + '">';
            html += '<div class="category-header" onclick="toggleCategory(this)">';
            html += '<span class="category-toggle">&#9660;</span>';
            html += '<span class="category-name">' + escHtml(catData.name) + '</span>';
            html += '<span class="category-count">' + catData.endpoints.length + '</span>';
            html += '</div>';
            html += '<div class="category-body">';

            for (const ep of catData.endpoints) {
                html += renderEndpoint(ep);
            }

            html += '</div></div>';
        }

        main.innerHTML = html;
    }

    function renderEndpoint(ep) {
        const methods = ep.methods.map(m => '<span class="method-badge method-' + m + '">' + m + '</span>').join('');

        let authBadge = '';
        if (ep.auth_required) {
            const authClass = 'auth-' + (ep.auth_type || 'customer');
            const authLabel = ep.auth_type ? ep.auth_type.charAt(0).toUpperCase() + ep.auth_type.slice(1) : 'Auth';
            authBadge = '<span class="auth-badge ' + authClass + '">' + authLabel + '</span>';
        } else if (ep.auth_type === 'optional') {
            authBadge = '<span class="auth-badge auth-optional">Optional</span>';
        } else if (ep.auth_type === 'webhook_signature') {
            authBadge = '<span class="auth-badge auth-webhook">Signature</span>';
        }

        let detail = '';

        // Description
        if (ep.description) {
            detail += '<div class="detail-row"><div class="detail-label">Description</div><div class="detail-value">' + escHtml(ep.description) + '</div></div>';
        }

        // Full URL
        detail += '<div class="detail-row"><div class="detail-label">Full URL</div>';
        detail += '<div class="full-url" onclick="copyUrl(this, event)" title="Click to copy">' + BASE_URL + ep.path + '<span class="copy-hint">click to copy</span></div></div>';

        // Query params
        if (ep.query_params && ep.query_params.length > 0) {
            detail += '<div class="detail-row"><div class="detail-label">Query Parameters</div><div class="param-list">';
            for (const p of ep.query_params) {
                detail += '<span class="param-tag">' + escHtml(p) + '</span>';
            }
            detail += '</div></div>';
        }

        // Body fields
        if (ep.body_fields && ep.body_fields.length > 0) {
            detail += '<div class="detail-row"><div class="detail-label">Request Body Fields</div><div class="param-list">';
            for (const f of ep.body_fields) {
                detail += '<span class="param-tag">' + escHtml(f) + '</span>';
            }
            detail += '</div></div>';
        }

        // Auth info
        if (ep.auth_type) {
            let authDesc = '';
            switch (ep.auth_type) {
                case 'admin': authDesc = 'Requires admin JWT token via Authorization header'; break;
                case 'partner': authDesc = 'Requires partner JWT token via Authorization header'; break;
                case 'customer': authDesc = 'Requires customer JWT token via Authorization header'; break;
                case 'optional': authDesc = 'Authentication is optional (works with or without JWT)'; break;
                case 'webhook_signature': authDesc = 'Requires HMAC signature verification'; break;
                default: authDesc = 'Authentication: ' + ep.auth_type;
            }
            detail += '<div class="detail-row"><div class="detail-label">Authentication</div><div class="detail-value">' + authDesc + '</div></div>';
        }

        return '<div class="endpoint">' +
            '<div class="endpoint-header" onclick="toggleEndpoint(this.parentElement)">' +
            methods +
            '<span class="endpoint-path">' + escHtml(ep.path) + '</span>' +
            authBadge +
            '<span class="expand-icon">&#9654;</span>' +
            '</div>' +
            '<div class="endpoint-detail">' + detail + '</div>' +
            '</div>';
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function debounce(fn, ms) {
        let timer;
        return function() {
            clearTimeout(timer);
            timer = setTimeout(fn, ms);
        };
    }

    // Expose globals for onclick handlers
    window.toggleEndpoint = function(el) {
        el.classList.toggle('expanded');
    };

    window.toggleCategory = function(header) {
        const toggle = header.querySelector('.category-toggle');
        const body = header.nextElementSibling;
        toggle.classList.toggle('collapsed');
        body.classList.toggle('collapsed');
    };

    window.copyUrl = function(el, e) {
        // Stop the endpoint toggle
        if (e) e.stopPropagation(); else if (window.event) window.event.stopPropagation();
        const url = el.textContent.replace('click to copy', '').trim();
        navigator.clipboard.writeText(url).then(() => {
            const toast = document.getElementById('toast');
            toast.textContent = 'Copied: ' + url;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2000);
        });
    };

    // Keyboard shortcut: / to focus search
    document.addEventListener('keydown', function(e) {
        if (e.key === '/' && document.activeElement.tagName !== 'INPUT') {
            e.preventDefault();
            document.getElementById('searchInput').focus();
        }
        if (e.key === 'Escape') {
            document.getElementById('searchInput').blur();
        }
    });
})();
</script>
</body>
</html>
