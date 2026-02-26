<?php
function formatMoney($v) { return "R$ " . number_format((float)$v, 2, ",", "."); }
function isLoggedIn() { if(session_status()===PHP_SESSION_NONE){session_name("OCSESSID");session_start();} return !empty($_SESSION["customer_id"]); }
function getCustomerId() { if(session_status()===PHP_SESSION_NONE){session_name("OCSESSID");session_start();} return $_SESSION["customer_id"] ?? 0; }
function getCart() { if(session_status()===PHP_SESSION_NONE){session_name("OCSESSID");session_start();} return $_SESSION["market_cart"] ?? []; }
function getCartCount() { $c=0; foreach(getCart() as $i) $c+=$i["qty"]; return $c; }
function getCartTotal() { $t=0; foreach(getCart() as $i) { $p=($i["price_promo"]??0)>0?$i["price_promo"]:$i["price"]; $t+=$p*$i["qty"]; } return $t; }