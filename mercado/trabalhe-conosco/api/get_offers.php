<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['worker_id'])) exit(json_encode(['offers' => []]));

// Por enquanto retorna vazio - será integrado com sistema de ofertas
echo json_encode(['offers' => []]);
