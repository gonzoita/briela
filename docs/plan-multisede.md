# Plan: Multi-sede + Roles configurables para SGI Interfrigo

Fecha: 26 jul 2026. **Estado: COMPLETADO — las 7 fases están construidas y
desplegadas.**

Para el uso diario ver [Sedes y numeración](./manual/sedes.md) y
[Roles y permisos](./manual/roles-permisos.md). Este documento queda como
registro del diseño y de las decisiones que se tomaron.

## Resumen de lo construido

| Fase | Qué quedó |
|---|---|
| 1 | Sedes (Bogotá/Cali/Cúcuta), bodegas por sede, numeración configurable, selector de sede |
| 2 | Roles y permisos configurables + rutas protegidas por permiso |
| 3 | Inventario y Compras por sede |
| 4 | Producción por sede (la OP pertenece a la fábrica) |
| 5 | Ventas y CRM por sede + elección de fábrica al generar la OP |
| 6 | Logística, Cartera, RRHH y Mantenimiento por sede |
| 7 | Informes por sede y comparativos entre sedes |

10 tablas llevan `sede_id`: clientes, cotizaciones, crm_leads, ops,
estaciones_trabajo, solicitudes_compra, ordenes_compra, remisiones, operarios y
equipos_mantenimiento. Bodegas y usuarios la recibieron en la Fase 1.

## Límites conocidos

- El filtrado por sede se aplica en los **listados**. Un enlace directo a un
  documento de otra sede sigue abriéndose. Cerrarlo requiere filtros a nivel de
  modelo (global scopes), que tienen más riesgo de romper referencias cruzadas
  (PDFs, remisiones, comisiones) y se dejó fuera a propósito.
- Los permisos protegen **por módulo**, no acción por acción dentro de cada
  módulo (salvo las acciones sensibles, que sí están separadas).
- Los clientes se reasignan de a uno, desde el campo Sede de su ficha. No hay
  acción masiva para mover muchos a la vez.

## 1. Objetivo

Que Interfrigo pueda operar con varias sedes (ventas y/o fábrica) desde el
mismo SGI, con los datos de cada módulo filtrados por sede, y con un sistema de
roles y permisos que el administrador pueda configurar sin tocar código.

El diseño queda abierto para, más adelante, dar el salto a multi-tenant (varias
empresas distintas usando el sistema) sin rehacer esta base — pero esa parte
(aislamiento entre empresas, registro público, cobros) **no se construye ahora**.

---

## 2. Sedes

Tabla nueva `sedes`:

| Campo | Qué es |
|---|---|
| `nombre` | Ej. "Bogotá", "Cali", "Cúcuta" |
| `codigo` | Corto, único, para prefijos: `BOG`, `CAL`, `CUC` |
| `tiene_ventas` | boolean — si en esta sede se crean clientes/cotizaciones |
| `tiene_produccion` | boolean — si en esta sede hay fábrica (trabajos, OPs) |
| `es_principal` | boolean — sede matriz (valores por defecto y migración de datos viejos) |
| `direccion`, `ciudad`, `telefono`, `nit` | datos propios de la sede (salen en PDFs, remisiones, etc.) |
| `activa` | boolean |

Dos banderas independientes (`tiene_ventas` / `tiene_produccion`) en vez de un
"tipo" rígido: así una sede puede ser solo ventas, solo fábrica, o mixta, sin
inventar categorías nuevas si mañana aparece otra combinación.

### Sedes de entrada (datos reales)

| Sede | Código | Ventas | Producción | Principal |
|---|---|---|---|---|
| Bogotá | `BOG` | Sí | Sí | **Sí** |
| Cali | `CAL` | Sí | Sí | No |
| Cúcuta | `CUC` | Sí | No | No |

*(las direcciones/teléfonos de cada una se cargan al construir la Fase 1)*

Pantalla nueva: **Configuración → Sedes** (CRUD, mismo patrón que Bodegas hoy).

---

## 3. Almacenes (bodegas) por sede

Hoy ya existe la tabla `bodegas` (con `es_principal` y `activa`), pero sin
noción de sede. Se le agrega `sede_id`, de modo que cada sede pueda tener uno o
varios almacenes, configurables desde Configuración → Bodegas:

- Bogotá → "Almacén Principal"
- Cali → "Almacén Sede Cali"
- Cúcuta → "Almacén 1", "Almacén 2"

El stock (`producto_stock`) y los movimientos (`producto_movimientos`) ya
cuelgan de la bodega, así que heredan la sede automáticamente — no necesitan
columna propia.

---

## 4. Roles y permisos configurables

**Este es el cambio más grande del plan.** Hoy el rol es un campo fijo en
`users` (`administrador | jefe_produccion | vendedor | operario`) y está escrito
a fuego en tres lugares: las rutas (`middleware('rol:administrador,...')`), el
menú del `AppLayout.vue`, y los métodos del modelo `User`. Reemplazarlo por
roles configurables toca todo eso.

### Cómo queda

- Tabla `roles`: `nombre` (ej. "Comercial"), `descripcion`, `es_sistema`
  (los 4 actuales, que no se pueden borrar), `activo`.
- **Catálogo de permisos definido en código** (no en base de datos), con el
  formato `modulo.accion`. Ej.: `clientes.ver`, `clientes.crear`,
  `clientes.editar`, `clientes.eliminar`, `ops.ver`, `ops.crear`,
  `ops.calidad`, `remisiones.despachar`, `comisiones.liquidar`, etc.
  Es la misma idea del catálogo de notificaciones que ya existe: una sola
  fuente de verdad que alimenta la pantalla de configuración.
- Tabla `rol_permiso`: qué permisos tiene marcados cada rol.
- `users.rol_id` (FK a `roles`) reemplaza el enum actual.

Con eso, tu ejemplo del rol "Comercial" queda así: marcas `clientes.*` completo,
`cotizaciones.*` completo, y de OPs y Trabajos solo `ver`. Sin tocar código.

### Alcance por sede y por almacén

Además de *qué puede hacer*, cada usuario define *dónde*:

- Tabla `usuario_sede`: a qué sede(s) tiene acceso el usuario.
- Tabla `usuario_bodega`: a qué almacén(es) — para casos como "ve la sede
  Cúcuta pero solo el Almacén 1".
- Un flag `todas_las_sedes` en el rol, para perfiles de dirección que deben ver
  todo sin listarlas una por una.

### Migración sin romper nada

Los 4 roles actuales se crean automáticamente como roles de sistema con los
permisos equivalentes a lo que hoy puede hacer cada uno, y cada usuario queda
enlazado al suyo. **Nadie pierde acceso el día del cambio.** El middleware
`rol:` se reemplaza por `permiso:` ruta por ruta, y el menú lateral pasa a
dibujarse según los permisos reales del usuario en vez de una lista fija.

Pantalla nueva: **Configuración → Roles y permisos** (crear rol, marcar
permisos por módulo con casillas, asignar rol y sedes a cada usuario).

---

## 5. Numeración y prefijos configurables

Hoy cada módulo genera su número a mano:

- OP: `OP-0001` (`Op.php`) · Cotización: `COT-2026-001` · Remisión: `REM-0001`
- Ítems serializados: `IF-2026-045-P-001`

Se reemplaza por **una tabla y un servicio único de secuencias**:

`secuencias_documento`: `sede_id` (nullable si el documento no es por sede),
`tipo_documento` (`op`, `cotizacion`, `remision`, `orden_compra`,
`solicitud_compra`, `serie_item`…), `prefijo` editable (ej. `"BOG-OP-"`),
`siguiente_numero`, `padding` (ceros).

Un `SecuenciaService` entrega el número con bloqueo de fila — de paso corrige un
riesgo que hoy existe: dos documentos creados en el mismo instante pueden pelear
por el mismo número.

Configurable desde **Configuración → Numeración**, por sede y por documento.

---

## 6. Qué se toca en cada módulo

Regla general: `sede_id` (FK) en la tabla principal de cada módulo. Todo lo
existente se migra a **Bogotá (Principal)** automáticamente. Los usuarios
actuales quedan todos en Bogotá.

| Módulo | Tabla(s) con `sede_id` | Notas |
|---|---|---|
| Usuarios | `users` + pivotes `usuario_sede`, `usuario_bodega` | Ver sección 4 |
| Inventario | `bodegas` | Stock y movimientos heredan |
| Producción | `ops`, `op_items`, `op_item_trabajos`, `estaciones_trabajo` | La OP pertenece a la sede que **fabrica** |
| Ventas / CRM | `clientes`, `cotizaciones`, `crm_leads` | Sede de **venta** que originó el negocio |
| Compras | `solicitudes_compra`, `ordenes_compra` | Sede que solicita/recibe |
| Logística | `remisiones` | Sede que despacha |
| RRHH | `operarios` | Sede donde trabaja |
| Mantenimiento | `equipos_mantenimiento` | Sede donde está el equipo |
| Financiero | (hereda de cotización/OP) | Sin columna propia |
| Capacitación | Sin cambio | Los cursos son de toda la empresa |

Caso Cúcuta (solo ventas): la cotización nace con `sede_id = CUC`, y al
convertirse en OP esa OP se asigna a la sede de fábrica que corresponda (Bogotá
o Cali). Así queda registrado quién vendió y quién fabricó, sin duplicar el
proceso.

**Filtros:** cada listado y cada informe lleva filtro por sede desde el momento
en que se le agrega `sede_id` — no se deja para después.

---

## 7. Lo que NO se construye en esta fase

- Aislamiento total entre empresas distintas (multi-tenant real).
- Registro público / onboarding automático de una empresa nueva.
- Cobros o planes de suscripción.
- Subdominio por empresa.

Nota para el futuro: el salto a multi-tenant sería agregar una tabla `empresas`
por encima de `sedes`. No hace falta crearla ahora ni afecta este plan.

---

## 8. Fases de construcción

1. **Sedes + Almacenes + Numeración**
   Tabla `sedes` con las 3 sedes reales, `sede_id` en `bodegas`, pantallas de
   configuración, `secuencias_documento` + `SecuenciaService`, y el selector de
   sede activa en el encabezado.

2. **Roles y permisos configurables**
   Tablas `roles` / `rol_permiso` / `usuario_sede` / `usuario_bodega`, catálogo
   de permisos, pantalla de configuración, migración de los 4 roles actuales,
   y reemplazo del middleware `rol:` por `permiso:` en todas las rutas + menú
   dinámico. *Fase larga: toca prácticamente todas las rutas del sistema.*

3. **Inventario y Compras** — `sede_id` operativo, filtros, y que cada
   solicitud/orden quede ligada a la sede y almacén correctos.

4. **Producción** — `sede_id` en OPs y trabajos, numeración por sede,
   estaciones de trabajo por sede.

5. **Ventas / CRM** — `sede_id` en clientes, cotizaciones y leads; numeración
   de cotización por sede; flujo cotización (sede venta) → OP (sede fábrica).

6. **Logística, Financiero, RRHH y Mantenimiento** — `sede_id` y filtros.

7. **Informes** — filtro por sede en todos los reportes existentes, y
   comparativos entre sedes.

Cada fase se despliega y se prueba antes de pasar a la siguiente.

---

## 9. Advertencia de alcance

Esto es considerablemente más grande que el módulo de RRSS: son ~7 fases que
tocan casi todas las tablas y todas las rutas del sistema. La Fase 2 (roles) en
particular es delicada porque un error ahí puede dejar a alguien sin acceso a
algo que necesita, o darle acceso a lo que no debería.

Recomendación: hacerlo en este orden, desplegando y probando fase por fase, y
no empezar la IA hasta terminar al menos las fases 1 y 2 — porque la IA va a
necesitar saber de qué sede son los datos que analiza.
