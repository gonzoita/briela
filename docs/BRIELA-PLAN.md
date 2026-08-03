# Briela — Arquitectura y plan por fases

Fecha: 2 ago 2026 · Estado: **decidido, sin construir**
Documento de partida: [BRIELA-CONTEXTO.md](./BRIELA-CONTEXTO.md)

> Este documento **reemplaza** una versión anterior del mismo día que diseñaba
> Briela como SaaS multiempresa con base de datos por empresa. El modelo de
> producto cambió a **instalación por cliente**, y con eso el multi-tenancy dejó
> de tener sentido. La sección 3 deja el registro de por qué.

---

## 1. Decisiones tomadas

| Decisión | Elección |
|---|---|
| Modelo de producto | **Una instalación por cliente**, con su propia base de datos |
| Dónde se instala | **Ambos**: unos en el servidor del cliente, otros en el de Briela |
| Aislamiento entre empresas | **Físico, por instalación separada.** No hay multi-tenancy |
| Licenciamiento | **Serial** emitido por Briela, con suscripción mensual anclada |
| Al vencerse el pago | **Bloqueo total de Briela** (con gracia offline — sección 4) |
| Modelo de ganancia | **Suministro de IA**: Briela provee OpenRouter y cobra por uso |
| Superadmin | **Aplicación aparte y pequeña**, repo propio |
| `sistema.briela.app` | Instalación propia de Briela: demo y banco de pruebas |
| `superadmin.briela.app` | El panel central de seriales, suscripciones y IA |
| Cuenta de GitHub | **`gonzoita`** — repos privados |

---

## 2. Las tres piezas

### 2.1 Briela ERP — repo `gonzoita/briela`

El código heredado del SGI, **single-tenant**. Una instalación por cliente, con
su base de datos, su dominio y su marca. Es el 95 % del código y ya funciona.

Lo que se le agrega: cliente de licencia, pantalla de bloqueo, asistente de
instalación, actualizador, y que la IA salga por el proxy de Briela en vez de
OpenRouter directo.

Las sedes se quedan tal cual: `ContextoSede` sigue sirviendo para las sucursales
**de ese cliente**. Cada instalación es multisede por dentro.

### 2.2 Briela Superadmin — repo `gonzoita/briela-superadmin`

Aplicación Laravel **nueva y chica**, en `superadmin.briela.app`. No hereda las
110 tablas del ERP. Responsabilidades:

- Clientes, suscripciones y estado de pago.
- Emisión y revocación de **seriales**.
- **Proxy de IA** hacia OpenRouter, con medición de consumo por cliente.
- Registro de versiones publicadas (para el actualizador).
- Telemetría mínima: qué versión corre cada instalación y cuándo validó.

Repo aparte y no un módulo del ERP por una razón concreta: **el código que valida
los seriales no debe viajar dentro de la instalación del cliente.**

### 2.3 `sistema.briela.app` — la instalación propia

Una instancia normal del ERP, que sirve de vitrina para vender y de banco de
pruebas para cada versión antes de liberarla. Es el primer cliente de Briela.

---

## 3. Por qué no hay multi-tenancy (registro de la decisión)

La versión anterior de este plan eligió "una base de datos por empresa" con
`stancl/tenancy`, subdominio por cliente y base central de tenants. Con el modelo
de instalación por cliente, **todo eso sobra**: si cada empresa tiene su propia
instalación y su propia base, el aislamiento ya es físico y no hay nada que
multiplexar. El problema difícil —aislar 110 tablas, 101 modelos y 98
controladores que nacieron sin noción de empresa— **desaparece por completo**.

Se descarta entonces: `stancl/tenancy`, el subdominio por empresa, la base central
de tenants, el aprovisionamiento automático y todo el trabajo de volver
`Configuracion`, `Sede`, `Rol` y `PerfilMarca` "por empresa". Ya lo son: cada
instalación tiene los suyos.

Queda vigente del análisis anterior una sola cosa, y conviene no perderla: el
filtrado por sede del SGI es **opt-in** (`ContextoSede::aplicar()` llamado a mano
en 33 archivos), y un enlace directo a un documento de otra sede sigue
abriéndose. Dentro de una misma empresa eso es tolerable —es lo que el SGI
decidió a propósito—, pero es una deuda heredada que conviene tener presente si
algún cliente pide separación estricta entre sus sucursales.

---

## 4. Licenciamiento — diseño

### 4.1 Lo que hay que saber antes de construirlo

**En una instalación en el servidor del cliente, no existe forma técnica de
impedirle quitar el bloqueo.** Es PHP: código fuente legible, con acceso al
filesystem y a la base de datos. Cualquier validación que viva ahí es una línea
que se puede comentar. Se puede hacer molesto; nunca imposible.

La decisión tomada es **bloqueo total**, y así se construye. Pero el diseño no
debe apostar el negocio a ese candado:

| Palanca | Qué tan efectiva |
|---|---|
| Bloqueo total de la app | **Disuasiva** en el servidor del cliente · **Efectiva** en el servidor de Briela |
| Corte del proxy de IA | **Efectiva siempre** — la llave nunca está en el cliente |

De ahí que el modelo de ganancia por IA (sección 5) sea además la protección
real: no depende de que el cliente no toque el código.

### 4.2 Cómo funciona

- **Serial**: opaco, emitido por el superadmin, atado a un cliente y a un
  dominio. Formato tipo `BRL-XXXX-XXXX-XXXX`.
- **En la instalación**: se pide en el asistente de instalación y se guarda con
  el estado de la licencia, la fecha de la última validación correcta y el fin de
  la gracia.
- **Latido (heartbeat)**: la instalación consulta
  `superadmin.briela.app/api/licencia/validar` cada pocas horas. La respuesta va
  **firmada con clave asimétrica** (el superadmin firma con la privada, la
  instalación verifica con la pública embebida). Así una respuesta "activo" no se
  puede fabricar apuntando a otro servidor.
- **Gracia offline — obligatoria**: si el servidor de licencias no responde, la
  instalación sigue trabajando N días (propuesta: 7) antes de bloquear. **Sin
  esto, una caída del servidor de Briela deja fábricas paradas**, y el daño de
  reputación es peor que el de un pago atrasado.
- **Bloqueo**: middleware global → pantalla de "suscripción vencida". Se deja
  pasar solo el login del administrador y la pantalla de pago, para que pueda
  regularizar y desbloquear sin soporte.
- **Al reactivar**: el siguiente latido desbloquea solo, sin tocar nada.

### 4.3 Lo que hay que decidir al construirlo

- ¿El bloqueo deja **leer** los datos o cierra todo? Cerrar todo con un pago de
  tres días de atraso es agresivo para una fábrica en producción. Recomendación:
  avisos visibles antes del vencimiento, y bloqueo de escritura antes que
  bloqueo total.
- ¿Se puede exportar los datos con la licencia vencida? Un "sí" reduce mucho la
  fricción de venta: el cliente sabe que sus datos no quedan de rehén.

---

## 5. Proxy de IA — el modelo de ganancia

Hoy el ERP habla con OpenRouter directo, con la llave guardada en
`Configuracion`. En Briela la llave **nunca** vive en la instalación del cliente:
la instalación llama al superadmin, y el superadmin habla con OpenRouter.

**Buena noticia, ya verificada:** toda la salida está concentrada en
[IaService.php](../app/Services/IA/IaService.php) (767 líneas), con cuatro puntos
de salida:

| Punto | Uso |
|---|---|
| `POST /chat/completions` con `stream: true` | El asistente escribiendo en pantalla |
| `POST /chat/completions` sin stream | Textos y el paso de "decidir" |
| `POST /audio/speech` y `chat/completions` con `modalities:[text,audio]` | La voz (pcm16 por streaming) |
| `GET /models` | La lista de modelos, cacheada un día |

Cambiar la URL base y la autenticación es un cambio **localizado en un archivo**,
no disperso por el sistema.

Lo que el superadmin tiene que hacer en cada llamada:

1. Validar el serial y que la suscripción esté al día.
2. Reenviar a OpenRouter con la llave de Briela.
3. **Medir tokens y costo real**, registrarlos por cliente.
4. Aplicar el límite del plan.

Dos puntos delicados que hay que resolver bien:

- **Streaming pass-through (SSE)**: los trozos tienen que reenviarse sin
  bufferizar, o el asistente deja de "escribir en vivo" y se siente roto. Es la
  parte más técnica de la fase.
- **Margen**: registrar costo real contra lo cobrado. OpenRouter cobra 5,5 %
  sobre cada recarga, y las imágenes van por USD 0,04 cada una. Sin medición por
  cliente, el margen es una corazonada.

**Límite honesto del modelo:** como el cliente tiene el código, puede apuntar
`IaService` a OpenRouter con su propia llave. No se puede evitar. Pero entonces
asume cuenta, recarga y gestión de saldo. **La IA de Briela se vende como
conveniencia, no como monopolio** — y hay que ponerle precio así.

---

## 6. Instalador y actualizaciones

### 6.1 Asistente de instalación

Ruta `/instalar`, disponible solo mientras no haya instalación hecha:

1. Chequeo de requisitos: PHP 8.3, extensiones, permisos de escritura.
2. Datos de la base y creación del `.env` + `APP_KEY`.
3. `migrate` + semillas base (roles, permisos, configuración inicial).
4. Primer usuario administrador.
5. **Serial** y primera validación.
6. `storage:link` y marca del cliente (logo y colores).
7. Se cierra: `/instalar` queda bloqueado.

Dos puntos que definen si esto sirve o no:

- **`vendor/` tiene que venir en el zip.** El cliente promedio no tiene composer
  en su hosting. Igual `public/build/` ya compilado — que en este proyecto ya
  está trackeado en git, así que sale gratis.
- **Dos vías**: zip para clientes no técnicos, git para los técnicos y para las
  instalaciones que aloje Briela.

### 6.2 El botón de actualizar (estilo WordPress)

La meta es un botón dentro de Briela: "hay una versión nueva, actualizar". Para
el cliente, igual que WordPress.

**Cómo se entrega: paquete zip firmado, no `git pull`.** El "desde git" queda del
lado de Briela — se publica desde git, GitHub Actions arma el paquete, y la
instalación descarga un zip. Las razones son concretas:

| `git pull` desde la instalación | Zip firmado |
|---|---|
| Exige git instalado en el servidor del cliente | Solo `ZipArchive`, disponible casi siempre |
| Exige `shell_exec`, a menudo deshabilitado en compartido | Sin ejecución de comandos |
| Exige un **token del repo privado en el servidor de cada cliente** — acceso permanente a versiones futuras y otras ramas | No expone el repositorio |
| Trae lo que haya en la rama | Se controla exactamente qué va en el paquete |

Ayuda mucho que en este proyecto **`public/build/` ya está trackeado en git**: los
assets compilados viajan listos y el cliente nunca necesita Node ni Vite.

**Los pasos del botón**, en orden, y ninguno es opcional:

1. Verificar requisitos y **permisos de escritura** antes de tocar nada.
2. Modo mantenimiento.
3. **Respaldo de la base de datos.**
4. Descargar el zip, verificar **firma y hash**.
5. Copiar los archivos actuales a un lado (para poder volver).
6. Extraer y reemplazar — **nunca** `.env`, `storage/`, ni `public/storage`.
7. `migrate --force` y limpieza de cachés (config, rutas, vistas).
8. Salir de mantenimiento y reportar la versión al superadmin.
9. **Si algo falla en cualquier punto: rollback** — restaurar archivos y base.

Tres puntos que definen si esto sirve o es un juguete:

- **Rollback real.** Un actualizador que puede dejar la instalación a medias, en
  un servidor al que no tienes acceso, es peor que no tener actualizador.
- **Por pasos, no en un solo request.** En hosting compartido con
  `max_execution_time` de 30 s, reemplazar miles de archivos no cabe en una
  petición. Tiene que avanzar por etapas con progreso en pantalla, como hace
  WordPress.
- **`vendor/` viaja en el paquete.** El cliente no tiene composer. El zip pesa
  más (decenas de MB), y a cambio la actualización no depende de nada instalado
  en su servidor.

### 6.3 Fragmentación de versiones — el problema real

Actualizar una instalación es un problema técnico y se resuelve. **Tener diez
clientes en cuatro versiones distintas es un problema de negocio y no se
resuelve: se previene.**

El costo de soporte no crece con el número de clientes, crece con el número de
**versiones vivas**: cada bug reportado hay que reproducirlo en la versión de ese
cliente, no en la propia. Es así como un producto instalable se vuelve
inmantenible con muy pocos clientes.

Cuatro reglas, para decidir ahora y no cuando ya haya diez instalados:

1. **La actualización es obligatoria, no opcional.** Anclada al serial: la
   licencia exige la versión vigente o la inmediatamente anterior. Si el cliente
   elige cuándo, en seis meses hay cinco versiones vivas. Es la regla que más
   trabajo ahorra.
2. **Migraciones siempre hacia adelante y nunca destructivas.** Cada una debe
   poder correr sobre cualquier versión anterior soportada. Nada de renombrar o
   eliminar columnas sin un período de compatibilidad.
3. **Cero personalización en el código de un cliente.** Lo que alguien necesite
   distinto va como opción configurable en el core. La primera vez que se parchea
   la instalación de un cliente "solo por esta vez", nace una versión que ya no se
   puede actualizar.
4. **Telemetría de versión** en el superadmin: qué versión corre cada instalación
   y cuándo validó. Sin eso se trabaja a ciegas.

Más: **respaldo automático antes de cada migración**. Correr `migrate --force` sin
supervisión en el servidor de un cliente es el escenario que ya costó dos pérdidas
totales de datos (15 jul 2026), multiplicado por cada cliente.

`sistema.briela.app` prueba cada versión antes de que se libere. Ninguna versión
llega a un cliente sin haber corrido ahí primero.

### 6.4 Dónde conviene que vivan las instalaciones

La decisión comercial es "ambos", y como oferta está bien. Pero el **default
operativo debería ser el servidor de Briela**, y el self-hosted la excepción —
cobrada aparte, o condicionada a acceso SSH.

Para el cliente la diferencia es casi nula: entra por su dominio, con su marca.
Para Briela es enorme:

| | En el servidor de Briela | En el del cliente |
|---|---|---|
| Actualizar 10 instalaciones | Un comando | 10 actualizadores que pueden fallar sin acceso |
| Bloqueo por serial | **Efectivo** | Disuasivo |
| Soporte | Se puede entrar a mirar | Por teléfono, pidiendo credenciales |
| Dependencias | Un servidor conocido | El hosting que contrató cada quien |

El self-hosted cuesta más de lo que parece y no paga más. Si un cliente lo exige
por política interna, va como plan aparte, con requisitos de servidor por escrito
y soporte limitado.

---

## 7. Plan por fases

### Fase 0 — Higiene y arranque del repo · **hecha el 2 ago 2026**

Dos commits: `12a22d2` (arranque, 768 archivos) y `cd6c248` (borrado del código
muerto). El repositorio quedó en 180 migraciones y 96 modelos.

**Lo que quedó pendiente, y por qué:**

| Pendiente | Por qué |
|---|---|
| Correr las migraciones de cero sobre una base vacía | MySQL de Laragon no estaba arriba. Es la verificación que confirma que el borrado no rompió la cadena de FK |
| Crear los repos en `gonzoita` y hacer push | `gh` no está instalado en la máquina |
| Generar el grafo con `/graphify` | Es un skill de Claude Code, se lanza aparte |
| Definir el email de los commits | Se usó el de la cuenta del SGI; si `gonzoita` usa otro, GitHub no atribuirá los commits |

**Hallazgos del camino** (no estaban previstos en el plan):

- El `.env` de la copia **apuntaba a la base real de Interfrigo** y traía su llave
  de OpenRouter en texto plano. Un `migrate` en Briela habría escrito en la base
  de producción del SGI.
- 150 archivos temporales `.fuse_hidden` repartidos por `resources/` y `app/`.
- Credenciales SSH de Interfrigo en tres documentos del manual y en
  `.claude/settings.local.json`.
- `config/pdf_modulos.php` y `PdfVariablesEngine` apuntaban a las tablas muertas:
  **el editor de plantillas PDF de OP ofrecía las columnas de la tabla vieja.**
  Bug heredado, corregido.

---

*(Registro de lo que la fase implicaba, para referencia:)*

La copia estaba en `C:\laragon\www\briela` **sin git**.

Verificado contra el `.gitignore` heredado, que es mejor de lo esperado: `*.sql`
ya está ignorado ([.gitignore:34](../.gitignore)), junto con `/graphify-out/` y
`/.claude/`. Así que los respaldos (`backup_local_hoy.sql` 347 K,
`binlog_hoy2.sql` 8,8 M; `respaldo-sgi.sql` está en 0 bytes) no entrarían a un
commit. Conviene igual **moverlos fuera del proyecto** —no borrarlos, el binlog
es del incidente del 15 jul—: son datos de otra empresa dentro del árbol de un
producto que se va a vender.

**Esto sí entraría al primer commit y hay que sacarlo:**
`alter_op_items.php`, `crear_tabla.php`, `crear_tabla2.php`, `edit-vue-dump.txt`,
`rutas.txt`, `HANDOFF.md`.

El resto de la fase:

- `git init` con historial limpio. Nunca un fork: el historial del SGI lleva la
  contraseña de producción de Interfrigo en texto plano.
- **Repos privados en `gonzoita`**: `briela` y `briela-superadmin`.
- El `user.name` de git en esta máquina es `Blueffalo`. Definir con qué identidad
  firman los commits de Briela y configurarla **en el repo**, no global.
- Borrar el código muerto: `OrdenProduccion`, `LineaOP`, `ItemOP`,
  `InventarioItem`, `InventarioMovimiento` y sus migraciones.
- Sacar credenciales y datos de Interfrigo de `CLAUDE.md`, `.env` y
  `.github/workflows/deploy.yml`. Secretos nuevos en la cuenta `gonzoita`.
- **Reescribir `CLAUDE.md` para Briela** — hoy sigue siendo el de Interfrigo, con
  el SSH y la contraseña de Hostinger dentro.
- Ajustes al `.gitignore`: agregar los archivos sueltos, y **cambiar `/.claude/`**
  por reglas finas — versionar `.claude/CLAUDE.md` y `.claude/skills/`, ignorar
  solo `settings.local.json` y `scheduled_tasks.lock`. En un producto con
  colaboradores, esas instrucciones conviene compartirlas.
- **Regenerar graphify**: `graphify .` (no viajó en la copia, y el skill sí está
  en `.claude/skills/graphify`). Correr `graphify update .` después de cada fase.

**Criterio de aceptación:** el primer commit no contiene ninguna credencial ni
ningún dato de Interfrigo. Verificado leyendo el commit, no de memoria.

*Aparte, para el SGI: esa contraseña de producción debería rotarse de todos modos.*

### Fase 1 — Desacoplar de Interfrigo

Que `sistema.briela.app` quede en pie como instalación propia y presentable.

- Marca configurable de verdad: `PerfilMarca` ya existe, pero el color `#0A4283`
  y el logo de Interfrigo están sembrados en el código. Que la instalación se vea
  como el cliente, no como Interfrigo.
- Identidad de Briela: nombre, colores, favicon, correos.
- `.env.example` limpio y documentado para instalaciones nuevas.
- Salir de Google Drive: `ArchivoController` todavía sube a Drive primero. En
  Briela no hay archivos históricos que migrar, así que sale gratis hacerlo bien.
- Revisar `docs/manual/` uno por uno: la mayoría sirve como base, pero hablan de
  Interfrigo.

### Fase 2 — Superadmin y licencias

La app nueva (`briela-superadmin`) y el cliente de licencia en el ERP:

- Superadmin: clientes, suscripciones, emisión y revocación de seriales, firma
  asimétrica, endpoint de validación.
- ERP: guardado del serial, latido, **gracia offline**, middleware de bloqueo,
  pantalla de suscripción vencida.

**Criterio de aceptación:** un serial revocado bloquea la instalación en el
siguiente latido; y con el servidor de licencias apagado, la instalación sigue
trabajando los días de gracia y **no** se bloquea.

### Fase 3 — Proxy de IA

El modelo de ganancia. Los cuatro puntos de salida de `IaService` apuntando al
superadmin, con streaming que no se rompa, medición de consumo por cliente y
límites por plan.

**Criterio de aceptación:** el asistente sigue escribiendo en vivo y la voz sigue
funcionando, con la llave de OpenRouter **fuera** de la instalación; y el consumo
de cada cliente queda medido con su costo real.

### Fase 4 — Asistente de instalación

El `/instalar` de la sección 6.1, el empaquetado del zip con `vendor/` y el
bloqueo posterior.

> **Recomendación de orden:** esta fase se puede posponer hasta después del primer
> cliente. Las primeras instalaciones las puedes hacer tú por git, como haces hoy
> con el SGI. Construir un instalador pulido antes de tener a quién instalarle es
> trabajo que todavía no sabe qué necesita.

### Fase 5 — El botón de actualizar

Las dos mitades de la sección 6.2:

- **En el superadmin**: versionado, canal de publicación, armado del paquete
  firmado desde GitHub Actions, y el endpoint que responde "cuál es la última
  versión".
- **En la instalación**: el aviso, el botón, los nueve pasos con progreso en
  pantalla, y el **rollback**.
- **Para las instalaciones propias**: el comando que las recorre todas. Es el
  camino principal (sección 6.4) y el más barato de construir — es el deploy del
  SGI puesto en un bucle.

**Criterio de aceptación:** una instalación en la versión anterior se actualiza
sola desde el botón, sin acceso al servidor; y una actualización que falla a
mitad deja la instalación **funcionando en la versión anterior**, con su base
intacta.

Las cuatro reglas de la sección 6.3 (actualización obligatoria, migraciones no
destructivas, cero personalización por cliente, telemetría de versión) se
adoptan aquí y aplican desde el primer cliente.

### Fase 6 — Cobros

Pasarela, ciclo mensual, avisos antes del vencimiento y renovación que
desbloquee sola. Hasta aquí, cobrar por transferencia y activar el serial a mano
es perfectamente viable con pocos clientes.

---

## 8. Decisiones pendientes

1. **¿A quién se le vende?** La más importante y sigue sin respuesta. El sistema
   está hecho a la medida de fabricar por pedido con medidas variables
   (plantillas de ensamble con fórmulas, trabajos por unidad física, calidad con
   foto). Es una ventaja enorme frente a un ERP genérico, pero solo ante un
   comprador que fabrique algo parecido. **Definir antes de invertir meses.**
2. **Precio**: cuánto de licencia y cuánto de IA. Si el margen está en la IA, la
   licencia puede ser barata a propósito.
3. **Qué incluye el plan de IA**: cupo mensual, qué pasa al agotarse, si se puede
   recargar.
4. **Redes sociales**: cada cliente con sus propias apps de Meta, o apps de
   Briela. El módulo nunca se ha conectado de verdad.
5. **Soporte** con 20 instalaciones, y **quién mantiene el SGI de Interfrigo**
   ahora que los caminos se separan.
6. **Qué tanto se personaliza por cliente** sin volver el código un nudo. Con
   instalaciones separadas la tentación es grande, y es la forma más rápida de
   terminar con 20 versiones distintas imposibles de actualizar.

---

## 9. Reglas que no se rompen

Heredadas del SGI. Van al `CLAUDE.md` nuevo de Briela:

1. **Español colombiano neutro, prohibido el voseo** — UI, código, commits, chat.
2. Sin Ziggy · sin `resolvePageComponent` · sin CSS separado · vistas solo en Vue
   · `AppLayout.vue` en páginas autenticadas · **mobile-first siempre**.
3. ⛔ **Nunca `migrate:fresh`, `migrate:refresh` ni `db:wipe`** contra una base
   real. Causó dos pérdidas totales de datos el mismo día (15 jul 2026). **Con
   una instalación por cliente el riesgo se multiplica**, y ahora la base que se
   pierde puede no ser la propia.
4. Cada acción real dispara sola el siguiente paso del proceso. Evaluar cada cosa
   nueva bajo ese criterio.
