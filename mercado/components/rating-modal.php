<?php
/**
 * ⭐ COMPONENTE DE AVALIAÇÃO - MODAL
 * 
 * Uso: <?php $order_id = 123; include "components/rating-modal.php"; ?>
 */
if (!isset($order_id)) return;
?>
<div id="rating-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:99999;padding:20px;overflow-y:auto;">
    <div style="max-width:500px;margin:0 auto;background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);border-radius:24px;padding:24px;">
        
        <div style="text-align:center;margin-bottom:24px;">
            <div style="font-size:48px;margin-bottom:8px;">⭐</div>
            <h2 style="font-size:22px;margin-bottom:4px;">Como foi sua experiência?</h2>
            <p style="color:#888;font-size:14px;">Sua avaliação ajuda a melhorar nosso serviço</p>
        </div>
        
        <!-- Avaliação Geral -->
        <div class="rating-section" style="margin-bottom:24px;">
            <div style="font-weight:600;margin-bottom:12px;">Avaliação Geral</div>
            <div class="star-rating" data-target="overall" style="display:flex;gap:8px;justify-content:center;">
                <span class="star" data-value="1" style="font-size:36px;cursor:pointer;color:#444;">★</span>
                <span class="star" data-value="2" style="font-size:36px;cursor:pointer;color:#444;">★</span>
                <span class="star" data-value="3" style="font-size:36px;cursor:pointer;color:#444;">★</span>
                <span class="star" data-value="4" style="font-size:36px;cursor:pointer;color:#444;">★</span>
                <span class="star" data-value="5" style="font-size:36px;cursor:pointer;color:#444;">★</span>
            </div>
            <input type="hidden" id="overall_rating" value="0">
        </div>
        
        <!-- Avaliação do Shopper -->
        <div id="shopper-rating-section" class="rating-section" style="margin-bottom:24px;padding:16px;background:rgba(0,0,0,0.2);border-radius:16px;display:none;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                <div style="width:40px;height:40px;background:#8b5cf6;border-radius:50%;display:flex;align-items:center;justify-content:center;">🛒</div>
                <div>
                    <div style="font-weight:600;">Shopper</div>
                    <div id="shopper-name" style="font-size:12px;color:#888;"></div>
                </div>
            </div>
            <div class="star-rating" data-target="shopper" style="display:flex;gap:6px;margin-bottom:12px;">
                <span class="star" data-value="1" style="font-size:28px;cursor:pointer;color:#444;">★</span>
                <span class="star" data-value="2" style="font-size:28px;cursor:pointer;color:#444;">★</span>
                <span class="star" data-value="3" style="font-size:28px;cursor:pointer;color:#444;">★</span>
                <span class="star" data-value="4" style="font-size:28px;cursor:pointer;color:#444;">★</span>
                <span class="star" data-value="5" style="font-size:28px;cursor:pointer;color:#444;">★</span>
            </div>
            <input type="hidden" id="shopper_rating" value="0">
            <div class="tags" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <label style="background:rgba(255,255,255,0.1);padding:6px 12px;border-radius:20px;font-size:12px;cursor:pointer;">
                    <input type="checkbox" name="shopper_tags" value="atencioso" style="display:none;"> 👍 Atencioso
                </label>
                <label style="background:rgba(255,255,255,0.1);padding:6px 12px;border-radius:20px;font-size:12px;cursor:pointer;">
                    <input type="checkbox" name="shopper_tags" value="rapido" style="display:none;"> ⚡ Rápido
                </label>
                <label style="background:rgba(255,255,255,0.1);padding:6px 12px;border-radius:20px;font-size:12px;cursor:pointer;">
                    <input type="checkbox" name="shopper_tags" value="boas_escolhas" style="display:none;"> ✓ Boas escolhas
                </label>
            </div>
        </div>
        
        <!-- Avaliação do Delivery -->
        <div id="delivery-rating-section" class="rating-section" style="margin-bottom:24px;padding:16px;background:rgba(0,0,0,0.2);border-radius:16px;display:none;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                <div style="width:40px;height:40px;background:#f59e0b;border-radius:50%;display:flex;align-items:center;justify-content:center;">🚴</div>
                <div>
                    <div style="font-weight:600;">Entregador</div>
                    <div id="delivery-name" style="font-size:12px;color:#888;"></div>
                </div>
            </div>
            <div class="star-rating" data-target="delivery" style="display:flex;gap:6px;margin-bottom:12px;">
                <span class="star" data-value="1" style="font-size:28px;cursor:pointer;color:#444;">★</span>
                <span class="star" data-value="2" style="font-size:28px;cursor:pointer;color:#444;">★</span>
                <span class="star" data-value="3" style="font-size:28px;cursor:pointer;color:#444;">★</span>
                <span class="star" data-value="4" style="font-size:28px;cursor:pointer;color:#444;">★</span>
                <span class="star" data-value="5" style="font-size:28px;cursor:pointer;color:#444;">★</span>
            </div>
            <input type="hidden" id="delivery_rating" value="0">
            <div class="tags" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <label style="background:rgba(255,255,255,0.1);padding:6px 12px;border-radius:20px;font-size:12px;cursor:pointer;">
                    <input type="checkbox" name="delivery_tags" value="educado" style="display:none;"> 👍 Educado
                </label>
                <label style="background:rgba(255,255,255,0.1);padding:6px 12px;border-radius:20px;font-size:12px;cursor:pointer;">
                    <input type="checkbox" name="delivery_tags" value="rapido" style="display:none;"> ⚡ Rápido
                </label>
                <label style="background:rgba(255,255,255,0.1);padding:6px 12px;border-radius:20px;font-size:12px;cursor:pointer;">
                    <input type="checkbox" name="delivery_tags" value="cuidadoso" style="display:none;"> 📦 Cuidadoso
                </label>
            </div>
        </div>
        
        <!-- Comentário -->
        <div style="margin-bottom:24px;">
            <textarea id="overall_comment" placeholder="Deixe um comentário (opcional)" style="width:100%;height:80px;background:rgba(0,0,0,0.2);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:12px;color:#fff;font-size:14px;resize:none;"></textarea>
        </div>
        
        <!-- Botões -->
        <div style="display:flex;gap:12px;">
            <button onclick="closeRatingModal()" style="flex:1;padding:14px;background:rgba(255,255,255,0.1);border:none;border-radius:12px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;">Agora não</button>
            <button onclick="submitRating()" style="flex:2;padding:14px;background:linear-gradient(135deg,#f59e0b,#d97706);border:none;border-radius:12px;color:#000;font-size:14px;font-weight:700;cursor:pointer;">Enviar Avaliação</button>
        </div>
    </div>
</div>

<script>
(function() {
    const orderId = <?php echo intval($order_id); ?>;
    let ratingData = { overall: 0, shopper: 0, delivery: 0 };
    
    // Verificar se pode avaliar
    async function checkRating() {
        try {
            const res = await fetch(`/mercado/api/rating.php?action=check&order_id=${orderId}`);
            const data = await res.json();
            
            if (data.can_rate) {
                // Mostrar seções conforme disponível
                if (data.has_shopper) {
                    document.getElementById("shopper-rating-section").style.display = "block";
                    document.getElementById("shopper-name").textContent = data.shopper_name || "Shopper";
                }
                if (data.has_delivery) {
                    document.getElementById("delivery-rating-section").style.display = "block";
                    document.getElementById("delivery-name").textContent = data.delivery_name || "Entregador";
                }
                
                // Mostrar modal após 2 segundos
                setTimeout(() => {
                    document.getElementById("rating-modal").style.display = "block";
                }, 2000);
            }
        } catch (e) {
            console.error("Erro ao verificar rating:", e);
        }
    }
    
    // Estrelas clicáveis
    document.querySelectorAll(".star-rating").forEach(container => {
        const target = container.dataset.target;
        const stars = container.querySelectorAll(".star");
        
        stars.forEach(star => {
            star.addEventListener("click", () => {
                const value = parseInt(star.dataset.value);
                ratingData[target] = value;
                document.getElementById(target + "_rating").value = value;
                
                stars.forEach((s, i) => {
                    s.style.color = i < value ? "#f59e0b" : "#444";
                });
            });
            
            star.addEventListener("mouseenter", () => {
                const value = parseInt(star.dataset.value);
                stars.forEach((s, i) => {
                    s.style.color = i < value ? "#f59e0b" : "#444";
                });
            });
        });
        
        container.addEventListener("mouseleave", () => {
            const currentValue = ratingData[target];
            stars.forEach((s, i) => {
                s.style.color = i < currentValue ? "#f59e0b" : "#444";
            });
        });
    });
    
    // Tags clicáveis
    document.querySelectorAll(".tags label").forEach(label => {
        label.addEventListener("click", () => {
            const checkbox = label.querySelector("input");
            checkbox.checked = !checkbox.checked;
            label.style.background = checkbox.checked ? "rgba(245,158,11,0.3)" : "rgba(255,255,255,0.1)";
        });
    });
    
    // Fechar modal
    window.closeRatingModal = function() {
        document.getElementById("rating-modal").style.display = "none";
    };
    
    // Enviar avaliação
    window.submitRating = async function() {
        if (ratingData.overall === 0) {
            alert("Por favor, dê uma avaliação geral");
            return;
        }
        
        const shopperTags = Array.from(document.querySelectorAll("input[name=\"shopper_tags\"]:checked")).map(c => c.value);
        const deliveryTags = Array.from(document.querySelectorAll("input[name=\"delivery_tags\"]:checked")).map(c => c.value);
        
        const payload = {
            action: "submit",
            order_id: orderId,
            overall_rating: ratingData.overall,
            overall_comment: document.getElementById("overall_comment").value,
            shopper_rating: ratingData.shopper || null,
            shopper_tags: shopperTags,
            delivery_rating: ratingData.delivery || null,
            delivery_tags: deliveryTags
        };
        
        try {
            const res = await fetch("/mercado/api/rating.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            
            if (data.success) {
                document.getElementById("rating-modal").innerHTML = `
                    <div style="max-width:400px;margin:50px auto;background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);border-radius:24px;padding:40px;text-align:center;">
                        <div style="font-size:64px;margin-bottom:16px;">🎉</div>
                        <h2 style="margin-bottom:8px;">Obrigado!</h2>
                        <p style="color:#888;margin-bottom:24px;">Sua avaliação foi enviada com sucesso</p>
                        <button onclick="closeRatingModal()" style="padding:14px 32px;background:linear-gradient(135deg,#22c55e,#16a34a);border:none;border-radius:12px;color:#fff;font-size:14px;font-weight:700;cursor:pointer;">Fechar</button>
                    </div>
                `;
                setTimeout(closeRatingModal, 3000);
            } else {
                alert(data.error || "Erro ao enviar avaliação");
            }
        } catch (e) {
            alert("Erro de conexão");
        }
    };
    
    // Iniciar
    checkRating();
})();
</script>
