<?php
// Auth helper functions for Shopper system

function checkShopperAuth() {
    if (!isset($_SESSION['shopper_id'])) {
        return false;
    }
    return true;
}

function redirectToLogin() {
    header('Location: login.php');
    exit;
}

function validateShopperCredentials($email, $password, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT shopper_id, name, email, partner_id FROM om_market_shoppers WHERE email = ? AND password = SHA2(?, 256)");
        $stmt->execute([$email, $password]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function loginShopper($shopper_data) {
    $_SESSION['shopper_id'] = $shopper_data['shopper_id'];
    $_SESSION['shopper_name'] = $shopper_data['name'];
    $_SESSION['shopper_email'] = $shopper_data['email'];
    $_SESSION['partner_id'] = $shopper_data['partner_id'];
}

function logoutShopper($pdo) {
    if (isset($_SESSION['shopper_id'])) {
        $stmt = $pdo->prepare("UPDATE om_market_shoppers SET status = 'offline', is_busy = 0 WHERE shopper_id = ?");
        $stmt->execute([$_SESSION['shopper_id']]);
    }
    session_destroy();
}
?>