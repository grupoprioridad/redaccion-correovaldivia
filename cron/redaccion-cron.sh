#!/bin/bash
# Cron unificado de Redacción · El Correo de Valdivia
set -e
LOG="/var/log/redaccion-cron.log"
BASE="/var/www/redaccion-correovaldivia"
HORA=$(date +%H)
FECHA=$(date '+%Y-%m-%d %H:%M')

echo "[$FECHA] === Cron Unificado ===" >> "$LOG"

# 1. Recordatorio mitad de plazo (10:00)
if [ "$HORA" = "10" ]; then
    echo "[$FECHA] recordatorio-mitad..." >> "$LOG"
    php "$BASE/cron/recordatorio-mitad.php" >> "$LOG" 2>&1
fi

# 2. Recordatorio vencimiento (09:00)
if [ "$HORA" = "09" ]; then
    echo "[$FECHA] recordatorio-vencimiento..." >> "$LOG"
    php "$BASE/cron/recordatorio-vencimiento.php" >> "$LOG" 2>&1
fi

# 3. Recordatorios post-vencimiento (08:00)
if [ "$HORA" = "08" ]; then
    echo "[$FECHA] recordatorio-vencido..." >> "$LOG"
    php "$BASE/cron/recordatorio-vencido.php" >> "$LOG" 2>&1
fi

# 4. Scraper cada 6h (03,09,15,21)
if [ "$HORA" = "03" ] || [ "$HORA" = "09" ] || [ "$HORA" = "15" ] || [ "$HORA" = "21" ]; then
    echo "[$FECHA] scraper_medios..." >> "$LOG"
    cd "$BASE/scripts" && SCRAPER_DATA_DIR="$BASE/datos" python3 scraper_medios.py >> "$LOG" 2>&1
fi

echo "[$FECHA] === Fin ===" >> "$LOG"
