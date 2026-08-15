# Unidades de medida

Se administran desde configuración · API: `/api/unidades-medida`

La lista de unidades que aparece al crear un producto o un ensamble: unidad, metro, kilo, litro,
lámina, rollo, hora.

## Es editable

Cada empresa mide distinto. La lista **no está en el código**: se agrega, se renombra y se quita
desde la interfaz, y el selector de los formularios lee de ahí.

## Por qué importa que sea configurable

El sistema se instala en el servidor de cada cliente, y una empresa que vende perfilería mide en
metros mientras otra vende horas de instalación. Dejar la lista fija obligaría a parchear la
instalación de un cliente para agregarle «rollo» — y la primera vez que se parcha la instalación
de un cliente nace una versión que ya no se puede actualizar.

## Dónde se usa

El selector aparece en productos, en ensambles y en las líneas de un ensamble directo. La unidad
se copia al ítem cuando se cotiza, así que un documento viejo conserva la que tenía aunque
después se renombre.
