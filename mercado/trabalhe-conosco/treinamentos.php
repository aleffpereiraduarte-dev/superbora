<?php
session_start();
require_once __DIR__ . '/includes/theme.php';

$cursos = [
    ['id' => 1, 'title' => 'Boas Práticas de Entrega', 'desc' => 'Aprenda como fazer entregas perfeitas', 'duration' => '15 min', 'modules' => 4, 'completed' => true, 'reward' => 20],
    ['id' => 2, 'title' => 'Atendimento ao Cliente', 'desc' => 'Técnicas de comunicação eficaz', 'duration' => '20 min', 'modules' => 5, 'completed' => false, 'progress' => 60, 'reward' => 25],
    ['id' => 3, 'title' => 'Segurança no Trânsito', 'desc' => 'Direção defensiva e prevenção', 'duration' => '25 min', 'modules' => 6, 'completed' => false, 'progress' => 0, 'reward' => 30],
    ['id' => 4, 'title' => 'Manuseio de Produtos', 'desc' => 'Cuidados com diferentes tipos de produtos', 'duration' => '12 min', 'modules' => 3, 'completed' => false, 'progress' => 0, 'reward' => 15],
    ['id' => 5, 'title' => 'Uso do Aplicativo', 'desc' => 'Domine todas as funcionalidades', 'duration' => '10 min', 'modules' => 4, 'completed' => false, 'progress' => 0, 'reward' => 10],
];

$completedCount = count(array_filter($cursos, fn($c) => $c['completed']));
$totalCourses = count($cursos);

pageStart('Treinamentos');
echo renderHeader('Treinamentos');
?>
<main class="main">
    <!-- Hero Card -->
    <div class="hero-card purple" style="text-align: center; margin-bottom: 24px;">
        <div style="font-size: 48px; margin-bottom: 8px;"><?= icon('book') ?></div>
        <div style="font-size: 14px; opacity: .9; margin-bottom: 4px;">Seu progresso</div>
        <div style="font-size: 42px; font-weight: 700; margin-bottom: 16px;"><?= $completedCount ?>/<?= $totalCourses ?></div>
        
        <div style="display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 16px;">
            <div style="flex: 1; max-width: 200px;">
                <div style="height: 6px; background: rgba(255,255,255,.2); border-radius: 3px; overflow: hidden;">
                    <div style="height: 100%; width: <?= ($completedCount / $totalCourses) * 100 ?>%; background: #fff; border-radius: 3px;"></div>
                </div>
            </div>
            <div style="background: rgba(255,255,255,.2); padding: 8px 16px; border-radius: 99px; display: flex; align-items: center; gap: 8px;">
                <?= icon('trophy') ?>
                <span style="font-weight: 600;">Nível Pro</span>
            </div>
        </div>
        
        <p style="font-size: 14px; opacity: .9;">Complete todos os cursos para desbloquear benefícios exclusivos!</p>
    </div>

    <!-- Tabs -->
    <div class="tabs" style="margin-bottom: 20px;">
        <button class="tab active" onclick="filterCourses('all', this)">Todos</button>
        <button class="tab" onclick="filterCourses('progress', this)">Em andamento</button>
        <button class="tab" onclick="filterCourses('completed', this)">Concluídos</button>
        <button class="tab" onclick="filterCourses('new', this)">Novos</button>
    </div>

    <!-- Course List -->
    <div id="courses">
        <?php foreach($cursos as $curso): ?>
        <div class="card course-item" 
             data-status="<?= $curso['completed'] ? 'completed' : (($curso['progress'] ?? 0) > 0 ? 'progress' : 'new') ?>"
             onclick="openCourse(<?= $curso['id'] ?>)"
             style="cursor: pointer; margin-bottom: 12px;">
            
            <div style="display: flex; gap: 16px;">
                <div style="width: 64px; height: 64px; background: <?= $curso['completed'] ? 'var(--brand-lt)' : 'var(--bg2)' ?>; border-radius: 16px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <?php if($curso['completed']): ?>
                        <div style="color: var(--brand);"><?= icon('check') ?></div>
                    <?php else: ?>
                        <div style="color: var(--txt3);"><?= icon('book') ?></div>
                    <?php endif; ?>
                </div>
                
                <div style="flex: 1; min-width: 0;">
                    <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 4px;"><?= $curso['title'] ?></h3>
                    <p style="font-size: 13px; color: var(--txt2); margin-bottom: 8px;"><?= $curso['desc'] ?></p>
                    
                    <div style="display: flex; align-items: center; gap: 12px; font-size: 13px; color: var(--txt3);">
                        <span><?= $curso['duration'] ?></span>
                        <span>📚 <?= $curso['modules'] ?> módulos</span>
                    </div>
                </div>
            </div>
            
            <?php if(!$curso['completed'] && ($curso['progress'] ?? 0) > 0): ?>
            <div style="margin-top: 16px;">
                <div style="height: 4px; background: var(--bg3); border-radius: 2px; overflow: hidden;">
                    <div style="height: 100%; width: <?= $curso['progress'] ?>%; background: var(--brand); border-radius: 2px;"></div>
                </div>
            </div>
            <?php endif; ?>
            
            <div style="display: flex; align-items: center; gap: 12px; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
                <?php if($curso['completed']): ?>
                    <span class="badge badge-success"><?= icon('check') ?> Concluído</span>
                    <span class="badge" style="background: var(--orange-lt); color: var(--orange);">📜 Certificado</span>
                <?php elseif(($curso['progress'] ?? 0) > 0): ?>
                    <span class="badge badge-warning"><?= $curso['progress'] ?>% concluído</span>
                <?php else: ?>
                    <span class="badge badge-info">Novo</span>
                <?php endif; ?>
                
                <span class="badge" style="background: var(--brand-lt); color: var(--brand); margin-left: auto;">+R$ <?= $curso['reward'] ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</main>

<script>
function filterCourses(status, btn) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    
    document.querySelectorAll('.course-item').forEach(item => {
        const itemStatus = item.dataset.status;
        if (status === 'all' || itemStatus === status) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

function openCourse(id) {
    // Vibrar ao clicar
    if (navigator.vibrate) navigator.vibrate(30);
    alert('Abrindo curso #' + id + '...\n\nEm desenvolvimento.');
}
</script>
<?php pageEnd(); ?>
