<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO AUTH SYSTEM
 * Sistema de autenticação unificado com JWT-like tokens
 * ══════════════════════════════════════════════════════════════════════════════
 */

class OmAuth {
    private static $instance = null;
    private $pdo;
    private $secretKey;
    private $tokenExpiry = 86400 * 7; // 7 dias

    const USER_TYPE_SHOPPER = 'shopper';
    const USER_TYPE_MOTORISTA = 'motorista';
    const USER_TYPE_ADMIN = 'admin';        // Funcionário contratado pelo RH
    const USER_TYPE_PARTNER = 'partner';    // Mercado cadastrado pelo RH
    const USER_TYPE_RH = 'rh';              // Super admin - gerencia tudo

    private function __construct() {
        $this->secretKey = $_ENV['JWT_SECRET'] ?? $_ENV['APP_KEY'] ?? 'om_secure_key_' . md5(__FILE__);
    }

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
     * Gera um token seguro para o usuário
     */
    public function generateToken(string $userType, int $userId, array $extraData = []): string {
        $payload = [
            'type' => $userType,
            'uid' => $userId,
            'iat' => time(),
            'exp' => time() + $this->tokenExpiry,
            'jti' => bin2hex(random_bytes(16)),
            'data' => $extraData
        ];

        $payloadBase64 = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $payloadBase64, $this->secretKey);

        $token = $payloadBase64 . '.' . $signature;

        // Armazenar token no banco para validação e revogação
        $this->storeToken($userType, $userId, $payload['jti'], $payload['exp']);

        return $token;
    }

    /**
     * Valida um token e retorna os dados do payload
     */
    public function validateToken(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadBase64, $signature] = $parts;

        // Verificar assinatura
        $expectedSignature = hash_hmac('sha256', $payloadBase64, $this->secretKey);
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decodificar payload
        $payload = json_decode(base64_decode($payloadBase64), true);
        if (!$payload) {
            return null;
        }

        // Verificar expiração
        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }

        // Verificar se token não foi revogado
        // Tokens must have uid and jti fields (homegrown tokens use user_id instead — reject gracefully)
        $uid = $payload['uid'] ?? $payload['user_id'] ?? null;
        $jti = $payload['jti'] ?? null;
        if ($uid === null || $jti === null) {
            return null;
        }
        if (!$this->isTokenValid($payload['type'], (int)$uid, $jti)) {
            return null;
        }
        // Normalize uid for callers
        $payload['uid'] = (int)$uid;

        return $payload;
    }

    /**
     * Extrai token do header Authorization
     */
    public function getTokenFromRequest(): ?string {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // SECURITY: Removed query string token fallback to prevent token leakage in URLs/logs
        // Tokens must only be sent via Authorization header
        return null;
    }

    /**
     * Middleware: Requer autenticação
     */
    public function requireAuth(?string $requiredType = null): array {
        $token = $this->getTokenFromRequest();

        if (!$token) {
            $this->unauthorized('Token de autenticação não fornecido');
        }

        $payload = $this->validateToken($token);

        if (!$payload) {
            $this->unauthorized('Token inválido ou expirado');
        }

        if ($requiredType && $payload['type'] !== $requiredType) {
            $this->forbidden('Acesso não autorizado para este tipo de usuário');
        }

        return $payload;
    }

    /**
     * Middleware: Requer autenticação de admin (funcionário contratado pelo RH)
     */
    public function requireAdmin(): array {
        $payload = $this->requireAuth();

        // Admin pode ser funcionário (admin) ou RH (super admin)
        if (!in_array($payload['type'], [self::USER_TYPE_ADMIN, self::USER_TYPE_RH])) {
            $this->forbidden('Acesso restrito a administradores');
        }

        // Verificar se admin foi contratado/aprovado pelo RH e está ativo
        if ($payload['type'] === self::USER_TYPE_ADMIN && !$this->isAdminActive($payload['uid'])) {
            $this->forbidden('Conta de administrador inativa ou não aprovada pelo RH');
        }

        return $payload;
    }

    /**
     * Middleware: Requer autenticação de RH (super admin)
     */
    public function requireRH(): array {
        $payload = $this->requireAuth(self::USER_TYPE_RH);

        if (!$this->isRHActive($payload['uid'])) {
            $this->forbidden('Conta de RH inativa');
        }

        return $payload;
    }

    /**
     * Middleware: Requer autenticação de Partner (mercado cadastrado pelo RH)
     */
    public function requirePartner(int $requestedPartnerId = null): array {
        $payload = $this->requireAuth(self::USER_TYPE_PARTNER);

        // SECURITY: Block 2FA temp tokens — require full auth
        if (!empty($payload['data']['2fa_pending'])) {
            $this->forbidden('Verificacao 2FA obrigatoria');
        }

        // Verificar se mercado foi aprovado pelo RH
        if (!$this->isPartnerApproved($payload['uid'])) {
            $this->forbidden('Mercado ainda não foi aprovado pelo RH');
        }

        if ($requestedPartnerId !== null && $payload['uid'] !== $requestedPartnerId) {
            $this->forbidden('Você não tem permissão para acessar este recurso');
        }

        return $payload;
    }

    /**
     * Middleware: Requer autenticação de shopper e valida ownership
     * Shopper precisa ter cadastro APROVADO pelo RH
     */
    public function requireShopper(int $requestedShopperId = null): array {
        $payload = $this->requireAuth(self::USER_TYPE_SHOPPER);

        // Se um ID específico foi solicitado, verificar se pertence ao usuário autenticado
        if ($requestedShopperId !== null && $payload['uid'] !== $requestedShopperId) {
            $this->forbidden('Você não tem permissão para acessar este recurso');
        }

        // Verificar se shopper foi aprovado pelo RH
        if (!$this->isShopperApproved($payload['uid'])) {
            $this->forbidden('Seu cadastro está pendente de aprovação pelo RH. Aguarde a análise.');
        }

        return $payload;
    }

    /**
     * Verifica se o shopper foi aprovado pelo RH
     */
    public function isShopperApproved(int $shopperId): bool {
        if (!$this->pdo) return true;

        try {
            $stmt = $this->pdo->prepare("
                SELECT status FROM om_market_shoppers
                WHERE shopper_id = ?
            ");
            $stmt->execute([$shopperId]);
            $result = $stmt->fetch();

            // Status: 0 = pendente, 1 = aprovado, 2 = rejeitado, 3 = suspenso
            return $result && $result['status'] == 1;
        } catch (Exception $e) {
            error_log("[OmAuth] Erro ao verificar aprovação shopper: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica status do cadastro do shopper (para mostrar mensagem apropriada)
     */
    public function getShopperStatus(int $shopperId): array {
        if (!$this->pdo) {
            return ['status' => 'unknown', 'message' => 'Não foi possível verificar'];
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT status, motivo_rejeicao, data_aprovacao, aprovado_por
                FROM om_market_shoppers
                WHERE shopper_id = ?
            ");
            $stmt->execute([$shopperId]);
            $result = $stmt->fetch();

            if (!$result) {
                return ['status' => 'not_found', 'message' => 'Cadastro não encontrado'];
            }

            $statusMap = [
                0 => ['status' => 'pending', 'message' => 'Aguardando aprovação do RH'],
                1 => ['status' => 'approved', 'message' => 'Cadastro aprovado'],
                2 => ['status' => 'rejected', 'message' => 'Cadastro rejeitado: ' . ($result['motivo_rejeicao'] ?? 'Motivo não informado')],
                3 => ['status' => 'suspended', 'message' => 'Cadastro suspenso temporariamente']
            ];

            return $statusMap[$result['status']] ?? ['status' => 'unknown', 'message' => 'Status desconhecido'];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => 'Erro ao verificar status'];
        }
    }

    /**
     * Middleware: Requer autenticação de motorista e valida ownership
     */
    public function requireMotorista(int $requestedMotoristaId = null): array {
        $payload = $this->requireAuth(self::USER_TYPE_MOTORISTA);

        if ($requestedMotoristaId !== null && $payload['uid'] !== $requestedMotoristaId) {
            $this->forbidden('Você não tem permissão para acessar este recurso');
        }

        return $payload;
    }

    /**
     * Revoga todos os tokens de um usuário (logout global)
     */
    public function revokeAllTokens(string $userType, int $userId): bool {
        if (!$this->pdo) return false;

        try {
            $stmt = $this->pdo->prepare("
                UPDATE om_auth_tokens
                SET revoked = 1, revoked_at = NOW()
                WHERE user_type = ? AND user_id = ?
            ");
            return $stmt->execute([$userType, $userId]);
        } catch (Exception $e) {
            error_log("[OmAuth] Erro ao revogar tokens: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Revoga um token específico
     */
    public function revokeToken(string $jti): bool {
        if (!$this->pdo) return false;

        try {
            $stmt = $this->pdo->prepare("
                UPDATE om_auth_tokens
                SET revoked = 1, revoked_at = NOW()
                WHERE jti = ?
            ");
            return $stmt->execute([$jti]);
        } catch (Exception $e) {
            error_log("[OmAuth] Erro ao revogar token: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Hash seguro para senhas
     */
    public function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }

    /**
     * Verifica senha
     */
    public function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    // ══════════════════════════════════════════════════════════════════════
    // MÉTODOS PRIVADOS
    // ══════════════════════════════════════════════════════════════════════

    private function storeToken(string $userType, int $userId, string $jti, int $expiresAt): void {
        if (!$this->pdo) return;

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO om_auth_tokens (user_type, user_id, jti, expires_at, created_at)
                VALUES (?, ?, ?, TO_TIMESTAMP(?), NOW())
            ");
            $stmt->execute([$userType, $userId, $jti, $expiresAt]);
        } catch (Exception $e) {
            // Tabela pode não existir ainda, apenas logar
            error_log("[OmAuth] Erro ao armazenar token: " . $e->getMessage());
        }
    }

    private function isTokenValid(string $userType, int $userId, string $jti): bool {
        if (!$this->pdo) return true;

        try {
            $stmt = $this->pdo->prepare("
                SELECT revoked FROM om_auth_tokens
                WHERE user_type = ? AND user_id = ? AND jti = ?
            ");
            $stmt->execute([$userType, $userId, $jti]);
            $row = $stmt->fetch();

            // Token not found in DB: accept it (replication lag between primary/replica)
            // Signature was already verified by validateToken(), so token is authentic
            if (!$row) return true;

            // Only reject if explicitly revoked
            return $row['revoked'] == 0;
        } catch (Exception $e) {
            return true;
        }
    }

    private function isAdminActive(int $adminId): bool {
        if (!$this->pdo) return true;

        try {
            // Admin precisa ter status ativo E ter sido aprovado pelo RH
            $stmt = $this->pdo->prepare("
                SELECT 1 FROM om_admins
                WHERE admin_id = ? AND status = 1 AND aprovado_rh = 1
            ");
            $stmt->execute([$adminId]);
            return $stmt->fetch() !== false;
        } catch (Exception $e) {
            // Tabela pode não existir, tenta formato antigo
            try {
                $stmt = $this->pdo->prepare("SELECT 1 FROM om_admins WHERE admin_id = ? AND status = 1");
                $stmt->execute([$adminId]);
                return $stmt->fetch() !== false;
            } catch (Exception $e2) {
                return true;
            }
        }
    }

    private function isRHActive(int $rhId): bool {
        if (!$this->pdo) return true;

        try {
            $stmt = $this->pdo->prepare("
                SELECT 1 FROM om_rh_users
                WHERE rh_id = ? AND status = 1
            ");
            $stmt->execute([$rhId]);
            return $stmt->fetch() !== false;
        } catch (Exception $e) {
            // Pode usar tabela de admins com flag is_rh
            try {
                $stmt = $this->pdo->prepare("
                    SELECT 1 FROM om_admins
                    WHERE admin_id = ? AND status = 1 AND is_rh = 1
                ");
                $stmt->execute([$rhId]);
                return $stmt->fetch() !== false;
            } catch (Exception $e2) {
                return true;
            }
        }
    }

    private function isPartnerApproved(int $partnerId): bool {
        if (!$this->pdo) return true;

        try {
            $stmt = $this->pdo->prepare("
                SELECT status FROM om_market_partners
                WHERE partner_id = ?
            ");
            $stmt->execute([$partnerId]);
            $result = $stmt->fetch();

            // Status 1 = aprovado pelo RH
            return $result && $result['status'] == 1;
        } catch (Exception $e) {
            return false;
        }
    }

    private function unauthorized(string $message): void {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => 'UNAUTHORIZED'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function forbidden(string $message): void {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => 'FORBIDDEN'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Helper global para obter instância
 */
function om_auth(): OmAuth {
    return OmAuth::getInstance();
}
