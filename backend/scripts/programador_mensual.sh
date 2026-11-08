#!/bin/sh
# Programador del contenedor "cron" (ver docker-compose.yml): ejecuta
# scripts/cron.php una vez al mes.
#
# Cada hora comprueba si ya se ha ejecutado en el mes actual; si no, lo ejecuta.
# Así, si el equipo está apagado el día 1, la tarea se hace en cuanto vuelve a
# arrancar. El mes de la última ejecución correcta se guarda en un volumen; si
# se perdiera no pasa nada, porque cron.php no repite avisos ya enviados.
#
# Ver la salida: docker compose logs -f cron

MARCA=/var/lib/gastos-cron/ultimo_mes
mkdir -p "$(dirname "$MARCA")"

# Parar enseguida con "docker compose stop" en lugar de esperar al SIGKILL
trap 'exit 0' TERM INT

esperar() {
    sleep "$1" &
    wait $!
}

# Dar tiempo a que MySQL termine de arrancar
esperar 60

while true; do
    MES=$(date +%Y-%m)
    if [ "$(cat "$MARCA" 2>/dev/null)" != "$MES" ]; then
        if php /var/www/html/scripts/cron.php; then
            echo "$MES" > "$MARCA"
        else
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] cron.php ha fallado; se reintentará en una hora"
        fi
    fi
    esperar 3600
done
