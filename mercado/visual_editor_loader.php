<?php
/**
 * Loader do Editor Visual - Mercado OneMundo
 * Inclua este arquivo no header do seu template para aplicar os estilos salvos
 * 
 * Uso: <?php include 'mercado/admin/visual_editor_loader.php'; ?>
 */

// Verificar se está em modo de edição
$editor_mode = isset($_GET['editor_mode']) && $_GET['editor_mode'] == '1';

// Identificar a página atual
$page_key = 'index'; // Ajuste conforme necessário

// Carregar estilos do banco se não estiver em modo editor
if (!$editor_mode) {
    // Conectar ao banco
    try {
        $db_host = '147.93.12.236';
        $db_name = 'love1';
        $db_user = 'root';
        $db_pass = '';
        
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Verificar se tabela existe
        $stmt = $pdo->query("SHOW TABLES LIKE 'om_visual_editor'");
        if ($stmt->rowCount() > 0) {
            
            // Carregar elementos salvos
            $stmt = $pdo->prepare("SELECT * FROM `om_visual_editor` WHERE `page_key` = :page");
            $stmt->execute([':page' => $page_key]);
            $elements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($elements)) {
                echo "<style id='visual-editor-styles'>\n";
                echo "/* Estilos do Editor Visual */\n";
                
                foreach ($elements as $el) {
                    $styles = json_decode($el['styles'], true);
                    if (!empty($styles)) {
                        echo "[data-id=\"{$el['element_id']}\"] {\n";
                        foreach ($styles as $prop => $value) {
                            $cssProp = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $prop));
                            echo "    {$cssProp}: {$value} !important;\n";
                        }
                        echo "}\n";
                    }
                }
                
                echo "</style>\n";
            }
            
            // Carregar HTML customizado se publicado
            $stmt = $pdo->prepare("SELECT `css_overrides` FROM `om_page_html` WHERE `page_key` = :page AND `status` = 'published'");
            $stmt->execute([':page' => $page_key]);
            $pageRow = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($pageRow && !empty($pageRow['css_overrides'])) {
                echo "<style id='visual-editor-custom-css'>\n";
                echo $pageRow['css_overrides'];
                echo "</style>\n";
            }
        }
        
    } catch (PDOException $e) {
        // Silenciosamente ignorar se não conseguir conectar
    }
}

// Se estiver em modo editor, adicionar atributos data-id aos elementos
if ($editor_mode) {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Adicionar data-id a elementos que não têm
        let counter = 0;
        const selectors = [
            'header', 'footer', 'nav', 'section', 'article', 'aside',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'a', 'button',
            'img', 'div[class]', 'form', 'input', 'textarea', 'select',
            '.banner', '.hero', '.slider', '.carousel', '.product', '.category',
            '.card', '.btn', '.button', '.container', '.wrapper'
        ];
        
        selectors.forEach(selector => {
            document.querySelectorAll(selector).forEach(el => {
                if (!el.hasAttribute('data-id')) {
                    el.setAttribute('data-id', 'el-' + (++counter));
                }
                if (!el.hasAttribute('data-editable')) {
                    el.setAttribute('data-editable', 'true');
                }
            });
        });
    });
    </script>
    <?php
}
?>
