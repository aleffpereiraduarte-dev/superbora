<?php
require_once __DIR__ . '/includes/theme.php';

$action = $_GET['action'] ?? 'view'; // view, accept
$type = $_GET['type'] ?? 'terms'; // terms, privacy, contract

$titles = [
    'terms' => 'Termos de Uso',
    'privacy' => 'Política de Privacidade', 
    'contract' => 'Contrato de Parceria'
];

pageStart($titles[$type] ?? 'Termos');
echo renderHeader($titles[$type] ?? 'Termos');
?>
<style>
.doc-header {
    text-align: center;
    padding: 24px 0;
    border-bottom: 1px solid var(--border);
    margin-bottom: 24px;
}
.doc-icon {
    width: 64px;
    height: 64px;
    background: var(--brand-lt);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}
.doc-icon svg {
    width: 32px;
    height: 32px;
    color: var(--brand);
}
.doc-title {
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 4px;
}
.doc-version {
    font-size: 13px;
    color: var(--txt3);
}
.doc-content {
    font-size: 14px;
    line-height: 1.7;
    color: var(--txt2);
}
.doc-content h3 {
    font-size: 16px;
    font-weight: 600;
    color: var(--txt);
    margin: 24px 0 12px;
}
.doc-content p {
    margin-bottom: 12px;
}
.doc-content ul {
    margin: 12px 0;
    padding-left: 20px;
}
.doc-content li {
    margin-bottom: 8px;
}
.accept-section {
    position: sticky;
    bottom: 0;
    background: var(--bg);
    padding: 20px;
    border-top: 1px solid var(--border);
    margin: 24px -20px -20px;
}
.checkbox-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 16px;
}
.checkbox {
    width: 24px;
    height: 24px;
    border: 2px solid var(--border);
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    margin-top: 2px;
}
.checkbox.checked {
    background: var(--brand);
    border-color: var(--brand);
}
.checkbox.checked svg {
    color: white;
}
.checkbox svg {
    width: 14px;
    height: 14px;
    color: transparent;
}
.checkbox-label {
    font-size: 14px;
    color: var(--txt);
    cursor: pointer;
}
</style>

<main class="main">
    <div class="doc-header">
        <div class="doc-icon"><?= icon('document') ?></div>
        <h2 class="doc-title"><?= $titles[$type] ?></h2>
        <p class="doc-version">Versão 2.1 • Atualizado em 01/12/2024</p>
    </div>

    <div class="doc-content">
        <?php if ($type === 'terms'): ?>
        <h3>1. Aceitação dos Termos</h3>
        <p>Ao utilizar o aplicativo OneMundo Driver, você concorda com estes Termos de Uso. Se não concordar, não utilize o aplicativo.</p>

        <h3>2. Cadastro e Conta</h3>
        <p>Para utilizar nossos serviços, você deve:</p>
        <ul>
            <li>Ter pelo menos 18 anos de idade</li>
            <li>Possuir CNH válida (para entregas com veículo)</li>
            <li>Fornecer informações verdadeiras e atualizadas</li>
            <li>Manter a confidencialidade de sua senha</li>
        </ul>

        <h3>3. Serviços de Entrega</h3>
        <p>Como entregador parceiro, você é responsável por:</p>
        <ul>
            <li>Realizar entregas de forma segura e pontual</li>
            <li>Manter comunicação clara com clientes e lojas</li>
            <li>Seguir as instruções de coleta e entrega</li>
            <li>Reportar problemas imediatamente</li>
        </ul>

        <h3>4. Pagamentos</h3>
        <p>Os pagamentos são processados conforme as entregas realizadas. O valor de cada entrega é exibido antes da aceitação e inclui:</p>
        <ul>
            <li>Taxa base da entrega</li>
            <li>Adicional por distância</li>
            <li>Bônus e promoções aplicáveis</li>
            <li>Gorjetas dos clientes (100% para você)</li>
        </ul>

        <h3>5. Cancelamentos</h3>
        <p>Cancelamentos frequentes podem resultar em penalidades, incluindo suspensão temporária ou permanente da conta.</p>

        <h3>6. Conduta</h3>
        <p>É proibido:</p>
        <ul>
            <li>Qualquer forma de discriminação</li>
            <li>Uso de substâncias que prejudiquem a direção</li>
            <li>Compartilhamento de conta com terceiros</li>
            <li>Fraudes ou manipulação do sistema</li>
        </ul>

        <?php elseif ($type === 'privacy'): ?>
        <h3>1. Dados Coletados</h3>
        <p>Coletamos os seguintes dados para prestação dos serviços:</p>
        <ul>
            <li>Dados pessoais: nome, CPF, endereço, telefone</li>
            <li>Documentos: CNH, foto de perfil, documentos do veículo</li>
            <li>Localização: GPS durante entregas</li>
            <li>Dados de uso: histórico de entregas, avaliações</li>
        </ul>

        <h3>2. Uso dos Dados</h3>
        <p>Seus dados são utilizados para:</p>
        <ul>
            <li>Processar e gerenciar entregas</li>
            <li>Calcular rotas e tempo estimado</li>
            <li>Realizar pagamentos</li>
            <li>Melhorar nossos serviços</li>
            <li>Comunicação sobre sua conta</li>
        </ul>

        <h3>3. Compartilhamento</h3>
        <p>Compartilhamos dados limitados com:</p>
        <ul>
            <li>Clientes: apenas nome e localização durante entrega</li>
            <li>Parceiros: dados necessários para operação</li>
            <li>Autoridades: quando exigido por lei</li>
        </ul>

        <h3>4. Segurança</h3>
        <p>Utilizamos criptografia e medidas de segurança para proteger seus dados. Seus documentos são armazenados de forma segura.</p>

        <h3>5. Seus Direitos</h3>
        <p>Você pode solicitar:</p>
        <ul>
            <li>Acesso aos seus dados</li>
            <li>Correção de informações</li>
            <li>Exclusão da conta</li>
            <li>Portabilidade dos dados</li>
        </ul>

        <?php else: ?>
        <h3>1. Objeto do Contrato</h3>
        <p>Este contrato estabelece a parceria entre você (Entregador Parceiro) e OneMundo para prestação de serviços de entrega.</p>

        <h3>2. Natureza da Relação</h3>
        <p>Esta é uma relação de parceria comercial, não configurando vínculo empregatício. Você tem autonomia para:</p>
        <ul>
            <li>Definir seus próprios horários</li>
            <li>Aceitar ou recusar pedidos</li>
            <li>Trabalhar com outras plataformas</li>
        </ul>

        <h3>3. Obrigações do Parceiro</h3>
        <ul>
            <li>Manter documentação regularizada</li>
            <li>Possuir veículo em bom estado</li>
            <li>Cumprir as normas de trânsito</li>
            <li>Zelar pelos produtos transportados</li>
        </ul>

        <h3>4. Remuneração</h3>
        <p>A remuneração é calculada por entrega e transferida semanalmente ou sob demanda via PIX.</p>

        <h3>5. Vigência</h3>
        <p>Este contrato tem vigência indeterminada, podendo ser rescindido por qualquer parte a qualquer momento.</p>

        <h3>6. Foro</h3>
        <p>Fica eleito o foro da comarca de São Paulo/SP para dirimir quaisquer questões oriundas deste contrato.</p>
        <?php endif; ?>
    </div>

    <?php if ($action === 'accept'): ?>
    <div class="accept-section">
        <div class="checkbox-row" onclick="toggleCheckbox(this)">
            <div class="checkbox" id="checkbox-1">
                <?= icon('check') ?>
            </div>
            <span class="checkbox-label">Li e aceito os <?= strtolower($titles[$type]) ?></span>
        </div>

        <button class="btn btn-primary" id="accept-btn" onclick="acceptTerms()" disabled>
            Aceitar e Continuar
        </button>
    </div>
    <?php endif; ?>
</main>

<script>
function toggleCheckbox(row) {
    const checkbox = row.querySelector('.checkbox');
    checkbox.classList.toggle('checked');
    
    if (navigator.vibrate) navigator.vibrate(30);
    
    // Verificar se pode habilitar botão
    const allChecked = document.querySelectorAll('.checkbox.checked').length === 
                       document.querySelectorAll('.checkbox').length;
    document.getElementById('accept-btn').disabled = !allChecked;
}

function acceptTerms() {
    // Salvar aceitação
    fetch('api/accept-terms.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            type: '<?= $type ?>',
            worker_id: <?= $_SESSION['worker_id'] ?? 0 ?>,
            accepted_at: new Date().toISOString()
        })
    });
    
    if (navigator.vibrate) navigator.vibrate([50, 30, 50]);
    
    // Redirecionar
    const next = new URLSearchParams(window.location.search).get('next') || 'app.php';
    location.href = next;
}
</script>
<?php pageEnd(); ?>
