<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API DE FRETE INTELIGENTE - OneMundo Mercado
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Integra:
 * - Frete do parceiro (mercado local)
 * - Melhor Envio (Correios/Transportadoras)
 * - Desconto de Membership
 *
 * Ações:
 * - calcular: Calcula frete com desconto de membership
 * - status: Retorna status do membership do usuário
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Conectar ao banco
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

// Sessão
session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$customer_id = $_SESSION['customer_id'] ?? 0;
$partner_id = $_GET['partner_id'] ?? $_POST['partner_id'] ?? $_SESSION['market_partner_id'] ?? 1;

// Input
$input_raw = file_get_contents('php://input');
$input = json_decode($input_raw, true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? 'calcular';

/**
 * Buscar dados do membership do usuário
 */
function getMembershipData($pdo, $customer_id) {
    if (!$customer_id) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT m.member_id, m.level_id, m.status, m.free_shipping_used, m.annual_points,
                   l.name as level_name, l.slug as level_code, l.icon as level_icon,
                   l.free_shipping_qty, l.shipping_discount, l.color,
                   l.color_primary, l.color_secondary
            FROM om_membership_members m
            JOIN om_membership_levels l ON m.level_id = l.level_id
            WHERE m.customer_id = ? AND m.status = 'active'
        ");
        $stmt->execute([$customer_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            return null;
        }

        // Calcular fretes disponíveis no mês
        $free_qty = (int)$member['free_shipping_qty'];
        $used = (int)$member['free_shipping_used'];

        // Verificar se precisa resetar (novo mês)
        $stmt2 = $pdo->prepare("SELECT free_shipping_reset_date FROM om_membership_members WHERE member_id = ?");
        $stmt2->execute([$member['member_id']]);
        $reset_date = $stmt2->fetchColumn();

        $current_month = date('Y-m');
        if ($reset_date && substr($reset_date, 0, 7) !== $current_month) {
            // Resetar contador do mês
            $pdo->prepare("UPDATE om_membership_members SET free_shipping_used = 0, free_shipping_reset_date = ? WHERE member_id = ?")
                ->execute([date('Y-m-d'), $member['member_id']]);
            $used = 0;
        }

        $available = $free_qty === 999999 ? 'ilimitado' : max(0, $free_qty - $used);
        $discount_percent = (float)$member['shipping_discount'];

        return [
            'is_member' => true,
            'member_id' => (int)$member['member_id'],
            'level_id' => (int)$member['level_id'],
            'level_code' => $member['level_code'],
            'level_name' => $member['level_name'],
            'level_icon' => $member['level_icon'],
            'color' => $member['color'],
            'color_primary' => $member['color_primary'],
            'color_secondary' => $member['color_secondary'],
            'shipping_discount' => $discount_percent,
            'free_shipping_qty' => $free_qty,
            'free_shipping_used' => $used,
            'free_shipping_available' => $available,
            'has_free_shipping' => ($available === 'ilimitado' || $available > 0) && $discount_percent >= 100,
            'annual_points' => (int)$member['annual_points']
        ];
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Buscar dados do parceiro (mercado)
 */
function getPartnerData($pdo, $partner_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT partner_id, name, city, state, delivery_fee, delivery_time_min,
                   free_delivery_above, lat, lng
            FROM om_market_partners
            WHERE partner_id = ? AND status = '1'
        ");
        $stmt->execute([$partner_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Calcular frete via Melhor Envio (para produtos não-mercado)
 */
function calcularMelhorEnvio($pdo, $cep_origem, $cep_destino, $peso, $valor) {
    try {
        // Buscar token
        $stmt = $pdo->query("SELECT valor FROM om_entrega_config WHERE chave = 'melhor_envio_token'");
        $token = $stmt->fetchColumn();

        if (!$token) {
            return null;
        }

        $payload = [
            'from' => ['postal_code' => preg_replace('/\D/', '', $cep_origem)],
            'to' => ['postal_code' => preg_replace('/\D/', '', $cep_destino)],
            'products' => [[
                'id' => '1',
                'width' => 15,
                'height' => 10,
                'length' => 20,
                'weight' => max(0.3, $peso),
                'insurance_value' => $valor,
                'quantity' => 1
            ]],
            'services' => '1,2,3,4,17'
        ];

        $ch = curl_init('https://melhorenvio.com.br/api/v2/me/shipment/calculate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
                'User-Agent: OneMundoMercado/1.0'
            ],
            CURLOPT_TIMEOUT => 15
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        $result = json_decode($response, true);
        $opcoes = [];

        foreach ($result as $servico) {
            if (isset($servico['error']) || !isset($servico['price'])) continue;

            $opcoes[] = [
                'id' => 'me_' . $servico['id'],
                'nome' => $servico['name'] ?? $servico['company']['name'],
                'empresa' => $servico['company']['name'] ?? 'Correios',
                'preco' => (float)$servico['price'],
                'prazo_dias' => (int)$servico['delivery_time'],
                'prazo_texto' => $servico['delivery_time'] . ' dias úteis'
            ];
        }

        usort($opcoes, fn($a, $b) => $a['prazo_dias'] <=> $b['prazo_dias']);
        return $opcoes;

    } catch (Exception $e) {
        return null;
    }
}

switch ($action) {

    // ========================================================================
    // CALCULAR - Calcular frete com membership
    // ========================================================================
    case 'calcular':
        $cep_destino = preg_replace('/\D/', '', $input['cep'] ?? $_GET['cep'] ?? '');
        $subtotal = (float)($input['subtotal'] ?? $_GET['subtotal'] ?? 0);
        $peso = (float)($input['peso'] ?? $_GET['peso'] ?? 1);
        $use_partner = $partner_id > 0;

        // Buscar dados do membership
        $membership = getMembershipData($pdo, $customer_id);

        // Buscar dados do parceiro
        $partner = $use_partner ? getPartnerData($pdo, $partner_id) : null;

        $opcoes = [];

        // === FRETE DO MERCADO (parceiro local) ===
        if ($partner) {
            $frete_base = (float)($partner['delivery_fee'] ?? 7.99);
            $tempo = (int)($partner['delivery_time_min'] ?? 45);
            $gratis_acima = (float)($partner['free_delivery_above'] ?? 0);

            // Verificar frete grátis por valor mínimo
            $frete_gratis_valor = $gratis_acima > 0 && $subtotal >= $gratis_acima;

            // Aplicar desconto de membership
            $frete_final = $frete_base;
            $desconto_membership = 0;
            $frete_gratis_membership = false;

            if ($membership) {
                $discount_percent = $membership['shipping_discount'];
                $has_free = $membership['has_free_shipping'];

                if ($has_free && ($membership['free_shipping_available'] === 'ilimitado' || $membership['free_shipping_available'] > 0)) {
                    // Frete grátis pelo membership
                    $frete_gratis_membership = true;
                    $desconto_membership = $frete_base;
                    $frete_final = 0;
                } elseif ($discount_percent > 0) {
                    // Desconto percentual
                    $desconto_membership = $frete_base * ($discount_percent / 100);
                    $frete_final = max(0, $frete_base - $desconto_membership);
                }
            }

            // Se frete grátis por valor, sobrescreve
            if ($frete_gratis_valor) {
                $frete_final = 0;
            }

            $opcoes[] = [
                'id' => 'mercado_entrega',
                'tipo' => 'mercado',
                'nome' => 'Entrega ' . ($partner['name'] ?? 'Mercado'),
                'empresa' => $partner['name'] ?? 'Mercado Local',
                'preco_original' => $frete_base,
                'preco_final' => round($frete_final, 2),
                'desconto_membership' => round($desconto_membership, 2),
                'is_free' => $frete_final == 0,
                'free_reason' => $frete_gratis_valor ? 'valor_minimo' : ($frete_gratis_membership ? 'membership' : null),
                'prazo_minutos' => $tempo,
                'prazo_texto' => $tempo . ' min',
                'disponivel' => true
            ];

            // Entrega expressa (se disponível)
            $frete_express = $frete_base + 5.00;
            $tempo_express = max(15, $tempo - 15);

            $frete_express_final = $frete_express;
            if ($membership && $membership['shipping_discount'] > 0 && !$frete_gratis_valor) {
                $frete_express_final = $frete_express * (1 - $membership['shipping_discount'] / 100);
            }

            $opcoes[] = [
                'id' => 'mercado_express',
                'tipo' => 'mercado_express',
                'nome' => 'Entrega Express',
                'empresa' => $partner['name'] ?? 'Mercado Local',
                'preco_original' => $frete_express,
                'preco_final' => round($frete_express_final, 2),
                'desconto_membership' => round($frete_express - $frete_express_final, 2),
                'is_free' => false,
                'prazo_minutos' => $tempo_express,
                'prazo_texto' => $tempo_express . ' min',
                'disponivel' => true,
                'badge' => 'Mais rápido'
            ];
        }

        // === MELHOR ENVIO (para entregas fora da área) ===
        if ($cep_destino && strlen($cep_destino) === 8 && !$partner) {
            $cep_origem = '01310100'; // CEP padrão SP
            $me_opcoes = calcularMelhorEnvio($pdo, $cep_origem, $cep_destino, $peso, $subtotal);

            if ($me_opcoes) {
                foreach ($me_opcoes as $me) {
                    $preco_final = $me['preco'];
                    $desconto = 0;

                    // Aplicar desconto membership
                    if ($membership && $membership['shipping_discount'] > 0) {
                        $desconto = $me['preco'] * ($membership['shipping_discount'] / 100);
                        $preco_final = max(0, $me['preco'] - $desconto);
                    }

                    $opcoes[] = [
                        'id' => $me['id'],
                        'tipo' => 'correios',
                        'nome' => $me['nome'],
                        'empresa' => $me['empresa'],
                        'preco_original' => $me['preco'],
                        'preco_final' => round($preco_final, 2),
                        'desconto_membership' => round($desconto, 2),
                        'is_free' => $preco_final == 0,
                        'prazo_dias' => $me['prazo_dias'],
                        'prazo_texto' => $me['prazo_texto'],
                        'disponivel' => true
                    ];
                }
            }
        }

        // Ordenar por preço
        usort($opcoes, fn($a, $b) => $a['preco_final'] <=> $b['preco_final']);

        echo json_encode([
            'success' => true,
            'opcoes' => $opcoes,
            'membership' => $membership ? [
                'is_member' => true,
                'level_code' => $membership['level_code'],
                'level_name' => $membership['level_name'],
                'level_icon' => $membership['level_icon'],
                'color' => $membership['color'],
                'shipping_discount' => $membership['shipping_discount'],
                'free_shipping_available' => $membership['free_shipping_available']
            ] : ['is_member' => false],
            'subtotal' => $subtotal,
            'partner_id' => $partner_id
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ========================================================================
    // STATUS - Status do membership
    // ========================================================================
    case 'status':
        $membership = getMembershipData($pdo, $customer_id);

        if ($membership) {
            echo json_encode([
                'success' => true,
                'is_member' => true,
                'data' => $membership
            ]);
        } else {
            // Buscar informações dos níveis para mostrar benefícios
            $stmt = $pdo->query("
                SELECT level_id, name, slug, icon, color, shipping_discount, free_shipping_qty
                FROM om_membership_levels
                WHERE status = '1'
                ORDER BY sort_order
            ");
            $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'is_member' => false,
                'levels' => $levels,
                'promo' => [
                    'price' => 19.90,
                    'price_formatted' => 'R$ 19,90/mês',
                    'benefits' => [
                        'Até 100% de desconto no frete',
                        'Fretes grátis todo mês',
                        'Acumule pontos a cada compra',
                        'Suba de nível automaticamente'
                    ]
                ]
            ]);
        }
        break;

    // ========================================================================
    // USAR-FRETE - Registrar uso de frete grátis
    // ========================================================================
    case 'usar-frete':
        if (!$customer_id) {
            echo json_encode(['success' => false, 'error' => 'Não logado']);
            exit;
        }

        $membership = getMembershipData($pdo, $customer_id);
        if (!$membership || !$membership['has_free_shipping']) {
            echo json_encode(['success' => false, 'error' => 'Sem frete grátis disponível']);
            exit;
        }

        // Registrar uso
        $stmt = $pdo->prepare("
            UPDATE om_membership_members
            SET free_shipping_used = free_shipping_used + 1,
                free_shipping_reset_date = COALESCE(free_shipping_reset_date, ?)
            WHERE member_id = ?
        ");
        $stmt->execute([date('Y-m-d'), $membership['member_id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Frete grátis aplicado!',
            'remaining' => $membership['free_shipping_available'] === 'ilimitado'
                ? 'ilimitado'
                : max(0, $membership['free_shipping_available'] - 1)
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}
