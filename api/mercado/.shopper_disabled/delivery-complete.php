<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/delivery-complete.php
 * Finaliza entrega com foto de comprovacao e/ou verificacao de PIN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticacao de Shopper APROVADO pelo RH
 * Header: Authorization: Bearer <token>
 *
 * Body (multipart/form-data ou JSON):
 * {
 *   "order_id": 123,
 *   "delivery_type": "handed" | "left_at_door" | "reception",
 *   "pin_entered": "1234",     // Obrigatorio se delivery_type = "handed"
 *   "photo": "base64...",      // Obrigatorio se delivery_type = "left_at_door"
 *   "notes": "Deixado na portaria com Jose",  // Opcional
 *   "lat": -23.550520,         // Opcional - localizacao da entrega
 *   "lng": -46.633308          // Opcional
 * }
 *
 * REGRAS:
 * - delivery_type "handed": PIN obrigatorio, foto opcional
 * - delivery_type "left_at_door": Foto obrigatoria, PIN ignorado
 * - delivery_type "reception": Foto OU PIN obrigatorio
 *
 * SEGURANCA:
 * - Autenticacao obrigatoria
 * - Verificacao de ownership do pedido
 * - Validacao de estado do pedido
 * - Rate limiting
 */

require_once __DIR__ . "/../config/auth.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";

// Rate limiting: 30 entregas por minuto (por shopper)
if (!RateLimiter::check(30, 60)) {
    exit;
}

try {
    $db = getDB();

    // Autenticacao
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    // Parsear input (suporta JSON e multipart)
    $input = getInput();

    // Sanitizar entrada
    $order_id = (int)($input["order_id"] ?? 0);
    $delivery_type = trim($input["delivery_type"] ?? "");
    $pin_entered = trim($input["pin_entered"] ?? $input["pin"] ?? "");
    $notes = trim(substr($input["notes"] ?? "", 0, 500));
    $photo_data = $input["photo"] ?? null;
    $lat = isset($input["lat"]) ? (float)$input["lat"] : null;
    $lng = isset($input["lng"]) ? (float)$input["lng"] : null;

    // Validacoes basicas
    if (!$order_id) {
        response(false, null, "order_id e obrigatorio", 400);
    }

    $delivery_types = ['handed', 'left_at_door', 'reception'];
    if (!in_array($delivery_type, $delivery_types)) {
        response(false, null, "delivery_type invalido. Use: handed, left_at_door ou reception", 400);
    }

    // Validacoes especificas por tipo de entrega (pre-check before photo processing)
    $pin_verified = false;
    $photo_path = null;

    // Pre-validate delivery_type requirements (photo/pin presence)
    switch ($delivery_type) {
        case 'handed':
            if (empty($pin_entered)) {
                response(false, null, "PIN e obrigatorio para entrega em maos. Peca o codigo ao cliente.", 400);
            }
            break;
        case 'left_at_door':
            if (empty($photo_data)) {
                response(false, null, "Foto e obrigatoria quando deixar na porta. Tire uma foto do pedido.", 400);
            }
            break;
        case 'reception':
            if (empty($photo_data) && empty($pin_entered)) {
                response(false, null, "Para entrega em recepcao, informe o PIN ou tire uma foto.", 400);
            }
            break;
    }

    // Processar foto se enviada (before transaction to avoid holding lock during I/O)
    if ($photo_data) {
        $photo_path = processDeliveryPhoto($photo_data, $order_id, $shopper_id);
        if (!$photo_path) {
            response(false, null, "Erro ao processar foto. Tente novamente.", 500);
        }
    }

    // Iniciar transacao
    $db->beginTransaction();

    try {
        // Buscar pedido com lock (FOR UPDATE previne race condition)
        $stmt = $db->prepare("
            SELECT o.*, p.trade_name as parceiro_nome
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.order_id = ?
            FOR UPDATE OF o
        ");
        $stmt->execute([$order_id]);
        $pedido = $stmt->fetch();

        if (!$pedido) {
            $db->rollBack();
            response(false, null, "Pedido nao encontrado", 404);
        }

        // Verificar ownership
        if ($pedido['shopper_id'] != $shopper_id) {
            $db->rollBack();
            response(false, null, "Voce nao tem permissao para finalizar este pedido", 403);
        }

        // Validar estado do pedido
        $allowed_statuses = ['delivering', 'em_entrega', 'out_for_delivery'];
        if (!in_array($pedido['status'], $allowed_statuses)) {
            $db->rollBack();
            response(false, null, "Pedido nao esta em estado de entrega. Status atual: " . $pedido['status'], 409);
        }

        // Validate PIN against locked order data
        switch ($delivery_type) {
            case 'handed':
                if ($pedido['delivery_pin'] && $pin_entered !== $pedido['delivery_pin']) {
                    $db->rollBack();
                    response(false, null, "PIN incorreto. Verifique o codigo com o cliente.", 400);
                }
                $pin_verified = true;
                break;
            case 'reception':
                if (!empty($pin_entered) && $pedido['delivery_pin'] && $pin_entered === $pedido['delivery_pin']) {
                    $pin_verified = true;
                }
                break;
        }

        $now = date('Y-m-d H:i:s');

        // Atualizar pedido com WHERE atomico no status
        $stmt = $db->prepare("
            UPDATE om_market_orders SET
                status = 'entregue',
                delivery_type = ?,
                delivery_photo = ?,
                delivery_notes = ?,
                pin_verified_at = ?,
                photo_taken_at = ?,
                delivery_lat = ?,
                delivery_lng = ?,
                delivered_at = ?,
                updated_at = ?,
                date_modified = ?
            WHERE order_id = ? AND status IN ('delivering', 'em_entrega', 'out_for_delivery')
        ");
        $stmt->execute([
            $delivery_type,
            $photo_path,
            $notes ?: null,
            $pin_verified ? $now : null,
            $photo_path ? $now : null,
            $lat,
            $lng,
            $now,
            $now,
            $now,
            $order_id
        ]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "Pedido ja foi atualizado por outra operacao", 409);
        }

        // Liberar shopper
        $stmt = $db->prepare("
            UPDATE om_market_shoppers SET
                disponivel = 1,
                pedido_atual_id = NULL,
                total_entregas = total_entregas + 1
            WHERE shopper_id = ?
        ");
        $stmt->execute([$shopper_id]);

        $db->commit();

        // Log de auditoria
        logAudit('delivery_complete', 'order', $order_id, null, [
            'delivery_type' => $delivery_type,
            'pin_verified' => $pin_verified,
            'has_photo' => !empty($photo_path),
            'lat' => $lat,
            'lng' => $lng
        ], "Entrega concluida por shopper #$shopper_id - Tipo: $delivery_type");

        // Notificar cliente
        notifyCustomerDeliveryComplete($db, $pedido, $delivery_type, $photo_path);

        // Processar pagamento do shopper
        $pagamento = processarPagamentoEntrega($order_id);

        // Construir resposta
        $responseData = [
            "order_id" => $order_id,
            "order_number" => $pedido['order_number'],
            "status" => "delivered",
            "delivery_type" => $delivery_type,
            "pin_verified" => $pin_verified,
            "photo_saved" => !empty($photo_path),
            "photo_url" => $photo_path ? "/uploads/deliveries/" . basename($photo_path) : null,
            "entregue_em" => $now,
            "pagamento" => $pagamento,
            "mensagem" => getDeliveryMessage($delivery_type, $pin_verified)
        ];

        response(true, $responseData, "Entrega finalizada com sucesso!");

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[delivery-complete] Erro: " . $e->getMessage());
    response(false, null, "Erro ao finalizar entrega. Tente novamente.", 500);
}

/**
 * Processa e salva a foto de entrega
 * @param string $photo_data Base64 ou path do arquivo
 * @param int $order_id
 * @param int $shopper_id
 * @return string|null Path da foto salva ou null em caso de erro
 */
function processDeliveryPhoto(string $photo_data, int $order_id, int $shopper_id): ?string {
    try {
        $upload_dir = '/var/www/html/uploads/deliveries/';

        // Verificar se e base64
        if (preg_match('/^data:image\/(\w+);base64,/', $photo_data, $matches)) {
            $extension = strtolower($matches[1]);
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) {
                error_log("[delivery-photo] Extensao invalida: $extension");
                return null;
            }

            // Decodificar base64
            $photo_data = preg_replace('/^data:image\/\w+;base64,/', '', $photo_data);
            $photo_data = str_replace([' ', "\n", "\r"], ['+', '', ''], $photo_data);
            $decoded = base64_decode($photo_data, true);

            if ($decoded === false) {
                error_log("[delivery-photo] Falha ao decodificar base64");
                return null;
            }

            // Verificar tamanho (max 10MB)
            if (strlen($decoded) > 10 * 1024 * 1024) {
                error_log("[delivery-photo] Arquivo muito grande");
                return null;
            }

            // Gerar nome unico
            $filename = sprintf(
                'delivery_%d_%d_%s.%s',
                $order_id,
                $shopper_id,
                date('Ymd_His'),
                $extension
            );
            $filepath = $upload_dir . $filename;

            // Salvar arquivo
            if (file_put_contents($filepath, $decoded) === false) {
                error_log("[delivery-photo] Falha ao salvar arquivo");
                return null;
            }

            // Ajustar permissoes
            chmod($filepath, 0644);

            return $filepath;
        }

        // Se nao e base64, pode ser upload via multipart (nao implementado aqui)
        error_log("[delivery-photo] Formato nao suportado");
        return null;

    } catch (Exception $e) {
        error_log("[delivery-photo] Excecao: " . $e->getMessage());
        return null;
    }
}

/**
 * Notifica cliente sobre entrega concluida
 */
function notifyCustomerDeliveryComplete(PDO $db, array $pedido, string $delivery_type, ?string $photo_path): void {
    try {
        require_once __DIR__ . '/../config/notify.php';

        $orderNum = $pedido['order_number'] ?? $pedido['order_id'];
        $customer_id = (int)$pedido['customer_id'];

        $typeMessages = [
            'handed' => 'foi entregue em maos',
            'left_at_door' => 'foi deixado na porta',
            'reception' => 'foi entregue na recepcao'
        ];

        $typeMsg = $typeMessages[$delivery_type] ?? 'foi entregue';
        $title = 'Pedido entregue!';
        $body = "Seu pedido #$orderNum $typeMsg.";

        if ($photo_path) {
            $body .= " Veja a foto de comprovacao no app.";
        }

        $data = [
            'order_id' => $pedido['order_id'],
            'order_number' => $orderNum,
            'status' => 'entregue',
            'delivery_type' => $delivery_type,
            'has_photo' => !empty($photo_path),
            'url' => '/pedidos?id=' . $pedido['order_id'] . '&avaliar=1',
            'type' => 'delivery_complete'
        ];

        sendNotification($db, $customer_id, 'customer', $title, $body, $data);

        // Push via NotificationSender para notificacao rica
        try {
            require_once __DIR__ . '/../helpers/NotificationSender.php';
            $notifSender = NotificationSender::getInstance($db);
            $notifSender->notifyCustomer(
                $customer_id,
                $title,
                $body . " Avalie sua experiencia!",
                $data
            );
        } catch (Exception $e) {
            error_log("[delivery-complete] FCM erro: " . $e->getMessage());
        }

        // Pusher para real-time (se configurado)
        try {
            if (class_exists('Pusher\Pusher')) {
                $pusher = new Pusher\Pusher(
                    getenv('PUSHER_APP_KEY') ?: '',
                    getenv('PUSHER_APP_SECRET') ?: '',
                    getenv('PUSHER_APP_ID') ?: '',
                    ['cluster' => getenv('PUSHER_CLUSTER') ?: 'sa1', 'useTLS' => true]
                );
                $pusher->trigger(
                    'customer-' . $customer_id,
                    'delivery-complete',
                    [
                        'order_id' => $pedido['order_id'],
                        'order_number' => $orderNum,
                        'delivery_type' => $delivery_type,
                        'has_photo' => !empty($photo_path),
                        'message' => $body
                    ]
                );
            }
        } catch (Exception $e) {
            error_log("[delivery-complete] Pusher erro: " . $e->getMessage());
        }

    } catch (Exception $e) {
        error_log("[delivery-complete] Notificacao erro: " . $e->getMessage());
    }
}

/**
 * Retorna mensagem amigavel baseada no tipo de entrega
 */
function getDeliveryMessage(string $delivery_type, bool $pin_verified): string {
    switch ($delivery_type) {
        case 'handed':
            return $pin_verified
                ? "Entrega confirmada pelo cliente! PIN verificado."
                : "Entrega em maos concluida!";
        case 'left_at_door':
            return "Pedido deixado na porta. Foto registrada.";
        case 'reception':
            return $pin_verified
                ? "Entrega na recepcao confirmada com PIN."
                : "Entrega na recepcao registrada com foto.";
        default:
            return "Entrega concluida com sucesso!";
    }
}

/**
 * Processa pagamento do shopper apos entrega
 */
function processarPagamentoEntrega(int $order_id): array {
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'http://localhost/api/financeiro/processar-entrega.php',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'order_id' => $order_id,
                'order_type' => 'mercado'
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);

        $result = curl_exec($ch);
        curl_close($ch);

        if ($result) {
            $data = json_decode($result, true);
            if ($data && ($data['success'] ?? false)) {
                return [
                    'processado' => true,
                    'valor' => $data['resumo_financeiro']['distribuicao']['shopper']['recebe'] ?? 0
                ];
            }
        }

        return ['processado' => false, 'mensagem' => 'Pagamento sera processado em breve'];

    } catch (Exception $e) {
        error_log("[processarPagamentoEntrega] Erro: " . $e->getMessage());
        return ['processado' => false, 'mensagem' => 'Pagamento sera processado em breve'];
    }
}
