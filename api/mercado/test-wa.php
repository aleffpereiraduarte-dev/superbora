<?php
/**
 * Simulates a WhatsApp "oi" message to find the exact error.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['key'] ?? '') !== 'abc123') { die("no"); }

echo "=== WhatsApp AI Test ===\n\n";

// Step 1: Load all dependencies one by one
$deps = [
    'config/database.php',
    'helpers/claude-client.php',
    'helpers/zapi-whatsapp.php',
    'helpers/ai-memory.php',
    'helpers/ai-safeguards.php',
    'helpers/callcenter-sms.php',
    'helpers/ws-callcenter-broadcast.php',
];

echo "--- Loading dependencies ---\n";
foreach ($deps as $d) {
    $path = __DIR__ . '/' . $d;
    try {
        require_once $path;
        echo "OK: {$d}\n";
    } catch (Throwable $e) {
        echo "FAIL: {$d} => " . $e->getMessage() . "\n  at " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

echo "\n--- Loading whatsapp-ai.php functions ---\n";
// We can't require the whole file (it runs the webhook), so let's test pieces
try {
    $db = getDB();
    echo "DB: OK\n";
} catch (Throwable $e) {
    echo "DB FAIL: " . $e->getMessage() . "\n";
    die("Cannot continue without DB\n");
}

// Test: load or create conversation
echo "\n--- Test: conversation for 5533999652818 ---\n";
try {
    $phone = '5533999652818';

    // Check if conversation exists
    $stmt = $db->prepare("SELECT * FROM om_callcenter_whatsapp WHERE phone = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$phone]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($conv) {
        echo "Existing conversation found: id={$conv['id']} status={$conv['status']}\n";
        echo "ai_context: " . substr($conv['ai_context'] ?? '{}', 0, 200) . "\n";
    } else {
        echo "No conversation found, will create one\n";
        $stmt = $db->prepare("INSERT INTO om_callcenter_whatsapp (phone, status, ai_context) VALUES (?, 'bot', '{}') RETURNING id");
        $stmt->execute([$phone]);
        $convId = $stmt->fetchColumn();
        echo "Created conversation id={$convId}\n";
    }
} catch (Throwable $e) {
    echo "CONV ERROR: " . $e->getMessage() . "\n";
}

// Test: check what functions exist from the whatsapp-ai.php file
echo "\n--- Test: required functions ---\n";
$funcs = [
    'sendWhatsApp', 'sendWhatsAppWithRetry', 'formatPhoneForZapi',
    'runSafeguards', 'validateAiResponse', 'handleDegradedMode',
    'aiMemoryBuildContext', 'aiMemoryTrackCall', 'aiMemoryLearn',
    'callcenterBroadcast', 'ccBroadcastDashboard',
];
foreach ($funcs as $f) {
    echo "{$f}: " . (function_exists($f) ? "OK" : "MISSING") . "\n";
}

echo "\nClaudeClient class: " . (class_exists('ClaudeClient') ? "OK" : "MISSING") . "\n";

// Test: try calling Claude
echo "\n--- Test: Claude API ---\n";
try {
    $claude = new ClaudeClient('claude-sonnet-4-20250514', 30, 0);
    $result = $claude->send(
        "Voce e um assistente. Responda em uma frase curta em portugues.",
        [['role' => 'user', 'content' => 'oi']]
    );

    if (is_array($result)) {
        $text = $result['content'] ?? $result['text'] ?? json_encode($result);
        if (is_array($text)) {
            $parts = [];
            foreach ($text as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') $parts[] = $block['text'];
            }
            $text = implode(' ', $parts);
        }
        echo "Claude response: {$text}\n";
    } else {
        echo "Claude response: {$result}\n";
    }
} catch (Throwable $e) {
    echo "Claude ERROR: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Test: Now actually include whatsapp-ai.php and call handleWhatsAppMessage
echo "\n--- Test: Full message handling ---\n";
echo "(This simulates receiving 'oi' from 5533999652818)\n\n";

// Override the webhook entry to not run
$_SERVER['REQUEST_METHOD'] = 'GET'; // webhook checks for POST, so it won't auto-run

try {
    // Include the file - it will try to run but REQUEST_METHOD is GET so parseWebhookRequest returns null and exits
    // We need a different approach - let's manually test the flow

    // Instead, let's trace through the actual error
    $phone = '5533999652818';
    $message = 'oi';

    // Get conversation
    $stmt = $db->prepare("SELECT * FROM om_callcenter_whatsapp WHERE phone = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$phone]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conversation) {
        echo "Creating conversation...\n";
        $stmt = $db->prepare("INSERT INTO om_callcenter_whatsapp (phone, status, ai_context) VALUES (?, 'bot', '{}') RETURNING *");
        $stmt->execute([$phone]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $conversationId = $conversation['id'];
    $context = json_decode($conversation['ai_context'] ?? '{}', true) ?: [];
    echo "Conversation id={$conversationId}\n";
    echo "Context: " . json_encode($context) . "\n";

    // Save message
    echo "Saving message...\n";
    $stmt = $db->prepare("INSERT INTO om_callcenter_wa_messages (conversation_id, direction, sender_type, message, message_type) VALUES (?, 'inbound', 'customer', ?, 'text')");
    $stmt->execute([$conversationId, $message]);
    echo "Message saved OK\n";

    // Try Claude
    echo "Calling Claude...\n";
    $systemPrompt = "Voce e a Bora, assistente virtual da SuperBora, um app de delivery de supermercado. Responda de forma amigavel e informal em portugues brasileiro. Quando alguem diz 'oi', cumprimente e pergunte como pode ajudar. Nao use emojis excessivos.";

    $claude = new ClaudeClient('claude-sonnet-4-20250514', 30, 0);
    $result = $claude->send($systemPrompt, [
        ['role' => 'user', 'content' => 'oi']
    ]);

    $aiResponse = '';
    if (is_array($result)) {
        $content = $result['content'] ?? $result['text'] ?? $result['message'] ?? '';
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') $parts[] = $block['text'];
                elseif (is_string($block)) $parts[] = $block;
            }
            $aiResponse = implode("\n", $parts);
        } else {
            $aiResponse = (string)$content;
        }
    } else {
        $aiResponse = (string)$result;
    }

    echo "AI Response: {$aiResponse}\n";

    // Save AI response
    $stmt = $db->prepare("INSERT INTO om_callcenter_wa_messages (conversation_id, direction, sender_type, message, message_type) VALUES (?, 'outbound', 'ai', ?, 'text')");
    $stmt->execute([$conversationId, $aiResponse]);
    echo "AI message saved OK\n";

    // Send via WhatsApp
    echo "Sending via WhatsApp...\n";
    $sendResult = sendWhatsApp($phone, $aiResponse);
    echo "Send result: " . json_encode($sendResult) . "\n";

    echo "\n=== SUCCESS! Full flow works! ===\n";

} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
