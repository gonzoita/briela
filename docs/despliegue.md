# Desplegar una instalación de Briela

Hay dos caminos, y para un cliente **siempre es el primero**:

| Camino | Cuándo |
|---|---|
| **Instalador web** (sección 0) | Instalaciones de clientes. Sin consola, sin composer, sin git |
| **Manual por SSH** (secciones 1 a 5) | La instalación propia y el desarrollo, donde sí hay acceso |

---

## 0. Instalador web — el camino normal

### Cómo se prepara el paquete (esto lo haces tú)

```bash
composer install --no-dev --optimize-autoloader
```

```bash
php artisan briela:empaquetar 1.0.0
```

Eso deja en `storage/app/paquetes/` tres cosas: el ZIP, su `.sha256` y el
`instalar.php`. El comando **se niega a empaquetar** si `vendor/` todavía tiene
paquetes de desarrollo, porque uno de ellos (whoops) muestra el código fuente en
las pantallas de error.

El ZIP lleva `vendor/` y `public/build/` ya compilados —para que el cliente no
necesite composer ni Node— y **no lleva** `.env`, `docs/`, `CLAUDE.md`, `tests/`,
`installer/`, ni las herramientas de compilación. Son unos 46.000 archivos.

Después subes el ZIP a tu origen de descargas (por defecto el instalador lo busca
en `https://briela.app/descargas/`).

### Cómo lo instala el cliente

1. En su panel de hosting, crea el subdominio apuntando a una carpeta **`public`**
   (por ejemplo `sistema/public`).
2. Sube **solo `instalar.php`** (20 KB) a esa carpeta `public`.
3. Abre `https://su-dominio/instalar.php`, escribe su código y espera.

El instalador revisa el servidor, descarga el paquete, lo descomprime **por
tandas** con barra de progreso, crea el `.env` con una llave de cifrado propia de
esa instalación, se borra a sí mismo y lo deja en el asistente de configuración.

> Lo de las tandas no es un lujo: descomprimir 46.000 archivos tomó 89 segundos en
> pruebas, repartidos en 78 peticiones de alrededor de un segundo. En una sola
> petición se pasaría del tiempo máximo de casi cualquier hosting compartido, y
> dejaría la instalación a medias.

Si el servidor del cliente no puede salir a internet, hay salida: subir el ZIP a
mano como `briela-paquete.zip` en la carpeta padre de `public`. El instalador lo
detecta y se salta la descarga.

### El asistente de configuración

Tres pasos en `/instalar`: revisión del servidor, datos de la base de datos —que
se prueban antes de guardar nada— y por último la empresa y el administrador. Al
terminar se cierra solo: quien entre después a `/instalar` va al inicio de sesión.

---

## Instalación manual

Lo que sigue es para la instalación propia y el desarrollo, donde hay consola.

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
