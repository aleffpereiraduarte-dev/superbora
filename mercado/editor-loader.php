<?php
/**
 * Loader do Editor Visual Pro - Mercado OneMundo
 * 
 * COMO USAR:
 * Adicione esta linha no header do seu template (antes do </head>):
 * <?php include 'admin/editor-loader.php'; ?>
 * 
 * Ou em Twig:
 * {{ include('admin/editor-loader.php') }}
 */

// Configuração do banco de dados
$editor_config = [
    'host' => 'localhost',
    'dbname' => 'love1',
    'user' => 'root',
    'pass' => ''
];

// Identificar página atual
$current_page = 'index';
if (isset($_GET['route'])) {
    $current_page = str_replace('/', '-', $_GET['route']);
}

// Verificar se está em modo de edição
$editor_mode = isset($_GET['editor_mode']) && $_GET['editor_mode'] == '1';

// Função para carregar estilos do banco
function loadEditorStyles($config, $page_key) {
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4",
            $config['user'],
            $config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Verificar se tabela existe
        $stmt = $pdo->query("SHOW TABLES LIKE 'om_editor_pages'");
        if ($stmt->rowCount() === 0) {
            return null;
        }
        
        // Buscar página publicada
        $stmt = $pdo->prepare("
            SELECT `html_content`, `global_styles` 
            FROM `om_editor_pages` 
            WHERE (`page_key` = ? OR `is_homepage` = 1) 
            AND `status` = 'published'
            ORDER BY `page_key` = ? DESC, `is_homepage` DESC
            LIMIT 1
        ");
        $stmt->execute([$page_key, $page_key]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        // Silenciosamente falhar se não conseguir conectar
        return null;
    }
}

// Carregar dados salvos (apenas se NÃO estiver em modo de edição)
if (!$editor_mode) {
    $saved_data = loadEditorStyles($editor_config, $current_page);
    
    if ($saved_data) {
        $global_styles = json_decode($saved_data['global_styles'], true);
        
        // Aplicar CSS Variables
        if (!empty($global_styles)) {
            echo "<style id='editor-global-styles'>\n";
            echo ":root {\n";
            
            if (!empty($global_styles['primary'])) {
                echo "    --primary: {$global_styles['primary']};\n";
            }
            if (!empty($global_styles['secondary'])) {
                echo "    --secondary: {$global_styles['secondary']};\n";
            }
            if (!empty($global_styles['accent'])) {
                echo "    --accent: {$global_styles['accent']};\n";
            }
            if (!empty($global_styles['text'])) {
                echo "    --text: {$global_styles['text']};\n";
            }
            if (!empty($global_styles['font'])) {
                echo "    --font: '{$global_styles['font']}', sans-serif;\n";
            }
            if (!empty($global_styles['container'])) {
                echo "    --container: {$global_styles['container']}px;\n";
            }
            if (!empty($global_styles['radius'])) {
                echo "    --radius: {$global_styles['radius']}px;\n";
            }
            
            echo "}\n";
            echo "</style>\n";
        }
    }
}

// Se estiver em modo de edição, adicionar script para comunicação com o editor
if ($editor_mode) {
    ?>
    <script>
    // Comunicação com o editor pai (iframe)
    window.addEventListener('message', function(event) {
        if (event.data.type === 'updateStyle') {
            const el = document.querySelector('[data-editor-id="' + event.data.elementId + '"]');
            if (el) {
                el.style[event.data.property] = event.data.value;
            }
        }
        
        if (event.data.type === 'getHTML') {
            parent.postMessage({
                type: 'pageHTML',
                html: document.body.innerHTML
            }, '*');
        }
    });
    
    // Notificar que a página foi carregada
    if (parent !== window) {
        parent.postMessage({ type: 'pageLoaded' }, '*');
    }
    </script>
    <?php
}
?>
