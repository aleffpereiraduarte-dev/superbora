<?php
/**
 * 📸 COMPONENTE DE GALERIA DE FOTOS
 */
if (!isset($order_id)) return;
?>
<div id="photos-gallery" style="margin:16px 0;"></div>

<div id="photo-lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.95);z-index:99999;padding:20px;">
    <button onclick="closeLightbox()" style="position:absolute;top:20px;right:20px;background:rgba(255,255,255,0.2);border:none;color:#fff;width:40px;height:40px;border-radius:50%;font-size:20px;cursor:pointer;">×</button>
    <img id="lightbox-img" src="" style="max-width:90%;max-height:90%;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);border-radius:8px;">
</div>

<script>
(function() {
    const orderId = <?php echo intval($order_id); ?>;
    
    async function loadPhotos() {
        try {
            const res = await fetch(`/mercado/api/photos.php?action=list&order_id=${orderId}`);
            const data = await res.json();
            
            if (data.success && data.photos.length > 0) {
                renderGallery(data.photos);
            }
        } catch (e) {}
    }
    
    function renderGallery(photos) {
        const container = document.getElementById("photos-gallery");
        
        // Agrupar por tipo
        const byType = {};
        photos.forEach(p => {
            if (!byType[p.photo_type]) byType[p.photo_type] = [];
            byType[p.photo_type].push(p);
        });
        
        const typeLabels = {
            products: "📸 Fotos dos Produtos",
            receipt: "🧾 Comprovantes",
            delivery_proof: "📦 Comprovante de Entrega",
            issue: "⚠️ Problemas Reportados"
        };
        
        let html = "";
        
        for (const [type, typePhotos] of Object.entries(byType)) {
            html += `
                <div style="margin-bottom:20px;">
                    <div style="font-size:14px;font-weight:600;margin-bottom:10px;color:#888;">${typeLabels[type] || type}</div>
                    <div style="display:flex;gap:8px;overflow-x:auto;padding-bottom:8px;">
            `;
            
            typePhotos.forEach(photo => {
                html += `
                    <div onclick="openLightbox('${photo.photo_path}')" style="flex-shrink:0;width:100px;height:100px;border-radius:12px;overflow:hidden;cursor:pointer;background:#222;">
                        <img src="${photo.thumbnail_path || photo.photo_path}" style="width:100%;height:100%;object-fit:cover;">
                    </div>
                `;
            });
            
            html += `</div></div>`;
        }
        
        container.innerHTML = html;
    }
    
    window.openLightbox = function(src) {
        document.getElementById("lightbox-img").src = src;
        document.getElementById("photo-lightbox").style.display = "block";
    };
    
    window.closeLightbox = function() {
        document.getElementById("photo-lightbox").style.display = "none";
    };
    
    // Fechar com ESC
    document.addEventListener("keydown", e => {
        if (e.key === "Escape") closeLightbox();
    });
    
    loadPhotos();
})();
</script>
