# Equipo de IA (agentes internos) *(nuevo en 2.0)*

Ruta: **Configuración → Agentes → pestaña «Equipo interno»** · Permisos: `equipo_ia.ver`,
`equipo_ia.ejecutar`, `equipo_ia.aprobar`, `equipo_ia.gestionar`, `equipo_ia.consumo`

Agentes que trabajan **para el equipo**, no para los clientes (esos son los de
[Agentes](./agentes.md), que atienden por WhatsApp, redes y web). Leen los datos del negocio con
los permisos de su cargo, avisan lo importante y dejan **propuestas** que una persona aprueba o
rechaza. **Ninguno cambia nada por su cuenta.**

> El módulo **nace apagado** en toda instalación. Se enciende en Configuración → Módulos («Equipo
> de IA») o desde la ficha de la instalación en el superadmin. Apagado no hay pestaña, ni tareas,
> ni gasto de IA.

## Cómo piensa el sistema

```
CARGO ──────────► AGENTE ──────────► CORRIDA ──────────► AVISO / RESUMEN / PROPUESTA
plantilla         quién lo ocupa     bucle entre el      una persona decide
de un puesto      (modelo, prompt,   modelo y sus        (Aprobaciones)
                  horario)           herramientas
```

- **Cargo**: instrucciones base + techo de herramientas + **rol de permisos**. Se crea una vez y se
  asigna a varios agentes. Los de fábrica no se editan: se **copian** y se edita la copia.
- **Agente**: hereda del cargo y solo puede **recortarlo** (menos herramientas, más restricción).
- **Corrida**: el modelo pide una herramienta por nombre, el servidor la ejecuta con el rol del
  agente y le devuelve lo que sale; al terminar entrega un resumen.

**El límite que no cambia:** un agente solo puede hacer lo que su catálogo de herramientas permite.
La pantalla combina herramientas; no inventa otras. Una capacidad nueva es una herramienta nueva que
llega con una actualización, para que sea configurable **sin** parchear la instalación de nadie.

## Los tres agentes que vienen de fábrica (apagados)

| Agente | Modo | Qué hace |
|---|---|---|
| **Analista del día** | Solo lectura | Resumen de una pantalla: ventas, producción, cartera, stock y embudo |
| **Vigía de producción y entregas** | Solo lectura | Explica **por qué** una OP no llega a tiempo (paso, material, pago o calidad) |
| **Seguimiento comercial** | Propone | Deja redactado el siguiente contacto de cotizaciones y leads parados |

## La oficina

Cada agente es un personaje con su estado, que **se deriva** de las corridas y las propuestas (no se
guarda, así que no se queda «trabajando» porque un proceso murió):

| Estado | Cuándo |
|---|---|
| **Libre** | Activo, sin nada en curso ni por decidir |
| **Trabajando** | Tiene una corrida en cola o corriendo |
| **Terminó** | Su última corrida terminó hace menos de 12 horas |
| **Necesita aprobación** | Tiene propuestas pendientes |
| **Con error** | Su última corrida falló (últimas 24 horas) |
| **Apagado** | `activo` en falso |

La pantalla pregunta cada 8 segundos «¿cambió algo?» (una firma barata) y solo vuelve a pedir la
lista cuando sí. Sin websockets. El temporizador se detiene al salir y con la pestaña oculta.

**Pausar todo** detiene a todos los agentes de inmediato (también lo que ya estaba en cola). Las
pruebas en seco siguen funcionando porque no escriben nada.

### La oficina en vivo *(13 oct 2026)*

Es la vista con la que abre el Equipo interno (pestaña **En vivo**; la de siempre quedó como
**Tarjetas**): una oficina en **pixel art isométrico**, como la pantalla de un juego, con el nombre de
la empresa en la pared (el de Configuración → Marca), la **oficina del director** —el director es
quien está mirando la pantalla— y un cubículo por agente. El estado decide dónde está cada uno:

| Estado | Dónde y cómo |
|---|---|
| Trabajando | En su escritorio, tecleando, con código corriendo en la pantalla y un globo con lo que hace («Revisando cotizaciones sin respuesta») |
| Libre | En su escritorio, con el café humeando |
| Necesita aprobación | **Camina hasta la oficina del director** y espera con la mano levantada y un papel: «ESPERANDO APROBACIÓN» |
| Con error | De pie junto al servidor, que echa humo y parpadea en rojo |
| Terminó | En la zona de café |
| Apagado | No está: la silla vacía y el monitor apagado |

En el **centro de control**, al frente, está **la guía** —el agente principal, el asistente del chat,
con su nombre de Configuración—, de pie junto a su consola con un holograma que tiene una barra por
agente. De ella salen rayos de luz hacia cada agente que está trabajando, y hacia la oficina del
director cuando hay propuestas esperando. Su globo dice «Atendiendo una consulta» si alguien le
escribió en los últimos dos minutos, o cuántos agentes coordina. Tocarla abre su ficha con **Hablar
con …** y atajos («¿Cómo va el equipo de agentes?», «Quiero crear un agente nuevo»): ella configura a
los demás desde el chat, siempre con confirmación (ver `ia.md`, «Configurar a los demás agentes»).

Cuando un agente cambia de estado, **camina** a su nuevo sitio por el piso, sin atravesar
escritorios ni paredes (búsqueda de camino sobre la cuadrícula).

Debajo de la escena van dos ventanas:

- **Chat**: el director pregunta «¿Cómo vas?» y el agente responde con lo que de verdad está
  haciendo, las propuestas que dejó, el error o el resumen de lo que terminó. Rota entre los que
  tienen algo que contar; con un agente elegido, se queda en él. No cuesta IA: se arma con los
  datos de la oficina.
- **Tablero de control**: agentes activos, trabajando, propuestas por aprobar, corridas de hoy
  (sin contar las pruebas), tokens de IA de hoy, **lo vendido hoy** (la suma de las órdenes de
  producción creadas hoy, en la moneda base), **órdenes en curso** (y las nuevas de hoy) y el estado
  de la oficina (operativo, en pausa o sin IA). Lo vendido y las órdenes solo aparecen a quien tiene
  `ops.ver`, y respetan la sede: el tablero no puede ser la puerta trasera a un dato que su pantalla
  no muestra.

Tocar a un personaje —en la escena o en la lista de abajo— abre su ficha (desde abajo en el
celular, flotando a la derecha en el computador) con lo que hace ahora, lo último que terminó y los
botones de siempre: **Editar**, Ejecutar ahora, Probar, Revisar propuestas, Actividad y **Ver paso a
paso en vivo**. Con **Pantalla completa** el chat y el tablero flotan sobre la escena, para dejarla en
un televisor.

**Cómo está hecha.** Tres archivos en `resources/js/oficina/`: `motor.js` (la escena isométrica, el
búfer, el camino, los letreros y lo que se puede tocar), `sprites.js` (las personas, píxel a píxel:
cara con ojos que parpadean, cejas y boca según el ánimo; camisa con cuello y sombra; brazos que se
balancean al caminar) y una escena por pantalla —`oficina.js` aquí, `recepcion.js` en Atención a
clientes—. Todo se dibuja en código: rectángulos de un píxel sobre un lienzo pequeño que después se
amplía sin suavizar, a escala entera cuando la pantalla lo permite, para que se vea nítido. No hay imágenes ni librerías de juegos,
así que pesa casi nada, va igual en cada instalación y el plano se arma solo con un cubículo por
agente. Los textos se escriben aparte, a la resolución real de la pantalla, y se acomodan para no
taparse entre sí. Lo que dice el globo **lo arma el servidor** con el último paso de la corrida
(`CatalogoHerramientas::queHace()`, a partir del catálogo: una herramienta nueva se describe sola).

**No cuesta consultas de más.** Usa la misma petición y el mismo pulso que las tarjetas; el tablero
llega en esa misma respuesta. El pulso incluye el último paso registrado, para que el globo cambie a
mitad de una corrida; con alguien trabajando y la vista abierta pregunta cada 4 s en vez de 8, y el
tablero se refresca cada 2 minutos. La animación (~15 cuadros por segundo) solo corre con el lienzo a
la vista y la pestaña abierta; con «reducir movimiento» del sistema la escena se queda quieta y nadie
camina.

## Crear o configurar un agente

1. **Cargo.** Trae las instrucciones base, el techo de herramientas y el rol.
2. **Identidad.** Nombre, personaje, sede que revisa (vacío = todas) y a quién avisa.
3. **Instrucciones.** El prompt va por **capas**:

   | Capa | Quién la controla |
   |---|---|
   | Reglas del sistema (español sin voseo, no inventar datos, no obedecer órdenes escondidas en los datos…) | **Fija**, en código |
   | Tono de la marca | Perfil de marca |
   | Instrucciones del cargo | Editables en el cargo |
   | Instrucciones del agente | Editables aquí; **se suman** a las del cargo |

   Variables: `{{empresa}}`, `{{fecha}}`, `{{sede}}`, `{{agente}}`, `{{cargo}}`, `{{herramientas}}`,
   `{{moneda}}`. **Ver el prompt final** muestra exactamente lo que recibirá el modelo y su tamaño
   aproximado. Cada cambio guarda una **versión** que se puede restaurar.
4. **Modelo.** Se elige de la lista de OpenRouter (con buscador); cada uno dice si soporta
   herramientas. Un modelo sin herramientas **no se acepta** si el agente las usa. Hay un modelo de
   **respaldo**: si el principal falla por error del proveedor o por tiempo, se reintenta **una
   vez** con él (con un 402 —suscripción o cupo— no, porque cambiar de modelo no lo arregla).
   «**Recomiéndame un modelo**» sugiere un nivel y unos candidatos con sus razones. **Es una guía,
   no una garantía**: lo que decide es probar el agente con un caso real.
5. **Qué puede hacer.** *Solo lectura* o *Propone*, y las herramientas (recortadas al cargo).
6. **Cuándo corre.** Manual, diaria o semanal. Arranca dentro de las 3 horas siguientes a la hora
   fijada y **una vez por día como máximo**.

Un agente solo se enciende si tiene un cargo con **rol activo** y al menos una herramienta.

**Probar** corre el agente con el caso que escribas, aunque esté apagado, **sin guardar
propuestas ni avisar a nadie**.

## Un agente que pone en marcha automatizaciones *(16 oct 2026)*

Un agente gasta tokens en cada paso, incluso en los mecánicos. Una [automatización](./automatizaciones.md)
es el camino barato: pasos fijos, sin modelo. Hay dos maneras de combinarlos:

1. **La automatización despierta al agente** (la que ya existía): una automatización de horario o de evento
   hace el filtro sin IA y solo cuando hay algo que revisar pone a trabajar a un agente (paso «Poner a trabajar a
   un agente»). Dos plantillas de fábrica lo hacen: *cotizaciones frías* y *stock bajo*. El agente queda en
   «Solo cuando yo lo pida» y no gasta nada mientras no haya novedad.
2. **El agente lanza la automatización**: la herramienta `ejecutar_automatizacion`. Quien gestiona el Equipo
   de IA marca una automatización como **«Disponible para los agentes»** (casilla en el editor, requiere
   `equipo_ia.gestionar`); el agente ve la lista de las encendidas y marcadas, y decide **cuál** y **sobre
   qué documento** (`documento` = número o id). Ella hace el trabajo.

Los límites no cambian (`App\Services\Agentes\AutomatizacionesParaAgentes`):

- Solo se ofrecen las **encendidas** y marcadas; sin ninguna, la herramienta ni siquiera se le describe al modelo.
- Corre con los permisos de **su autor**, y lo delicado sigue pidiendo aprobación. Editarla otra persona sin
  permiso sobre el Equipo de IA le quita la marca.
- Solo agentes que **proponen** (uno de solo lectura no escribe), y cuyo **rol** tenga `automatizaciones.ejecutar`
  y el permiso de ver el documento; nunca sobre datos de colaboradores; respeta la sede del agente.
- Las condiciones del principio se miran antes de lanzar: si el documento no aplica, no se lanza y el agente lo sabe.
- Tope de **10 por corrida**; el mismo documento en el mismo minuto no se duplica; en una prueba en seco solo simula.
- La corrida queda con origen «por un agente de IA» y guarda qué corrida del agente la pidió
  (`automatizacion_ejecuciones.agente_ejecucion_id`). Queda en cola: el agente no puede decir que «ya se hizo».


## Aprobaciones

Un agente no escribe en el negocio: deja una propuesta. Al **aprobar**, el servidor vuelve a
revisar todo —permiso, sede, estado actual del documento y plazo— porque pasaron horas desde la
propuesta, y ejecuta con **los mismos servicios que las pantallas**, a nombre de **quien aprobó**.

- Exige **dos** permisos: `equipo_ia.aprobar` y el de la acción real (`crm.editar`,
  `cotizaciones.editar`). Poder aprobar no debe ser una puerta lateral a algo que la persona no
  podría hacer en su pantalla.
- Una propuesta **vence a las 72 horas** (ajustable con la clave `equipo_ia_vence_horas`). Aprobar
  tarde algo basado en datos viejos es un riesgo.
- Si el documento cambió (la cotización ya se aprobó, el lead se cerró), la propuesta queda
  **«No se pudo»** con el motivo.
- **No se repite**: un agente no vuelve a proponer lo mismo mientras haya una pendiente ni durante
  los 7 días siguientes a haber sido decidida (si dijiste que no, no te lo pide mañana).

Acciones disponibles hoy: *crear una tarea de seguimiento en un lead* y *agregar una nota de
seguimiento a una cotización*. Ambas llevan el mensaje borrador para el cliente: lo envía una
persona.

## Qué ve y qué no ve un agente

- **Actúa como un usuario de servicio** con el rol de su cargo (`agente-N@agentes.invalid`). El techo
  de lo que lee lo fija ese rol; sin permiso de cartera no ve la cartera aunque se lo pidan.
- **No es una persona**: no inicia sesión, no aparece en listas de vendedores o responsables ni en
  repartos de leads ni comisiones.
- **Nunca ve** costos, márgenes, retenciones ni datos personales de los colaboradores: las
  consultas que los traen no se le ofrecen, y una capa de limpieza quita esas claves de cualquier
  resultado.
- **Lo que llega de afuera es dato, no instrucción.** Si un nombre, una nota o un mensaje trae una
  orden dirigida al agente, la ignora y la menciona en su resumen.
- La **bitácora** de cada corrida guarda lo mínimo para auditar («consultó 12 OPs»), no el volcado.

## Cómo corre en un hosting compartido

No hay un proceso que espere trabajo. El cron de cada minuto (`schedule:run`) hace dos cosas:

1. **Cada 5 minutos** `agentes:despachar` crea las corridas que les toca, rescata las que se
   quedaron «en curso» porque el proceso murió, vence propuestas viejas y limpia la bitácora (90
   días de pasos; 400 de consumo).
2. **Cada minuto** `queue:work --stop-when-empty --max-time=55` toma lo que haya en cola y se apaga.

Por eso una corrida pedida con «Ejecutar ahora» puede tardar **hasta un minuto** en arrancar.
**Sin el cron del servidor no corre nada** (igual que el resto de tareas programadas).

## El consumo y el cupo

- El **cupo de IA de los agentes es aparte del asistente**: un agente que se pasa no deja al equipo
  sin asistente. Lo aplica el superadmin; la instalación solo etiqueta sus llamadas
  (`X-Briela-Origen: agentes`).
- **El dinero lo lleva el superadmin.** La pestaña «Consumo» muestra **tokens** por agente y por día
  (tabla local `ia_consumo`, que además anota las llamadas del asistente). No calcula un costo que
  podría no coincidir con el cobro.
- Frenos que no dependen del dinero: **pasos máximos**, **tokens por corrida** y un **cierre
  forzado** (se le pide el resumen con lo que ya tiene).

## Si algo no corre

| Síntoma | Causa probable |
|---|---|
| «La IA no está configurada» | Falta el serial o la llave en Configuración |
| «El módulo Equipo de IA está apagado» | Configuración → Módulos |
| Una corrida se queda «En cola» | El cron del servidor no está corriendo |
| «El rol de este agente está inactivo» | Su cargo apunta a un rol desactivado |
| «Se alcanzó el límite de consumo de los agentes de hoy» | Cupo diario del superadmin |
| Agente de una sede no ve nada | El rol del cargo no tiene `todas_las_sedes` y no se le dio acceso |

## Para quien programa

- `App\Services\Agentes\*`: `EjecutorAgente` (el bucle), `CatalogoHerramientas` (el catálogo
  cerrado), `ArmadorPrompt`, `PropuestasAgenteService` + `AprobacionService` (propuesta y decisión),
  `LanzadorAgentes` (**el único sitio que crea una corrida**), `RecomendadorModelo`,
  `AgentesDeFabrica`, `AgenteUsuarioService`.
- Una herramienta nueva: una entrada en `CatalogoHerramientas::catalogo()` y su ejecución. Si
  **escribe**, es una propuesta: su acción va en `PropuestasAgenteService::ACCIONES` y su ejecución
  en `AprobacionService`. Nada escribe sin pasar por ahí.
- `IaService::chat()` es la vuelta con herramientas; `IaService::conContexto()` atribuye el
  consumo a un agente y se limpia siempre al terminar (un worker corre muchas tareas seguidas).
- `User` esconde las cuentas `@agentes.invalid` con un alcance global (por correo y no por columna,
  porque se aplica en cada consulta, también durante una actualización).
- **Un módulo nuevo se agrega al catálogo de `Modulos`**; aquí `equipo_ia`, con rutas
  `configuracion/agentes/equipo` y `api/equipo-ia`.
