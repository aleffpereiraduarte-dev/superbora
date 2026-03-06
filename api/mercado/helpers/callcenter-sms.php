<?php
/**
 * Call Center SMS Helper - SuperBora
 * Formatted SMS messages for phone/WhatsApp order flow
 */

require_once __DIR__ . '/twilio-sms.php';

/**
 * Send order summary SMS after order is placed
 */
function sendOrderSummary(string $phone, array $draft): array {
    if (is_string($draft['items'] ?? null)) {
        $items = json_decode($draft['items'], true) ?: [];
    } else {
        $items = $draft['items'] ?? [];
    }

    $lines = ["SuperBora - Pedido Confirmado!\n"];
    $lines[] = "Restaurante: " . ($draft['partner_name'] ?? 'N/A');
    $lines[] = "Itens:";
    foreach ($items as $item) {
        $qty = $item['quantity'] ?? 1;
        $price = number_format(($item['price'] ?? 0) * $qty, 2, ',', '.');
        $lines[] = "- {$qty}x {$item['name']} ... R\${$price}";
        // Show selected options
        foreach (($item['options'] ?? []) as $opt) {
            $optPrice = number_format(($opt['price'] ?? 0) * $qty, 2, ',', '.');
            $lines[] = "  + {$opt['name']} R\${$optPrice}";
        }
    }
    $lines[] = "";
    $lines[] = "Subtotal: R$" . number_format($draft['subtotal'] ?? 0, 2, ',', '.');
    if (($draft['delivery_fee'] ?? 0) > 0) {
        $lines[] = "Entrega: R$" . number_format($draft['delivery_fee'], 2, ',', '.');
    }
    if (($draft['service_fee'] ?? 0) > 0) {
        $lines[] = "Taxa: R$" . number_format($draft['service_fee'], 2, ',', '.');
    }
    if (($draft['discount'] ?? 0) > 0) {
        $lines[] = "Desconto: -R$" . number_format($draft['discount'], 2, ',', '.');
    }
    $lines[] = "Total: R$" . number_format($draft['total'] ?? 0, 2, ',', '.');
    $lines[] = "";

    // Payment method label
    $paymentLabels = [
        'dinheiro' => 'Dinheiro',
        'pix' => 'PIX',
        'credito' => 'Cartao Credito',
        'debito' => 'Cartao Debito',
        'link' => 'Link Pagamento',
    ];
    $pm = $draft['payment_method'] ?? 'dinheiro';
    $lines[] = "Pagamento: " . ($paymentLabels[$pm] ?? $pm);
    if ($pm === 'dinheiro' && ($draft['payment_change'] ?? 0) > 0) {
        $lines[] = "Troco para: R$" . number_format($draft['payment_change'], 2, ',', '.');
    }

    // Tracking link
    if (!empty($draft['submitted_order_id'])) {
        $lines[] = "";
        $lines[] = "Acompanhe: superbora.com.br/tracking/" . $draft['submitted_order_id'];
    }

    $body = implode("\n", $lines);
    return sendSMS($phone, $body);
}

/**
 * Send payment link SMS
 */
function sendPaymentLink(string $phone, string $link, float $total): array {
    $totalStr = number_format($total, 2, ',', '.');
    $body = "SuperBora - Link de Pagamento\n\nTotal: R\${$totalStr}\n\nPague aqui: {$link}\n\nLink valido por 30 minutos.";
    return sendSMS($phone, $body);
}

/**
 * Send callback notice SMS
 */
function sendCallbackNotice(string $phone, int $estimatedMinutes): array {
    $body = "SuperBora\n\nObrigado por ligar! Nosso atendente ligara de volta em aproximadamente {$estimatedMinutes} minutos.\n\nSe preferir, envie uma mensagem pelo WhatsApp.";
    return sendSMS($phone, $body);
}
