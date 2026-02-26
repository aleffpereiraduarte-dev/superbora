#!/usr/bin/env python3
"""
DIAGNÓSTICO COMPLETO - Entender onde está o gargalo
"""

import os
import requests
import time
from urllib.parse import quote
from concurrent.futures import ThreadPoolExecutor, as_completed
import mysql.connector

BYPASS_URL = 'http://localhost:8000'

DB_CONFIG = {
    'host': 'localhost',
    'user': 'root', 
    'password': os.environ.get('DB_PASS', ''),
    'database': 'love1'
}

print("🔍 DIAGNÓSTICO COMPLETO DO ENRIQUECEDOR")
print("=" * 60)

# 1. STATUS DO BANCO
print("\n📊 1. STATUS DO BANCO DE DADOS")
print("-" * 40)
conn = mysql.connector.connect(**DB_CONFIG)
cursor = conn.cursor()

cursor.execute("SELECT COUNT(*) FROM om_market_products_base")
total = cursor.fetchone()[0]

cursor.execute("""SELECT COUNT(*) FROM om_market_products_base 
    WHERE suggested_price > 0 AND description IS NOT NULL AND description != ''""")
processados = cursor.fetchone()[0]

cursor.execute("""SELECT COUNT(*) FROM om_market_products_base 
    WHERE suggested_price IS NULL OR suggested_price = 0 
    OR description IS NULL OR description = ''""")
pendentes = cursor.fetchone()[0]

cursor.execute("""SELECT COUNT(*) FROM om_market_products_base 
    WHERE barcode IS NOT NULL AND LENGTH(barcode) >= 8""")
com_ean = cursor.fetchone()[0]

cursor.execute("""SELECT COUNT(*) FROM om_market_products_base 
    WHERE barcode IS NULL OR LENGTH(barcode) < 8""")
sem_ean = cursor.fetchone()[0]

print(f"   Total produtos:     {total:,}")
print(f"   ✅ Processados:     {processados:,} ({100*processados/total:.1f}%)")
print(f"   ⏳ Pendentes:       {pendentes:,} ({100*pendentes/total:.1f}%)")
print(f"   🏷️ Com EAN válido:  {com_ean:,}")
print(f"   ❌ Sem EAN:         {sem_ean:,}")

# 2. TESTE DO BYPASS - VELOCIDADE
print("\n⚡ 2. TESTE DE VELOCIDADE DO BYPASS")
print("-" * 40)

def testar_bypass(url, nome):
    inicio = time.time()
    try:
        r = requests.get(f"{BYPASS_URL}/html?url={quote(url, safe='')}", timeout=120)
        tempo = time.time() - inicio
        sucesso = r.status_code == 200 and 'Access denied' not in r.text and 'Attention' not in r.text
        tamanho = len(r.text)
        return (nome, tempo, sucesso, tamanho)
    except Exception as e:
        return (nome, time.time() - inicio, False, 0)

testes = [
    ("https://cosmos.bluesoft.com.br/produtos/7891000100103", "Cosmos EAN"),
    ("https://cosmos.bluesoft.com.br/pesquisar?q=leite", "Cosmos Busca"),
    ("https://www.google.com/search?q=leite+ninho+EAN", "Google"),
]

for url, nome in testes:
    resultado = testar_bypass(url, nome)
    status = "✅" if resultado[2] else "❌"
    print(f"   {status} {nome}: {resultado[1]:.1f}s ({resultado[3]:,} bytes)")

# 3. TESTE DE CONCORRÊNCIA
print("\n🔄 3. TESTE DE CONCORRÊNCIA (5 requests simultâneos)")
print("-" * 40)

def fazer_request(i):
    url = f"https://cosmos.bluesoft.com.br/produtos/789100010010{i}"
    inicio = time.time()
    try:
        r = requests.get(f"{BYPASS_URL}/html?url={quote(url, safe='')}", timeout=120)
        tempo = time.time() - inicio
        ok = r.status_code == 200 and 'GTIN' in r.text
        return (i, tempo, ok)
    except:
        return (i, time.time() - inicio, False)

inicio_total = time.time()
resultados = []

with ThreadPoolExecutor(max_workers=5) as executor:
    futures = [executor.submit(fazer_request, i) for i in range(5)]
    for future in as_completed(futures):
        resultados.append(future.result())

tempo_total = time.time() - inicio_total
sucessos = sum(1 for r in resultados if r[2])
tempos = [r[1] for r in resultados]

print(f"   Sucessos: {sucessos}/5")
print(f"   Tempo total: {tempo_total:.1f}s")
print(f"   Média por request: {sum(tempos)/len(tempos):.1f}s")
print(f"   Throughput: {5/tempo_total:.2f} req/s")

# 4. AMOSTRA DE PRODUTOS PENDENTES
print("\n📦 4. AMOSTRA DE 10 PRODUTOS PENDENTES")
print("-" * 40)

cursor.execute("""
    SELECT product_id, name, barcode 
    FROM om_market_products_base 
    WHERE (suggested_price IS NULL OR suggested_price = 0)
    ORDER BY RAND() LIMIT 10
""")

for row in cursor.fetchall():
    ean_status = f"EAN: {row[2]}" if row[2] and len(str(row[2])) >= 8 else "❌ SEM EAN"
    print(f"   #{row[0]} {row[1][:35]} [{ean_status}]")

# 5. ANÁLISE DO LOG
print("\n📋 5. ANÁLISE DO LOG (últimas 200 linhas)")
print("-" * 40)

try:
    with open('/var/log/enriquecedor.log', 'r') as f:
        linhas = f.readlines()[-200:]
    
    sucessos = sum(1 for l in linhas if '✅' in l)
    deletados = sum(1 for l in linhas if '🗑️' in l)
    erros = sum(1 for l in linhas if '❌' in l or 'ERRO' in l)
    header1 = sum(1 for l in linhas if 'Header 1' in l)
    
    cosmos_ean = sum(1 for l in linhas if '[cosmos_ean]' in l)
    cosmos_nome = sum(1 for l in linhas if '[cosmos_nome]' in l)
    atacadao = sum(1 for l in linhas if '[atacadao]' in l)
    google = sum(1 for l in linhas if '[google_ean]' in l)
    
    print(f"   ✅ Sucessos:      {sucessos}")
    print(f"   🗑️ Deletados:    {deletados}")
    print(f"   ❌ Erros:        {erros}")
    print(f"   ⚠️ Header 1:     {header1}")
    print(f"")
    print(f"   Por fonte:")
    print(f"      Cosmos EAN:   {cosmos_ean}")
    print(f"      Cosmos Nome:  {cosmos_nome}")
    print(f"      Atacadão:     {atacadao}")
    print(f"      Google→EAN:   {google}")
except:
    print("   ⚠️ Log não encontrado ou vazio")

# 6. CONCLUSÃO
print("\n" + "=" * 60)
print("📊 CONCLUSÃO")
print("=" * 60)

if sucessos > erros * 2:
    print("✅ Sistema funcionando bem!")
else:
    print("⚠️ Muitos erros - pode ser problema de conexão ou Cloudflare")

throughput_estimado = (sucessos / 200) * 60 * 10  # por hora com 10 workers
horas_restantes = pendentes / max(throughput_estimado, 1)
dias_restantes = horas_restantes / 24

print(f"\n📈 Estimativa:")
print(f"   Throughput: ~{throughput_estimado:.0f} produtos/hora")
print(f"   Tempo restante: ~{dias_restantes:.1f} dias")

cursor.close()
conn.close()
