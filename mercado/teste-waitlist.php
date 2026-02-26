<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Waitlist - OneMundo Mercado</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 24px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 25px 80px rgba(0,0,0,0.3);
        }

        h1 {
            font-size: 28px;
            color: #2d3436;
            margin-bottom: 8px;
            text-align: center;
        }

        .subtitle {
            color: #636e72;
            text-align: center;
            margin-bottom: 32px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: 600;
            color: #2d3436;
            margin-bottom: 8px;
        }

        input[type="text"] {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 18px;
            text-align: center;
            letter-spacing: 2px;
            transition: all 0.3s ease;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102,126,234,0.1);
        }

        .btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102,126,234,0.4);
        }

        .result {
            margin-top: 24px;
            padding: 20px;
            border-radius: 12px;
            display: none;
        }

        .result.success {
            background: #d3f9d8;
            border: 2px solid #40c057;
            display: block;
        }

        .result.info {
            background: #e7f5ff;
            border: 2px solid #339af0;
            display: block;
        }

        .result h3 {
            color: #2d3436;
            margin-bottom: 8px;
        }

        .result p {
            color: #495057;
        }

        .ceps-exemplo {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e9ecef;
        }

        .ceps-exemplo h4 {
            color: #868e96;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .cep-btn {
            display: inline-block;
            padding: 8px 16px;
            margin: 4px;
            background: #f1f3f5;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .cep-btn:hover {
            background: #e9ecef;
        }

        .cep-btn.com-mercado {
            background: #d3f9d8;
            color: #2b8a3e;
        }

        .cep-btn.sem-mercado {
            background: #ffe3e3;
            color: #c92a2a;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Verificar Disponibilidade</h1>
        <p class="subtitle">Digite seu CEP para verificar se atendemos sua regiao</p>

        <form id="cepForm" onsubmit="verificarCep(event)">
            <div class="form-group">
                <label for="cep">Seu CEP</label>
                <input type="text" id="cep" name="cep" placeholder="00000-000" maxlength="9" required>
            </div>

            <button type="submit" class="btn">Verificar Disponibilidade</button>
        </form>

        <div class="result" id="result">
            <h3 id="resultTitle">Resultado</h3>
            <p id="resultMessage"></p>
        </div>

        <div class="ceps-exemplo">
            <h4>CEPs para teste:</h4>
            <button class="cep-btn com-mercado" onclick="setarCep('35010-000')">35010-000 (Gov. Valadares)</button>
            <button class="cep-btn com-mercado" onclick="setarCep('01310-100')">01310-100 (Sao Paulo)</button>
            <button class="cep-btn com-mercado" onclick="setarCep('30130-000')">30130-000 (Belo Horizonte)</button>
            <button class="cep-btn sem-mercado" onclick="setarCep('22041-080')">22041-080 (Rio de Janeiro)</button>
            <button class="cep-btn sem-mercado" onclick="setarCep('69020-030')">69020-030 (Manaus)</button>
            <button class="cep-btn sem-mercado" onclick="setarCep('90010-150')">90010-150 (Porto Alegre)</button>
        </div>
    </div>

    <!-- Incluir componente de Waitlist -->
    <?php include __DIR__ . '/components/waitlist-modal.php'; ?>

    <script>
        // Mascara de CEP
        document.getElementById('cep').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 5) {
                value = value.substring(0, 5) + '-' + value.substring(5, 8);
            }
            e.target.value = value;
        });

        function setarCep(cep) {
            document.getElementById('cep').value = cep;
            verificarCep(new Event('submit'));
        }

        async function verificarCep(e) {
            e.preventDefault();

            const cep = document.getElementById('cep').value.replace(/\D/g, '');
            const resultDiv = document.getElementById('result');

            if (cep.length !== 8) {
                alert('Por favor, digite um CEP valido');
                return;
            }

            // Usar a funcao do componente waitlist
            const resultado = await verificarCepComWaitlist(cep);

            if (resultado.disponivel) {
                // Tem mercado!
                resultDiv.className = 'result success';
                document.getElementById('resultTitle').textContent = 'Otimo! Atendemos sua regiao!';
                document.getElementById('resultMessage').textContent =
                    `Mercado: ${resultado.data.mercado.nome} - ${resultado.data.mercado.distancia_km}km de distancia`;
            }
            // Se nao tem mercado, o modal ja foi aberto automaticamente pelo componente
        }
    </script>
</body>
</html>
