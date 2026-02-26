#!/bin/bash
LOCKFILE="/tmp/buscador_ean.lock"

if [ -f "$LOCKFILE" ]; then
    PID=$(cat "$LOCKFILE")
    if ps -p $PID > /dev/null 2>&1; then
        exit 0
    fi
fi

echo $$ > "$LOCKFILE"
python3 /var/www/html/mercado/buscador_ean_turbo.py >> /var/www/html/mercado/logs/buscador_ean.log 2>&1
rm -f "$LOCKFILE"
