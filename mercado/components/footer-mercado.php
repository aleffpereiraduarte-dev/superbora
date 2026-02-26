<?php
/**
 * 🟢 FOOTER VERDE - ONEMUNDO MERCADO
 */
?>
<style>
.om-footer {
    background: linear-gradient(135deg, #047857 0%, #065f46 100%);
    color: white;
    padding: 40px 20px 20px;
    margin-top: 40px;
}
.om-footer-content {
    max-width: 1200px;
    margin: 0 auto;
}
.om-footer-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 30px;
}
.om-footer-col h4 {
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 16px;
    color: #a7f3d0;
}
.om-footer-col a {
    display: block;
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    padding: 6px 0;
    font-size: 14px;
    transition: color 0.2s;
}
.om-footer-col a:hover {
    color: #fbbf24;
}
.om-footer-col p {
    font-size: 13px;
    line-height: 1.6;
    opacity: 0.8;
}
.om-footer-social {
    display: flex;
    gap: 10px;
    margin-top: 12px;
}
.om-footer-social a {
    width: 36px;
    height: 36px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    padding: 0;
}
.om-footer-social a:hover {
    background: rgba(255,255,255,0.2);
}
.om-footer-bottom {
    border-top: 1px solid rgba(255,255,255,0.1);
    margin-top: 30px;
    padding-top: 20px;
    text-align: center;
    font-size: 13px;
    color: rgba(255,255,255,0.6);
}
.om-payment-icons {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    flex-wrap: wrap;
}
.om-payment-icons span {
    background: rgba(255,255,255,0.1);
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 12px;
}
.om-footer-parcelamento {
    margin-top: 12px;
    font-size: 12px;
}
</style>

<footer class="om-footer">
    <div class="om-footer-content">
        <div class="om-footer-grid">
            <div class="om-footer-col">
                <h4>🛒 OneMundo Mercado</h4>
                <p>Seu supermercado online com entrega rápida e preços incríveis.</p>
                <div class="om-footer-social">
                    <a href="#" title="Facebook">📘</a>
                    <a href="#" title="Instagram">📸</a>
                    <a href="#" title="WhatsApp">💬</a>
                </div>
            </div>
            
            <div class="om-footer-col">
                <h4>Navegação</h4>
                <a href="/mercado/">🏠 Início</a>
                <a href="/mercado/estabelecimentos.php">🏪 Estabelecimentos</a>
                <a href="/mercado/categoria.php">📂 Categorias</a>
                <a href="/mercado/pedidos.php">📦 Meus Pedidos</a>
                <a href="/mercado/favoritos.php">❤️ Favoritos</a>
            </div>
            
            <div class="om-footer-col">
                <h4>Ajuda</h4>
                <a href="/mercado/contato.php">📞 Fale Conosco</a>
                <a href="/mercado/faq.php">❓ Dúvidas Frequentes</a>
                <a href="/mercado/politicas.php">📜 Políticas</a>
                <a href="/mercado/trocas.php">🔄 Trocas e Devoluções</a>
            </div>
            
            <div class="om-footer-col">
                <h4>Pagamento</h4>
                <div class="om-payment-icons">
                    <span>💳 Crédito</span>
                    <span>💳 Débito</span>
                    <span>📱 PIX</span>
                    <span>📄 Boleto</span>
                </div>
                <p class="om-footer-parcelamento">
                    Parcele em ate 12x sem juros*
                </p>
            </div>
        </div>
        
        <div class="om-footer-bottom">
            <p>© <?= date('Y') ?> OneMundo Mercado - Todos os direitos reservados</p>
        </div>
    </div>
</footer>