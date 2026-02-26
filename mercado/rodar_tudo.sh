#!/bin/bash
cd /var/www/html/mercado
source enriquecedor_env/bin/activate

echo "🚀 INICIANDO - $(date)"

while true; do
    python3 enriquecedor_turbo.py
    
    PENDENTES=$(mysql -u root --defaults-file=/root/.my.cnf love1 -N -e "
        SELECT COUNT(*) FROM om_market_products_base 
        WHERE (suggested_price IS NULL OR suggested_price = 0 
               OR description IS NULL OR description = '' 
               OR category_id IS NULL OR category_id = 0)" 2>/dev/null)
    
    echo "📊 Restam: $PENDENTES - $(date '+%H:%M')"
    
    [ "$PENDENTES" = "0" ] && echo "🎉 CONCLUÍDO!" && break
    
    sleep 5
done
