<?php
if (!isset($customer_id)) {
    if (isset($_SESSION["customer_id"])) {
        $customer_id = $_SESSION["customer_id"];
    } elseif (isset($_SESSION["cliente_id"])) {
        $customer_id = $_SESSION["cliente_id"];
    } elseif (isset($_SESSION["user_id"])) {
        $customer_id = $_SESSION["user_id"];
    } else {
        $customer_id = 0;
    }
}
if ($customer_id):
?>
<div data-customer-id="<?php echo intval($customer_id); ?>" style="display:none;"></div>
<script src="/mercado/assets/js/cliente-notifications.js"></script>
<?php endif; ?>