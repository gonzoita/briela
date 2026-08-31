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

> Hasta el 17 ago 2026 nada obligaba a tenerlos. El servidor le inventaba un paso
> único al ensamble directo la primera vez que una OP lo necesitaba, y los de
> plantilla llegaban a producción con el **trabajo vacío**: el operario escaneaba su
> QR y no tenía nada que marcar, el avance se quedaba en cero y la OP quieta en
> `confirmada` sin nada que lo explicara.

Guardar la ficha **no reescribe los pasos si no cambiaron**. Reescribirlos borra y
recrea las filas, y eso deja sin referencia a la plantilla los trabajos que estén en
curso —no pierden sus pasos, cada uno guarda su copia—, así que cambiarle el precio a
un ensamble no puede arrastrar eso.

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

## El tablero: una ficha por unidad *(nuevo 30 ago 2026)*

`/trabajos` es una **ficha grande por unidad física**, con un botón por paso. Un toque
marca el paso, otro lo deshace, y **«Terminar»** cierra la unidad completa marcando lo
que falte en orden y respetando las dependencias.

Antes era una tabla con puntitos de progreso: para marcar un paso había que entrar al
trabajo, bajar hasta él, abrirlo y guardarlo. Ocho toques para lo que ahora es uno, y por
eso los pasos se marcaban en tandas al final del día en vez de a medida que pasaban.

Cada ficha lleva el número de la orden, el sufijo de la unidad (`−2` cuando la orden pide
varias), **las medidas de esa unidad** y el color de urgencia según la fecha de entrega.
Sin las medidas, cinco fichas del mismo ensamble son cinco fichas idénticas.

Un paso con dependencias sin cumplir sale bloqueado y no se deja tocar; al cerrarse el
paso del que colgaba, se desbloquea en la misma pantalla. Cerrar el **paso final** desde
aquí descuenta los materiales y mete la unidad a bodega igual que desde cualquier otro
sitio, y el tablero lo dice: un movimiento de inventario no puede ocurrir en silencio.

Es **la misma ficha** que usa [Calidad](./calidad.md) —
`resources/js/Components/FichaProceso.vue` —, porque el gesto es el mismo y quien está en
planta no tiene por qué aprender dos pantallas.

## Las fechas del proceso se ponen solas *(nuevo 30 ago 2026)*

Nadie las escribe. La unidad guarda dos:

| Fecha | Cuándo se pone |
|---|---|
| **Inicio** | La primera vez que alguien toca cualquiera de sus pasos |
| **A calidad** | Cuando se cierra el último. **Es también la hora en que llegó a Calidad** |

La segunda es una sola fecha y no dos porque es un solo hecho: una unidad entra a revisión en
el mismo instante en que deja de fabricarse. Guardarlo por duplicado sería tener dos versiones
de lo mismo, y se separarían.

Se sellan dentro de `OpItemTrabajo::recalcularAvance()`, que es el punto único por el que pasa
cualquier cambio de avance venga de donde venga. Escritas en las cuatro pantallas que cierran
pasos, la fecha dependería de por dónde entró quien lo marcó.

**«A calidad» se retira si la unidad se reabre**: una que volvió a planta no terminó nada. La de
inicio no: sí arrancó, y eso no se deshace.

Los pasos siguen guardando su propia hora de inicio y cierre — de ahí sale el tiempo real de
cada uno. Lo nuevo es que la **unidad** también las tiene, que es lo que el tablero necesita.

## Cerrar un paso pasa por un solo sitio *(nuevo 30 ago 2026)*

Hay cuatro pantallas que cierran un paso: el código QR del operario, el panel de la orden, la
hoja del trabajo y el tablero. Las cuatro llaman a **`CierrePasoService`**, y por eso hacen
exactamente lo mismo. Antes cada una lo hacía a su manera, y de ahí salían tres problemas que
parecían no tener relación:

- **La entrega a bodega ocurría en momentos distintos.** Dos entregaban cuando no quedaba
  ningún paso pendiente; la tercera, al cerrar el marcado como final. Con una plantilla sin
  paso final, dos entregaban y una nunca.
- **Los puntos solo se otorgaban por el código QR**, pero se devolvían desde la hoja del
  trabajo: se perdían por un camino que nunca los daba. Ahora se otorgan **cierre quien
  cierre**, a los operarios registrados en el paso. Si no hay ninguno registrado —el toque
  único del tablero— no se otorgan: no hay a quién.
- **La bodega se preguntaba distinto** en cada una.

Reabrir un paso también pasa por ahí: devuelve los puntos y desmarca. **No devuelve la unidad
de la bodega ni repone el material** — se gastó de verdad, y la unidad está armada en un
estante. Deshacer ese movimiento diría que no existe algo que sí existe; lo que corresponde es
un ajuste de inventario, que lo decide quien cuenta el estante.

## El paso final pide las dos bodegas

El paso marcado como final es el que **entrega la unidad**. Al cerrarlo se pregunta:

| Pregunta | Para qué |
|---|---|
| ¿A qué bodega entra la unidad terminada? | Ahí se registra la entrada del producto terminado |
| ¿De qué bodega salieron los insumos? | De ahí se descuenta el material que se gastó |

**Son dos y no una.** Una bodega de producto terminado no guarda insumos: descontar el material
contra ella lo recorta a cero en silencio y el inventario queda mintiendo por los dos lados.

Llegan **ya elegidas**, en este orden: lo que se eligió en la unidad anterior de la misma
orden, y si no, lo que declaró la orden al confirmarse. Casi siempre es confirmar y seguir; el
selector está para el caso real de que la puerta terminara en otro estante del que se planeó, o
de que el material saliera de otra caja. En una orden de diez puertas, sin esa memoria serían
veinte respuestas idénticas, y a la tercera se contestan sin leer.

Se guardan **por unidad** (`op_item_trabajos.bodega_entrega_id` y `bodega_material_id`) porque
un lote se puede partir: tres puertas con material de la principal y dos con el de la sucursal.

**El paso final no se cierra con trabajo por delante.** Si quedan otros pasos pendientes, se
rechaza y se dice cuántos faltan: entregar ahí metería al inventario una puerta que todavía no
tiene marco. Y toda plantilla tiene garantizado un paso final —si nadie lo marca, lo es el
último—, así que una unidad nunca se queda sin entrar a ninguna parte.

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
