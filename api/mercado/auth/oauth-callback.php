<!DOCTYPE html>
<html>
<head><title>SuperBora - Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
<script>
var hash = window.location.hash.substring(1);
var params = new URLSearchParams(hash);
var idToken = params.get('id_token');
var accessToken = params.get('access_token');
var provider = new URLSearchParams(window.location.search).get('provider') || 'google';

var token = idToken || accessToken;

if (token) {
    window.location.href = 'superbora://auth/' + provider + '?token=' + encodeURIComponent(token);
    setTimeout(function() {
        document.getElementById('msg').innerHTML = 
            '<h2 style="color:#036B52">Login realizado!</h2>' +
            '<p>Volte para o app SuperBora</p>' +
            '<p style="color:#999;font-size:13px">Se o app nao abriu, abra manualmente</p>';
    }, 2000);
} else {
    document.getElementById('msg').innerHTML = 
        '<h2 style="color:#ef4444">Erro</h2><p>Token nao encontrado. Tente novamente.</p>';
}
</script>
<div id="msg" style="text-align:center;padding:60px 20px;font-family:system-ui">
    <p>Redirecionando para o app...</p>
</div>
</body>
</html>
