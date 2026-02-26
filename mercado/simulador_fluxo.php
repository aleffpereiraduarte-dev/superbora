<?php
require_once __DIR__ . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════════╗
 * ║       🤖 SIMULADOR DE FLUXO COMPLETO - PÁGINA PRINCIPAL ATÉ ENTREGA                      ║
 * ╠══════════════════════════════════════════════════════════════════════════════════════════╣
 * ║                                                                                          ║
 * ║  Simula o fluxo REAL de um cliente:                                                      ║
 * ║                                                                                          ║
 * ║  1. Entra na página principal                                                            ║
 * ║  2. Sistema detecta CEP (logado) ou pede CEP (visitante)                                 ║
 * ║  3. Match com mercado mais próximo                                                       ║
 * ║  4. Mostra banner → Redireciona para /mercado                                            ║
 * ║  5. Vê produtos do SEU mercado (isolado)                                                 ║
 * ║  6. Adiciona ao carrinho → Checkout                                                      ║
 * ║  7. Header mostra tempo estimado (baseado em shoppers/drivers)                           ║
 * ║  8. Faz pagamento → Dispara para shopper                                                 ║
 * ║  9. Shopper coleta → Handoff → Driver entrega                                            ║
 * ║                                                                                          ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════════╝
 */

session_name('OCSESSID');
session_start();

header('Content-Type: text/html; charset=utf-8');

// Conexão
try {
    $pdo = getPDO();
} catch (Exception $e) {
    die("Erro DB: " . $e->getMessage());
}

$acao = $_GET['acao'] ?? $_POST['acao'] ?? 'dashboard';

// ═══════════════════════════════════════════════════════════════════════════
// API - SIMULAR FLUXO COMPLETO
// ═══════════════════════════════════════════════════════════════════════════

if ($acao === 'simular_fluxo') {
    header('Content-Type: application/json');
    
    $cep = $_POST['cep'] ?? '';
    $logado = $_POST['logado'] ?? false;
    $customer_id = $_POST['customer_id'] ?? null;
    
    $resultado = [
        'etapas' => [],
        'sucesso' => true,
        'tempo_total' => 0
    ];
    
    $inicio = microtime(true);
    
    // ETAPA 1: Verificar CEP
    $resultado['etapas'][] = [
        'numero' => 1,
        'nome' => 'Verificar CEP',
        'status' => 'processando'
    ];
    
    // Buscar coordenadas do CEP
    $coordenadas = buscarCoordenadas($cep);
    
    if (!$coordenadas) {
        $resultado['etapas'][0]['status'] = 'erro';
        $resultado['etapas'][0]['mensagem'] = 'CEP não encontrado';
        $resultado['sucesso'] = false;
        echo json_encode($resultado);
        exit;
    }
    
    $resultado['etapas'][0]['status'] = 'ok';
    $resultado['etapas'][0]['dados'] = [
        'cep' => $cep,
        'lat' => $coordenadas['lat'],
        'lng' => $coordenadas['lng'],
        'cidade' => $coordenadas['cidade']
    ];
    
    // ETAPA 2: Match com Mercado
    $resultado['etapas'][] = [
        'numero' => 2,
        'nome' => 'Match com Mercado',
        'status' => 'processando'
    ];
    
    $mercado = buscarMercadoProximo($pdo, $coordenadas['lat'], $coordenadas['lng']);
    
    if (!$mercado) {
        $resultado['etapas'][1]['status'] = 'erro';
        $resultado['etapas'][1]['mensagem'] = 'Nenhum mercado atende essa região';
        $resultado['sucesso'] = false;
        echo json_encode($resultado);
        exit;
    }
    
    $resultado['etapas'][1]['status'] = 'ok';
    $resultado['etapas'][1]['dados'] = [
        'mercado' => $mercado['name'],
        'partner_id' => $mercado['partner_id'],
        'distancia' => round($mercado['distancia'], 2) . ' km',
        'dentro_raio' => $mercado['distancia'] <= ($mercado['raio_entrega_km'] ?? 10) ? 'SIM' : 'NÃO'
    ];
    
    // ETAPA 3: Verificar Produtos
    $resultado['etapas'][] = [
        'numero' => 3,
        'nome' => 'Verificar Produtos do Mercado',
        'status' => 'processando'
    ];
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               MIN(pp.price) as preco_min,
               MAX(pp.price) as preco_max
        FROM om_market_products_price pp
        WHERE pp.partner_id = ? AND pp.status = 1
    ");
    $stmt->execute([$mercado['partner_id']]);
    $produtos = $stmt->fetch();
    
    $resultado['etapas'][2]['status'] = 'ok';
    $resultado['etapas'][2]['dados'] = [
        'total_produtos' => $produtos['total'],
        'preco_min' => 'R$ ' . number_format($produtos['preco_min'], 2, ',', '.'),
        'preco_max' => 'R$ ' . number_format($produtos['preco_max'], 2, ',', '.'),
        'isolamento' => '✅ Produtos exclusivos deste mercado'
    ];
    
    // ETAPA 4: Verificar Shoppers Disponíveis
    $resultado['etapas'][] = [
        'numero' => 4,
        'nome' => 'Verificar Shoppers na Região',
        'status' => 'processando'
    ];
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               AVG(rating) as rating_medio
        FROM om_market_shoppers
        WHERE is_online = 1 AND cidade = ?
    ");
    $stmt->execute([$mercado['city']]);
    $shoppers = $stmt->fetch();
    
    $resultado['etapas'][3]['status'] = $shoppers['total'] > 0 ? 'ok' : 'aviso';
    $resultado['etapas'][3]['dados'] = [
        'shoppers_online' => $shoppers['total'],
        'rating_medio' => $shoppers['rating_medio'] ? number_format($shoppers['rating_medio'], 1) . ' ⭐' : 'N/A',
        'tempo_estimado_coleta' => calcularTempoColeta($shoppers['total'])
    ];
    
    // ETAPA 5: Verificar Drivers Disponíveis
    $resultado['etapas'][] = [
        'numero' => 5,
        'nome' => 'Verificar Drivers na Região',
        'status' => 'processando'
    ];
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               AVG(rating) as rating_medio
        FROM om_market_drivers
        WHERE is_online = 1 AND cidade = ?
    ");
    $stmt->execute([$mercado['city']]);
    $drivers = $stmt->fetch();
    
    $resultado['etapas'][4]['status'] = $drivers['total'] > 0 ? 'ok' : 'aviso';
    $resultado['etapas'][4]['dados'] = [
        'drivers_online' => $drivers['total'],
        'rating_medio' => $drivers['rating_medio'] ? number_format($drivers['rating_medio'], 1) . ' ⭐' : 'N/A',
        'tempo_estimado_entrega' => calcularTempoEntrega($drivers['total'], $mercado['distancia'])
    ];
    
    // ETAPA 6: Calcular Tempo Total Estimado
    $resultado['etapas'][] = [
        'numero' => 6,
        'nome' => 'Calcular Tempo Total',
        'status' => 'ok'
    ];
    
    $tempo_coleta = calcularTempoColetaMinutos($shoppers['total']);
    $tempo_entrega = calcularTempoEntregaMinutos($drivers['total'], $mercado['distancia']);
    $tempo_total = $tempo_coleta + $tempo_entrega;
    
    $resultado['etapas'][5]['dados'] = [
        'tempo_coleta' => $tempo_coleta . ' min',
        'tempo_entrega' => $tempo_entrega . ' min',
        'tempo_total' => $tempo_total . ' min',
        'faixa_horaria' => calcularFaixaHoraria($tempo_total)
    ];
    
    // Resumo Final
    $resultado['resumo'] = [
        'cep' => $cep,
        'cidade' => $coordenadas['cidade'],
        'mercado' => $mercado['name'],
        'distancia' => round($mercado['distancia'], 2) . ' km',
        'produtos_disponiveis' => $produtos['total'],
        'shoppers_online' => $shoppers['total'],
        'drivers_online' => $drivers['total'],
        'tempo_estimado' => $tempo_total . ' min',
        'pronto_para_pedido' => ($shoppers['total'] > 0 || $drivers['total'] > 0) ? 'SIM ✅' : 'NÃO ❌'
    ];
    
    $resultado['tempo_processamento'] = round((microtime(true) - $inicio) * 1000, 2) . ' ms';
    
    echo json_encode($resultado);
    exit;
}

if ($acao === 'simular_massa') {
    header('Content-Type: application/json');
    
    $quantidade = (int)($_POST['quantidade'] ?? 100);
    $resultados = [];
    $sucesso = 0;
    $erros = 0;
    
    // CEPs de teste
    $ceps_teste = [
        '35010-000', '35020-000', '35030-000', '35040-090',
        '30110-000', '30120-000', '30130-000',
        '01310-000', '01310-100', '04538-000',
    ];
    
    for ($i = 0; $i < $quantidade; $i++) {
        $cep = $ceps_teste[array_rand($ceps_teste)];
        
        $coordenadas = buscarCoordenadas($cep);
        if (!$coordenadas) {
            $erros++;
            continue;
        }
        
        $mercado = buscarMercadoProximo($pdo, $coordenadas['lat'], $coordenadas['lng']);
        if (!$mercado) {
            $erros++;
            continue;
        }
        
        // Contabilizar por mercado
        $key = $mercado['partner_id'];
        if (!isset($resultados[$key])) {
            $resultados[$key] = [
                'mercado' => $mercado['name'],
                'cidade' => $mercado['city'],
                'clientes' => 0
            ];
        }
        $resultados[$key]['clientes']++;
        $sucesso++;
    }
    
    echo json_encode([
        'success' => true,
        'total' => $quantidade,
        'sucesso' => $sucesso,
        'erros' => $erros,
        'distribuicao' => array_values($resultados)
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ═══════════════════════════════════════════════════════════════════════════

function buscarCoordenadas($cep) {
    $cep_limpo = preg_replace('/\D/', '', $cep);
    $prefixo = substr($cep_limpo, 0, 5);
    
    $coords = [
        '35010' => ['lat' => -18.8510, 'lng' => -41.9530, 'cidade' => 'Governador Valadares'],
        '35020' => ['lat' => -18.8620, 'lng' => -41.9420, 'cidade' => 'Governador Valadares'],
        '35030' => ['lat' => -18.8730, 'lng' => -41.9310, 'cidade' => 'Governador Valadares'],
        '35040' => ['lat' => -18.8574, 'lng' => -41.9439, 'cidade' => 'Governador Valadares'],
        '30110' => ['lat' => -19.9200, 'lng' => -43.9400, 'cidade' => 'Belo Horizonte'],
        '30120' => ['lat' => -19.9300, 'lng' => -43.9300, 'cidade' => 'Belo Horizonte'],
        '30130' => ['lat' => -19.9400, 'lng' => -43.9200, 'cidade' => 'Belo Horizonte'],
        '01310' => ['lat' => -23.5500, 'lng' => -46.6500, 'cidade' => 'São Paulo'],
        '04538' => ['lat' => -23.5900, 'lng' => -46.6800, 'cidade' => 'São Paulo'],
        '20040' => ['lat' => -22.9000, 'lng' => -43.1800, 'cidade' => 'Rio de Janeiro'],
        '80010' => ['lat' => -25.4300, 'lng' => -49.2700, 'cidade' => 'Curitiba'],
    ];
    
    return $coords[$prefixo] ?? null;
}

function buscarMercadoProximo($pdo, $lat, $lng) {
    $stmt = $pdo->prepare("
        SELECT partner_id, name, city, latitude, longitude, raio_entrega_km,
               (6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distancia
        FROM om_market_partners
        WHERE status = 1
        HAVING distancia <= COALESCE(raio_entrega_km, 50)
        ORDER BY distancia
        LIMIT 1
    ");
    $stmt->execute([$lat, $lng, $lat]);
    return $stmt->fetch();
}

function calcularTempoColeta($shoppers_online) {
    if ($shoppers_online >= 5) return '15-25 min';
    if ($shoppers_online >= 2) return '25-40 min';
    if ($shoppers_online >= 1) return '40-60 min';
    return 'Indisponível';
}

function calcularTempoColetaMinutos($shoppers_online) {
    if ($shoppers_online >= 5) return 20;
    if ($shoppers_online >= 2) return 35;
    if ($shoppers_online >= 1) return 50;
    return 60;
}

function calcularTempoEntrega($drivers_online, $distancia) {
    $tempo_base = ceil($distancia * 3); // 3 min por km
    
    if ($drivers_online >= 5) return ($tempo_base + 5) . '-' . ($tempo_base + 15) . ' min';
    if ($drivers_online >= 2) return ($tempo_base + 10) . '-' . ($tempo_base + 25) . ' min';
    if ($drivers_online >= 1) return ($tempo_base + 20) . '-' . ($tempo_base + 40) . ' min';
    return 'Indisponível';
}

function calcularTempoEntregaMinutos($drivers_online, $distancia) {
    $tempo_base = ceil($distancia * 3);
    
    if ($drivers_online >= 5) return $tempo_base + 10;
    if ($drivers_online >= 2) return $tempo_base + 20;
    if ($drivers_online >= 1) return $tempo_base + 30;
    return 45;
}

function calcularFaixaHoraria($minutos) {
    $agora = new DateTime();
    $entrega_min = clone $agora;
    $entrega_min->modify("+{$minutos} minutes");
    $entrega_max = clone $entrega_min;
    $entrega_max->modify("+20 minutes");
    
    return $entrega_min->format('H:i') . ' - ' . $entrega_max->format('H:i');
}

// ═══════════════════════════════════════════════════════════════════════════
// DASHBOARD HTML
// ═══════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🤖 Simulador de Fluxo - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 100%); color: #fff; min-height: 100vh; padding: 20px; }
        
        .container { max-width: 1000px; margin: 0 auto; }
        
        h1 { text-align: center; color: #00d4aa; margin-bottom: 30px; }
        h1 span { color: #ff6b6b; }
        
        .section { background: rgba(255,255,255,0.03); border-radius: 16px; padding: 25px; margin-bottom: 25px; border: 1px solid rgba(255,255,255,0.1); }
        .section h2 { color: #00d4aa; margin-bottom: 20px; }
        
        .input-group { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .input-group input { padding: 15px 20px; border: 2px solid #333; border-radius: 12px; background: #1a1a2e; color: #fff; font-size: 18px; width: 200px; text-align: center; letter-spacing: 2px; }
        .input-group input:focus { border-color: #00d4aa; outline: none; }
        
        .btn { padding: 15px 30px; border: none; border-radius: 12px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-primary { background: linear-gradient(135deg, #00d4aa, #00b894); color: #000; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,212,170,0.3); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .etapas { margin-top: 30px; }
        .etapa { background: rgba(0,0,0,0.2); border-radius: 12px; padding: 20px; margin-bottom: 15px; border-left: 4px solid #333; transition: all 0.3s; }
        .etapa.ok { border-left-color: #00d4aa; }
        .etapa.erro { border-left-color: #ff6b6b; }
        .etapa.aviso { border-left-color: #ffc107; }
        .etapa.processando { border-left-color: #3498db; }
        
        .etapa-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .etapa-numero { background: #00d4aa; color: #000; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .etapa-nome { font-size: 16px; font-weight: 600; }
        .etapa-status { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .etapa-status.ok { background: #00d4aa; color: #000; }
        .etapa-status.erro { background: #ff6b6b; color: #fff; }
        .etapa-status.aviso { background: #ffc107; color: #000; }
        .etapa-status.processando { background: #3498db; color: #fff; }
        
        .etapa-dados { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
        .etapa-dado { background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; }
        .etapa-dado label { color: #888; font-size: 11px; text-transform: uppercase; display: block; margin-bottom: 3px; }
        .etapa-dado value { color: #00d4aa; font-weight: 600; }
        
        .resumo { background: linear-gradient(135deg, rgba(0,212,170,0.1), rgba(0,184,148,0.05)); border: 2px solid #00d4aa; border-radius: 16px; padding: 25px; margin-top: 30px; }
        .resumo h3 { color: #00d4aa; margin-bottom: 20px; text-align: center; }
        .resumo-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; }
        .resumo-item { text-align: center; padding: 15px; background: rgba(0,0,0,0.2); border-radius: 12px; }
        .resumo-item label { color: #888; font-size: 11px; text-transform: uppercase; display: block; }
        .resumo-item value { color: #fff; font-size: 20px; font-weight: bold; display: block; margin-top: 5px; }
        .resumo-item.destaque value { color: #00d4aa; font-size: 28px; }
        
        .distribuicao { margin-top: 30px; }
        .distribuicao table { width: 100%; border-collapse: collapse; }
        .distribuicao th, .distribuicao td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .distribuicao th { color: #00d4aa; }
        
        .barra { height: 20px; background: #333; border-radius: 10px; overflow: hidden; }
        .barra-fill { height: 100%; background: linear-gradient(90deg, #00d4aa, #00b894); border-radius: 10px; transition: width 0.5s; }
        
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .loading { animation: pulse 1s infinite; }
    </style>
</head>
<body>

<div class="container">
    <h1>🤖 Simulador de <span>Fluxo Completo</span></h1>
    
    <!-- SIMULAÇÃO INDIVIDUAL -->
    <div class="section">
        <h2>🎯 Simular Fluxo Individual</h2>
        <p style="color:#888;margin-bottom:20px">Digite um CEP para simular todo o fluxo do cliente, desde a entrada até a estimativa de entrega.</p>
        
        <div class="input-group">
            <input type="text" id="cepInput" placeholder="00000-000" maxlength="9">
            <button class="btn btn-primary" onclick="simularFluxo()">🚀 Simular Fluxo</button>
        </div>
        
        <div id="etapasContainer" class="etapas" style="display:none"></div>
        <div id="resumoContainer" class="resumo" style="display:none"></div>
    </div>
    
    <!-- SIMULAÇÃO EM MASSA -->
    <div class="section">
        <h2>🔥 Simulação em Massa</h2>
        <p style="color:#888;margin-bottom:20px">Simule milhares de clientes para testar a distribuição entre mercados.</p>
        
        <div class="input-group">
            <input type="number" id="qtdMassa" value="1000" min="10" max="10000" style="width:150px">
            <button class="btn btn-primary" onclick="simularMassa()">🚀 Simular em Massa</button>
        </div>
        
        <div id="distribuicaoContainer" class="distribuicao" style="display:none"></div>
    </div>
</div>

<script>
// Formatar CEP
document.getElementById('cepInput').addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '');
    if (v.length > 5) v = v.substring(0, 5) + '-' + v.substring(5, 3);
    e.target.value = v;
});

async function simularFluxo() {
    const cep = document.getElementById('cepInput').value;
    if (!cep || cep.length < 9) {
        alert('Digite um CEP válido');
        return;
    }
    
    const container = document.getElementById('etapasContainer');
    const resumoContainer = document.getElementById('resumoContainer');
    
    container.innerHTML = '<div class="loading" style="text-align:center;padding:30px;color:#888">Processando...</div>';
    container.style.display = 'block';
    resumoContainer.style.display = 'none';
    
    try {
        const res = await fetch('?acao=simular_fluxo', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `cep=${encodeURIComponent(cep)}`
        });
        const data = await res.json();
        
        // Renderizar etapas
        container.innerHTML = '';
        data.etapas.forEach(etapa => {
            const html = `
                <div class="etapa ${etapa.status}">
                    <div class="etapa-header">
                        <div style="display:flex;align-items:center;gap:15px">
                            <div class="etapa-numero">${etapa.numero}</div>
                            <div class="etapa-nome">${etapa.nome}</div>
                        </div>
                        <div class="etapa-status ${etapa.status}">${etapa.status.toUpperCase()}</div>
                    </div>
                    ${etapa.dados ? `
                        <div class="etapa-dados">
                            ${Object.entries(etapa.dados).map(([k, v]) => `
                                <div class="etapa-dado">
                                    <label>${k.replace(/_/g, ' ')}</label>
                                    <value>${v}</value>
                                </div>
                            `).join('')}
                        </div>
                    ` : ''}
                    ${etapa.mensagem ? `<p style="color:#ff6b6b;margin-top:10px">${etapa.mensagem}</p>` : ''}
                </div>
            `;
            container.innerHTML += html;
        });
        
        // Renderizar resumo
        if (data.resumo) {
            resumoContainer.innerHTML = `
                <h3>📊 Resumo da Simulação</h3>
                <div class="resumo-grid">
                    <div class="resumo-item">
                        <label>CEP</label>
                        <value>${data.resumo.cep}</value>
                    </div>
                    <div class="resumo-item">
                        <label>Cidade</label>
                        <value>${data.resumo.cidade}</value>
                    </div>
                    <div class="resumo-item">
                        <label>Mercado</label>
                        <value>${data.resumo.mercado}</value>
                    </div>
                    <div class="resumo-item">
                        <label>Distância</label>
                        <value>${data.resumo.distancia}</value>
                    </div>
                    <div class="resumo-item">
                        <label>Produtos</label>
                        <value>${data.resumo.produtos_disponiveis}</value>
                    </div>
                    <div class="resumo-item">
                        <label>Shoppers</label>
                        <value>${data.resumo.shoppers_online}</value>
                    </div>
                    <div class="resumo-item">
                        <label>Drivers</label>
                        <value>${data.resumo.drivers_online}</value>
                    </div>
                    <div class="resumo-item destaque">
                        <label>Tempo Estimado</label>
                        <value>${data.resumo.tempo_estimado}</value>
                    </div>
                </div>
                <p style="text-align:center;margin-top:20px;color:#888">
                    Processado em ${data.tempo_processamento}
                </p>
            `;
            resumoContainer.style.display = 'block';
        }
        
    } catch (e) {
        container.innerHTML = `<div style="color:#ff6b6b;padding:20px">Erro: ${e.message}</div>`;
    }
}

async function simularMassa() {
    const qtd = document.getElementById('qtdMassa').value;
    const container = document.getElementById('distribuicaoContainer');
    
    container.innerHTML = '<div class="loading" style="text-align:center;padding:30px;color:#888">Simulando ' + qtd + ' clientes...</div>';
    container.style.display = 'block';
    
    try {
        const res = await fetch('?acao=simular_massa', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `quantidade=${qtd}`
        });
        const data = await res.json();
        
        // Encontrar máximo para barra
        const maxClientes = Math.max(...data.distribuicao.map(d => d.clientes));
        
        container.innerHTML = `
            <h3 style="color:#00d4aa;margin-bottom:20px">📊 Distribuição de ${data.total} Clientes</h3>
            <p style="color:#888;margin-bottom:20px">
                ✅ Sucesso: ${data.sucesso} | ❌ Erros: ${data.erros}
            </p>
            <table>
                <thead>
                    <tr>
                        <th>Mercado</th>
                        <th>Cidade</th>
                        <th>Clientes</th>
                        <th>Distribuição</th>
                    </tr>
                </thead>
                <tbody>
                    ${data.distribuicao.map(d => `
                        <tr>
                            <td><strong>${d.mercado}</strong></td>
                            <td>${d.cidade}</td>
                            <td>${d.clientes}</td>
                            <td style="width:40%">
                                <div class="barra">
                                    <div class="barra-fill" style="width:${(d.clientes / maxClientes * 100)}%"></div>
                                </div>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
        
    } catch (e) {
        container.innerHTML = `<div style="color:#ff6b6b;padding:20px">Erro: ${e.message}</div>`;
    }
}
</script>

</body>
</html>
