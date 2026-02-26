<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * MODAL DE VERIFICAÇÃO DE CEP - INTELIGENTE
 * OneMundo Mercado
 * 
 * Lógica:
 * 1. Se já tem mercado na sessão → não mostra
 * 2. Se logado com endereço → busca mercado automaticamente
 * 3. Se logado sem endereço → mostra modal
 * 4. Se não logado → mostra modal
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('OCSESSID');
    session_start();
}

// Conexão com banco
$conn = null;
try {
    $conn = getMySQLi();
    $conn->set_charset('utf8mb4');
} catch (Exception $e) {
    // Silenciar erro
}

$tem_mercado = isset($_SESSION['market_partner_id']) && $_SESSION['market_partner_id'] > 0;
$mostrar_modal = false;
$cep_cliente = null;
$endereco_cliente = null;
$buscar_automatico = false;

// Se já tem mercado, não faz nada
if (!$tem_mercado && $conn) {
    
    // Verificar se está logado
    $customer_id = $_SESSION['customer_id'] ?? null;
    
    if ($customer_id) {
        // Buscar endereço padrão do cliente
        $stmt = $conn->prepare("
            SELECT a.*, z.name as zone_name
            FROM oc_address a
            LEFT JOIN oc_zone z ON a.zone_id = z.zone_id
            WHERE a.customer_id = ?
            ORDER BY a.address_id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $endereco = $result->fetch_assoc();
            
            // Tentar extrair CEP do endereço
            // Pode estar em 'postcode' ou dentro de 'address_1' ou 'address_2'
            $cep = $endereco['postcode'] ?? '';
            
            // Se não tem postcode, tentar extrair do endereço
            if (empty($cep)) {
                $texto = $endereco['address_1'] . ' ' . $endereco['address_2'];
                if (preg_match('/(\d{5})-?(\d{3})/', $texto, $matches)) {
                    $cep = $matches[1] . $matches[2];
                }
            }
            
            $cep = preg_replace('/\D/', '', $cep);
            
            if (strlen($cep) == 8) {
                $cep_cliente = $cep;
                $endereco_cliente = $endereco;
                $buscar_automatico = true; // Vai buscar mercado automaticamente via JS
            } else {
                $mostrar_modal = true; // Logado mas sem CEP válido
            }
        } else {
            $mostrar_modal = true; // Logado mas sem endereço
        }
        $stmt->close();
    } else {
        $mostrar_modal = true; // Não logado
    }
}

if ($conn) $conn->close();
?>

<style>
.om-cep-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:99999;display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:all .3s;padding:20px;box-sizing:border-box}
.om-cep-overlay.active{opacity:1;visibility:visible}
.om-cep-modal{background:#fff;border-radius:20px;max-width:420px;width:100%;overflow:hidden;transform:translateY(30px) scale(0.95);transition:transform .3s;box-shadow:0 25px 60px rgba(0,0,0,0.3)}
.om-cep-overlay.active .om-cep-modal{transform:translateY(0) scale(1)}
.om-cep-header{background:linear-gradient(135deg,#00d4aa 0%,#00b894 100%);padding:35px 30px;text-align:center;position:relative}
.om-cep-header-icon{width:70px;height:70px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 15px;font-size:32px}
.om-cep-header h2{color:#fff;font-size:22px;margin:0 0 8px;font-weight:700}
.om-cep-header p{color:rgba(255,255,255,0.9);margin:0;font-size:14px}
.om-cep-close{position:absolute;top:15px;right:15px;background:rgba(255,255,255,0.2);border:none;color:#fff;width:36px;height:36px;border-radius:50%;cursor:pointer;font-size:20px;display:flex;align-items:center;justify-content:center}
.om-cep-close:hover{background:rgba(255,255,255,0.3)}
.om-cep-body{padding:30px}
.om-cep-input{width:100%;padding:16px 20px;font-size:20px;border:2px solid #e0e0e0;border-radius:12px;text-align:center;letter-spacing:3px;font-weight:600;outline:none;box-sizing:border-box;margin-bottom:15px}
.om-cep-input:focus{border-color:#00d4aa;box-shadow:0 0 0 4px rgba(0,212,170,0.15)}
.om-cep-btn{width:100%;padding:16px;background:linear-gradient(135deg,#00d4aa 0%,#00b894 100%);color:#fff;border:none;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px}
.om-cep-btn:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(0,212,170,0.3)}
.om-cep-btn:disabled{background:#ccc;cursor:not-allowed;transform:none}
.om-cep-msg{padding:15px;border-radius:10px;margin-bottom:15px;text-align:center;font-size:14px;display:none}
.om-cep-msg.success{background:#e8f8f5;color:#00875a;display:block}
.om-cep-msg.error{background:#fef2f2;color:#dc2626;display:block}
.om-cep-msg.loading{background:#f0f9ff;color:#0369a1;display:block}
.om-cep-link{text-align:center;margin-top:15px}
.om-cep-link a{color:#888;font-size:13px;text-decoration:none}
.om-cep-spinner{width:20px;height:20px;border:3px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:omSpin .8s linear infinite}
@keyframes omSpin{to{transform:rotate(360deg)}}
.om-cep-resultado{display:none;text-align:center}
.om-cep-resultado.active{display:block}
.om-cep-disponivel{padding:20px;background:linear-gradient(135deg,rgba(0,212,170,0.1) 0%,rgba(0,184,148,0.1) 100%);border-radius:15px;margin-bottom:20px}
.om-cep-disponivel h3{color:#00875a;font-size:18px;margin:10px 0 5px}
.om-cep-indisponivel{padding:20px;background:#fef2f2;border-radius:15px;margin-bottom:20px}
.om-cep-indisponivel h3{color:#dc2626;font-size:18px;margin:10px 0 5px}
.om-cep-auto{text-align:center;padding:20px}
.om-cep-auto p{color:#666;margin:10px 0}
</style>

<div class="om-cep-overlay" id="omCepOverlay">
    <div class="om-cep-modal">
        <div class="om-cep-header">
            <?php if ($tem_mercado): ?><button class="om-cep-close" onclick="omFecharModalCep()">&times;</button><?php endif; ?>
            <div class="om-cep-header-icon">📍</div>
            <h2>Verificar disponibilidade</h2>
            <p>Veja se entregamos na sua região</p>
        </div>
        <div class="om-cep-body">
            <!-- Formulário manual -->
            <div id="omCepForm">
                <input type="text" class="om-cep-input" id="omCepInput" placeholder="00000-000" maxlength="9" inputmode="numeric">
                <div class="om-cep-msg" id="omCepMsg"></div>
                <button class="om-cep-btn" id="omCepBtn" onclick="omVerificarCep()">Verificar disponibilidade</button>
                <div class="om-cep-link"><a href="https://buscacepinter.correios.com.br/app/endereco/index.php" target="_blank">Não sei meu CEP →</a></div>
            </div>
            
            <!-- Busca automática (para logados) -->
            <div id="omCepAuto" class="om-cep-auto" style="display:none">
                <div class="om-cep-spinner" style="margin:0 auto;border-color:#00d4aa;border-top-color:#fff"></div>
                <p>Verificando disponibilidade para seu endereço...</p>
            </div>
            
            <!-- Resultado -->
            <div class="om-cep-resultado" id="omCepResultado"></div>
        </div>
    </div>
</div>

<script>
// Dados do PHP
var omCepCliente = <?= json_encode($cep_cliente) ?>;
var omBuscarAuto = <?= $buscar_automatico ? 'true' : 'false' ?>;
var omMostrarModal = <?= $mostrar_modal ? 'true' : 'false' ?>;
var omTemMercado = <?= $tem_mercado ? 'true' : 'false' ?>;

// Formatação do input
document.getElementById('omCepInput')?.addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '');
    if (v.length > 5) v = v.substring(0, 5) + '-' + v.substring(5, 8);
    e.target.value = v;
    if (v.length === 9) omVerificarCep();
});

document.getElementById('omCepInput')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') omVerificarCep();
});

function omAbrirModalCep() {
    document.getElementById('omCepOverlay').classList.add('active');
    document.getElementById('omCepInput')?.focus();
    document.getElementById('omCepForm').style.display = 'block';
    document.getElementById('omCepAuto').style.display = 'none';
    document.getElementById('omCepResultado').classList.remove('active');
}

function omFecharModalCep() {
    document.getElementById('omCepOverlay').classList.remove('active');
}

async function omVerificarCep(cepParam) {
    const cep = cepParam || document.getElementById('omCepInput').value.replace(/\D/g, '');
    
    if (cep.length !== 8) {
        document.getElementById('omCepMsg').className = 'om-cep-msg error';
        document.getElementById('omCepMsg').textContent = 'Digite um CEP válido';
        return;
    }
    
    const btn = document.getElementById('omCepBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<div class="om-cep-spinner"></div> Verificando...';
    }
    
    const msg = document.getElementById('omCepMsg');
    if (msg) {
        msg.className = 'om-cep-msg loading';
        msg.textContent = 'Consultando...';
    }
    
    try {
        const res = await fetch('/mercado/api/localizacao.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'verificar_cep', cep: cep})
        });
        const data = await res.json();
        
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = 'Verificar disponibilidade';
        }
        
        if (data.success && data.disponivel) {
            // Sucesso! Recarregar página
            if (cepParam) {
                // Busca automática - recarrega direto
                window.location.reload();
            } else {
                // Manual - mostra mensagem antes
                document.getElementById('omCepForm').style.display = 'none';
                document.getElementById('omCepAuto').style.display = 'none';
                document.getElementById('omCepResultado').innerHTML = '<div class="om-cep-disponivel"><div style="font-size:48px">🎉</div><h3>Ótimo! Entregamos na sua região!</h3><p>Entrega em até <strong>' + (data.mercado?.tempo_estimado || 30) + ' minutos</strong></p></div><button class="om-cep-btn" onclick="window.location.reload()">Ver Produtos</button>';
                document.getElementById('omCepResultado').classList.add('active');
            }
        } else {
            // Não disponível
            document.getElementById('omCepForm').style.display = 'none';
            document.getElementById('omCepAuto').style.display = 'none';
            document.getElementById('omCepResultado').innerHTML = '<div class="om-cep-indisponivel"><div style="font-size:48px">😔</div><h3>Ainda não atendemos sua região</h3><p>' + (data.localizacao?.cidade || '') + '</p></div><button class="om-cep-btn" style="background:#666" onclick="omTentarOutroCep()">Tentar outro CEP</button>';
            document.getElementById('omCepResultado').classList.add('active');
        }
    } catch (err) {
        console.error('Erro:', err);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = 'Verificar disponibilidade';
        }
        if (msg) {
            msg.className = 'om-cep-msg error';
            msg.textContent = 'Erro ao verificar. Tente novamente.';
        }
        // Se era automático, mostra form manual
        if (cepParam) {
            document.getElementById('omCepAuto').style.display = 'none';
            document.getElementById('omCepForm').style.display = 'block';
        }
    }
}

function omTentarOutroCep() {
    document.getElementById('omCepForm').style.display = 'block';
    document.getElementById('omCepAuto').style.display = 'none';
    document.getElementById('omCepResultado').classList.remove('active');
    document.getElementById('omCepInput').value = '';
    document.getElementById('omCepInput').focus();
    document.getElementById('omCepMsg').className = 'om-cep-msg';
}

// Inicialização
document.addEventListener('DOMContentLoaded', function() {
    if (omTemMercado) {
        // Já tem mercado, não faz nada
        return;
    }
    
    if (omBuscarAuto && omCepCliente) {
        // Usuário logado com CEP - buscar automaticamente
        setTimeout(function() {
            document.getElementById('omCepOverlay').classList.add('active');
            document.getElementById('omCepForm').style.display = 'none';
            document.getElementById('omCepAuto').style.display = 'block';
            
            // Buscar mercado automaticamente
            setTimeout(function() {
                omVerificarCep(omCepCliente);
            }, 500);
        }, 300);
    } else if (omMostrarModal) {
        // Mostrar modal para digitar CEP
        setTimeout(omAbrirModalCep, 800);
    }
});

// Função global para abrir modal de endereço
window.abrirModalEndereco = omAbrirModalCep;
</script>
