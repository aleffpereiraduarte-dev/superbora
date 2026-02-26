<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO AUDIT LOG
 * Sistema de auditoria para rastrear todas as ações administrativas
 * ══════════════════════════════════════════════════════════════════════════════
 */

class OmAudit {
    private static $instance = null;
    private $pdo;

    // Ações comuns
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_LOGIN = 'login';
    const ACTION_LOGOUT = 'logout';
    const ACTION_APPROVE = 'approve';
    const ACTION_REJECT = 'reject';
    const ACTION_SUSPEND = 'suspend';
    const ACTION_PAY = 'pay';
    const ACTION_REFUND = 'refund';
    const ACTION_VIEW = 'view';
    const ACTION_EXPORT = 'export';

    // Entidades
    const ENTITY_SHOPPER = 'shopper';
    const ENTITY_MOTORISTA = 'motorista';
    const ENTITY_ORDER = 'order';
    const ENTITY_SAQUE = 'saque';
    const ENTITY_PARTNER = 'partner';
    const ENTITY_ADMIN = 'admin';
    const ENTITY_CONFIG = 'config';

    private function __construct() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setDb(PDO $pdo): void {
        $this->pdo = $pdo;
    }

    /**
     * Registra uma ação no log de auditoria
     */
    public function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldData = null,
        ?array $newData = null,
        ?string $description = null,
        ?string $actorType = null,
        ?int $actorId = null
    ): bool {
        if (!$this->pdo) {
            error_log("[OmAudit] PDO não configurado");
            return false;
        }

        try {
            // Detectar actor automaticamente se não fornecido
            if ($actorType === null || $actorId === null) {
                [$actorType, $actorId] = $this->detectActor();
            }

            // Obter informações da requisição
            $ipAddress = $this->getClientIp();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $requestUri = $_SERVER['REQUEST_URI'] ?? null;
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;

            // Serializar dados antigos e novos (removendo dados sensíveis)
            $oldDataJson = $oldData ? json_encode($this->sanitizeData($oldData)) : null;
            $newDataJson = $newData ? json_encode($this->sanitizeData($newData)) : null;

            $stmt = $this->pdo->prepare("
                INSERT INTO om_audit_log (
                    action, entity_type, entity_id,
                    actor_type, actor_id,
                    old_data, new_data, description,
                    ip_address, user_agent, request_uri, request_method,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            return $stmt->execute([
                $action, $entityType, $entityId,
                $actorType, $actorId,
                $oldDataJson, $newDataJson, $description,
                $ipAddress, $userAgent, $requestUri, $requestMethod
            ]);

        } catch (Exception $e) {
            error_log("[OmAudit] Erro ao registrar: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Atalhos para ações comuns
     */
    public function logApproval(string $entityType, int $entityId, ?string $description = null): bool {
        return $this->log(self::ACTION_APPROVE, $entityType, $entityId, null, null, $description);
    }

    public function logRejection(string $entityType, int $entityId, string $reason): bool {
        return $this->log(self::ACTION_REJECT, $entityType, $entityId, null, ['motivo' => $reason], $reason);
    }

    public function logPayment(string $entityType, int $entityId, float $amount, ?string $transactionId = null): bool {
        return $this->log(
            self::ACTION_PAY,
            $entityType,
            $entityId,
            null,
            ['valor' => $amount, 'transaction_id' => $transactionId],
            "Pagamento de R$ " . number_format($amount, 2, ',', '.')
        );
    }

    public function logLogin(string $userType, int $userId, bool $success = true): bool {
        return $this->log(
            self::ACTION_LOGIN,
            $userType,
            $userId,
            null,
            ['success' => $success],
            $success ? 'Login bem-sucedido' : 'Tentativa de login falhou',
            $userType,
            $userId
        );
    }

    public function logStatusChange(string $entityType, int $entityId, string $oldStatus, string $newStatus): bool {
        return $this->log(
            self::ACTION_UPDATE,
            $entityType,
            $entityId,
            ['status' => $oldStatus],
            ['status' => $newStatus],
            "Status alterado de '$oldStatus' para '$newStatus'"
        );
    }

    /**
     * Busca logs de auditoria com filtros
     */
    public function search(array $filters = [], int $limit = 50, int $offset = 0): array {
        if (!$this->pdo) return [];

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['action'])) {
            $where[] = 'action = ?';
            $params[] = $filters['action'];
        }

        if (!empty($filters['entity_type'])) {
            $where[] = 'entity_type = ?';
            $params[] = $filters['entity_type'];
        }

        if (!empty($filters['entity_id'])) {
            $where[] = 'entity_id = ?';
            $params[] = $filters['entity_id'];
        }

        if (!empty($filters['actor_type'])) {
            $where[] = 'actor_type = ?';
            $params[] = $filters['actor_type'];
        }

        if (!empty($filters['actor_id'])) {
            $where[] = 'actor_id = ?';
            $params[] = $filters['actor_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['date_to'];
        }

        $sql = "SELECT * FROM om_audit_log WHERE " . implode(' AND ', $where) .
               " ORDER BY created_at DESC LIMIT ? OFFSET ?";

        $params[] = $limit;
        $params[] = $offset;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("[OmAudit] Erro na busca: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Remove dados sensíveis antes de logar
     */
    private function sanitizeData(array $data): array {
        $sensitiveFields = [
            'password', 'senha', 'password_hash',
            'token', 'api_key', 'secret',
            'pix_chave', 'cpf', 'cnpj',
            'card_number', 'cvv'
        ];

        foreach ($sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = '***REDACTED***';
            }
        }

        return $data;
    }

    /**
     * Detecta quem está fazendo a ação baseado no contexto
     */
    private function detectActor(): array {
        // Tentar obter do token de autenticação
        if (class_exists('OmAuth')) {
            $auth = OmAuth::getInstance();
            $token = $auth->getTokenFromRequest();
            if ($token) {
                $payload = $auth->validateToken($token);
                if ($payload) {
                    return [$payload['type'], $payload['uid']];
                }
            }
        }

        // Tentar obter da sessão
        if (isset($_SESSION['admin_id'])) {
            return ['admin', $_SESSION['admin_id']];
        }

        if (isset($_SESSION['user_id'])) {
            return ['user', $_SESSION['user_id']];
        }

        return ['system', 0];
    }

    /**
     * Obtém IP real do cliente
     */
    private function getClientIp(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Proxy
            'HTTP_X_REAL_IP',            // Nginx
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Se for lista, pegar o primeiro
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validar IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}

/**
 * Helper global
 */
function om_audit(): OmAudit {
    return OmAudit::getInstance();
}
