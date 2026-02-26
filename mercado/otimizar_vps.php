<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔒 SEGURANÇA + OTIMIZAÇÃO VPS - ONEMUNDO
 * Acesse: https://onemundo.com.br/otimizar_vps.php
 */

header('Content-Type: text/html; charset=UTF-8');

$action = $_GET['action'] ?? '';
$results = [];

function runCmd($cmd) {
    return shell_exec($cmd . ' 2>&1') ?? 'Erro';
}

// Executar ações
if ($action) {
    switch ($action) {
        case 'firewall':
            // Configurar UFW
            $results[] = "Instalando UFW...";
            $results[] = runCmd('sudo apt-get install -y ufw');
            $results[] = "Configurando regras...";
            $results[] = runCmd('sudo ufw default deny incoming');
            $results[] = runCmd('sudo ufw default allow outgoing');
            $results[] = runCmd('sudo ufw allow 22/tcp');  // SSH
            $results[] = runCmd('sudo ufw allow 80/tcp');  // HTTP
            $results[] = runCmd('sudo ufw allow 443/tcp'); // HTTPS
            $results[] = runCmd('sudo ufw allow from 127.0.0.1 to any port 3306'); // MySQL só local
            $results[] = runCmd('sudo ufw allow from 127.0.0.1 to any port 6379'); // Redis só local
            $results[] = runCmd('echo "y" | sudo ufw enable');
            $results[] = runCmd('sudo ufw status');
            break;
            
        case 'mysql_secure':
            // Fechar MySQL para conexões externas
            $results[] = "Configurando MySQL para só aceitar localhost...";
            $mysqlConf = "[mysqld]\nbind-address = 127.0.0.1\n";
            file_put_contents('/tmp/mysql_bind.cnf', $mysqlConf);
            $results[] = runCmd('sudo cp /tmp/mysql_bind.cnf /etc/mysql/mysql.conf.d/bind-address.cnf');
            $results[] = runCmd('sudo systemctl restart mysql');
            $results[] = "MySQL reiniciado - agora só aceita conexões locais!";
            break;
            
        case 'opcache':
            // Ativar OPcache
            $results[] = "Verificando OPcache...";
            $opcacheConf = <<<'CONF'
[opcache]
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
opcache.fast_shutdown=1
opcache.jit=1255
opcache.jit_buffer_size=128M
CONF;
            file_put_contents('/tmp/opcache.ini', $opcacheConf);
            $results[] = runCmd('sudo cp /tmp/opcache.ini /etc/php/8.3/mods-available/custom-opcache.ini');
            $results[] = runCmd('sudo phpenmod custom-opcache');
            $results[] = runCmd('sudo systemctl restart php8.3-fpm');
            $results[] = "OPcache configurado e ativado!";
            break;
            
        case 'swap':
            // Criar Swap
            $results[] = "Criando Swap de 4GB...";
            $results[] = runCmd('sudo fallocate -l 4G /swapfile');
            $results[] = runCmd('sudo chmod 600 /swapfile');
            $results[] = runCmd('sudo mkswap /swapfile');
            $results[] = runCmd('sudo swapon /swapfile');
            $results[] = runCmd('echo "/swapfile none swap sw 0 0" | sudo tee -a /etc/fstab');
            $results[] = runCmd('free -h');
            break;
            
        case 'ssl':
            // Instalar SSL Let's Encrypt
            $results[] = "Instalando Certbot...";
            $results[] = runCmd('sudo apt-get install -y certbot python3-certbot-nginx');
            $results[] = "Gerando certificado para onemundo.com.br...";
            $results[] = runCmd('sudo certbot --nginx -d onemundo.com.br -d www.onemundo.com.br --non-interactive --agree-tos -m contato@onemundo.com.br');
            break;
            
        case 'fix_embedding':
            // Verificar/criar coluna embedding
            try {
                $pdo = getPDO();
                
                // Verificar estrutura atual
                $cols = $pdo->query("SHOW COLUMNS FROM om_one_brain_universal")->fetchAll(PDO::FETCH_COLUMN);
                $results[] = "Colunas atuais: " . implode(', ', $cols);
                
                if (!in_array('embedding', $cols)) {
                    $results[] = "Coluna 'embedding' não existe. Criando...";
                    $pdo->exec("ALTER TABLE om_one_brain_universal ADD COLUMN embedding LONGTEXT NULL AFTER resposta");
                    $results[] = "✅ Coluna 'embedding' criada!";
                } else {
                    $results[] = "✅ Coluna 'embedding' já existe!";
                }
                
                // Contar registros
                $total = $pdo->query("SELECT COUNT(*) FROM om_one_brain_universal")->fetchColumn();
                $results[] = "Total de registros no brain: " . number_format($total);
                
            } catch (Exception $e) {
                $results[] = "Erro: " . $e->getMessage();
            }
            break;
            
        case 'check_tables':
            // Verificar nomes das maiores tabelas
            try {
                $pdo = getPDO();
                $tables = $pdo->query("SELECT table_name, ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb, table_rows FROM information_schema.tables WHERE table_schema = 'love1' ORDER BY (data_length + index_length) DESC LIMIT 15")->fetchAll();
                
                $results[] = "<table border='1' cellpadding='5'><tr><th>Tabela</th><th>Tamanho (MB)</th><th>Linhas</th></tr>";
                foreach ($tables as $t) {
                    $results[] = "<tr><td>{$t['table_name']}</td><td>{$t['size_mb']}</td><td>" . number_format($t['table_rows']) . "</td></tr>";
                }
                $results[] = "</table>";
                
            } catch (Exception $e) {
                $results[] = "Erro: " . $e->getMessage();
            }
            break;
            
        case 'crons':
            // Criar crons recomendados
            $cronJobs = <<<'CRON'
# OneMundo Crons
# Renovar SSL
0 0 1 * * certbot renew --quiet --post-hook 'systemctl reload nginx'

# Limpar logs antigos (mais de 30 dias)
0 3 * * * find /var/log -name "*.log" -mtime +30 -delete

# Limpar sessões PHP antigas
0 4 * * * find /var/lib/php/sessions -mtime +1 -delete

# Worker de embeddings (a cada 5 minutos)
*/5 * * * * php /var/www/html/mercado/cron_embeddings.php >> /var/log/embeddings.log 2>&1

# Otimizar tabelas MySQL (domingo 3h)
0 3 * * 0 mysqlcheck --defaults-file=/root/.my.cnf --optimize love1

# Backup diário (2h da manhã)
0 2 * * * mysqldump --defaults-file=/root/.my.cnf love1 | gzip > /home/onemundo/backups/love1_$(date +\%Y\%m\%d).sql.gz
CRON;
            $results[] = "<h4>Crons recomendados:</h4><pre>$cronJobs</pre>";
            $results[] = "<p>Para instalar, rode: <code>crontab -e</code> e cole os crons acima.</p>";
            break;
            
        case 'cleanup':
            // Limpar arquivos de instalação da raiz
            $results[] = "Arquivos de instalação na raiz:";
            $files = glob('/var/www/html/*.php');
            $installFiles = [];
            foreach ($files as $f) {
                $name = basename($f);
                if (preg_match('/(INSTALL|CRIAR|ADICIONAR|AJUSTAR|GERAR|MIGRAR|TEST)/i', $name)) {
                    $installFiles[] = $name;
                }
            }
            $results[] = "<pre>" . implode("\n", $installFiles) . "</pre>";
            $results[] = "<p>Total: " . count($installFiles) . " arquivos que podem ser movidos para uma pasta /installers/</p>";
            break;
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>🔧 Otimizar VPS - OneMundo</title>
    <style>
        body { background: #1a1a2e; color: #eee; font-family: 'Courier New', monospace; padding: 20px; }
        .section { background: #16213e; border-radius: 10px; padding: 15px; margin: 15px 0; border-left: 4px solid #00ff88; }
        .section h2 { color: #00ff88; margin-top: 0; }
        .btn { 
            background: #00ff88; color: #1a1a2e; border: none; padding: 12px 24px; 
            border-radius: 5px; cursor: pointer; font-weight: bold; margin: 5px;
            text-decoration: none; display: inline-block;
        }
        .btn:hover { background: #00cc6a; }
        .btn-danger { background: #ff4444; color: white; }
        .btn-warning { background: #ffaa00; color: #1a1a2e; }
        pre { background: #0f0f23; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .result { background: #0f3d0f; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .header { text-align: center; padding: 20px; }
        .header h1 { color: #00ff88; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; }
        .status-ok { color: #00ff88; }
        .status-warn { color: #ffaa00; }
        .status-error { color: #ff4444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #333; }
        th { background: #0f0f23; }
    </style>
</head>
<body>

<div class="header">
    <h1>🔧 OTIMIZAÇÃO & SEGURANÇA VPS</h1>
    <p>OneMundo - Clique nos botões para executar cada ação</p>
</div>

<?php if ($results): ?>
<div class="section">
    <h2>📋 Resultado: <?= htmlspecialchars($action) ?></h2>
    <div class="result">
        <?php foreach ($results as $r): ?>
            <div><?= $r ?></div>
        <?php endforeach; ?>
    </div>
    <a href="?" class="btn">← Voltar</a>
</div>
<?php endif; ?>

<div class="grid">

<!-- SEGURANÇA -->
<div class="section">
    <h2>🔒 SEGURANÇA (CRÍTICO)</h2>
    
    <p><span class="status-error">●</span> <strong>Firewall UFW:</strong> Desativado!</p>
    <a href="?action=firewall" class="btn btn-danger" onclick="return confirm('Ativar firewall UFW?')">🔥 ATIVAR FIREWALL</a>
    
    <p><span class="status-error">●</span> <strong>MySQL:</strong> Aberto para internet (porta 3306)</p>
    <a href="?action=mysql_secure" class="btn btn-danger" onclick="return confirm('Fechar MySQL para só localhost?')">🔐 FECHAR MYSQL</a>
    
    <p><span class="status-warn">●</span> <strong>SSL:</strong> Sem certificado Let's Encrypt</p>
    <a href="?action=ssl" class="btn btn-warning" onclick="return confirm('Instalar SSL Let\\'s Encrypt?')">🔒 INSTALAR SSL</a>
</div>

<!-- PERFORMANCE -->
<div class="section">
    <h2>⚡ PERFORMANCE</h2>
    
    <p><span class="status-warn">●</span> <strong>OPcache:</strong> Não carregado</p>
    <a href="?action=opcache" class="btn btn-warning">🚀 ATIVAR OPCACHE</a>
    
    <p><span class="status-warn">●</span> <strong>Swap:</strong> 0B (sem swap)</p>
    <a href="?action=swap" class="btn btn-warning">💾 CRIAR SWAP 4GB</a>
</div>

<!-- BANCO DE DADOS -->
<div class="section">
    <h2>🗄️ BANCO DE DADOS</h2>
    
    <p><strong>Tamanho:</strong> 16.6 GB</p>
    <a href="?action=check_tables" class="btn">📊 VER MAIORES TABELAS</a>
    
    <p><span class="status-warn">●</span> <strong>Coluna embedding:</strong> Erro detectado</p>
    <a href="?action=fix_embedding" class="btn btn-warning">🔧 VERIFICAR/CRIAR COLUNA</a>
</div>

<!-- MANUTENÇÃO -->
<div class="section">
    <h2>🧹 MANUTENÇÃO</h2>
    
    <p><strong>Crons:</strong> Só 1 ativo (certbot)</p>
    <a href="?action=crons" class="btn">📅 VER CRONS RECOMENDADOS</a>
    
    <p><strong>Arquivos:</strong> Muitos instaladores na raiz</p>
    <a href="?action=cleanup" class="btn">🗂️ LISTAR PARA LIMPEZA</a>
</div>

</div>

<!-- STATUS ATUAL -->
<div class="section">
    <h2>📊 STATUS ATUAL DO VPS</h2>
    <table>
        <tr><th>Item</th><th>Valor</th><th>Status</th></tr>
        <tr><td>RAM</td><td>31GB (5GB usado)</td><td class="status-ok">✅ Excelente</td></tr>
        <tr><td>CPU</td><td>8 cores (load 0.14)</td><td class="status-ok">✅ Excelente</td></tr>
        <tr><td>Disco</td><td>387GB (19% usado)</td><td class="status-ok">✅ Ótimo</td></tr>
        <tr><td>MySQL Buffer</td><td>16GB</td><td class="status-ok">✅ Ótimo</td></tr>
        <tr><td>Nginx</td><td>8 workers</td><td class="status-ok">✅ Rodando</td></tr>
        <tr><td>Redis</td><td>Localhost</td><td class="status-ok">✅ Rodando</td></tr>
        <tr><td>PHP-FPM</td><td>8.3.6</td><td class="status-ok">✅ Rodando</td></tr>
        <tr><td>VoiceID</td><td>Porta 5000</td><td class="status-ok">✅ Rodando</td></tr>
        <tr><td>Firewall</td><td>Desativado</td><td class="status-error">🚨 CRÍTICO</td></tr>
        <tr><td>MySQL Porta</td><td>Aberta pro mundo</td><td class="status-error">🚨 CRÍTICO</td></tr>
        <tr><td>SSL</td><td>Não configurado</td><td class="status-warn">⚠️ Atenção</td></tr>
        <tr><td>OPcache</td><td>Desativado</td><td class="status-warn">⚠️ Atenção</td></tr>
        <tr><td>Swap</td><td>0B</td><td class="status-warn">⚠️ Atenção</td></tr>
    </table>
</div>

<div class="header">
    <p style="color: #ff4444;">⚠️ APAGUE ESTE ARQUIVO DEPOIS DE USAR!</p>
    <p>Ordem recomendada: 1) Firewall → 2) MySQL Secure → 3) SSL → 4) OPcache → 5) Swap</p>
</div>

</body>
</html>
