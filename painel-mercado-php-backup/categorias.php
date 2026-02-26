<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Gestão de Categorias
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

// Processar ações
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Drag-to-reorder: AJAX handler
    if ($action === 'reorder') {
        $ids = array_map('intval', explode(',', $_POST['ids'] ?? ''));
        $ids = array_filter($ids, fn($id) => $id > 0);
        if (!empty($ids)) {
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("UPDATE om_market_categories SET sort_order = ? WHERE category_id = ? AND partner_id = ?");
                foreach ($ids as $i => $id) {
                    $stmt->execute([$i + 1, $id, $mercado_id]);
                }
                $db->commit();
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $db->rollBack();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Erro ao reordenar']);
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'IDs inválidos']);
        }
        exit;
    }

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $order = intval($_POST['sort_order'] ?? 0);

        if ($name) {
            $stmt = $db->prepare("INSERT INTO om_market_categories (partner_id, name, description, sort_order, status, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
            $stmt->execute([$mercado_id, $name, $description, $order]);
            $message = 'Categoria criada com sucesso';
        } else {
            $error = 'Nome da categoria é obrigatório';
        }
    }

    if ($action === 'update') {
        $category_id = intval($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $order = intval($_POST['sort_order'] ?? 0);

        if ($name && $category_id) {
            $stmt = $db->prepare("UPDATE om_market_categories SET name = ?, description = ?, sort_order = ? WHERE category_id = ? AND partner_id = ?");
            $stmt->execute([$name, $description, $order, $category_id, $mercado_id]);
            $message = 'Categoria atualizada';
        } else {
            $error = 'Nome da categoria é obrigatório';
        }
    }

    if ($action === 'delete') {
        $category_id = intval($_POST['category_id'] ?? 0);

        // Verificar se há produtos vinculados
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_products WHERE category_id = ? AND partner_id = ?");
        $stmt->execute([$category_id, $mercado_id]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            $error = "Não é possível excluir. Existem $count produtos nesta categoria.";
        } else {
            $stmt = $db->prepare("DELETE FROM om_market_categories WHERE category_id = ? AND partner_id = ?");
            $stmt->execute([$category_id, $mercado_id]);
            $message = 'Categoria excluída';
        }
    }

    if ($action === 'toggle_status') {
        $category_id = intval($_POST['category_id'] ?? 0);
        $stmt = $db->prepare("UPDATE om_market_categories SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END WHERE category_id = ? AND partner_id = ?");
        $stmt->execute([$category_id, $mercado_id]);
        $message = 'Status atualizado';
    }
}

// Buscar categorias
$stmt = $db->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM om_market_products WHERE category_id = c.category_id) as product_count
    FROM om_market_categories c
    WHERE c.partner_id = ?
    ORDER BY c.sort_order ASC, c.name ASC
");
$stmt->execute([$mercado_id]);
$categorias = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorias - Painel do Mercado</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
</head>
<body class="om-app-layout">
    <!-- Sidebar -->
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <img src="/assets/img/logo-onemundo-white.png" alt="OneMundo" class="om-sidebar-logo"
                 onerror="this.outerHTML='<span class=\'om-sidebar-logo-text\'>OneMundo</span>'">
        </div>

        <nav class="om-sidebar-nav">
            <a href="index.php" class="om-sidebar-link">
                <i class="lucide-layout-dashboard"></i>
                <span>Dashboard</span>
            </a>
            <a href="pedidos.php" class="om-sidebar-link">
                <i class="lucide-shopping-bag"></i>
                <span>Pedidos</span>
            </a>
            <a href="produtos.php" class="om-sidebar-link">
                <i class="lucide-package"></i>
                <span>Produtos</span>
            </a>
                <a href="cardapio-ia.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path><path d="M12 18v4"></path><path d="M8 22h8"></path></svg>
                    <span class="om-sidebar-link-text">Cardapio IA</span>
                    <span style="background:#8b5cf6;color:#fff;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:700;">NOVO</span>
                </a>
            <a href="categorias.php" class="om-sidebar-link active">
                <i class="lucide-tags"></i>
                <span>Categorias</span>
            </a>
            <a href="faturamento.php" class="om-sidebar-link">
                <i class="lucide-bar-chart-3"></i>
                <span>Faturamento</span>
            </a>
            <a href="repasses.php" class="om-sidebar-link">
                <i class="lucide-wallet"></i>
                <span>Repasses</span>
            </a>
            <a href="avaliacoes.php" class="om-sidebar-link">
                <i class="lucide-star"></i>
                <span>Avaliacoes</span>
            </a>
            <a href="horarios.php" class="om-sidebar-link">
                <i class="lucide-clock"></i>
                <span>Horários</span>
            </a>
            <a href="perfil.php" class="om-sidebar-link">
                <i class="lucide-settings"></i>
                <span>Configurações</span>
            </a>
        </nav>

        <div class="om-sidebar-footer">
            <a href="logout.php" class="om-sidebar-link">
                <i class="lucide-log-out"></i>
                <span>Sair</span>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="om-main-content">
        <!-- Topbar -->
        <header class="om-topbar">
            <button class="om-sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                <i class="lucide-menu"></i>
            </button>

            <h1 class="om-topbar-title">Categorias</h1>

            <div class="om-topbar-actions">
                <button class="om-btn om-btn-primary" onclick="abrirModal()">
                    <i class="lucide-plus"></i> Nova Categoria
                </button>
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($mercado_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="om-page-content">
            <?php if ($message): ?>
            <div class="om-alert om-alert-success om-mb-4">
                <div class="om-alert-content">
                    <div class="om-alert-message"><?= htmlspecialchars($message) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="om-alert om-alert-error om-mb-4">
                <div class="om-alert-content">
                    <div class="om-alert-message"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="om-card">
                <div class="om-card-header">
                    <h3 class="om-card-title">Suas Categorias</h3>
                    <span class="om-badge om-badge-neutral"><?= count($categorias) ?> categorias</span>
                </div>

                <?php if (empty($categorias)): ?>
                <div class="om-card-body">
                    <div class="om-empty-state om-py-12">
                        <i class="lucide-tags om-text-4xl om-text-muted"></i>
                        <p class="om-mt-2">Nenhuma categoria cadastrada</p>
                        <p class="om-text-muted om-text-sm">Crie categorias para organizar seus produtos</p>
                        <button class="om-btn om-btn-primary om-mt-4" onclick="abrirModal()">
                            <i class="lucide-plus"></i> Criar Primeira Categoria
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <div class="om-table-responsive">
                    <table class="om-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;"></th>
                                <th>Nome</th>
                                <th>Descrição</th>
                                <th class="om-text-center">Produtos</th>
                                <th class="om-text-center">Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody id="categoryList">
                            <?php foreach ($categorias as $cat): ?>
                            <tr class="cat-row" data-id="<?= $cat['category_id'] ?>">
                                <td>
                                    <span class="drag-handle" title="Arraste para reordenar">
                                        <i class="lucide-grip-vertical"></i>
                                    </span>
                                </td>
                                <td>
                                    <span class="om-font-semibold"><?= htmlspecialchars($cat['name']) ?></span>
                                </td>
                                <td>
                                    <span class="om-text-muted"><?= htmlspecialchars($cat['description'] ?? '-') ?></span>
                                </td>
                                <td class="om-text-center">
                                    <a href="produtos.php?categoria=<?= $cat['category_id'] ?>" class="om-badge om-badge-primary">
                                        <?= $cat['product_count'] ?> produtos
                                    </a>
                                </td>
                                <td class="om-text-center">
                                    <form method="POST" class="om-inline-form">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="category_id" value="<?= $cat['category_id'] ?>">
                                        <button type="submit" class="om-switch <?= $cat['status'] ? 'active' : '' ?>">
                                            <span class="om-switch-slider"></span>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <div class="om-btn-group">
                                        <button class="om-btn om-btn-sm om-btn-ghost" onclick="editarCategoria(<?= htmlspecialchars(json_encode($cat)) ?>)" title="Editar">
                                            <i class="lucide-pencil"></i>
                                        </button>
                                        <?php if ($cat['product_count'] == 0): ?>
                                        <button class="om-btn om-btn-sm om-btn-ghost om-text-error" onclick="confirmarExclusao(<?= $cat['category_id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>')" title="Excluir">
                                            <i class="lucide-trash-2"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="reorderStatus" class="om-reorder-status" style="display: none;">
                        <i class="lucide-check"></i> Ordem salva
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal Categoria -->
    <div id="modalCategoria" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModal()"></div>
        <div class="om-modal-content">
            <div class="om-modal-header">
                <h3 class="om-modal-title" id="modalTitle">Nova Categoria</h3>
                <button class="om-modal-close" onclick="fecharModal()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <form method="POST" id="formCategoria">
                <div class="om-modal-body">
                    <input type="hidden" name="action" id="categoriaAction" value="create">
                    <input type="hidden" name="category_id" id="categoriaId" value="">

                    <div class="om-form-group">
                        <label class="om-label">Nome da Categoria *</label>
                        <input type="text" name="name" id="categoriaNome" class="om-input" required placeholder="Ex: Bebidas, Laticínios, Hortifruti...">
                    </div>

                    <div class="om-form-group">
                        <label class="om-label">Descrição</label>
                        <textarea name="description" id="categoriaDescricao" class="om-input" rows="2" placeholder="Descrição opcional"></textarea>
                    </div>

                    <input type="hidden" name="sort_order" id="categoriaOrdem" value="0">
                </div>

                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Confirmar Exclusão -->
    <div id="modalExclusao" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalExclusao()"></div>
        <div class="om-modal-content om-modal-sm">
            <div class="om-modal-header">
                <h3 class="om-modal-title">Confirmar Exclusão</h3>
                <button class="om-modal-close" onclick="fecharModalExclusao()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <form method="POST">
                <div class="om-modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="category_id" id="excluirCategoriaId">

                    <p class="om-text-center">
                        <i class="lucide-alert-triangle om-text-4xl om-text-warning"></i>
                    </p>
                    <p class="om-text-center om-mt-4">
                        Tem certeza que deseja excluir a categoria<br>
                        <strong id="excluirCategoriaNome"></strong>?
                    </p>
                </div>

                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModalExclusao()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-error">Excluir</button>
                </div>
            </form>
        </div>
    </div>

    <style>
    .om-inline-form { display: inline; }

    /* Drag handle */
    .drag-handle {
        cursor: grab;
        color: var(--om-gray-400);
        padding: 4px 8px;
        display: inline-flex;
        align-items: center;
        border-radius: var(--om-radius-sm);
        transition: color 0.15s, background 0.15s;
    }
    .drag-handle:hover {
        color: var(--om-gray-700);
        background: var(--om-gray-100);
    }
    .drag-handle:active {
        cursor: grabbing;
    }

    /* SortableJS ghost/chosen */
    .cat-row.sortable-ghost {
        background: var(--om-primary-50);
        opacity: 0.6;
    }
    .cat-row.sortable-chosen {
        background: var(--om-primary-50);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    .cat-row.sortable-drag {
        opacity: 0.9;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    }

    /* Reorder status toast */
    .om-reorder-status {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        margin-top: 8px;
        font-size: 0.85rem;
        color: var(--om-success);
        animation: fadeInOut 2s forwards;
    }
    @keyframes fadeInOut {
        0% { opacity: 0; transform: translateY(-4px); }
        15% { opacity: 1; transform: translateY(0); }
        70% { opacity: 1; }
        100% { opacity: 0; }
    }
    </style>

    <script>
    function abrirModal() {
        document.getElementById('modalTitle').textContent = 'Nova Categoria';
        document.getElementById('categoriaAction').value = 'create';
        document.getElementById('formCategoria').reset();
        document.getElementById('categoriaId').value = '';
        document.getElementById('modalCategoria').classList.add('open');
    }

    function editarCategoria(cat) {
        document.getElementById('modalTitle').textContent = 'Editar Categoria';
        document.getElementById('categoriaAction').value = 'update';
        document.getElementById('categoriaId').value = cat.category_id;
        document.getElementById('categoriaNome').value = cat.name;
        document.getElementById('categoriaDescricao').value = cat.description || '';
        document.getElementById('categoriaOrdem').value = cat.sort_order || 0;
        document.getElementById('modalCategoria').classList.add('open');
    }

    function fecharModal() {
        document.getElementById('modalCategoria').classList.remove('open');
    }

    function confirmarExclusao(id, nome) {
        document.getElementById('excluirCategoriaId').value = id;
        document.getElementById('excluirCategoriaNome').textContent = nome;
        document.getElementById('modalExclusao').classList.add('open');
    }

    function fecharModalExclusao() {
        document.getElementById('modalExclusao').classList.remove('open');
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            fecharModal();
            fecharModalExclusao();
        }
    });
    </script>

    <!-- SortableJS for drag-to-reorder -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script>
    (function() {
        const list = document.getElementById('categoryList');
        if (!list) return;

        let reorderTimeout = null;

        new Sortable(list, {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            easing: 'cubic-bezier(0.25, 1, 0.5, 1)',
            onEnd: function(evt) {
                const ids = [...document.querySelectorAll('.cat-row')].map(el => el.dataset.id);
                const statusEl = document.getElementById('reorderStatus');

                fetch('categorias.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=reorder&ids=' + ids.join(',')
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Show saved toast
                        statusEl.style.display = 'flex';
                        statusEl.style.animation = 'none';
                        statusEl.offsetHeight; // reflow
                        statusEl.style.animation = 'fadeInOut 2s forwards';
                        clearTimeout(reorderTimeout);
                        reorderTimeout = setTimeout(() => { statusEl.style.display = 'none'; }, 2100);
                    }
                })
                .catch(() => {
                    // Reload on error so order stays in sync
                    location.reload();
                });
            }
        });
    })();
    </script>
</body>
</html>
