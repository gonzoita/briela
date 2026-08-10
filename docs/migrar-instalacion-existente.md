# Pasar un sistema existente a Briela conservando sus datos

Procedimiento para que una instalación que ya viene operando pase a correr sobre
Briela **sin perder su historial**: clientes, cotizaciones, órdenes de producción,
inventario, remisiones y certificados.

No es una instalación nueva. Es cambiar el código y conservar la base.

> **La regla de oro:** ningún paso destructivo antes de tener un respaldo que ya se
> probó restaurar. Un archivo que nadie restauró no es un respaldo, es un archivo.

---

## Lo que hay que saber antes de empezar

**1. Los archivos subidos no viajan en el respaldo de la base.** Fotos de productos,
PDF de clientes, firmas de remisión, logos: eso vive en `storage/app/public`, y el
volcado SQL solo trae la base. Hay que copiar esa carpeta aparte, o las fichas
quedarán con imágenes rotas.

**2. Hay datos cifrados con la llave de la instalación.** Los tokens de las cuentas
de redes sociales (`cuentas_rrss.access_token` y `refresh_token`) se guardan
cifrados con la `APP_KEY`. Si la instalación nueva tiene otra llave, esos tokens
**no se pueden descifrar** y hay que volver a conectar las cuentas.

Se comprueba antes, con esta consulta sobre la base de origen:

```sql
SELECT COUNT(*) FROM cuentas_rrss WHERE access_token IS NOT NULL AND access_token <> '';
```

- Si devuelve **0**: no hay nada cifrado que perder y la llave puede ser nueva.
- Si devuelve **más de 0**: o se copia la `APP_KEY` del `.env` viejo al nuevo, o se
  asume que hay que reconectar esas cuentas a mano.

**3. La base traerá cosas que Briela ya no conoce.** Las tablas del sistema viejo de
producción y del stock antiguo siguen en una base que viene operando. No molestan:
Laravel no reejecuta lo que ya está registrado en la tabla `migrations`, y esas
tablas quedan ahí sin que nadie las lea. No hay que borrarlas en la migración —
menos cosas que hacer el día del cambio, mejor.

**4. La marca del cliente sobrevive.** Logo, color, nombre, sedes y perfil de marca
viven en la base, no en el código. Así que al pasar a Briela el sistema **sigue
viéndose como esa empresa**, con el diseño nuevo aplicado.

---

## Antes del cambio

1. **Descargar el respaldo** desde el propio sistema: Administración → Copias de
   seguridad → Descargar.

2. **Comprobar que el archivo sirve.** Que empiece con la codificación declarada:

   ```bash
   head -5 respaldo.sql
   ```

   Tiene que aparecer `SET NAMES utf8mb4;` en las primeras líneas. Si no está, el
   respaldo es de una versión anterior al arreglo y **puede corromper las tildes**:
   hay que volver a descargarlo con el sistema actualizado, o exportar por
   phpMyAdmin.

3. **Restaurarlo en una base de prueba** y contar filas. Este paso es el que
   convierte un archivo en un respaldo:

   ```bash
   mysql -u root -e "CREATE DATABASE prueba_restauracion CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```

   ```bash
   mysql -u root --default-character-set=utf8mb4 prueba_restauracion < respaldo.sql
   ```

   Y comparar contra el original: cantidad de tablas, filas de `clientes`,
   `cotizaciones` y `ops`, y que un nombre con tildes se lea bien.

4. **Copiar los archivos subidos**: toda la carpeta `storage/app/public` del sistema
   viejo.

5. **Anotar la `APP_KEY`** del `.env` viejo, por si el punto 2 de arriba dio más de 0.

---

## El cambio

6. **Poner el sistema en mantenimiento**, para que nadie escriba mientras se migra.

7. **Subir el código de Briela** a la carpeta del sistema. El paquete trae `vendor/`
   y los assets compilados, así que no hace falta composer ni Node.

   > **No** se usa el asistente de instalación: la base ya tiene datos. Briela lo
   > detecta solo —comprueba que existan usuarios— y arranca reconociendo que está
   > instalada.

8. **El `.env`**: se conserva el del sistema viejo, que ya apunta a su base y tiene
   su `APP_KEY`. Solo hay que añadirle lo nuevo de Briela:

   ```
   BRIELA_SERIAL=
   BRIELA_LICENCIA_URL=https://superadmin.briela.app
   BRIELA_IA_URL=https://superadmin.briela.app/api/ia
   ```

9. **Correr las migraciones.** Aplica solo las que Briela agregó; lo ya migrado no
   se repite:

   ```bash
   php artisan migrate --force
   ```

10. **Limpiar cachés**, o seguirá sirviendo las vistas y rutas del código anterior:

    ```bash
    php artisan config:clear && php artisan route:clear && php artisan view:clear
    ```

11. **Restaurar los archivos subidos** en `storage/app/public` y comprobar el enlace:

    ```bash
    php artisan storage:link
    ```

12. **Quitar el mantenimiento.**

---

## Después: comprobar que quedó bien

Entrar con un usuario de siempre —las contraseñas son las mismas, están en la
base— y revisar:

- La lista de clientes, con sus nombres bien escritos (tildes y ñ).
- Una cotización vieja y su PDF.
- Una orden de producción con sus trabajos y fotos.
- El inventario, con sus existencias por bodega.
- El logo y el color de la empresa en su sitio.
- Que `/.env` en el navegador dé 404 o 403, nunca el contenido del archivo.

## Si algo sale mal

La vuelta atrás es en este orden:

1. Restaurar el código anterior.
2. Restaurar el respaldo de la base.
3. Restaurar `storage/app/public`.
4. Limpiar cachés.

Por eso el respaldo probado del paso 3 no es opcional: es la única vuelta atrás que
existe.
