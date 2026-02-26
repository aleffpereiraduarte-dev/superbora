#!/bin/bash
cd /var/www/html/mercado
source enriquecedor_env/bin/activate

echo "🚀 INICIANDO v22 - $(date)"

while true; do
    python3 enriquecedor_v22.py
    
    PENDENTES=$(mysql -u root --defaults-file=/root/.my.cnf love1 -N -e "
        SELECT COUNT(*) FROM om_market_products_base 
        WHERE suggested_price IS NULL OR description IS NULL" 2>/dev/null)
    
    echo "📊 Restam: $PENDENTES - $(date '+%H:%M')"
    
    [ "$PENDENTES" = "0" ] && echo "🎉 CONCLUÍDO!" && break
    
    sleep 3
done
