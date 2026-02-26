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
        <?php $pageTitle = "Fornecedores"; ?>
        <header class="header">
            <h1><i class="fas fa-truck"></i> Fornecedores</h1>
            <div class="header-right">
                <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Novo Fornecedor</button>
            </div>
        </header>
        
        <div class="filters">
            <div class="filter-group">
                <label>Buscar</label>
                <input type="text" id="search" placeholder="Nome, CNPJ..." onkeyup="loadSuppliers()">
            </div>
        </div>
        
        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nome/Razão Social</th>
                        <th>CPF/CNPJ</th>
                        <th>Telefone</th>
                        <th>Cidade/UF</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="5" class="empty-state"><i class="fas fa-spinner fa-spin"></i></td></tr>
                </tbody>
            </table>
        </div>
        
        <script>
            async function loadSuppliers() {
                const search = document.getElementById("search").value;
                const r = await fetch("api/suppliers.php?action=list&search=" + encodeURIComponent(search));
                const d = await r.json();
                const tbody = document.getElementById("tableBody");
                
                if (d.success && d.suppliers && d.suppliers.length > 0) {
                    tbody.innerHTML = d.suppliers.map(s => `<tr>
                        <td><strong>${s.company_name}</strong><br><small style="color:var(--text2)">${s.trade_name || ""}</small></td>
                        <td>${s.cpf_cnpj || "-"}</td>
                        <td>${s.phone || "-"}</td>
                        <td>${s.address_city || "-"}/${s.address_state || "-"}</td>
                        <td class="actions">
                            <button class="btn-edit" title="Editar"><i class="fas fa-edit"></i></button>
                        </td>
                    </tr>`).join("");
                } else {
                    tbody.innerHTML = `<tr><td colspan="5" class="empty-state"><i class="fas fa-truck"></i><br>Nenhum fornecedor cadastrado</td></tr>`;
                }
            }
            
            function openModal() { alert("Em desenvolvimento"); }
            
            loadSuppliers();
        </script>

    </main>
    <script>
        function formatMoney(v){return new Intl.NumberFormat("pt-BR",{style:"currency",currency:"BRL"}).format(v||0)}
        function formatDate(d){if(!d)return"-";return new Date(d+"T00:00:00").toLocaleDateString("pt-BR")}
        async function logout(){await fetch("api/auth.php?action=logout");location.href="index.php";}
    </script>
</body>
</html>