<!-- 🛒 ONE WIDGET - Chat Flutuante -->
<style>
#one-widget-btn{position:fixed;bottom:20px;right:20px;width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#10b981 0%,#059669 100%);border:none;cursor:pointer;box-shadow:0 4px 20px rgba(16,185,129,0.4);z-index:99998;display:flex;align-items:center;justify-content:center;transition:transform 0.3s,box-shadow 0.3s}
#one-widget-btn:hover{transform:scale(1.1);box-shadow:0 6px 30px rgba(16,185,129,0.5)}
#one-widget-btn svg{width:28px;height:28px;fill:white}
#one-widget-btn .pulse{position:absolute;width:100%;height:100%;border-radius:50%;background:rgba(16,185,129,0.4);animation:pulse-one 2s infinite}
@keyframes pulse-one{0%{transform:scale(1);opacity:1}100%{transform:scale(1.5);opacity:0}}
#one-widget-frame{position:fixed;bottom:90px;right:20px;width:380px;height:600px;max-height:80vh;border:none;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,0.3);z-index:99999;display:none;background:#0a0a0f}
#one-widget-frame.show{display:block;animation:slideUp 0.3s ease}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
#one-widget-close{position:fixed;bottom:700px;right:25px;width:30px;height:30px;border-radius:50%;background:#ef4444;border:none;color:white;font-size:18px;cursor:pointer;z-index:100000;display:none;align-items:center;justify-content:center}
#one-widget-close.show{display:flex}
@media(max-width:480px){#one-widget-frame{width:calc(100% - 20px);height:calc(100% - 100px);right:10px;bottom:80px;max-height:none}#one-widget-close{bottom:auto;top:10px;right:20px}}
</style>
<button id="one-widget-btn" onclick="toggleOneWidget()"><div class="pulse"></div><svg viewBox="0 0 24 24"><path d="M12 3c5.5 0 10 3.58 10 8s-4.5 8-10 8c-1.24 0-2.43-.18-3.53-.5C5.55 21 2 21 2 21c2.33-2.33 2.7-3.9 2.75-4.5C3.05 15.07 2 13.13 2 11c0-4.42 4.5-8 10-8z"/></svg></button>
<button id="one-widget-close" onclick="toggleOneWidget()">✕</button>
<iframe id="one-widget-frame" src="/mercado/one.php"></iframe>
<script>function toggleOneWidget(){var f=document.getElementById("one-widget-frame"),b=document.getElementById("one-widget-btn"),c=document.getElementById("one-widget-close");f.classList.contains("show")?(f.classList.remove("show"),c.classList.remove("show"),b.style.display="flex"):(f.classList.add("show"),c.classList.add("show"),b.style.display="none")}</script>