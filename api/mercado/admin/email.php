<?php
/**
 * GET/POST /api/mercado/admin/email.php
 *
 * Webmail API — read (IMAP) and send (SMTP) emails.
 *
 * GET actions:
 *   ?action=accounts              — List email accounts
 *   ?action=folders&account=...   — List IMAP folders
 *   ?action=inbox&account=...&folder=INBOX&page=1&per_page=20&search=  — List emails
 *   ?action=message&account=...&uid=123&folder=INBOX  — Read full email
 *   ?action=unread_counts         — Unread count per account
 *
 * POST actions (JSON body):
 *   action=send       — Send new email (to, subject, body, cc, bcc, account)
 *   action=reply      — Reply to email (account, uid, folder, body)
 *   action=forward    — Forward email (account, uid, folder, to, body)
 *   action=move       — Move email (account, uid, from_folder, to_folder)
 *   action=delete     — Delete email (account, uid, folder)
 *   action=mark_read  — Mark as read (account, uid, folder)
 *   action=mark_unread— Mark as unread (account, uid, folder)
 *   action=create_account  — Create email account (email, password, display_name, quota_mb)
 *   action=update_account  — Update account (email, display_name, is_active)
 *   action=change_password — Change password (email, new_password)
 *   action=delete_account  — Delete account (email)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

// PHPMailer for sending
$autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
if (file_exists($autoload)) require_once $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

setCorsHeaders();

// IMAP server config (our mail server via VPN)
define('IMAP_HOST', $_ENV['MAIL_IMAP_HOST'] ?? '10.0.0.11');
define('IMAP_PORT', (int)($_ENV['MAIL_IMAP_PORT'] ?? 993));
define('SMTP_HOST', $_ENV['SUPERBORA_MAIL_HOST'] ?? $_ENV['MAIL_HOST'] ?? '10.0.0.11');
define('SMTP_PORT', (int)($_ENV['SUPERBORA_MAIL_PORT'] ?? $_ENV['MAIL_PORT'] ?? 587));

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        switch ($action) {
            case 'accounts':
                handleGetAccounts($db);
                break;
            case 'folders':
                handleGetFolders($db);
                break;
            case 'inbox':
                handleGetInbox($db);
                break;
            case 'message':
                handleGetMessage($db);
                break;
            case 'unread_counts':
                handleUnreadCounts($db);
                break;
            default:
                response(false, null, "action invalida", 400);
        }

    } elseif ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'send':
                handleSend($db, $input, $admin_id);
                break;
            case 'reply':
                handleReply($db, $input, $admin_id);
                break;
            case 'forward':
                handleForward($db, $input, $admin_id);
                break;
            case 'move':
                handleMove($db, $input);
                break;
            case 'delete':
                handleDelete($db, $input);
                break;
            case 'mark_read':
                handleMarkRead($db, $input, true);
                break;
            case 'mark_unread':
                handleMarkRead($db, $input, false);
                break;
            case 'create_account':
                handleCreateAccount($db, $input, $admin_id);
                break;
            case 'update_account':
                handleUpdateAccount($db, $input, $admin_id);
                break;
            case 'change_password':
                handleChangePassword($db, $input, $admin_id);
                break;
            case 'delete_account':
                handleDeleteAccount($db, $input, $admin_id);
                break;
            default:
                response(false, null, "action invalida", 400);
        }

    } else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[admin/email] Erro: " . $e->getMessage());
    response(false, null, "Erro interno: " . $e->getMessage(), 500);
}

// =============================================================================
// IMAP HELPERS
// =============================================================================

function getImapConnection(PDO $db, string $email, string $folder = 'INBOX') {
    // Get account password from DB
    $stmt = $db->prepare("SELECT password_hash FROM om_email_accounts WHERE email = ? AND is_active = true");
    $stmt->execute([$email]);
    $account = $stmt->fetch();
    if (!$account) throw new Exception("Conta de email nao encontrada: {$email}");

    $password = $account['password_hash']; // stored as plain for IMAP auth

    $mailbox = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}' . $folder;
    $imap = @imap_open($mailbox, $email, $password);
    if (!$imap) {
        $errors = imap_errors();
        throw new Exception("Falha ao conectar IMAP: " . ($errors ? implode(', ', $errors) : 'Erro desconhecido'));
    }
    return $imap;
}

function parseEmailAddress($addr): array {
    $result = [];
    if (!$addr) return $result;
    if (is_string($addr)) {
        $decoded = imap_rfc822_parse_adrlist($addr, '');
        foreach ($decoded as $a) {
            if (isset($a->mailbox, $a->host)) {
                $result[] = [
                    'email' => $a->mailbox . '@' . $a->host,
                    'name' => $a->personal ?? '',
                ];
            }
        }
        return $result;
    }
    if (is_array($addr)) {
        foreach ($addr as $a) {
            if (isset($a->mailbox, $a->host)) {
                $result[] = [
                    'email' => $a->mailbox . '@' . $a->host,
                    'name' => isset($a->personal) ? iconv_mime_decode($a->personal, 0, 'UTF-8') : '',
                ];
            }
        }
    }
    return $result;
}

function decodeSubject($subject): string {
    if (!$subject) return '(sem assunto)';
    $decoded = iconv_mime_decode($subject, 0, 'UTF-8');
    return $decoded ?: $subject;
}

function getEmailBody($imap, int $msgno): array {
    $structure = imap_fetchstructure($imap, $msgno);
    $html = '';
    $text = '';
    $attachments = [];

    if (!$structure->parts) {
        // Simple message (not multipart)
        $body = imap_body($imap, $msgno);
        $body = decodeBody($body, $structure->encoding ?? 0);
        $body = convertCharset($body, $structure->parameters ?? []);
        if (strtolower($structure->subtype ?? '') === 'html') {
            $html = $body;
        } else {
            $text = $body;
        }
    } else {
        // Multipart — walk parts
        foreach ($structure->parts as $partNum => $part) {
            $partId = ($partNum + 1);
            extractPart($imap, $msgno, $part, (string)$partId, $html, $text, $attachments);
        }
    }

    return [
        'html' => $html,
        'text' => $text ?: strip_tags($html),
        'attachments' => $attachments,
    ];
}

function extractPart($imap, int $msgno, $part, string $partId, string &$html, string &$text, array &$attachments): void {
    $data = imap_fetchbody($imap, $msgno, $partId);
    $data = decodeBody($data, $part->encoding ?? 0);

    // Check if attachment
    $filename = '';
    if ($part->ifdparameters) {
        foreach ($part->dparameters as $p) {
            if (strtolower($p->attribute) === 'filename') {
                $filename = iconv_mime_decode($p->value, 0, 'UTF-8');
            }
        }
    }
    if (!$filename && $part->ifparameters) {
        foreach ($part->parameters as $p) {
            if (strtolower($p->attribute) === 'name') {
                $filename = iconv_mime_decode($p->value, 0, 'UTF-8');
            }
        }
    }

    if ($filename) {
        $attachments[] = [
            'filename' => $filename,
            'size' => strlen($data),
            'type' => ($part->type ?? 0) . '/' . strtolower($part->subtype ?? 'octet-stream'),
            'part_id' => $partId,
        ];
        return;
    }

    // Text part
    $subtype = strtolower($part->subtype ?? '');
    if ($part->type === 0) { // TEXT
        $data = convertCharset($data, $part->parameters ?? []);
        if ($subtype === 'html') {
            $html .= $data;
        } elseif ($subtype === 'plain') {
            $text .= $data;
        }
    }

    // Recurse into sub-parts
    if (isset($part->parts)) {
        foreach ($part->parts as $subNum => $subPart) {
            extractPart($imap, $msgno, $subPart, $partId . '.' . ($subNum + 1), $html, $text, $attachments);
        }
    }
}

function decodeBody(string $data, int $encoding): string {
    switch ($encoding) {
        case 3: return base64_decode($data);          // BASE64
        case 4: return quoted_printable_decode($data); // QUOTED-PRINTABLE
        default: return $data;
    }
}

function convertCharset(string $data, $params): string {
    $charset = 'UTF-8';
    if ($params) {
        foreach ($params as $p) {
            if (strtolower($p->attribute) === 'charset') {
                $charset = strtoupper($p->value);
            }
        }
    }
    if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $data);
        if ($converted !== false) return $converted;
    }
    return $data;
}

// =============================================================================
// GET HANDLERS
// =============================================================================

function handleGetAccounts(PDO $db): void {
    $stmt = $db->query("SELECT id, email, display_name, quota_mb, is_active, created_at FROM om_email_accounts ORDER BY email");
    $accounts = $stmt->fetchAll();
    response(true, ['accounts' => $accounts]);
}

function handleGetFolders(PDO $db): void {
    $email = $_GET['account'] ?? '';
    if (!$email) response(false, null, "account obrigatorio", 400);

    $imap = getImapConnection($db, $email);
    $mailbox = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}';
    $folders = imap_list($imap, $mailbox, '*');
    imap_close($imap);

    $result = [];
    if ($folders) {
        foreach ($folders as $f) {
            $name = str_replace($mailbox, '', $f);
            $result[] = $name;
        }
    }

    response(true, ['folders' => $result]);
}

function handleGetInbox(PDO $db): void {
    $email = $_GET['account'] ?? '';
    if (!$email) response(false, null, "account obrigatorio", 400);

    $folder = $_GET['folder'] ?? 'INBOX';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(50, max(10, (int)($_GET['per_page'] ?? 20)));
    $search = trim($_GET['search'] ?? '');

    $imap = getImapConnection($db, $email, $folder);

    // Search or get all
    if ($search) {
        $uids = imap_search($imap, 'TEXT "' . addcslashes($search, '"\\') . '"', SE_UID);
    } else {
        $uids = imap_search($imap, 'ALL', SE_UID);
    }

    if (!$uids) $uids = [];
    rsort($uids); // Newest first

    $total = count($uids);
    $totalPages = max(1, ceil($total / $perPage));
    $offset = ($page - 1) * $perPage;
    $pageUids = array_slice($uids, $offset, $perPage);

    $emails = [];
    foreach ($pageUids as $uid) {
        $msgno = imap_msgno($imap, $uid);
        if (!$msgno) continue;

        $header = imap_headerinfo($imap, $msgno);
        if (!$header) continue;

        $from = parseEmailAddress($header->from ?? []);
        $to = parseEmailAddress($header->to ?? []);

        $emails[] = [
            'uid' => $uid,
            'msgno' => $msgno,
            'subject' => decodeSubject($header->subject ?? ''),
            'from' => $from,
            'to' => $to,
            'date' => isset($header->date) ? date('Y-m-d H:i:s', strtotime($header->date)) : null,
            'timestamp' => isset($header->date) ? strtotime($header->date) : 0,
            'seen' => (bool)($header->Unseen ?? '') === false && ($header->Seen ?? '') !== '',
            'answered' => (bool)($header->Answered ?? ''),
            'flagged' => (bool)($header->Flagged ?? ''),
            'size' => (int)($header->Size ?? 0),
        ];
    }

    // Get unread count for badge
    $status = imap_status($imap, '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}' . $folder, SA_UNSEEN);
    $unread = $status ? $status->unseen : 0;

    imap_close($imap);

    response(true, [
        'emails' => $emails,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'unread' => $unread,
        'folder' => $folder,
    ]);
}

function handleGetMessage(PDO $db): void {
    $email = $_GET['account'] ?? '';
    $uid = (int)($_GET['uid'] ?? 0);
    $folder = $_GET['folder'] ?? 'INBOX';

    if (!$email || !$uid) response(false, null, "account e uid obrigatorios", 400);

    $imap = getImapConnection($db, $email, $folder);
    $msgno = imap_msgno($imap, $uid);
    if (!$msgno) {
        imap_close($imap);
        response(false, null, "Email nao encontrado", 404);
    }

    $header = imap_headerinfo($imap, $msgno);
    $body = getEmailBody($imap, $msgno);

    // Mark as read
    imap_setflag_full($imap, (string)$uid, '\\Seen', ST_UID);

    $from = parseEmailAddress($header->from ?? []);
    $to = parseEmailAddress($header->to ?? []);
    $cc = parseEmailAddress($header->cc ?? []);
    $replyTo = parseEmailAddress($header->reply_to ?? []);

    imap_close($imap);

    // Sanitize HTML for safe rendering
    $safeHtml = $body['html'];
    if ($safeHtml) {
        // Remove script tags and event handlers
        $safeHtml = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $safeHtml);
        $safeHtml = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $safeHtml);
        $safeHtml = preg_replace('/\bon\w+\s*=\s*\S+/i', '', $safeHtml);
    }

    response(true, [
        'uid' => $uid,
        'subject' => decodeSubject($header->subject ?? ''),
        'from' => $from,
        'to' => $to,
        'cc' => $cc,
        'reply_to' => $replyTo,
        'date' => isset($header->date) ? date('Y-m-d H:i:s', strtotime($header->date)) : null,
        'html' => $safeHtml,
        'text' => $body['text'],
        'attachments' => $body['attachments'],
        'seen' => true,
        'folder' => $folder,
        'message_id' => $header->message_id ?? '',
        'in_reply_to' => $header->in_reply_to ?? '',
    ]);
}

function handleUnreadCounts(PDO $db): void {
    $stmt = $db->query("SELECT email FROM om_email_accounts WHERE is_active = true ORDER BY email");
    $accounts = $stmt->fetchAll();

    $counts = [];
    foreach ($accounts as $acct) {
        try {
            $imap = getImapConnection($db, $acct['email']);
            $status = imap_status($imap, '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}INBOX', SA_UNSEEN);
            $counts[$acct['email']] = $status ? $status->unseen : 0;
            imap_close($imap);
        } catch (Exception $e) {
            $counts[$acct['email']] = -1; // error indicator
        }
    }

    response(true, ['counts' => $counts]);
}

// =============================================================================
// POST HANDLERS — EMAIL
// =============================================================================

function handleSend(PDO $db, array $input, int $admin_id): void {
    $account = $input['account'] ?? '';
    $to = trim($input['to'] ?? '');
    $subject = trim($input['subject'] ?? '');
    $body = $input['body'] ?? '';
    $cc = trim($input['cc'] ?? '');
    $bcc = trim($input['bcc'] ?? '');

    if (!$account || !$to || !$subject) {
        response(false, null, "account, to e subject obrigatorios", 400);
    }

    // Get account credentials
    $stmt = $db->prepare("SELECT email, display_name, password_hash FROM om_email_accounts WHERE email = ? AND is_active = true");
    $stmt->execute([$account]);
    $acct = $stmt->fetch();
    if (!$acct) response(false, null, "Conta de email nao encontrada", 404);

    $sent = sendEmail($acct, $to, $subject, $body, $cc, $bcc);

    om_audit()->log('email_send', 'email', 0, null,
        ['from' => $account, 'to' => $to, 'subject' => substr($subject, 0, 100)],
        "Email enviado de {$account} para {$to}"
    );

    response(true, ['sent' => $sent], $sent ? "Email enviado" : "Falha ao enviar email");
}

function handleReply(PDO $db, array $input, int $admin_id): void {
    $account = $input['account'] ?? '';
    $uid = (int)($input['uid'] ?? 0);
    $folder = $input['folder'] ?? 'INBOX';
    $body = $input['body'] ?? '';
    $replyAll = (bool)($input['reply_all'] ?? false);

    if (!$account || !$uid || !$body) {
        response(false, null, "account, uid e body obrigatorios", 400);
    }

    // Get original message headers
    $imap = getImapConnection($db, $account, $folder);
    $msgno = imap_msgno($imap, $uid);
    if (!$msgno) {
        imap_close($imap);
        response(false, null, "Email original nao encontrado", 404);
    }

    $header = imap_headerinfo($imap, $msgno);
    $originalBody = getEmailBody($imap, $msgno);
    imap_close($imap);

    // Determine reply-to address
    $replyToAddrs = parseEmailAddress($header->reply_to ?? $header->from ?? []);
    $to = $replyToAddrs[0]['email'] ?? '';
    if (!$to) response(false, null, "Nao foi possivel determinar destinatario", 400);

    $cc = '';
    if ($replyAll) {
        $ccAddrs = array_merge(
            parseEmailAddress($header->to ?? []),
            parseEmailAddress($header->cc ?? [])
        );
        $ccEmails = array_filter(array_map(fn($a) => $a['email'], $ccAddrs), fn($e) => $e !== $account);
        $cc = implode(', ', $ccEmails);
    }

    $subject = decodeSubject($header->subject ?? '');
    if (!preg_match('/^Re:/i', $subject)) {
        $subject = 'Re: ' . $subject;
    }

    // Build reply body with quote
    $fromName = ($replyToAddrs[0]['name'] ?? '') ?: $replyToAddrs[0]['email'];
    $date = isset($header->date) ? date('d/m/Y H:i', strtotime($header->date)) : '';
    $quotedText = $originalBody['text'] ?: strip_tags($originalBody['html']);
    $quotedLines = array_map(fn($l) => '> ' . $l, explode("\n", $quotedText));

    $fullBody = $body . "\n\n" .
        "Em {$date}, {$fromName} escreveu:\n" .
        implode("\n", $quotedLines);

    // Get account credentials
    $stmt = $db->prepare("SELECT email, display_name, password_hash FROM om_email_accounts WHERE email = ? AND is_active = true");
    $stmt->execute([$account]);
    $acct = $stmt->fetch();
    if (!$acct) response(false, null, "Conta de email nao encontrada", 404);

    $sent = sendEmail($acct, $to, $subject, nl2br(htmlspecialchars($fullBody, ENT_QUOTES, 'UTF-8')), $cc, '', $header->message_id ?? '');

    om_audit()->log('email_reply', 'email', $uid, null,
        ['from' => $account, 'to' => $to, 'subject' => substr($subject, 0, 100)],
        "Reply enviado de {$account} para {$to}"
    );

    response(true, ['sent' => $sent], $sent ? "Resposta enviada" : "Falha ao enviar");
}

function handleForward(PDO $db, array $input, int $admin_id): void {
    $account = $input['account'] ?? '';
    $uid = (int)($input['uid'] ?? 0);
    $folder = $input['folder'] ?? 'INBOX';
    $to = trim($input['to'] ?? '');
    $body = $input['body'] ?? '';

    if (!$account || !$uid || !$to) {
        response(false, null, "account, uid e to obrigatorios", 400);
    }

    // Get original message
    $imap = getImapConnection($db, $account, $folder);
    $msgno = imap_msgno($imap, $uid);
    if (!$msgno) {
        imap_close($imap);
        response(false, null, "Email original nao encontrado", 404);
    }

    $header = imap_headerinfo($imap, $msgno);
    $originalBody = getEmailBody($imap, $msgno);
    imap_close($imap);

    $subject = decodeSubject($header->subject ?? '');
    if (!preg_match('/^Fwd:/i', $subject)) {
        $subject = 'Fwd: ' . $subject;
    }

    $fromAddrs = parseEmailAddress($header->from ?? []);
    $fromName = ($fromAddrs[0]['name'] ?? '') ?: ($fromAddrs[0]['email'] ?? 'desconhecido');
    $date = isset($header->date) ? date('d/m/Y H:i', strtotime($header->date)) : '';

    $forwardBody = ($body ? $body . "\n\n" : '') .
        "---------- Mensagem encaminhada ----------\n" .
        "De: {$fromName}\n" .
        "Data: {$date}\n" .
        "Assunto: " . decodeSubject($header->subject ?? '') . "\n\n" .
        ($originalBody['text'] ?: strip_tags($originalBody['html']));

    $stmt = $db->prepare("SELECT email, display_name, password_hash FROM om_email_accounts WHERE email = ? AND is_active = true");
    $stmt->execute([$account]);
    $acct = $stmt->fetch();
    if (!$acct) response(false, null, "Conta de email nao encontrada", 404);

    $sent = sendEmail($acct, $to, $subject, nl2br(htmlspecialchars($forwardBody, ENT_QUOTES, 'UTF-8')));

    om_audit()->log('email_forward', 'email', $uid, null,
        ['from' => $account, 'to' => $to, 'subject' => substr($subject, 0, 100)],
        "Email encaminhado de {$account} para {$to}"
    );

    response(true, ['sent' => $sent], $sent ? "Email encaminhado" : "Falha ao encaminhar");
}

function handleMove(PDO $db, array $input): void {
    $account = $input['account'] ?? '';
    $uid = (int)($input['uid'] ?? 0);
    $fromFolder = $input['from_folder'] ?? $input['folder'] ?? 'INBOX';
    $toFolder = $input['to_folder'] ?? '';

    if (!$account || !$uid || !$toFolder) {
        response(false, null, "account, uid e to_folder obrigatorios", 400);
    }

    $imap = getImapConnection($db, $account, $fromFolder);
    $mailbox = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}' . $toFolder;
    $moved = imap_mail_move($imap, (string)$uid, $toFolder, CP_UID);
    if ($moved) imap_expunge($imap);
    imap_close($imap);

    response($moved, null, $moved ? "Email movido para {$toFolder}" : "Falha ao mover email");
}

function handleDelete(PDO $db, array $input): void {
    $account = $input['account'] ?? '';
    $uid = (int)($input['uid'] ?? 0);
    $folder = $input['folder'] ?? 'INBOX';

    if (!$account || !$uid) {
        response(false, null, "account e uid obrigatorios", 400);
    }

    $imap = getImapConnection($db, $account, $folder);

    // Move to Trash instead of permanent delete
    if ($folder !== 'Trash') {
        $moved = @imap_mail_move($imap, (string)$uid, 'Trash', CP_UID);
        if ($moved) {
            imap_expunge($imap);
            imap_close($imap);
            response(true, null, "Email movido para Lixeira");
            return;
        }
    }

    // If already in Trash or move failed, mark deleted
    imap_delete($imap, (string)$uid, FT_UID);
    imap_expunge($imap);
    imap_close($imap);

    response(true, null, "Email excluido");
}

function handleMarkRead(PDO $db, array $input, bool $read): void {
    $account = $input['account'] ?? '';
    $uid = (int)($input['uid'] ?? 0);
    $folder = $input['folder'] ?? 'INBOX';

    if (!$account || !$uid) {
        response(false, null, "account e uid obrigatorios", 400);
    }

    $imap = getImapConnection($db, $account, $folder);
    if ($read) {
        imap_setflag_full($imap, (string)$uid, '\\Seen', ST_UID);
    } else {
        imap_clearflag_full($imap, (string)$uid, '\\Seen', ST_UID);
    }
    imap_close($imap);

    response(true, null, $read ? "Marcado como lido" : "Marcado como nao lido");
}

// =============================================================================
// POST HANDLERS — ACCOUNT MANAGEMENT
// =============================================================================

function handleCreateAccount(PDO $db, array $input, int $admin_id): void {
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $displayName = trim($input['display_name'] ?? '');
    $quotaMb = max(100, (int)($input['quota_mb'] ?? 500));

    if (!$email || !$password) {
        response(false, null, "email e password obrigatorios", 400);
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        response(false, null, "Formato de email invalido", 400);
    }

    // Must be @superbora.com.br
    if (!str_ends_with($email, '@superbora.com.br')) {
        response(false, null, "Email deve ser @superbora.com.br", 400);
    }

    // Check duplicate
    $stmt = $db->prepare("SELECT id FROM om_email_accounts WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) response(false, null, "Email ja existe", 409);

    // Insert into DB
    $stmt = $db->prepare("
        INSERT INTO om_email_accounts (email, display_name, password_hash, quota_mb, is_active, created_by)
        VALUES (?, ?, ?, ?, true, ?)
        RETURNING id
    ");
    $stmt->execute([$email, $displayName, $password, $quotaMb, $admin_id]);
    $id = (int)$stmt->fetchColumn();

    // Extract local part for vmailbox
    $localPart = explode('@', $email)[0];

    // Create on mail server via SSH (vmailbox + dovecot users)
    // This will be done via the mail server setup - for now store in DB
    // The mail server setup script reads from DB or we provide an API

    om_audit()->log('email_account_create', 'email_account', $id, null,
        ['email' => $email, 'quota_mb' => $quotaMb],
        "Conta de email criada: {$email}"
    );

    response(true, ['id' => $id, 'email' => $email], "Conta criada: {$email}");
}

function handleUpdateAccount(PDO $db, array $input, int $admin_id): void {
    $email = strtolower(trim($input['email'] ?? ''));
    if (!$email) response(false, null, "email obrigatorio", 400);

    $updates = [];
    $params = [];

    if (isset($input['display_name'])) {
        $updates[] = "display_name = ?";
        $params[] = trim($input['display_name']);
    }
    if (isset($input['is_active'])) {
        $updates[] = "is_active = ?";
        $params[] = (bool)$input['is_active'];
    }
    if (isset($input['quota_mb'])) {
        $updates[] = "quota_mb = ?";
        $params[] = max(100, (int)$input['quota_mb']);
    }

    if (empty($updates)) response(false, null, "Nada para atualizar", 400);

    $updates[] = "updated_at = NOW()";
    $params[] = $email;

    $stmt = $db->prepare("UPDATE om_email_accounts SET " . implode(', ', $updates) . " WHERE email = ?");
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) response(false, null, "Conta nao encontrada", 404);

    om_audit()->log('email_account_update', 'email_account', 0, null,
        ['email' => $email, 'changes' => array_keys($input)],
        "Conta de email atualizada: {$email}"
    );

    response(true, null, "Conta atualizada");
}

function handleChangePassword(PDO $db, array $input, int $admin_id): void {
    $email = strtolower(trim($input['email'] ?? ''));
    $newPassword = $input['new_password'] ?? '';

    if (!$email || !$newPassword) {
        response(false, null, "email e new_password obrigatorios", 400);
    }

    if (strlen($newPassword) < 8) {
        response(false, null, "Senha deve ter no minimo 8 caracteres", 400);
    }

    $stmt = $db->prepare("UPDATE om_email_accounts SET password_hash = ?, updated_at = NOW() WHERE email = ?");
    $stmt->execute([$newPassword, $email]);

    if ($stmt->rowCount() === 0) response(false, null, "Conta nao encontrada", 404);

    om_audit()->log('email_account_password', 'email_account', 0, null,
        ['email' => $email],
        "Senha alterada para: {$email}"
    );

    response(true, null, "Senha alterada");
}

function handleDeleteAccount(PDO $db, array $input, int $admin_id): void {
    $email = strtolower(trim($input['email'] ?? ''));
    if (!$email) response(false, null, "email obrigatorio", 400);

    // Safety: don't delete contato@ or noreply@
    $protected = ['contato@superbora.com.br', 'noreply@superbora.com.br'];
    if (in_array($email, $protected)) {
        response(false, null, "Esta conta nao pode ser excluida", 403);
    }

    $stmt = $db->prepare("DELETE FROM om_email_accounts WHERE email = ?");
    $stmt->execute([$email]);

    if ($stmt->rowCount() === 0) response(false, null, "Conta nao encontrada", 404);

    om_audit()->log('email_account_delete', 'email_account', 0, null,
        ['email' => $email],
        "Conta de email excluida: {$email}"
    );

    response(true, null, "Conta excluida: {$email}");
}

// =============================================================================
// SMTP SENDER
// =============================================================================

function sendEmail(array $acct, string $to, string $subject, string $htmlBody, string $cc = '', string $bcc = '', string $inReplyTo = ''): bool {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = $acct['email'];
        $mail->Password = $acct['password_hash'];
        $mail->SMTPSecure = SMTP_PORT === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($acct['email'], $acct['display_name'] ?: 'SuperBora');

        // Parse multiple recipients
        foreach (array_filter(array_map('trim', explode(',', $to))) as $addr) {
            if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($addr);
            }
        }

        if ($cc) {
            foreach (array_filter(array_map('trim', explode(',', $cc))) as $addr) {
                if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $mail->addCC($addr);
                }
            }
        }

        if ($bcc) {
            foreach (array_filter(array_map('trim', explode(',', $bcc))) as $addr) {
                if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $mail->addBCC($addr);
                }
            }
        }

        if ($inReplyTo) {
            $mail->addCustomHeader('In-Reply-To', $inReplyTo);
            $mail->addCustomHeader('References', $inReplyTo);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));

        $mail->send();
        error_log("[admin/email] Sent '{$subject}' from {$acct['email']} to {$to}");
        return true;

    } catch (PHPMailerException $e) {
        error_log("[admin/email] Failed to send from {$acct['email']}: " . $e->getMessage());
        return false;
    }
}
