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

Cuando se crea un ensamble a partir de una plantilla, el sistema calcula la
lista de materiales, la suma, y de ahí salen los precios. A partir del costo y
de un margen se calculan tres precios en escalera:

- **Mayorista**: costo + margen
- **Distribuidor**: costo + margen + 2,5 puntos
- **Cliente final**: costo + margen + 5 puntos

Cada ensamble guarda además sus topes de negocio: comisión mínima y máxima por
tipo de cliente, utilidad mínima de la empresa, y descuento máximo autorizado
para cada precio. Eso es lo que después limita hasta dónde puede negociar un
vendedor sin pedir permiso.

> La comisión del vendedor se calcula sobre el **excedente por encima del precio
> mayorista**, no sobre el total de la venta. Ver [Cotizaciones](./cotizaciones.md).

## Nota técnica

- Tablas: `plantillas_ensamble`, `plantilla_campos`, `plantilla_componentes`,
  `plantilla_secciones`, `ensambles`.
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
