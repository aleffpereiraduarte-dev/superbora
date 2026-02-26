<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false]); exit; }

define('OPENAI_KEY', getenv('OPENAI_API_KEY') ?: '');

$input = json_decode(file_get_contents('php://input'), true);
$image = $input['image'] ?? '';
if (empty($image)) { echo json_encode(['success'=>false]); exit; }

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_KEY],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'gpt-4o-mini',
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Produto na imagem? JSON: {"produto":"nome","termos":["termo1","termo2"]}'],
                ['type' => 'image_url', 'image_url' => ['url' => $image, 'detail' => 'low']]
            ]
        ]],
        'max_tokens' => 80
    ])
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code === 200) {
    $data = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    preg_match('/\{[^}]+\}/', $content, $m);
    if (!empty($m[0])) {
        $r = json_decode($m[0], true);
        if ($r) { echo json_encode(['success'=>true,'data'=>$r]); exit; }
    }
}
echo json_encode(['success'=>false]);