<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🧠 ONEMUNDO - MOTOR DE ALERTAS INTELIGENTES COM IA
 * Dashboard + Motor + APIs v1.0
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('America/Sao_Paulo');

$isCron = (php_sapi_name() === 'cli');
$isApi = isset($_GET['api']);

$conn = getMySQLi();
if ($conn->connect_error) die($isCron ? "ERRO DB\n" : json_encode(['error' => 'DB Error']));
$conn->set_charset('utf8mb4');

// ════════════════════════════════════════════════════════════════════════════
// CLASSE MOTOR DE ALERTAS
// ════════════════════════════════════════════════════════════════════════════

class MotorAlertas {
    private $conn, $config = [], $stats = ['checks'=>0,'created'=>0,'updated'=>0,'resolved'=>0,'anomalias'=>0];
    
    public function __construct($conn) {
        $this->conn = $conn;
        $r = $conn->query("SELECT config_key, config_value, config_type FROM om_market_alerts_config");
        if ($r) while ($row = $r->fetch_assoc()) {
            $v = $row['config_value'];
            if ($row['config_type'] == 'int') $v = (int)$v;
            elseif ($row['config_type'] == 'bool') $v = ($v === '1');
            $this->config[$row['config_key']] = $v;
        }
    }
    
    public function cfg($k, $d = null) { return $this->config[$k] ?? $d; }
    
    public function executar() {
        $inicio = microtime(true);
        $this->conn->query("INSERT INTO om_market_alerts_engine_log (run_type, started_at) VALUES ('cron', NOW())");
        $logId = $this->conn->insert_id;
        
        $h = $this->cfg('auto_resolve_hours', 24);
        $this->conn->query("UPDATE om_market_alerts SET status='auto_resolvido', resolved_at=NOW() WHERE status='aberto' AND created_at < DATE_SUB(NOW(), INTERVAL $h HOUR)");
        $this->stats['resolved'] = $this->conn->affected_rows;
        
        $r = $this->conn->query("SELECT * FROM om_market_alerts_rules WHERE is_active=1 ORDER BY priority DESC");
        while ($r && $regra = $r->fetch_assoc()) {
            $regra['parameters'] = json_decode($regra['parameters'], true) ?? [];
            $this->stats['checks']++;
            $this->executarRegra($regra);
        }
        
        if ($this->cfg('ai_enabled', true)) $this->detectarAnomaliasIA();
        $this->atualizarHealthScores();
        
        $d = round(microtime(true) - $inicio, 3);
        $s = json_encode($this->stats);
        $this->conn->query("UPDATE om_market_alerts_engine_log SET finished_at=NOW(), duration_seconds=$d, checks_executed={$this->stats['checks']}, alerts_created={$this->stats['created']}, alerts_resolved={$this->stats['resolved']}, summary='$s' WHERE log_id=$logId");
        
        return $this->stats;
    }
    
    private function executarRegra($regra) {
        $sql = "SELECT COUNT(*) as c FROM om_market_alerts WHERE alert_code='{$regra['rule_code']}' AND created_at > DATE_SUB(NOW(), INTERVAL {$regra['cooldown_minutes']} MINUTE) AND status NOT IN ('resolvido','falso_positivo','auto_resolvido')";
        $r = $this->conn->query($sql);
        if ($r && $r->fetch_assoc()['c'] > 0) return;
        
        switch ($regra['rule_code']) {
            case 'MERCADO_FECHADO_ACEITANDO': $this->checkMercadoFechado($regra); break;
            case 'MERCADO_SEM_LOGIN': $this->checkMercadoSemLogin($regra); break;
            case 'MERCADO_SEM_ATUALIZAR_PRECO': $this->checkMercadoSemAtualizarPreco($regra); break;
            case 'DELIVERY_NENHUM_ONLINE': $this->checkDeliveryNenhumOnline($regra); break;
            case 'PRECO_MUITO_BAIXO': $this->checkPrecoMuitoBaixo($regra); break;
            case 'PRECO_ERRO_CADASTRO': $this->checkPrecoErroCadastro($regra); break;
            case 'ESTOQUE_ESSENCIAL_ZERADO': $this->checkEstoqueEssencial($regra); break;
            case 'PEDIDO_ATRASADO': $this->checkPedidoAtrasado($regra); break;
        }
    }
    
    private function checkMercadoFechado($regra) {
        $hora = date('H:i:s'); $hoje = date('Y-m-d');
        $sql = "SELECT p.*, (SELECT COUNT(*) FROM om_market_orders o WHERE o.partner_id=p.partner_id AND o.status='pending') as pendentes FROM om_market_partners p WHERE p.status=1";
        $r = $this->conn->query($sql);
        while ($r && $m = $r->fetch_assoc()) {
            $aberto = true; $motivo = '';
            $ab = $m['opening_time'] ?? '08:00'; $fe = $m['closing_time'] ?? '22:00';
            if ($hora < $ab || $hora > $fe) { $aberto = false; $motivo = "Fora do horário ($ab-$fe)"; }
            if ($aberto) {
                $rf = $this->conn->query("SELECT holiday_name FROM om_market_holidays WHERE holiday_date='$hoje' AND affects_commerce=1 AND (scope='nacional' OR (scope='estadual' AND state='{$m['state']}'))");
                if ($rf && $f = $rf->fetch_assoc()) { $aberto = false; $motivo = "Feriado: {$f['holiday_name']}"; }
            }
            if (!$aberto && $m['pendentes'] > 0) {
                $this->criarAlerta(['alert_code'=>$regra['rule_code'],'category'=>'mercado','severity'=>'critico','partner_id'=>$m['partner_id'],'region'=>"{$m['city']}, {$m['state']}",'title'=>'Mercado fechado aceitando pedidos','message'=>"Mercado {$m['trade_name']} fechado ({$motivo}) com {$m['pendentes']} pedido(s) pendente(s).",'risk_score'=>95,'notify_email'=>1]);
            }
        }
    }
    
    private function checkMercadoSemLogin($regra) {
        $dias = $regra['parameters']['days_without_login'] ?? 7;
        $r = $this->conn->query("SELECT * FROM om_market_partners WHERE status=1 AND (last_login IS NULL OR last_login < DATE_SUB(NOW(), INTERVAL $dias DAY))");
        while ($r && $m = $r->fetch_assoc()) {
            $d = $m['last_login'] ? floor((time()-strtotime($m['last_login']))/86400) : 999;
            $this->criarAlerta(['alert_code'=>$regra['rule_code'],'category'=>'mercado','severity'=>$d>14?'alto':'medio','partner_id'=>$m['partner_id'],'region'=>"{$m['city']}, {$m['state']}",'title'=>'Mercado sem acessar painel','message'=>"Mercado {$m['trade_name']} não acessa o painel há {$d} dias.",'risk_score'=>min(100,50+$d*3)]);
        }
    }
    
    private function checkMercadoSemAtualizarPreco($regra) {
        $h = $regra['parameters']['hours_without_update'] ?? 72;
        $r = $this->conn->query("SELECT * FROM om_market_partners WHERE status=1 AND (last_price_update IS NULL OR last_price_update < DATE_SUB(NOW(), INTERVAL $h HOUR))");
        while ($r && $m = $r->fetch_assoc()) {
            $hrs = $m['last_price_update'] ? floor((time()-strtotime($m['last_price_update']))/3600) : 999;
            $this->criarAlerta(['alert_code'=>$regra['rule_code'],'category'=>'mercado','severity'=>'alto','partner_id'=>$m['partner_id'],'region'=>"{$m['city']}, {$m['state']}",'title'=>'Mercado sem atualizar preços','message'=>"Mercado {$m['trade_name']} não atualiza preços há {$hrs}h.",'risk_score'=>min(100,60+$hrs/24*5)]);
        }
    }
    
    private function checkDeliveryNenhumOnline($regra) {
        $sql = "SELECT DISTINCT CONCAT(city,', ',state) as region, city, (SELECT COUNT(*) FROM om_market_deliveries d WHERE d.is_online=1 AND d.current_region LIKE CONCAT('%',p.city,'%')) as online, (SELECT COUNT(*) FROM om_market_orders o WHERE o.status='pending' AND o.partner_id IN (SELECT partner_id FROM om_market_partners WHERE city=p.city)) as pendentes FROM om_market_partners p WHERE p.status=1";
        $r = $this->conn->query($sql);
        while ($r && $reg = $r->fetch_assoc()) {
            if ($reg['online'] == 0 && $reg['pendentes'] > 0) {
                $this->criarAlerta(['alert_code'=>$regra['rule_code'],'category'=>'delivery','severity'=>'critico','region'=>$reg['region'],'title'=>'Nenhum entregador online','message'=>"Região {$reg['region']} com {$reg['pendentes']} pedido(s) e ZERO entregadores!",'risk_score'=>100,'notify_email'=>1,'notify_whatsapp'=>1]);
            }
        }
    }
    
    private function checkPrecoMuitoBaixo($regra) {
        $th = $regra['parameters']['threshold_percent_below'] ?? 30;
        $sql = "SELECT pp.*, p.trade_name, pb.name as produto, pb.price_reference FROM om_market_products_price pp JOIN om_market_partners p ON p.partner_id=pp.partner_id JOIN om_market_products_base pb ON pb.product_id=pp.product_id WHERE pp.price>0 AND pb.price_reference>0 AND pp.price < pb.price_reference*(1-$th/100) LIMIT 10";
        $r = $this->conn->query($sql);
        while ($r && $prod = $r->fetch_assoc()) {
            $diff = round((1-$prod['price']/$prod['price_reference'])*100,1);
            $this->criarAlerta(['alert_code'=>$regra['rule_code'],'category'=>'preco','severity'=>$diff>50?'critico':'alto','partner_id'=>$prod['partner_id'],'product_id'=>$prod['product_id'],'title'=>'Preço muito baixo','message'=>"'{$prod['produto']}' em {$prod['trade_name']} está {$diff}% abaixo. R$".number_format($prod['price'],2,',','.')." vs R$".number_format($prod['price_reference'],2,',','.'),'risk_score'=>min(100,70+$diff),'notify_email'=>1]);
        }
    }
    
    private function checkPrecoErroCadastro($regra) {
        $min = $regra['parameters']['min_price'] ?? 0.50; $max = $regra['parameters']['max_price'] ?? 5000;
        $sql = "SELECT pp.*, p.trade_name, pb.name as produto FROM om_market_products_price pp JOIN om_market_partners p ON p.partner_id=pp.partner_id JOIN om_market_products_base pb ON pb.product_id=pp.product_id WHERE pp.price>0 AND (pp.price<$min OR pp.price>$max) LIMIT 10";
        $r = $this->conn->query($sql);
        while ($r && $prod = $r->fetch_assoc()) {
            $tipo = $prod['price']<$min ? 'muito baixo' : 'muito alto';
            $this->criarAlerta(['alert_code'=>$regra['rule_code'],'category'=>'preco','severity'=>'critico','partner_id'=>$prod['partner_id'],'product_id'=>$prod['product_id'],'title'=>'Erro de cadastro','message'=>"'{$prod['produto']}' com preço {$tipo}: R$".number_format($prod['price'],2,',','.'),'risk_score'=>95,'notify_email'=>1]);
        }
    }
    
    private function checkEstoqueEssencial($regra) {
        $sql = "SELECT pp.*, p.trade_name, pb.name as produto, ep.priority FROM om_market_products_price pp JOIN om_market_partners p ON p.partner_id=pp.partner_id JOIN om_market_products_base pb ON pb.product_id=pp.product_id JOIN om_market_essential_products ep ON pb.name LIKE ep.product_name_pattern WHERE (pp.stock_quantity=0 OR pp.stock_quantity IS NULL) LIMIT 20";
        $r = $this->conn->query($sql);
        while ($r && $prod = $r->fetch_assoc()) {
            $sev = $prod['priority']=='critica' ? 'critico' : ($prod['priority']=='alta' ? 'alto' : 'medio');
            $this->criarAlerta(['alert_code'=>$regra['rule_code'],'category'=>'estoque','severity'=>$sev,'partner_id'=>$prod['partner_id'],'product_id'=>$prod['product_id'],'title'=>'Produto essencial sem estoque','message'=>"'{$prod['produto']}' sem estoque em {$prod['trade_name']}.",'risk_score'=>$prod['priority']=='critica'?90:70]);
        }
    }
    
    private function checkPedidoAtrasado($regra) {
        $min = $regra['parameters']['delay_minutes_warning'] ?? 15;
        $sql = "SELECT o.*, p.trade_name FROM om_market_orders o JOIN om_market_partners p ON p.partner_id=o.partner_id WHERE o.status IN ('pending','processing') AND o.estimated_delivery IS NOT NULL AND o.estimated_delivery < NOW() AND TIMESTAMPDIFF(MINUTE, o.estimated_delivery, NOW()) >= $min";
        $r = $this->conn->query($sql);
        while ($r && $ped = $r->fetch_assoc()) {
            $atraso = floor((time()-strtotime($ped['estimated_delivery']))/60);
            $this->criarAlerta(['alert_code'=>$regra['rule_code'],'category'=>'pedido','severity'=>$atraso>30?'critico':'alto','partner_id'=>$ped['partner_id'],'order_id'=>$ped['order_id'],'title'=>'Pedido atrasado','message'=>"Pedido #{$ped['order_id']} ({$ped['trade_name']}) atrasado {$atraso}min.",'risk_score'=>min(100,70+$atraso)]);
        }
    }
    
    private function detectarAnomaliasIA() {
        $sql = "SELECT p.partner_id, p.trade_name, p.city, p.state, p.avg_daily_orders, (SELECT COUNT(*) FROM om_market_orders o WHERE o.partner_id=p.partner_id AND DATE(o.date_added)=CURRENT_DATE) as hoje FROM om_market_partners p WHERE p.status=1 AND p.avg_daily_orders>0";
        $r = $this->conn->query($sql);
        while ($r && $m = $r->fetch_assoc()) {
            $h = (int)date('H'); $esperado = ($m['avg_daily_orders']/14)*$h;
            if ($esperado >= 2 && $m['hoje'] < $esperado * 0.3) {
                $this->criarAlerta(['alert_code'=>'IA_ANOMALIA_VENDAS','category'=>'ia_anomalia','severity'=>'medio','partner_id'=>$m['partner_id'],'region'=>"{$m['city']}, {$m['state']}",'title'=>'IA: Vendas abaixo do padrão','message'=>"Mercado {$m['trade_name']} tem {$m['hoje']} pedidos (esperado ~".round($esperado).").",'ai_analysis'=>"Desvio detectado. Média: {$m['avg_daily_orders']}/dia.",'risk_score'=>70]);
                $this->stats['anomalias']++;
            }
        }
    }
    
    private function atualizarHealthScores() {
        $sql = "SELECT p.partner_id, (SELECT COUNT(*) FROM om_market_alerts a WHERE a.partner_id=p.partner_id AND a.status='aberto' AND a.severity='critico') as crit, (SELECT COUNT(*) FROM om_market_alerts a WHERE a.partner_id=p.partner_id AND a.status='aberto' AND a.severity='alto') as alto FROM om_market_partners p WHERE p.status=1";
        $r = $this->conn->query($sql);
        while ($r && $m = $r->fetch_assoc()) {
            $score = max(0, min(100, 100 - $m['crit']*15 - $m['alto']*8));
            $risk = $score<40?'critico':($score<60?'alto':($score<80?'medio':'baixo'));
            $this->conn->query("UPDATE om_market_partners SET health_score=$score, risk_level='$risk' WHERE partner_id={$m['partner_id']}");
        }
    }
    
    private function criarAlerta($data) {
        $hash = md5($data['alert_code'].($data['partner_id']??'').($data['product_id']??'').($data['order_id']??'').($data['region']??''));
        $r = $this->conn->query("SELECT alert_id, group_count FROM om_market_alerts WHERE alert_hash='$hash' AND status='aberto'");
        if ($r && $ex = $r->fetch_assoc()) {
            $this->conn->query("UPDATE om_market_alerts SET group_count=group_count+1, updated_at=NOW() WHERE alert_id={$ex['alert_id']}");
            $this->stats['updated']++; return $ex['alert_id'];
        }
        $ai = isset($data['ai_analysis']) ? "'".$this->conn->real_escape_string($data['ai_analysis'])."'" : 'NULL';
        $sql = "INSERT INTO om_market_alerts (alert_code, alert_hash, category, severity, risk_score, partner_id, product_id, order_id, region, title, message, ai_analysis, notify_email, notify_whatsapp) VALUES ('{$data['alert_code']}', '$hash', '{$data['category']}', '{$data['severity']}', ".($data['risk_score']??50).", ".($data['partner_id']??'NULL').", ".($data['product_id']??'NULL').", ".($data['order_id']??'NULL').", ".($data['region']?"'".$data['region']."'":'NULL').", '".$this->conn->real_escape_string($data['title'])."', '".$this->conn->real_escape_string($data['message'])."', $ai, ".($data['notify_email']??0).", ".($data['notify_whatsapp']??0).")";
        if ($this->conn->query($sql)) { $this->stats['created']++; return $this->conn->insert_id; }
        return false;
    }
}

// ════════════════════════════════════════════════════════════════════════════
// MODO CRON
// ════════════════════════════════════════════════════════════════════════════
if ($isCron) { echo json_encode((new MotorAlertas($conn))->executar())."\n"; exit; }

// ════════════════════════════════════════════════════════════════════════════
// MODO API
// ════════════════════════════════════════════════════════════════════════════
if ($isApi) {
    header('Content-Type: application/json');
    $api = $_GET['api'];
    
    if ($api === 'dashboard') {
        $data = [];
        $r = $conn->query("SELECT severity, COUNT(*) as c FROM om_market_alerts WHERE status='aberto' GROUP BY severity");
        $data['severidade'] = ['critico'=>0,'alto'=>0,'medio'=>0,'info'=>0];
        while ($r && $row = $r->fetch_assoc()) $data['severidade'][$row['severity']] = (int)$row['c'];
        $r = $conn->query("SELECT category, COUNT(*) as c FROM om_market_alerts WHERE status='aberto' GROUP BY category");
        $data['categoria'] = []; while ($r && $row = $r->fetch_assoc()) $data['categoria'][$row['category']] = (int)$row['c'];
        $r = $conn->query("SELECT COUNT(*) as c FROM om_market_alerts WHERE status='aberto'"); $data['total_abertos'] = $r->fetch_assoc()['c'];
        $r = $conn->query("SELECT p.partner_id, p.trade_name, p.health_score, COUNT(a.alert_id) as alertas FROM om_market_partners p LEFT JOIN om_market_alerts a ON a.partner_id=p.partner_id AND a.status='aberto' WHERE p.status=1 GROUP BY p.partner_id ORDER BY alertas DESC LIMIT 5");
        $data['top_problemas'] = []; while ($r && $row = $r->fetch_assoc()) $data['top_problemas'][] = $row;
        echo json_encode(['success'=>true,'data'=>$data]); exit;
    }
    
    if ($api === 'alertas') {
        $where = ["1=1"];
        if (!empty($_GET['status'])) $where[] = "a.status='".$conn->real_escape_string($_GET['status'])."'";
        if (!empty($_GET['severity'])) $where[] = "a.severity='".$conn->real_escape_string($_GET['severity'])."'";
        if (!empty($_GET['category'])) $where[] = "a.category='".$conn->real_escape_string($_GET['category'])."'";
        if (!empty($_GET['search'])) $where[] = "(a.title LIKE '%".$conn->real_escape_string($_GET['search'])."%' OR a.message LIKE '%".$conn->real_escape_string($_GET['search'])."%')";
        $sql = "SELECT a.*, p.trade_name FROM om_market_alerts a LEFT JOIN om_market_partners p ON p.partner_id=a.partner_id WHERE ".implode(' AND ', $where)." ORDER BY FIELD(a.severity,'critico','alto','medio','info'), a.created_at DESC LIMIT 100";
        $r = $conn->query($sql); $alertas = []; while ($r && $row = $r->fetch_assoc()) $alertas[] = $row;
        echo json_encode(['success'=>true,'alertas'=>$alertas]); exit;
    }
    
    if ($api === 'resolver') { $id = (int)($_POST['alert_id']??0); if ($id) { $conn->query("UPDATE om_market_alerts SET status='resolvido', resolved_at=NOW() WHERE alert_id=$id"); echo json_encode(['success'=>true]); } else echo json_encode(['success'=>false]); exit; }
    if ($api === 'silenciar') { $id = (int)($_POST['alert_id']??0); $h = (int)($_POST['horas']??24); if ($id) { $conn->query("UPDATE om_market_alerts SET status='silenciado', silenced_until=DATE_ADD(NOW(), INTERVAL $h HOUR) WHERE alert_id=$id"); echo json_encode(['success'=>true]); } else echo json_encode(['success'=>false]); exit; }
    if ($api === 'falso_positivo') { $id = (int)($_POST['alert_id']??0); if ($id) { $conn->query("UPDATE om_market_alerts SET status='falso_positivo', is_false_positive=1 WHERE alert_id=$id"); echo json_encode(['success'=>true]); } else echo json_encode(['success'=>false]); exit; }
    if ($api === 'executar_motor') { echo json_encode(['success'=>true,'stats'=>(new MotorAlertas($conn))->executar()]); exit; }
    if ($api === 'regras') { $r = $conn->query("SELECT * FROM om_market_alerts_rules ORDER BY priority DESC"); $regras = []; while ($r && $row = $r->fetch_assoc()) $regras[] = $row; echo json_encode(['success'=>true,'regras'=>$regras]); exit; }
    if ($api === 'toggle_regra') { $id = (int)($_POST['rule_id']??0); if ($id) { $conn->query("UPDATE om_market_alerts_rules SET is_active=1-is_active WHERE rule_id=$id"); echo json_encode(['success'=>true]); } else echo json_encode(['success'=>false]); exit; }
    echo json_encode(['success'=>false,'error'=>'API não encontrada']); exit;
}

// ════════════════════════════════════════════════════════════════════════════
// DASHBOARD HTML
// ════════════════════════════════════════════════════════════════════════════
?>

<!DOCTYPE html>

<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🧠 Central de Alertas - OneMundo</title>
<style>
:root{--bg:#0a0e1a;--card:#111827;--border:rgba(255,255,255,0.08);--primary:#10b981;--red:#ef4444;--orange:#f59e0b;--blue:#3b82f6;--text:#f9fafb;--muted:#6b7280}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:var(--card);border-bottom:1px solid var(--border);padding:16px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:100}
.logo{font-size:20px;font-weight:800}.logo span{color:var(--primary)}
.header-actions{display:flex;gap:12px}
.btn{padding:10px 20px;border-radius:10px;font-weight:600;cursor:pointer;border:none;font-size:14px;transition:transform .2s}
.btn-primary{background:linear-gradient(135deg,var(--primary),#059669);color:#fff}
.btn-secondary{background:rgba(255,255,255,0.1);color:var(--text);border:1px solid var(--border)}
.btn:hover{transform:translateY(-2px)}
.container{max-width:1400px;margin:0 auto;padding:24px}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;text-align:center}
.stat-card.critico{border-left:4px solid var(--red)}.stat-card.alto{border-left:4px solid var(--orange)}.stat-card.medio{border-left:4px solid var(--blue)}.stat-card.info{border-left:4px solid var(--muted)}
.stat-value{font-size:36px;font-weight:800;margin-bottom:5px}.stat-label{color:var(--muted);font-size:13px}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;margin-bottom:20px;overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card-title{font-weight:700;font-size:16px}
.filters{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.filter-select,.search-input{padding:10px 16px;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:14px}
.search-input{flex:1;min-width:200px}
.alert-list{display:flex;flex-direction:column;gap:12px}
.alert-item{background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:12px;padding:16px;display:flex;gap:16px;align-items:flex-start;transition:background .2s}
.alert-item:hover{background:rgba(255,255,255,0.05)}
.alert-item.critico{border-left:4px solid var(--red)}.alert-item.alto{border-left:4px solid var(--orange)}.alert-item.medio{border-left:4px solid var(--blue)}.alert-item.info{border-left:4px solid var(--muted)}
.alert-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.alert-icon.critico{background:rgba(239,68,68,0.2)}.alert-icon.alto{background:rgba(245,158,11,0.2)}.alert-icon.medio{background:rgba(59,130,246,0.2)}.alert-icon.info{background:rgba(107,114,128,0.2)}
.alert-content{flex:1;min-width:0}.alert-title{font-weight:600;margin-bottom:4px}.alert-message{color:var(--muted);font-size:13px;line-height:1.5}
.alert-meta{display:flex;gap:16px;margin-top:8px;font-size:12px;color:var(--muted);flex-wrap:wrap}
.alert-actions{display:flex;gap:8px;flex-shrink:0}
.btn-sm{padding:8px 14px;font-size:12px;border-radius:8px}
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-critico{background:rgba(239,68,68,0.2);color:var(--red)}.badge-alto{background:rgba(245,158,11,0.2);color:var(--orange)}.badge-medio{background:rgba(59,130,246,0.2);color:var(--blue)}.badge-info{background:rgba(107,114,128,0.2);color:var(--muted)}
.tabs{display:flex;gap:4px;padding:4px;background:rgba(255,255,255,0.05);border-radius:12px;margin-bottom:20px}
.tab{padding:10px 20px;border-radius:10px;cursor:pointer;font-weight:500;transition:background .2s}.tab.active{background:var(--primary);color:#fff}.tab:not(.active):hover{background:rgba(255,255,255,0.1)}
.empty-state{text-align:center;padding:60px 20px;color:var(--muted)}.empty-state-icon{font-size:48px;margin-bottom:16px}
.toast{position:fixed;bottom:24px;right:24px;background:var(--card);border:1px solid var(--border);padding:16px 24px;border-radius:12px;display:none;z-index:1000}.toast.show{display:flex;align-items:center;gap:12px}
.loading{display:inline-block;width:20px;height:20px;border:2px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:768px){.header{flex-direction:column;gap:12px}.filters{flex-direction:column}.alert-item{flex-direction:column}.alert-actions{width:100%;justify-content:flex-end}}
</style>

<!-- HEADER PREMIUM v3.0 -->
<style>

/* ══════════════════════════════════════════════════════════════════════════════
   🎨 HEADER PREMIUM v3.0 - OneMundo Mercado
   ══════════════════════════════════════════════════════════════════════════════ */

/* Variáveis do Header */
:root {
    --header-bg: rgba(255, 255, 255, 0.92);
    --header-bg-scrolled: rgba(255, 255, 255, 0.98);
    --header-blur: 20px;
    --header-shadow: 0 4px 30px rgba(0, 0, 0, 0.08);
    --header-border: rgba(0, 0, 0, 0.04);
    --header-height: 72px;
    --header-height-mobile: 64px;
}

/* Header Principal */
.header, .site-header, [class*="header-main"] {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 1000 !important;
    background: var(--header-bg) !important;
    backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    -webkit-backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    border-bottom: 1px solid var(--header-border) !important;
    box-shadow: none !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    height: auto !important;
    min-height: var(--header-height) !important;
}

.header.scrolled, .site-header.scrolled {
    background: var(--header-bg-scrolled) !important;
    box-shadow: var(--header-shadow) !important;
}

/* Container do Header */
.header-inner, .header-content, .header > div:first-child {
    max-width: 1400px !important;
    margin: 0 auto !important;
    padding: 12px 24px !important;
    display: flex !important;
    align-items: center !important;
    gap: 20px !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOCALIZAÇÃO - Estilo Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.location-btn, .endereco, [class*="location"], [class*="endereco"], [class*="address"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 10px 18px !important;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(16, 185, 129, 0.04)) !important;
    border: 1px solid rgba(16, 185, 129, 0.15) !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    min-width: 200px !important;
    max-width: 320px !important;
}

.location-btn:hover, .endereco:hover, [class*="location"]:hover {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.06)) !important;
    border-color: rgba(16, 185, 129, 0.25) !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15) !important;
}

/* Ícone de localização */
.location-btn svg, .location-btn i, [class*="location"] svg {
    width: 22px !important;
    height: 22px !important;
    color: #10b981 !important;
    flex-shrink: 0 !important;
}

/* Texto da localização */
.location-text, .endereco-text {
    flex: 1 !important;
    min-width: 0 !important;
}

.location-label, .entregar-em {
    font-size: 11px !important;
    font-weight: 500 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin-bottom: 2px !important;
}

.location-address, .endereco-rua {
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* Seta da localização */
.location-arrow, .location-btn > svg:last-child {
    width: 16px !important;
    height: 16px !important;
    color: #94a3b8 !important;
    transition: transform 0.2s ease !important;
}

.location-btn:hover .location-arrow {
    transform: translateX(3px) !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   TEMPO DE ENTREGA - Badge Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.delivery-time, .tempo-entrega, [class*="delivery-time"], [class*="tempo"] {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 10px 16px !important;
    background: linear-gradient(135deg, #0f172a, #1e293b) !important;
    border-radius: 12px !important;
    color: white !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2) !important;
    transition: all 0.3s ease !important;
}

.delivery-time:hover, .tempo-entrega:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.25) !important;
}

.delivery-time svg, .tempo-entrega svg, .delivery-time i {
    width: 18px !important;
    height: 18px !important;
    color: #10b981 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOGO - Design Moderno
   ══════════════════════════════════════════════════════════════════════════════ */

.logo, .site-logo, [class*="logo"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    text-decoration: none !important;
    transition: transform 0.3s ease !important;
}

.logo:hover {
    transform: scale(1.02) !important;
}

.logo-icon, .logo img, .logo svg {
    width: 48px !important;
    height: 48px !important;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
    border-radius: 14px !important;
    padding: 10px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.logo:hover .logo-icon, .logo:hover img {
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4) !important;
    transform: rotate(-3deg) !important;
}

.logo-text, .logo span, .site-title {
    font-size: 1.5rem !important;
    font-weight: 800 !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
    background-clip: text !important;
    letter-spacing: -0.02em !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   BUSCA - Search Bar Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.search-container, .search-box, [class*="search"], .busca {
    flex: 1 !important;
    max-width: 600px !important;
    position: relative !important;
}

.search-input, input[type="search"], input[name*="search"], input[name*="busca"], .busca input {
    width: 100% !important;
    padding: 14px 20px 14px 52px !important;
    background: #f1f5f9 !important;
    border: 2px solid transparent !important;
    border-radius: 16px !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
}

.search-input:hover, input[type="search"]:hover {
    background: #e2e8f0 !important;
}

.search-input:focus, input[type="search"]:focus {
    background: #ffffff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12), inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
    outline: none !important;
}

.search-input::placeholder {
    color: #94a3b8 !important;
    font-weight: 400 !important;
}

/* Ícone da busca */
.search-icon, .search-container svg, .busca svg {
    position: absolute !important;
    left: 18px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 22px !important;
    height: 22px !important;
    color: #94a3b8 !important;
    pointer-events: none !important;
    transition: color 0.3s ease !important;
}

.search-input:focus + .search-icon,
.search-container:focus-within svg {
    color: #10b981 !important;
}

/* Botão de busca por voz (opcional) */
.search-voice-btn {
    position: absolute !important;
    right: 12px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 36px !important;
    height: 36px !important;
    background: transparent !important;
    border: none !important;
    border-radius: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.search-voice-btn:hover {
    background: rgba(16, 185, 129, 0.1) !important;
}

.search-voice-btn svg {
    width: 20px !important;
    height: 20px !important;
    color: #64748b !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   CARRINHO - Cart Button Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.cart-btn, .carrinho-btn, [class*="cart"], [class*="carrinho"], a[href*="cart"], a[href*="carrinho"] {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 52px !important;
    height: 52px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border: none !important;
    border-radius: 16px !important;
    cursor: pointer !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
}

.cart-btn:hover, .carrinho-btn:hover, [class*="cart"]:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4) !important;
}

.cart-btn:active {
    transform: translateY(-1px) scale(0.98) !important;
}

.cart-btn svg, .carrinho-btn svg, [class*="cart"] svg {
    width: 26px !important;
    height: 26px !important;
    color: white !important;
}

/* Badge do carrinho */
.cart-badge, .carrinho-badge, [class*="cart-count"], [class*="badge"] {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 24px !important;
    height: 24px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    color: white !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 6px !important;
    border: 3px solid white !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: badge-pulse 2s ease-in-out infinite !important;
}

@keyframes badge-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

/* ══════════════════════════════════════════════════════════════════════════════
   MENU MOBILE
   ══════════════════════════════════════════════════════════════════════════════ */

.menu-btn, .hamburger, [class*="menu-toggle"] {
    display: none !important;
    width: 44px !important;
    height: 44px !important;
    background: #f1f5f9 !important;
    border: none !important;
    border-radius: 12px !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.menu-btn:hover {
    background: #e2e8f0 !important;
}

.menu-btn svg {
    width: 24px !important;
    height: 24px !important;
    color: #475569 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   RESPONSIVO
   ══════════════════════════════════════════════════════════════════════════════ */

@media (max-width: 1024px) {
    .search-container, .search-box {
        max-width: 400px !important;
    }
    
    .location-btn, .endereco {
        max-width: 250px !important;
    }
}

@media (max-width: 768px) {
    :root {
        --header-height: var(--header-height-mobile);
    }
    
    .header-inner, .header-content {
        padding: 10px 16px !important;
        gap: 12px !important;
    }
    
    /* Esconder busca no header mobile - mover para baixo */
    .search-container, .search-box, [class*="search"]:not(.search-icon) {
        position: absolute !important;
        top: 100% !important;
        left: 0 !important;
        right: 0 !important;
        max-width: 100% !important;
        padding: 12px 16px !important;
        background: white !important;
        border-top: 1px solid #e2e8f0 !important;
        display: none !important;
    }
    
    .search-container.active {
        display: block !important;
    }
    
    /* Logo menor */
    .logo-icon, .logo img {
        width: 42px !important;
        height: 42px !important;
        border-radius: 12px !important;
    }
    
    .logo-text {
        display: none !important;
    }
    
    /* Localização compacta */
    .location-btn, .endereco {
        min-width: auto !important;
        max-width: 180px !important;
        padding: 8px 12px !important;
    }
    
    .location-label, .entregar-em {
        display: none !important;
    }
    
    .location-address {
        font-size: 13px !important;
    }
    
    /* Tempo de entrega menor */
    .delivery-time, .tempo-entrega {
        padding: 8px 12px !important;
        font-size: 12px !important;
    }
    
    /* Carrinho menor */
    .cart-btn, .carrinho-btn {
        width: 46px !important;
        height: 46px !important;
        border-radius: 14px !important;
    }
    
    .cart-btn svg {
        width: 22px !important;
        height: 22px !important;
    }
    
    /* Mostrar menu button */
    .menu-btn, .hamburger {
        display: flex !important;
    }
}

@media (max-width: 480px) {
    .location-btn, .endereco {
        max-width: 140px !important;
    }
    
    .delivery-time, .tempo-entrega {
        display: none !important;
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   ANIMAÇÕES DE ENTRADA
   ══════════════════════════════════════════════════════════════════════════════ */

@keyframes headerSlideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.header, .site-header {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *, .header-content > * {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *:nth-child(1) { animation-delay: 0.05s !important; }
.header-inner > *:nth-child(2) { animation-delay: 0.1s !important; }
.header-inner > *:nth-child(3) { animation-delay: 0.15s !important; }
.header-inner > *:nth-child(4) { animation-delay: 0.2s !important; }
.header-inner > *:nth-child(5) { animation-delay: 0.25s !important; }

/* ══════════════════════════════════════════════════════════════════════════════
   AJUSTES DE BODY PARA HEADER FIXED
   ══════════════════════════════════════════════════════════════════════════════ */

body {
    padding-top: calc(var(--header-height) + 10px) !important;
}

@media (max-width: 768px) {
    body {
        padding-top: calc(var(--header-height-mobile) + 10px) !important;
    }
}

</style>
</head>
<body>
<header class="header">
    <div style="display:flex;align-items:center;gap:16px;">
        <a href="index.php" class="btn-voltar" style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:#9ca3af;text-decoration:none;font-size:18px;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.1)';this.style.color='#10b981'" onmouseout="this.style.background='rgba(255,255,255,0.05)';this.style.color='#9ca3af'">←</a>
        <div class="logo">🧠 Central de <span>Alertas</span></div>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;gap:8px;">← Dashboard</a>
        <button class="btn btn-secondary" onclick="loadAll()">🔄 Atualizar</button>
        <button class="btn btn-primary" onclick="executarMotor()">⚡ Executar Motor</button>
    </div>
</header>
<div class="container">
    <div class="stats-grid">
        <div class="stat-card critico"><div class="stat-value" id="statCritico">-</div><div class="stat-label">🔴 Críticos</div></div>
        <div class="stat-card alto"><div class="stat-value" id="statAlto">-</div><div class="stat-label">🟠 Altos</div></div>
        <div class="stat-card medio"><div class="stat-value" id="statMedio">-</div><div class="stat-label">🔵 Médios</div></div>
        <div class="stat-card info"><div class="stat-value" id="statInfo">-</div><div class="stat-label">⚪ Info</div></div>
    </div>
    <div class="tabs">
        <div class="tab active" data-tab="alertas">📋 Alertas</div>
        <div class="tab" data-tab="regras">⚙️ Regras</div>
    </div>
    <div id="tabAlertas">
        <div class="filters">
            <select class="filter-select" id="filterStatus" onchange="loadAlertas()">
                <option value="aberto">📂 Abertos</option>
                <option value="">📋 Todos</option>
                <option value="resolvido">✅ Resolvidos</option>
                <option value="silenciado">🔇 Silenciados</option>
            </select>
            <select class="filter-select" id="filterSeverity" onchange="loadAlertas()">
                <option value="">🎯 Todas severidades</option>
                <option value="critico">🔴 Crítico</option>
                <option value="alto">🟠 Alto</option>
                <option value="medio">🔵 Médio</option>
            </select>
            <select class="filter-select" id="filterCategory" onchange="loadAlertas()">
                <option value="">📁 Todas categorias</option>
                <option value="mercado">🏪 Mercado</option>
                <option value="delivery">🚴 Delivery</option>
                <option value="preco">💰 Preço</option>
                <option value="estoque">📦 Estoque</option>
                <option value="pedido">🛒 Pedido</option>
                <option value="ia_anomalia">🤖 IA</option>
            </select>
            <input type="text" class="search-input" id="searchInput" placeholder="🔍 Buscar..." onkeyup="debounceSearch()">
        </div>
        <div class="alert-list" id="alertList"><div class="empty-state"><div class="loading"></div><p style="margin-top:16px">Carregando...</p></div></div>
    </div>
    <div id="tabRegras" style="display:none"><div class="card"><div class="card-header"><span class="card-title">⚙️ Regras</span></div><div class="card-body" id="regrasList"></div></div></div>
</div>
<div class="toast" id="toast"><span id="toastIcon">✅</span><span id="toastMsg"></span></div>
<script>
const $=id=>document.getElementById(id);
let searchTimeout;
const icons={mercado:'🏪',delivery:'🚴',preco:'💰',estoque:'📦',pedido:'🛒',calendario:'📅',tecnico:'⚙️',ia_anomalia:'🤖'};
document.querySelectorAll('.tab').forEach(tab=>{tab.onclick=()=>{document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));tab.classList.add('active');$('tabAlertas').style.display=tab.dataset.tab==='alertas'?'block':'none';$('tabRegras').style.display=tab.dataset.tab==='regras'?'block':'none';if(tab.dataset.tab==='regras')loadRegras();}});
async function loadDashboard(){const r=await fetch('?api=dashboard');const d=await r.json();if(d.success){$('statCritico').textContent=d.data.severidade.critico||0;$('statAlto').textContent=d.data.severidade.alto||0;$('statMedio').textContent=d.data.severidade.medio||0;$('statInfo').textContent=d.data.severidade.info||0;}}
async function loadAlertas(){const params=new URLSearchParams({api:'alertas'});if($('filterStatus').value)params.append('status',$('filterStatus').value);if($('filterSeverity').value)params.append('severity',$('filterSeverity').value);if($('filterCategory').value)params.append('category',$('filterCategory').value);if($('searchInput').value)params.append('search',$('searchInput').value);const r=await fetch('?'+params);const d=await r.json();if(d.success&&d.alertas.length>0){$('alertList').innerHTML=d.alertas.map(a=>`<div class="alert-item ${a.severity}"><div class="alert-icon ${a.severity}">${icons[a.category]||'⚠️'}</div><div class="alert-content"><div class="alert-title">${a.title} ${a.group_count>1?`<span class="badge badge-${a.severity}">×${a.group_count}</span>`:''}</div><div class="alert-message">${a.message}</div>${a.ai_analysis?`<div class="alert-message" style="margin-top:8px;padding:8px;background:rgba(139,92,246,0.1);border-radius:8px">🤖 ${a.ai_analysis}</div>`:''}<div class="alert-meta"><span>📍 ${a.region||a.trade_name||'Sistema'}</span><span>🕐 ${new Date(a.created_at).toLocaleString('pt-BR')}</span><span class="badge badge-${a.severity}">${a.severity.toUpperCase()}</span></div></div><div class="alert-actions">${a.status==='aberto'?`<button class="btn btn-sm btn-primary" onclick="resolver(${a.alert_id})">✅</button><button class="btn btn-sm btn-secondary" onclick="silenciar(${a.alert_id})">🔇</button><button class="btn btn-sm btn-secondary" onclick="falsoPositivo(${a.alert_id})">❌</button>`:`<span class="badge">${a.status}</span>`}</div></div>`).join('');}else{$('alertList').innerHTML='<div class="empty-state"><div class="empty-state-icon">✨</div><p>Nenhum alerta</p></div>';}}
async function loadRegras(){const r=await fetch('?api=regras');const d=await r.json();if(d.success){$('regrasList').innerHTML=d.regras.map(r=>`<div class="alert-item ${r.is_active?'':'info'}" style="opacity:${r.is_active?1:0.5}"><div class="alert-icon ${r.severity_default}">${icons[r.category]||'⚙️'}</div><div class="alert-content"><div class="alert-title">${r.rule_name}</div><div class="alert-message">${r.rule_code}</div><div class="alert-meta"><span>⏱️ ${r.check_interval_minutes}min</span><span class="badge badge-${r.severity_default}">${r.severity_default}</span></div></div><div class="alert-actions"><button class="btn btn-sm ${r.is_active?'btn-secondary':'btn-primary'}" onclick="toggleRegra(${r.rule_id})">${r.is_active?'⏸️':'▶️'}</button></div></div>`).join('');}}
async function resolver(id){if(!confirm('Resolver?'))return;await fetch('?api=resolver',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`alert_id=${id}`});showToast('Resolvido!');loadAll();}
async function silenciar(id){if(!confirm('Silenciar 24h?'))return;await fetch('?api=silenciar',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`alert_id=${id}&horas=24`});showToast('Silenciado!');loadAll();}
async function falsoPositivo(id){if(!confirm('Falso positivo?'))return;await fetch('?api=falso_positivo',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`alert_id=${id}`});showToast('Marcado!');loadAll();}
async function toggleRegra(id){await fetch('?api=toggle_regra',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`rule_id=${id}`});showToast('Atualizado!');loadRegras();}
async function executarMotor(){showToast('Executando...','⚡');const r=await fetch('?api=executar_motor');const d=await r.json();if(d.success)showToast(`${d.stats.created} alertas criados!`);loadAll();}
function debounceSearch(){clearTimeout(searchTimeout);searchTimeout=setTimeout(loadAlertas,300);}
function showToast(msg,icon='✅'){$('toastMsg').textContent=msg;$('toastIcon').textContent=icon;$('toast').classList.add('show');setTimeout(()=>$('toast').classList.remove('show'),3000);}
function loadAll(){loadDashboard();loadAlertas();}
loadAll();setInterval(loadAll,30000);
</script>

<script>
// Header scroll effect
(function() {
    const header = document.querySelector('.header, .site-header, [class*="header-main"]');
    if (!header) return;
    
    let lastScroll = 0;
    let ticking = false;
    
    function updateHeader() {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
        
        // Hide/show on scroll (opcional)
        /*
        if (currentScroll > lastScroll && currentScroll > 100) {
            header.style.transform = 'translateY(-100%)';
        } else {
            header.style.transform = 'translateY(0)';
        }
        */
        
        lastScroll = currentScroll;
        ticking = false;
    }
    
    window.addEventListener('scroll', function() {
        if (!ticking) {
            requestAnimationFrame(updateHeader);
            ticking = true;
        }
    });
    
    // Cart badge animation
    window.animateCartBadge = function() {
        const badge = document.querySelector('.cart-badge, .carrinho-badge, [class*="cart-count"]');
        if (badge) {
            badge.style.transform = 'scale(1.3)';
            setTimeout(() => {
                badge.style.transform = 'scale(1)';
            }, 200);
        }
    };
    
    // Mobile search toggle
    const searchToggle = document.querySelector('.search-toggle, [class*="search-btn"]');
    const searchContainer = document.querySelector('.search-container, .search-box');
    
    if (searchToggle && searchContainer) {
        searchToggle.addEventListener('click', function() {
            searchContainer.classList.toggle('active');
        });
    }
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     🎨 ONEMUNDO HEADER PREMIUM v3.0 - CSS FINAL UNIFICADO
═══════════════════════════════════════════════════════════════════════════════ -->
<style id="om-header-final">
/* RESET */
.mkt-header, .mkt-header-row, .mkt-logo, .mkt-logo-box, .mkt-logo-text,
.mkt-user, .mkt-user-avatar, .mkt-guest, .mkt-cart, .mkt-cart-count, .mkt-search,
.om-topbar, .om-topbar-main, .om-topbar-icon, .om-topbar-content,
.om-topbar-label, .om-topbar-address, .om-topbar-arrow, .om-topbar-time {
    all: revert;
}

/* TOPBAR VERDE */
.om-topbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 14px 20px !important;
    background: linear-gradient(135deg, #047857 0%, #059669 40%, #10b981 100%) !important;
    color: #fff !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    position: relative !important;
    overflow: hidden !important;
}

.om-topbar::before {
    content: '' !important;
    position: absolute !important;
    top: 0 !important;
    left: -100% !important;
    width: 100% !important;
    height: 100% !important;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent) !important;
    transition: left 0.6s ease !important;
}

.om-topbar:hover::before { left: 100% !important; }
.om-topbar:hover { background: linear-gradient(135deg, #065f46 0%, #047857 40%, #059669 100%) !important; }

.om-topbar-main {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex: 1 !important;
    min-width: 0 !important;
}

.om-topbar-icon {
    width: 40px !important;
    height: 40px !important;
    background: rgba(255,255,255,0.18) !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar:hover .om-topbar-icon {
    background: rgba(255,255,255,0.25) !important;
    transform: scale(1.05) !important;
}

.om-topbar-icon svg { width: 20px !important; height: 20px !important; color: #fff !important; }

.om-topbar-content { flex: 1 !important; min-width: 0 !important; }

.om-topbar-label {
    font-size: 11px !important;
    font-weight: 500 !important;
    opacity: 0.85 !important;
    margin-bottom: 2px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: block !important;
}

.om-topbar-address {
    font-size: 14px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-width: 220px !important;
}

.om-topbar-arrow {
    width: 32px !important;
    height: 32px !important;
    background: rgba(255,255,255,0.12) !important;
    border-radius: 8px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    transition: all 0.3s ease !important;
    margin-right: 12px !important;
}

.om-topbar:hover .om-topbar-arrow {
    background: rgba(255,255,255,0.2) !important;
    transform: translateX(3px) !important;
}

.om-topbar-arrow svg { width: 16px !important; height: 16px !important; color: #fff !important; }

.om-topbar-time {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 8px 14px !important;
    background: rgba(0,0,0,0.2) !important;
    border-radius: 50px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar-time:hover { background: rgba(0,0,0,0.3) !important; transform: scale(1.02) !important; }
.om-topbar-time svg { width: 16px !important; height: 16px !important; color: #34d399 !important; }

/* HEADER BRANCO */
.mkt-header {
    background: #ffffff !important;
    padding: 0 !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 9999 !important;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08) !important;
    border-bottom: none !important;
}

.mkt-header-row {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 14px 20px !important;
    margin-bottom: 0 !important;
    background: #fff !important;
    border-bottom: 1px solid rgba(0,0,0,0.06) !important;
}

/* LOGO */
.mkt-logo {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-decoration: none !important;
    flex-shrink: 0 !important;
}

.mkt-logo-box {
    width: 44px !important;
    height: 44px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 14px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 22px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-logo:hover .mkt-logo-box {
    transform: scale(1.05) rotate(-3deg) !important;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45) !important;
}

.mkt-logo-text {
    font-size: 20px !important;
    font-weight: 800 !important;
    color: #10b981 !important;
    letter-spacing: -0.02em !important;
}

/* USER */
.mkt-user { margin-left: auto !important; text-decoration: none !important; }

.mkt-user-avatar {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 50% !important;
    color: #fff !important;
    font-weight: 700 !important;
    font-size: 16px !important;
    box-shadow: 0 3px 12px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.mkt-user-avatar:hover {
    transform: scale(1.08) !important;
    box-shadow: 0 5px 18px rgba(16, 185, 129, 0.4) !important;
}

.mkt-user.mkt-guest {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: #f1f5f9 !important;
    border-radius: 12px !important;
    transition: all 0.3s ease !important;
}

.mkt-user.mkt-guest:hover { background: #e2e8f0 !important; }
.mkt-user.mkt-guest svg { width: 24px !important; height: 24px !important; color: #64748b !important; }

/* CARRINHO */
.mkt-cart {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 46px !important;
    height: 46px !important;
    background: linear-gradient(135deg, #1e293b, #0f172a) !important;
    border: none !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    box-shadow: 0 4px 15px rgba(15, 23, 42, 0.25) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-cart:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.3) !important;
}

.mkt-cart:active { transform: translateY(-1px) scale(0.98) !important; }
.mkt-cart svg { width: 22px !important; height: 22px !important; color: #fff !important; }

.mkt-cart-count {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 22px !important;
    height: 22px !important;
    padding: 0 6px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    border-radius: 11px !important;
    color: #fff !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 2px solid #fff !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: cartPulse 2s ease-in-out infinite !important;
}

@keyframes cartPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }

/* BUSCA */
.mkt-search {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    background: #f1f5f9 !important;
    border-radius: 14px !important;
    padding: 0 16px !important;
    margin: 0 16px 16px !important;
    border: 2px solid transparent !important;
    transition: all 0.3s ease !important;
}

.mkt-search:focus-within {
    background: #fff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1) !important;
}

.mkt-search svg {
    width: 20px !important;
    height: 20px !important;
    color: #94a3b8 !important;
    flex-shrink: 0 !important;
    transition: color 0.3s ease !important;
}

.mkt-search:focus-within svg { color: #10b981 !important; }

.mkt-search input {
    flex: 1 !important;
    border: none !important;
    background: transparent !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    outline: none !important;
    padding: 14px 0 !important;
    width: 100% !important;
}

.mkt-search input::placeholder { color: #94a3b8 !important; }

/* RESPONSIVO */
@media (max-width: 480px) {
    .om-topbar { padding: 12px 16px !important; }
    .om-topbar-icon { width: 36px !important; height: 36px !important; }
    .om-topbar-address { max-width: 150px !important; font-size: 13px !important; }
    .om-topbar-arrow { display: none !important; }
    .om-topbar-time { padding: 6px 10px !important; font-size: 11px !important; }
    .mkt-header-row { padding: 12px 16px !important; }
    .mkt-logo-box { width: 40px !important; height: 40px !important; font-size: 18px !important; }
    .mkt-logo-text { font-size: 18px !important; }
    .mkt-cart { width: 42px !important; height: 42px !important; }
    .mkt-search { margin: 0 12px 12px !important; }
    .mkt-search input { font-size: 14px !important; padding: 12px 0 !important; }
}

/* ANIMAÇÕES */
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.mkt-header { animation: slideDown 0.4s ease !important; }

::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
::selection { background: rgba(16, 185, 129, 0.2); color: #047857; }
</style>

<script>
(function() {
    var h = document.querySelector('.mkt-header');
    if (h && !document.querySelector('.om-topbar')) {
        var t = document.createElement('div');
        t.className = 'om-topbar';
        t.innerHTML = '<div class="om-topbar-main"><div class="om-topbar-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><div class="om-topbar-content"><div class="om-topbar-label">Entregar em</div><div class="om-topbar-address" id="omAddrFinal">Carregando...</div></div><div class="om-topbar-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div></div><div class="om-topbar-time"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>25-35 min</div>';
        h.insertBefore(t, h.firstChild);
        fetch('/mercado/api/address.php?action=list').then(r=>r.json()).then(d=>{var el=document.getElementById('omAddrFinal');if(el&&d.current)el.textContent=d.current.address_1||'Selecionar';}).catch(()=>{});
    }
    var l = document.querySelector('.mkt-logo');
    if (l && !l.querySelector('.mkt-logo-text')) {
        var s = document.createElement('span');
        s.className = 'mkt-logo-text';
        s.textContent = 'Mercado';
        l.appendChild(s);
    }
})();
</script>
</body>
</html>