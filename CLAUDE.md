# Briela — Contexto del proyecto para Claude Code

**Briela** es un ERP para fabricantes por pedido, que se vende e **instala en el
servidor de cada cliente**. Nació el 1 ago 2026 a partir de un ERP interno ya
probado en operación real, y desde entonces es un producto independiente.

Repositorio: https://github.com/gonzoita/briela (privado)
Superadmin: https://github.com/gonzoita/briela-superadmin (privado, app aparte)
Rama principal: main | Cuenta: gonzoita

**Documentos maestros del proyecto** — leerlos antes de diseñar algo nuevo:
- `docs/BRIELA-PLAN.md` — arquitectura decidida y plan por fases. **Fuente de verdad.**
- `docs/BRIELA-CONTEXTO.md` — documento de arranque y decisiones de origen.
- `docs/MANUAL-BRIELA.md` — **el sistema completo de una sola lectura**, módulo por módulo,
  incluida la IA. Es el mejor punto de entrada para entender qué existe.
- `docs/manual/00-indice.md` — el detalle, una página por módulo (42).
- `docs/OPERACION.md` — **cómo trabajar**: SSH al servidor, montar el proyecto en otro
  computador, git y despliegue, el grafo, y cómo arrancar un chat nuevo sin gastar de más.

---

## Lo que Briela NO es

- **No es el sistema del que se originó.** Ese ERP interno sigue vivo en su
  propio repositorio, su servidor y su base, y **no se toca nunca**. Se usa como
  banco de pruebas en operación real; no es cliente de Briela ni forma parte del
  producto. **Su nombre no debe aparecer en este repositorio.**
- **No es multiempresa por dentro.** Se evaluó y se descartó: cada cliente tiene
  su **propia instalación con su propia base de datos**, así que el aislamiento es
  físico. No hay `tenant_id`, no hay `stancl/tenancy`, no hay base central de
  tenants. Ver `docs/BRIELA-PLAN.md` sección 3.
- **No es un SaaS de una sola instancia.** Hay N instalaciones, y cada una se
  actualiza. Eso condiciona todo: ver "Reglas del producto instalable".

---

## Las tres piezas

| Pieza | Qué es |
|---|---|
| **Briela ERP** (este repo) | El sistema. Single-tenant. Una instalación por cliente |
| **Briela Superadmin** (repo aparte) | `superadmin.briela.app` — seriales, suscripciones, proxy de IA, versiones |
| **`sistema.briela.app`** | La instalación propia: demo y banco de pruebas antes de liberar versiones |

La lógica de negocio del licenciamiento **no vive en este repo**: este código
viaja al servidor del cliente.

---

## Stack tecnológico

- **Backend**: Laravel 13 + PHP 8.3
- **Frontend**: Vue 3 + Inertia.js 2.x (SPA)
- **CSS**: Tailwind CSS — mobile-first SIEMPRE
- **Build**: Vite 8 — `public/build/` está trackeado en git (NO en .gitignore).
  Es lo que permite que el cliente instale sin Node ni Vite.
- **Auth**: Laravel Breeze + Sanctum
- **DB**: MySQL

---

## Reglas que nunca se rompen

1. **Idioma: español colombiano neutro. PROHIBIDO el voseo.** Nunca "tomá /
   decí / entrá / hablá". Siempre "toma / di / entra / habla". Aplica a la UI,
   comentarios de código, mensajes de commit y respuestas de chat.
2. **Sin Ziggy** — todas las URLs son strings hardcodeados en los componentes Vue.
3. **Sin resolvePageComponent** — el resolve de Inertia usa `import.meta.glob`, **sin
   `eager`**: cada pantalla queda en su propio archivo y se trae al abrirla. Llevaba
   `eager: true` hasta el 12 ago 2026, y eso metía las 128 pantallas en un solo archivo
   de 2,9 MB que el navegador descargaba y procesaba en cada primera carga. Medido en el
   servidor real, lo que bloquea el dibujado bajó de 2.980 KB a 356 KB. De paso, un
   cambio ya no invalida el bundle completo: solo el trozo de la pantalla que se tocó.
4. **Sin CSS separado** — el CSS va dentro del bundle JS generado por Vite.
5. `app.blade.php` usa `@vite(['resources/js/app.js'])` — sin CSS separado.
6. Todas las vistas son componentes Vue en `resources/js/Pages/`. Nunca Blade para vistas.
7. Las rutas usan `Inertia::render()` en `routes/web.php`.
8. El layout principal es `Layouts/AppLayout.vue` — úsalo en todas las páginas autenticadas.
9. **Mobile-first siempre.** Diseña primero para celular, luego adapta a desktop.
10. **El `step` de un campo numérico iguala la precisión de su columna.** Los porcentajes y
    el dinero son `decimal(_,2)` → `step="0.01"`; las cantidades son `decimal(_,3)` →
    `step="0.001"`. Un `step` más grueso que la columna hace que el navegador rechace un
    valor que la base sí acepta: con `step="0.1"` no se puede escribir una comisión de
    2,25%, y el campo no explica por qué. Solo llevan `step="1"` —o ninguno— los que son
    enteros de verdad: píxeles, puertos, puntos, minutos, intentos, días.
    Para mostrarlos, `resources/js/formato.js`: `formatPct` (hasta 2 decimales, sin ceros
    de relleno), `formatCOP`, `formatCantidad`. Tres pasos al 33,33% mostrados con
    `toFixed(0)` daban «33%» tres veces, que suma 99 y parece un error de cuentas.

---

## Colores: nunca un gris fijo de Tailwind para líneas ni realces

`border-linea` delimita, `divide-separador` separa filas, `hover:bg-realce` marca el
renglón del puntero. Los tres cambian con el tema. **Prohibido** `divide-gray-50`,
`border-gray-50` y `hover:bg-blue-50`: son colores fijos que en modo noche dejan líneas
casi blancas —15,6:1 contra la superficie— y hovers que destellan. Ver
`docs/manual/marca.md`.

Los fondos de aviso van con las familias del tema, **nunca con un color fijo**. Cada
familia —azul, verde, ámbar, rojo, violeta, naranja— tiene cuatro clases y las cuatro se
necesitan:

| Clase | Para qué |
|---|---|
| `bg-pastel-rojo` | El fondo de la caja |
| `bg-pastel-rojo-2` | El fondo de la insignia que va **dentro** de la caja |
| `border-borde-aviso-rojo` | Su borde |
| `text-aviso-rojo` | Su texto |

Un `bg-red-50` con `text-red-700` escritos a mano quedan claro sobre claro de día y
oscuro sobre oscuro de noche: ilegibles de las dos maneras. El 15 ago 2026 se cambiaron
596 fondos, 1.188 textos y 279 bordes; ya no queda ninguno fijo, y hay que mantenerlo
así. Los fondos **saturados** (`bg-red-500` de un botón, con `text-white`) sí se quedan:
funcionan igual en los dos temas.

## Listas: el orden lo pide la pantalla

Toda lista paginada se ordena por lo que elija el usuario. Tres piezas, y no hay que
inventar una cuarta:

- **`App\Support\Orden::aplicar($query, $request, $permitidos, $defecto, $dir)`** en el
  `index()` del controlador. El campo llega del navegador, así que **jamás** entra al SQL
  tal cual: cada lista declara qué columnas se pueden ordenar y lo que no esté en esa lista
  se ignora en silencio. Devuelve `['campo' => …, 'dir' => …]`, que va a la vista como
  `'orden'`.
- **`useOrden(url, orden, filtros)`** (`resources/js/composables/useOrden.js`) en la
  pantalla. El orden viaja en la URL, no en memoria: así el enlace se puede compartir, el
  botón de atrás funciona y recargar no pierde lo elegido.
- **`OrdenarLista.vue`** para el control visible, y **`BotonOrden.vue`** dentro de un `<th>`
  cuando la lista es una tabla. El botón va **dentro** del `<th>` que ya existe: cada lista
  tiene su propio estilo de encabezado, y reemplazarlo entero obligaba a copiar esas clases
  dieciséis veces.

Siempre hay desempate por `id`: dos filas con el mismo nombre tienen que salir siempre en
el mismo orden, o la paginación repite unas y esconde otras.

> Al agregar el orden a una pantalla, **`const props = defineProps(...)`**, no `defineProps`
> a secas. Varias pantallas lo tenían sin asignar, y `useOrden(url, props.orden, …)` sobre un
> `props` que no existe es un `ReferenceError` **al montar**: el build pasa —es JavaScript
> válido— y la pantalla queda en negro. Le pasó a Usuarios el 15 ago 2026. Y al agregar orden a una lista
que ya existía, el **orden por omisión no cambia** — mover las filas de sitio en una
pantalla que nadie pidió tocar se siente como un error.

## Velocidad: nada cuesta una petición por clic

El sistema tiene que sentirse instantáneo. No es un adorno: quien lo usa cambia de
pantalla cien veces al día, y medio segundo de más en cada cambio es medio día de
trabajo al año mirando una barra de progreso.

**`AppLayout` NO es un layout persistente de Inertia.** Las 110 pantallas lo escriben
en su plantilla (`<AppLayout title="…">`), así que Inertia lo destruye y lo vuelve a
construir en CADA navegación, con todo lo que lleva dentro. De ahí salen las tres
reglas siguientes, y las tres se rompen igual de fácil.

**1. Nada dentro del layout pide datos en `onMounted`.** Un `fetch` al montar es un
`fetch` por clic del menú. El 17 sep 2026 eran seis —`/notificaciones`,
`/api/comentarios/pendientes`, `/api/chat/conversaciones`, `/api/chat/grupos`,
`/api/asistente/historial` y las disciplinas del operario—, y como las sesiones van en
archivo, **Laravel las atiende en fila**: cada una espera el candado de la sesión de
la anterior. Medido con `php artisan serve`: cambiar de pantalla disparaba 5 peticiones
y 14,2 s sumados, con `/notificaciones` tardando 5,5 s. Después: **1 petición, 1,1 s**.
Lo que se ve siempre —un contador, una insignia— va en un módulo compartido
(`resources/js/composables/useAvisos.js`) con **un solo** temporizador; lo que solo se
ve al abrir un panel se pide **al abrirlo** (`useAsistente.js`).

**2. Un componente pesado que casi nadie abre no se monta: se monta al abrirse.**
`AsistenteBurbuja` y `ChatBurbuja` son 1.050 líneas que se construían en cada
navegación para que casi siempre nadie las tocara. Van con `v-if` + `nextTick` antes de
llamar a su `ref`. Su contador sale del módulo, no del componente: si saliera del
componente, habría que construirlo entero solo para saber si hay un 3.

**3. Todo `addEventListener` sobre `window` o `document` tiene su
`removeEventListener` en `onUnmounted`, y con función CON NOMBRE.** Una función anónima
no se puede quitar: `removeEventListener` necesita la misma referencia. Cuatro escuchas
sin quitar × un remontaje por clic = basura que crece toda la jornada y retiene una
instancia completa del layout cada una. Es la razón de que el sistema se pusiera más
lento cuanto más tiempo llevaba abierta la pestaña, y de que recargar lo «arreglara».
Lo que debe ocurrir una sola vez por sesión se marca con `soloUnaVez()` del módulo:
un `let` de `<script setup>` no sirve, porque nace de nuevo con cada instancia.

**4. En el servidor, un ajuste no es una consulta.** `Configuracion::get()` lee la tabla
entera UNA vez por petición. El bloque `marca` de `HandleInertiaRequests::share()` pide
nombre, color, logo, logo oscuro, favicon, favicon oscuro, correo y web en cada
navegación: eran 11 consultas antes de mirar un solo dato del negocio, y ahora es 1.
Lo mismo vale para cualquier cosa que `share()` agregue: **lo que se comparte se paga en
cada clic**, así que se memoriza o se manda como `fn () =>` para que Inertia lo resuelva
solo cuando toca.

> El caché de petición va en el **contenedor** (`app()->instance(...)`), nunca en una
> propiedad `static`. Una estática vive lo que vive el proceso, no la petición: en las
> pruebas, donde un mismo proceso corre los 104 casos seguidos, sobreviviría al
> `RefreshDatabase` del siguiente y devolvería datos de una base que ya se borró.

**Al agregar algo al layout o a `share()`, la pregunta es: ¿esto cuesta una petición o
una consulta en cada clic del menú?** Si la respuesta es sí, todavía no está listo.

## Reglas del producto instalable

Propias de Briela; no existían en el sistema de origen. Si se rompen, el producto se vuelve
inmantenible con menos de diez clientes.

1. **Cero personalización en el código de un cliente.** Lo que alguien necesite
   distinto va como **opción configurable** en el core. La primera vez que se
   parchea la instalación de un cliente "solo por esta vez", nace una versión que
   ya no se puede actualizar.
2. **Migraciones siempre hacia adelante y nunca destructivas.** Cada migración
   debe poder correr sobre cualquier versión anterior soportada. Nada de renombrar
   ni eliminar columnas sin un período de compatibilidad. Al otro lado hay bases
   de clientes a las que no tienes acceso.
3. **Respaldo automático de la base antes de cada migración** en el actualizador.
4. **La actualización es obligatoria**, anclada al serial: la licencia exige la
   versión vigente o la inmediatamente anterior.
5. **Nada que dependa del hosting del cliente.** No asumir `shell_exec`, ni git
   instalado, ni composer, ni `mysqldump`, ni permisos de escritura: **verificar
   y degradar con elegancia.**
6. **La llave de OpenRouter nunca vive en la instalación.** El asistente sale por
   el proxy del superadmin. Es el modelo de ganancia y la única palanca de
   licenciamiento realmente efectiva.
7. Nunca sobreescribir en una actualización: `.env`, `storage/`, `public/storage`.

---

## Entorno local

- OS: Windows | Servidor: Laragon
- PHP: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- Composer: `C:\laragon\bin\composer\composer`
- Proyecto: `C:\laragon\www\briela`
- DB local: MySQL — base **`briela`** — usuario `root` — password vacío
- URL local: http://localhost:8000

**En cada terminal nueva ejecutar primero:**
```powershell
$env:Path = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;" + $env:Path
```

**Comandos útiles:**
```bash
npm run dev        # Vite dev server
npm run build      # Build de producción (incluye PWA/SW)
php artisan serve  # Servidor PHP
```

> ⚠️ La base local de Briela es **`briela`**. Cualquier otra base es de otro
> proyecto: **jamás apuntar el `.env` de Briela ahí.** El `.env` heredado de la
> copia venía apuntando a la base del sistema de origen; ya se corrigió, pero
> conviene verificarlo antes de correr cualquier `migrate`.

---

## Esquema de base de datos

El esquema funcional es el heredado del sistema de origen, descrito módulo por módulo en
`docs/manual/`. Lo esencial:

### Producción — el flujo real

**`ops`** (modelo `Op`) — la Orden de Producción
`id, sede_id, numero, token_publico, cliente_id, cotizacion_id, responsable_id`
`estado`: `borrador → confirmada → en_produccion → calidad → despachada`
  (+ `reproceso` cuando calidad rechaza)
`porcentaje_avance` — se recalcula solo al avanzar los trabajos
`calidad_aprobada_at` — **candado obligatorio** antes de remisión/despacho
timestamps, softDeletes, auditado

**`op_items`** (modelo `OpItem`) — cada línea de la OP, con `variables_instancia`
(las medidas de ESTE ítem) y `componentes_snapshot` (la receta ya calculada,
congelada).

**`op_item_trabajos`** (modelo `OpItemTrabajo`) — **uno por unidad física**, con
su `token_trabajo` (QR del operario).

**`op_item_trabajo_pasos`** — los pasos, con peso, dependencias, tiempos y fotos.
**`op_item_trabajo_paso_operarios`** — pivot: quiénes trabajaron cada paso.

### Resto del sistema, por área

- **Comercial**: `CrmLead` → `Cotizacion` → `CotizacionItem`, `ComisionVendedor`,
  `Cliente`, `ContactoCliente`.
- **Cotizador**: `PlantillaEnsamble` → `PlantillaCampo` / `PlantillaComponente` /
  `PlantillaSeccion`; `Ensamble`. Motor: `FormulaEvaluatorService`.
- **Plantillas de trabajo**: `TemplateTrabajo` → `TemplateTrabajoPaso`,
  emparejado 1 a 1 con `PlantillaEnsamble`.
- **Inventario**: `Producto` + `Bodega` + `ProductoStock` + `ProductoMovimiento` +
  `CategoriaProducto`.
- **Compras**: `SolicitudCompra`, `OrdenCompra`, `OrdenCompraItem`, `Proveedor`.
- **Logística**: `Remision` → `RemisionItem`.
- **Capacitación**: `Curso` → `CursoModulo` → `CursoLeccion`; evaluaciones,
  inscripciones, certificados.
- **RRHH**: `Operario` + disciplina, horas extra, permisos, bonos, puntos,
  niveles, `EstacionTrabajo`.
- **Transversal**: `Notificacion`, `RegistroActividad` (auditoría),
  `Configuracion` (clave/valor), `Archivo` (morph), `Sede`, `Rol`.

### Multisede — dentro de cada instalación

Cada cliente puede tener varias sedes (`App\Support\ContextoSede`). Casi todo se
filtra por la sede activa del encabezado. Si algo "no aparece", revisar la sede
antes que el dato.

> Deuda heredada conocida: el filtrado por sede es **opt-in** —
> `ContextoSede::aplicar()` se llama a mano— y un enlace directo a un documento de
> otra sede sigue abriéndose. Entre sedes de una misma empresa es tolerable. Si un
> cliente pide separación estricta entre sus sucursales, hay trabajo.

---

## Roles y permisos — configurables

Catálogo de permisos en código (`App\Support\Permisos`) + roles configurables
desde la interfaz (tabla `roles`). `User::permisos()` devuelve los efectivos.
Middleware **`permiso:`** (`VerificarPermiso`) en rutas nuevas. `VerificarRol` es
legado, no usar.

**Usuarios de prueba (seeder):** conviene renombrarlos a dominio `briela.app` al
tocar los seeders.

---

## Servicios clave

- `IaService` — **el único punto de salida a IA**, 767 líneas, 4 endpoints
  (`chat/completions` con y sin stream, `audio/speech`, `GET /models`). Cuando se
  construya el proxy, es aquí y en un solo lugar.
- `NotificacionService` — punto único de avisos. Catálogo en `::catalogo()`.
- `FormulaEvaluatorService` — motor de fórmulas del cotizador (Symfony
  ExpressionLanguage, **no** `eval()`).
- `TrabajoAutoGeneratorService` — genera trabajos y pasos al crear un `OpItem`.
- `PuntosColaboradorService` — gamificación, idempotente.
- `EvaluacionService` — califica intentos, genera certificado y puntos.
- `BuscadorGlobalService` — buscador Ctrl+K.
- `BackupService` — respaldo; **cuidado**: cae a PHP si no hay `mysqldump`.
- `Auditable` (trait) — auto-registra create/update/delete.
- `SmtpConfigService::aplicar()` — carga el SMTP desde `Configuracion`.

> **Las pruebas corren contra `briela_test`**, declarada en `phpunit.xml`; nunca contra
> `briela`. Si esa base no existe, las 51 de Feature fallan todas por conexión y solo pasan las
> 16 unitarias — parece un código roto y es una base que falta. Se crea una vez:
> `mysql -u root -e "CREATE DATABASE IF NOT EXISTS briela_test"`. Al 7 oct 2026: 243 en verde.

**Tareas programadas** (`routes/console.php`, requieren cron):
`cotizaciones:marcar-vencidas` · `notificaciones:entregas-proximas` ·
`notificaciones:cursos-por-vencer` · `notificaciones:recordatorios` · `stock:liberar-reservas` ·
`rrss:publicar-programadas` · `rrss:revisar-tokens` ·
`whatsapp:sincronizar-plantillas`

**Portales públicos (sin login):** `/op/{token}` · `/seguimiento` (exige apellido
o documento) · `/cotizaciones/{token}/aprobar` · `/verificar-certificado/{codigo}`
· `/catalogo/productos/{id}` y `/catalogo/ensambles/{id}` (con sus `/pdf`) ·
`/planta/{token}`.

> No existe un índice `/catalogo`: solo fichas individuales. El manual heredado
> decía `/catalogo` y devuelve 404 — verificado el 2 ago 2026.

El QR de trabajo del operario (`/trabajo/{token}`) **sí exige login**.

---

## Proceso de producción — el flujo del negocio

1. Lead en el CRM → cotización con el cotizador de ensambles (medidas → fórmulas
   → materiales → precio).
2. El cliente aprueba la cotización (puede hacerlo desde el link público).
3. La cotización aprobada genera la **OP** (`borrador → confirmada`).
4. Al crear cada ítem se generan los trabajos: uno por unidad física, con los
   pasos copiados de la plantilla del ensamble.
5. El operario entra por QR y completa pasos (con operarios, tiempo y fotos). El
   avance recalcula la OP y la pasa sola a `en_produccion`.
6. Cuando todos los ítems terminan, la OP pasa sola a `calidad`.
7. Calidad: foto + observaciones. Falla → `reproceso`. Aprueba → se sella
   `calidad_aprobada_at`, **candado obligatorio** para remisionar.
8. Remisión → **lo remisionado sale del almacén al crearla** (productos tal cual, ensambles por sus
   componentes); la OP se despacha cuando la remisión sale (admite parciales).

**Principio de fondo:** cada acción real dispara sola el siguiente paso del
proceso. Al construir algo nuevo, evaluarlo también bajo ese criterio.

---

## Estado actual

Plan completo en `docs/BRIELA-PLAN.md` sección 7.

| Fase | Qué | Estado |
|---|---|---|
| 0 | Higiene, `git init`, repos en `gonzoita`, este archivo | **hecha** |
| 1 | Desacoplar la marca: identidad configurable, salir de Google Drive | **hecha** |
| 2 | Superadmin + licencias (serial, latido, gracia offline, bloqueo) | **hecha** |
| 3 | Proxy de IA — el modelo de ganancia | **hecha** |
| 4 | Asistente de instalación (`/instalar`) | pendiente |
| 5 | El botón de actualizar (zip firmado) + rollback | pendiente |
| 6 | Cobros recurrentes | pendiente |

**Cierre obligatorio de toda tarea** (decidido el 23 sep 2026): al terminar un cambio, sin
preguntar, siempre las tres cosas: (1) actualizar el grafo (`python -m graphify update . --force`
y luego `cluster-only .` con `GRAPHIFY_VIZ_NODE_LIMIT=10000`) **y comprometerlo**, porque desde
el 8 oct 2026 `graphify-out/graph.json` va al repo, (2) commit y `git push` de la rama
de trabajo, y (3) deploy: llevar el cambio a `main` sin reescribir historia y hacer
`git push origin main`, que es lo que dispara el despliegue en `sistema.briela.app`. Antes de
subir a `main`, las pruebas y el build (`npm run build`) tienen que estar en verde.

**Hasta que el usuario declare la primera versión, se despliega directo:** el servidor jala de
GitHub por cron y no hay paquetes ni actualizador. Ver `docs/manual/deploy-automatico.md`. Y
comprometer no es desplegar — sin `git push` el cambio no sale de la PC.

Lo construido después de la fase 3, que conviene conocer antes de tocar algo cerca:

- **Precios por canal** (`canal_precios`, morph a producto y ensamble). La empresa define sus
  canales en Segmentación y marca dos papeles: **canal base** (piso de utilidad, sin comisión ni
  descuento) y **precio público** (respaldo del cliente sin segmentar, y precio del catálogo web).
  Las columnas viejas `precio_mayorista` y compañía siguen existiendo por compatibilidad y las
  escribe un espejo; no se leen para cotizar.
- **Ensamble directo** (`ensambles.tipo_armado`): receta escrita a mano, sin plantilla ni
  fórmulas. Guarda sus componentes con la MISMA forma que los calculados, y por eso la OP, el
  inventario y los PDF no los distinguen.
- **Una OP declara DOS bodegas** (exigidas al confirmar, o precargadas con la principal si arranca
  sola desde borrador): `ops.bodega_material_id`, de dónde salen los **componentes de los
  ensambles** al remisionar; `ops.bodega_entrega_id`, de dónde salen los **productos**. Confundirlas
  descontaba contra una bodega en cero y `registrarMovimiento()` lo recortaba en silencio.
- **El inventario sale al CREAR la remisión, y por un solo sitio** (`InventarioRemisionService`,
  llamado por `RemisionService::crear`; 14 oct 2026). Un producto sale tal cual; un ensamble sale por
  sus **componentes** (receta de una unidad × unidades que viajan); un servicio no se mueve. Cada
  movimiento queda con origen `remision`, y **anular o borrar devuelve exactamente esos movimientos**
  —leídos de la base, no recalculados—. Si algo no alcanza se descuenta lo que hay y se avisa
  (`material_faltante`). **Terminar en planta no mueve inventario** y ya no existe
  `EntregaAlmacenService`; una unidad que alcanzó a entrar como producto terminado (`entregado_at`)
  sale como tal. Cada unidad sabe en qué remisión salió (`op_item_trabajos.remision_id`), y anular
  suelta exactamente esas. **Crear, mover y anular remisiones pasa por `RemisionService`**, lo use la
  pantalla o la guía.
- **Una lista de bodegas para elegir sale de `ContextoSede::bodegasParaElegir()`**, nunca de
  `idsBodegasVisibles()` a secas: esa cruza bodegas con sedes y devuelve **cero** en una
  instalación donde las bodegas no tienen sede asignada, que es el estado natural de una empresa
  de una sola sede. Un selector vacío ahí no deja confirmar ninguna OP.
- **El margen es un recargo sobre el costo**, y la cuenta vive en **un solo lugar**:
  `PreciosPorCanalService::precioDesdeCosto()` — `ceil(costo × (1 + m/100) / 1000) × 1000`.
  `usePreciosPorCanal.js` la repite porque la pantalla la necesita mientras se teclea; si se
  cambia una, se cambia la otra, y `tests/Unit/PrecioDesdeCostoTest.php` fija los números.
  Estuvo escrita tres veces con tres resultados y el mismo ensamble valía tres precios.
  **El margen de un canal jamás se escribe en el código**: sale de
  `PreciosPorCanalService::margenDe()` —la fila guardada del ítem, y si no, el margen del canal
  en Segmentación—. Un mapa de claves internas con respaldo fijo 30/32,5/35 hacía que todo
  ensamble medido se cotizara al 32,5 % en cuanto la empresa creaba sus propios canales.
  Cambiar un margen no reprecia lo guardado: eso lo hace `php artisan precios:recalcular`,
  y después **siempre** `comisiones:recalcular`.
- **Cerrar un paso pasa por `CierrePasoService`, y por ningún otro sitio.** Las cuatro
  pantallas que cierran un paso —QR del operario, panel de la OP, hoja del trabajo, tablero—
  llaman ahí. Antes cada una lo hacía distinto, y de eso salían tres problemas que parecían no
  tener relación: la entrega a bodega ocurría en momentos distintos, los puntos solo se
  otorgaban por el QR pero se devolvían desde la hoja, y la bodega se preguntaba de tres
  maneras. **Un cierre nuevo se agrega llamando al servicio, nunca escribiendo `completado` a
  mano.**
- **Las fechas del proceso las pone `OpItemTrabajo::recalcularAvance()`, y nadie más.**
  `iniciado_at` al primer toque de cualquier paso; `terminado_at` al cerrarse el último — y esa
  **es** la hora de llegada a calidad, no hay una segunda columna: es un solo hecho. Van ahí
  porque es el punto único por el que pasa cualquier cambio de avance; escritas en las cuatro
  pantallas que cierran pasos, la fecha dependería de por dónde entró quien marcó.
- **El paso final solo da la unidad por terminada**: no pide bodegas ni mueve inventario
  (`bodegas_sugeridas.pide = false`), y **no se cierra con otros pasos pendientes**.
- **Las unidades siguen a la cantidad del ítem** (`TrabajoAutoGeneratorService::
  sincronizarParaItem`), y la pantalla lo **pregunta** antes de guardar. Nunca se borra una
  unidad con avance. Y `items.*.id` **tiene que seguir en las reglas de validación** de
  `OpController`: `validate()` devuelve solo lo que valida, y sin esa regla cada guardado de
  una OP recreaba sus ítems y se llevaba en cascada unidades, pasos y revisión.
- **La remisión es por unidad, y lo que la abre es `op_item_trabajos.calidad_revisada_at`** —
  la firma de calidad sobre ESA unidad—, no «no le quedan puntos pendientes». Esa segunda
  versión dejaba fuera a las unidades **sin lista de revisión**, que son casi todas: no tenían
  puntos, así que nunca quedaban listas, el tablero no las mostraba y su botón «Terminar» no
  cambiaba nada. **Los puntos son lo que hay que mirar para firmar; no son la firma.**
- **Trabajos y Calidad comparten la ficha**, `resources/js/Components/FichaProceso.vue`: una
  tarjeta grande por unidad física con un botón por paso o por punto de revisión. Es el mismo
  gesto —mirar la unidad, tocar el paso, seguir—, así que la pantalla es la misma; escribirla
  dos veces habría hecho que se separaran al primer arreglo. Los botones marcados llevan color
  saturado con texto blanco, que es lo único que se ve igual de día y de noche; el pendiente
  lleva un tinte de su color con transparencia sobre la superficie. La urgencia sale de
  `App\Support\Urgencia::de($fechaEntrega)`, no de un campo de prioridad que nadie llena.
- **Calidad es un módulo con permiso propio** (`/calidad`, `ops.calidad`), separado de
  `trabajos.ver`: quien revisa no siempre puede tocar la producción. Por eso los endpoints de
  los puntos existen bajo **dos prefijos** —`/trabajos/checks/...` y `/calidad/checks/...`—
  apuntando al mismo `CalidadCheckController`. `RevisionCalidad.vue` recibe cuál usar en su
  propiedad `base`.
- **Un punto de revisión con `exige_foto` no se marca sin foto**, y el servidor lo rechaza
  igual que la pantalla: `ModalFoto.vue` la pide con la cámara o con un archivo, y las dos
  entradas importan —en planta se toma con el celular, en la oficina se sube la que mandaron—.
  El atajo «Terminar» de una unidad **no** cubre esos puntos: devuelve 422 con cuáles faltan.
- **`costos.ver`** es un permiso aparte: el costo no se manda al navegador de quien no lo tiene.
- **Los módulos se apagan por instalación** (`App\Support\Modulos`, `/configuracion/modulos`, y
  desde la ficha en el superadmin; en el latido gana el cambio más reciente). No hay planes que
  los restrinjan. Un módulo apagado **se lleva sus permisos** en `User::permisos()`, y con eso se
  van el menú, las rutas y lo que ve la IA; lo que no pasa por permisos lo corta
  `BloquearModuloApagado` por prefijo de URL. **Un módulo nuevo se agrega al catálogo** con sus
  permisos, sus rutas públicas y de qué depende. **Un paso del flujo que pertenece a un módulo
  pregunta `Modulos::activo()` y se salta**, nunca se queda esperando: sin Calidad la unidad
  terminada se firma sola; sin Stock no se piden bodegas ni se mueve inventario. Ver
  `docs/manual/modulos.md`.
- **La bandeja es UNA pantalla y DOS juegos de tablas** (`/bandeja`, módulo y permisos
  `bandeja.*`). WhatsApp vive en `whatsapp_conversaciones`/`whatsapp_mensajes` y las redes en
  `bandeja_conversaciones`/`bandeja_mensajes`: no se unificaron porque renombrar una tabla está
  prohibido en un producto instalado, y porque el almacenamiento de verdad es distinto —WhatsApp
  identifica por teléfono y Meta por un identificador por página—. Lo que **sí** es uno solo es
  todo lo de arriba: una lista (un `UNION` normalizado en el `SELECT`, no una mezcla en PHP, que
  daría mal la segunda página), un hilo, una caja de respuesta. Un canal nuevo se agrega
  escribiendo un adaptador de `CanalBandeja` y poniéndolo en la lista de `AppServiceProvider`.
- **El plazo de 24 horas de Meta se pregunta ANTES de enviar, y lo calcula un solo sitio**
  (`EsConversacion::ventanaAbierta()`). Corre desde el **último mensaje de la persona**, no desde
  el último de la conversación: contestar no reabre nada, y `ultimo_entrante_at` jamás lo escribe
  un mensaje nuestro —hacerlo sería regalarse 24 horas que Meta no dio—. El error de Meta habla
  de «re-engagement message» y no de un plazo vencido, así que dejar que falle allá deja a quien
  atiende creyendo que envió. Fuera del plazo, en WhatsApp sale una **plantilla aprobada**
  (`whatsapp_plantillas`, espejo de Meta, no se crean desde acá) y en Instagram y Messenger no
  sale nada: no tienen plantillas.
- **Las reglas de cada canal viven en `App\Support\Canales`**, no en las pantallas: plazo, si
  admite plantillas, si admite adjuntos, de qué módulos depende. Son reglas de Meta y cambian por
  canal; escritas en cada pantalla, la bandeja ofrecía botones que la API rechazaba.
- **Los dos webhooks de Meta rechazan lo que no pueden verificar** (`/webhook/whatsapp` y
  `/webhook/meta`, que comparten firma y token de verificación porque son la misma app). Sin App
  Secret no entra nada, salvo en `local`. Antes se aceptaba todo y solo quedaba una línea en el
  log: cualquiera que supiera la URL podía inventar mensajes y meter leads falsos al CRM, que
  además se repartían solos entre los vendedores.
- **Un comentario de la propia página no se procesa.** La respuesta vuelve por el mismo webhook,
  y sin ese corte el agente de IA se contestaba a sí mismo en bucle, en público. Y contestar
  comentarios con IA es un interruptor **aparte y apagado de fábrica**: una respuesta automática
  en un comentario la lee cualquiera y la indexa Google.
- **Lo que llega por un canal se baja al servidor** (`AdjuntoBandeja`, `categoria='bandeja'`):
  los enlaces que da Meta vencen en minutos, y guardar la URL dejaba un historial de imágenes
  rotas al día siguiente. Por eso `archivos.subido_por` es nulable: un archivo que llega de
  afuera no lo subió nadie del sistema.
- **Los permisos de las redes vencen a los ~60 días y `rrss:revisar-tokens` los vigila**: renueva
  con 20 días de margen lo que se puede renovar solo (Meta estirando el token, Google con su
  `refresh_token`) y avisa a los 15 de lo que exige reconectar a mano. El aviso va a los
  administradores, no a quien publica: reconectar exige entrar al Business Manager.
- **Por dentro todo está en la moneda base** (`App\Support\Monedas::base()`, el peso colombiano
  mientras la instalación no diga otra; se congela con la primera cotización u orden). Nunca
  escribir `'COP'` ni «pesos» en código nuevo: pregunta `Monedas::base()` (PHP) o `monedaBase()`
  (`formato.js`). Un costo en USD/EUR guarda
  `productos.costo_moneda` y `precio_costo` (pesos) sale de la tasa del día; una cotización en
  otra moneda guarda sus ítems en pesos y una tasa, que solo sirve para **mostrar**. Nunca
  guardar un monto en otra moneda en una columna que se suma en informes o comisiones. La tasa
  la trae `tasas:actualizar` (TRM de datos.gov.co; euro cruzado con el BCE), que recalcula
  costos y cotizaciones abiertas. Ver `docs/manual/monedas-y-retenciones.md`.
- **Lo tributario no se escribe en el código.** UVT, tarifas y bases de retención viven en
  Configuración → Perfil fiscal (`App\Support\Fiscal`); la cuenta, en `RetencionesService`, y
  la pantalla se la pide al servidor en vez de repetirla.
- **Lo que depende de un país es un paquete de datos, no código** (`App\Support\Paises`,
  `resources/paises/{ISO}.json`): identificaciones, impuestos, tarifas, documento fiscal. Un país
  nuevo es un archivo. Lo que la empresa ajuste va aparte (`paises_ajustes`) y una actualización
  no lo pisa. La casa es el país de la sede principal; un cliente o proveedor sin país es de la
  casa. **Los paquetes de Panamá y Venezuela no los ha revisado un contador**: no se presentan como
  verdad. Ver `docs/manual/paises.md`.
- **El costo puesto en bodega sale de una importación liquidada**
  (`LiquidacionImportacionService`, la cuenta en un solo lugar; la pantalla la pide al servidor).
  Liquidar reprecia lo calculado y deja el producto fuera de la tasa diaria; una liquidada no se
  edita. Ver `docs/manual/importaciones.md`.
- **El correo sale por el panel de Briela, como la IA.** `App\Mail\TransporteBriela` es un
  transporte de Laravel: todo `Mail::` va al superadmin con el serial, y el superadmin envía con
  Brevo o Amazon SES desde el subdominio verificado de la instalación (Cloudflare). La
  instalación **no** guarda credenciales de correo; el SMTP propio es solo respaldo. Un correo
  masivo se marca con `X-Briela-Tipo: masivo`; las notificaciones no se cobran nunca. Ver
  `docs/manual/correo.md`.
- **El verificador de pagos solo LEE, y toda fuente entra por un solo sitio**
  (`VerificadorPagosService::importar()`). Hoy la fuente es el extracto subido (`.xlsx`/`.csv`,
  `App\Services\Banco\`); la conexión de banca abierta (Belvo) será **otra fuente** que llama a ese mismo
  método. **Los datos bancarios de una empresa nunca pasan por el superadmin ni se guardan allí**:
  la conexión se configura en la cuenta de cada instalación, que habla directo con el proveedor con
  credenciales cifradas propias; la membresía solo decide si la conexión en vivo consulta (como la
  IA) y nunca detiene la operación. Solo pasan los **abonos**, y solo se concilia sola una cuota cuando no queda duda —la orden
  nombrada con el valor exacto, o el valor exacto con el cliente en la descripción—: ante dos
  explicaciones se pregunta, nunca se adivina. El pago que registra es el mismo de la cartera
  (`op_pagos`), marcado con `verificado_at`; deshacerlo pide `cartera.eliminar`. Ver
  `docs/manual/verificador-pagos.md`.
- **El estado de la OP se decide en `Op::sincronizarEstado()`, y en ningún otro sitio.** Lo
  llaman alistar (`OpItem::alistar()`, el único que escribe la marca de un producto), cerrar o
  reabrir un paso, firmar calidad y que salga o se anule una remisión. **Una acción nueva que
  cambie algo de la orden llama ahí; nunca escribe `estado` a mano.** Antes cada camino hacía su
  parte y alistar un producto no movía la orden. La orden se **despacha cuando la remisión sale**
  (en camino/entregada), no al crearla; el inventario ya salió al **crear** la remisión (candado
  `remisiones.inventario_movido_at`). Lo remisionable es **una sola regla**:
  producto con algo por despachar —alistado o no— o unidad firmada por calidad (`OpItem::cantidadDisponible()` y el scope
  `disponiblesParaRemision`); los servicios nunca viajan (`Op::TIPOS_SIN_DESPACHO`) **ni frenan**
  el paso a calidad (`Op::cuentanParaListo()`). Rechazar en calidad, por cualquier pantalla, es
  `Op::mandarAReproceso()`: reabre lo que hay que rehacer y le quita la firma; cambiar solo la
  etiqueta dejaba las unidades remisionables y la orden rebotaba sola a «Calidad».
- **Solo los ensambles pasan por planta; un producto se alista y ya es remisionable** (14 oct 2026).
  `TrabajoAutoGeneratorService::templateDe()` devuelve nada para un producto y `procesarEnPlanta()`
  lo rechaza; los que la 1.5.0 alcanzó a mandar a planta vuelven a alistar en
  `ops:sincronizar-estados` (`quitarUnidadesDeProductos()`). **Una OP en borrador arranca sola** en
  cuanto algo queda listo, con las bodegas precargadas (`Op::iniciarProduccionSiAplica()`). **Un
  producto se remisiona aunque no esté alistado** y la remisión lo alista: `OpItem::cantidadDisponible()`
  es lo despachable, `cantidadLista()` lo ya alistado (la pestaña «Por remisionar» y Alistamiento).
- **La guía hace transacciones, siempre con confirmación** (`App\Services\IA\TransaccionesIaService`,
  14 oct 2026): generar OP, alistar, remisionar, mover remisión, registrar pago, confirmar abono del
  verificador, cerrar pasos, aprobar calidad, cambiar estado de cotización, mover y crear leads.
  **Proponer no escribe**: ubica todo y arma un resumen que la pantalla muestra en una tarjeta.
  **Confirmar** (`POST /api/asistente/confirmar`) vuelve a resolver, exige que salga lo mismo que se
  propuso y ejecuta con **los mismos servicios que las pantallas** —nunca un camino propio—. Cada una
  exige el permiso de su pantalla. Una transacción nueva se agrega al catálogo con su `preparar…` y su
  `hacer…`.
- **El CRM se entera de la cotización** (`CrmLead::alCambiarCotizacion()`, desde `CotizacionObserver`):
  aprobada → lead ganado; rechazada o vencida → perdido con el motivo, si no le queda otra cotización
  viva. La cotización automática del lead usa las condiciones configuradas (`CrmLead::crearCotizacion()`),
  convertir a cliente reutiliza el que ya exista (documento, correo o celular), y un lead de otra sede
  no se abre por enlace directo. Todo lead entra por `LeadEntranteService`, también las novedades del
  agente de IA (canal `agente_ia`).
- **Sin cliente no hay cotización ni orden** (`Cotizacion::SIN_CLIENTE`, `Op::SIN_CLIENTE`, 15 oct
  2026). La columna sigue nulable por lo viejo; la regla la exige cada puerta: formularios, cambio de
  estado, enlace público, duplicar, `generarOp`, alistar, cerrar paso (`CierrePasoService`),
  `RemisionService::errores` y la guía. Una acción nueva sobre una orden o cotización pregunta lo mismo.
- **Alistamiento parcial** (`op_items.cantidad_alistada`): `OpItem::alistar($alistar, $cantidad)`
  recibe el **total** alistado; `cantidadLista()` es lo alistado sin salir y `cantidadAlistada()` lo
  alistado (lo remisionado cuenta). `en_proceso` = alistado en parte, y también arranca la orden.
  `OpItem::booted` hace que la cantidad siga a la marca cuando alguien escribe `estado_item`.
  Cada renglón de remisión congela lo que quedó por entregar (`cantidad_pendiente`).
- **La remisión entregada se cierra sola** (`cerrada`) cuando la orden está pagada con pagos
  verificados (`Op::pagadaYVerificada()`; verificado = el verificador o `verificado_por` a mano). Un
  solo sitio: `RemisionService::sincronizarCierre()`, llamado por los eventos de `OpPago` y al
  entregar. «Salió» es `Remision::SALIDAS` (en camino, entregada, cerrada): nunca escribir
  `['en_camino','entregada']` a mano.
- **Firmas**: `FirmaPad.vue` es el único lienzo de firma (remisión y perfil). Una firma es siempre
  `data:image/(png|jpeg);base64,…`, validada al guardar y otra vez al imprimir
  (`PdfVariablesEngine::imgFirma`). La del usuario va en `users.firma` (oculta en `toArray`) y sale
  en los PDF con el bloque `pdf/_firma.blade.php` o las variables `{{!vendedor.firma}}`,
  `{{!responsable.firma}}`, `{{!firma.despacho}}`, `{{!firma.recibido}}`.
- **Chat y campana en vivo sin websockets**: la conversación abierta pide `?desde={id}` cada 4 s;
  la campana late contra `/api/avisos/pulso` cada 15 s y solo trae las listas si la firma cambió.
  Los avisos del chat apuntan a `/chat/{usuario}` y `/chat/grupos/{grupo}`, que abren la burbuja.
- **Informes: la IA elige, el servidor calcula** (`App\Services\Informes\MotorInformes`, 16 oct 2026).
  Cada fuente es una clase (`Informes\Fuentes\*`) con columnas tipadas, dimensiones, medidas,
  filtros y cifras clave; respeta el permiso de su pantalla y la sede. La guía pide un informe con
  un plan JSON que `normalizarPlan()` limpia; a la redacción solo llega `paraIa()` —totales y
  resúmenes, **nunca filas**— y el texto queda como `informes.analisis`. El periodo se guarda por
  nombre (`Periodo`). Exportar: `ExportadorInformes` (CSV `;` + BOM + coma decimal + protección
  contra fórmulas) y `EscritorXlsx` (sin librerías; sin `zip` cae al CSV). Una fuente nueva = una
  clase en `MotorInformes::fuentes()`. Las cuatro de siempre siguen en `InformeService`.
- **Meta entra por un solo webhook y se vincula con un botón** (17 oct 2026). `/webhook/meta` reparte
  por el `object` del cuerpo —`whatsapp_business_account` a `WhatsAppService`, `page` e `instagram`
  a sí mismo—; `/webhook/whatsapp` queda como alias. La firma se comprueba en **un solo sitio**
  (`App\Support\FirmaMeta`) y **los dos webhooks tienen que estar en la lista de CSRF de
  `bootstrap/app.php`**: PHPUnit apaga el CSRF, así que ninguna prueba de POST avisa si falta (a
  `/webhook/meta` le faltaba y en producción respondía 419). WhatsApp se vincula con Embedded Signup
  (`EmbeddedSignupService`, `useMetaSdk.js`, `ConectarMeta.vue`): **un número en coexistencia
  jamás se registra en la API en la nube** —lo sacaría de la app del celular—, y lo que el
  navegador diga de `waba_id` se contrasta con `debug_token`. Las suscripciones a los webhooks de
  páginas, de la cuenta y de la aplicación viven en `SuscripcionesMeta`; la versión de la Graph API
  que usa lo nuevo, en `ConexionMeta`. **Los permisos de Embedded Signup y los de páginas e
  Instagram no se pueden pedir en una sola ventana**: son dos autorizaciones, en un solo panel.
- **Una OP con trabajo hecho no cambia sus ítems** (`Op::itemsBloqueados()`), y el candado está
  en el servidor.

**Pendiente de negocio sin resolver, y es la más importante: a quién se le
vende.** El sistema está hecho a la medida de fabricar por pedido con medidas
variables. Es una ventaja enorme ante un fabricante parecido, y un problema ante
un comercio o una empresa de servicios.

---

## Comportamiento esperado del copiloto

**SIEMPRE:**
- Leer archivos relevantes antes de proponer código
- Verificar la estructura de carpetas existente antes de crear archivos
- Proponer comandos en el orden exacto en que deben ejecutarse
- Usar español en comentarios y mensajes de commit
- Antes de cambiar `AppLayout.vue`, considerar AMBAS versiones: mobile Y desktop
- Al diseñar algo nuevo, preguntarse **cómo se actualiza en 10 instalaciones**
- Al tocar `AppLayout`, `share()` o cualquier componente del encabezado, medir: ver
  «Velocidad: nada cuesta una petición por clic». Funcionar no basta — tiene que ser
  instantáneo

**NUNCA:**
- ⛔ **Correr `migrate:fresh`, `migrate:refresh` ni `db:wipe` contra una base
  real.** Causó DOS pérdidas totales de datos el mismo día (15 jul 2026) en el
  proyecto de origen. Con una instalación por cliente el riesgo se multiplica, y
  la base que se pierde puede no ser la propia. Para tests, `RefreshDatabase`
  dentro de archivos de test reales.
- ⛔ Apuntar el `.env` de Briela a la base de otro proyecto
- ⛔ Escribir en este repositorio el nombre, el código o las credenciales de la
  empresa donde se hacen las pruebas
- Inventar rutas con Ziggy o `route()`
- Usar `resolvePageComponent`
- Poner CSS en archivos separados
- Usar Blade para vistas (solo `app.blade.php`)
- Romper el flujo de estados de las OPs
- Asumir que una migración existe — siempre verificar

**Al encontrar un bug:**
1. Leer el archivo afectado completo
2. Identificar la causa raíz
3. Proponer la solución mínima necesaria (no refactorizar todo)
4. Mostrar solo las líneas que cambian con contexto suficiente

## graphify

**graphify es un skill de Claude Code (`/graphify`), no un comando de terminal.**
No existe un binario `graphify` en el PATH: las instrucciones heredadas del sistema de origen
decían lo contrario y estaban equivocadas.

- El grafo se genera invocando el skill `/graphify` sobre el proyecto. Deja sus salidas en
  `graphify-out/`.
- **El grafo va al repo**, para que esté al día en cualquier máquina y en cualquier sesión
  nueva sin reconstruirlo —reconstruirlo cuesta tiempo, y la parte semántica cuesta tokens—.
  Van `graph.json` (lo que leen las consultas) y `GRAPH_REPORT.md`. **No** van los
  `.graphify_*`, que guardan rutas absolutas de la máquina donde se generó y en otra no
  existen, ni `graph.html`, que lleva el grafo incrustado y se reescribe entero cada vez:
  comprometerlo sumaría varios MB a la historia en cada reconstrucción, en un repo que se
  clona en el servidor de cada cliente. Se regenera con `cluster-only .`.
- Cuando exista `graphify-out/`, sirve para preguntas de arquitectura y
  relaciones entre archivos antes de leer código a mano.
- Conviene regenerarlo después de cambios que muevan estructura (borrar módulos,
  mover carpetas), porque un grafo desactualizado es peor que no tenerlo.
- **Generado y al día.** Al 17 oct 2026: **11.648 nodos, 22.921 aristas, 666 comunidades**,
  actualizado sobre el commit `72dc616`. Cubre el proyecto completo: 926 archivos de
  código por AST y los 70 documentos por subagentes. Se actualiza con
  `python -m graphify update . --force` y luego `cluster-only .` con
  `GRAPHIFY_VIZ_NODE_LIMIT=10000`: pasado de 5.000 nodos, graphify no escribe `graph.html` si no.
  La reconstrucción entera con la extracción AST es gratis, sin LLM.
- **La caché semántica de los documentos va en `graphify-out/cache/` y NO al repo.** Guarda
  rutas absolutas de la máquina donde se extrajo, igual que los `.graphify_*`. Eso significa que
  **en una máquina nueva la parte de documentos se vuelve a pagar en subagentes**: lo que llega
  gratis en el clon es `graph.json`, que ya los trae dentro. Reconstruir en frío ahí sin volver a
  extraer los documentos **encoge el grafo** y graphify lo rechaza, que es lo correcto.
- **Las comunidades se nombran solas, con su archivo dominante** —«Servicios · IaService»— y
  **no** con una lista escrita a mano. Louvain las renumera en cada reconstrucción: el id 12 de
  hoy no es el de ayer, así que un mapa de nombres a mano termina poniendo «Autenticación»
  encima de otra cosa. Un nombre equivocado es peor que un número.
- La parte semántica —documentos e imágenes— **no se reextrae en cada reconstrucción**: exige
  subagentes y se paga en tokens. Lo que hay en caché se reaprovecha; el resto queda fuera, y
  por eso al reconstruir salta el guardián de encogimiento de graphify. Se fuerza a propósito:
  el código queda completo, que es para lo que se consulta el grafo.
