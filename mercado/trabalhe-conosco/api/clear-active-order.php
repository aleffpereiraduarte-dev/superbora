<?php
session_start();
unset($_SESSION['active_order']);
echo json_encode(['success' => true]);
