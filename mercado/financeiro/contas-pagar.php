<?php
session_start();
if(!isset($_SESSION["fin_user_id"])){header("Location: index.php");exit;}
$userName=$_SESSION["fin_user_name"];
$userRole=$_SESSION["fin_user_role"];
$perms=$_SESSION["fin_permissions"];
$currentPage = basename($_SERVER["PHP_SELF"], ".php");
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? "Financeiro"; ?> - OneMundo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{--primary:#22c55e;--danger:#ef4444;--warning:#f59e0b;--info:#3b82f6;--purple:#8b5cf6;--bg:#0f172a;--card:#1e293b;--border:#334155;--text:#f1f5f9;--text2:#94a3b8}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
        .sidebar{position:fixed;left:0;top:0;width:250px;height:100vh;background:var(--card);border-right:1px solid var(--border);padding:20px 0;overflow-y:auto;z-index:100}
        .sidebar-logo{padding:0 20px 20px;border-bottom:1px solid var(--border);margin-bottom:20px;display:flex;align-items:center;gap:12px}
        .sidebar-logo .icon{width:45px;height:45px;background:linear-gradient(135deg,var(--primary),#16a34a);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff}
        .sidebar-logo h2{font-size:16px}
        .sidebar-logo span{font-size:12px;color:var(--text2)}
        .nav-section{padding:0 15px;margin-bottom:15px}
        .nav-title{font-size:11px;text-transform:uppercase;color:var(--text2);padding:10px;font-weight:600}
        .nav-item{display:flex;align-items:center;gap:12px;padding:12px 15px;color:var(--text2);text-decoration:none;border-radius:8px;margin-bottom:2px;transition:all 0.2s}
        .nav-item:hover{background:var(--border);color:var(--text)}
        .nav-item.active{background:linear-gradient(135deg,var(--primary),#16a34a);color:#fff}
        .nav-item i{width:20px;text-align:center}
        .main{margin-left:250px;padding:20px 30px;min-height:100vh}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:25px;padding-bottom:20px;border-bottom:1px solid var(--border)}
        .header h1{font-size:24px;display:flex;align-items:center;gap:12px}
        .header h1 i{color:var(--primary)}
        .header-right{display:flex;align-items:center;gap:15px}
        .btn{padding:10px 20px;border-radius:8px;border:none;cursor:pointer;font-size:14px;display:inline-flex;align-items:center;gap:8px;transition:all 0.2s;text-decoration:none}
        .btn-primary{background:linear-gradient(135deg,var(--primary),#16a34a);color:#fff}
        .btn-secondary{background:var(--card);color:var(--text);border:1px solid var(--border)}
        .btn-danger{background:var(--danger);color:#fff}
        .btn:hover{transform:translateY(-2px);box-shadow:0 5px 15px rgba(0,0,0,0.3)}
        .card{background:var(--card);border-radius:12px;padding:20px;border:1px solid var(--border);margin-bottom:20px}
        .card-title{font-size:16px;margin-bottom:15px;display:flex;align-items:center;gap:10px}
        .card-title i{color:var(--primary)}
        .stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:20px}
        .stat-card{background:var(--card);padding:20px;border-radius:12px;border:1px solid var(--border)}
        .stat-value{font-size:28px;font-weight:700}
        .stat-label{font-size:13px;color:var(--text2);margin-top:5px}
        .table{width:100%;border-collapse:collapse}
        .table th,.table td{padding:12px 15px;text-align:left;border-bottom:1px solid var(--border)}
        .table th{color:var(--text2);font-size:12px;text-transform:uppercase;font-weight:600}
        .table tr:hover{background:rgba(255,255,255,0.02)}
        .badge{padding:4px 10px;border-radius:6px;font-size:11px;font-weight:500}
        .badge-success{background:rgba(34,197,94,0.15);color:var(--primary)}
        .badge-danger{background:rgba(239,68,68,0.15);color:var(--danger)}
        .badge-warning{background:rgba(245,158,11,0.15);color:var(--warning)}
        .badge-info{background:rgba(59,130,246,0.15);color:var(--info)}
        .filters{display:flex;gap:15px;margin-bottom:20px;flex-wrap:wrap}
        .filter-group{display:flex;flex-direction:column;gap:5px}
        .filter-group label{font-size:12px;color:var(--text2)}
        .filter-group select,.filter-group input{padding:10px 15px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:14px}
        .modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:1000;align-items:center;justify-content:center}
        .modal.active{display:flex}
        .modal-content{background:var(--card);border-radius:16px;padding:30px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto}
        .modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
        .modal-header h2{font-size:20px}
        .modal-close{background:none;border:none;color:var(--text2);font-size:24px;cursor:pointer}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px}
        .form-group{display:flex;flex-direction:column;gap:5px}
        .form-group.full{grid-column:1/-1}
        .form-group label{font-size:13px;color:var(--text2)}
        .form-group input,.form-group select,.form-group textarea{padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:14px}
        .form-group textarea{min-height:80px;resize:vertical}
        .modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:20px;border-top:1px solid var(--border)}
        .empty-state{text-align:center;padding:60px 20px;color:var(--text2)}
        .empty-state i{font-size:48px;margin-bottom:15px;opacity:0.5}
        .actions{display:flex;gap:5px}
        .actions button{padding:6px 10px;border:none;border-radius:6px;cursor:pointer;font-size:12px}
        .btn-view{background:var(--info);color:#fff}
        .btn-edit{background:var(--warning);color:#fff}
        .btn-delete{background:var(--danger);color:#fff}
        .btn-pay{background:var(--primary);color:#fff}
        .alert{padding:15px;border-radius:8px;margin-bottom:20px;display:none}
        .alert-success{background:rgba(34,197,94,0.1);border:1px solid var(--primary);color:var(--primary)}
        .alert-error{background:rgba(239,68,68,0.1);border:1px solid var(--danger);color:var(--danger)}
        @media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.form-row{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-logo">
            <div class="icon"><i class="fas fa-dollar-sign"></i></div>
            <div><h2>Financeiro</h2><span>OneMundo</span></div>
        </div>
        <nav class="nav-section">
            <div class="nav-title">Principal</div>
            <a href="dashboard.php" class="nav-item <?php echo $currentPage=="dashboard"?"active":""; ?>"><i class="fas fa-home"></i> Dashboard</a>
        </nav>
        <nav class="nav-section">
            <div class="nav-title">Operações</div>
            <a href="contas-pagar.php" class="nav-item <?php echo $currentPage=="contas-pagar"?"active":""; ?>"><i class="fas fa-file-invoice-dollar"></i> Contas a Pagar</a>
            <a href="contas-receber.php" class="nav-item <?php echo $currentPage=="contas-receber"?"active":""; ?>"><i class="fas fa-hand-holding-usd"></i> Contas a Receber</a>
            <a href="tesouraria.php" class="nav-item <?php echo $currentPage=="tesouraria"?"active":""; ?>"><i class="fas fa-university"></i> Tesouraria</a>
        </nav>
        <nav class="nav-section">
            <div class="nav-title">Cadastros</div>
            <a href="fornecedores.php" class="nav-item <?php echo $currentPage=="fornecedores"?"active":""; ?>"><i class="fas fa-truck"></i> Fornecedores</a>
            <a href="centros-custo.php" class="nav-item <?php echo $currentPage=="centros-custo"?"active":""; ?>"><i class="fas fa-sitemap"></i> Centros de Custo</a>
            <a href="categorias.php" class="nav-item <?php echo $currentPage=="categorias"?"active":""; ?>"><i class="fas fa-tags"></i> Categorias</a>
        </nav>
        <nav class="nav-section">
            <div class="nav-title">Relatórios</div>
            <a href="relatorios.php" class="nav-item <?php echo $currentPage=="relatorios"?"active":""; ?>"><i class="fas fa-chart-bar"></i> Relatórios</a>
        </nav>
        <nav class="nav-section" style="margin-top:auto;padding-top:20px;border-top:1px solid var(--border)">
            <a href="#" onclick="logout()" class="nav-item"><i class="fas fa-sign-out-alt"></i> Sair</a>
        </nav>
    </aside>
    <main class="main">
        <?php $pageTitle = "Contas a Pagar"; ?>
        <header class="header">
            <h1><i class="fas fa-file-invoice-dollar"></i> Contas a Pagar</h1>
            <div class="header-right">
                <?php if($perms["create"]): ?>
                <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Nova Despesa</button>
                <?php endif; ?>
            </div>
        </header>
        
        <div class="alert alert-success" id="alertSuccess"></div>
        <div class="alert alert-error" id="alertError"></div>
        
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-value" id="statTotal">0</div>
                <div class="stat-label">Total de Contas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:var(--warning)" id="statPendente">R$ 0</div>
                <div class="stat-label">Valor Pendente</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:var(--danger)" id="statAtrasado">R$ 0</div>
                <div class="stat-label">Valor Atrasado</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:var(--primary)" id="statPago">R$ 0</div>
                <div class="stat-label">Pago este Mês</div>
            </div>
        </div>
        
        <div class="filters">
            <div class="filter-group">
                <label>Status</label>
                <select id="filterStatus" onchange="loadPayables()">
                    <option value="">Todos</option>
                    <option value="pendente">Pendente</option>
                    <option value="aguardando_aprovacao">Aguardando Aprovação</option>
                    <option value="aprovado">Aprovado</option>
                    <option value="pago">Pago</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Data Início</label>
                <input type="date" id="filterDateFrom" onchange="loadPayables()">
            </div>
            <div class="filter-group">
                <label>Data Fim</label>
                <input type="date" id="filterDateTo" onchange="loadPayables()">
            </div>
        </div>
        
        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>Fornecedor</th>
                        <th>Descrição</th>
                        <th>Vencimento</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="6" class="empty-state"><i class="fas fa-spinner fa-spin"></i><br>Carregando...</td></tr>
                </tbody>
            </table>
        </div>
        
        <!-- Modal Nova Despesa -->
        <div class="modal" id="modalDespesa">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="modalTitle">Nova Despesa</h2>
                    <button class="modal-close" onclick="closeModal()">&times;</button>
                </div>
                <form id="formDespesa">
                    <input type="hidden" id="payable_id">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Fornecedor *</label>
                            <input type="text" id="supplier_name" required placeholder="Nome do fornecedor">
                        </div>
                        <div class="form-group">
                            <label>Nº Documento</label>
                            <input type="text" id="document_number" placeholder="NF, Boleto, etc">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Categoria</label>
                            <select id="category_id"></select>
                        </div>
                        <div class="form-group">
                            <label>Centro de Custo</label>
                            <select id="cost_center_id"></select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Valor Bruto *</label>
                            <input type="number" step="0.01" id="gross_value" required placeholder="0,00">
                        </div>
                        <div class="form-group">
                            <label>Desconto</label>
                            <input type="number" step="0.01" id="discount" value="0" placeholder="0,00">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Data Vencimento *</label>
                            <input type="date" id="due_date" required>
                        </div>
                        <div class="form-group">
                            <label>Forma de Pagamento</label>
                            <select id="payment_method">
                                <option value="">Selecione...</option>
                                <option value="boleto">Boleto</option>
                                <option value="pix">PIX</option>
                                <option value="ted">TED</option>
                                <option value="debito">Débito</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group full">
                        <label>Descrição</label>
                        <textarea id="description" placeholder="Detalhes da despesa..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Modal Pagar -->
        <div class="modal" id="modalPagar">
            <div class="modal-content" style="max-width:450px">
                <div class="modal-header">
                    <h2>Registrar Pagamento</h2>
                    <button class="modal-close" onclick="closeModalPagar()">&times;</button>
                </div>
                <form id="formPagar">
                    <input type="hidden" id="pay_payable_id">
                    <div class="form-group">
                        <label>Valor a Pagar *</label>
                        <input type="number" step="0.01" id="pay_value" required>
                    </div>
                    <div class="form-group">
                        <label>Data do Pagamento *</label>
                        <input type="date" id="pay_date" required>
                    </div>
                    <div class="form-group">
                        <label>Conta Bancária</label>
                        <select id="pay_bank_account"></select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModalPagar()">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Confirmar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
            async function loadStats() {
                const r = await fetch("api/payables.php?action=getStats");
                const d = await r.json();
                if (d.success) {
                    document.getElementById("statTotal").textContent = d.stats.total;
                    document.getElementById("statPendente").textContent = formatMoney(d.stats.valor_pendente);
                    document.getElementById("statAtrasado").textContent = formatMoney(d.stats.valor_atrasado);
                    document.getElementById("statPago").textContent = formatMoney(d.stats.pago_mes);
                }
            }
            
            async function loadPayables() {
                const status = document.getElementById("filterStatus").value;
                const dateFrom = document.getElementById("filterDateFrom").value;
                const dateTo = document.getElementById("filterDateTo").value;
                
                let url = "api/payables.php?action=list";
                if (status) url += "&status=" + status;
                if (dateFrom) url += "&date_from=" + dateFrom;
                if (dateTo) url += "&date_to=" + dateTo;
                
                const r = await fetch(url);
                const d = await r.json();
                
                const tbody = document.getElementById("tableBody");
                if (d.success && d.payables && d.payables.length > 0) {
                    tbody.innerHTML = d.payables.map(p => {
                        let badge = "badge-info";
                        let statusText = p.status;
                        if (p.status === "pago") { badge = "badge-success"; statusText = "Pago"; }
                        else if (p.status === "pendente") { badge = "badge-warning"; statusText = "Pendente"; }
                        else if (p.status === "aguardando_aprovacao") { badge = "badge-info"; statusText = "Aguardando"; }
                        else if (p.status === "aprovado") { badge = "badge-success"; statusText = "Aprovado"; }
                        
                        if (p.status !== "pago" && new Date(p.due_date) < new Date()) {
                            badge = "badge-danger";
                            statusText = "Atrasado";
                        }
                        
                        let actions = "";
                        <?php if($perms["approve"]): ?>
                        if (p.status === "aguardando_aprovacao") {
                            actions += `<button class="btn-edit" onclick="approvePayable(${p.payable_id})" title="Aprovar"><i class="fas fa-check"></i></button>`;
                        }
                        <?php endif; ?>
                        <?php if($perms["edit"]): ?>
                        if (p.status === "aprovado" || p.status === "pendente") {
                            actions += `<button class="btn-pay" onclick="openPagar(${p.payable_id}, ${p.net_value - p.paid_value})" title="Pagar"><i class="fas fa-dollar-sign"></i></button>`;
                        }
                        <?php endif; ?>
                        
                        return `<tr>
                            <td><strong>${p.supplier_name || "N/I"}</strong></td>
                            <td>${p.description || p.document_number || "-"}</td>
                            <td>${formatDate(p.due_date)}</td>
                            <td><strong>${formatMoney(p.net_value)}</strong></td>
                            <td><span class="badge ${badge}">${statusText}</span></td>
                            <td class="actions">${actions}</td>
                        </tr>`;
                    }).join("");
                } else {
                    tbody.innerHTML = `<tr><td colspan="6" class="empty-state"><i class="fas fa-inbox"></i><br>Nenhuma conta encontrada</td></tr>`;
                }
            }
            
            async function loadCategories() {
                const r = await fetch("api/categories.php?action=list&type=expense");
                const d = await r.json();
                const sel = document.getElementById("category_id");
                sel.innerHTML = "<option value=\"\">Selecione...</option>";
                if (d.success && d.categories) {
                    d.categories.forEach(c => { sel.innerHTML += `<option value="${c.category_id}">${c.name}</option>`; });
                }
            }
            
            async function loadCostCenters() {
                const r = await fetch("api/cost-centers.php?action=list");
                const d = await r.json();
                const sel = document.getElementById("cost_center_id");
                sel.innerHTML = "<option value=\"\">Selecione...</option>";
                if (d.success && d.cost_centers) {
                    d.cost_centers.forEach(c => { sel.innerHTML += `<option value="${c.cost_center_id}">${c.code} - ${c.name}</option>`; });
                }
            }
            
            async function loadBankAccounts() {
                const r = await fetch("api/bank.php?action=getAccounts");
                const d = await r.json();
                const sel = document.getElementById("pay_bank_account");
                sel.innerHTML = "<option value=\"\">Selecione...</option>";
                if (d.success && d.accounts) {
                    d.accounts.forEach(a => { sel.innerHTML += `<option value="${a.bank_account_id}">${a.account_name}</option>`; });
                }
            }
            
            function openModal() {
                document.getElementById("formDespesa").reset();
                document.getElementById("payable_id").value = "";
                document.getElementById("modalTitle").textContent = "Nova Despesa";
                document.getElementById("modalDespesa").classList.add("active");
            }
            
            function closeModal() {
                document.getElementById("modalDespesa").classList.remove("active");
            }
            
            function openPagar(id, valor) {
                document.getElementById("pay_payable_id").value = id;
                document.getElementById("pay_value").value = valor.toFixed(2);
                document.getElementById("pay_date").value = new Date().toISOString().split("T")[0];
                document.getElementById("modalPagar").classList.add("active");
            }
            
            function closeModalPagar() {
                document.getElementById("modalPagar").classList.remove("active");
            }
            
            function showAlert(type, msg) {
                const el = document.getElementById("alert" + (type === "success" ? "Success" : "Error"));
                el.textContent = msg;
                el.style.display = "block";
                setTimeout(() => { el.style.display = "none"; }, 5000);
            }
            
            document.getElementById("formDespesa").addEventListener("submit", async function(e) {
                e.preventDefault();
                const data = {
                    payable_id: document.getElementById("payable_id").value,
                    supplier_name: document.getElementById("supplier_name").value,
                    document_number: document.getElementById("document_number").value,
                    category_id: document.getElementById("category_id").value,
                    cost_center_id: document.getElementById("cost_center_id").value,
                    gross_value: document.getElementById("gross_value").value,
                    discount: document.getElementById("discount").value || 0,
                    due_date: document.getElementById("due_date").value,
                    payment_method: document.getElementById("payment_method").value,
                    description: document.getElementById("description").value
                };
                
                const r = await fetch("api/payables.php?action=save", {
                    method: "POST",
                    headers: {"Content-Type": "application/json"},
                    body: JSON.stringify(data)
                });
                const d = await r.json();
                
                if (d.success) {
                    closeModal();
                    loadPayables();
                    loadStats();
                    showAlert("success", "Despesa salva com sucesso!");
                } else {
                    showAlert("error", d.error || "Erro ao salvar");
                }
            });
            
            document.getElementById("formPagar").addEventListener("submit", async function(e) {
                e.preventDefault();
                const formData = new FormData();
                formData.append("payable_id", document.getElementById("pay_payable_id").value);
                formData.append("paid_value", document.getElementById("pay_value").value);
                formData.append("payment_date", document.getElementById("pay_date").value);
                formData.append("bank_account_id", document.getElementById("pay_bank_account").value);
                
                const r = await fetch("api/payables.php?action=pay", { method: "POST", body: formData });
                const d = await r.json();
                
                if (d.success) {
                    closeModalPagar();
                    loadPayables();
                    loadStats();
                    showAlert("success", "Pagamento registrado!");
                } else {
                    showAlert("error", d.error || "Erro");
                }
            });
            
            async function approvePayable(id) {
                if (!confirm("Aprovar esta despesa?")) return;
                const formData = new FormData();
                formData.append("payable_id", id);
                const r = await fetch("api/payables.php?action=approve", { method: "POST", body: formData });
                const d = await r.json();
                if (d.success) {
                    loadPayables();
                    loadStats();
                    showAlert("success", "Despesa aprovada!");
                } else {
                    showAlert("error", d.error);
                }
            }
            
            // Init
            loadStats();
            loadPayables();
            loadCategories();
            loadCostCenters();
            loadBankAccounts();
        </script>

    </main>
    <script>
        function formatMoney(v){return new Intl.NumberFormat("pt-BR",{style:"currency",currency:"BRL"}).format(v||0)}
        function formatDate(d){if(!d)return"-";return new Date(d+"T00:00:00").toLocaleDateString("pt-BR")}
        async function logout(){await fetch("api/auth.php?action=logout");location.href="index.php";}
    </script>
</body>
</html>