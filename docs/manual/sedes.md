# Sedes y numeración de documentos *(nuevo, 26 jul 2026)*

Primera fase del multi-sede. Permite que el SGI opere con varias sedes de la
empresa — unas de venta, otras de fábrica, o mixtas — y que los códigos de cada
documento se configuren desde el sistema, sin tocar código.

Acceso: solo **administrador**, desde Configuración → Organización.

## Sedes

Una sede se define por dos cosas independientes: si allí se **vende** y si allí
se **fabrica**. Por eso son dos casillas separadas y no un "tipo" fijo — así una
sede puede ser solo de ventas, solo fábrica, o las dos.

Sedes cargadas de entrada:

| Sede | Código | Ventas | Fábrica | |
|---|---|---|---|---|
| Bogotá | `BOG` | Sí | Sí | **Principal** |
| Cali | `CAL` | Sí | Sí | |
| Cúcuta | `CUC` | Sí | No | |

El **código** (3 letras) se usa para armar los códigos de documentos de esa
sede. La sede **principal** es la matriz: todo lo que ya existía en el sistema
antes de este cambio (bodegas, usuarios) quedó asignado a Bogotá.

Una sede no se puede eliminar si es la principal. Si tiene bodegas o usuarios
asociados, en vez de borrarse se desactiva, para no dejar datos huérfanos.

## Almacenes (bodegas) por sede

Cada bodega ahora pertenece a una sede, y **cada sede puede tener su propia
bodega principal**. Así se pueden tener, por ejemplo:

- Bogotá → Almacén Principal
- Cali → Almacén Sede Cali
- Cúcuta → Almacén 1, Almacén 2

El stock y los movimientos siguen colgando de la bodega, así que heredan la sede
automáticamente.

## Selector de sede activa

Quien tenga acceso a más de una sede ve un selector en el encabezado (en el menú
lateral, si está en celular). La sede activa se guarda en la sesión, no en el
usuario — cambiarla no modifica su configuración.

En esta primera fase el acceso funciona así:

| Rol | Sedes que ve |
|---|---|
| administrador | Todas |
| jefe_producción, vendedor, operario | Solo la suya |

Esto se vuelve configurable en la Fase 2 (roles y permisos configurables), donde
se podrá definir por rol y por usuario a qué sedes y a qué almacenes accede cada
quien.

## Numeración de documentos

Antes, cada módulo armaba su consecutivo por su cuenta y el formato estaba
escrito en el código. Ahora todos los consecutivos viven en un solo lugar y se
configuran desde **Configuración → Numeración**, por sede y por documento:

- Órdenes de Producción · Cotizaciones · Remisiones
- Solicitudes de Compra · Órdenes de Compra · Series de ítems

De cada uno se puede cambiar el **prefijo** (ej. `OP-`, `CAL-OP-`), si **incluye
el año**, el **siguiente número** y la **cantidad de ceros**. La pantalla muestra
una vista previa en vivo de cómo quedará el código.

Los consecutivos de Bogotá continúan donde iban (no se reinician). Las sedes
nuevas arrancan en 1 y con su código de sede en el prefijo, para que los códigos
de una sede no choquen con los de otra.

> **Cuidado:** si bajas el "siguiente número" por debajo de uno ya usado, el
> sistema intentará repetir códigos que ya existen.

Detalle técnico: los números se entregan dentro de una transacción con bloqueo
de fila. Esto además corrige un riesgo que existía antes — dos documentos
creados en el mismo instante podían pelear por el mismo número.

## Módulos que ya filtran por sede

- **Inventario** — el stock, los movimientos y el tablero se calculan solo
  sobre las bodegas de la sede activa. Un ajuste o traslado a una bodega a la
  que no tienes acceso se rechaza.
- **Compras** — las solicitudes y órdenes pertenecen a una sede, se listan
  filtradas, y su número usa el consecutivo configurado para esa sede.
- **Producción** — la OP pertenece a la sede que la **fabrica** (una venta de
  Cúcuta se puede producir en Bogotá). El listado de OPs, los trabajos, el
  programador y las estaciones de trabajo se limitan a la sede activa, y el
  número de OP usa el consecutivo de esa sede.

- **Ventas y CRM** — clientes, cotizaciones y leads pertenecen a la sede de
  **venta**. Cada sede ve solo los suyos, y el número de cotización usa el
  consecutivo de esa sede.

- **Logística** — la remisión pertenece a la sede que despacha (hereda la de la
  OP) y su número usa el consecutivo de esa sede.
- **Financiero** — la cartera muestra las cuotas de las OPs de la sede activa.
- **RRHH** — los colaboradores pertenecen a la sede donde trabajan.
- **Mantenimiento** — equipos y mantenimientos son de la sede donde está la
  máquina.

- **Informes** — los informes dinámicos y los reportes del CRM respetan la sede
  activa. Además se puede agregar la columna **Sede** a cualquier informe: con
  "Todas las sedes" activo, eso convierte cualquier informe en un comparativo
  entre sedes.

Con esto el multi-sede queda completo en todos los módulos.

### Ojo con los clientes

Los clientes quedaron **separados por sede**. El día del despliegue todos los
clientes existentes pasaron a Bogotá, así que **los usuarios de Cali y Cúcuta
arrancan con la lista de clientes vacía**.

Para repartirlos: al crear o editar un cliente aparece el campo **Sede** (solo
si tienes acceso a más de una). Cambiarlo mueve el cliente — deja de verse
desde la sede anterior. Así se va armando la cartera de cada sede sin tocar la
base de datos.

### De cotización a OP: elegir la fábrica

Una sede de solo ventas (Cúcuta) sí puede generar OPs: al convertir la
cotización se pregunta **en qué fábrica se va a producir** (Bogotá o Cali). La
cotización queda registrada en la sede que vendió y la OP en la sede que
fabrica, sin duplicar el proceso.

Si por alguna razón no se elige, el sistema usa la sede activa cuando tiene
fábrica, y si no, la primera sede con producción (normalmente Bogotá).

## "Todas las sedes"

Quien tenga un rol con acceso a todas las sedes ve además la opción **"Todas
las sedes"** en el selector. Con ella activa, los listados muestran la
información de todas sus sedes junta — útil para comparar. Los documentos
nuevos que se creen en ese modo nacen en la sede propia del usuario, porque un
documento siempre tiene que pertenecer a una sede concreta.
