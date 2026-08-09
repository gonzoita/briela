# Desplegar una instalación de Briela

Guía de la primera instalación en un servidor y del deploy automático posterior.
Sirve tanto para `sistema.briela.app` (la instalación propia) como para la de un
cliente.

> **Nunca correr `db:seed` en un servidor real.** Crea cuatro usuarios con la
> contraseña `password` y siembra productos y plantillas de ejemplo que son de
> otro negocio. En su lugar va `php artisan briela:instalar`, que crea la bodega
> principal y pide el administrador con su propia contraseña.

---

## 1. Requisitos del servidor

| | |
|---|---|
| PHP | 8.3, activado para ese dominio |
| MySQL | una base **por instalación**, con su propio usuario |
| Document root | tiene que apuntar a la carpeta **`public/`** del proyecto, no a la raíz |
| Extensiones | las de Laravel: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `gd` o `imagick` |
| Espacio en archivos | ~47.000 archivos por instalación (45.800 son de `vendor/`) |

Verificar antes de empezar:

```bash
php -v && composer --version && git --version
```

Si no hay `composer` en el servidor, hay que subir `vendor/` por FTP desde una
máquina donde sí lo haya (son ~46.000 archivos, así que conviene comprimirlo,
subir el zip y descomprimir allá).

## 2. Acceso al repositorio: llave de despliegue

El repositorio es privado, así que el servidor necesita poder leerlo. Se hace con
una **llave de despliegue de solo lectura**, no con un token en la URL del remote:
un token queda en texto plano dentro de `.git/config` y da acceso de escritura.

En el servidor:

```bash
ssh-keygen -t ed25519 -C "briela-deploy" -f ~/.ssh/briela_deploy -N ""
```

```bash
cat ~/.ssh/briela_deploy.pub
```

Ese contenido se pega en GitHub → repositorio → **Settings → Deploy keys → Add
deploy key**, **sin** marcar "Allow write access".

Y para que git la use:

```bash
printf 'Host github.com\n  IdentityFile ~/.ssh/briela_deploy\n  IdentitiesOnly yes\n' >> ~/.ssh/config
```

## 3. Primera instalación

Clonar (con SSH, no HTTPS, para que use la llave):

```bash
git clone git@github.com:gonzoita/briela.git RUTA_DEL_PROYECTO
```

Dependencias, sin las de desarrollo:

```bash
composer install --no-dev --optimize-autoloader
```

Configuración: copiar la plantilla y editarla.

```bash
cp .env.example .env
```

En `.env` hay que poner, como mínimo:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://EL-DOMINIO
DB_DATABASE=EL-NOMBRE-DE-LA-BASE
DB_USERNAME=EL-USUARIO
DB_PASSWORD=LA-CONTRASEÑA
```

`APP_DEBUG=false` no es opcional: en `true`, cualquier error muestra la
configuración del servidor y fragmentos de código a quien visite el sitio.

Llave de cifrado propia de esta instalación (nunca copiar la de otra: cifra las
sesiones y los datos sensibles):

```bash
php artisan key:generate --force
```

Base de datos y primer administrador:

```bash
php artisan migrate --force
```

```bash
php artisan briela:instalar
```

Enlace de archivos públicos y cachés de producción:

```bash
php artisan storage:link
```

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## 4. Tareas programadas

Sin cron no corren los avisos de entregas, los recordatorios, el cierre de
cotizaciones vencidas ni las publicaciones programadas. Una sola línea por
instalación:

```
* * * * * cd RUTA_DEL_PROYECTO && php artisan schedule:run >> /dev/null 2>&1
```

## 5. Deploy automático

El workflow `.github/workflows/deploy.yml` compila los assets en cada push a
`main` y actualiza el servidor. Necesita cinco secretos en el repositorio
(Settings → Secrets and variables → Actions):

| Secreto | Qué es |
|---|---|
| `SSH_HOST` | IP o host del servidor |
| `SSH_PORT` | puerto SSH |
| `SSH_USER` | usuario SSH |
| `SSH_PASSWORD` | contraseña SSH |
| `DEPLOY_PATH` | ruta del proyecto (la que contiene `artisan`, **no** `public/`) |

Mientras `SSH_HOST` esté vacío, el workflow solo compila y no intenta desplegar.

> El deploy corre `migrate --force` sin supervisión. Antes de que eso apunte a la
> instalación de un cliente hay que tener el respaldo automático previo a las
> migraciones (Fase 5 del plan).

## 6. Varias instalaciones en un mismo servidor

Se puede, y al 9 ago 2026 así se está haciendo. Conviene saber qué implica:

- **Una base de datos y un `.env` por instalación.** Nunca compartir base entre
  dos instalaciones: el aislamiento entre empresas depende de eso.
- **En hosting compartido todas corren bajo el mismo usuario del sistema.** El
  código de una instalación puede leer el `.env` de las otras, y con eso las
  credenciales de sus bases. El aislamiento deja de ser físico y pasa a depender
  de que ninguna instalación tenga un fallo que permita leer archivos.
- **Los límites son del plan, no de cada instalación**: número de archivos, CPU,
  procesos y memoria se reparten. Una instalación con mucho uso degrada a las
  demás.
- **Crear la base hay que hacerlo a mano** desde el panel: en compartido la
  aplicación no puede crear bases, así que el registro automático de clientes no
  es posible ahí (ver `BRIELA-PLAN.md` sección 3).

Cuando haya clientes que paguen, lo que resuelve esto es un VPS con **un usuario
del sistema por cliente**. Ahí el aislamiento vuelve a ser real, y de paso se
habilita el aprovisionamiento automático.

## 7. Comprobación final

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://EL-DOMINIO/login
```

Debe responder `200`. Después, en el navegador:

- Entrar con el administrador que creó `briela:instalar`.
- Configuración → Organización: nombre de la empresa, NIT, logo y color.
- Que el título de la pestaña y el logo sean los de la empresa, no los de fábrica.
