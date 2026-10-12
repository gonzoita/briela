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
