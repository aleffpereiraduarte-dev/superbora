#!/bin/bash
# CRON ENRIQUECIMENTO - 15 workers paralelos

LOG="/var/log/enriquecimento.log"
LOCK="/tmp/enriquecimento.lock"

if [ -f "$LOCK" ]; then
    PID=$(cat "$LOCK")
    if ps -p $PID > /dev/null 2>&1; then
        exit 0
    fi
fi

echo $$ > "$LOCK"
echo "$(date): Iniciando 15 workers..." >> $LOG

# 15 workers x 100 produtos = 1500 por rodada
for i in {0..14}; do
    php /var/www/html/mercado/cron_enriquece_rapido.php 100 $i >> $LOG 2>&1 &
done

wait
echo "$(date): Lote finalizado!" >> $LOG
rm -f "$LOCK"
