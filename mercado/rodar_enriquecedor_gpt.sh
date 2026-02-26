#!/bin/bash
LOCKFILE="/tmp/enriquecedor_gpt.lock"

if [ -f "$LOCKFILE" ]; then
    PID=$(cat "$LOCKFILE")
    if ps -p $PID > /dev/null 2>&1; then
        exit 0
    fi
fi

echo $$ > "$LOCKFILE"
python3 /var/www/html/mercado/enriquecedor_gpt_turbo.py >> /var/www/html/mercado/logs/enriquecedor_gpt.log 2>&1
rm -f "$LOCKFILE"
