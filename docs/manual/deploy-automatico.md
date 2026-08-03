# Deploy automático (GitHub Actions)

Desde ahora, cada vez que se sube un cambio a la rama `main`, GitHub hace
solo todo el proceso de deploy: compila los assets con Vite, guarda el
build, se conecta por SSH al servidor de Hostinger y corre `git pull`, las
migraciones y la limpieza de cachés. **Nadie tiene que hacer el build ni
entrar por SSH a mano.**

El workflow vive en `.github/workflows/deploy.yml`.

## Configuración inicial — se hace UNA sola vez

Para que GitHub pueda entrar al servidor necesita las credenciales SSH,
guardadas de forma segura (nunca en el código). Se pegan en la
configuración del repositorio en GitHub:

1. Entrar al repositorio en GitHub: `github.com/gonzoita/briela`
2. Arriba, ir a la pestaña **Settings** (Configuración).
3. En el menú de la izquierda: **Secrets and variables** → **Actions**.
4. Botón verde **New repository secret**. Crear estos cinco, uno por uno
   (el nombre debe escribirse EXACTAMENTE así, en mayúsculas):

   | Nombre (Name)   | Valor (Secret)                                       |
   |-----------------|------------------------------------------------------|
   | `SSH_HOST`      | la IP o el host del servidor                         |
   | `SSH_PORT`      | el puerto SSH                                        |
   | `SSH_USER`      | el usuario SSH                                       |
   | `SSH_PASSWORD`  | la contraseña SSH                                    |
   | `DEPLOY_PATH`   | la ruta de la instalación en el servidor             |

   Para cada uno: se escribe el nombre en "Name", el valor en "Secret", y
   se aprieta **Add secret**.

   > Los valores reales **nunca** se escriben en este manual ni en ningún
   > archivo del repositorio: solo viven en los secretos de GitHub.

Eso es todo. A partir del siguiente push a `main`, el deploy corre solo.
Mientras `SSH_HOST` esté vacío, el workflow solo compila los assets y se salta
el paso de deploy, así que no falla por no tener servidor todavía.

> **Esto actualiza únicamente `sistema.briela.app`**, la instalación propia. Las
> instalaciones de los clientes se actualizan con el botón de actualizar, desde
> un paquete firmado — ver `docs/BRIELA-PLAN.md` sección 6.2.

## Cómo saber si funcionó

En el repositorio de GitHub, pestaña **Actions**. Ahí aparece cada deploy:
un ✅ verde si salió bien, un ❌ rojo si algo falló (haciendo clic se ve
en qué paso y por qué). Los deploys quedan registrados con fecha y el
cambio que los disparó.

## Notas

- **Migraciones**: el workflow siempre corre `php artisan migrate --force`.
  Si no hay migraciones nuevas, no hace nada — es seguro dejarlo siempre.
- **Paquetes PHP nuevos** (composer): si algún día se agrega un paquete de
  PHP, hay que correr `composer install --no-dev` en el servidor a mano esa
  vez — el deploy automático no lo hace (es raro y se prefiere controlarlo).
- **El build ya no se hace localmente**: antes había que correr
  `npm run build` antes de cada commit. Ya no hace falta; GitHub lo hace y
  guarda el resultado solo. El commit "build: assets compilados por CI" que
  aparece en el historial es automático, es normal.
- **La tarea programada (cron) de cotizaciones vencidas** es aparte de
  esto — ver [Cotizaciones](./cotizaciones.md). Se configura una vez en el
  `crontab` del servidor y no depende de GitHub Actions.
