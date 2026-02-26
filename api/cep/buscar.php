<?php
/**
 * PROXY CEP - OneMundo v4 ULTRA
 * Retorna assim que a primeira API responder
 * Otimizado com cache (TTL: 24 horas) - dados de CEP raramente mudam
 */
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: public, max-age=86400");

require_once(__DIR__ . '/../cache/CacheHelper.php');

$cep = preg_replace("/[^0-9]/", "", $_GET["cep"] ?? $_POST["cep"] ?? "");

if (strlen($cep) !== 8) {
    die(json_encode(["erro" => true, "success" => false, "message" => "CEP inválido"]));
}

if (preg_match('/^(\d)\1{7}$/', $cep)) {
    die(json_encode(["erro" => true, "success" => false, "message" => "CEP inválido"]));
}

// Cache de 24 horas - dados de CEP são estáveis
$result = CacheHelper::remember("cep_{$cep}", 86400, function() use ($cep) {
    // Tentar OpenCEP primeiro (mais rápido)
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://opencep.com/v1/{$cep}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 1500,
        CURLOPT_CONNECTTIMEOUT_MS => 800,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if ($data && !empty($data["localidade"])) {
            return [
                "success" => true,
                "cep" => $data["cep"] ?? substr($cep, 0, 5) . "-" . substr($cep, 5),
                "logradouro" => $data["logradouro"] ?? "",
                "bairro" => $data["bairro"] ?? "",
                "cidade" => $data["localidade"],
                "localidade" => $data["localidade"],
                "uf" => $data["uf"] ?? ""
            ];
        }
    }

    // Fallback: ViaCEP
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://viacep.com.br/ws/{$cep}/json/",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 2000,
        CURLOPT_CONNECTTIMEOUT_MS => 1000,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if ($data && !isset($data["erro"]) && !empty($data["localidade"])) {
            return [
                "success" => true,
                "cep" => $data["cep"] ?? substr($cep, 0, 5) . "-" . substr($cep, 5),
                "logradouro" => $data["logradouro"] ?? "",
                "bairro" => $data["bairro"] ?? "",
                "cidade" => $data["localidade"],
                "localidade" => $data["localidade"],
                "uf" => $data["uf"] ?? ""
            ];
        }
    }

    // Nenhuma API funcionou - não cachear erro
    return null;
});

if ($result) {
    echo json_encode($result);
} else {
    echo json_encode(["erro" => true, "success" => false, "message" => "CEP não encontrado"]);
}
