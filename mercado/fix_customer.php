<?php
/**
 * Corrige model customer - adiciona métodos getAffiliate
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo '<pre style="background:#1e1e1e;color:#f8f8f2;padding:20px;font-family:monospace;">';
echo "<span style='color:#66d9ef;font-size:18px;'>🔧 Corrigir Model Customer</span>\n\n";

$customerModelFile = dirname(__FILE__) . '/catalog/model/account/customer.php';

if (!file_exists($customerModelFile)) {
    echo "<span style='color:#f92672;'>❌ Arquivo não encontrado!</span>\n";
    echo "Caminho: {$customerModelFile}\n";
    exit;
}

$content = file_get_contents($customerModelFile);
echo "✅ Arquivo encontrado (" . strlen($content) . " bytes)\n\n";

// Verificar se já tem o método
if (strpos($content, 'function getAffiliate') !== false) {
    echo "<span style='color:#a6e22e;'>✅ Método getAffiliate já existe!</span>\n";
    echo "\nNão precisa fazer nada.\n";
    echo '</pre>';
    exit;
}

echo "⚠️ Método getAffiliate NÃO existe. Adicionando...\n\n";

// Métodos a adicionar
$newMethods = '
    public function getAffiliate($customer_id) {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer_affiliate WHERE customer_id = \'" . (int)$customer_id . "\'");
        return $query->row;
    }

    public function getAffiliateByTracking($tracking) {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer_affiliate WHERE tracking = \'" . $this->db->escape($tracking) . "\'");
        return $query->row;
    }
}';

// Remover última chave e adicionar métodos
$content = rtrim($content);
if (substr($content, -1) === '}') {
    $content = substr($content, 0, -1);
}

$newContent = $content . $newMethods;

// Backup
$backupFile = $customerModelFile . '.backup.' . date('YmdHis');
file_put_contents($backupFile, file_get_contents($customerModelFile));
echo "✅ Backup criado: " . basename($backupFile) . "\n";

// Salvar
file_put_contents($customerModelFile, $newContent);
echo "✅ Métodos adicionados!\n\n";

// Verificar
$verify = file_get_contents($customerModelFile);
if (strpos($verify, 'function getAffiliate') !== false) {
    echo "<span style='color:#a6e22e;'>✅ SUCESSO!</span>\n\n";
    echo "Agora faça:\n";
    echo "1. Admin > Extensions > Modifications > Refresh\n";
    echo "2. Teste: index.php?route=account/account\n";
} else {
    echo "<span style='color:#f92672;'>❌ Erro ao salvar!</span>\n";
}

echo '</pre>';
?>
