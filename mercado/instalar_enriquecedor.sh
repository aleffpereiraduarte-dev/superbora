#!/bin/bash
echo "🚀 Instalando sistema de enriquecimento..."

# Instalar Python e pip
apt-get update
apt-get install -y python3 python3-pip python3-venv

# Criar ambiente virtual
cd /var/www/html/mercado
python3 -m venv enriquecedor_env
source enriquecedor_env/bin/activate

# Instalar dependências
pip install playwright mysql-connector-python requests fake-useragent openai

# Instalar browsers do Playwright
playwright install chromium
playwright install-deps

echo "✅ Instalação concluída!"
