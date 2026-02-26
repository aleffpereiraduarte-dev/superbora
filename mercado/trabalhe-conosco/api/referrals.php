<?php
/**
 * API: Programa de Indicações
 * GET /api/referrals.php - Listar indicações
 * POST /api/referrals.php - Enviar convite
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Listar indicações
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Código de indicação do trabalhador
        $stmt = $db->prepare("
            SELECT referral_code FROM " . table('workers') . " WHERE id = ?
        ");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();

        $referralCode = $worker['referral_code'];

        // Se não tiver, gerar
        if (!$referralCode) {
            $referralCode = strtoupper(substr(md5($workerId . time()), 0, 8));
            $stmt = $db->prepare("UPDATE " . table('workers') . " SET referral_code = ? WHERE id = ?");
            $stmt->execute([$referralCode, $workerId]);
        }

        // Indicações feitas
        $stmt = $db->prepare("
            SELECT 
                r.id, r.referred_name, r.referred_phone, r.status, r.reward_amount,
                r.created_at, r.completed_at
            FROM " . table('referrals') . " r
            WHERE r.referrer_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$workerId]);
        $referrals = $stmt->fetchAll();

        // Estatísticas
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN reward_amount ELSE 0 END), 0) as total_earned
            FROM " . table('referrals') . "
            WHERE referrer_id = ?
        ");
        $stmt->execute([$workerId]);
        $stats = $stmt->fetch();

        // Regras do programa
        $rules = [
            'reward_per_referral' => 50,
            'bonus_after_5' => 100,
            'referred_bonus' => 25,
            'min_orders_to_complete' => 10
        ];

        jsonSuccess([
            'referral_code' => $referralCode,
            'referral_link' => 'https://onemundo.com.br/trabalhe?ref=' . $referralCode,
            'referrals' => $referrals,
            'stats' => $stats,
            'rules' => $rules
        ]);

    } catch (Exception $e) {
        error_log("Referrals GET error: " . $e->getMessage());
        jsonError('Erro ao buscar indicações', 500);
    }
}

// POST - Enviar convite
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();

    $name = trim($input['name'] ?? '');
    $phone = preg_replace('/\D/', '', $input['phone'] ?? '');
    $method = $input['method'] ?? 'whatsapp'; // whatsapp, sms, copy

    if (empty($name) || strlen($phone) < 10) {
        jsonError('Nome e telefone são obrigatórios');
    }

    try {
        // Verificar se já foi indicado
        $stmt = $db->prepare("
            SELECT id FROM " . table('referrals') . "
            WHERE referred_phone = ?
        ");
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            jsonError('Este telefone já foi indicado');
        }

        // Verificar se já é cadastrado
        $stmt = $db->prepare("
            SELECT id FROM " . table('workers') . " WHERE phone = ?
        ");
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            jsonError('Esta pessoa já está cadastrada');
        }

        // Obter código do indicador
        $stmt = $db->prepare("SELECT referral_code, name FROM " . table('workers') . " WHERE id = ?");
        $stmt->execute([$workerId]);
        $referrer = $stmt->fetch();

        // Registrar indicação
        $stmt = $db->prepare("
            INSERT INTO " . table('referrals') . "
            (referrer_id, referred_name, referred_phone, status, created_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$workerId, $name, $phone]);
        $referralId = $db->lastInsertId();

        // Gerar link
        $link = 'https://onemundo.com.br/trabalhe?ref=' . $referrer['referral_code'];
        
        // Mensagem de convite
        $message = "Olá $name! O {$referrer['name']} está te convidando para trabalhar no OneMundo. " .
                   "Ganhe dinheiro fazendo entregas no seu horário! " .
                   "Cadastre-se: $link";

        $response = [
            'referral_id' => $referralId,
            'link' => $link,
            'message' => $message
        ];

        // Gerar links de compartilhamento
        if ($method === 'whatsapp') {
            $response['share_url'] = 'https://wa.me/55' . $phone . '?text=' . urlencode($message);
        }

        jsonSuccess($response, 'Convite registrado');

    } catch (Exception $e) {
        error_log("Referrals POST error: " . $e->getMessage());
        jsonError('Erro ao enviar convite', 500);
    }
}

jsonError('Método não permitido', 405);
