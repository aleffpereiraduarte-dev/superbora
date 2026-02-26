<?php
/**
 * 🗺️ COMPONENTE DE MAPA - RASTREAMENTO EM TEMPO REAL
 * 
 * Uso: <?php $order_id = 123; include "components/tracking-map.php"; ?>
 */

if (!isset($order_id)) return;
?>
<div id="tracking-container" style="display:none;">
    <div id="tracking-map" style="width:100%;height:300px;border-radius:16px;overflow:hidden;"></div>
    <div id="tracking-info" style="margin-top:12px;padding:16px;background:rgba(255,255,255,0.05);border-radius:12px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
            <div id="delivery-avatar" style="width:48px;height:48px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:24px;">🚴</div>
            <div>
                <div id="delivery-name" style="font-weight:700;font-size:16px;">Carregando...</div>
                <div id="delivery-vehicle" style="font-size:12px;color:#888;">Veículo</div>
            </div>
            <a id="delivery-phone-link" href="#" style="margin-left:auto;background:#22c55e;color:#fff;padding:10px;border-radius:50%;text-decoration:none;">📞</a>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div style="background:rgba(0,0,0,0.2);padding:12px;border-radius:10px;text-align:center;">
                <div style="font-size:24px;font-weight:800;" id="eta-time">--</div>
                <div style="font-size:11px;color:#888;">TEMPO ESTIMADO</div>
            </div>
            <div style="background:rgba(0,0,0,0.2);padding:12px;border-radius:10px;text-align:center;">
                <div style="font-size:24px;font-weight:800;" id="eta-distance">--</div>
                <div style="font-size:11px;color:#888;">DISTÂNCIA</div>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
(function() {
    const orderId = <?php echo intval($order_id); ?>;
    let map = null;
    let deliveryMarker = null;
    let customerMarker = null;
    let partnerMarker = null;
    let routeLine = null;
    let updateInterval = null;
    
    // Inicializar
    async function init() {
        const data = await fetchLocation();
        if (!data || !data.tracking) return;
        
        document.getElementById("tracking-container").style.display = "block";
        
        // Criar mapa
        const center = [data.delivery.latitude, data.delivery.longitude];
        map = L.map("tracking-map").setView(center, 15);
        
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "© OpenStreetMap"
        }).addTo(map);
        
        // Ícones customizados
        const deliveryIcon = L.divIcon({
            html: `<div style="background:linear-gradient(135deg,#f59e0b,#d97706);width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 4px 15px rgba(0,0,0,0.3);border:3px solid #fff;">${data.delivery.vehicle === "carro" ? "🚗" : "🏍️"}</div>`,
            className: "",
            iconSize: [40, 40],
            iconAnchor: [20, 20]
        });
        
        const customerIcon = L.divIcon({
            html: `<div style="background:linear-gradient(135deg,#22c55e,#16a34a);width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;box-shadow:0 4px 15px rgba(0,0,0,0.3);border:3px solid #fff;">📍</div>`,
            className: "",
            iconSize: [36, 36],
            iconAnchor: [18, 18]
        });
        
        const partnerIcon = L.divIcon({
            html: `<div style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;box-shadow:0 4px 15px rgba(0,0,0,0.3);border:3px solid #fff;">🏪</div>`,
            className: "",
            iconSize: [36, 36],
            iconAnchor: [18, 18]
        });
        
        // Adicionar marcadores
        deliveryMarker = L.marker([data.delivery.latitude, data.delivery.longitude], {icon: deliveryIcon}).addTo(map);
        
        if (data.customer.latitude && data.customer.longitude) {
            customerMarker = L.marker([data.customer.latitude, data.customer.longitude], {icon: customerIcon}).addTo(map)
                .bindPopup("Seu endereço");
        }
        
        if (data.partner.latitude && data.partner.longitude) {
            partnerMarker = L.marker([data.partner.latitude, data.partner.longitude], {icon: partnerIcon}).addTo(map)
                .bindPopup(data.partner.name || "Mercado");
        }
        
        // Linha da rota
        if (data.customer.latitude && data.customer.longitude) {
            routeLine = L.polyline([
                [data.delivery.latitude, data.delivery.longitude],
                [data.customer.latitude, data.customer.longitude]
            ], {color: "#f59e0b", weight: 4, opacity: 0.7, dashArray: "10, 10"}).addTo(map);
        }
        
        // Ajustar zoom para ver todos os pontos
        const bounds = L.latLngBounds([
            [data.delivery.latitude, data.delivery.longitude]
        ]);
        if (data.customer.latitude) bounds.extend([data.customer.latitude, data.customer.longitude]);
        if (data.partner.latitude) bounds.extend([data.partner.latitude, data.partner.longitude]);
        map.fitBounds(bounds, {padding: [50, 50]});
        
        // Atualizar info
        updateInfo(data);
        
        // Polling a cada 5 segundos
        updateInterval = setInterval(updateLocation, 5000);
    }
    
    async function fetchLocation() {
        try {
            const response = await fetch(`/mercado/api/tracking.php?action=get_location&order_id=${orderId}`);
            return await response.json();
        } catch (e) {
            return null;
        }
    }
    
    async function updateLocation() {
        const data = await fetchLocation();
        if (!data || !data.tracking) return;
        
        // Atualizar posição do delivery com animação
        if (deliveryMarker) {
            const newLatLng = L.latLng(data.delivery.latitude, data.delivery.longitude);
            deliveryMarker.setLatLng(newLatLng);
        }
        
        // Atualizar linha da rota
        if (routeLine && data.customer.latitude) {
            routeLine.setLatLngs([
                [data.delivery.latitude, data.delivery.longitude],
                [data.customer.latitude, data.customer.longitude]
            ]);
        }
        
        updateInfo(data);
    }
    
    function updateInfo(data) {
        document.getElementById("delivery-name").textContent = data.delivery.name || "Entregador";
        document.getElementById("delivery-vehicle").textContent = data.delivery.vehicle === "carro" ? "🚗 Carro" : "🏍️ Moto";
        document.getElementById("delivery-phone-link").href = "tel:" + (data.delivery.phone || "");
        
        if (data.eta_minutes) {
            document.getElementById("eta-time").textContent = data.eta_minutes + " min";
        }
        if (data.distance_km) {
            document.getElementById("eta-distance").textContent = data.distance_km + " km";
        }
    }
    
    // Iniciar quando DOM pronto
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
</script>
