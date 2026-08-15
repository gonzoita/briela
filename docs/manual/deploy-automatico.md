# Despliegue — el servidor jala los cambios

Cada vez que un cambio llega a la rama `main` en GitHub, el servidor lo trae solo: respalda la
base, actualiza los archivos, corre las migraciones y limpia las cachés. **Nadie compila ni
entra por SSH a mano.**

El script es `scripts/traer-cambios.sh` y lo llama una tarea programada del panel del hosting.

## Por qué el servidor jala en vez de que GitHub empuje

Hasta el 13 ago 2026 esto era un workflow de GitHub Actions que entraba por SSH al servidor.
**No funcionaba de forma sostenida:** Hostinger agota el tiempo de las conexiones SSH que vienen
de los servidores de GitHub. La misma llave desde otra máquina entra sin problema, y desde
GitHub falla. No es algo que se arregle con código nuestro.

Al invertir la dirección, el servidor solo necesita salida a internet —que sí tiene— y no hay
nada que bloquear. De paso desaparecen los cuatro secretos de GitHub y la llave de acceso al
servidor: en el hosting solo vive una llave de **lectura** del repositorio.

## Lo que hace, en orden

1. **Un candado por instalación.** Dos despliegues simultáneos sobre la misma carpeta se pisan.
2. **¿Hay algo nuevo?** Compara el commit local con el remoto. Si son iguales, no hace nada — por
   eso puede correr seguido sin costo.
3. **Respalda la base.** Obligatorio y primero. Si el respaldo falla, no se despliega. Guarda los
   diez últimos: en un hosting compartido el disco es finito.
4. **Trae los archivos** y reinstala dependencias **solo si cambió `composer.lock`**.
5. **Aplica**: `migrate --force`, y vuelve a cachear configuración, rutas y vistas.
6. **Comprueba que la aplicación arranca.** Si no, lo dice en el registro y **no revierte solo**:
   volver atrás a ciegas con migraciones ya aplicadas es peor que quedarse quieto y avisar.

## El registro

Todo queda en `~/despliegue.log` del hosting. El script lo abre él mismo y no lo deja a la
redirección de la tarea programada: el campo del panel puede recortar la línea al pegarla, y
entonces el despliegue funciona pero no queda rastro de nada.

```bash
tail -40 ~/despliegue.log
```

> Las dos instalaciones —el ERP y el superadmin— escriben en el **mismo** archivo, así que sus
> líneas se entremezclan cuando despliegan a la vez.

## Comprometer no es desplegar

El servidor jala de **GitHub**. Un commit sin `git push` se queda en la computadora y producción
sigue igual por más veces que corra la tarea. Para comprobar que no falta empujar:

```bash
git log --oneline origin/main..main
```

Si no imprime nada, GitHub está al día.

## Disparar el despliegue a mano

Cuando no se quiere esperar a la tarea programada:

```bash
for A in $(find ~/domains -maxdepth 4 -name artisan 2>/dev/null); do R=$(dirname "$A"); [ -f "$R/scripts/traer-cambios.sh" ] && bash "$R/scripts/traer-cambios.sh" "$R" main; done; tail -15 ~/despliegue.log
```

Es seguro aunque la tarea acabe de pasar: el script no hace nada si no hay cambios y tiene su
propio candado.

## La tarea programada

Se configura en el panel del hosting (Hostinger: **Cron Jobs**), no en el crontab del usuario —
por eso `crontab -l` dice «no crontab» aunque el despliegue esté funcionando.

```
bash /home/USUARIO/domains/DOMINIO/public_html/scripts/traer-cambios.sh /home/USUARIO/domains/DOMINIO/public_html main
```

## Después de desplegar, en el navegador

Briela tiene service worker. Una recarga normal puede servir el JavaScript viejo desde la caché;
para ver un cambio de pantalla hay que recargar con **Ctrl+Shift+R**.
