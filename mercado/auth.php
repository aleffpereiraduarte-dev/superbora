<?php
// Arquivo de autenticação para Shopper v5.0
require_once 'config.php';

function requireLogin() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['shopper_id'])) {
        header('Location: login.php');
        exit;
    }
}

function checkShopperAuth() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['shopper_id']) ? $_SESSION['shopper_id'] : false;
}

function loginShopper($shopper_id, $name = '', $email = '') {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['shopper_id'] = $shopper_id;
    $_SESSION['shopper_name'] = $name;
    $_SESSION['shopper_email'] = $email;
    $_SESSION['login_time'] = time();
}

function logoutShopper() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    session_unset();
    session_destroy();
}

// Verificação automática de autenticação se não for página de login
if (basename($_SERVER['PHP_SELF']) !== 'login.php' && basename($_SERVER['PHP_SELF']) !== 'register.php') {
    requireLogin();
}
?>