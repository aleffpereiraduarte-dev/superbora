<?php
/**
 * API de Salvamento - Editor Visual Pro
 * Mercado OneMundo
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
    respond(['error' => 'Erro de conexão: ' . $e->getMessage()], 500);
}

// Criar tabelas se não existirem
createTables($pdo);

// Processar requisição
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'save':
        savePage($pdo, $input);
        break;
    case 'load':
        loadPage($pdo, $input);
        break;
    case 'publish':
        publishPage($pdo, $input);
        break;
    case 'list':
        listPages($pdo);
        break;
    case 'delete':
        deletePage($pdo, $input);
        break;
    case 'upload':
        uploadMedia();
        break;
    case 'media':
        listMedia($pdo);
        break;
    default:
        respond(['error' => 'Ação inválida'], 400);
}

/**
 * Criar tabelas necessárias
 */
function createTables($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `om_editor_pages` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `page_key` VARCHAR(100) NOT NULL,
            `title` VARCHAR(255),
            `description` TEXT,
            `slug` VARCHAR(100),
            `html_content` LONGTEXT,
            `global_styles` TEXT,
            `settings` TEXT,
            `status` ENUM('draft', 'published') DEFAULT 'draft',
            `is_homepage` TINYINT(1) DEFAULT 0,
            `version` INT(11) DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `page_key` (`page_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `om_editor_versions` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `page_id` INT(11) NOT NULL,
            `version` INT(11) NOT NULL,
            `html_content` LONGTEXT,
            `global_styles` TEXT,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `page_id` (`page_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `om_editor_media` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `filename` VARCHAR(255) NOT NULL,
            `original_name` VARCHAR(255),
            `path` VARCHAR(500) NOT NULL,
            `mime_type` VARCHAR(100),
            `size` INT(11),
            `width` INT(11),
            `height` INT(11),
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Salvar página
 */
function savePage($pdo, $data) {
    $pageKey = $data['page'] ?? 'index';
    $html = $data['html'] ?? '';
    $settings = $data['settings'] ?? [];
    $globalStyles = $data['globalStyles'] ?? [];
    
    // Verificar se página existe
    $stmt = $pdo->prepare("SELECT id, version FROM `om_editor_pages` WHERE `page_key` = ?");
    $stmt->execute([$pageKey]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Salvar versão anterior
        $pdo->prepare("
            INSERT INTO `om_editor_versions` (`page_id`, `version`, `html_content`, `global_styles`)
            SELECT `id`, `version`, `html_content`, `global_styles` FROM `om_editor_pages` WHERE `id` = ?
        ")->execute([$existing['id']]);
        
        // Atualizar
        $stmt = $pdo->prepare("
            UPDATE `om_editor_pages` SET
                `title` = ?,
                `description` = ?,
                `slug` = ?,
                `html_content` = ?,
                `global_styles` = ?,
                `settings` = ?,
                `is_homepage` = ?,
                `version` = `version` + 1
            WHERE `page_key` = ?
        ");
        
        $stmt->execute([
            $settings['title'] ?? '',
            $settings['description'] ?? '',
            $settings['slug'] ?? $pageKey,
            $html,
            json_encode($globalStyles),
            json_encode($settings),
            $settings['isHomepage'] ?? 0,
            $pageKey
        ]);
    } else {
        // Inserir novo
        $stmt = $pdo->prepare("
            INSERT INTO `om_editor_pages` 
            (`page_key`, `title`, `description`, `slug`, `html_content`, `global_styles`, `settings`, `is_homepage`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $pageKey,
            $settings['title'] ?? '',
            $settings['description'] ?? '',
            $settings['slug'] ?? $pageKey,
            $html,
            json_encode($globalStyles),
            json_encode($settings),
            $settings['isHomepage'] ?? 0
        ]);
    }
    
    // Se for homepage, desmarcar outras
    if (!empty($settings['isHomepage'])) {
        $pdo->prepare("UPDATE `om_editor_pages` SET `is_homepage` = 0 WHERE `page_key` != ?")->execute([$pageKey]);
    }
    
    respond([
        'success' => true,
        'message' => 'Página salva com sucesso!'
    ]);
}

/**
 * Carregar página
 */
function loadPage($pdo, $data) {
    $pageKey = $data['page'] ?? $_GET['page'] ?? 'index';
    
    $stmt = $pdo->prepare("SELECT * FROM `om_editor_pages` WHERE `page_key` = ?");
    $stmt->execute([$pageKey]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($page) {
        $page['global_styles'] = json_decode($page['global_styles'], true);
        $page['settings'] = json_decode($page['settings'], true);
        
        respond([
            'success' => true,
            'page' => $page
        ]);
    } else {
        respond([
            'success' => false,
            'page' => null
        ]);
    }
}

/**
 * Publicar página
 */
function publishPage($pdo, $data) {
    $pageKey = $data['page'] ?? 'index';
    
    $stmt = $pdo->prepare("UPDATE `om_editor_pages` SET `status` = 'published' WHERE `page_key` = ?");
    $stmt->execute([$pageKey]);
    
    respond([
        'success' => true,
        'message' => 'Página publicada!'
    ]);
}

/**
 * Listar páginas
 */
function listPages($pdo) {
    $stmt = $pdo->query("SELECT `id`, `page_key`, `title`, `slug`, `status`, `is_homepage`, `updated_at` FROM `om_editor_pages` ORDER BY `updated_at` DESC");
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    respond([
        'success' => true,
        'pages' => $pages
    ]);
}

/**
 * Excluir página
 */
function deletePage($pdo, $data) {
    $pageKey = $data['page'] ?? '';
    
    if (empty($pageKey)) {
        respond(['error' => 'Página não especificada'], 400);
    }
    
    $pdo->prepare("DELETE FROM `om_editor_pages` WHERE `page_key` = ?")->execute([$pageKey]);
    
    respond([
        'success' => true,
        'message' => 'Página excluída!'
    ]);
}

/**
 * Upload de mídia
 */
function uploadMedia() {
    if (empty($_FILES['file'])) {
        respond(['error' => 'Nenhum arquivo enviado'], 400);
    }
    
    $file = $_FILES['file'];
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4'];
    
    if (!in_array($file['type'], $allowed)) {
        respond(['error' => 'Tipo de arquivo não permitido'], 400);
    }
    
    if ($file['size'] > 10 * 1024 * 1024) { // 10MB
        respond(['error' => 'Arquivo muito grande (máx 10MB)'], 400);
    }
    
    $uploadDir = '../../../image/editor/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '.' . $ext;
    $path = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $path)) {
        respond([
            'success' => true,
            'url' => '/image/editor/' . $filename,
            'filename' => $filename
        ]);
    } else {
        respond(['error' => 'Erro ao fazer upload'], 500);
    }
}

/**
 * Listar mídia
 */
function listMedia($pdo) {
    $uploadDir = '../../../image/editor/';
    $files = [];
    
    if (is_dir($uploadDir)) {
        $items = scandir($uploadDir);
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                $files[] = [
                    'filename' => $item,
                    'url' => '/image/editor/' . $item
                ];
            }
        }
    }
    
    respond([
        'success' => true,
        'files' => $files
    ]);
}

/**
 * Resposta JSON
 */
function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}
