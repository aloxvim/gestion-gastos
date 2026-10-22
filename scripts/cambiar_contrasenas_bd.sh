#!/usr/bin/env bash
# ============================================
# Cambia las contraseñas de MySQL (gastos_user y root) por unas aleatorias,
# las guarda en .env (DB_APP_PASSWORD y DB_ROOT_PASSWORD) y recrea los
# contenedores para que las usen. Los datos no se tocan.
#
# Uso, desde la carpeta del proyecto y con los contenedores en marcha:
#   ./scripts/cambiar_contrasenas_bd.sh
#
# Se puede volver a ejecutar cuando se quiera cambiar las contraseñas otra vez.
# Antes de modificar .env guarda una copia en .env.bak-FECHA.
# ============================================
set -euo pipefail
cd "$(dirname "$0")/.."

DB_CONTAINER=gastos_db
ENV_FILE=.env

info() { echo "==> $*"; }
fail() { echo "ERROR: $*" >&2; exit 1; }

# Valor de una clave de .env (vacío si no existe)
env_get() { grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- || true; }

# Crea o sustituye una clave de .env (los valores generados son hexadecimales,
# así que no hay caracteres que escapar)
env_set() {
    if grep -qE "^$1=" "$ENV_FILE"; then
        sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"
    else
        # .env puede no terminar en salto de línea
        [ -n "$(tail -c1 "$ENV_FILE")" ] && echo >> "$ENV_FILE"
        echo "$1=$2" >> "$ENV_FILE"
    fi
}

# Ejecuta SQL (leído de la entrada estándar) como root con la contraseña $1
mysql_root() { docker exec -i -e MYSQL_PWD="$1" "$DB_CONTAINER" mysql -uroot -N; }

# 40 caracteres hexadecimales (160 bits) sin depender de openssl
new_password() { od -An -tx1 -N20 /dev/urandom | tr -d ' \n'; }

# --------------------------------------------
# Comprobaciones previas
# --------------------------------------------
[ -f "$ENV_FILE" ] || fail "No existe $ENV_FILE en $(pwd)."
[ "$(docker inspect -f '{{.State.Running}}' "$DB_CONTAINER" 2>/dev/null)" = "true" ] \
    || fail "El contenedor $DB_CONTAINER no está en marcha."

# Contraseña actual de root: la de .env si ya se ejecutó este script antes,
# o la de fábrica que tenía docker-compose.yml
ROOT_ACTUAL=""
for candidata in "$(env_get DB_ROOT_PASSWORD)" "rootpassword"; do
    if [ -n "$candidata" ] && echo "SELECT 1" | mysql_root "$candidata" >/dev/null 2>&1; then
        ROOT_ACTUAL="$candidata"
        break
    fi
done
if [ -z "$ROOT_ACTUAL" ]; then
    read -rsp "No se pudo entrar como root. Escribe la contraseña actual de root de MySQL: " ROOT_ACTUAL
    echo
    echo "SELECT 1" | mysql_root "$ROOT_ACTUAL" >/dev/null 2>&1 || fail "Contraseña de root incorrecta. No se ha cambiado nada."
fi
info "Acceso como root comprobado."

BACKUP="$ENV_FILE.bak-$(date +%Y%m%d-%H%M%S)"
cp -p "$ENV_FILE" "$BACKUP"
chmod 600 "$BACKUP"
info "Copia de .env guardada en $BACKUP"

# --------------------------------------------
# Cambio de contraseñas (cada una se guarda en .env en cuanto MySQL la acepta,
# para que .env y MySQL nunca queden desincronizados)
# --------------------------------------------
NUEVA_APP="$(new_password)"
NUEVA_ROOT="$(new_password)"

echo "ALTER USER 'gastos_user'@'%' IDENTIFIED BY '$NUEVA_APP';" | mysql_root "$ROOT_ACTUAL" \
    || fail "MySQL no aceptó el cambio de gastos_user. No se ha cambiado nada."
env_set DB_APP_PASSWORD "$NUEVA_APP"
info "Contraseña de gastos_user cambiada y guardada en .env (DB_APP_PASSWORD)."

echo "ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY '$NUEVA_ROOT', 'root'@'localhost' IDENTIFIED BY '$NUEVA_ROOT';" \
    | mysql_root "$ROOT_ACTUAL" \
    || fail "MySQL no aceptó el cambio de root. gastos_user ya tiene la contraseña nueva (en .env); root sigue con la anterior."
env_set DB_ROOT_PASSWORD "$NUEVA_ROOT"
info "Contraseña de root cambiada y guardada en .env (DB_ROOT_PASSWORD)."

# DB_PASS ya no se usa (docker-compose.yml lee DB_APP_PASSWORD); quitarlo evita confusiones
sed -i '/^DB_PASS=/d' "$ENV_FILE"
chmod 600 "$ENV_FILE"

# --------------------------------------------
# Recrear contenedores con las contraseñas nuevas y comprobar
# --------------------------------------------
info "Recreando los contenedores con la configuración nueva..."
docker compose up -d

info "Esperando a que MySQL acepte la contraseña nueva..."
for _ in $(seq 1 60); do
    if docker exec -e MYSQL_PWD="$NUEVA_APP" "$DB_CONTAINER" mysql -ugastos_user -e "SELECT 1" gastos_db >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
docker exec -e MYSQL_PWD="$NUEVA_APP" "$DB_CONTAINER" mysql -ugastos_user -e "SELECT 1" gastos_db >/dev/null 2>&1 \
    || fail "MySQL no responde con la contraseña nueva. Revisa 'docker compose logs db'."

# Por si el frontend se construyó con un nginx.conf antiguo, que no vuelve a
# resolver la IP del backend recreado (daría 502)
docker compose restart frontend >/dev/null 2>&1 || true

info "Comprobando que la app conecta con la base de datos..."
APP_OK=""
for _ in $(seq 1 30); do
    # A través del frontend (puerto 3000), igual que los usuarios
    if curl -fs http://localhost:3000/api/tags 2>/dev/null | grep -q '"success":true'; then
        APP_OK=1
        break
    fi
    sleep 1
done
[ -n "$APP_OK" ] || fail "La app no responde correctamente. Revisa 'docker compose logs backend frontend'."

echo
info "Todo correcto. Las contraseñas están en .env (que no se sube a GitHub)."
echo "    Para entrar en phpMyAdmin (puerto 8081):"
echo "      Usuario:    gastos_user"
echo "      Contraseña: $NUEVA_APP"
echo "    Guárdala en tu gestor de contraseñas. Puedes volver a verla con:"
echo "      grep DB_APP_PASSWORD .env"
