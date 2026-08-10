# Instalar Briela en Hostinger

Guía para poner una instalación en marcha en un hosting compartido de Hostinger.
Los pasos 1 a 4 se hacen en el panel y el gestor de archivos; el resto lo hace el
instalador desde el navegador.

Referencia de esta guía: `sistema.briela.app`, la instalación propia de Briela.

---

## Antes de empezar: lo que se sube

Dos archivos, que salen de `php artisan briela:empaquetar`:

| Archivo | Dónde va | Para qué |
|---|---|---|
| `briela-X.Y.Z.zip` (~60 MB) | en la carpeta del sitio, renombrado a `briela-paquete.zip` | el sistema completo |
| `instalar.php` (~24 KB) | en la **carpeta pública** del sitio | el asistente que lo descomprime |

El ZIP ya trae `vendor/` y los assets compilados, así que **no hace falta composer
ni Node en el servidor**.

---

## 1. El subdominio, apuntando a la carpeta pública

Esto es lo que más se equivoca, y tiene consecuencias: Laravel sirve desde su
subcarpeta `public`, no desde la raíz del proyecto. Si el dominio apunta a la raíz,
cualquiera puede leer el `.env` con las credenciales de la base.

En hPanel → **Dominios → Subdominios**:

- Subdominio: `sistema`
- Dominio: `briela.app`
- **Carpeta personalizada**: `public_html/sistema/public`

Si el subdominio ya existía apuntando a `public_html/sistema`, hay que **editarlo**
para que apunte a `public_html/sistema/public`. Y si Hostinger dejó un `default.php`
de bienvenida en `public_html/sistema`, se borra.

> El instalador descomprime el paquete en la carpeta que **contiene** al archivo
> `instalar.php`. Por eso el archivo va en `public_html/sistema/public/` y el
> sistema termina en `public_html/sistema/`. La primera pantalla del instalador
> muestra la ruta donde va a instalar: conviene leerla antes de continuar.

## 2. Certificado SSL

En hPanel → **Seguridad → SSL**, emitir el certificado para el subdominio. Sin
HTTPS el navegador no guarda bien las cookies de sesión y el asistente puede
quedarse dando vueltas en la pantalla de entrada.

## 3. La base de datos

En hPanel → **Bases de datos → MySQL**:

- Crear la base, por ejemplo `u000000000_briela`.
- Crear el usuario y **darle todos los permisos** sobre esa base.
- Anotar nombre de base, usuario y contraseña: los pide el asistente.

El servidor de base de datos suele ser `localhost`. Si Hostinger indica otro, se
usa ese.

> La base se crea **vacía**. El instalador arma las tablas.

## 4. Versión de PHP

En hPanel → **Avanzado → Configuración de PHP**, dejar el subdominio en **PHP 8.3
o superior** y verificar que estén activas: `pdo_mysql`, `mbstring`, `openssl`,
`zip`, `gd`, `fileinfo`, `curl`. La primera pantalla del instalador las revisa y
avisa si falta alguna.

## 5. Subir los dos archivos

En el gestor de archivos:

1. `briela-paquete.zip` en `public_html/sistema/`
   *(el ZIP se sube tal cual, sin descomprimirlo desde el gestor: el instalador lo
   hace por tandas para no pasarse del límite de ejecución)*
2. `instalar.php` en `public_html/sistema/public/`

Si el gestor falla con los 60 MB, se sube por SFTP con el mismo usuario del SSH.

## 6. Instalar

Abrir en el navegador:

```
https://sistema.briela.app/instalar.php
```

El asistente hace el resto:

1. **Revisión del servidor** — versión de PHP, extensiones y permisos de escritura.
   Muestra la carpeta destino: comprobar que es la correcta.
2. **Código de instalación** — el que entrega Briela. Hoy se guarda pero todavía no
   se valida contra el servidor de licencias.
3. **Descarga y descompresión** — con barra de progreso. Si el ZIP ya está subido lo
   usa directamente y se salta la descarga.
4. **Base de datos** — los datos del paso 3. Prueba la conexión antes de guardar
   nada, así que un error se ve ahí mismo y no a mitad de la instalación.
5. **Migraciones** — crea las tablas. Toma un par de minutos.
6. **Empresa y cuenta** — nombre de la empresa y el primer administrador, con su
   propia contraseña. Aquí se genera también la llave de cifrado de la instalación.

Al terminar, el asistente se cierra solo y `instalar.php` se borra. Desde ahí se
entra con el correo y la contraseña que se acabaron de crear.

## 7. Después de instalar

**El cron**, para las tareas programadas (cotizaciones que vencen, avisos de
entregas y de cursos, publicaciones de redes). En hPanel → **Avanzado → Trabajos
cron**, cada minuto:

```
cd /home/USUARIO/domains/briela.app/public_html/sistema && php artisan schedule:run >> /dev/null 2>&1
```

**Comprobar que la raíz del proyecto no se ve desde la web.** Abrir
`https://sistema.briela.app/.env` en el navegador: debe dar 404. Si muestra el
archivo, el subdominio está apuntando a la carpeta equivocada — hay que corregir el
paso 1 **antes** de meter datos reales.

**Identidad visual.** En Ajustes → Identidad visual se sube el logo, se elige el
color y la tipografía. El sistema muestra el nombre de la empresa mientras no haya
logo.

---

## Si algo sale mal

| Síntoma | Causa habitual |
|---|---|
| Se ve el listado de archivos o `default.php` | El subdominio apunta a `sistema` y no a `sistema/public` |
| "No se pudo conectar con la base" | Usuario sin permisos sobre la base, o nombre de servidor distinto de `localhost` |
| La descompresión se detiene | Límite de ejecución del hosting. Se recarga la página: continúa donde iba |
| Pantalla en blanco después de instalar | Permisos de escritura en `storage/`. Deben ser 755 en carpetas |
| Da vueltas en la pantalla de entrada | Falta el certificado SSL, o la hora del servidor está desfasada |

Los errores quedan en `storage/logs/laravel.log`, dentro de la carpeta del sistema.
