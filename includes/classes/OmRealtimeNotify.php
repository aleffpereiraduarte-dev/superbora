<?php
/**
 * ==============================================================================
 * OneMundo - Sistema de Notificacoes em Tempo Real
 * ==============================================================================
 *
 * Gerencia notificacoes em tempo real para o sistema de mercado:
 * - Grava eventos em om_realtime_events para SSE
 * - Grava tracking de shoppers em om_shopper_tracking
 * - Notifica mercados, shoppers e clientes
 *
 * Uso:
 *   om_realtime()->notificarEvento('pedido_aceito', [...]);
 *   om_realtime()->notificarMercado($partner_id, 'Novo pedido!', [...]);
 *   om_realtime()->notificarCliente($customer_id, 'Seu pedido foi aceito', [...]);
 */

class OmRealtimeNotify {

    private $db;
    private static $instance = null;

    private function __construct() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setDb(PDO $db): void {
        $this->db = $db;
    }

    /**
     * Notifica evento generico - grava em om_realtime_events
     *
     * @param string $tipo Tipo do evento (pedido_criado, pedido_aceito, coleta_iniciada, etc)
     * @param array $dados Dados do evento
     * @param array $destinatarios Array de destinatarios: [['tipo' => 'mercado', 'id' => 1], ...]
     * @return int|false ID do evento criado ou false em caso de erro
     */
    public function notificarEvento(string $tipo, array $dados, array $destinatarios = []): int|false {
        try {
            $this->ensureDb();

            // Inserir evento principal
            $stmt = $this->db->prepare("
                INSERT INTO om_realtime_events
                (event_type, event_data, created_at, expires_at)
                VALUES (?, ?, NOW(), NOW() + INTERVAL '24 hours') RETURNING event_id
            ");
            $stmt->execute([$tipo, json_encode($dados, JSON_UNESCAPED_UNICODE)]);
            $event_id = (int)$stmt->fetchColumn();

            // Registrar destinatarios
            if (!empty($destinatarios)) {
                $stmt = $this->db->prepare("
                    INSERT INTO om_realtime_event_recipients
                    (event_id, recipient_type, recipient_id, delivered, created_at)
                    VALUES (?, ?, ?, 0, NOW())
                ");
                foreach ($destinatarios as $dest) {
                    $stmt->execute([
                        $event_id,
                        $dest['tipo'] ?? $dest['type'],
                        $dest['id']
                    ]);
                }
            }

            return $event_id;

        } catch (Exception $e) {
            error_log("[OmRealtimeNotify] Erro ao notificar evento: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifica mercado (parceiro)
     */
    public function notificarMercado(int $partner_id, string $titulo, array $dados = []): int|false {
        $dados['titulo'] = $titulo;
        $dados['destinatario'] = 'mercado';
        $dados['partner_id'] = $partner_id;

        return $this->notificarEvento('notificacao_mercado', $dados, [
            ['tipo' => 'mercado', 'id' => $partner_id]
        ]);
    }

    /**
     * Notifica cliente
     */
    public function notificarCliente(int $customer_id, string $titulo, array $dados = []): int|false {
        $dados['titulo'] = $titulo;
        $dados['destinatario'] = 'cliente';
        $dados['customer_id'] = $customer_id;

        return $this->notificarEvento('notificacao_cliente', $dados, [
            ['tipo' => 'cliente', 'id' => $customer_id]
        ]);
    }

    /**
     * Notifica shopper
     */
    public function notificarShopper(int $shopper_id, string $titulo, array $dados = []): int|false {
        $dados['titulo'] = $titulo;
        $dados['destinatario'] = 'shopper';
        $dados['shopper_id'] = $shopper_id;

        return $this->notificarEvento('notificacao_shopper', $dados, [
            ['tipo' => 'shopper', 'id' => $shopper_id]
        ]);
    }

    /**
     * Notifica shoppers proximos de um mercado
     */
    public function notificarShoppersProximos(int $partner_id, string $titulo, array $dados = [], float $raio_km = 5.0): int {
        try {
            $this->ensureDb();

            // Buscar localizacao do mercado
            $stmt = $this->db->prepare("SELECT latitude, longitude FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$partner_id]);
            $mercado = $stmt->fetch();

            if (!$mercado || !$mercado['latitude'] || !$mercado['longitude']) {
                return 0;
            }

            $lat = (float)$mercado['latitude'];
            $lng = (float)$mercado['longitude'];

            // Buscar shoppers ativos e disponiveis dentro do raio
            // Formula Haversine para calcular distancia
            $stmt = $this->db->prepare("
                SELECT shopper_id,
                       (6371 * acos(cos(radians(?)) * cos(radians(latitude))
                       * cos(radians(longitude) - radians(?)) + sin(radians(?))
                       * sin(radians(latitude)))) AS distancia_km
                FROM om_market_shoppers
                WHERE disponivel = 1
                  AND status = 'approved'
                  AND latitude IS NOT NULL
                  AND longitude IS NOT NULL
                HAVING distancia_km <= ?
                ORDER BY distancia_km ASC
                LIMIT 50
            ");
            $stmt->execute([$lat, $lng, $lat, $raio_km]);
            $shoppers = $stmt->fetchAll();

            $count = 0;
            $destinatarios = [];

            foreach ($shoppers as $shopper) {
                $destinatarios[] = ['tipo' => 'shopper', 'id' => $shopper['shopper_id']];
                $count++;
            }

            if (!empty($destinatarios)) {
                $dados['titulo'] = $titulo;
                $dados['partner_id'] = $partner_id;
                $dados['raio_km'] = $raio_km;
                $this->notificarEvento('novo_pedido_disponivel', $dados, $destinatarios);
            }

            return $count;

        } catch (Exception $e) {
            error_log("[OmRealtimeNotify] Erro ao notificar shoppers proximos: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Grava posicao do shopper no tracking
     */
    public function gravarPosicaoShopper(int $shopper_id, float $lat, float $lng, ?int $order_id = null): bool {
        try {
            $this->ensureDb();

            // Inserir no historico de tracking
            $stmt = $this->db->prepare("
                INSERT INTO om_shopper_tracking
                (shopper_id, order_id, latitude, longitude, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$shopper_id, $order_id, $lat, $lng]);

            // Se tem pedido, notifica atualizacao de posicao para o cliente
            if ($order_id) {
                // Buscar customer_id do pedido
                $stmt = $this->db->prepare("SELECT customer_id FROM om_market_orders WHERE order_id = ?");
                $stmt->execute([$order_id]);
                $pedido = $stmt->fetch();

                if ($pedido && $pedido['customer_id']) {
                    $this->notificarEvento('posicao_shopper', [
                        'shopper_id' => $shopper_id,
                        'order_id' => $order_id,
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'timestamp' => date('c')
                    ], [
                        ['tipo' => 'cliente', 'id' => $pedido['customer_id']]
                    ]);
                }
            }

            return true;

        } catch (Exception $e) {
            error_log("[OmRealtimeNotify] Erro ao gravar posicao: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Evento de pedido criado
     */
    public function pedidoCriado(int $order_id, int $partner_id, int $customer_id, array $extras = []): void {
        $dados = array_merge([
            'order_id' => $order_id,
            'partner_id' => $partner_id,
            'customer_id' => $customer_id,
            'status' => 'pendente',
            'timestamp' => date('c')
        ], $extras);

        // Notificar mercado
        $this->notificarMercado($partner_id, "Novo pedido #$order_id recebido!", $dados);

        // Notificar shoppers proximos
        $this->notificarShoppersProximos($partner_id, "Novo pedido disponivel!", $dados);

        // Gravar evento geral
        $this->notificarEvento('pedido_criado', $dados, [
            ['tipo' => 'mercado', 'id' => $partner_id],
            ['tipo' => 'cliente', 'id' => $customer_id]
        ]);
    }

    /**
     * Evento de pedido aceito por shopper
     */
    public function pedidoAceito(int $order_id, int $partner_id, int $customer_id, int $shopper_id, string $shopper_nome): void {
        $dados = [
            'order_id' => $order_id,
            'partner_id' => $partner_id,
            'customer_id' => $customer_id,
            'shopper_id' => $shopper_id,
            'shopper_nome' => $shopper_nome,
            'status' => 'aceito',
            'timestamp' => date('c')
        ];

        // Notificar mercado
        $this->notificarMercado($partner_id, "Shopper $shopper_nome aceitou o pedido #$order_id", $dados);

        // Notificar cliente
        $this->notificarCliente($customer_id, "Seu pedido foi aceito por $shopper_nome", $dados);

        // Gravar evento
        $this->notificarEvento('pedido_aceito', $dados, [
            ['tipo' => 'mercado', 'id' => $partner_id],
            ['tipo' => 'cliente', 'id' => $customer_id]
        ]);
    }

    /**
     * Evento de coleta iniciada
     */
    public function coletaIniciada(int $order_id, int $partner_id, int $customer_id, int $shopper_id): void {
        $dados = [
            'order_id' => $order_id,
            'partner_id' => $partner_id,
            'customer_id' => $customer_id,
            'shopper_id' => $shopper_id,
            'status' => 'coletando',
            'timestamp' => date('c')
        ];

        // Notificar mercado
        $this->notificarMercado($partner_id, "Coleta iniciada - Pedido #$order_id", $dados);

        // Notificar cliente
        $this->notificarCliente($customer_id, "Seu pedido esta sendo preparado", $dados);

        // Gravar evento
        $this->notificarEvento('coleta_iniciada', $dados, [
            ['tipo' => 'mercado', 'id' => $partner_id],
            ['tipo' => 'cliente', 'id' => $customer_id]
        ]);
    }

    /**
     * Evento de item coletado
     */
    public function itemColetado(int $order_id, int $partner_id, int $customer_id, int $coletados, int $total): void {
        $dados = [
            'order_id' => $order_id,
            'partner_id' => $partner_id,
            'customer_id' => $customer_id,
            'coletados' => $coletados,
            'total' => $total,
            'porcentagem' => $total > 0 ? round(($coletados / $total) * 100) : 0,
            'timestamp' => date('c')
        ];

        // Sempre notificar mercado
        $this->notificarMercado($partner_id, "Progresso: $coletados/$total itens coletados", $dados);

        // Notificar cliente a cada 3 itens ou quando completar
        if ($coletados % 3 === 0 || $coletados === $total) {
            $this->notificarCliente($customer_id, "Progresso do pedido: $coletados de $total itens", $dados);
        }

        // Gravar evento
        $this->notificarEvento('item_coletado', $dados, [
            ['tipo' => 'mercado', 'id' => $partner_id]
        ]);
    }

    /**
     * Evento de coleta finalizada
     */
    public function coletaFinalizada(int $order_id, int $partner_id, int $customer_id, int $shopper_id): void {
        $dados = [
            'order_id' => $order_id,
            'partner_id' => $partner_id,
            'customer_id' => $customer_id,
            'shopper_id' => $shopper_id,
            'status' => 'coleta_finalizada',
            'timestamp' => date('c')
        ];

        // Notificar mercado
        $this->notificarMercado($partner_id, "Coleta finalizada, pronto para entrega - Pedido #$order_id", $dados);

        // Notificar cliente
        $this->notificarCliente($customer_id, "Seu pedido esta pronto!", $dados);

        // Gravar evento
        $this->notificarEvento('coleta_finalizada', $dados, [
            ['tipo' => 'mercado', 'id' => $partner_id],
            ['tipo' => 'cliente', 'id' => $customer_id]
        ]);
    }

    /**
     * Evento de entrega iniciada
     */
    public function entregaIniciada(int $order_id, int $partner_id, int $customer_id, int $shopper_id, string $shopper_nome): void {
        $dados = [
            'order_id' => $order_id,
            'partner_id' => $partner_id,
            'customer_id' => $customer_id,
            'shopper_id' => $shopper_id,
            'shopper_nome' => $shopper_nome,
            'status' => 'em_entrega',
            'tracking_ativo' => true,
            'timestamp' => date('c')
        ];

        // Notificar mercado
        $this->notificarMercado($partner_id, "Entrega iniciada - Pedido #$order_id", $dados);

        // Notificar cliente
        $this->notificarCliente($customer_id, "$shopper_nome esta a caminho!", $dados);

        // Gravar evento
        $this->notificarEvento('entrega_iniciada', $dados, [
            ['tipo' => 'mercado', 'id' => $partner_id],
            ['tipo' => 'cliente', 'id' => $customer_id]
        ]);
    }

    /**
     * Evento de entrega finalizada
     */
    public function entregaFinalizada(int $order_id, int $partner_id, int $customer_id, int $shopper_id): void {
        $dados = [
            'order_id' => $order_id,
            'partner_id' => $partner_id,
            'customer_id' => $customer_id,
            'shopper_id' => $shopper_id,
            'status' => 'delivered',
            'timestamp' => date('c')
        ];

        // Notificar mercado
        $this->notificarMercado($partner_id, "Pedido #$order_id entregue!", $dados);

        // Notificar cliente
        $this->notificarCliente($customer_id, "Pedido entregue! Avalie sua experiencia", $dados);

        // Gravar evento
        $this->notificarEvento('entrega_finalizada', $dados, [
            ['tipo' => 'mercado', 'id' => $partner_id],
            ['tipo' => 'cliente', 'id' => $customer_id]
        ]);
    }

    /**
     * Garante que o DB esta configurado
     */
    private function ensureDb(): void {
        if (!$this->db) {
            throw new RuntimeException("Database connection not set. Call setDb() first.");
        }
    }
}

/**
 * Helper function para acesso rapido
 */
function om_realtime(): OmRealtimeNotify {
    return OmRealtimeNotify::getInstance();
}

/**
 * Helper function simplificada para uso nas APIs
 * notificarEvento('tipo', [...dados...])
 */
function notificarEvento(string $tipo, array $dados): int|false {
    return om_realtime()->notificarEvento($tipo, $dados);
}
