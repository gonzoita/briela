# Auditoría — bitácora de actividad

Ruta: `/auditoria` (solo administrador)

## Qué es

Un registro permanente de acciones realizadas por los usuarios sobre los datos
del sistema: quién creó, editó o eliminó qué, y cuándo. Sirve para poder
rastrear cualquier manipulación de un registro — si algo se borró, se movió o
se cambió, queda constancia aquí, sin importar en qué módulo haya ocurrido.

## Qué queda registrado hoy

Por ahora el registro automático está activo en estos modelos (los más
sensibles de la operación comercial):

- **Leads del CRM** (`CrmLead`)
- **Cotizaciones** (`Cotizacion`)
- **Clientes** (`Cliente`)
- **Productos** (`Producto`)
- **Órdenes de Producción** (`Op`)

Para cada uno se guarda automáticamente:

- **Creado**: cuándo se creó y quién.
- **Actualizado**: qué campos cambiaron, con el valor anterior y el nuevo
  (desplegable "Ver cambios").
- **Eliminado** / **Eliminado (def.)**: quién lo borró y cuándo. Como estos
  modelos usan borrado suave (`SoftDeletes`), el registro sigue existiendo en
  la base de datos aunque desaparezca de las vistas — esta bitácora dice
  además quién lo borró.
- **Restaurado**: si un registro borrado se recupera.

## Cómo se usa

Filtros disponibles: usuario, módulo (Lead, Cotizacion, Cliente, Producto),
tipo de acción, rango de fechas, y búsqueda de texto libre sobre la
descripción. La lista está paginada y ordenada del más reciente al más
antiguo.

## Cómo se extiende a otros módulos

Técnicamente, activar este registro automático en cualquier otro modelo del
sistema (Órdenes de Producción, Trabajos, Usuarios, etc.) es tan simple como
agregar `use Auditable;` a ese modelo — el trait ya hace el resto. Esto se
irá haciendo módulo por módulo a medida que se revisa cada uno, priorizando
los que manejan dinero, compromisos con clientes o datos que no deberían
perderse sin dejar rastro.
