#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# ONE PREMIUM FIX - Corrige TODAS as cores de verde para preto
# ═══════════════════════════════════════════════════════════════

FILE="/var/www/html/mercado/one.php"
BACKUP="/var/www/html/mercado/one.php.backup_$(date +%Y%m%d_%H%M%S)"

echo "═══════════════════════════════════════════════════════════════"
echo "🔧 ONE PREMIUM FIX - Corrigindo cores"
echo "═══════════════════════════════════════════════════════════════"

# Verifica se arquivo existe
if [ ! -f "$FILE" ]; then
    echo "❌ Arquivo $FILE não encontrado!"
    exit 1
fi

# Conta cores verdes antes
ANTES=$(grep -c "#10b981\|#059669\|#34d399\|#06d6a0" "$FILE")
echo "📊 Cores verdes encontradas: $ANTES"

# Faz backup
cp "$FILE" "$BACKUP"
echo "✅ Backup criado: $BACKUP"

# Substitui TODAS as cores verdes por preto/cinza
# #10b981 (verde primário) -> #000000 (preto)
sed -i 's/#10b981/#000000/g' "$FILE"

# #059669 (verde escuro) -> #171717 (preto escuro)
sed -i 's/#059669/#171717/g' "$FILE"

# #34d399 (verde claro) -> #404040 (cinza escuro)
sed -i 's/#34d399/#404040/g' "$FILE"

# #06d6a0 (verde accent) -> #000000 (preto)
sed -i 's/#06d6a0/#000000/g' "$FILE"

# rgba(16, 185, 129 -> rgba(0, 0, 0 (verde em rgba)
sed -i 's/rgba(16, 185, 129/rgba(0, 0, 0/g' "$FILE"
sed -i 's/rgba(16,185,129/rgba(0,0,0/g' "$FILE"

# Conta cores verdes depois
DEPOIS=$(grep -c "#10b981\|#059669\|#34d399\|#06d6a0" "$FILE")

echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "✅ CORREÇÃO COMPLETA!"
echo "═══════════════════════════════════════════════════════════════"
echo "📊 Cores verdes antes: $ANTES"
echo "📊 Cores verdes depois: $DEPOIS"
echo ""
echo "🌐 Teste: https://onemundo.com.br/mercado/one.php"
echo "   (Ctrl+Shift+R para forçar reload)"
echo ""
echo "🔙 Para reverter: cp $BACKUP $FILE"
echo "═══════════════════════════════════════════════════════════════"
