<?php
/**
 * API do Editor Visual - Mercado OneMundo
 * Salva e carrega configurações de página
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    jsonResponse(['error' => 'Erro de conexão com banco de dados'], 500);
}

// Criar tabela se não existir
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `om_visual_editor` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `page_key` VARCHAR(100) NOT NULL,
        `element_id` VARCHAR(100) NOT NULL,
        `styles` TEXT,
        `content` TEXT,
        `attributes` TEXT,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `page_element` (`page_key`, `element_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `om_page_html` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `page_key` VARCHAR(100) NOT NULL UNIQUE,
        `html_content` LONGTEXT,
        `css_overrides` TEXT,
        `status` ENUM('draft', 'published') DEFAULT 'draft',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Obter ação
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'save':
        savePageHTML($pdo, $input);
        break;
    case 'load':
        loadPageHTML($pdo, $input);
        break;
    case 'save_element':
        saveElement($pdo, $input);
        break;
    case 'load_elements':
        loadElements($pdo, $input);
        break;
    case 'publish':
        publishPage($pdo, $input);
        break;
    case 'get_styles':
        getPageStyles($pdo, $input);
        break;
    default:
        jsonResponse(['error' => 'Ação inválida'], 400);
}

/**
 * Salvar HTML completo da página
 */
function savePageHTML($pdo, $data) {
    $page = $data['page'] ?? 'index';
    $html = $data['html'] ?? '';
    $css = $data['css'] ?? '';
    
    if (empty($html)) {
        jsonResponse(['error' => 'HTML não fornecido'], 400);
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO `om_page_html` (`page_key`, `html_content`, `css_overrides`, `status`)
        VALUES (:page, :html, :css, 'draft')
        ON DUPLICATE KEY UPDATE 
            `html_content` = :html2,
            `css_overrides` = :css2,
            `updated_at` = NOW()
    ");
    
    $stmt->execute([
        ':page' => $page,
        ':html' => $html,
        ':css' => $css,
        ':html2' => $html,
        ':css2' => $css
    ]);
    
    jsonResponse([
        'success' => true,
        'message' => 'Página salva com sucesso!'
    ]);
}

/**
 * Carregar HTML da página
 */
function loadPageHTML($pdo, $data) {
    $page = $data['page'] ?? $_GET['page'] ?? 'index';
    
    $stmt = $pdo->prepare("SELECT * FROM `om_page_html` WHERE `page_key` = :page");
    $stmt->execute([':page' => $page]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        jsonResponse([
            'success' => true,
            'data' => $result
        ]);
    } else {
        jsonResponse([
            'success' => false,
            'data' => null
        ]);
    }
}

/**
 * Salvar alterações de um elemento específico
 */
function saveElement($pdo, $data) {
    $page = $data['page'] ?? 'index';
    $elementId = $data['element_id'] ?? '';
    $styles = $data['styles'] ?? [];
    $content = $data['content'] ?? '';
    $attributes = $data['attributes'] ?? [];
    
    if (empty($elementId)) {
        jsonResponse(['error' => 'ID do elemento não fornecido'], 400);
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO `om_visual_editor` (`page_key`, `element_id`, `styles`, `content`, `attributes`)
        VALUES (:page, :element_id, :styles, :content, :attributes)
        ON DUPLICATE KEY UPDATE 
            `styles` = :styles2,
            `content` = :content2,
            `attributes` = :attributes2
    ");
    
    $stmt->execute([
        ':page' => $page,
        ':element_id' => $elementId,
        ':styles' => json_encode($styles),
        ':content' => $content,
        ':attributes' => json_encode($attributes),
        ':styles2' => json_encode($styles),
        ':content2' => $content,
        ':attributes2' => json_encode($attributes)
    ]);
    
    jsonResponse([
        'success' => true,
        'message' => 'Elemento salvo!'
    ]);
}

/**
 * Carregar elementos salvos
 */
function loadElements($pdo, $data) {
    $page = $data['page'] ?? $_GET['page'] ?? 'index';
    
    $stmt = $pdo->prepare("SELECT * FROM `om_visual_editor` WHERE `page_key` = :page");
    $stmt->execute([':page' => $page]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $elements = [];
    foreach ($results as $row) {
        $elements[$row['element_id']] = [
            'styles' => json_decode($row['styles'], true),
            'content' => $row['content'],
            'attributes' => json_decode($row['attributes'], true)
        ];
    }
    
    jsonResponse([
        'success' => true,
        'elements' => $elements
    ]);
}

/**
 * Publicar página (copiar draft para published)
 */
function publishPage($pdo, $data) {
    $page = $data['page'] ?? 'index';
    
    $stmt = $pdo->prepare("UPDATE `om_page_html` SET `status` = 'published' WHERE `page_key` = :page");
    $stmt->execute([':page' => $page]);
    
    jsonResponse([
        'success' => true,
        'message' => 'Página publicada com sucesso!'
    ]);
}

/**
 * Gerar CSS a partir dos elementos salvos
 */
function getPageStyles($pdo, $data) {
    $page = $data['page'] ?? $_GET['page'] ?? 'index';
    
    $stmt = $pdo->prepare("SELECT * FROM `om_visual_editor` WHERE `page_key` = :page");
    $stmt->execute([':page' => $page]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $css = "/* Estilos gerados pelo Editor Visual */\n";
    
    foreach ($results as $row) {
        $styles = json_decode($row['styles'], true);
        if (!empty($styles)) {
            $css .= "[data-id=\"{$row['element_id']}\"] {\n";
            foreach ($styles as $prop => $value) {
                $cssProp = camelToKebab($prop);
                $css .= "    {$cssProp}: {$value} !important;\n";
            }
            $css .= "}\n";
        }
    }
    
    // Também incluir CSS overrides da página
    $stmt = $pdo->prepare("SELECT `css_overrides` FROM `om_page_html` WHERE `page_key` = :page AND `status` = 'published'");
    $stmt->execute([':page' => $page]);
    $pageRow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($pageRow && !empty($pageRow['css_overrides'])) {
        $css .= "\n/* CSS Customizado */\n" . $pageRow['css_overrides'];
    }
    
    header('Content-Type: text/css');
    echo $css;
    exit;
}

/**
 * Converter camelCase para kebab-case
 */
function camelToKebab($string) {
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $string));
}

/**
 * Resposta JSON
 */
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}
