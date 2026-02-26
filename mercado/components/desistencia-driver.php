<?php
/**
 * Componente de Desistência - Incluir nas páginas do delivery
 */
?>
<script>
async function desistirPedido(orderId, driverId) {
    if (!confirm("Tem certeza que deseja desistir?\n\nIsso afetará sua pontuação.")) return;
    
    try {
        const response = await fetch("/mercado/api/driver_penalty.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ driver_id: driverId, order_id: orderId, reason: "desistencia" })
        });
        const data = await response.json();
        if (data.success) {
            alert("Pedido liberado.\nPontos perdidos: " + data.points_lost + "\nSeu score: " + data.new_score + "/100");
            location.reload();
        } else {
            alert("Erro: " + (data.error || "Tente novamente"));
        }
    } catch (e) {
        alert("Erro de conexão");
    }
}
</script>
<style>
.btn-desistir { background: #dc2626; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; }
.btn-desistir:hover { background: #b91c1c; }
</style>