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

**Tareas programadas** (`routes/console.php`, requieren cron):
`cotizaciones:marcar-vencidas` · `notificaciones:entregas-proximas` ·
`notificaciones:cursos-por-vencer` · `notificaciones:recordatorios` ·
`rrss:publicar-programadas`

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
8. Remisión con firma → la OP se despacha y **consume el inventario al salir**
   (admite parciales).

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
- **Una OP declara DOS bodegas, y confundirlas descuenta contra el vacío.**
  `ops.bodega_material_id` es de dónde salen los insumos; `ops.bodega_entrega_id`, a dónde entra
  lo fabricado. Las dos exigidas al **confirmar** y no antes. Mientras fueron una sola, una OP
  que entregaba en bodega de producto terminado descontaba contra una bodega en cero,
  `registrarMovimiento()` lo recortaba con `max(0, …)` y el material seguía figurando entero
  donde estaba: un descuento que no descontaba nada, sin error y sin stock en rojo.
  Si el material no alcanza, se descuenta lo que hay y **se avisa** (`material_faltante` a
  administración y jefatura); no se bloquea al operario, que no puede arreglar un inventario
  desde la pantalla del QR.
- **Producción entra a bodega, y la bodega la decide la OP.** `ops.bodega_entrega_id`, exigido
  al **confirmar** la orden y no antes. Al cerrar el último paso, `EntregaAlmacenService`
  descuenta los materiales de esa unidad y registra su entrada como **producto terminado**
  (`productos.ensamble_id`). El despacho ya no vuelve a consumir material.
  `op_item_trabajos.entregado_at` es el candado contra la doble entrada.
  El `bodega_destino_id` del paso y la principal siguen como respaldo **solo** para las OPs
  nacidas antes del campo. El ensamble ya **no** pregunta «se guarda en bodega»: `maneja_stock`
  la prende sola la primera entrega, y por eso el formulario del ensamble **no debe volver a
  escribir esa columna** — mandarla en `false` desactivaría el producto terminado de un ensamble
  que sí tiene unidades en una estantería.
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
- **`costos.ver`** es un permiso aparte: el costo no se manda al navegador de quien no lo tiene.
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

- El grafo se genera invocando el skill `/graphify` sobre el proyecto. Deja sus
  salidas en `graphify-out/` (que está en `.gitignore` — se regenera).
- Cuando exista `graphify-out/`, sirve para preguntas de arquitectura y
  relaciones entre archivos antes de leer código a mano.
- Conviene regenerarlo después de cambios que muevan estructura (borrar módulos,
  mover carpetas), porque un grafo desactualizado es peor que no tenerlo.
- **Generado y al día.** Al 22 ago 2026: 6.657 nodos, 11.310 aristas, 650 comunidades,
  anclado al commit `422f73ad`. Se reconstruye entero con la extracción AST
  (gratis, sin LLM) y `parallel=False`.
- La parte semántica —documentos e imágenes— **no se reextrae en cada reconstrucción**: exige
  subagentes y se paga en tokens. Lo que hay en caché se reaprovecha; el resto queda fuera, y
  por eso al reconstruir salta el guardián de encogimiento de graphify. Se fuerza a propósito:
  el código queda completo, que es para lo que se consulta el grafo.
