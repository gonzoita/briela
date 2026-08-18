#!/usr/bin/env bash
#
# Trae los cambios del repositorio y los aplica. Lo llama el cron cada pocos minutos.
#
# ¿Por qué el servidor jala en vez de que GitHub empuje?
#
# Porque Hostinger agota el tiempo de las conexiones SSH que vienen de los servidores de
# GitHub. Sostenidamente: la misma llave desde otra máquina entra sin problema, y desde
# GitHub falla dos veces seguidas. No es algo que se arregle con código nuestro.
#
# Al invertir la dirección, el servidor solo necesita salida a internet —que sí tiene— y
# no hay nada que bloquear. De paso desaparecen los cuatro secretos de GitHub y la llave
# de acceso al servidor: aquí solo vive una llave de LECTURA de un repositorio.
#
# No hace nada si no hay cambios, así que puede correr cada dos minutos sin costo.

set -euo pipefail

# El cron corre con un PATH mínimo: sin esto no encuentra php ni composer, y el
# despliegue falla en silencio — el peor modo de fallar, porque nadie se entera hasta
# que alguien nota que producción llevaba días atrasada.
export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

RAIZ="${1:?Falta la ruta de la instalación}"
RAMA="${2:-main}"

# El registro lo abre el propio script, y no se deja en manos de la redirección del
# cron: el campo del panel puede recortar la línea al pegarla, y entonces el despliegue
# funciona pero no queda rastro de nada. Sin rastro, el día que falle no hay por dónde
# empezar.
exec >> "${HOME}/despliegue.log" 2>&1

# Un candado por instalación. Dos despliegues simultáneos sobre la misma carpeta se
# pisan: uno reemplaza archivos mientras el otro migra. Ya pasó una vez —el automatismo
# de GitHub y este script desplegaron a la vez, con veinte segundos de diferencia— y
# salió bien de pura suerte.
exec 9> "/tmp/briela-despliegue-$(echo "$RAIZ" | md5sum | cut -c1-12).lock"

if ! flock -n 9; then
    echo "[$(date '+%F %T')] otro despliegue está en curso sobre $RAIZ: no se hace nada"
    exit 0
fi

cd "$RAIZ"

# ─── ¿Hay algo nuevo? ────────────────────────────────────────────────────────
git fetch --quiet origin "$RAMA"

LOCAL=$(git rev-parse HEAD)
REMOTO=$(git rev-parse "origin/$RAMA")

if [ "$LOCAL" = "$REMOTO" ]; then
    exit 0
fi

echo "[$(date '+%F %T')] cambios detectados: ${LOCAL:0:7} → ${REMOTO:0:7}"

# ─── Respaldo antes de tocar nada ────────────────────────────────────────────
# Obligatorio y primero. Si el respaldo falla, no se despliega: la regla 3 del
# proyecto existe porque ya hubo dos pérdidas totales de datos.
mkdir -p storage/app/backups
CNF=$(mktemp); chmod 600 "$CNF"
printf '[client]\nuser=%s\npassword=%s\n' \
    "$(grep '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"')" \
    "$(grep '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"')" > "$CNF"
BASE=$(grep '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"')

mysqldump --defaults-extra-file="$CNF" --single-transaction --default-character-set=utf8mb4 \
    "$BASE" > "storage/app/backups/deploy-$(date +%F-%H%M%S).sql"
rm -f "$CNF"

# Diez últimos: en un hosting compartido el disco y los inodos son finitos.
ls -1t storage/app/backups/deploy-*.sql 2>/dev/null | tail -n +11 | xargs -r rm --

# ─── Traer los archivos ──────────────────────────────────────────────────────
# Se compara composer.lock ANTES de moverlo, para saber si hay que reinstalar
# dependencias. Correr composer en cada despliegue son dos minutos regalados.
LOCK_ANTES=$(git rev-parse "HEAD:composer.lock" 2>/dev/null || echo none)

git reset --hard "origin/$RAMA" --quiet

LOCK_DESPUES=$(git rev-parse "HEAD:composer.lock" 2>/dev/null || echo none)

if [ "$LOCK_ANTES" != "$LOCK_DESPUES" ]; then
    echo "  cambiaron las dependencias: instalando"
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
fi

# ─── Aplicar ─────────────────────────────────────────────────────────────────
php artisan migrate --force

# Los permisos que trae una actualizacion no llegan solos a ningun rol: el catalogo vive en
# codigo y lo que un rol puede hacer vive en la base, escrita cuando se creo la instalacion.
# Sin esto, un modulo nuevo queda instalado e invisible — sin menu y con las rutas en 403.
# Solo agrega: lo que la empresa le quito a un rol a mano se queda quitado.
php artisan permisos:sincronizar
php artisan config:cache
php artisan route:cache
php artisan view:cache

# El enlace de archivos no viaja por git —es un enlace, no un archivo— y sin él las
# imágenes subidas se ven rotas. `storage:link` no sirve aquí: usa exec(), desactivado.
[ -e public/storage ] || ln -s ../storage/app/public public/storage

# Qué quedó instalado. Lo reporta el latido de licencia.
echo "dev-${REMOTO:0:7}" > version.txt

# ─── Comprobar que arrancó ───────────────────────────────────────────────────
# Si la aplicación no levanta, se avisa en el registro. No se revierte solo: volver
# atrás a ciegas con migraciones ya aplicadas es peor que quedarse quieto y avisar.
if php artisan route:list --path=login > /dev/null 2>&1; then
    echo "  listo en ${REMOTO:0:7}"
else
    echo "  ATENCIÓN: la aplicación no arranca después del despliegue"
    exit 1
fi
