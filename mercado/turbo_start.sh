#!/bin/bash
echo "🚀 Iniciando 30 workers paralelos..."
for i in {1..30}; do
    php /var/www/html/mercado/cron_turbo.php $i >> /var/log/central_ia.log 2>&1 &
done
echo "✅ 30 workers iniciados!"
wait
echo "🏁 Todos workers finalizados"
