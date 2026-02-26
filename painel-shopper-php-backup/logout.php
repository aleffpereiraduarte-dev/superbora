<?php
session_start();
session_destroy();
header('Location: /painel/shopper/login.php');
exit;
