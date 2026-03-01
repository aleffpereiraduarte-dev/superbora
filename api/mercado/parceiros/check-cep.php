<?php
/**
 * GET /api/mercado/parceiros/check-cep.php?cep=35040090
 *
 * Busca se tem parceiro que atende o CEP informado.
 * Retorna: city, state, neighborhood, partner_id, partner_name
 * Usado pelo app mobile (LocationContext.checkCoverage)
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

// SECURITY: Rate limiting to prevent DoS — 30 requests/min per IP
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
if (!RateLimiter::check(30, 60)) {
    exit;
}

try {
    $cep = preg_replace('/\D/', '', $_GET["cep"] ?? $_POST["cep"] ?? "");

    if (strlen($cep) !== 8) {
        response(false, null, "CEP invalido. Informe 8 digitos.", 400);
    }

    $db = getDB();

    // 1. Buscar endereco pelo ViaCEP (com cache de 24h em /tmp)
    $city = '';
    $state = '';
    $neighborhood = '';

    $viaCepCacheFile = "/tmp/viacep_{$cep}.json";
    $viaCepJson = false;

    // Check file cache (24h TTL)
    if (file_exists($viaCepCacheFile) && (time() - filemtime($viaCepCacheFile)) < 86400) {
        $viaCepJson = @file_get_contents($viaCepCacheFile);
    }

    if (!$viaCepJson) {
        $viaCepUrl = "https://viacep.com.br/ws/{$cep}/json/";
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $viaCepJson = @file_get_contents($viaCepUrl, false, $ctx);

        // Cache the response
        if ($viaCepJson) {
            @file_put_contents($viaCepCacheFile, $viaCepJson);
        }
    }

    if ($viaCepJson) {
        $viaCep = json_decode($viaCepJson, true);
        if ($viaCep && empty($viaCep['erro'])) {
            $city = $viaCep['localidade'] ?? '';
            $state = $viaCep['uf'] ?? '';
            $neighborhood = $viaCep['bairro'] ?? '';
        }
    }

    // 2. Buscar parceiro que atende o CEP
    //    Os ranges em om_market_partners.cep_inicio/cep_fim podem ser 5 digitos (35000-35099)
    //    ou 8 digitos (35000000-35099999). Precisamos tratar ambos os formatos.
    $cep5 = substr($cep, 0, 5);
    $cep3 = substr($cep, 0, 3);

    $stmt = $db->query("
        SELECT partner_id, name, trade_name, city as partner_city, state as partner_state,
               cep, cep_inicio, cep_fim, delivery_fee, taxa_entrega, delivery_time_min, tempo_preparo
        FROM om_market_partners
        WHERE status::text = '1'
          AND cep_inicio IS NOT NULL
          AND cep_inicio != ''
          AND cep_fim IS NOT NULL
          AND cep_fim != ''
        ORDER BY rating DESC NULLS LAST
        LIMIT 50
    ");
    $partners = $stmt->fetchAll();

    foreach ($partners as $p) {
        $inicio = preg_replace('/\D/', '', $p['cep_inicio'] ?? '');
        $fim = preg_replace('/\D/', '', $p['cep_fim'] ?? '');

        if (!$inicio || !$fim) continue;

        $matched = false;

        // Check based on range length
        $len = strlen($inicio);
        if ($len === 5) {
            // 5-digit range: compare first 5 digits of CEP
            $matched = (intval($cep5) >= intval($inicio) && intval($cep5) <= intval($fim));
        } elseif ($len === 8) {
            // 8-digit range: compare full CEP
            $matched = (intval($cep) >= intval($inicio) && intval($cep) <= intval($fim));
        } elseif ($len === 3) {
            // 3-digit range: compare prefix
            $matched = (intval($cep3) >= intval($inicio) && intval($cep3) <= intval($fim));
        }

        if ($matched) {
            $fee = floatval($p['delivery_fee'] ?? $p['taxa_entrega'] ?? 0);
            $time = (int)($p['delivery_time_min'] ?? $p['tempo_preparo'] ?? 60);

            response(true, [
                'city' => $city ?: ($p['partner_city'] ?? ''),
                'state' => $state ?: ($p['partner_state'] ?? ''),
                'neighborhood' => $neighborhood,
                'partner_id' => (int)$p['partner_id'],
                'partner_name' => $p['name'] ?? $p['trade_name'] ?? '',
                'delivery_fee' => $fee,
                'delivery_time' => $time,
            ]);
        }
    }

    // 3. Fallback: buscar por prefixo de CEP (3 primeiros digitos = mesma regiao)
    $stmt = $db->query("
        SELECT partner_id, name, trade_name, delivery_fee, taxa_entrega, delivery_time_min, tempo_preparo,
               city as partner_city, state as partner_state, cep as partner_cep
        FROM om_market_partners
        WHERE status::text = '1'
        ORDER BY rating DESC NULLS LAST
        LIMIT 50
    ");
    $allPartners = $stmt->fetchAll();

    foreach ($allPartners as $p) {
        $partnerCep = preg_replace('/\D/', '', $p['partner_cep'] ?? '');
        if (!$partnerCep) continue;
        $partnerPrefix = substr($partnerCep, 0, 3);

        if ($partnerPrefix === $cep3) {
            $fee = floatval($p['delivery_fee'] ?? $p['taxa_entrega'] ?? 0);
            $time = (int)($p['delivery_time_min'] ?? $p['tempo_preparo'] ?? 60);

            response(true, [
                'city' => $city ?: ($p['partner_city'] ?? ''),
                'state' => $state ?: ($p['partner_state'] ?? ''),
                'neighborhood' => $neighborhood,
                'partner_id' => (int)$p['partner_id'],
                'partner_name' => $p['name'] ?? $p['trade_name'] ?? '',
                'delivery_fee' => $fee,
                'delivery_time' => $time,
            ]);
        }
    }

    // 4. Nenhum parceiro encontrado - retorna so a localizacao
    response(true, [
        'city' => $city,
        'state' => $state,
        'neighborhood' => $neighborhood,
        'partner_id' => null,
        'partner_name' => null,
    ]);

} catch (Exception $e) {
    error_log("[parceiros/check-cep] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
