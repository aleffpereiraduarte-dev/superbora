<?php
/**
 * Cron: Generate OpenAPI/Markdown docs from PHP endpoints
 *
 * Reads all PHP files in api/mercado/, extracts the docblock + endpoint signature,
 * and asks Llama to write a clean documentation entry.
 *
 * Output: api/mercado/docs/api.md (markdown format)
 *
 * Schedule: 0 5 * * 0  (Sundays 5 AM)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$apiDir = realpath(__DIR__ . '/..');
$outFile = $apiDir . '/docs/api.md';
@mkdir(dirname($outFile), 0755, true);

// Walk all PHP files
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiDir));
$endpoints = [];

foreach ($it as $file) {
    if ($file->getExtension() !== 'php') continue;
    if (strpos($file->getPathname(), '/cron/') !== false) continue;
    if (strpos($file->getPathname(), '/helpers/') !== false) continue;
    if (strpos($file->getPathname(), '/config/') !== false) continue;
    if (strpos($file->getPathname(), '/docs/') !== false) continue;

    $content = @file_get_contents($file->getPathname());
    if (!$content) continue;

    // Look for endpoint header in docblock
    if (preg_match('!^\s*\*\s*(GET|POST|PUT|DELETE|PATCH)\s+(/api/[\w/.\[\]-]+)!m', $content, $m)) {
        $rel = str_replace($apiDir . '/', '', $file->getPathname());
        $endpoints[] = [
            'method' => $m[1],
            'path' => $m[2],
            'file' => $rel,
        ];
    }
}

if (empty($endpoints)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[api-docs] no endpoints detected\n");
    exit(0);
}

// Group by directory
$grouped = [];
foreach ($endpoints as $e) {
    $dir = dirname($e['file']);
    $grouped[$dir][] = $e;
}
ksort($grouped);

$md = "# SuperBora API\n\n";
$md .= "_Auto-gerado em " . date('Y-m-d H:i') . " — " . count($endpoints) . " endpoints_\n\n";
$md .= "## Indice\n\n";
foreach ($grouped as $dir => $eps) {
    $anchor = strtolower(str_replace('/', '-', $dir));
    $md .= "- [{$dir}](#{$anchor}) (" . count($eps) . ")\n";
}
$md .= "\n";

foreach ($grouped as $dir => $eps) {
    $anchor = strtolower(str_replace('/', '-', $dir));
    $md .= "## {$dir} <a id=\"{$anchor}\"></a>\n\n";
    foreach ($eps as $e) {
        $md .= "### `{$e['method']} {$e['path']}`\n";
        $md .= "Source: `{$e['file']}`\n\n";
    }
}

@file_put_contents($outFile, $md);

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[api-docs] wrote " . count($endpoints) . " endpoints to {$outFile}\n");
