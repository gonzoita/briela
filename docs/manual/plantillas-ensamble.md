# Plantillas de Ensamble (el cotizador que arma productos)

Ruta: `/cotizadores/plantillas` · Acceso: quien tenga permiso de editar
configuración (en la práctica, administrador).

## Qué problema resuelve

Una puerta refrigerada no tiene un precio de lista: depende del ancho, del alto,
del espesor, del tipo de acabado y de una lista larga de materiales que cambia
con cada medida. Antes eso se calculaba por fuera del sistema y se pegaba el
resultado en la cotización.

Una **plantilla de ensamble** es la receta de un producto fabricado: se define
una vez qué preguntas hay que responder (las medidas) y qué materiales consume
según esas respuestas. De ahí en adelante, cualquiera que cotice solo llena las
medidas y el sistema calcula solo los materiales, el costo y el precio.

Tres conceptos que conviene no confundir:

| Concepto | Qué es |
|---|---|
| **Plantilla** | La receta genérica. "Puerta batiente" con sus fórmulas. |
| **Ensamble** | Un producto concreto salido de esa receta, con medidas ya fijadas y precio calculado. Vive en el catálogo. |
| **Ítem de cotización/OP** | El ensamble ya metido en una venta, con sus propias medidas para ese cliente. |

## Dos formas de armar un ensamble

Desde el 14 ago 2026 hay dos, y se eligen al crearlo en `/ensambles/crear`:

| Modo | Cuándo sirve |
|---|---|
| **Con plantilla** | Se fabrica por medidas. Se escriben ancho, alto y demás, y las fórmulas calculan los materiales. Es lo que describe el resto de este documento. |
| **Directo, sin cálculos** | Siempre lleva lo mismo. Se escribe la lista de componentes con cantidades exactas, a mano, sin plantilla y sin fórmulas. |

El **ensamble directo** existe porque escribir una plantilla con fórmulas para un
kit que siempre lleva dos bisagras, un motor y cuatro metros de perfil es trabajo
de más. Sus líneas son de dos clases:

- **Material del inventario**: se busca por nombre o referencia, trae su costo, y
  **descuenta inventario al despachar**, igual que cualquier material de una
  receta.
- **Concepto libre** — mano de obra, transporte, instalación —: suma al costo y no
  descuenta nada, porque no vive en ninguna bodega.

El cliente no ve esta lista: el ensamble se cotiza como un ítem con su resumen
técnico, igual que uno con plantilla.

Los pasos de producción de un ensamble directo **cuelgan del ensamble**, no de una
plantilla. Nace con un paso único que pesa el 100% —«Fabricación»— para que el
operario pueda escanear su QR y la OP avance sola; se editan en
`/produccion/templates`, donde aparece con el nombre del ensamble.

El modo no se cambia después de creado: sería reescribir la receta completa.

### Referencia y unidad de medida

Desde el 14 ago 2026 un ensamble tiene **referencia** y **unidad de medida**, como un
producto. La referencia se genera sola (`ENS-0001`, `ENS-0002`…) si se deja en blanco,
y se puede escribir a mano. Antes el ensamble era la única línea sin código en una
cotización, una orden de producción o una remisión: las pantallas escribían `ENS-{id}`
a mano, que es un identificador de base de datos disfrazado de referencia —cambia si
se migra la base y no se puede dictar por teléfono—. La unidad, igual: todo se cotizaba
«por unidad» aunque el fabricante venda metros lineales o juegos de dos puertas.

### ¿Está en almacén? — cuántos se pueden armar

La ficha del ensamble responde **cuántas unidades alcanzan a armarse hoy** con el
inventario de la sede, y cuál es el material que primero se agota. Si falta algo para
armar una sola unidad, lista qué falta y cuánto.

Esa es la respuesta honesta a «¿lo tengo?» para algo que se fabrica por pedido: **un
ensamble no vive en un estante**. Cada uno se arma cuando se vende, y su receta
consume materiales que sí están en bodega. Lo que se puede saber no es cuántos hay
guardados, sino con cuántos alcanza. Sale del componente que primero se agota: si la
receta pide 4 bisagras y hay 10, alcanza para 2.

Los conceptos libres —mano de obra, transporte— no limitan nada: no se agotan.

Si el ensamble no tiene materiales de inventario en su receta —o la receta no se ha
calculado— no se muestra ningún número. No se inventa uno.

> **Lo que todavía no existe:** stock de ensambles *ya armados*. Si se fabrican tres
> kits por adelantado y se guardan en bodega, hoy el sistema no los cuenta como
> existencias — cuenta los materiales. Tendría sentido para un **ensamble directo**,
> que siempre lleva lo mismo; no para uno por medidas, donde cada unidad es distinta y
> nunca sería la que el próximo cliente pide.

### Imágenes

Se eligen **al crear**, en Información de catálogo: la primera queda como principal
y las demás como secundarias. Antes ahí solo había un aviso que decía que se subían
después de guardar, así que había que guardar el ensamble, volver a entrar a editar
y subirlas una por una.

Cuando el envío lleva imágenes pasa a `multipart/form-data`, y ahí todo viaja como
texto: un `true` llegaría como `'1'`. Por eso las medidas, las líneas y los canales
van como JSON y el servidor los desempaca antes de validar — si no, un campo de sí/no
guardado como `'0'` volvería a la pantalla de editar marcado, porque `'0'` es una
cadena no vacía.

### ¿Lo tengo en almacén?

Un ensamble puede responder a dos preguntas distintas, y las dos aparecen en su ficha:

**Cuántas se pueden armar.** Sale del material que primero se agota: si la receta pide
4 bisagras y en bodega hay 10, alcanza para 2. Es la respuesta honesta para algo que se
fabrica contra pedido y no vive en un estante. Dice además cuál es el cuello de botella
y qué falta, que es lo que se lleva a una solicitud de compra.

**Cuántas hay ya armadas.** Solo si el ensamble tiene prendido **«Se guarda en bodega»**,
para lo que se fabrica por adelantado y se deja listo.

Al prender ese interruptor el ensamble obtiene su **producto terminado** en el catálogo,
y con él **todo el inventario que ya existe**: stock por bodega, movimientos con su
historia, traslados, mínimos, el aviso diario de stock bajo, los informes y la etiqueta
de disponibles al cotizar. Las unidades se cargan desde inventario como con cualquier
producto —entrada, traslado o ajuste—, y la ficha del ensamble enlaza directo ahí.

El producto terminado nace **no vendible** a propósito: lo que se cotiza es el ensamble,
con sus medidas y su receta. Si el producto terminado también se pudiera cotizar, el
mismo artículo saldría dos veces en el buscador y nadie sabría cuál elegir.

Al apagar el interruptor el producto **se desactiva, no se borra**: sus movimientos son
historia de inventario y el stock que tenga es algo que existe en una estantería.

**Al despachar se descuenta de ahí, no del material.** Si hay unidades armadas suficientes,
la remisión saca del stock de armadas y **no** vuelve a consumir los componentes: se
gastaron el día que la unidad se armó. Sin esa salida, despachar algo que estaba en el
estante descontaba el material una segunda vez, el inventario de insumos quedaba en
negativo y el contador de armadas nunca bajaba.

Si el ensamble se guarda en bodega pero **no hay suficientes armadas**, se consumen los
materiales como siempre: esa vez se fabricó contra pedido. Es la opción honesta — la
alternativa sería dejar el stock en negativo para sostener la ficción de que estaba listo.

Las unidades **entran a mano** desde inventario, cuando alguien declara «armé cuatro».
Todavía no las suma sola al terminar una OP, y es a propósito: «cuándo está armada» tiene
más de una respuesta —¿al aprobar calidad? ¿al terminar el último paso de cada unidad?— y
adivinar mal infla el inventario con unidades que no existen.

### Duplicar un ensamble

Desde la ficha del ensamble, **Duplicar** abre el formulario de crear ya lleno: la
receta completa —con plantilla o directa—, las descripciones y los precios por
canal. El nombre llega con «(copia)» para que dos ensambles no queden con el mismo.
No se copian las imágenes: se suben contra un ensamble ya guardado, y compartir el
archivo haría que borrar la foto de uno la borrara del otro. El original no cambia
hasta que guardes la copia.

## Las tres partes de una plantilla

### 1. Campos — lo que se pregunta

Son las preguntas que llena quien cotiza. Hay tres clases:

- **De entrada**: los que el usuario responde (ancho, alto, tipo de acabado).
  Pueden ser texto, número, decimal, lista desplegable, o casilla de sí/no.
- **Calculados**: no se preguntan, se deducen de otros. Por ejemplo, el área a
  partir de ancho y alto. Se resuelven **en orden**, así que un campo calculado
  puede usar otro campo calculado que esté antes que él en la lista.
- **Variables de instancia**: las que se vuelven a pedir en cada venta concreta.
  Son las que hacen que el mismo ensamble sirva para medidas distintas sin tener
  que crear un producto nuevo cada vez.

Cada campo puede llevar texto de ayuda, un valor por defecto, marcarse como
obligatorio, y tener una **imagen de referencia** (útil para explicar qué medida
se está pidiendo). Las listas desplegables también admiten una imagen por cada
opción, para que se elija viendo el acabado en vez de leyendo un código.

### 2. Componentes — lo que consume

Cada componente enlaza un **producto del inventario** con una **fórmula** que
dice cuánto se necesita de él. Por cada componente se define:

- **Fórmula de cantidad**: cuánto entra al costo y a la cotización.
- **Fórmula real** (opcional): cuánto se consume de verdad en planta. Sirve para
  materiales que se compran por lámina pero se usan por metro — el cliente paga
  una cosa y la bodega descuenta otra.
- **Condición** (opcional): el componente solo entra si se cumple. Por ejemplo,
  incluir la resistencia perimetral solo cuando la temperatura sea de
  congelación.
- **Sub-fórmulas** (opcional): cálculos de detalle que se imprimen en el
  documento de producción (cortes, despieces). No afectan el precio.
- Tres interruptores de visibilidad: si **entra al precio**, si lo **ve el
  cliente** en la cotización, y si aparece en el **documento de la OP**.

Un componente cuya fórmula dé cero o menos simplemente no aparece — no hay que
condicionarlo aparte.

Los componentes se agrupan en **secciones** (Marco, Hoja, Herrajes...) para que
la lista sea legible cuando la receta tiene cuarenta materiales.

### 3. Pasos de producción — cómo se fabrica

Están en la misma plantilla, en su propia pestaña. Se documentan aparte en
[Trabajos y pasos de trabajo](./trabajos-pasos.md), pero lo importante acá es
que **van juntos a propósito**: quien define la receta define también cómo se
arma, y los pasos se generan solos cuando la OP se crea. No hay que ir a otro
módulo a elegir un flujo de trabajo a mano.

## Cómo se escriben las fórmulas

Se escriben como una operación matemática usando los nombres de los campos:

```
(ancho * alto) / 1000000
```

Está disponible lo que uno esperaría: `+ - * / ( )`, comparaciones, y estas
funciones:

`round` · `ceil` · `floor` · `abs` · `sqrt` · `log` · `exp` · `fmod` · `max` ·
`min` · `pow` · `intval` · `floatval` · `iif`

`iif(condición, valor_si, valor_no)` es la que más se usa para casos del tipo
"si el espesor es mayor a 100, usa este refuerzo, si no, el otro".

Dos detalles prácticos:

- **La coma decimal funciona.** Escribir `2,87` es lo mismo que `2.87`; el
  sistema lo convierte solo. Nadie tiene que acordarse de cambiar el separador.
- **Las condiciones sí distinguen texto.** Se puede escribir
  `temperatura == "BAJA"`. En las fórmulas de cantidad, en cambio, todo se
  convierte a número.

### El probador

No hace falta guardar y crear un ensamble para saber si una fórmula sirve. Cada
fórmula tiene un probador al lado: se ponen valores de prueba y muestra el
resultado — o **el mensaje de error real** si algo está mal escrito. Lo mismo
existe para las sub-fórmulas y para la plantilla completa (que devuelve la lista
de materiales que saldría con esas medidas).

Esto es a propósito: el probador **no** esconde los errores. En cambio, cuando
una fórmula falla durante un cálculo de verdad, el sistema registra el error en
el log y sigue con cero en vez de tumbar la cotización.

> **Ojo con las condiciones que fallan.** Si una condición está mal escrita, el
> componente se **incluye** (no se excluye). Es más fácil notar un material de
> más en la lista que uno que faltó silenciosamente — pero vale la pena probar
> las condiciones antes de dejarlas.

## Duplicar, exportar e importar

- **Duplicar**: copia una plantilla completa. Es la forma sensata de crear una
  variante ("puerta batiente" → "puerta batiente doble") sin volver a escribir
  cuarenta fórmulas.
- **Exportar**: baja la plantilla como archivo. Sirve de respaldo antes de un
  cambio grande, y para pasar plantillas entre el ambiente local y producción.
- **Importar**: sube ese archivo y recrea la plantilla, con sus secciones,
  campos y componentes.
- También se pueden **exportar todas** de una sola vez.

## Del ensamble al precio

El costo sale de la lista de materiales: calculada por las fórmulas si el
ensamble tiene plantilla, o sumada de las líneas si es directo. De ahí salen los
precios.

Desde el 14 ago 2026 el ensamble tiene **un precio por cada canal que la empresa
configuró** en Configuración → Listas de segmentación, con el mismo componente que
usa un producto. Antes eran tres cajas fijas —mayorista, distribuidor, cliente
final— que escribían solo las columnas antiguas: una empresa con cuatro canales no
tenía dónde poner el cuarto, y la cotización lee de los canales. Ver
[Segmentación y precios](./segmentacion-y-precios.md).

Cuando el ensamble se crea desde una plantilla, los márgenes de la plantilla
**siembran** los de los canales: el canal base recibe el de mayorista, el que esté
marcado como precio público el de cliente final, y el primer canal intermedio el de
distribuidor. Es una siembra, no un tope: se ajustan ensamble por ensamble.

Cada ensamble guarda además sus topes de negocio: comisión mínima y máxima por
canal, utilidad mínima de la empresa, y descuento máximo autorizado. El descuento
máximo no se pide en pantalla: sale de la distancia con el canal de abajo, para que
un descuento nunca haga que un cliente pague menos que el canal que tiene mejor
precio por derecho. Eso es lo que después limita hasta dónde puede negociar un
vendedor sin pedir permiso.

**Recalcular** relee los costos y rearma los precios desde el margen guardado de
cada canal. En un ensamble directo relee el costo de sus productos; los conceptos
libres se quedan como están, porque no hay de dónde releerlos.

> La comisión del vendedor se calcula sobre el **excedente por encima del precio
> mayorista**, no sobre el total de la venta. Ver [Cotizaciones](./cotizaciones.md).

## Nota técnica

- Tablas: `plantillas_ensamble`, `plantilla_campos`, `plantilla_componentes`,
  `plantilla_secciones`, `ensambles`, `canal_precios`.
- `ensambles.tipo_armado` (`plantilla` | `directo`) dice cómo está armado, y
  `plantilla_id` admite nulo. Se pregunta por el tipo y no por si tiene plantilla:
  deducirlo funcionaría hoy y se rompería el día que un ensamble directo se asocie
  a una plantilla como referencia.
- `EnsambleDirectoService` escribe las líneas con la **misma forma** que los
  componentes que calcula `FormulaEvaluatorService` —mismas claves, mismo
  `cantidad_real`, más un `es_concepto` para lo que no es inventario—. Por eso la
  orden de producción, el consumo de inventario al despachar, los PDF y la
  cotización no distinguen un ensamble directo de uno con plantilla: no hubo que
  tocar ninguno.
- `templates_trabajo.ensamble_id` deja que el flujo de pasos cuelgue del ensamble
  cuando no hay plantilla. Sin esa columna, `TrabajoAutoGeneratorService` se
  devolvía sin crear nada y la OP nacía con cero trabajos, sin nada que explicara
  por qué no avanzaba.
- `php artisan briela:diagnostico ENS-12` imprime los canales, las filas guardadas
  y qué precio recibiría la cotización, con su origen.
- El motor de fórmulas es `FormulaEvaluatorService`, construido sobre Symfony
  ExpressionLanguage. No es `eval()` de PHP: solo entiende expresiones, no
  ejecuta código arbitrario.
- El orden de evaluación es explícito y siempre el mismo: variables de entrada
  convertidas a número → campos calculados en orden → condiciones y fórmulas de
  los componentes.
- Al exportar, los componentes referencian su sección **por posición**, no por
  id. El id de la sección no existe todavía en el destino de la importación;
  hacerlo por id era justo lo que perdía la organización en secciones al
  importar.
- La ruta `/cotizadores/configurador` sigue viva como alias de compatibilidad
  del configurador anterior. Apunta al mismo controlador.
