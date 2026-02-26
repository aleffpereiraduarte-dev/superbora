<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Opcoes/Complementos de Produto
 * ══════════════════════════════════════════════════════════════════════════════
 */
session_start();

if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
$db = getDB();

$mercado_id = $_SESSION['mercado_id'];
$mercado_nome = $_SESSION['mercado_nome'];
$product_id = (int)($_GET['product_id'] ?? 0);

if (!$product_id) {
    header('Location: produtos.php');
    exit;
}

// Verificar que produto pertence ao parceiro
$stmt = $db->prepare("SELECT * FROM om_market_products WHERE product_id = ? AND partner_id = ?");
$stmt->execute([$product_id, $mercado_id]);
$produto = $stmt->fetch();

if (!$produto) {
    header('Location: produtos.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Opcoes - <?= htmlspecialchars($produto['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
    <style>
    .option-group-card {
        border: 1px solid var(--om-gray-200);
        border-radius: var(--om-radius-lg);
        margin-bottom: var(--om-space-4);
        overflow: hidden;
    }
    .option-group-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: var(--om-space-4);
        background: var(--om-gray-50);
        cursor: pointer;
    }
    .option-group-header:hover {
        background: var(--om-gray-100);
    }
    .option-group-body {
        padding: var(--om-space-4);
        border-top: 1px solid var(--om-gray-200);
    }
    .option-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: var(--om-space-3);
        border-bottom: 1px solid var(--om-gray-100);
    }
    .option-item:last-child {
        border-bottom: none;
    }
    .option-info { flex: 1; }
    .option-price {
        font-weight: 600;
        color: var(--om-primary);
        margin-right: var(--om-space-3);
    }
    .group-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    .group-badge.required {
        background: var(--om-error-50, #fef2f2);
        color: var(--om-error, #dc2626);
    }
    .group-badge.optional {
        background: var(--om-gray-100);
        color: var(--om-gray-600);
    }
    </style>
</head>
<body class="om-app-layout">
    <!-- Sidebar (mesma do produtos.php) -->
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <img src="/assets/img/logo-onemundo-white.png" alt="OneMundo" class="om-sidebar-logo"
                 onerror="this.outerHTML='<span class=\'om-sidebar-logo-text\'>OneMundo</span>'">
        </div>
        <nav class="om-sidebar-nav">
            <a href="index.php" class="om-sidebar-link"><i class="lucide-layout-dashboard"></i><span>Dashboard</span></a>
            <a href="pedidos.php" class="om-sidebar-link"><i class="lucide-shopping-bag"></i><span>Pedidos</span></a>
            <a href="produtos.php" class="om-sidebar-link active"><i class="lucide-package"></i><span>Produtos</span></a>
            <a href="categorias.php" class="om-sidebar-link"><i class="lucide-tags"></i><span>Categorias</span></a>
            <a href="faturamento.php" class="om-sidebar-link"><i class="lucide-bar-chart-3"></i><span>Faturamento</span></a>
            <a href="avaliacoes.php" class="om-sidebar-link"><i class="lucide-star"></i><span>Avaliacoes</span></a>
            <a href="perfil.php" class="om-sidebar-link"><i class="lucide-settings"></i><span>Configuracoes</span></a>
        </nav>
        <div class="om-sidebar-footer">
            <a href="logout.php" class="om-sidebar-link"><i class="lucide-log-out"></i><span>Sair</span></a>
        </div>
    </aside>

    <main class="om-main-content">
        <header class="om-topbar">
            <button class="om-sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                <i class="lucide-menu"></i>
            </button>
            <h1 class="om-topbar-title">
                <a href="produtos.php" style="color: var(--om-text-muted); text-decoration: none;">Produtos</a>
                <i class="lucide-chevron-right" style="font-size:14px; margin: 0 8px; color: var(--om-gray-400);"></i>
                Opcoes
            </h1>
            <div class="om-topbar-actions">
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome) ?></span>
                </div>
            </div>
        </header>

        <div class="om-page-content">
            <!-- Produto Info -->
            <div class="om-card om-mb-6">
                <div class="om-card-body">
                    <div style="display: flex; align-items: center; gap: 16px;">
                        <div style="width:60px;height:60px;border-radius:8px;background:var(--om-gray-100);display:flex;align-items:center;justify-content:center;overflow:hidden;">
                            <?php if ($produto['image']): ?>
                            <img src="<?= htmlspecialchars($produto['image']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                            <i class="lucide-package" style="font-size:1.5rem;color:var(--om-gray-400);"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h2 style="margin:0;font-size:1.25rem;"><?= htmlspecialchars($produto['name']) ?></h2>
                            <p style="margin:4px 0 0;color:var(--om-text-muted);">R$ <?= number_format($produto['price'], 2, ',', '.') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grupos de Opcoes -->
            <div class="om-flex om-items-center om-justify-between om-mb-4">
                <h3 class="om-font-semibold">Grupos de Complementos</h3>
                <button class="om-btn om-btn-primary" onclick="abrirModalGrupo()">
                    <i class="lucide-plus"></i> Novo Grupo
                </button>
            </div>

            <div id="groupsContainer">
                <div class="om-text-center om-py-8 om-text-muted">
                    <i class="lucide-loader-2 om-animate-spin"></i> Carregando...
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Grupo -->
    <div id="modalGrupo" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalGrupo()"></div>
        <div class="om-modal-content om-modal-md">
            <div class="om-modal-header">
                <h3 class="om-modal-title" id="modalGrupoTitle">Novo Grupo de Opcoes</h3>
                <button class="om-modal-close" onclick="fecharModalGrupo()"><i class="lucide-x"></i></button>
            </div>
            <div class="om-modal-body">
                <input type="hidden" id="grupoId" value="">
                <div class="om-form-group">
                    <label class="om-label">Nome do Grupo *</label>
                    <input type="text" id="grupoNome" class="om-input" placeholder="Ex: Tamanho, Adicionais, Molho" required>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="om-form-group">
                        <label class="om-label">Minimo</label>
                        <input type="number" id="grupoMin" class="om-input" value="0" min="0">
                        <span style="font-size:0.75rem;color:var(--om-text-muted);">0 = opcional</span>
                    </div>
                    <div class="om-form-group">
                        <label class="om-label">Maximo</label>
                        <input type="number" id="grupoMax" class="om-input" value="1" min="1">
                    </div>
                </div>
                <div class="om-form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" id="grupoRequired">
                        <span>Obrigatorio (cliente deve escolher)</span>
                    </label>
                </div>
            </div>
            <div class="om-modal-footer">
                <button class="om-btn om-btn-outline" onclick="fecharModalGrupo()">Cancelar</button>
                <button class="om-btn om-btn-primary" onclick="salvarGrupo()">Salvar</button>
            </div>
        </div>
    </div>

    <!-- Modal Opcao -->
    <div id="modalOpcao" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalOpcao()"></div>
        <div class="om-modal-content om-modal-md">
            <div class="om-modal-header">
                <h3 class="om-modal-title" id="modalOpcaoTitle">Nova Opcao</h3>
                <button class="om-modal-close" onclick="fecharModalOpcao()"><i class="lucide-x"></i></button>
            </div>
            <div class="om-modal-body">
                <input type="hidden" id="opcaoId" value="">
                <input type="hidden" id="opcaoGroupId" value="">
                <div class="om-form-group">
                    <label class="om-label">Nome da Opcao *</label>
                    <input type="text" id="opcaoNome" class="om-input" placeholder="Ex: Queijo extra, Tamanho G" required>
                </div>
                <div class="om-form-group">
                    <label class="om-label">Preco Extra (R$)</label>
                    <input type="text" id="opcaoPreco" class="om-input" placeholder="0,00" value="0,00">
                    <span style="font-size:0.75rem;color:var(--om-text-muted);">0 = sem custo adicional</span>
                </div>
            </div>
            <div class="om-modal-footer">
                <button class="om-btn om-btn-outline" onclick="fecharModalOpcao()">Cancelar</button>
                <button class="om-btn om-btn-primary" onclick="salvarOpcao()">Salvar</button>
            </div>
        </div>
    </div>

    <script>
    const PRODUCT_ID = <?= $product_id ?>;

    async function api(data) {
        const res = await fetch('/api/mercado/produtos/opcoes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        return await res.json();
    }

    async function carregarGrupos() {
        try {
            const res = await fetch(`/api/mercado/produtos/opcoes.php?product_id=${PRODUCT_ID}`);
            const data = await res.json();

            if (!data.success || !data.data.groups.length) {
                document.getElementById('groupsContainer').innerHTML = `
                    <div class="om-card om-text-center om-py-8">
                        <i class="lucide-layers" style="font-size:3rem;color:var(--om-gray-300);"></i>
                        <p class="om-mt-4 om-text-muted">Nenhum grupo de opcoes cadastrado</p>
                        <button class="om-btn om-btn-primary om-mt-4" onclick="abrirModalGrupo()">
                            <i class="lucide-plus"></i> Criar Primeiro Grupo
                        </button>
                    </div>`;
                return;
            }

            let html = '';
            data.data.groups.forEach(group => {
                const reqBadge = group.required
                    ? '<span class="group-badge required">Obrigatorio</span>'
                    : '<span class="group-badge optional">Opcional</span>';

                let optionsHtml = '';
                group.options.forEach(opt => {
                    const preco = parseFloat(opt.price_extra) > 0
                        ? `<span class="option-price">+ R$ ${parseFloat(opt.price_extra).toFixed(2).replace('.', ',')}</span>`
                        : '<span style="color:var(--om-gray-400);margin-right:12px;">Gratis</span>';
                    const disponivel = opt.available == 1;
                    optionsHtml += `
                        <div class="option-item" style="${!disponivel ? 'opacity:0.5;' : ''}">
                            <div class="option-info">
                                <span class="om-font-medium">${opt.name}</span>
                                ${!disponivel ? '<span class="om-badge om-badge-error" style="margin-left:8px;">Pausado</span>' : ''}
                            </div>
                            ${preco}
                            <div class="om-btn-group">
                                <button class="om-btn om-btn-xs om-btn-ghost" onclick="toggleOpcao(${opt.id})" title="${disponivel ? 'Pausar' : 'Reativar'}">
                                    <i class="lucide-${disponivel ? 'pause' : 'play'}"></i>
                                </button>
                                <button class="om-btn om-btn-xs om-btn-ghost" onclick="editarOpcao(${opt.id}, '${opt.name.replace(/'/g, "\\'")}', ${opt.price_extra}, ${group.id})" title="Editar">
                                    <i class="lucide-pencil"></i>
                                </button>
                                <button class="om-btn om-btn-xs om-btn-ghost om-text-error" onclick="deletarOpcao(${opt.id})" title="Remover">
                                    <i class="lucide-trash-2"></i>
                                </button>
                            </div>
                        </div>`;
                });

                html += `
                    <div class="option-group-card">
                        <div class="option-group-header" onclick="this.parentElement.querySelector('.option-group-body').classList.toggle('hidden')">
                            <div>
                                <strong>${group.name}</strong>
                                ${reqBadge}
                                <span style="font-size:0.75rem;color:var(--om-gray-500);margin-left:8px;">
                                    Min: ${group.min_select} | Max: ${group.max_select} | ${group.options.length} opcoes
                                </span>
                            </div>
                            <div class="om-btn-group">
                                <button class="om-btn om-btn-sm om-btn-ghost" onclick="event.stopPropagation();adicionarOpcao(${group.id})" title="Adicionar opcao">
                                    <i class="lucide-plus"></i>
                                </button>
                                <button class="om-btn om-btn-sm om-btn-ghost" onclick="event.stopPropagation();editarGrupo(${group.id}, '${group.name.replace(/'/g, "\\'")}', ${group.required}, ${group.min_select}, ${group.max_select})" title="Editar grupo">
                                    <i class="lucide-pencil"></i>
                                </button>
                                <button class="om-btn om-btn-sm om-btn-ghost om-text-error" onclick="event.stopPropagation();deletarGrupo(${group.id})" title="Remover grupo">
                                    <i class="lucide-trash-2"></i>
                                </button>
                            </div>
                        </div>
                        <div class="option-group-body">
                            ${optionsHtml || '<p class="om-text-muted om-text-center om-py-4">Nenhuma opcao. <a href="#" onclick="adicionarOpcao(' + group.id + ');return false;">Adicionar</a></p>'}
                        </div>
                    </div>`;
            });

            document.getElementById('groupsContainer').innerHTML = html;
        } catch (e) {
            document.getElementById('groupsContainer').innerHTML =
                '<div class="om-alert om-alert-error">Erro ao carregar opcoes</div>';
        }
    }

    // === GRUPO ===
    function abrirModalGrupo() {
        document.getElementById('modalGrupoTitle').textContent = 'Novo Grupo de Opcoes';
        document.getElementById('grupoId').value = '';
        document.getElementById('grupoNome').value = '';
        document.getElementById('grupoMin').value = 0;
        document.getElementById('grupoMax').value = 1;
        document.getElementById('grupoRequired').checked = false;
        document.getElementById('modalGrupo').classList.add('open');
    }

    function editarGrupo(id, nome, required, min, max) {
        document.getElementById('modalGrupoTitle').textContent = 'Editar Grupo';
        document.getElementById('grupoId').value = id;
        document.getElementById('grupoNome').value = nome;
        document.getElementById('grupoMin').value = min;
        document.getElementById('grupoMax').value = max;
        document.getElementById('grupoRequired').checked = !!required;
        document.getElementById('modalGrupo').classList.add('open');
    }

    function fecharModalGrupo() {
        document.getElementById('modalGrupo').classList.remove('open');
    }

    async function salvarGrupo() {
        const id = document.getElementById('grupoId').value;
        const payload = {
            action: id ? 'update_group' : 'create_group',
            product_id: PRODUCT_ID,
            name: document.getElementById('grupoNome').value,
            required: document.getElementById('grupoRequired').checked ? 1 : 0,
            min_select: parseInt(document.getElementById('grupoMin').value),
            max_select: parseInt(document.getElementById('grupoMax').value)
        };
        if (id) payload.group_id = parseInt(id);

        const data = await api(payload);
        if (data.success) {
            fecharModalGrupo();
            carregarGrupos();
        } else {
            alert(data.message);
        }
    }

    async function deletarGrupo(id) {
        if (!confirm('Remover este grupo e todas as opcoes?')) return;
        const data = await api({ action: 'delete_group', group_id: id });
        if (data.success) carregarGrupos();
        else alert(data.message);
    }

    // === OPCAO ===
    function adicionarOpcao(groupId) {
        document.getElementById('modalOpcaoTitle').textContent = 'Nova Opcao';
        document.getElementById('opcaoId').value = '';
        document.getElementById('opcaoGroupId').value = groupId;
        document.getElementById('opcaoNome').value = '';
        document.getElementById('opcaoPreco').value = '0,00';
        document.getElementById('modalOpcao').classList.add('open');
    }

    function editarOpcao(id, nome, preco, groupId) {
        document.getElementById('modalOpcaoTitle').textContent = 'Editar Opcao';
        document.getElementById('opcaoId').value = id;
        document.getElementById('opcaoGroupId').value = groupId;
        document.getElementById('opcaoNome').value = nome;
        document.getElementById('opcaoPreco').value = parseFloat(preco).toFixed(2).replace('.', ',');
        document.getElementById('modalOpcao').classList.add('open');
    }

    function fecharModalOpcao() {
        document.getElementById('modalOpcao').classList.remove('open');
    }

    async function salvarOpcao() {
        const id = document.getElementById('opcaoId').value;
        const precoStr = document.getElementById('opcaoPreco').value.replace(',', '.');
        const payload = {
            action: id ? 'update_option' : 'create_option',
            group_id: parseInt(document.getElementById('opcaoGroupId').value),
            name: document.getElementById('opcaoNome').value,
            price_extra: parseFloat(precoStr) || 0
        };
        if (id) payload.option_id = parseInt(id);

        const data = await api(payload);
        if (data.success) {
            fecharModalOpcao();
            carregarGrupos();
        } else {
            alert(data.message);
        }
    }

    async function toggleOpcao(id) {
        const data = await api({ action: 'toggle_option', option_id: id });
        if (data.success) carregarGrupos();
        else alert(data.message);
    }

    async function deletarOpcao(id) {
        if (!confirm('Remover esta opcao?')) return;
        const data = await api({ action: 'delete_option', option_id: id });
        if (data.success) carregarGrupos();
        else alert(data.message);
    }

    // ESC fecha modais
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { fecharModalGrupo(); fecharModalOpcao(); }
    });

    // Mascara preco
    document.getElementById('opcaoPreco').addEventListener('input', function(e) {
        let v = e.target.value.replace(/\D/g, '');
        if (v) { v = (parseInt(v) / 100).toFixed(2); e.target.value = v.replace('.', ','); }
    });

    // Carregar ao iniciar
    carregarGrupos();
    </script>
</body>
</html>
