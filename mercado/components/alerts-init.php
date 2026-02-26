<?php
/**
 * 🔔 COMPONENTE DE ALERTAS - INCLUIR NAS PÁGINAS SHOPPER/DELIVERY
 * 
 * USO:
 * <?php 
 * $alert_user_type = "delivery"; // ou "shopper"
 * $alert_user_id = $delivery_id; // ou $shopper_id
 * include __DIR__ . "/../components/alerts-init.php"; 
 * ?>
 */

if (!isset($alert_user_type) || !isset($alert_user_id)) {
    return;
}
?>

<!-- OneMundo Alert System -->
<script src="/mercado/assets/js/om-alerts.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Inicializar sistema de alertas
    omAlerts = new OneMundoAlerts({
        userType: "<?= $alert_user_type ?>",
        userId: <?= intval($alert_user_id) ?>,
        pollInterval: 3000
    });
    
    console.log("🔔 Sistema de alertas iniciado para <?= $alert_user_type ?> #<?= $alert_user_id ?>");
});
</script>
