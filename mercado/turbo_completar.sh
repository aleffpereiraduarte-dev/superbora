#!/bin/bash
# Rodar 5 instâncias em paralelo
for i in {1..5}; do
    curl -s "https://onemundo.com.br/mercado/cron_completar_v2.php" &
done
wait
