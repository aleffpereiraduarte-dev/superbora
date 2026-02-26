<?php
session_name('OCSESSID');
session_start();

$_SESSION['market_partner_id'] = 100;
$_SESSION['market_partner_name'] = 'Mercado Central GV';
$_SESSION['market_cep'] = '35040090';
$_SESSION['market_cidade'] = 'Governador Valadares';
$_SESSION['mercado_proximo'] = [
    'partner_id' => 100,
    'nome' => 'Mercado Central GV',
    'distancia' => 0.8,
    'tempo' => 3
];
$_SESSION['customer_cep'] = '35040090';

header('Location: /mercado/');
exit;
