<?php
/**
 * API: Ajuda e Suporte
 * GET /api/help.php - FAQs e artigos
 * POST /api/help.php - Abrir ticket
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) { jsonError('Erro de conexão', 500); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $category = $_GET['category'] ?? null;
    $search = $_GET['search'] ?? null;
    
    try {
        // FAQs
        $sql = "SELECT id, category, question, answer, views FROM " . table('faqs') . " WHERE is_active = 1";
        $params = [];
        
        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        if ($search) {
            $sql .= " AND (question LIKE ? OR answer LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $sql .= " ORDER BY views DESC LIMIT 20";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $faqs = $stmt->fetchAll();
        
        // Categorias
        $stmt = $db->prepare("SELECT DISTINCT category FROM " . table('faqs') . " WHERE is_active = 1 ORDER BY category");
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Tickets abertos do trabalhador
        $stmt = $db->prepare("
            SELECT id, subject, status, created_at, last_reply_at
            FROM " . table('support_tickets') . "
            WHERE worker_id = ? AND status != 'closed'
            ORDER BY created_at DESC
        ");
        $stmt->execute([$workerId]);
        $openTickets = $stmt->fetchAll();
        
        // Contatos de emergência
        $contacts = [
            ['type' => 'whatsapp', 'number' => '5511999999999', 'label' => 'WhatsApp Suporte'],
            ['type' => 'phone', 'number' => '08001234567', 'label' => 'Central de Atendimento'],
            ['type' => 'email', 'email' => 'suporte@onemundo.com.br', 'label' => 'E-mail']
        ];
        
        jsonSuccess([
            'faqs' => $faqs,
            'categories' => $categories,
            'open_tickets' => $openTickets,
            'contacts' => $contacts
        ]);
        
    } catch (Exception $e) {
        jsonError('Erro ao buscar ajuda', 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    $subject = trim($input['subject'] ?? '');
    $message = trim($input['message'] ?? '');
    $category = $input['category'] ?? 'general';
    $orderId = $input['order_id'] ?? null;
    $priority = $input['priority'] ?? 'normal';
    
    if (empty($subject) || empty($message)) {
        jsonError('Assunto e mensagem são obrigatórios');
    }
    
    try {
        $stmt = $db->prepare("
            INSERT INTO " . table('support_tickets') . "
            (worker_id, order_id, subject, category, priority, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'open', NOW())
        ");
        $stmt->execute([$workerId, $orderId, $subject, $category, $priority]);
        $ticketId = $db->lastInsertId();
        
        // Primeira mensagem
        $stmt = $db->prepare("
            INSERT INTO " . table('support_messages') . "
            (ticket_id, sender_type, message, created_at)
            VALUES (?, 'worker', ?, NOW())
        ");
        $stmt->execute([$ticketId, $message]);
        
        // Notificar equipe de suporte
        // Em produção: email, Slack, etc
        
        jsonSuccess([
            'ticket_id' => $ticketId,
            'ticket_number' => 'TKT-' . str_pad($ticketId, 6, '0', STR_PAD_LEFT)
        ], 'Ticket aberto com sucesso');
        
    } catch (Exception $e) {
        jsonError('Erro ao abrir ticket', 500);
    }
}

jsonError('Método não permitido', 405);
