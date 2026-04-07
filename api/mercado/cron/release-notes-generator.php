<?php
/**
 * Cron: Release notes generator
 *
 * Reads recent git commits (last 7 days) from /var/www/html and uses Llama
 * to write a friendly changelog in pt-BR for the in-app "What's new" screen.
 *
 * Schedule: 0 6 * * 1  (every Monday at 6 AM)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

// Get commits from the last 7 days
$repo = '/var/www/html';
$cmd = "cd {$repo} && git log --since='7 days ago' --pretty=format:'%h %s' 2>&1";
$out = shell_exec($cmd);
if (!$out) {
    fwrite(STDERR, "[release-notes] no git output\n");
    exit(1);
}

$lines = array_filter(array_map('trim', explode("\n", $out)));
if (empty($lines)) {
    fwrite(STDOUT, "[release-notes] no commits this week\n");
    exit(0);
}

$commits = implode("\n", array_slice($lines, 0, 100));

$prompt = "Aqui estao os commits do SuperBora dos ultimos 7 dias:\n\n{$commits}\n\n" .
          "Gere um changelog AMIGAVEL em pt-BR para mostrar aos usuarios. " .
          "AGRUPE por categoria (Novidades, Melhorias, Correcoes), use bullets curtos com emoji. " .
          "Filtre commits irrelevantes (refactor interno, lint, etc.). " .
          "Max 1500 chars total. Responda APENAS JSON: " .
          '{"version":"semantic ou data","headline":"frase curta","sections":[{"title":"Novidades","items":["..."]}],' .
          '"footer":"frase de agradecimento"}';

$reply = ClaudeClient::text(
    $prompt,
    'Voce eh o redator de release notes. Foco no usuario final, nao no dev.',
    1500
);
$parsed = ClaudeClient::parseJson($reply ?: '');
if (!$parsed) {
    fwrite(STDERR, "[release-notes] AI failed\n");
    exit(1);
}

// Persist
$db = getDB();
try {
    $db->exec("CREATE TABLE IF NOT EXISTS om_release_notes (
        id BIGSERIAL PRIMARY KEY,
        version VARCHAR(50),
        week_of DATE NOT NULL,
        headline TEXT,
        body_json JSONB,
        published BOOLEAN DEFAULT true,
        created_at TIMESTAMPTZ DEFAULT NOW()
    )");
    $db->prepare(
        "INSERT INTO om_release_notes (version, week_of, headline, body_json)
         VALUES (:v, :w, :h, :b)"
    )->execute([
        ':v' => $parsed['version'] ?? date('Y.m.d'),
        ':w' => date('Y-m-d', strtotime('last monday')),
        ':h' => $parsed['headline'] ?? '',
        ':b' => json_encode($parsed),
    ]);
    fwrite(STDOUT, "[release-notes] generated for week of " . date('Y-m-d', strtotime('last monday')) . "\n");
} catch (Exception $e) {
    fwrite(STDERR, "[release-notes] " . $e->getMessage() . "\n");
}
