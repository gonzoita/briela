# Operación — trabajar en Briela desde cualquier computador

Todo lo que hace falta para retomar el proyecto: conectarse al servidor, montarlo en otro
computador, mover el código, regenerar el grafo, saber qué documento es cada uno, y arrancar un
chat nuevo sin perder contexto.

**Aquí no hay contraseñas ni llaves.** Este archivo viaja con el repositorio, y el repositorio
se clona en el servidor de cada cliente. Las contraseñas van en tu gestor de contraseñas o en el
panel del hosting; lo que está aquí es el procedimiento.

**Actualizado el 15 ago 2026.**

---

## 1. Conexión al servidor (SSH)

### Los datos

| Dato | Valor |
|---|---|
| Usuario | `u787912762` |
| Servidor | `62.72.62.4`, puerto **65002** |
| Máquina | `br-asc-web1078` (el nombre que muestra el prompt al entrar) |
| Panel | hPanel de Hostinger |
| Contraseña | hPanel → **Avanzado → Acceso SSH**, o tu gestor de contraseñas |

Con la llave ya autorizada —`~/.ssh/briela_deploy` en el computador de trabajo— no pide
contraseña:

```bash
ssh -i ~/.ssh/briela_deploy 62.72.62.4
```

Desde un computador nuevo, sin la llave, va con contraseña:

```bash
ssh -p 65002 u787912762@62.72.62.4
```

Sabes que entraste porque el prompt cambia a:

```
[u787912762@br-asc-web1078 ~]$
```

### Dónde vive cada cosa

```
~/domains/briela.app/public_html/sistema/      ← el ERP  (sistema.briela.app)
~/domains/briela.app/public_html/superadmin/   ← el superadmin
~/despliegue.log                               ← el registro, de las DOS instalaciones
```

En el mismo hosting viven otros dominios que no son de Briela. El `find` de abajo solo
encuentra los que tienen `artisan`, así que no hay riesgo de tocar lo que no es.

Para encontrar la raíz de una instalación sin adivinar la carpeta:

```bash
find ~/domains -maxdepth 4 -name artisan
```

### Lo que más se hace por SSH

**Ver qué pasó en el último despliegue:**

```bash
tail -40 ~/despliegue.log
```

**Forzar el despliegue sin esperar al cron:**

```bash
for A in $(find ~/domains -maxdepth 4 -name artisan 2>/dev/null); do R=$(dirname "$A"); [ -f "$R/scripts/traer-cambios.sh" ] && bash "$R/scripts/traer-cambios.sh" "$R" main; done; tail -15 ~/despliegue.log
```

Es seguro aunque el cron acabe de pasar: el script no hace nada si no hay cambios y tiene su
propio candado.

### Dos cosas que confunden

- **La tarea programada no está en `crontab`.** Vive en hPanel → Cron Jobs. Por eso
  `crontab -l` responde «no crontab for u787912762» aunque el despliegue esté funcionando.
- **Los comandos del repositorio no van aquí.** Un `cd C:\laragon\...` en el servidor falla:
  esa ruta es de tu computador. Si estás en la sesión SSH y necesitas git del repo, sal con
  `exit` primero.

---

## 2. Conexión local (en tu computador)

### El entorno

| Cosa | Valor |
|---|---|
| Sistema | Windows |
| Servidor | Laragon |
| PHP | `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` |
| Composer | `C:\laragon\bin\composer\composer` |
| Proyecto ERP | `C:\laragon\www\briela` |
| Proyecto superadmin | `C:\laragon\www\briela-superadmin` |
| Base local | MySQL, base **`briela`**, usuario `root`, contraseña vacía |
| URL local | http://localhost:8000 |

### En cada terminal nueva

PHP no está en el PATH del sistema. Antes de cualquier `php artisan`:

```powershell
$env:Path = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;" + $env:Path
```

### Levantar el proyecto

```bash
php artisan serve
```

**No hace falta compilar para arrancar**: `public/build/` viene en el repositorio. Solo si vas a
cambiar código de Vue conviene levantar Vite en otra terminal:

```bash
npm run dev
```

### Si MySQL no responde

Laragon lo apaga al cerrarse. El síntoma es `SQLSTATE[HY000] [2002]`. Se prende desde Laragon
(botón **Start All**), o desde una terminal:

```bash
cd /c/laragon/bin/mysql/mysql-8.4.3-winx64 && ./bin/mysqld.exe --defaults-file=my.ini --console
```

Si dice `ibdata1 must be writable`, ya hay una instancia corriendo: revisa con
`netstat -ano | grep 3306`.

> ⚠️ **La base local de Briela es `briela`.** Cualquier otra base es de otro proyecto: jamás
> apuntes el `.env` de Briela ahí. Verifícalo antes de correr cualquier `migrate`.

---

## 3. Montar el proyecto en otro computador

### 3.1 Instalar el entorno (una vez)

- [Laragon](https://laragon.org/download/) — trae PHP, MySQL, Composer y Node
- [Git](https://git-scm.com/download/win)

### 3.2 Clonar

```bash
cd C:\laragon\www
git clone https://github.com/gonzoita/briela.git
cd briela
```

El repositorio es privado: GitHub va a pedir usuario y un **token de acceso personal** (no la
contraseña de la cuenta). Se crea en GitHub → Settings → Developer settings → Personal access
tokens.

### 3.3 Dependencias

```bash
composer install
npm install
```

### 3.4 El archivo `.env`

```bash
copy .env.example .env
php artisan key:generate
```

Confirma la conexión local:

```
DB_DATABASE=briela
DB_USERNAME=root
DB_PASSWORD=
```

**Lo que hay que traer del computador viejo.** Sin esto el sistema arranca, pero algunas cosas
quedan mudas:

| Variable | Sin ella, no funciona |
|---|---|
| `OPENROUTER_KEY` | La IA en local. **En la instalación de un cliente no va nunca**: sale por el proxy del superadmin |
| `MAIL_*` | El envío de correos |
| `APP_KEY` | Ver el aviso de abajo |

> **Si vas a importar la base del computador viejo, copia también la `APP_KEY` vieja.** Con otra
> llave no se pueden descifrar los datos que quedaron cifrados —por ejemplo los tokens de las
> cuentas de redes sociales— y se pierden en silencio. Si empiezas con base nueva, usa la que
> generó `key:generate`.

### 3.5 La carpeta de archivos

```bash
php artisan storage:link
```

Sin esto, las imágenes subidas salen rotas aunque estén en el disco. Una sola vez.

### 3.6 La base

Crear una base vacía llamada `briela` (desde HeidiSQL, que trae Laragon), y luego:

```bash
php artisan migrate --seed
```

Eso crea las tablas y carga datos de prueba. Usuario: `admin@briela.app` / `password`.

### 3.7 Si quieres tus datos reales, no los de prueba

**En el computador viejo**, exportar:

```bash
mysqldump -u root briela > briela.sql
```

**En el nuevo**, importar sobre una base vacía y sin `--seed`:

```bash
mysql -u root briela < briela.sql
php artisan migrate
```

Y copiar a mano la carpeta `storage/app/public` completa: ahí viven las imágenes y los archivos
subidos, que no están en git.

---

## 4. Git — y por qué comprometer no es desplegar

### Lo básico

| Cosa | Valor |
|---|---|
| Repositorio ERP | `https://github.com/gonzoita/briela` (privado) |
| Repositorio superadmin | `https://github.com/gonzoita/briela-superadmin` (privado) |
| Rama | `main` |
| Cuenta | `gonzoita` |

### El ciclo completo

```bash
git add -A
git commit -m "Mensaje en español, diciendo qué cambia y por qué"
git push origin main
```

**El `push` es el paso que despliega.** El servidor jala de GitHub por cron cada rato; un commit
sin empujar se queda en tu computador y producción sigue igual por más veces que corra el cron.

Para comprobar que no falta empujar — si no imprime nada, GitHub está al día:

```bash
git log --oneline origin/main..main
```

### Reglas de este repositorio

- **`public/build/` va en git a propósito**, no en `.gitignore`. Es lo que permite que el cliente
  instale sin Node ni Vite. Si cambias código de Vue, corre `npm run build` **antes** de
  comprometer.
- **Mensajes en español**, explicando la razón y no solo el qué.
- **Nunca** se escriben en este repositorio credenciales, ni el nombre de la empresa donde se
  hacen las pruebas, ni el nombre del sistema del que Briela se originó.

### Después de desplegar, en el navegador

**Ctrl+Shift+R**, no F5. Briela tiene service worker y una recarga normal puede servir el
JavaScript viejo — vas a ver la versión anterior y creer que el cambio no llegó.

---

## 5. El grafo del proyecto

Es un mapa navegable del código: qué archivo se relaciona con qué. Sirve para ubicar algo
**antes** de leer código a mano, que es lo que más ahorra tiempo y tokens.

### Dónde está

```
graphify-out/graph.json          ← el grafo, para consultarlo
graphify-out/graph.html          ← el mapa visual, se abre en el navegador
graphify-out/GRAPH_REPORT.md     ← el informe en palabras
```

Está en `.gitignore`: **se regenera, no se versiona.** En un computador nuevo hay que generarlo.

### Regenerarlo completo

```bash
python -c "import graphify" || pip install graphifyy
```

Y luego, en la raíz del proyecto, invocando el skill `/graphify` desde Claude Code sobre el
proyecto. Al 15 ago 2026 el grafo tiene **6.050 nodos y 10.229 aristas** sobre 778 archivos de
código.

### Las dos reglas que cuestan si no se saben

- **`parallel=False` siempre.** En paralelo la extracción devuelve **cero nodos** para la mayoría
  de los archivos y **no avisa**: el grafo sale a medias y parece correcto.
- **`graphify` no es un comando de terminal.** Es un skill de Claude Code (`/graphify`). No
  existe un binario en el PATH.

### Cuándo regenerarlo

Después de cambios que muevan estructura: borrar módulos, mover carpetas, agregar servicios. Un
grafo desactualizado es peor que no tenerlo, porque manda a leer archivos que ya no existen.

---

## 6. Los documentos del proyecto

| Archivo | Qué es | Cuándo leerlo |
|---|---|---|
| **`CLAUDE.md`** | Las reglas del proyecto: stack, lo que nunca se rompe, convenciones | Se carga solo en cada chat. **No hay que pegarlo** |
| **`docs/MANUAL-BRIELA.md`** | El sistema completo de una lectura, módulo por módulo, incluida la IA | Para entender qué existe |
| **`docs/manual/00-indice.md`** | El detalle: una página por módulo (42) | Para el detalle de uno |
| **`docs/OPERACION.md`** | Este archivo | Al cambiar de computador o retomar |
| **`docs/BRIELA-PLAN.md`** | Arquitectura decidida y plan por fases. **Fuente de verdad** | Antes de diseñar algo nuevo |
| **`docs/BRIELA-CONTEXTO.md`** | Documento de arranque y decisiones de origen | Para entender por qué algo es como es |
| **`docs/manual/deploy-automatico.md`** | Cómo despliega el servidor, en detalle | Cuando el despliegue falle |
| **`graphify-out/GRAPH_REPORT.md`** | El informe del grafo | Para ubicarse en el código |

Los del superadmin están en su propio repositorio, en su `README.md`.

---

## 7. Cómo arrancar un chat nuevo

**Conviene abrir uno nuevo por tema.** En una conversación, cada mensaje reenvía todo lo
anterior: al final de un día de trabajo estás pagando por reenviar lo de la mañana cada vez que
preguntas algo. Un chat nuevo arranca en cero y **no pierde contexto**, porque el contexto está
en `CLAUDE.md`, en el manual y en el grafo.

### Lo que NO hay que hacer

- Pegar `CLAUDE.md` ni explicar el proyecto: se carga solo.
- Resumir lo que se hizo antes: está en los commits y en los documentos.

### Lo que sí ayuda

1. **Di qué quieres, concreto.** «Arregla que al editar un cliente no se guarda el teléfono»
   rinde más que «revisemos clientes».
2. **Si es sobre algo que ya existe**, pídelo así: «usa el grafo para ubicar X antes de leer
   código». Evita que se lean archivos completos al tanteo.
3. **Un tema por chat.** Cuando cierres un bloque y cambies de asunto, abre otro.
4. **Si algo no aparece en producción**, lo primero es verificar el despliegue (sección 4), no
   dar por hecho que el código está mal.

### Lo que conviene decir al arrancar, si aplica

- «Estoy en otro computador, recién clonado» → para que verifique el entorno antes de tocar nada.
- «No quiero gastar tokens» → para que use el grafo y no lea de más.
- «Esto va a producción» o «esto no» → cambia si empuja o solo comprometa.

---

## 8. Lo que nunca se hace

- ⛔ **`migrate:fresh`, `migrate:refresh` ni `db:wipe` contra una base real.** Causó dos
  pérdidas totales de datos el mismo día (15 jul 2026). Con una instalación por cliente el
  riesgo se multiplica, y la base que se pierde puede no ser la propia.
- ⛔ Apuntar el `.env` de Briela a la base de otro proyecto.
- ⛔ Escribir credenciales, o el nombre de la empresa de pruebas, en este repositorio.
- ⛔ Parchear la instalación de un cliente «solo por esta vez»: nace una versión que ya no se
  puede actualizar. Lo que alguien necesite distinto va como **opción configurable** en el core.

---

## 9. Estado y pendientes

Las fases 0 a 3 están hechas (repo, marca desacoplada, superadmin con licencias, proxy de IA).
Faltan la 4 (asistente de instalación), la 5 (botón de actualizar) y la 6 (cobros recurrentes).

**Hasta que declares la primera versión se despliega directo**, sin paquetes ni actualizador.

Pendientes concretos al 15 ago 2026:

- Revocar la llave vieja de OpenRouter que quedó expuesta.
- Ponerle bodega de entrega al paso final de las plantillas de ensamble que ya existen (hoy
  entran a la principal).
- Decidir si las unidades armadas deben sumarse solas al aprobar calidad.
- **La pregunta de negocio sin resolver, y es la más importante: a quién se le vende.** El
  sistema está hecho a la medida de fabricar por pedido con medidas variables.

## Correr las pruebas

La suite usa una base aparte, `briela_test`, declarada en `phpunit.xml`. **Nunca toca `briela`.**
Si no existe, las 51 pruebas de Feature fallan todas con «no se puede establecer una conexión» y
solo corren las 16 unitarias.

Se crea una vez, con Laragon arriba:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS briela_test"
```

Y desde ahí:

```bash
php artisan test
```

Al 22 ago 2026: **67 pruebas, 214 comprobaciones, todas en verde.**

Para correr solo las que no tocan la base, que son instantáneas:

```bash
php artisan test --testsuite=Unit
```

