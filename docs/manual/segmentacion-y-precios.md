# Segmentación de clientes y su efecto en el precio

**Configuración → Listas de segmentación.** Cuatro listas que sirven para
clasificar clientes: tipo de contacto, industria, proceso de seguimiento y
fuente de contacto.

Tres de ellas son etiquetas y nada más: sirven para filtrar y para saber de
dónde salió cada cliente. **La de tipo de contacto es distinta: decide cuánto
se le cobra al cliente.**

## Tipo de contacto → canal de precio

Cuando cotizas, el sistema mira el tipo de contacto del cliente y escoge solo
la columna de precio que le corresponde:

| Si el cliente está marcado como | Se le cotiza al precio | Comisión del vendedor |
|---|---|---|
| Mayorista | Mayorista | **Sin comisión** (precio fijo) |
| Distribuidor | Distribuidor | La del canal distribuidor |
| Cualquier otra cosa | Cliente final | La del canal cliente final |

Esto pasa solo, sin preguntar nada. Por eso vale la pena revisar el tipo de
contacto **antes** de cotizarle a un cliente nuevo.

**Si un cliente tiene marcados Mayorista y Distribuidor a la vez, gana
Mayorista** — el precio más bajo y sin comisión. No hay aviso en pantalla, así
que conviene no marcar los dos.

**Cliente directo y Prospecto no tienen precio propio**: ambos se cotizan como
cliente final.

## Por qué Mayorista y Distribuidor no se pueden borrar

En la pantalla de Listas de segmentación esas dos opciones aparecen marcadas
con la etiqueta **«define precio»** y no tienen botón de eliminar.

Es a propósito. Si se borraran, los clientes que las tuvieran no darían ningún
error: simplemente empezarían a cotizarse al precio de cliente final, y nadie
se enteraría hasta revisar una factura. Si no las necesitas, **desactívalas**
en vez de borrarlas.

## Crear tipos de contacto nuevos

Puedes crear los que quieras, pero ten en cuenta que **un tipo nuevo se cotiza
como cliente final**, aunque el nombre diga otra cosa. Crear «Mayorista
Premium» no le aplica el precio mayorista.

Hoy solo existen tres canales de precio y están fijos. Si el negocio necesita
un cuarto, hay que agregarlo también en el cotizador y en los productos — no
alcanza con crear la opción en la lista.

## Cargar la segmentación por CSV

El importador de clientes trae las cuatro columnas, y admite varias opciones
separadas por coma en la misma celda. Ver
[Importar clientes desde CSV](./importar-clientes.md).
