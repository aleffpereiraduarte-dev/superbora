<?php
/**
 * Verificar motoristas disponíveis na região
 * Cenário Crítico #32
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit;
}

require_once dirname(__DIR__, 3) . '/config.php';

function getDB() {
    static $db = null;
    if ($db === null) {
        $db = new PDO(
            "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
            DB_USERNAME, DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $db;
}

$lat = floatval($_GET['lat'] ?? 0);
$lng = floatval($_GET['lng'] ?? 0);
$raio = intval($_GET['raio'] ?? 50);

try {
    $db = getDB();
    $motoristas = [];

    if ($lat && $lng) {
        // Buscar motoristas na região
        try {
            $sql = "SELECT id, nome, foto, nota_media as nota, telefone, veiculo_modelo,
                    (6371 * acos(cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat)))) AS distancia
                    FROM boraum_motoristas
                    WHERE online = 1 AND status = 'aprovado'
                    AND lat IS NOT NULL
                    HAVING distancia <= ?
                    ORDER BY distancia ASC
                    LIMIT 20";
            $stmt = $db->prepare($sql);
            $stmt->execute([$lat, $lng, $lat, $raio]);
            $motoristas = $stmt->fetchAll();
        } catch (Exception $e) {
            // Tabela pode não existir
        }

        // Se não encontrou, tentar om_boraum_drivers
        if (empty($motoristas)) {
            try {
                $sql = "SELECT driver_id as id, name as nome, photo as foto, rating as nota, phone as telefone
                        FROM om_boraum_drivers
                        WHERE status = 'approved' OR status = 'ativo'
                        LIMIT 20";
                $motoristas = $db->query($sql)->fetchAll();
            } catch (Exception $e) {
                // Continuar
            }
        }
    }

    $total = count($motoristas);

    if ($total === 0) {
        echo json_encode([
            "success" => false,
            "total" => 0,
            "count" => 0,
            "motoristas" => [],
            "area_atendida" => false,
            "message" => "Não há motoristas disponíveis na sua região",
            "alternativas" => [
                "mensagem" => "Esta área ainda não possui cobertura de motoristas",
                "acoes" => [
                    ["tipo" => "notificar", "label" => "Avise-me quando houver motoristas"],
                    ["tipo" => "agendar", "label" => "Agendar corrida para depois"],
                    ["tipo" => "outras_opcoes", "label" => "Ver outras opções de transporte"]
                ],
                "sugestoes" => [
                    "Tente novamente em alguns minutos",
                    "Considere transporte público: consulte apps como Moovit"
                ],
                "mapa_cobertura" => "/boraum/cobertura"
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            "success" => true,
            "total" => $total,
            "count" => $total,
            "motoristas" => $motoristas,
            "area_atendida" => true,
            "message" => "$total motorista(s) disponível(is) na região"
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "total" => 0,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
