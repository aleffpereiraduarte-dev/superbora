<?php
/**
 * Email Marketing Templates - SuperBora
 *
 * Styled HTML templates for marketing campaigns.
 * All templates use inline CSS for email client compatibility.
 * Each returns a complete HTML string ready to send.
 *
 * Usage:
 *   require_once __DIR__ . '/email-templates.php';
 *   $html = emailTemplate_welcome('Maria');
 */

/**
 * Base layout wrapper for all marketing emails.
 * Uses inline CSS only (no <style> blocks) for maximum email client compatibility.
 *
 * @param string $content Inner HTML content
 * @param string $preheader Hidden preview text shown in inbox list
 * @param string $unsubscribeUrl URL for the unsubscribe link
 * @return string Complete HTML email
 */
function _marketingEmailLayout(string $content, string $preheader = '', string $unsubscribeUrl = ''): string {
    if (empty($unsubscribeUrl)) {
        $unsubscribeUrl = 'https://superbora.com.br/api/mercado/customer/email-preferences.php?action=unsubscribe';
    }
    $preheaderEsc = htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>SuperBora</title>
<!--[if mso]>
<noscript>
<xml>
<o:OfficeDocumentSettings>
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings>
</xml>
</noscript>
<![endif]-->
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background-color:#f1f5f9;color:#1e293b;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
<span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;max-height:0;max-width:0;overflow:hidden;mso-hide:all;">{$preheaderEsc}</span>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#f1f5f9;">
<tr><td align="center" style="padding:20px 10px;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

<!-- Header -->
<tr>
<td style="background:linear-gradient(135deg,#16a34a,#15803d);padding:28px 24px;text-align:center;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
<tr><td align="center">
<h1 style="margin:0;color:#ffffff;font-size:28px;font-weight:700;letter-spacing:-0.5px;">SuperBora</h1>
<p style="margin:4px 0 0;color:rgba(255,255,255,0.85);font-size:14px;">Mercado na sua porta</p>
</td></tr>
</table>
</td>
</tr>

<!-- Body -->
<tr>
<td style="padding:28px 24px;">
{$content}
</td>
</tr>

<!-- Footer -->
<tr>
<td style="padding:20px 24px;text-align:center;border-top:1px solid #e2e8f0;">
<p style="margin:0 0 8px;color:#94a3b8;font-size:12px;">SuperBora &mdash; Mercado na sua porta</p>
<p style="margin:0 0 8px;color:#94a3b8;font-size:12px;">
<a href="{$unsubscribeUrl}" style="color:#94a3b8;text-decoration:underline;">Cancelar inscricao</a> &middot;
<a href="https://superbora.com.br" style="color:#94a3b8;text-decoration:underline;">Visitar SuperBora</a>
</p>
<p style="margin:0;color:#cbd5e1;font-size:11px;">Voce recebeu este email porque tem uma conta no SuperBora.</p>
</td>
</tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * CTA button helper (inline-styled for email clients)
 */
function _marketingButton(string $text, string $url, string $bgColor = '#16a34a'): string {
    $textEsc = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:24px auto;">
<tr>
<td align="center" style="border-radius:12px;background-color:{$bgColor};">
<a href="{$url}" target="_blank" style="display:inline-block;padding:14px 32px;color:#ffffff;font-size:16px;font-weight:600;text-decoration:none;border-radius:12px;background-color:{$bgColor};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">{$textEsc}</a>
</td>
</tr>
</table>
HTML;
}

/**
 * Welcome email for new customers
 *
 * @param string $name Customer first name
 * @return string Complete HTML email
 */
function emailTemplate_welcome(string $name): string {
    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $btn = _marketingButton('Comecar a Comprar', 'https://superbora.com.br');

    $content = <<<HTML
<h2 style="margin:0 0 8px;color:#1e293b;font-size:22px;">Bem-vindo ao SuperBora, {$nameEsc}!</h2>
<p style="color:#64748b;margin:0 0 20px;font-size:15px;line-height:1.6;">Estamos felizes em ter voce! Agora voce pode receber produtos do mercado na sua porta com rapidez e praticidade.</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:20px 0;">
<tr>
<td style="padding:12px 0;border-bottom:1px solid #f1f5f9;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0">
<tr>
<td style="width:40px;font-size:24px;vertical-align:top;">&#128722;</td>
<td style="padding-left:12px;">
<p style="margin:0;font-weight:600;color:#1e293b;">Mercado completo</p>
<p style="margin:2px 0 0;color:#64748b;font-size:13px;">Milhares de produtos das melhores lojas</p>
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td style="padding:12px 0;border-bottom:1px solid #f1f5f9;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0">
<tr>
<td style="width:40px;font-size:24px;vertical-align:top;">&#128666;</td>
<td style="padding-left:12px;">
<p style="margin:0;font-weight:600;color:#1e293b;">Entrega rapida</p>
<p style="margin:2px 0 0;color:#64748b;font-size:13px;">Acompanhe em tempo real pelo app</p>
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td style="padding:12px 0;border-bottom:1px solid #f1f5f9;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0">
<tr>
<td style="width:40px;font-size:24px;vertical-align:top;">&#128176;</td>
<td style="padding-left:12px;">
<p style="margin:0;font-weight:600;color:#1e293b;">Cashback em tudo</p>
<p style="margin:2px 0 0;color:#64748b;font-size:13px;">Ganhe de volta em todas as compras</p>
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td style="padding:12px 0;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0">
<tr>
<td style="width:40px;font-size:24px;vertical-align:top;">&#127873;</td>
<td style="padding-left:12px;">
<p style="margin:0;font-weight:600;color:#1e293b;">Cupons exclusivos</p>
<p style="margin:2px 0 0;color:#64748b;font-size:13px;">Promocoes especiais so para voce</p>
</td>
</tr>
</table>
</td>
</tr>
</table>

{$btn}
HTML;

    return _marketingEmailLayout($content, "Bem-vindo ao SuperBora, {$nameEsc}! Descubra tudo que temos para voce.");
}

/**
 * Abandoned cart reminder email
 *
 * @param string $name Customer first name
 * @param array $items Cart items: [['name' => 'Produto', 'quantity' => 2, 'price' => 5.99], ...]
 * @param string[] $storeNames Names of partner stores in the cart
 * @return string Complete HTML email
 */
function emailTemplate_abandoned_cart(string $name, array $items, array $storeNames): string {
    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $storesText = htmlspecialchars(implode(', ', $storeNames), ENT_QUOTES, 'UTF-8');

    $itemsHtml = '';
    $total = 0;
    foreach (array_slice($items, 0, 6) as $item) {
        $itemName = htmlspecialchars($item['name'] ?? 'Produto', ENT_QUOTES, 'UTF-8');
        $qty = (int)($item['quantity'] ?? 1);
        $price = (float)($item['price'] ?? 0);
        $lineTotal = $qty * $price;
        $total += $lineTotal;
        $priceFormatted = 'R$ ' . number_format($lineTotal, 2, ',', '.');

        $itemsHtml .= <<<HTML
<tr>
<td style="padding:10px 0;border-bottom:1px solid #f1f5f9;">
<span style="color:#1e293b;font-size:14px;">{$qty}x {$itemName}</span>
</td>
<td style="padding:10px 0;border-bottom:1px solid #f1f5f9;text-align:right;">
<span style="color:#1e293b;font-weight:600;font-size:14px;">{$priceFormatted}</span>
</td>
</tr>
HTML;
    }

    $remaining = count($items) - 6;
    if ($remaining > 0) {
        $itemsHtml .= <<<HTML
<tr>
<td colspan="2" style="padding:10px 0;color:#64748b;font-size:13px;font-style:italic;">
...e mais {$remaining} item(ns)
</td>
</tr>
HTML;
    }

    $totalFormatted = 'R$ ' . number_format($total, 2, ',', '.');
    $btn = _marketingButton('Finalizar Compra', 'https://superbora.com.br/carrinho');

    $content = <<<HTML
<h2 style="margin:0 0 8px;color:#1e293b;font-size:22px;">Voce esqueceu algo no carrinho!</h2>
<p style="color:#64748b;margin:0 0 20px;font-size:15px;line-height:1.6;">Ola {$nameEsc}, seus produtos de <strong>{$storesText}</strong> estao esperando por voce.</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#f8fafc;border-radius:12px;padding:16px;border:1px solid #e2e8f0;">
<tr><td style="padding:16px;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
{$itemsHtml}
<tr>
<td style="padding:12px 0 0;font-size:18px;font-weight:700;color:#16a34a;">Subtotal</td>
<td style="padding:12px 0 0;text-align:right;font-size:18px;font-weight:700;color:#16a34a;">{$totalFormatted}</td>
</tr>
</table>
</td></tr>
</table>

{$btn}

<p style="color:#94a3b8;font-size:13px;text-align:center;margin:0;">Seus itens podem esgotar. Garanta os seus agora!</p>
HTML;

    return _marketingEmailLayout($content, "{$nameEsc}, seus produtos estao esperando no carrinho!");
}

/**
 * Win-back / re-engagement email for inactive customers
 *
 * @param string $name Customer first name
 * @param int $daysSinceLastOrder Days since the customer's last order
 * @param string $couponCode Discount coupon code to incentivize return
 * @return string Complete HTML email
 */
function emailTemplate_win_back(string $name, int $daysSinceLastOrder, string $couponCode): string {
    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $couponEsc = htmlspecialchars($couponCode, ENT_QUOTES, 'UTF-8');

    $daysText = $daysSinceLastOrder > 60
        ? 'um bom tempo'
        : "{$daysSinceLastOrder} dias";

    $btn = _marketingButton('Voltar a Comprar', 'https://superbora.com.br');

    $content = <<<HTML
<h2 style="margin:0 0 8px;color:#1e293b;font-size:22px;">Sentimos sua falta, {$nameEsc}!</h2>
<p style="color:#64748b;margin:0 0 20px;font-size:15px;line-height:1.6;">Faz {$daysText} desde sua ultima compra. Preparamos algo especial pra voce voltar:</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:20px 0;">
<tr>
<td align="center" style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:16px;padding:28px 24px;border:2px dashed #16a34a;">
<p style="margin:0 0 4px;color:#64748b;font-size:13px;text-transform:uppercase;letter-spacing:1px;">Cupom exclusivo</p>
<p style="margin:0 0 8px;color:#16a34a;font-size:32px;font-weight:700;letter-spacing:2px;">{$couponEsc}</p>
<p style="margin:0;color:#15803d;font-size:15px;font-weight:600;">Use no seu proximo pedido</p>
</td>
</tr>
</table>

<p style="color:#64748b;margin:0 0 20px;font-size:15px;line-height:1.6;text-align:center;">Temos lojas novas, mais opcoes de produtos e entregas ainda mais rapidas. Volte e confira!</p>

{$btn}
HTML;

    return _marketingEmailLayout($content, "{$nameEsc}, voce tem um cupom exclusivo esperando! Use {$couponEsc} no SuperBora.");
}

/**
 * Order summary email (weekly/monthly digest)
 *
 * @param string $name Customer first name
 * @param string $orderNumber Order number/ID
 * @param array $items Order items: [['name' => 'Produto', 'quantity' => 1, 'price' => 9.99], ...]
 * @param float $total Order total
 * @return string Complete HTML email
 */
function emailTemplate_order_summary(string $name, string $orderNumber, array $items, float $total): string {
    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $orderEsc = htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8');
    $totalFormatted = 'R$ ' . number_format($total, 2, ',', '.');

    $itemsHtml = '';
    foreach (array_slice($items, 0, 10) as $item) {
        $itemName = htmlspecialchars($item['name'] ?? 'Produto', ENT_QUOTES, 'UTF-8');
        $qty = (int)($item['quantity'] ?? 1);
        $price = (float)($item['price'] ?? 0);
        $lineTotal = $qty * $price;
        $priceFormatted = 'R$ ' . number_format($lineTotal, 2, ',', '.');

        $itemsHtml .= <<<HTML
<tr>
<td style="padding:8px 0;border-bottom:1px solid #f1f5f9;color:#1e293b;font-size:14px;">{$qty}x {$itemName}</td>
<td style="padding:8px 0;border-bottom:1px solid #f1f5f9;text-align:right;color:#1e293b;font-weight:600;font-size:14px;">{$priceFormatted}</td>
</tr>
HTML;
    }

    $remaining = count($items) - 10;
    if ($remaining > 0) {
        $itemsHtml .= <<<HTML
<tr>
<td colspan="2" style="padding:8px 0;color:#64748b;font-size:13px;font-style:italic;">...e mais {$remaining} item(ns)</td>
</tr>
HTML;
    }

    $btn = _marketingButton('Ver Pedido Completo', "https://superbora.com.br/pedidos");

    $content = <<<HTML
<h2 style="margin:0 0 8px;color:#1e293b;font-size:22px;">Resumo do Pedido #{$orderEsc}</h2>
<p style="color:#64748b;margin:0 0 20px;font-size:15px;line-height:1.6;">Ola {$nameEsc}, aqui esta o resumo do seu pedido.</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;">
<tr><td style="padding:16px;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
{$itemsHtml}
<tr>
<td style="padding:14px 0 0;font-size:20px;font-weight:700;color:#16a34a;">Total</td>
<td style="padding:14px 0 0;text-align:right;font-size:20px;font-weight:700;color:#16a34a;">{$totalFormatted}</td>
</tr>
</table>
</td></tr>
</table>

{$btn}
HTML;

    return _marketingEmailLayout($content, "Pedido #{$orderEsc} - {$totalFormatted}");
}

/**
 * New stores in your area email
 *
 * @param string $name Customer first name
 * @param array $stores New stores: [['name' => 'Loja X', 'category' => 'Mercado', 'description' => '...'], ...]
 * @return string Complete HTML email
 */
function emailTemplate_new_stores(string $name, array $stores): string {
    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    $storesHtml = '';
    foreach (array_slice($stores, 0, 4) as $store) {
        $storeName = htmlspecialchars($store['name'] ?? 'Loja', ENT_QUOTES, 'UTF-8');
        $category = htmlspecialchars($store['category'] ?? 'Mercado', ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($store['description'] ?? '', ENT_QUOTES, 'UTF-8');

        $storesHtml .= <<<HTML
<tr>
<td style="padding:16px;border-bottom:1px solid #f1f5f9;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
<tr>
<td style="width:48px;vertical-align:top;">
<div style="width:48px;height:48px;background-color:#dcfce7;border-radius:12px;text-align:center;line-height:48px;font-size:22px;">&#127979;</div>
</td>
<td style="padding-left:14px;vertical-align:top;">
<p style="margin:0;font-weight:700;color:#1e293b;font-size:15px;">{$storeName}</p>
<p style="margin:2px 0 0;color:#16a34a;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">{$category}</p>
<p style="margin:4px 0 0;color:#64748b;font-size:13px;line-height:1.4;">{$description}</p>
</td>
</tr>
</table>
</td>
</tr>
HTML;
    }

    $btn = _marketingButton('Explorar Lojas', 'https://superbora.com.br/busca');

    $content = <<<HTML
<h2 style="margin:0 0 8px;color:#1e293b;font-size:22px;">Novidades na sua regiao!</h2>
<p style="color:#64748b;margin:0 0 20px;font-size:15px;line-height:1.6;">Ola {$nameEsc}, novas lojas chegaram ao SuperBora perto de voce.</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;">
{$storesHtml}
</table>

{$btn}
HTML;

    return _marketingEmailLayout($content, "{$nameEsc}, novas lojas no SuperBora perto de voce!");
}

/**
 * Promotional email with coupon
 *
 * @param string $name Customer first name
 * @param string $title Promotion title (e.g., "Semana do Frete Gratis")
 * @param string $description Promotion description
 * @param string $couponCode Coupon code
 * @param string $expiresAt Expiration date (Y-m-d or readable format)
 * @return string Complete HTML email
 */
function emailTemplate_promo(string $name, string $title, string $description, string $couponCode, string $expiresAt): string {
    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $descEsc = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    $couponEsc = htmlspecialchars($couponCode, ENT_QUOTES, 'UTF-8');

    // Format expiration date
    $expDate = strtotime($expiresAt);
    $expiresFormatted = $expDate ? date('d/m/Y', $expDate) : htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8');

    $btn = _marketingButton('Aproveitar Agora', 'https://superbora.com.br');

    $content = <<<HTML
<h2 style="margin:0 0 8px;color:#1e293b;font-size:22px;">{$titleEsc}</h2>
<p style="color:#64748b;margin:0 0 20px;font-size:15px;line-height:1.6;">Ola {$nameEsc}, {$descEsc}</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:20px 0;">
<tr>
<td align="center" style="background:linear-gradient(135deg,#fef9c3,#fef08a);border-radius:16px;padding:28px 24px;border:2px dashed #ca8a04;">
<p style="margin:0 0 4px;color:#92400e;font-size:13px;text-transform:uppercase;letter-spacing:1px;font-weight:600;">Cupom promocional</p>
<p style="margin:0 0 8px;color:#854d0e;font-size:32px;font-weight:700;letter-spacing:2px;">{$couponEsc}</p>
<p style="margin:0;color:#a16207;font-size:14px;">Valido ate {$expiresFormatted}</p>
</td>
</tr>
</table>

{$btn}

<p style="color:#94a3b8;font-size:12px;text-align:center;margin:0;">Promocao valida para pedidos pelo app ou site. Nao cumulativo.</p>
HTML;

    return _marketingEmailLayout($content, "{$nameEsc}, {$titleEsc} - Use o cupom {$couponEsc}!");
}
