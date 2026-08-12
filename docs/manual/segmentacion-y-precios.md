# Segmentación de clientes y su efecto en el precio

**Configuración → Listas de segmentación.** Cuatro listas que sirven para
clasificar clientes: tipo de contacto, industria, proceso de seguimiento y
fuente de contacto.

Tres de ellas son etiquetas y nada más: sirven para filtrar y para saber de
dónde salió cada cliente. **La de tipo de contacto es distinta: decide cuánto
se le cobra al cliente.**

## Los canales de precio los defines tú

Un tipo de contacto se convierte en canal de precio cuando le marcas **«define
precio»**. Desde ese momento aparece como una fila más de margen y precio en
cada producto y en cada ensamble, y los clientes que lo tengan se cotizan a ese
precio.

Puedes crear los canales que necesites. Si tu negocio vende distinto a una
constructora, creas «Constructora», le marcas «define precio», y aparece en
todas partes. No hay que tocar nada más.

Hay dos marcas más, y solo una opción puede llevar cada una:

| Marca | Qué significa |
|---|---|
| **canal base** | Es el piso de utilidad de la empresa. No paga comisión al vendedor, y la comisión de los demás canales se calcula contra su precio |
| **precio público** | Es el precio que ve alguien que no ha entrado al sistema, en el catálogo |

De fábrica viene: Mayorista como canal base, Cliente directo como precio
público, y Distribuidor como canal normal. Prospecto no define precio.

## Cuando cotizas

El sistema mira el tipo de contacto del cliente y **muestra solo el precio que
le corresponde.** Los demás no aparecen: así nadie le vende al precio de otro
canal por error.

Junto al nombre del cliente ves una etiqueta con su canal, para que no haya
duda de qué precio se está usando.

**Si el cliente no está segmentado, no se muestra ningún precio** y la pantalla
te dice que le asignes un tipo de contacto. Es a propósito: cotizarle con un
precio por omisión es la forma de vender mal sin que nadie se entere.

Lo mismo si el cliente solo tiene tipos que no definen precio — un Prospecto,
por ejemplo. El aviso lo distingue del caso anterior, porque se arreglan
distinto: uno se soluciona en la ficha del cliente, el otro en Segmentación.

## Un cliente con varios canales

**Gana el que esté más arriba en la lista de Segmentación.** El orden de esa
lista es la prioridad, y lo controlas arrastrando las opciones.

De fábrica el orden es Mayorista, Distribuidor, Cliente directo — así que un
cliente marcado como mayorista y distribuidor a la vez paga precio mayorista.
Si prefieres otra prioridad, cambia el orden.

## Por qué un canal no se puede borrar

Las opciones con «define precio» no tienen botón de eliminar, y si intentas
quitarles la marca cuando tienen precios cargados, el sistema te lo explica en
vez de dejarte hacerlo.

Es a propósito. Si se borrara un canal, sus clientes no darían ningún error:
simplemente se quedarían sin precio, y solo se notaría al intentar cotizarles.
Si ya no lo usas, **desactiva la opción** en vez de borrarla: así los precios
quedan guardados por si vuelves a necesitarlos.

Tampoco puedes quitarle el precio al canal base ni al precio público sin marcar
otro antes: sin canal base no se pueden calcular comisiones, y sin precio
público el catálogo no sabría qué mostrar.

## Los ensambles a medida

Un ensamble cotizado por medidas no tiene precios guardados: se calculan al
vuelo desde el costo de sus componentes y el margen de su **plantilla**.

Las plantillas hoy llevan margen para los tres canales originales. Si creas un
canal nuevo, sus ensambles se calculan con un margen razonable —el más bajo si
es el canal base, el más alto si es el precio público— hasta que las plantillas
puedan llevar un margen por canal. Los productos sí toman el margen exacto que
le pongas a cada canal.

## Cargar la segmentación por CSV

El importador de clientes trae las cuatro columnas, y admite varias opciones
separadas por coma en la misma celda. Ver
[Importar clientes desde CSV](./importar-clientes.md).
