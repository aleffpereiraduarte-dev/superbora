<?php
header('Content-Type: application/json');
require_once '/var/www/html/includes/classes/OmAuth.php';

// Get the secret key that OmAuth is actually using
$reflection = new ReflectionClass('OmAuth');
$property = $reflection->getProperty('secretKey');
$property->setAccessible(true);
$instance = OmAuth::getInstance();
$secretKey = $property->getValue($instance);

echo json_encode([
    'secretKey' => substr($secretKey, 0, 50) . '...',
], JSON_PRETTY_PRINT);
