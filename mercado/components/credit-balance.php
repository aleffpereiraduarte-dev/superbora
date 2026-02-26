<?php
/**
 * 💰 COMPONENTE DE SALDO DE CRÉDITOS
 */
$customer_id = isset($_SESSION["customer_id"]) ? $_SESSION["customer_id"] : 0;
if (!$customer_id) return;
?>
<div id="credit-balance-widget" style="display:none;background:linear-gradient(135deg,#059669 0%,#047857 100%);border-radius:12px;padding:16px;margin:16px 0;">
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div style="font-size:11px;opacity:0.8;">💰 Seu saldo</div>
            <div style="font-size:24px;font-weight:800;" id="credit-balance-value">R$ 0,00</div>
        </div>
        <div style="font-size:32px;">💳</div>
    </div>
</div>

<script>
(function() {
    async function loadBalance() {
        try {
            const res = await fetch("/mercado/api/refund.php?action=balance");
            const data = await res.json();
            
            if (data.success && data.balance > 0) {
                document.getElementById("credit-balance-widget").style.display = "block";
                document.getElementById("credit-balance-value").textContent = 
                    "R$ " + data.balance.toFixed(2).replace(".", ",");
            }
        } catch (e) {}
    }
    
    loadBalance();
})();
</script>
