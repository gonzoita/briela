# Segmentación de clientes y su efecto en el precio

**Configuración → Listas de segmentación.** Cuatro listas que sirven para
clasificar clientes: tipo de contacto, industria, proceso de seguimiento y
fuente de contacto.

Tres de ellas son etiquetas y nada más: sirven para filtrar y para saber de
dónde salió cada cliente. **La de tipo de contacto es distinta: decide cuánto
se le cobra al cliente.**

## Los canales de precio los defines tú

Un tipo de contacto se convierte en canal de precio cuando le marcas **«define
precio»** —la casilla que tiene cada opción en la lista—. Desde ese momento
aparece como una fila más de margen y precio en cada producto y en cada
ensamble, y los clientes que lo tengan se cotizan a ese precio.

**Si creaste un canal y no aparece en los productos, es porque le falta esa
casilla.** Sin ella la opción sigue siendo una etiqueta para clasificar
clientes, nada más.

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

Cada canal lleva además un **margen sugerido**, que es el que traerá un producto
nuevo. Se edita en la misma fila y se puede cambiar producto por producto al
crearlo: es con qué arranca el formulario, no un tope.

## Cuando cotizas

El sistema mira el tipo de contacto del cliente y **muestra solo el precio que
le corresponde.** Los demás no aparecen: así nadie le vende al precio de otro
canal por error.

Junto al nombre del cliente ves una etiqueta con su canal, para que no haya
duda de qué precio se está usando.

**Si el cliente no está segmentado, se cotiza con el precio público** y la pantalla
lo dice: la etiqueta del canal aparece en ámbar con «por omisión», y un aviso explica
que le asignes un tipo de contacto si le corresponde otro precio.

Lo mismo si el cliente solo tiene tipos que no definen precio — un Prospecto, por
ejemplo. El aviso lo distingue del caso anterior, porque se arreglan distinto: uno se
soluciona en la ficha del cliente, el otro en Segmentación.

> Hasta el 13 ago 2026 no se mostraba ningún precio en esos casos. La idea era evitar
> vender al precio equivocado sin notarlo, pero en la práctica dejaba **cotizaciones en
> cero** — y una cotización en cero se firma. El precio público es además el que le
> corresponde a alguien de quien no se sabe nada. Lo que sí hace falta es decirlo, y eso
> ahora se ve en pantalla.

## El orden de la lista decide tres cosas

Va **del canal más barato al más caro**, y de ahí salen tres reglas:

1. **Qué precio paga un cliente con varios tipos**: gana el que esté más arriba.
2. **Hasta dónde puede descontar cada canal**: hasta el precio del canal anterior,
   nunca por debajo.
3. **Cuánta comisión gana el vendedor**: sube en cada escalón, porque vender a un
   canal más lejos del base deja más excedente para repartir.

Si pones un canal barato después de uno caro, ese canal no podrá descontar nada y su
comisión quedará más alta que la del anterior. No es un error del sistema: es lo que
dice el orden que le pusiste.

## Un cliente con varios canales

**Gana el que esté más arriba en la lista de Segmentación.** El orden de esa
lista es la prioridad, y lo cambias con las flechas de subir y bajar que tiene
cada opción.

De fábrica el orden es Mayorista, Distribuidor, Cliente directo — así que un
cliente marcado como mayorista y distribuidor a la vez paga precio mayorista.
Si prefieres otra prioridad, cambia el orden.

## Los únicos dos que no se pueden borrar

**El canal base y el precio público.** Nada más. El sistema necesita saber cuál
es el piso de utilidad —contra él calcula las comisiones— y qué precio ve
alguien que no ha entrado. Sin uno de los dos, las comisiones salen en cero y el
catálogo no sabe qué mostrar.

Lo que está atado es **el papel, no el nombre**. Puedes llamarlos como quieras:
«Precio de fábrica», «Lista pública», lo que use tu negocio. No tienen por qué
ser «Mayorista» ni «Cliente final».

Y si quieres borrar justamente uno de esos dos: marca otro canal con esa
función, y el anterior queda libre. Al marcar el nuevo, el viejo se desmarca
solo.

**Todos los demás canales se crean, se borran y se renombran cuando quieras.**

Si borras un canal que tenía precios cargados, el sistema te dice cuántos son
antes de hacerlo: esos precios se van con él y hay que volver a cargarlos. Los
clientes que tuvieran ese tipo se quedan sin precio hasta que les asignes otro.

Si solo quieres dejar de usarlo sin perder nada, **quítale «define precio»**: los
precios quedan guardados y vuelven si lo marcas de nuevo.

## Si rehiciste los canales con tus propios nombres

El sistema guarda los precios en dos sitios a la vez: las **filas por canal** —lo nuevo, y
lo que usa la cotización— y unas **columnas antiguas** por canal, que todavía leen algunas
pantallas mientras se van cambiando una por una.

El puente entre los dos mundos se decide por el **papel** de cada canal, no por su nombre:

| Columna antigua | A qué canal le corresponde |
|---|---|
| `precio_mayorista` | El **canal base** |
| `precio_cliente_final` | El **precio público** |
| `precio_distribuidor` | El primer canal que no es ninguno de los dos, en tu orden |

Un cuarto canal —por ejemplo «Precio Especial»— **no tiene columna antigua**, porque nunca
existió una para él. Su precio vive solo en las filas nuevas, así que hay que cargarlo
desde el formulario del producto; si está vacío, la cotización lo dice al agregar el ítem
en vez de mostrar un cero.

> Hasta el 13 ago 2026 ese puente estaba atado a tres nombres internos —`mayorista`,
> `distribuidor`, `cliente_directo`—. Funcionaba con los canales de fábrica y **fallaba en
> silencio** en cuanto la empresa creaba los suyos: los productos se cotizaban en cero
> teniendo sus precios a la vista en la ficha, y guardar desde la pantalla de editar no
> creaba ninguna fila. Ese era el motivo.

## El nombre es tuyo; la clave, no

Cada opción tiene una **clave interna** —el texto gris a la derecha— que no
cambia cuando renombras la etiqueta. Es a propósito: los clientes guardan esa
clave, y cambiarla dejaría a los clientes existentes apuntando a un tipo que ya
no existe.

Consecuencia práctica: la etiqueta y la clave pueden dejar de parecerse, y eso
está bien. Solo importa en un caso — **el importador de clientes por CSV usa la
clave, no la etiqueta.**

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
