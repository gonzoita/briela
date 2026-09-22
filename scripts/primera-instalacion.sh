#!/usr/bin/env bash
#
# Convierte un clon nuevo de Briela en una instalación funcionando: dependencias,
# .env, base de datos, cachés. Es el primer paso de una instalación nueva; de ahí
# en adelante, scripts/traer-cambios.sh la mantiene al día con cada `git push`
# a main (ese sí corre solo, por el Cron Job que este script deja pendiente).
#
# Hecho a mano ANTES de correr esto, en el panel del hosting:
#   1. El dominio o subdominio ya apuntando a esta carpeta.
#   2. Una base de datos MySQL vacía y su usuario, creados desde el panel — en
#      hosting compartido no hay acceso a un usuario root de MySQL.
#   3. Esta carpeta ya es un clon del repositorio (`git clone`), con una llave
#      de SOLO LECTURA —nunca un token con permiso de escritura— agregada como
#      Deploy Key del repositorio en GitHub.
#
# No pide el serial de licencia: eso se entra después, ya con el sistema
# arriba, desde Ajustes → Licencia. Ver config/briela.php y LicenciaService.

set -euo pipefail
export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"

if [ ! -f artisan ]; then
    echo "No se encuentra 'artisan' en $RAIZ — ¿el repositorio se clonó bien aquí?"
    exit 1
fi

# Repetir esto sobre una instalación que ya existe duplicaría los usuarios de
# ejemplo y el catálogo de demostración. Para poner al día una instalación que
# ya corrió este script una vez, el comando es scripts/traer-cambios.sh.
if [ -f .env ] && grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "Ya hay un .env con APP_KEY generada: esta instalación parece existir."
    echo "Para actualizarla usa scripts/traer-cambios.sh, no este script."
    exit 1
fi

echo "── Dominio ──────────────────────────────────────────────────────────────"
read -rp "Dominio de esta instalación (ej. sistema.ejemplo.com, sin https://): " DOMINIO

echo "── Base de datos (ya creada en el panel del hosting) ─────────────────────"
read -rp  "Host de la base de datos [127.0.0.1]: " DB_HOST
DB_HOST="${DB_HOST:-127.0.0.1}"
read -rp  "Nombre de la base de datos: " DB_NOMBRE
read -rp  "Usuario de la base de datos: " DB_USUARIO
read -rsp "Contraseña de la base de datos: " DB_CLAVE
echo

# ─── .env ──────────────────────────────────────────────────────────────────────
[ -f .env ] || cp .env.example .env

# Una contraseña generada por el hosting trae casi siempre `&`, y a veces `/` o
# `\`: los tres son especiales dentro del reemplazo de un `sed`. Sin escaparlos,
# una clave con `&` mete de vuelta el texto que hizo matchear el patrón —una
# contraseña rota, a medias, y sin ningún error que lo avise.
escapar_sed() { printf '%s' "$1" | sed -e 's/[\/&\\]/\\&/g'; }

sed -i \
    -e "s|^APP_URL=.*|APP_URL=https://$(escapar_sed "$DOMINIO")|" \
    -e "s|^DB_HOST=.*|DB_HOST=$(escapar_sed "$DB_HOST")|" \
    -e "s|^DB_DATABASE=.*|DB_DATABASE=$(escapar_sed "$DB_NOMBRE")|" \
    -e "s|^DB_USERNAME=.*|DB_USERNAME=$(escapar_sed "$DB_USUARIO")|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(escapar_sed "$DB_CLAVE")|" \
    .env

# ─── Dependencias ──────────────────────────────────────────────────────────────
# Sin --dev: nada de lo que corre en el servidor de un cliente necesita las
# herramientas de desarrollo. Node y Vite no hacen falta — public/build/ ya
# viene compilado en el repositorio, por regla del proyecto.
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

php artisan key:generate --force

# ─── Base de datos ─────────────────────────────────────────────────────────────
# --seed carga los cuatro usuarios de ejemplo (admin@briela.app / password, y
# uno por rol) y un catálogo de demostración. Cambia esa contraseña apenas
# entres, y reemplaza el catálogo de ejemplo por el real del cliente desde
# Productos y Ensambles.
php artisan migrate --seed --force

php artisan permisos:sincronizar
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ─── Archivos subidos ───────────────────────────────────────────────────────────
# Enlace directo, no `storage:link`: en hosting compartido el comando de
# Artisan puede fallar si el hosting tiene exec() desactivado. Mismo enlace
# que usa scripts/traer-cambios.sh en cada actualización.
[ -e public/storage ] || ln -s ../storage/app/public public/storage

echo "dev-$(git rev-parse --short HEAD)" > version.txt

echo
echo "── Listo ────────────────────────────────────────────────────────────────"
echo "Entra a https://${DOMINIO}/login con admin@briela.app / password"
echo "y CAMBIA esa contraseña de una vez."
echo
echo "Falta, a mano en el panel del hosting:"
echo "  1. Un Cron Job cada pocos minutos con:"
echo "     bash ${RAIZ}/scripts/traer-cambios.sh ${RAIZ} main"
echo "  2. El serial de licencia, cuando lo tengas, en Ajustes → Licencia."
