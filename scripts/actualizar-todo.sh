#!/usr/bin/env bash
#
# Actualiza TODAS las instalaciones del servidor, ahora mismo, sin esperar al cron.
#
# En un servidor conviven varias: el ERP, el superadmin, y cualquier instalación de
# demostración o de prueba. Cada una tiene su propia carpeta, su propia base y su propio
# `traer-cambios.sh`; este script las busca y las recorre.
#
# Por qué existe teniendo el cron: el cron pasa cada pocos minutos, y después de subir un
# arreglo urgente uno quiere verlo ya, no dentro de cinco minutos mirando el reloj. Y
# porque la alternativa —una línea de `find` de doscientos caracteres pegada desde un
# documento— se escribe mal una de cada tres veces, y cuando se escribe mal no falla:
# simplemente no actualiza nada y no dice nada.
#
# Es seguro aunque el cron acabe de pasar: `traer-cambios.sh` no hace nada si no hay
# cambios y tiene su propio candado por instalación.
#
#   bash scripts/actualizar-todo.sh              # todas, rama main
#   bash scripts/actualizar-todo.sh main         # igual, explícito
#   bash scripts/actualizar-todo.sh main ~/otro  # buscando en otra carpeta

set -uo pipefail

export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

RAMA="${1:-main}"
DONDE="${2:-$HOME/domains}"

if [ ! -d "$DONDE" ]; then
    echo "No existe $DONDE. Pasa la carpeta como segundo argumento."
    exit 1
fi

# `maxdepth 5` y no 4: en Hostinger la ruta es domains/DOMINIO/public_html/artisan, pero
# un subdominio puede colgar un nivel más abajo. Buscar de más cuesta un segundo; buscar
# de menos deja una instalación sin actualizar y nadie se entera.
mapfile -t RAICES < <(
    find "$DONDE" -maxdepth 5 -name artisan -type f 2>/dev/null \
        | while read -r A; do
            R=$(dirname "$A")
            [ -f "$R/scripts/traer-cambios.sh" ] && echo "$R"
          done \
        | sort -u
)

if [ "${#RAICES[@]}" -eq 0 ]; then
    echo "No se encontró ninguna instalación de Briela bajo $DONDE."
    echo "Una instalación es una carpeta con 'artisan' y 'scripts/traer-cambios.sh'."
    exit 1
fi

echo "Instalaciones encontradas: ${#RAICES[@]}"
printf '  %s\n' "${RAICES[@]}"
echo

FALLOS=0

for RAIZ in "${RAICES[@]}"; do
    NOMBRE=$(basename "$(dirname "$RAIZ")")
    ANTES=$(git -C "$RAIZ" rev-parse --short HEAD 2>/dev/null || echo '???????')

    printf '→ %-28s %s ' "$NOMBRE" "$ANTES"

    # El script escribe su propio registro y no imprime nada por salida estándar: lo que
    # se ve aquí es el antes y el después, que es lo que uno viene a mirar.
    if bash "$RAIZ/scripts/traer-cambios.sh" "$RAIZ" "$RAMA"; then
        DESPUES=$(git -C "$RAIZ" rev-parse --short HEAD 2>/dev/null || echo '???????')

        if [ "$ANTES" = "$DESPUES" ]; then
            echo "— ya estaba al día"
        else
            echo "→ $DESPUES  actualizada"
        fi
    else
        echo "✗ FALLÓ — mira ~/despliegue.log"
        FALLOS=$((FALLOS + 1))
    fi
done

echo
echo "─── últimas líneas de ~/despliegue.log ───"
tail -20 "${HOME}/despliegue.log" 2>/dev/null || echo "(todavía no hay registro)"

if [ "$FALLOS" -gt 0 ]; then
    echo
    echo "$FALLOS instalación(es) fallaron. No se revirtió nada: revisa el registro antes de volver a correr."
    exit 1
fi

echo
echo "Listo. En el navegador, recarga con Ctrl+Shift+R: el service worker sirve el JavaScript viejo."
