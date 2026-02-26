#!/bin/bash
# Roda 5 processos em paralelo

php /var/www/html/mercado/cron_ean_turbo.php 0 &
php /var/www/html/mercado/cron_ean_turbo.php 1 &
php /var/www/html/mercado/cron_ean_turbo.php 2 &
php /var/www/html/mercado/cron_ean_turbo.php 3 &
php /var/www/html/mercado/cron_ean_turbo.php 4 &
wait

echo "=== LOTE $(date +%H:%M:%S) ==="
