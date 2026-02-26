<?php
/**
 * Cadastro Multi-Step - Trabalhe Conosco
 * Shopper / Driver / Full Service
 */

$tipo = $_GET['tipo'] ?? 'shopper';
$tipoConfig = [
    'shopper' => ['nome' => 'Shopper', 'cor' => '#10b981', 'icon' => '🛒', 'desc' => 'Faça compras nos supermercados'],
    'driver' => ['nome' => 'Entregador', 'cor' => '#f97316', 'icon' => '🚴', 'desc' => 'Entregue pedidos aos clientes'],
    'full' => ['nome' => 'Full Service', 'cor' => '#8b5cf6', 'icon' => '⭐', 'desc' => 'Faça compras + entregas'],
];
$config = $tipoConfig[$tipo] ?? $tipoConfig['shopper'];
$needsVehicle = in_array($tipo, ['driver', 'full']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro <?= $config['nome'] ?> - OneMundo</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: <?= $config['cor'] ?>;
            --dark: #0f172a;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--light);
            min-height: 100vh;
        }
        
        .header {
            background: var(--dark);
            padding: 20px;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header a {
            color: #fff;
            text-decoration: none;
            font-size: 1.3rem;
        }
        
        .header h1 {
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-left: auto;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        /* Progress */
        .progress {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            position: relative;
        }
        
        .progress::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--border);
            z-index: 0;
        }
        
        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 1;
        }
        
        .progress-step .number {
            width: 40px;
            height: 40px;
            background: #fff;
            border: 3px solid var(--border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--gray);
            transition: all 0.3s;
        }
        
        .progress-step.active .number,
        .progress-step.completed .number {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        
        .progress-step .label {
            font-size: 0.75rem;
            color: var(--gray);
            text-align: center;
        }
        
        .progress-step.active .label {
            color: var(--primary);
            font-weight: 600;
        }
        
        /* Form */
        .form-step {
            display: none;
            animation: fadeIn 0.3s;
        }
        
        .form-step.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .card {
            background: #fff;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .card h2 {
            font-size: 1.3rem;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.95rem;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        /* Upload */
        .upload-area {
            border: 2px dashed var(--border);
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #fafafa;
        }
        
        .upload-area:hover {
            border-color: var(--primary);
            background: rgba(16, 185, 129, 0.05);
        }
        
        .upload-area .icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .upload-area p {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .upload-area input {
            display: none;
        }
        
        .upload-preview {
            display: none;
            margin-top: 15px;
        }
        
        .upload-preview img {
            max-width: 200px;
            max-height: 150px;
            border-radius: 10px;
        }
        
        .upload-preview.show {
            display: block;
        }
        
        /* Vehicle Options */
        .vehicle-options {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }
        
        .vehicle-option {
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 15px 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .vehicle-option:hover {
            border-color: var(--primary);
        }
        
        .vehicle-option.selected {
            border-color: var(--primary);
            background: rgba(16, 185, 129, 0.1);
        }
        
        .vehicle-option input {
            display: none;
        }
        
        .vehicle-option .icon {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }
        
        .vehicle-option .name {
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        /* Buttons */
        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            flex: 1;
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
        }
        
        .btn-primary {
            background: var(--primary);
            color: #fff;
        }
        
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #fff;
            color: var(--dark);
            border: 2px solid var(--border);
        }
        
        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
            margin-bottom: 15px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 22px;
            height: 22px;
            margin-top: 2px;
            accent-color: var(--primary);
        }
        
        .checkbox-group label {
            font-size: 0.9rem;
            color: var(--dark);
            line-height: 1.5;
        }
        
        .checkbox-group a {
            color: var(--primary);
        }
        
        /* Success */
        .success-message {
            text-align: center;
            padding: 40px 20px;
        }
        
        .success-message .icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }
        
        .success-message h2 {
            color: var(--primary);
            margin-bottom: 15px;
        }
        
        .success-message p {
            color: var(--gray);
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        /* Info box */
        .info-box {
            background: rgba(59, 130, 246, 0.1);
            border-left: 4px solid #3b82f6;
            padding: 15px;
            border-radius: 0 12px 12px 0;
            margin-bottom: 20px;
            font-size: 0.9rem;
            color: #1e40af;
        }
        
        @media (max-width: 500px) {
            .form-row { grid-template-columns: 1fr; }
            .vehicle-options { grid-template-columns: repeat(2, 1fr); }
            .btn-group { flex-direction: column; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <a href="index.php">←</a>
        <h1>Cadastro de Parceiro</h1>
        <span class="type-badge"><?= $config['icon'] ?> <?= $config['nome'] ?></span>
    </header>
    
    <div class="container">
        <!-- Progress -->
        <div class="progress">
            <div class="progress-step active" data-step="1">
                <div class="number">1</div>
                <div class="label">Dados Pessoais</div>
            </div>
            <div class="progress-step" data-step="2">
                <div class="number">2</div>
                <div class="label">Documentos</div>
            </div>
            <?php if ($needsVehicle): ?>
            <div class="progress-step" data-step="3">
                <div class="number">3</div>
                <div class="label">Veículo</div>
            </div>
            <div class="progress-step" data-step="4">
                <div class="number">4</div>
                <div class="label">Confirmar</div>
            </div>
            <?php else: ?>
            <div class="progress-step" data-step="3">
                <div class="number">3</div>
                <div class="label">Confirmar</div>
            </div>
            <?php endif; ?>
        </div>
        
        <form id="cadastroForm" enctype="multipart/form-data">
            <input type="hidden" name="tipo" value="<?= $tipo ?>">
            
            <!-- Step 1: Dados Pessoais -->
            <div class="form-step active" data-step="1">
                <div class="card">
                    <h2>👤 Dados Pessoais</h2>
                    
                    <div class="form-group">
                        <label>Nome Completo *</label>
                        <input type="text" name="name" required placeholder="Digite seu nome completo">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>CPF *</label>
                            <input type="text" name="cpf" required placeholder="000.000.000-00" maxlength="14">
                        </div>
                        <div class="form-group">
                            <label>Data de Nascimento *</label>
                            <input type="date" name="birth_date" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>E-mail *</label>
                        <input type="email" name="email" required placeholder="seu@email.com">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Celular/WhatsApp *</label>
                            <input type="tel" name="phone" required placeholder="(00) 00000-0000">
                        </div>
                        <div class="form-group">
                            <label>CEP *</label>
                            <input type="text" name="cep" required placeholder="00000-000" maxlength="9">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Endereço Completo *</label>
                        <input type="text" name="address" required placeholder="Rua, número, bairro, cidade">
                    </div>
                    
                    <div class="form-group">
                        <label>Senha *</label>
                        <input type="password" name="password" required placeholder="Mínimo 6 caracteres" minlength="6">
                    </div>
                </div>
                
                <div class="btn-group">
                    <a href="index.php" class="btn btn-secondary">Voltar</a>
                    <button type="button" class="btn btn-primary" onclick="nextStep()">Próximo →</button>
                </div>
            </div>
            
            <!-- Step 2: Documentos -->
            <div class="form-step" data-step="2">
                <div class="card">
                    <h2>📄 Documentos</h2>
                    
                    <div class="info-box">
                        📌 Envie fotos legíveis dos documentos. Arquivos aceitos: JPG, PNG, PDF (máx. 5MB)
                    </div>
                    
                    <div class="form-group">
                        <label>Foto do RG ou CNH (Frente) *</label>
                        <div class="upload-area" onclick="this.querySelector('input').click()">
                            <div class="icon">📎</div>
                            <p>Clique para enviar</p>
                            <input type="file" name="doc_frente" accept="image/*,.pdf" required>
                        </div>
                        <div class="upload-preview" id="preview_doc_frente"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Foto do RG ou CNH (Verso) *</label>
                        <div class="upload-area" onclick="this.querySelector('input').click()">
                            <div class="icon">📎</div>
                            <p>Clique para enviar</p>
                            <input type="file" name="doc_verso" accept="image/*,.pdf" required>
                        </div>
                        <div class="upload-preview" id="preview_doc_verso"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Selfie segurando o documento *</label>
                        <div class="upload-area" onclick="this.querySelector('input').click()">
                            <div class="icon">🤳</div>
                            <p>Tire uma selfie segurando seu RG/CNH</p>
                            <input type="file" name="selfie" accept="image/*" required>
                        </div>
                        <div class="upload-preview" id="preview_selfie"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Comprovante de Residência *</label>
                        <div class="upload-area" onclick="this.querySelector('input').click()">
                            <div class="icon">🏠</div>
                            <p>Conta de luz, água ou internet (últimos 3 meses)</p>
                            <input type="file" name="comprovante" accept="image/*,.pdf" required>
                        </div>
                        <div class="upload-preview" id="preview_comprovante"></div>
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="button" class="btn btn-secondary" onclick="prevStep()">← Voltar</button>
                    <button type="button" class="btn btn-primary" onclick="nextStep()">Próximo →</button>
                </div>
            </div>
            
            <?php if ($needsVehicle): ?>
            <!-- Step 3: Veículo -->
            <div class="form-step" data-step="3">
                <div class="card">
                    <h2>🚗 Veículo</h2>
                    
                    <div class="form-group">
                        <label>Tipo de Veículo *</label>
                        <div class="vehicle-options">
                            <label class="vehicle-option">
                                <input type="radio" name="vehicle_type" value="bike" required>
                                <div class="icon">🚲</div>
                                <div class="name">Bicicleta</div>
                            </label>
                            <label class="vehicle-option">
                                <input type="radio" name="vehicle_type" value="moto">
                                <div class="icon">🏍️</div>
                                <div class="name">Moto</div>
                            </label>
                            <label class="vehicle-option">
                                <input type="radio" name="vehicle_type" value="carro">
                                <div class="icon">🚗</div>
                                <div class="name">Carro</div>
                            </label>
                            <label class="vehicle-option">
                                <input type="radio" name="vehicle_type" value="van">
                                <div class="icon">🚐</div>
                                <div class="name">Van</div>
                            </label>
                        </div>
                    </div>
                    
                    <div id="vehicleDetails" style="display: none;">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Placa</label>
                                <input type="text" name="plate" placeholder="ABC-1234" maxlength="8">
                            </div>
                            <div class="form-group">
                                <label>Modelo</label>
                                <input type="text" name="model" placeholder="Ex: Honda CG 160">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Foto do CRLV</label>
                            <div class="upload-area" onclick="this.querySelector('input').click()">
                                <div class="icon">📄</div>
                                <p>Documento do veículo</p>
                                <input type="file" name="crlv" accept="image/*,.pdf">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="button" class="btn btn-secondary" onclick="prevStep()">← Voltar</button>
                    <button type="button" class="btn btn-primary" onclick="nextStep()">Próximo →</button>
                </div>
            </div>
            
            <!-- Step 4: Confirmar -->
            <div class="form-step" data-step="4">
            <?php else: ?>
            <!-- Step 3: Confirmar -->
            <div class="form-step" data-step="3">
            <?php endif; ?>
                <div class="card">
                    <h2>✅ Confirmação</h2>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="terms" required>
                        <label for="terms">Li e aceito os <a href="#">Termos de Uso</a> e a <a href="#">Política de Privacidade</a> da OneMundo.</label>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="mei" name="is_mei">
                        <label for="mei">Possuo MEI ativo (Microempreendedor Individual). Se sim, enviarei o comprovante posteriormente.</label>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="background" required>
                        <label for="background">Autorizo a verificação de antecedentes para fins de segurança.</label>
                    </div>
                    
                    <div class="info-box">
                        📌 Após o envio, nossa equipe analisará seu cadastro em até 48 horas. Você receberá um e-mail com o resultado.
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="button" class="btn btn-secondary" onclick="prevStep()">← Voltar</button>
                    <button type="submit" class="btn btn-primary">Enviar Cadastro ✓</button>
                </div>
            </div>
            
            <!-- Success -->
            <div class="form-step" data-step="success">
                <div class="card">
                    <div class="success-message">
                        <div class="icon">🎉</div>
                        <h2>Cadastro Enviado!</h2>
                        <p>Obrigado por se cadastrar como <strong><?= $config['nome'] ?></strong> na OneMundo!<br><br>Nossa equipe de RH irá analisar seus documentos e você receberá uma resposta em até 48 horas no e-mail cadastrado.</p>
                        <a href="index.php" class="btn btn-primary">Voltar ao início</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        let currentStep = 1;
        const totalSteps = <?= $needsVehicle ? 4 : 3 ?>;
        
        function updateProgress() {
            document.querySelectorAll('.progress-step').forEach((step, i) => {
                const stepNum = i + 1;
                step.classList.remove('active', 'completed');
                if (stepNum < currentStep) step.classList.add('completed');
                if (stepNum === currentStep) step.classList.add('active');
            });
            
            document.querySelectorAll('.form-step').forEach(step => {
                step.classList.remove('active');
            });
            document.querySelector(`.form-step[data-step="${currentStep}"]`).classList.add('active');
        }
        
        function nextStep() {
            if (currentStep < totalSteps) {
                currentStep++;
                updateProgress();
                window.scrollTo(0, 0);
            }
        }
        
        function prevStep() {
            if (currentStep > 1) {
                currentStep--;
                updateProgress();
                window.scrollTo(0, 0);
            }
        }
        
        // Vehicle type selection
        document.querySelectorAll('.vehicle-option').forEach(opt => {
            opt.addEventListener('click', function() {
                document.querySelectorAll('.vehicle-option').forEach(o => o.classList.remove('selected'));
                this.classList.add('selected');
                
                const type = this.querySelector('input').value;
                const details = document.getElementById('vehicleDetails');
                if (details) {
                    details.style.display = (type !== 'bike') ? 'block' : 'none';
                }
            });
        });
        
        // File upload preview
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function() {
                const preview = document.getElementById('preview_' + this.name);
                if (preview && this.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        preview.innerHTML = '<img src="' + e.target.result + '">';
                        preview.classList.add('show');
                    };
                    reader.readAsDataURL(this.files[0]);
                    
                    // Update upload area text
                    this.parentElement.querySelector('p').textContent = '✓ ' + this.files[0].name;
                }
            });
        });
        
        // CPF mask
        document.querySelector('input[name="cpf"]').addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '');
            v = v.replace(/(\d{3})(\d)/, '$1.$2');
            v = v.replace(/(\d{3})(\d)/, '$1.$2');
            v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            e.target.value = v;
        });
        
        // Phone mask
        document.querySelector('input[name="phone"]').addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '');
            v = v.replace(/^(\d{2})(\d)/g, '($1) $2');
            v = v.replace(/(\d{5})(\d)/, '$1-$2');
            e.target.value = v;
        });
        
        // CEP mask
        document.querySelector('input[name="cep"]').addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '');
            v = v.replace(/(\d{5})(\d)/, '$1-$2');
            e.target.value = v;
        });
        
        // Form submit
        document.getElementById('cadastroForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Enviando...';
            
            const formData = new FormData(this);
            
            try {
                const response = await fetch('../api/worker-register.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    document.querySelectorAll('.form-step').forEach(step => step.classList.remove('active'));
                    document.querySelector('.form-step[data-step="success"]').classList.add('active');
                    document.querySelector('.progress').style.display = 'none';
                } else {
                    alert('Erro: ' + result.message);
                    btn.disabled = false;
                    btn.textContent = 'Enviar Cadastro ✓';
                }
            } catch (error) {
                alert('Erro ao enviar. Tente novamente.');
                btn.disabled = false;
                btn.textContent = 'Enviar Cadastro ✓';
            }
        });
    </script>
</body>
</html>
