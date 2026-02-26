<?php
/**
 * API: Incidentes no Mapa (Waze-like)
 * /mercado/api/entregador/incidentes.php
 *
 * GET    - Listar incidentes ativos proximos (lat, lng, radius_km)
 * POST   - Criar novo incidente (requer auth motorista)
 * PUT    - Votar em incidente (upvote/downvote)
 * DELETE - Desativar incidente proprio
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ============================================================
// GET - Listar incidentes ativos proximos a uma localizacao
// ============================================================
if ($method === 'GET') {
    $lat = (float)($_GET['lat'] ?? 0);
    $lng = (float)($_GET['lng'] ?? 0);
    $radius_km = (float)($_GET['radius_km'] ?? 5);

    if (!$lat || !$lng) {
        jsonResponse(['success' => false, 'error' => 'lat e lng obrigatorios'], 400);
    }

    // Limitar raio entre 1 e 50 km
    $radius_km = max(1, min(50, $radius_km));

    $pdo = getDB();

    // Haversine formula para buscar incidentes dentro do raio
    $sql = "
        SELECT id, driver_id, driver_name, type, description,
               latitude, longitude, upvotes, downvotes, created_at,
               (6371 * acos(
                   cos(radians(:lat1)) * cos(radians(latitude)) *
                   cos(radians(longitude) - radians(:lng1)) +
                   sin(radians(:lat2)) * sin(radians(latitude))
               )) AS distance_km
        FROM om_market_map_incidents
        WHERE is_active = 1
          AND expires_at > NOW()
        HAVING distance_km <= :radius
        ORDER BY distance_km ASC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':lat1' => $lat,
        ':lng1' => $lng,
        ':lat2' => $lat,
        ':radius' => $radius_km
    ]);
    $incidents = $stmt->fetchAll();

    // Mapas de labels e icones para cada tipo
    $typeLabels = [
        'acidente'   => 'Acidente',
        'buraco'     => 'Buraco na via',
        'policia'    => 'Policia',
        'alagamento' => 'Alagamento',
        'obra'       => 'Obra na via',
        'transito'   => 'Transito intenso',
        'perigo'     => 'Perigo',
        'outro'      => 'Outro'
    ];

    $typeIcons = [
        'acidente'   => "\xF0\x9F\x9A\x97",  // car
        'buraco'     => "\xE2\x9A\xA0\xEF\xB8\x8F",   // warning
        'policia'    => "\xF0\x9F\x9A\x94",  // police car
        'alagamento' => "\xF0\x9F\x8C\x8A",  // water wave
        'obra'       => "\xF0\x9F\x9A\xA7",  // construction
        'transito'   => "\xF0\x9F\x9A\xA6",  // traffic light
        'perigo'     => "\xE2\x9B\x94",       // no entry
        'outro'      => "\xF0\x9F\x93\x8D"   // pin
    ];

    $formatted = array_map(function($inc) use ($typeLabels, $typeIcons) {
        return [
            'id'          => (int)$inc['id'],
            'type'        => $inc['type'],
            'type_label'  => $typeLabels[$inc['type']] ?? $inc['type'],
            'type_icon'   => $typeIcons[$inc['type']] ?? '',
            'description' => $inc['description'],
            'lat'         => (float)$inc['latitude'],
            'lng'         => (float)$inc['longitude'],
            'driver_name' => $inc['driver_name'],
            'upvotes'     => (int)$inc['upvotes'],
            'downvotes'   => (int)$inc['downvotes'],
            'distance_km' => round((float)$inc['distance_km'], 2),
            'created_at'  => $inc['created_at']
        ];
    }, $incidents);

    jsonResponse([
        'success' => true,
        'center'  => ['lat' => $lat, 'lng' => $lng],
        'radius_km' => $radius_km,
        'count'   => count($formatted),
        'incidents' => $formatted
    ]);
}

// ============================================================
// POST - Criar novo incidente (requer motorista autenticado)
// ============================================================
if ($method === 'POST') {
    $input = getInput();

    $driver_id = (int)($input['driver_id'] ?? 0);
    $type      = $input['type'] ?? '';
    $description = $input['description'] ?? '';
    $lat       = (float)($input['latitude'] ?? $input['lat'] ?? 0);
    $lng       = (float)($input['longitude'] ?? $input['lng'] ?? 0);

    // Validacoes
    if (!$driver_id) {
        jsonResponse(['success' => false, 'error' => 'driver_id obrigatorio'], 400);
    }

    $validTypes = ['acidente','buraco','policia','alagamento','obra','transito','perigo','outro'];
    if (!in_array($type, $validTypes)) {
        jsonResponse(['success' => false, 'error' => 'type invalido. Valores: ' . implode(', ', $validTypes)], 400);
    }

    if (!$lat || !$lng) {
        jsonResponse(['success' => false, 'error' => 'latitude e longitude obrigatorios'], 400);
    }

    $pdo = getDB();

    // Verificar se motorista existe
    $driver = validateDriver($driver_id);
    if (!$driver) {
        jsonResponse(['success' => false, 'error' => 'Motorista nao encontrado'], 404);
    }

    // Duracao de expiracao por tipo (em horas)
    $expirationHours = [
        'acidente'   => 2,
        'buraco'     => 24,
        'policia'    => 1,
        'alagamento' => 4,
        'obra'       => 48,
        'transito'   => 1,
        'perigo'     => 2,
        'outro'      => 2
    ];

    $hours = $expirationHours[$type] ?? 2;

    // Sanitizar descricao
    $description = mb_substr(trim($description), 0, 255);

    $stmt = $pdo->prepare("
        INSERT INTO om_market_map_incidents
            (driver_id, driver_name, type, description, latitude, longitude, expires_at)
        VALUES
            (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))
    ");
    $stmt->execute([
        $driver_id,
        $driver['name'] ?? 'Entregador',
        $type,
        $description ?: null,
        $lat,
        $lng,
        $hours
    ]);

    $incidentId = (int)$pdo->lastInsertId();

    // Labels e icones
    $typeLabels = [
        'acidente'   => 'Acidente',
        'buraco'     => 'Buraco na via',
        'policia'    => 'Policia',
        'alagamento' => 'Alagamento',
        'obra'       => 'Obra na via',
        'transito'   => 'Transito intenso',
        'perigo'     => 'Perigo',
        'outro'      => 'Outro'
    ];

    $typeIcons = [
        'acidente'   => "\xF0\x9F\x9A\x97",
        'buraco'     => "\xE2\x9A\xA0\xEF\xB8\x8F",
        'policia'    => "\xF0\x9F\x9A\x94",
        'alagamento' => "\xF0\x9F\x8C\x8A",
        'obra'       => "\xF0\x9F\x9A\xA7",
        'transito'   => "\xF0\x9F\x9A\xA6",
        'perigo'     => "\xE2\x9B\x94",
        'outro'      => "\xF0\x9F\x93\x8D"
    ];

    jsonResponse([
        'success' => true,
        'message' => 'Incidente reportado com sucesso!',
        'incident' => [
            'id'          => $incidentId,
            'type'        => $type,
            'type_label'  => $typeLabels[$type] ?? $type,
            'type_icon'   => $typeIcons[$type] ?? '',
            'description' => $description ?: null,
            'lat'         => $lat,
            'lng'         => $lng,
            'driver_id'   => $driver_id,
            'driver_name' => $driver['name'] ?? 'Entregador',
            'upvotes'     => 0,
            'downvotes'   => 0,
            'expires_in_hours' => $hours
        ]
    ]);
}

// ============================================================
// PUT - Votar em incidente (upvote/downvote)
// ============================================================
if ($method === 'PUT') {
    $input = getInput();

    $incident_id = (int)($input['incident_id'] ?? $input['id'] ?? 0);
    $vote        = $input['vote'] ?? '';

    if (!$incident_id) {
        jsonResponse(['success' => false, 'error' => 'incident_id obrigatorio'], 400);
    }

    if (!in_array($vote, ['up', 'down'])) {
        jsonResponse(['success' => false, 'error' => 'vote deve ser "up" ou "down"'], 400);
    }

    $pdo = getDB();

    // Verificar se incidente existe e esta ativo
    $stmt = $pdo->prepare("SELECT * FROM om_market_map_incidents WHERE id = ?");
    $stmt->execute([$incident_id]);
    $incident = $stmt->fetch();

    if (!$incident) {
        jsonResponse(['success' => false, 'error' => 'Incidente nao encontrado'], 404);
    }

    if (!$incident['is_active']) {
        jsonResponse(['success' => false, 'error' => 'Incidente ja desativado'], 400);
    }

    // Aplicar voto
    if ($vote === 'up') {
        $stmt = $pdo->prepare("UPDATE om_market_map_incidents SET upvotes = upvotes + 1 WHERE id = ?");
        $stmt->execute([$incident_id]);
        $incident['upvotes']++;
    } else {
        $stmt = $pdo->prepare("UPDATE om_market_map_incidents SET downvotes = downvotes + 1 WHERE id = ?");
        $stmt->execute([$incident_id]);
        $incident['downvotes']++;
    }

    // Se downvotes > upvotes + 3, desativar automaticamente
    $newUpvotes = (int)$incident['upvotes'];
    $newDownvotes = (int)$incident['downvotes'];
    $deactivated = false;

    if ($newDownvotes > $newUpvotes + 3) {
        $stmt = $pdo->prepare("UPDATE om_market_map_incidents SET is_active = 0 WHERE id = ?");
        $stmt->execute([$incident_id]);
        $deactivated = true;
    }

    jsonResponse([
        'success' => true,
        'message' => $deactivated
            ? 'Voto registrado. Incidente desativado por votos negativos.'
            : 'Voto registrado com sucesso!',
        'incident' => [
            'id'          => $incident_id,
            'upvotes'     => $newUpvotes,
            'downvotes'   => $newDownvotes,
            'is_active'   => $deactivated ? 0 : 1,
            'deactivated_by_votes' => $deactivated
        ]
    ]);
}

// ============================================================
// DELETE - Desativar incidente proprio
// ============================================================
if ($method === 'DELETE') {
    $incident_id = (int)($_GET['id'] ?? 0);
    $driver_id   = (int)($_GET['driver_id'] ?? 0);

    if (!$incident_id) {
        jsonResponse(['success' => false, 'error' => 'id do incidente obrigatorio'], 400);
    }

    if (!$driver_id) {
        jsonResponse(['success' => false, 'error' => 'driver_id obrigatorio'], 400);
    }

    $pdo = getDB();

    // Buscar incidente
    $stmt = $pdo->prepare("SELECT * FROM om_market_map_incidents WHERE id = ?");
    $stmt->execute([$incident_id]);
    $incident = $stmt->fetch();

    if (!$incident) {
        jsonResponse(['success' => false, 'error' => 'Incidente nao encontrado'], 404);
    }

    // Somente o criador pode desativar
    if ((int)$incident['driver_id'] !== $driver_id) {
        jsonResponse(['success' => false, 'error' => 'Apenas o criador pode desativar este incidente'], 403);
    }

    if (!$incident['is_active']) {
        jsonResponse(['success' => false, 'error' => 'Incidente ja desativado'], 400);
    }

    $stmt = $pdo->prepare("UPDATE om_market_map_incidents SET is_active = 0 WHERE id = ?");
    $stmt->execute([$incident_id]);

    jsonResponse([
        'success' => true,
        'message' => 'Incidente desativado com sucesso!',
        'incident_id' => $incident_id
    ]);
}

// Metodo nao suportado
jsonResponse(['success' => false, 'error' => 'Metodo nao suportado'], 405);
