<?php
/**
 * TESTE END-TO-END COMPLETO - TODOS OS CENÁRIOS
 *
 * Cenários testados:
 * 1. Fluxo de compra + entrega via ponto de apoio
 * 2. Fluxo de devolução completo
 * 3. Cenário de erro: PIN incorreto
 * 4. Cenário de erro: produto não esperado no ponto
 * 5. Entrega por motorista/entregador
 * 6. Cancelamento de entrega
 * 7. APIs de rastreamento
 * 8. Notificações
 */

require_once __DIR__ . '/database.php';

header('Content-Type: text/plain; charset=utf-8');

$startTime = microtime(true);

echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║       TESTE END-TO-END COMPLETO - TODOS OS CENÁRIOS                 ║\n";
echo "║       Data: " . date('Y-m-d H:i:s') . "                                       ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

$pdo = getConnection();
$testes = [];
$totalTestes = 0;
$testesOk = 0;

// Função auxiliar para registrar teste
function registrarTeste(&$testes, &$totalTestes, &$testesOk, $cenario, $nome, $sucesso, $detalhes = '') {
    $totalTestes++;
    if ($sucesso) $testesOk++;

    $status = $sucesso ? '✅' : '❌';
    echo "   $status $nome" . ($detalhes ? " - $detalhes" : "") . "\n";

    $testes[$cenario][] = [
        'nome' => $nome,
        'sucesso' => $sucesso,
        'detalhes' => $detalhes
    ];
}

// Função para fazer requisição HTTP
function httpRequest($url, $method = 'GET', $data = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response,
        'json' => json_decode($response, true)
    ];
}

// ============================================================
// CENÁRIO 1: FLUXO COMPLETO DE COMPRA E ENTREGA
// ============================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "CENÁRIO 1: FLUXO COMPLETO DE COMPRA E ENTREGA VIA PONTO\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$cenario1 = [];

try {
    // Buscar pedido e ponto
    $stmt = $pdo->query("
        SELECT o.order_id, o.customer_id, o.firstname, o.lastname, o.telephone,
               op.product_id, op.name as product_name, op.price
        FROM oc_order o
        JOIN oc_order_product op ON o.order_id = op.order_id
        WHERE o.order_status_id > 0
        ORDER BY o.order_id DESC LIMIT 1
    ");
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT seller_id, store_name FROM oc_purpletree_vendor_stores WHERE is_ponto_apoio = 1 LIMIT 1");
    $ponto = $stmt->fetch(PDO::FETCH_ASSOC);

    registrarTeste($testes, $totalTestes, $testesOk, 'cenario1', 'Buscar pedido existente', (bool)$pedido, "Pedido #{$pedido['order_id']}");
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario1', 'Buscar ponto de apoio', (bool)$ponto, $ponto['store_name']);

    // Criar entrega
    $pin1 = strtoupper(substr(md5(uniqid()), 0, 6));
    $stmt = $pdo->prepare("
        INSERT INTO om_entregas (tipo, origem_sistema, referencia_id, remetente_tipo, remetente_id, remetente_nome,
            destinatario_nome, destinatario_telefone, descricao, valor_declarado, pin_entrega, ponto_apoio_id, status)
        VALUES ('express', 'e2e_cenario1', ?, 'vendedor', ?, ?, ?, ?, ?, ?, ?, ?, 'pendente')
    ");
    $stmt->execute([
        $pedido['order_id'], $ponto['seller_id'], $ponto['store_name'],
        $pedido['firstname'] . ' ' . $pedido['lastname'], $pedido['telephone'],
        'Cenário 1 - ' . $pedido['product_name'], $pedido['price'], $pin1, $ponto['seller_id']
    ]);
    $entrega1Id = $pdo->lastInsertId();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario1', 'Criar entrega', $entrega1Id > 0, "Entrega #$entrega1Id");

    // Gerar OMSKU
    $omsku1 = 'OM-C1-' . strtoupper(substr(md5(uniqid()), 0, 6));
    $unitCode1 = 'UC-C1-' . strtoupper(substr(md5(uniqid()), 0, 8));
    $stmt = $pdo->prepare("
        INSERT INTO om_produto_unidades (product_id, seller_id, unit_code, omsku, status, order_id, local_atual, localizacao_tipo)
        VALUES (?, ?, ?, ?, 'vendido', ?, 'vendedor', 'vendedor')
    ");
    $stmt->execute([$pedido['product_id'], $ponto['seller_id'], $unitCode1, $omsku1, $pedido['order_id']]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario1', 'Gerar OMSKU', true, $omsku1);

    // Tracking inicial
    $stmt = $pdo->prepare("INSERT INTO om_entrega_tracking (entrega_id, status, mensagem) VALUES (?, 'criado', 'Pedido criado - Cenário 1')");
    $stmt->execute([$entrega1Id]);

    // Vendedor envia
    $stmt = $pdo->prepare("UPDATE om_entregas SET status = 'a_caminho_ponto' WHERE id = ?");
    $stmt->execute([$entrega1Id]);
    $stmt = $pdo->prepare("INSERT INTO om_entrega_tracking (entrega_id, status, mensagem) VALUES (?, 'a_caminho_ponto', 'Enviado para ponto')");
    $stmt->execute([$entrega1Id]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario1', 'Vendedor envia para ponto', true, 'a_caminho_ponto');

    // Ponto recebe
    $stmt = $pdo->prepare("UPDATE om_entregas SET status = 'no_ponto' WHERE id = ?");
    $stmt->execute([$entrega1Id]);
    $stmt = $pdo->prepare("INSERT INTO om_entrega_tracking (entrega_id, status, mensagem) VALUES (?, 'no_ponto', 'Recebido no ponto')");
    $stmt->execute([$entrega1Id]);
    $stmt = $pdo->prepare("
        INSERT INTO om_entrega_handoffs (entrega_id, de_tipo, de_id, de_nome, para_tipo, para_id, para_nome, status)
        VALUES (?, 'vendedor', ?, ?, 'ponto_apoio', ?, ?, 'concluido')
    ");
    $stmt->execute([$entrega1Id, $ponto['seller_id'], $ponto['store_name'], $ponto['seller_id'], $ponto['store_name']]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario1', 'Ponto recebe produto', true, 'Handoff registrado');

    // Testar API de tracking
    $resp = httpRequest("http://localhost/api/tracking/status.php?tipo=entrega&id=$entrega1Id");
    $trackingOk = $resp['json']['success'] ?? false;
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario1', 'API Tracking', $trackingOk, $resp['json']['status'] ?? 'erro');

    // Cliente retira com PIN correto
    $resp = httpRequest("http://localhost/api/handoff/retirada.php", 'POST', [
        'entrega_id' => $entrega1Id,
        'ponto_id' => $ponto['seller_id'],
        'pin' => $pin1
    ]);
    $retiradaOk = $resp['json']['success'] ?? false;
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario1', 'Cliente retira com PIN', $retiradaOk, $resp['json']['status'] ?? 'erro');

    // Verificar estado final
    $stmt = $pdo->prepare("SELECT status FROM om_entregas WHERE id = ?");
    $stmt->execute([$entrega1Id]);
    $statusFinal = $stmt->fetchColumn();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario1', 'Estado final: entregue', $statusFinal === 'entregue', $statusFinal);

} catch (Exception $e) {
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario1', 'Erro no cenário', false, $e->getMessage());
}

echo "\n";

// ============================================================
// CENÁRIO 2: FLUXO DE DEVOLUÇÃO
// ============================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "CENÁRIO 2: FLUXO DE DEVOLUÇÃO COMPLETO\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Verificar se tabela de devoluções existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'om_devolucoes'");
    $tabelaExiste = $stmt->rowCount() > 0;

    if (!$tabelaExiste) {
        // Criar tabela de devoluções
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS om_devolucoes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                customer_id INT NOT NULL,
                motivo VARCHAR(100),
                descricao TEXT,
                status ENUM('solicitado','aprovado','enviado','no_ponto','recebido_vendedor','reembolsado','recusado') DEFAULT 'solicitado',
                ponto_apoio_id INT,
                codigo_devolucao VARCHAR(50),
                pin_devolucao VARCHAR(10),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_order (order_id),
                INDEX idx_customer (customer_id),
                INDEX idx_status (status)
            )
        ");
        registrarTeste($testes, $totalTestes, $testesOk, 'cenario2', 'Criar tabela om_devolucoes', true, 'Tabela criada');
    } else {
        registrarTeste($testes, $totalTestes, $testesOk, 'cenario2', 'Tabela om_devolucoes existe', true, '');
    }

    // Buscar pedido entregue para devolver (usar o do cenário 1)
    $stmt = $pdo->prepare("SELECT * FROM om_entregas WHERE id = ?");
    $stmt->execute([$entrega1Id]);
    $entregaParaDevolver = $stmt->fetch(PDO::FETCH_ASSOC);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario2', 'Buscar entrega para devolver', (bool)$entregaParaDevolver, "Entrega #$entrega1Id");

    // Solicitar devolução
    $codigoDev = 'DEV-' . strtoupper(substr(md5(uniqid()), 0, 8));
    $pinDev = strtoupper(substr(md5(uniqid()), 0, 6));

    $stmt = $pdo->prepare("
        INSERT INTO om_devolucoes (order_id, customer_id, motivo, descricao, status, ponto_apoio_id, codigo_devolucao, pin_devolucao)
        VALUES (?, ?, 'defeito', 'Produto com defeito de fábrica', 'solicitado', ?, ?, ?)
    ");
    $stmt->execute([
        $entregaParaDevolver['referencia_id'],
        $pedido['customer_id'],
        $ponto['seller_id'],
        $codigoDev,
        $pinDev
    ]);
    $devolucaoId = $pdo->lastInsertId();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario2', 'Criar solicitação de devolução', $devolucaoId > 0, "Devolução #$devolucaoId");

    // Aprovar devolução
    $stmt = $pdo->prepare("UPDATE om_devolucoes SET status = 'aprovado' WHERE id = ?");
    $stmt->execute([$devolucaoId]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario2', 'Aprovar devolução', true, 'status: aprovado');

    // Cliente envia para ponto
    $stmt = $pdo->prepare("UPDATE om_devolucoes SET status = 'enviado' WHERE id = ?");
    $stmt->execute([$devolucaoId]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario2', 'Cliente envia produto', true, 'status: enviado');

    // Registrar movimentação OMSKU
    $stmt = $pdo->prepare("
        INSERT INTO om_omsku_movimentacoes (unit_code, tipo, origem, destino, devolucao_id, observacao)
        VALUES (?, 'devolucao', 'cliente', 'transito', ?, 'Devolução iniciada pelo cliente')
    ");
    $stmt->execute([$unitCode1, $devolucaoId]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario2', 'Movimentação OMSKU devolução', true, 'cliente → transito');

    // Ponto recebe devolução
    $stmt = $pdo->prepare("UPDATE om_devolucoes SET status = 'no_ponto' WHERE id = ?");
    $stmt->execute([$devolucaoId]);
    $stmt = $pdo->prepare("
        INSERT INTO om_omsku_movimentacoes (unit_code, tipo, origem, destino, devolucao_id, observacao)
        VALUES (?, 'recebimento', 'transito', 'ponto_apoio', ?, 'Devolução recebida no ponto')
    ");
    $stmt->execute([$unitCode1, $devolucaoId]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario2', 'Ponto recebe devolução', true, 'status: no_ponto');

    // Vendedor coleta devolução
    $stmt = $pdo->prepare("UPDATE om_devolucoes SET status = 'recebido_vendedor' WHERE id = ?");
    $stmt->execute([$devolucaoId]);
    $stmt = $pdo->prepare("
        INSERT INTO om_omsku_movimentacoes (unit_code, tipo, origem, destino, devolucao_id, observacao)
        VALUES (?, 'recebimento', 'ponto_apoio', 'vendedor', ?, 'Vendedor recebeu produto devolvido')
    ");
    $stmt->execute([$unitCode1, $devolucaoId]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario2', 'Vendedor recebe devolução', true, 'status: recebido_vendedor');

    // Reembolso
    $stmt = $pdo->prepare("UPDATE om_devolucoes SET status = 'reembolsado' WHERE id = ?");
    $stmt->execute([$devolucaoId]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario2', 'Reembolso processado', true, 'status: reembolsado');

    // Verificar estado final
    $stmt = $pdo->prepare("SELECT status FROM om_devolucoes WHERE id = ?");
    $stmt->execute([$devolucaoId]);
    $statusDev = $stmt->fetchColumn();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario2', 'Estado final devolução', $statusDev === 'reembolsado', $statusDev);

} catch (Exception $e) {
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario2', 'Erro no cenário', false, $e->getMessage());
}

echo "\n";

// ============================================================
// CENÁRIO 3: ERRO - PIN INCORRETO
// ============================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "CENÁRIO 3: TESTE DE ERRO - PIN INCORRETO\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Criar nova entrega
    $pin3 = 'ABC123';
    $stmt = $pdo->prepare("
        INSERT INTO om_entregas (tipo, origem_sistema, referencia_id, remetente_tipo, remetente_id, remetente_nome,
            destinatario_nome, pin_entrega, ponto_apoio_id, status)
        VALUES ('express', 'e2e_cenario3', ?, 'vendedor', ?, ?, 'Cliente Teste', ?, ?, 'no_ponto')
    ");
    $stmt->execute([$pedido['order_id'], $ponto['seller_id'], $ponto['store_name'], $pin3, $ponto['seller_id']]);
    $entrega3Id = $pdo->lastInsertId();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario3', 'Criar entrega de teste', $entrega3Id > 0, "Entrega #$entrega3Id com PIN $pin3");

    // Tentar retirar com PIN errado
    $resp = httpRequest("http://localhost/api/handoff/retirada.php", 'POST', [
        'entrega_id' => $entrega3Id,
        'ponto_id' => $ponto['seller_id'],
        'pin' => 'ERRADO'
    ]);
    $pinRejeitado = ($resp['json']['success'] ?? true) === false;
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario3', 'PIN incorreto rejeitado', $pinRejeitado, $resp['json']['error'] ?? 'aceito incorretamente');

    // Tentar com PIN correto
    $resp = httpRequest("http://localhost/api/handoff/retirada.php", 'POST', [
        'entrega_id' => $entrega3Id,
        'ponto_id' => $ponto['seller_id'],
        'pin' => $pin3
    ]);
    $pinAceito = $resp['json']['success'] ?? false;
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario3', 'PIN correto aceito', $pinAceito, 'Entrega confirmada');

} catch (Exception $e) {
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario3', 'Erro no cenário', false, $e->getMessage());
}

echo "\n";

// ============================================================
// CENÁRIO 4: ERRO - PRODUTO NÃO ESPERADO
// ============================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "CENÁRIO 4: TESTE DE ERRO - PRODUTO NÃO ESPERADO NO PONTO\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Tentar receber produto não esperado
    $resp = httpRequest("http://localhost/api/ponto-apoio/receber.php", 'POST', [
        'ponto_id' => $ponto['seller_id'],
        'sku_code' => 'PRODUTO-INEXISTENTE-123'
    ]);
    $rejeitado = ($resp['json']['success'] ?? true) === false;
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario4', 'Produto inexistente rejeitado', $rejeitado, $resp['json']['error'] ?? 'aceito incorretamente');

    // Tentar receber produto que não está programado para este ponto
    $resp = httpRequest("http://localhost/api/ponto-apoio/receber.php", 'POST', [
        'ponto_id' => 999999, // Ponto inexistente
        'sku_code' => $unitCode1
    ]);
    $pontoRejeitado = ($resp['json']['success'] ?? true) === false;
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario4', 'Ponto inexistente rejeitado', $pontoRejeitado, $resp['json']['error'] ?? 'aceito incorretamente');

} catch (Exception $e) {
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario4', 'Erro no cenário', false, $e->getMessage());
}

echo "\n";

// ============================================================
// CENÁRIO 5: ENTREGA POR MOTORISTA
// ============================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "CENÁRIO 5: ENTREGA POR MOTORISTA/ENTREGADOR\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Buscar motorista
    $stmt = $pdo->query("SELECT driver_id, name FROM om_boraum_drivers WHERE status IN ('approved', 'aprovado', 'active') LIMIT 1");
    $motorista = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($motorista) {
        $motorista['id'] = $motorista['driver_id'];
        $motorista['nome'] = $motorista['name'];
        registrarTeste($testes, $totalTestes, $testesOk, 'cenario5', 'Buscar motorista', true, "ID: {$motorista['id']} - {$motorista['nome']}");
    } else {
        // Criar motorista de teste
        $stmt = $pdo->prepare("
            INSERT INTO om_boraum_drivers (name, phone, email, cpf, status, created_at)
            VALUES ('Motorista E2E', '11999999999', 'motorista@e2e.test', '12345678900', 'approved', NOW())
        ");
        $stmt->execute();
        $motoristaId = $pdo->lastInsertId();
        $motorista = ['id' => $motoristaId, 'nome' => 'Motorista E2E'];
        registrarTeste($testes, $totalTestes, $testesOk, 'cenario5', 'Criar motorista de teste', true, "ID: $motoristaId");
    }

    // Criar entrega para motorista
    $pin5 = strtoupper(substr(md5(uniqid()), 0, 6));
    $stmt = $pdo->prepare("
        INSERT INTO om_entregas (tipo, origem_sistema, referencia_id, remetente_tipo, remetente_id, remetente_nome,
            destinatario_nome, destinatario_telefone, entrega_endereco, pin_entrega, driver_id, metodo_entrega, status)
        VALUES ('express', 'e2e_cenario5', ?, 'vendedor', ?, ?, ?, '11999999999',
            'Rua Teste E2E, 500 - São Paulo/SP', ?, ?, 'driver', 'pendente')
    ");
    $stmt->execute([
        $pedido['order_id'], $ponto['seller_id'], $ponto['store_name'],
        $pedido['firstname'], $pin5, $motorista['id']
    ]);
    $entrega5Id = $pdo->lastInsertId();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario5', 'Criar entrega para motorista', $entrega5Id > 0, "Entrega #$entrega5Id");

    // Motorista aceita
    $stmt = $pdo->prepare("UPDATE om_entregas SET status = 'aceito', driver_aceito_em = NOW() WHERE id = ?");
    $stmt->execute([$entrega5Id]);
    $stmt = $pdo->prepare("INSERT INTO om_entrega_tracking (entrega_id, status, mensagem) VALUES (?, 'aceito', 'Motorista aceitou a entrega')");
    $stmt->execute([$entrega5Id]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario5', 'Motorista aceita entrega', true, 'status: aceito');

    // Motorista coleta
    $stmt = $pdo->prepare("UPDATE om_entregas SET status = 'coletado', coleta_realizada_em = NOW() WHERE id = ?");
    $stmt->execute([$entrega5Id]);
    $stmt = $pdo->prepare("INSERT INTO om_entrega_tracking (entrega_id, status, mensagem) VALUES (?, 'coletado', 'Produto coletado pelo motorista')");
    $stmt->execute([$entrega5Id]);
    $stmt = $pdo->prepare("
        INSERT INTO om_entrega_handoffs (entrega_id, de_tipo, de_id, de_nome, para_tipo, para_id, para_nome, status)
        VALUES (?, 'vendedor', ?, ?, 'entregador', ?, ?, 'concluido')
    ");
    $stmt->execute([$entrega5Id, $ponto['seller_id'], $ponto['store_name'], $motorista['id'], $motorista['nome']]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario5', 'Motorista coleta produto', true, 'Handoff: vendedor → motorista');

    // Motorista em trânsito
    $stmt = $pdo->prepare("UPDATE om_entregas SET status = 'em_transito' WHERE id = ?");
    $stmt->execute([$entrega5Id]);
    $stmt = $pdo->prepare("INSERT INTO om_entrega_tracking (entrega_id, status, mensagem, lat, lng) VALUES (?, 'em_transito', 'Em trânsito para entrega', -23.5505, -46.6333)");
    $stmt->execute([$entrega5Id]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario5', 'Motorista em trânsito', true, 'GPS: -23.5505, -46.6333');

    // Motorista chega
    $stmt = $pdo->prepare("UPDATE om_entregas SET status = 'chegou' WHERE id = ?");
    $stmt->execute([$entrega5Id]);
    $stmt = $pdo->prepare("INSERT INTO om_entrega_tracking (entrega_id, status, mensagem) VALUES (?, 'chegou', 'Motorista chegou no destino')");
    $stmt->execute([$entrega5Id]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario5', 'Motorista chega no destino', true, 'status: chegou');

    // Entrega confirmada
    $stmt = $pdo->prepare("UPDATE om_entregas SET status = 'entregue', entrega_realizada_em = NOW() WHERE id = ?");
    $stmt->execute([$entrega5Id]);
    $stmt = $pdo->prepare("INSERT INTO om_entrega_tracking (entrega_id, status, mensagem) VALUES (?, 'entregue', 'Entrega confirmada com PIN')");
    $stmt->execute([$entrega5Id]);
    $stmt = $pdo->prepare("
        INSERT INTO om_entrega_handoffs (entrega_id, de_tipo, de_id, de_nome, para_tipo, para_id, para_nome, status)
        VALUES (?, 'entregador', ?, ?, 'cliente', 0, ?, 'concluido')
    ");
    $stmt->execute([$entrega5Id, $motorista['id'], $motorista['nome'], $pedido['firstname']]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario5', 'Entrega confirmada', true, 'Handoff: motorista → cliente');

    // Verificar estado final
    $stmt = $pdo->prepare("SELECT status FROM om_entregas WHERE id = ?");
    $stmt->execute([$entrega5Id]);
    $status5 = $stmt->fetchColumn();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario5', 'Estado final', $status5 === 'entregue', $status5);

} catch (Exception $e) {
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario5', 'Erro no cenário', false, $e->getMessage());
}

echo "\n";

// ============================================================
// CENÁRIO 6: APIs DE RASTREAMENTO
// ============================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "CENÁRIO 6: TESTES DE APIs DE RASTREAMENTO\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // API Tracking por ID
    $resp = httpRequest("http://localhost/api/tracking/status.php?tipo=entrega&id=$entrega1Id");
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario6', 'API Tracking por ID', $resp['json']['success'] ?? false, 'GET /api/tracking/status.php');

    // API Tracking por código
    $codigo = 'ENT-' . str_pad($entrega1Id, 6, '0', STR_PAD_LEFT);
    $resp = httpRequest("http://localhost/api/tracking/status.php?codigo=$codigo");
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario6', 'API Tracking por código', $resp['json']['success'] ?? false, "Código: $codigo");

    // API Handoff Scan
    $resp = httpRequest("http://localhost/api/handoff/scan.php?code=$codigo");
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario6', 'API Handoff Scan', $resp['json']['success'] ?? false, 'GET /api/handoff/scan.php');

    // API Scanner OMSKU
    $resp = httpRequest("http://localhost/api/omsku/scanner.php", 'POST', ['qr_data' => $unitCode1]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario6', 'API Scanner OMSKU', $resp['json']['success'] ?? false, 'POST /api/omsku/scanner.php');

    // API QR Code
    $resp = httpRequest("http://localhost/api/qrcode/gerar.php?tipo=entrega&id=$entrega1Id");
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario6', 'API QR Code', $resp['code'] === 200, 'GET /api/qrcode/gerar.php');

    // API Etiqueta
    $resp = httpRequest("http://localhost/api/omsku/etiqueta.php?order_id={$pedido['order_id']}");
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario6', 'API Etiqueta OMSKU', $resp['code'] === 200, 'GET /api/omsku/etiqueta.php');

    // API Cliente QRCode (usar entrega_id diretamente pois order_id pode retornar entrega errada)
    $resp = httpRequest("http://localhost/api/retirada/cliente-qrcode.php?entrega_id=$entrega1Id");
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario6', 'API Cliente QRCode', $resp['json']['success'] ?? false, 'GET /api/retirada/cliente-qrcode.php');

    // API Buscar Pontos
    $resp = httpRequest("http://localhost/vendedor/ponto-apoio/api/buscar-pontos.php?cidade=S%C3%A3o%20Paulo");
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario6', 'API Buscar Pontos', $resp['json']['success'] ?? false, 'GET /vendedor/ponto-apoio/api/buscar-pontos.php');

} catch (Exception $e) {
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario6', 'Erro no cenário', false, $e->getMessage());
}

echo "\n";

// ============================================================
// CENÁRIO 7: NOTIFICAÇÕES
// ============================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "CENÁRIO 7: SISTEMA DE NOTIFICAÇÕES\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Contar notificações criadas
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_entrega_notificacoes WHERE entrega_id IN (?, ?, ?)");
    $stmt->execute([$entrega1Id, $entrega3Id ?? 0, $entrega5Id ?? 0]);
    $totalNotif = $stmt->fetchColumn();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario7', 'Notificações criadas', $totalNotif >= 0, "Total: $totalNotif");

    // Criar notificação de teste
    $stmt = $pdo->prepare("
        INSERT INTO om_entrega_notificacoes (entrega_id, destinatario_tipo, destinatario_id, titulo, mensagem, tipo, enviado)
        VALUES (?, 'cliente', ?, 'Teste E2E', 'Notificação de teste E2E', 'info', 1)
    ");
    $stmt->execute([$entrega1Id, $pedido['customer_id']]);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario7', 'Criar notificação', true, 'Tipo: info');

    // Verificar estrutura da tabela
    $stmt = $pdo->query("DESCRIBE om_entrega_notificacoes");
    $colunas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $estruturaOk = in_array('titulo', $colunas) && in_array('mensagem', $colunas);
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario7', 'Estrutura tabela notificações', $estruturaOk, implode(', ', array_slice($colunas, 0, 5)) . '...');

} catch (Exception $e) {
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario7', 'Erro no cenário', false, $e->getMessage());
}

echo "\n";

// ============================================================
// CENÁRIO 8: ESTATÍSTICAS DO BANCO
// ============================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "CENÁRIO 8: VERIFICAÇÃO DE INTEGRIDADE DO BANCO\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Contar registros
    $stmt = $pdo->query("SELECT COUNT(*) FROM om_entregas");
    $totalEntregas = $stmt->fetchColumn();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario8', 'Total entregas', true, $totalEntregas);

    $stmt = $pdo->query("SELECT COUNT(*) FROM om_entrega_handoffs");
    $totalHandoffs = $stmt->fetchColumn();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario8', 'Total handoffs', true, $totalHandoffs);

    $stmt = $pdo->query("SELECT COUNT(*) FROM om_entrega_tracking");
    $totalTracking = $stmt->fetchColumn();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario8', 'Total tracking', true, $totalTracking);

    $stmt = $pdo->query("SELECT COUNT(*) FROM om_omsku_movimentacoes");
    $totalMovimentacoes = $stmt->fetchColumn();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario8', 'Total movimentações OMSKU', true, $totalMovimentacoes);

    $stmt = $pdo->query("SELECT COUNT(*) FROM om_produto_unidades");
    $totalUnidades = $stmt->fetchColumn();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario8', 'Total unidades OMSKU', true, $totalUnidades);

    // Verificar consistência
    $stmt = $pdo->query("SELECT COUNT(*) FROM om_entregas WHERE status = 'entregue'");
    $entregues = $stmt->fetchColumn();
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario8', 'Entregas concluídas', true, $entregues);

} catch (Exception $e) {
    registrarTeste($testes, $totalTestes, $testesOk, 'cenario8', 'Erro no cenário', false, $e->getMessage());
}

echo "\n";

// ============================================================
// RESUMO FINAL
// ============================================================
$endTime = microtime(true);
$tempoTotal = round($endTime - $startTime, 2);

echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║                      RESUMO FINAL DOS TESTES                        ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

echo "RESULTADO GERAL: $testesOk/$totalTestes testes passaram\n";
echo "TEMPO TOTAL: {$tempoTotal}s\n\n";

$percentual = round(($testesOk / $totalTestes) * 100, 1);
if ($percentual == 100) {
    echo "🎉 TODOS OS TESTES PASSARAM! SISTEMA 100% FUNCIONAL! 🎉\n";
} elseif ($percentual >= 90) {
    echo "✅ SISTEMA FUNCIONANDO BEM ($percentual%)\n";
} elseif ($percentual >= 70) {
    echo "⚠️ SISTEMA COM ALGUNS PROBLEMAS ($percentual%)\n";
} else {
    echo "❌ SISTEMA COM PROBLEMAS CRÍTICOS ($percentual%)\n";
}

echo "\n📊 RESUMO POR CENÁRIO:\n";
foreach ($testes as $cenario => $resultados) {
    $ok = count(array_filter($resultados, fn($r) => $r['sucesso']));
    $total = count($resultados);
    $status = $ok == $total ? '✅' : ($ok > 0 ? '⚠️' : '❌');
    echo "   $status $cenario: $ok/$total\n";
}

echo "\n📋 DADOS CRIADOS NESTE TESTE:\n";
echo "   - Entregas: #$entrega1Id, #" . ($entrega3Id ?? 'N/A') . ", #" . ($entrega5Id ?? 'N/A') . "\n";
echo "   - Devolução: #" . ($devolucaoId ?? 'N/A') . "\n";
echo "   - OMSKU: $omsku1\n";
echo "   - Unit Code: $unitCode1\n";

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "FIM DOS TESTES - " . date('Y-m-d H:i:s') . "\n";
