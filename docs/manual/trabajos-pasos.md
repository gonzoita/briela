# Trabajos y pasos de trabajo

Rutas: `/trabajos`, `/trabajo/{token}` (el QR del operario),
`/produccion/programador`

## Qué es un trabajo

Un **trabajo** es la hoja de ruta de **una unidad física** que hay que fabricar.
Si una OP pide tres puertas iguales, se crean **tres trabajos**, no uno con
cantidad tres. Cada puerta se arma, se avanza y se termina por separado, porque
en planta eso es lo que pasa de verdad.

Cada trabajo tiene una lista de **pasos** ordenados, y cada paso tiene un peso.
El avance del trabajo es la suma de los pesos de los pasos ya completados.

## Los pasos se generan solos

Antes, al crear un ítem de OP alguien tenía que entrar a producción y elegir a
mano un flujo de trabajo de una lista sin filtrar, y recién ahí aparecían los
pasos para el operario.

**Ya no.** Cada plantilla de ensamble tiene sus propios pasos de producción
(ver [Plantillas de Ensamble](./plantillas-ensamble.md)), y en el momento en que
se crea el ítem de la OP el sistema genera solo los trabajos y sus pasos — uno
por cada unidad de la cantidad pedida. Nadie tiene que acordarse de nada.

Si la plantilla todavía no tiene pasos cargados, el trabajo se crea igual, vacío.
Prefiere quedar disponible a no existir.

### El flujo se define en la ficha del ensamble, y es obligatorio

La ficha del ensamble tiene la sección **«Cómo se fabrica»**, y no deja guardar sin
al menos un paso. Arranca con uno ya escrito —«Fabricación», 100 %, final—, así que
cumplir el requisito no cuesta nada, y quien quiera detallar la producción parte de
ahí: agrega pasos, los ordena, reparte los pesos y marca cuál es el final.

**De quién son los pasos** depende de cómo se arma el ensamble:

- **Directo** (sin plantilla): los pasos son suyos y no los comparte con nadie.
- **Con plantilla**: los pasos son **de la plantilla**, y los comparten todos los
  ensambles que la usan. La ficha lo advierte en ámbar antes de que alguien los
  cambie creyendo que toca uno solo.

Se pueden editar también en `/produccion/templates`, donde el flujo de un ensamble
directo aparece con el nombre del ensamble.

> Hasta el 17 ago 2026 nada obligaba a tenerlos. El servidor le inventaba un paso
> único al ensamble directo la primera vez que una OP lo necesitaba, y los de
> plantilla llegaban a producción con el **trabajo vacío**: el operario escaneaba su
> QR y no tenía nada que marcar, el avance se quedaba en cero y la OP quieta en
> `confirmada` sin nada que lo explicara.

Guardar la ficha **no reescribe los pasos si no cambiaron**. Reescribirlos borra y
recrea las filas, y eso deja sin referencia a la plantilla los trabajos que estén en
curso —no pierden sus pasos, cada uno guarda su copia—, así que cambiarle el precio a
un ensamble no puede arrastrar eso.

### La bodega del paso final ya no se adivina

El paso final entrega la unidad: al cerrarlo se descuentan los materiales y entra el producto
terminado. **Desde el 18 ago 2026 no se puede cerrar sin decir a qué bodega entra.** Se puede
dejar predefinida en el paso de la plantilla, y quien cierra el trabajo puede cambiarla.

Antes, si nadie la había declarado, el sistema la mandaba a la principal. En una empresa con
varias bodegas eso es inventario apareciendo donde no es, sin que nadie lo haya decidido.

### La revisión de calidad de cada unidad

Si el ensamble tiene lista de revisión (ver [Plantillas de Ensamble](./plantillas-ensamble.md)),
cada unidad llega con **su propia copia** de esos puntos, y en la hoja de producción aparece la
sección «Revisión de calidad»: cada punto se marca **cumple** o **falla**, con observación y
fotos, y queda firmado por quien lo revisó.

- Un punto **crítico** que falla no deja aprobar esa unidad.
- Un punto que **exige foto** no se puede dar por cumplido sin ella.
- Las fallas se guardan. Es lo que hace falta cuando el cliente reclama, y antes no quedaba
  registrado en ninguna parte.

La copia se congela al generar el trabajo: cambiar la lista del ensamble después no reescribe
lo que alguien ya revisó.

### Las descripciones se rellenan con las medidas

En la descripción de un paso de la plantilla se pueden escribir variables entre
llaves:

```
Cortar lámina a {ancho} mm de ancho por {alto} mm de alto
```

Al generarse el trabajo, esas llaves se reemplazan con las medidas reales de esa
venta. El operario lee la instrucción con los números de **su** puerta, no una
fórmula genérica.

## Cómo se define un paso

En la plantilla, cada paso lleva:

| Campo | Para qué sirve |
|---|---|
| **Nombre** | Lo que ve el operario en la lista |
| **Objetivo** | Qué se busca lograr con el paso |
| **Descripción** | La instrucción detallada (admite variables entre llaves) |
| **Peso** | Cuánto aporta al avance total. La suma no puede pasar de 100 % |
| **Nivel de dificultad** | De 1 a 5. Determina cuántos puntos gana el operario |
| **Depende de** | Otros pasos que deben estar completos antes de poder empezar este |
| **Paso final** | Marca el cierre del trabajo; da un bono de puntos |
| **Imagen / plano** | Adjuntos de referencia que el operario puede abrir |

**"Depende de"** es lo que impide que alguien marque "pintar" antes de "soldar".
Un paso con dependencias sin cumplir no se puede iniciar.

## El operario: entrar por QR

Cada trabajo tiene su propio código QR. El operario lo escanea desde el celular
y cae directo en la lista de pasos de esa unidad — sin buscar la OP, sin navegar
por menús.

**El QR no es una puerta abierta.** Hay que estar con sesión iniciada, y si el
usuario tiene rol de operario solo ve los trabajos donde está asignado. Es un
atajo de navegación, no un salto de permisos. (Distinto de los portales
públicos del cliente, que sí funcionan sin login.)

Al completar un paso, el operario puede registrar:

- **Quiénes lo hicieron** — pueden ser varios operarios en el mismo paso, cada
  uno con su tiempo y sus observaciones.
- **Cuánto tiempo tomó.**
- **Fotos** de evidencia.

Un paso se puede **desmarcar** si se marcó por error. Eso devuelve el avance y
también **devuelve los puntos** otorgados, para que el ranking no quede inflado.

También se pueden agregar **pasos extra** sobre la marcha, para el trabajo que
no estaba en la receta.

## El último paso entrega a una bodega *(nuevo 15 ago 2026)*

**Toda producción entra a bodega.** El último paso del flujo es el de entrega: quien lo
cierra es quien físicamente deja la unidad en el estante, así que ese paso dice **en qué
bodega**. Se define una vez en la plantilla de pasos, y se puede cambiar en una orden
concreta si ese lote va a otra parte.

Al cerrar ese paso, en la misma operación:

1. Se **descuentan los materiales** de esa unidad. Es el momento honesto: es cuando se
   gastaron.
2. Se **registra la entrada** del producto terminado en esa bodega.

Antes un trabajo terminaba y ahí quedaba: la unidad existía en el mundo real —armada,
aprobada, en un estante— y en el sistema no existía en ninguna parte. El material recién
se descontaba al despachar, así que entre fabricar y despachar el inventario mostraba
insumos que ya no estaban y no mostraba el producto que sí estaba.

Un trabajo es **una unidad física**, así que cada uno descuenta lo suyo cuando de verdad
se armó. Una OP de cinco unidades a medio fabricar tiene el inventario que le
corresponde, no el de las cinco.

**No se puede entregar dos veces.** El trabajo guarda cuándo entró y a dónde, y esa marca
es el candado: sin ella, volver a marcar el último paso —o dos personas marcándolo a la
vez— metería la misma unidad dos veces al inventario, y eso no se nota hasta que alguien
cuenta el estante.

Al despachar, la remisión **saca de esa bodega y no vuelve a tocar los materiales**: ya se
descontaron al fabricar.

Si el ensamble no tenía prendido «se guarda en bodega», se prende solo al entregar la
primera unidad: de ese ensamble sí hay unidades guardadas, aunque nadie lo hubiera
declarado antes.

## Qué se dispara al avanzar

Marcar pasos no solo mueve una barra de progreso. Cada vez que cambia el avance
de un trabajo, el sistema:

1. **Recalcula el avance** del trabajo contra la suma real de los pesos.
2. Si todas las unidades del ítem llegaron al 100 %, marca el **ítem como
   terminado**.
3. Si hay avance real y la OP seguía en "Borrador" o "Confirmada", la pasa sola
   a **en producción** — el trabajo en planta es la prueba de que la producción
   ya empezó, no hace falta el clic manual.
4. **Recalcula el progreso general de la OP** (el que se ve en el detalle).
5. Si ya terminaron todos los ítems, pasa la OP sola a **control de calidad**.

Es el principio de fondo del sistema: cada acción real dispara el siguiente paso
del proceso, sin que nadie tenga que acordarse.

## Puntos por trabajo completado

Completar un paso otorga puntos al operario, según el **nivel de dificultad** que
tenga ese paso (configurable en Ajustes, por defecto 10 puntos). Encima hay dos
bonos:

- **Por rapidez**: si el tiempo registrado fue menor al tiempo estimado del paso
  (por defecto, 20 % extra).
- **Por paso final**: al completar el paso marcado como cierre del trabajo (por
  defecto, 50 puntos).

El conteo es **idempotente**: volver a marcar un paso ya completado no otorga
puntos otra vez. Solo un ciclo legítimo de desmarcar y volver a completar
vuelve a contar.

Ver [Recursos Humanos](./rrhh.md) para el ranking y los niveles.

## El programador de planta

En `/produccion/programador` se ve el trabajo pendiente y se puede asignar cada
paso a una **estación de trabajo**, a una **fecha** y a un **colaborador**,
además de estimarle un tiempo. Los pasos sin programar quedan aparte, a la
vista, para que no se pierdan.

La programación es por sede: solo se ven las estaciones y los trabajos de la
fábrica activa.

## Documentos

- **PDF de un trabajo**: la hoja de ruta de una unidad, con sus pasos y las
  sub-fórmulas de despiece que traiga la receta.
- **PDF de todos los trabajos de un ítem**: útil cuando se van a fabricar varias
  unidades iguales y se quiere imprimir el paquete completo de una vez.

## Nota técnica

- Tablas: `templates_trabajo` y `template_trabajo_pasos` (la plantilla),
  esta última con `ensamble_id` desde el 14 ago 2026, para el flujo de un ensamble
  directo. Un template pertenece a una plantilla del cotizador o a un ensamble; las
  dos columnas admiten nulo.
  `op_item_trabajos` y `op_item_trabajo_pasos` (lo real), más
  `op_item_trabajo_paso_operarios` (quiénes trabajaron cada paso).
- La generación automática la hace `TrabajoAutoGeneratorService` al crear el
  `OpItem`. Es segura ante repeticiones: si el ítem ya tiene trabajos, no genera
  nada.
- La fusión con Plantillas de Ensamble reutiliza la tabla `templates_trabajo`
  existente en vez de crear una nueva, para no tocar el historial de OPs y
  trabajos ya generados con el sistema anterior. El emparejamiento es 1 a 1 y el
  template se crea vacío la primera vez que se necesita — por eso no hizo falta
  ninguna migración de relleno para las plantillas que ya existían.
- Al guardar los pasos de una plantilla se **borran y recrean todos**. Por eso
  los adjuntos (imagen, plano) se suben primero, reciben una ruta, y esa ruta
  viaja como un campo más del paso en el siguiente guardado.
- El porcentaje de avance se normaliza contra la **suma real** de los pesos, no
  contra 100. Si un trabajo viejo quedó con pasos de peso cero, cae a un simple
  "pasos completados / pasos totales" en vez de quedarse pegado en 0 %.
- La generación automática **no** cambia `estado_item`: el ítem queda
  "pendiente" hasta que alguien complete un paso de verdad, para no pisar el
  flujo de verificación de la OP.
