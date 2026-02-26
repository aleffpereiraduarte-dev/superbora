#!/bin/bash
for i in {1..50}; do
    php /var/www/html/mercado/cron_scraper.php >> /var/log/scraper.log 2>&1 &
done
wait
