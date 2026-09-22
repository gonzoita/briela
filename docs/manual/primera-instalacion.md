# Primera instalación en un servidor nuevo

Cómo poner Briela a andar en el servidor de un cliente, de cero. Es el mismo método
con el que corre `sistema.briela.app`: sin instalador empaquetado todavía —eso es la
Fase 4 del plan—, pero ya no es una lista de comandos sueltos: la mayor parte la hace
`scripts/primera-instalacion.sh`.

**Nada de esto usa IA.** Es un script de shell, comandos de Artisan y SQL — se corre
una vez y queda. La IA solo hace falta para escribirlo, no para ejecutarlo.

---

## Antes del script (a mano, en el panel del hosting)

Tres cosas que no se pueden meter en un script porque viven detrás de una interfaz
web, no de una terminal:

### 1. El dominio

En hPanel (Hostinger): **Dominios** → agregar el dominio o subdominio de esta
instalación, apuntando su carpeta pública (`public_html` de ese dominio) a donde va a
vivir el clon del repositorio.

### 2. La base de datos

En hPanel: **Bases de datos → Bases de datos MySQL**. Crear una base vacía y un
usuario con permisos sobre ella. En hosting compartido **no hay** acceso a un usuario
`root` de MySQL — todo pasa por el usuario que crea el panel, con un nombre parecido a
`u787912762_nombrebase`.

Anota los tres datos: nombre de la base, usuario, contraseña. El script los va a pedir.

### 3. La llave de solo lectura del repositorio

El repositorio es privado, así que clonarlo pide credenciales. La regla del proyecto
es que en el servidor de un cliente **solo vive una llave de lectura**, nunca una de
escritura — ver `docs/manual/deploy-automatico.md`.

Por SSH, en el servidor:

```bash
ssh-keygen -t ed25519 -C "briela-deploy-<dominio-del-cliente>" -f ~/.ssh/briela_deploy -N ""
cat ~/.ssh/briela_deploy.pub
```

Copiar esa línea y agregarla en GitHub: repositorio `gonzoita/briela` → **Settings →
Deploy keys → Add deploy key**. Pegar la llave, **sin marcar "Allow write access"**, y
guardar.

Luego, en el servidor, decirle a Git que use esa llave para este repositorio
(`~/.ssh/config`):

```
Host github.com-briela
    HostName github.com
    User git
    IdentityFile ~/.ssh/briela_deploy
    IdentitiesOnly yes
```

Y clonar con ese alias, en la carpeta pública del dominio que se creó en el paso 1:

```bash
git clone git@github.com-briela:gonzoita/briela.git .
```

(El `.` al final clona *dentro* de la carpeta actual — hay que estar ya parado en la
carpeta pública del dominio antes de correrlo.)

---

## El script

Parado en esa misma carpeta, con el repositorio ya clonado:

```bash
bash scripts/primera-instalacion.sh
```

Pide el dominio y los tres datos de la base de datos del paso 2, y de ahí en adelante
no pregunta nada más: instala dependencias con Composer, genera la `APP_KEY`, migra y
siembra la base (usuarios de ejemplo + catálogo de demostración), sincroniza permisos,
cachea configuración/rutas/vistas, y enlaza `storage/app/public`.

Al final imprime lo que queda pendiente — el paso 4 de abajo.

---

## Después del script (otra vez a mano)

### 4. El Cron Job del despliegue automático

El script imprime la línea exacta. Va en hPanel: **Avanzado → Cron Jobs**, cada pocos
minutos:

```
bash /home/USUARIO/domains/DOMINIO/public_html/scripts/traer-cambios.sh /home/USUARIO/domains/DOMINIO/public_html main
```

De ahí en adelante, un `git push` a `main` despliega solo — ver
`docs/manual/deploy-automatico.md`.

### 5. Entrar y cambiar la contraseña

`https://<dominio>/login` — `admin@briela.app` / `password`. Cambiarla de una vez,
desde el perfil.

### 6. Reemplazar el catálogo de ejemplo

El seed carga productos y un ensamble de demostración (puertas frigoríficas) para que
la instalación no quede vacía. Bórralos o edítalos desde **Productos** y **Ensambles**
con el catálogo real del cliente.

### 7. El serial de licencia

Se genera en el superadmin y se entra desde **Ajustes → Licencia** ya con el sistema
arriba — no hace falta para instalar ni para operar de entrada: sin serial, el sistema
funciona igual, solo sin proxy de IA ni actualizaciones. Ver `LicenciaService` y
`config/briela.php`.

---

## Si algo falla a mitad de camino

El script no es destructivo, pero tampoco se puede correr dos veces sobre la misma
instalación: si ya hay un `.env` con `APP_KEY` generada, se niega a seguir para no
duplicar los usuarios de ejemplo. Para reintentar de cero, hay que vaciar la base de
datos (no la de `briela` real de otro cliente — la que se creó en el paso 2, vacía) y
borrar el `.env` antes de volver a correrlo.
