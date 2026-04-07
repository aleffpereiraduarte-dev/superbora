<?php
/**
 * Cron: Seasonal/holiday auto-campaign
 *
 * Detects upcoming Brazilian holidays and seasonal moments and generates a
 * promotional campaign with banner copy + push template.
 *
 * Schedule: 0 7 * * *  (7 AM daily)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

// Brazilian holidays and seasonal moments — date in MM-DD format
$holidays = [
    '01-01' => 'Ano Novo',
    '02-14' => 'Dia dos Namorados (Valentines internacional)',
    '03-08' => 'Dia das Mulheres',
    '04-21' => 'Tiradentes',
    '05-01' => 'Dia do Trabalhador',
    '05-12' => 'Dia das Maes (segundo domingo - aproximado)',
    '06-12' => 'Dia dos Namorados (BR)',
    '06-24' => 'Sao Joao',
    '08-11' => 'Dia dos Pais (segundo domingo - aproximado)',
    '09-07' => 'Independencia',
    '10-12' => 'Dia das Criancas',
    '10-15' => 'Dia dos Professores',
    '11-15' => 'Proclamacao da Republica',
    '11-20' => 'Consciencia Negra',
    '11-25' => 'Black Friday (semana)',
    '12-25' => 'Natal',
    '12-31' => 'Reveillon',
];

$todayMd = date('m-d');
$nextDays = [];
for ($i = 0; $i <= 7; $i++) {
    $date = date('m-d', strtotime("+{$i} day"));
    if (isset($holidays[$date])) {
        $nextDays[$date] = $holidays[$date];
    }
}

if (empty($nextDays)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[seasonal] no upcoming holidays in next 7 days\n");
    exit(0);
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS om_seasonal_campaigns (
        id BIGSERIAL PRIMARY KEY,
        date_md VARCHAR(5) NOT NULL,
        holiday_name TEXT,
        push_title TEXT,
        push_body TEXT,
        banner_title TEXT,
        banner_subtitle TEXT,
        cta TEXT,
        promo_idea TEXT,
        created_at TIMESTAMPTZ DEFAULT NOW(),
        UNIQUE(date_md, holiday_name)
    )");
} catch (Exception $e) {}

$ins = $db->prepare(
    "INSERT INTO om_seasonal_campaigns (date_md, holiday_name, push_title, push_body, banner_title, banner_subtitle, cta, promo_idea)
     VALUES (:d, :h, :pt, :pb, :bt, :bs, :c, :p)
     ON CONFLICT (date_md, holiday_name) DO NOTHING"
);
$generated = 0;

foreach ($nextDays as $date => $name) {
    $prompt = "Crie uma campanha para a data {$date}: {$name}. " .
              "Empresa: SuperBora delivery. Responda APENAS JSON: " .
              '{"push_title":"max 50 chars com emoji","push_body":"max 120 chars",' .
              '"banner_title":"max 30 chars chamativo","banner_subtitle":"max 70 chars",' .
              '"cta":"max 20 chars","promo_idea":"sugestao de promocao max 150 chars"}';

    $reply = ClaudeClient::text($prompt, 'Voce eh diretor de marketing sazonal. Criativo e estrategico.', 500);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) continue;

    $ins->execute([
        ':d' => $date,
        ':h' => $name,
        ':pt' => $parsed['push_title'] ?? '',
        ':pb' => $parsed['push_body'] ?? '',
        ':bt' => $parsed['banner_title'] ?? '',
        ':bs' => $parsed['banner_subtitle'] ?? '',
        ':c' => $parsed['cta'] ?? '',
        ':p' => $parsed['promo_idea'] ?? '',
    ]);
    $generated++;
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[seasonal] generated {$generated} campaigns\n");
