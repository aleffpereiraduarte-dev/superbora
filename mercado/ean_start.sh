#!/bin/bash
for i in {1..20}; do
    php /var/www/html/mercado/cron_ean.php >> /var/log/ean.log 2>&1 &
done
wait
