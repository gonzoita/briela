# Montar el proyecto en otra computadora

El **código** vive todo en GitHub, así que en otra máquina se baja con un
`git clone`. Pero hay cuatro cosas que **no** viajan por GitHub (a propósito)
y hay que resolver en la máquina nueva:

1. **Las dependencias** (`vendor/` de PHP y `node_modules/` de JavaScript) —
   se regeneran con un comando, no se guardan en el repo.
2. **El archivo `.env`** — tiene la configuración local (contraseñas, llaves
   de API), por seguridad nunca se sube. Hay que crearlo en la máquina nueva.
3. **La base de datos** (tus registros: OPs, cotizaciones, clientes…) — vive
   en el MySQL de esta computadora, no en GitHub. En la máquina nueva
   empiezas con datos de prueba, salvo que copies la base (ver el final).
4. **Los archivos subidos** (fotos de productos, planos, imágenes de
   ensambles y de pasos) — están en `storage/app/public/` y tampoco se
   guardan en el repo. Si los necesitas, hay que copiarlos aparte.

## Pasos en la computadora nueva

### 1. Instalar el entorno (una vez)
- **Git** (https://git-scm.com)
- **Laragon** (https://laragon.org) — trae PHP, MySQL y Node casi listos.
- **Composer** (https://getcomposer.org)

### 2. Clonar el proyecto
```bash
cd C:\laragon\www
git clone https://github.com/Blueffalo/interfrigo-sgi.git
cd interfrigo-sgi
```

### 3. Instalar dependencias
```powershell
$env:Path = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;" + $env:Path
composer install
npm install
```

### 4. Crear el archivo `.env`
```powershell
copy .env.example .env
php artisan key:generate
```
Luego abrir `.env` y confirmar la conexión a la base local (normalmente ya
viene bien para Laragon):
```
DB_DATABASE=interfrigo_sgi
DB_USERNAME=root
DB_PASSWORD=
```

**Llaves que hay que copiar de la computadora vieja.** Sin ellas el sistema
arranca, pero algunas funciones quedan mudas:

| Variable | Sin ella, no funciona |
|---|---|
| `OPENROUTER_API_KEY` | El asistente Ofe, la redacción con IA y la generación de imágenes |
| `OPENROUTER_MODELO_TEXTO` / `_IMAGEN` | Los modelos que usa la IA (si faltan, cae a los de defecto) |
| `MAIL_*` | El envío de correos (avisos por email, cotizaciones al cliente) |
| `GOOGLE_DRIVE_CREDENTIALS_PATH` y `GOOGLE_DRIVE_FOLDER_ID` | La integración con Google Drive. La ruta apunta a un archivo de credenciales que tampoco está en el repo: hay que copiarlo también |

La forma más simple es **abrir el `.env` viejo y el nuevo lado a lado** y pasar
esos valores a mano. No copies el archivo entero a ciegas: `APP_KEY` debe ser
la que generó `key:generate` en la máquina nueva si la base es nueva — pero si
vas a importar la base de la máquina vieja, **copia también la `APP_KEY`
vieja**, porque con otra llave no se pueden descifrar los datos que quedaron
cifrados (por ejemplo, los tokens de las cuentas de redes sociales).

### 4.1 Enlazar la carpeta de archivos
```powershell
php artisan storage:link
```
Sin esto, las imágenes y los archivos subidos no se ven en pantalla (salen
rotas) aunque estén en el disco. Se hace una sola vez.

### 5. Crear la base de datos y las tablas
En MySQL (o desde HeidiSQL que trae Laragon), crear una base vacía llamada
`interfrigo_sgi`. Luego:
```powershell
php artisan migrate --seed
```
Esto crea todas las tablas y carga los **datos de prueba** (usuarios de
ejemplo, etc.). Con esto ya puedes entrar y trabajar.

### 6. Levantar el proyecto
```powershell
php artisan serve
```
Abrir http://localhost:8000

No hace falta compilar para arrancar: `public/build/` viene incluido en el
repositorio. Solo si vas a **cambiar** código de Vue conviene levantar Vite en
otra terminal, que recarga en caliente mientras editas:
```powershell
npm run dev
```

Usuarios de prueba (del seeder):
`admin@interfrigo.com` / `password` — administrador

### 7. Subir cambios desde la máquina nueva
Igual que siempre — el deploy automático no depende de la computadora:
```powershell
.\subir.ps1 "descripción del cambio"
```
Ese comando hace el commit y el push; GitHub Actions compila y despliega solo
al servidor. **No** corras `npm run build` antes de subir: de eso se encarga
el CI (ver [Deploy automático](./deploy-automatico.md)).

## Si quieres llevarte TUS datos (no los de prueba)

Los registros reales de esta computadora están en su MySQL. Para tenerlos en
la máquina nueva hay que **exportar** la base acá e **importarla** allá.

**Exportar (en la computadora vieja).** Con Laragon encendido, desde la raíz
del proyecto:
```powershell
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe" -u root interfrigo_sgi > respaldo-sgi.sql
```

**Importar (en la computadora nueva).** Primero crear la base vacía y después
cargar el archivo:
```powershell
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root -e "CREATE DATABASE interfrigo_sgi"
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root interfrigo_sgi < respaldo-sgi.sql
```

En ese caso **no** corras `migrate --seed`: el archivo ya trae las tablas y
los datos. Sí conviene correr `php artisan migrate` después, por si en el
repositorio hay migraciones más nuevas que el respaldo.

También sirve HeidiSQL (viene con Laragon) si prefieres hacerlo con clics:
clic derecho sobre la base → "Exportar base de datos como SQL".

### Los archivos subidos
El respaldo SQL trae los **registros**, pero no las **fotos ni los archivos**.
Esos están en `storage/app/public/` (imágenes de productos, ensambles, pasos
de trabajo, planos, evidencias de calidad). Si los necesitas en la máquina
nueva, copia esa carpeta completa por USB o nube y pégala en la misma ruta.
Si no lo haces, el sistema funciona igual pero esas imágenes salen rotas.

> **Nunca** corras `migrate:fresh`, `migrate:refresh` ni `db:wipe` contra
> `interfrigo_sgi`. Borran todo. Esa regla existe porque ya causó dos pérdidas
> totales de datos.

> Nota: esto es solo para el entorno de desarrollo local. El sistema en
> producción (el que usan de verdad, en https://sgi.interfrigo.com.co) tiene
> su propia base en el servidor y no se toca con esto.
