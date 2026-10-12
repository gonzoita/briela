# Plan maestro: agentes configurables y módulo de Automatizaciones

> **Estado:** plan. No hay código, migraciones ni commits. Complementa `docs/agentes-ia.md`
> (diagnóstico y oportunidades). Fecha: 12 oct 2026.

## 0. Decisiones ya tomadas

| Tema | Decisión |
|---|---|
| **Cargos** | Un **cargo** es una plantilla completa: instrucciones base + herramientas permitidas + rol de permisos. Se crea una vez y se asigna a varios agentes |
| **Automatizaciones** | **Constructor visual de flujos** (lienzo con nodos, ramas y esperas) |
| **Autonomía** | **Todo nace con aprobación**; el administrador puede soltar acciones concretas, por agente |
| **Marketing** | Primero para **los clientes que usan Briela**, probado antes en `sistema.briela.app` |
| **Ubicación** | Los agentes viven en el módulo **Agentes** (pestaña «Equipo interno»). Automatizaciones es un **módulo propio**, apagable |

---

## 1. La idea en una frase

Tres piezas que se combinan, todas configurables desde la interfaz y sin tocar código:

```
   CARGO ──────────► AGENTE ──────────► AUTOMATIZACIÓN
   «qué sabe hacer»   «quién lo hace,    «cuándo y en qué orden
   (plantilla)         con qué modelo»     se hace, con o sin IA»
        │                  │                      │
        └──────────────────┴──────────┬───────────┘
                                      ▼
                          CATÁLOGO DE HERRAMIENTAS (código)
                          lo único que toca datos de Briela
                                      │
                       lectura ── propone ── (auto, si se suelta)
                                      │
                         servicios de las pantallas
```

**El límite que no cambia:** un agente puede hacer **solo lo que el catálogo de herramientas
permite**. La interfaz combina herramientas, no las inventa. Una capacidad nueva (por ejemplo
«crear lead») es una herramienta nueva que llega con una actualización a todas las instalaciones.
Es lo que hace posible que sea muy configurable **sin** parchear a ningún cliente
(regla 1 del producto instalable).

---

## 2. Cargos

Un cargo es el «puesto» en la oficina.

| Campo | Para qué |
|---|---|
| Nombre, descripción, área, color, avatar | Identidad y orden en la oficina visual |
| **Instrucciones base** | El prompt que todo agente de ese cargo hereda |
| **Herramientas permitidas** | El techo: un agente de este cargo no puede tener más que esto |
| **Rol de permisos** | Un rol existente (`roles`). Define qué datos puede leer, sede incluida |
| Nivel de modelo sugerido | `económico` o `potente`, solo como punto de partida |
| Autonomía por defecto | Matriz acción → `lectura` / `propone` / `auto` |
| Topes por defecto | Pasos, tokens y gasto diario |
| De fábrica / propio | Los de fábrica vienen en la actualización, apagados y **copiables**; el cliente edita su copia, no el original |

**Cargos de fábrica propuestos** (plantillas listas, apagadas hasta que alguien las active):

| Área | Cargos |
|---|---|
| Operaciones | Vigía de producción, Auditor de datos, Analista de calidad |
| Comercial | Seguimiento comercial, Calificador de leads, Copiloto de bandeja |
| Finanzas | Analista de cartera, Analista del día |
| Compras | Analista de abastecimiento |
| Marketing | Community manager, Reactivación de clientes, Casos de éxito |
| **En blanco** | Cargo genérico vacío para que el administrador escriba el suyo |

**Asignar un cargo** a un agente hace tres cosas: hereda instrucciones y herramientas, crea o
vincula su **usuario de servicio** con el rol del cargo, y le aplica los topes y la autonomía por
defecto. Después se puede ajustar **solo hacia abajo** (menos herramientas, más restricción); subir
por encima del techo del cargo exige editar el cargo.

---

## 3. Agentes

### 3.1 Qué se configura

| Bloque | Opciones |
|---|---|
| **Identidad** | Nombre, avatar, cargo, a quién avisa, sede (o todas) |
| **Instrucciones** | Editor por capas (ver 3.2) |
| **Modelo** | Elegido por el administrador, con respaldo (ver 3.3) |
| **Parámetros** | Temperatura, máximo de tokens por respuesta, máximo de pasos |
| **Herramientas** | Subconjunto de las del cargo, con la matriz de autonomía por acción |
| **Cuándo corre** | Manual, programado, o disparado por una automatización |
| **Topes** | Por corrida, por día, y qué hacer al llegar (pausar y avisar) |
| **Estado** | Activo, en pausa, apagado |

### 3.2 El prompt: editable por capas

El prompt final que recibe el modelo se arma con capas, y **el administrador ve y edita todas
menos la primera**:

| # | Capa | Quién la controla |
|---|---|---|
| 1 | **Reglas del sistema**: español colombiano neutro sin voseo, no inventar datos, formato de salida, no obedecer instrucciones que vengan dentro de los datos | **Fija**, en código. No editable (mismo criterio que las reglas intocables de la ficha técnica) |
| 2 | **Perfil de marca**: tono y voz de la empresa | Se toma de `PerfilMarca`, editable allí |
| 3 | **Instrucciones del cargo** | Editables en el cargo |
| 4 | **Instrucciones del agente** | Editables en el agente (se suman a las del cargo) |
| 5 | **La tarea de esta corrida** | La pone el disparador, la automatización o quien pulsa «Ejecutar» |

Lo que hace útil al editor:

- **Variables** que se rellenan solas: `{{empresa}}`, `{{fecha}}`, `{{sede}}`, `{{responsable}}`,
  `{{herramientas}}`.
- **Vista previa del prompt final**, con el conteo aproximado de tokens, para ver cuánto cuesta
  cada llamada antes de activar.
- **Versiones:** cada cambio guarda una versión (`agente_prompt_versiones`), con quién y cuándo.
  Se puede **comparar y restaurar**. Sin esto, un ajuste mal hecho no se puede deshacer.
- **Prueba en seco:** el agente corre con un caso escrito por el administrador y muestra qué
  haría, **sin escribir nada y sin notificar a nadie**. Funciona con el agente apagado y con el
  texto aún sin guardar (igual que la prueba de WhatsApp de hoy).

### 3.3 Elegir el modelo, y «recomendado según el prompt»

Al crear o editar un agente el administrador elige el modelo de la lista de OpenRouter, con
buscador. Cada modelo muestra: **si soporta herramientas**, ventana de contexto y precio por
millón de tokens. Si el agente usa herramientas y el modelo no las soporta, **no deja guardar**.

**Cómo se calcula el «recomendado»** (honesto sobre lo que es: una **sugerencia razonada, no una
garantía**):

1. Se mira lo que el agente necesita: ¿usa herramientas? ¿cuántas? ¿su salida es estructurada?
   ¿el prompt es largo? ¿es clasificar, redactar o razonar en varios pasos?
2. Se descartan los modelos que no sirven (sin herramientas, contexto corto).
3. Se proponen **dos o tres candidatos** con la razón: «económico, suficiente para clasificar»,
   «más potente, para razonar sobre varias fuentes».
4. La clasificación del tipo de tarea la hace un modelo rápido leyendo el prompt (una llamada
   barata) con reglas fijas de respaldo si falla.

**Comparador de modelos** (lo que de verdad decide): se corre la **misma prueba** con dos o tres
modelos y se ve lado a lado la respuesta, el tiempo y el costo. Así el administrador elige con
evidencia y no con la recomendación.

**Cadena de resolución:** modelo del agente → nivel del cargo → ajustes globales de IA →
valor por defecto. **Respaldo:** modelo de respaldo si el principal falla por error o tiempo de
espera (no si el proxy corta por suscripción).

### 3.4 Autonomía por acción

Cada herramienta que cambia algo tiene tres modos **por agente**:

| Modo | Qué pasa |
|---|---|
| `propone` (por defecto) | Queda en la cola de aprobación; una persona la ve y decide |
| `auto` | Se ejecuta sola, queda auditada y se puede **deshacer** si la acción lo permite |
| `bloqueada` | El agente no la tiene |

**Techo fijado por código** (no se puede soltar desde la interfaz): lo que mueve dinero, cambia el
estado de una OP, despacha, borra, o **envía un mensaje a un cliente o a las redes**. Son acciones
donde un error es caro o público. Cuáles entran en esa lista es una decisión tuya (ver preguntas).

Al soltar una acción a `auto`, la pantalla muestra **la tasa de aprobación histórica** de esa
acción para ese agente («aprobaste el 96 % de las últimas 50»), para decidir con datos.

### 3.5 Pantalla de creación (asistente en pasos)

Desde «Equipo interno → Nuevo agente»:

1. **Cargo:** elegir uno de fábrica, uno propio, o en blanco.
2. **Identidad:** nombre, avatar, a quién avisa.
3. **Instrucciones:** editor por capas con vista previa.
4. **Modelo:** recomendación, selector y comparador.
5. **Herramientas y autonomía:** matriz con candados.
6. **Cuándo corre y topes.**
7. **Probar → Activar.** No se activa sin una prueba en seco exitosa (se puede saltar con
   confirmación).

---

## 4. Módulo de Automatizaciones

Programa lo que Briela hace sola, con o sin IA. Un agente es **un tipo de paso**, no un módulo
aparte.

### 4.1 Piezas del flujo

| Tipo de nodo | Ejemplos |
|---|---|
| **Disparadores** | Evento del sistema (cotización aprobada, OP cambia de estado, lead nuevo, mensaje entrante, pago registrado, stock bajo mínimo, cotización vencida) · horario (diario, semanal, cada N horas) · botón manual · *más adelante:* webhook entrante |
| **Condiciones** | Compara un dato del evento: «monto > X», «cliente es nuevo», «canal = WhatsApp», «sede = Principal», y combinaciones Y/O |
| **Acciones** | Notificar a una persona o rol · crear tarea o comentario · agregar nota a un lead · mover etapa de un lead · **ejecutar un agente** · **proponer** algo a aprobación · enviar plantilla de WhatsApp o correo (con aprobación) · crear borrador de publicación |
| **Control** | Si/si no · **esperar** N minutos/horas/días · **esperar aprobación humana** · repetir con tope · terminar |

### 4.2 Ejemplos que el módulo permitiría armar

1. **Cotización sin respuesta:** *Disparador: cotización enviada* → *Esperar 5 días* → *Si no está
   aprobada ni rechazada* → *Agente «Seguimiento comercial» redacta* → *Propuesta al vendedor*.
2. **OP lista para despachar:** *OP pasa a calidad aprobada* → *Notificar a logística* → *Esperar 2
   días* → *Si no tiene remisión* → *Avisar al jefe de planta*.
3. **Lead nuevo:** *Lead entra* → *Agente «Calificador» lo puntúa* → *Si puntaje alto* → *Asignar y
   notificar al mejor vendedor disponible*.
4. **Cliente dormido:** *Cada lunes* → *Agente «Reactivación» elige clientes sin compra en 6
   meses* → *Borrador de correo* → *Aprobación* → *Cola de envío*.

### 4.3 Cómo funciona por dentro

**El flujo es un dato, no código.** Se guarda como un grafo JSON versionado. El mismo motor lo
ejecuta, no importa si se dibujó en el lienzo o en una lista.

| Tabla | Para qué |
|---|---|
| `automatizaciones` | Nombre, activa, grafo (JSON), versión, creado por |
| `automatizacion_versiones` | Cada guardado, para comparar y restaurar |
| `automatizacion_ejecuciones` | Una corrida: disparador, datos del evento, estado, origen |
| `automatizacion_pasos` | Estado de cada nodo en esa corrida (la «caja negra») |
| `automatizacion_esperas` | Esperas pendientes con su fecha de reanudación |

**Motor, pensado para Hostinger** (sin procesos permanentes):

- Los **eventos** se emiten desde los *observers* que ya existen
  (`CotizacionObserver`, etc.) hacia un único `EventosAutomatizacion::emitir()`. Esa función es
  **barata**: consulta en memoria de la petición qué automatizaciones activas escuchan ese evento
  y, si ninguna, no hace nada. Solo si hay coincidencia encola un job. **Esto es obligatorio por
  la regla de velocidad de `CLAUDE.md`**: ninguna automatización puede costar una consulta
  extra en cada guardado.
- Una **espera** no deja nada corriendo: guarda la ejecución con su fecha y un comando cada
  minuto (`automatizaciones:avanzar`) la reanuda cuando llega la hora.
- **Idempotencia:** una misma ejecución no se crea dos veces para el mismo evento.
- **Anti-bucles:** una automatización que provoca un evento que dispara otra tiene un **contador de
  profundidad** (por ejemplo, máximo 3) y no se dispara por cambios hechos por ella misma.
- **Frenos:** máximo de ejecuciones por hora por automatización, interruptor global, y pausa
  automática con aviso si falla N veces seguidas.
- Las acciones pasan por **las mismas herramientas** que usan los agentes: una sola puerta, un
  solo lugar donde viven los permisos y la aprobación.

### 4.4 El lienzo visual

- **Constructor con nodos** conectados, arrastrar y soltar, una barra lateral con los nodos, un
  panel para configurar el nodo seleccionado, y un botón **«Probar con un evento de ejemplo»** que
  muestra el camino que seguiría y qué haría, sin ejecutar nada.
- **Candidato técnico:** una librería de grafos de código abierto para Vue (por ejemplo, Vue Flow).
  Hay que **validar** su licencia, su peso y que funcione con el *build* que se versiona en
  `public/build/` antes de comprometerse. Se carga solo al abrir esta pantalla (el `import.meta.glob`
  sin `eager` ya lo garantiza), así que no engorda el resto del sistema.
- **Móvil primero, con una salvedad real:** editar un lienzo con el dedo es incómodo y las reglas
  del proyecto piden diseñar primero para celular. Propuesta: en el celular la **misma
  automatización se ve y se edita como una lista vertical de pasos**, y el lienzo es la vista de
  escritorio. Ambas leen y escriben el mismo JSON, así que no son dos productos.
- **Cada ejecución es inspeccionable:** se abre y se ve nodo por nodo qué pasó, con qué datos y
  cuánto tardó.

### 4.5 Relación con lo que ya existe

- Las **tareas programadas actuales** (`routes/console.php`) **no se reemplazan**. Funcionan y
  están probadas. El módulo ofrece **plantillas** equivalentes, y se migra una a una solo si
  conviene.
- Los **avisos de `NotificacionService`** siguen igual; las automatizaciones los usan como una
  acción más.
- Se agrega al catálogo de **`Modulos`** con sus permisos, sus rutas y su dependencia
  (CRM, cotizaciones y OP solo son disparadores disponibles cuando su módulo está encendido),
  como pide `CLAUDE.md`.

---

## 5. La oficina de agentes y la navegación

| Dónde | Qué |
|---|---|
| **Agentes → Atención a clientes** | Lo que ya existe, sin tocar |
| **Agentes → Equipo interno → Oficina** | Personajes con estado: libre / trabajando / terminado / necesita aprobación / error |
| **Agentes → Equipo interno → Agentes** | Lista y asistente de creación |
| **Agentes → Equipo interno → Cargos** | Plantillas |
| **Agentes → Equipo interno → Aprobaciones** | Cola de propuestas con Aprobar / Editar / Rechazar |
| **Agentes → Equipo interno → Consumo** | Tokens y costo por agente, día y modelo |
| **Automatizaciones** (entrada propia en el menú) | Lista, lienzo, ejecuciones, plantillas |

Las propuestas pendientes se suman al **pulso que ya existe** de la campana, sin peticiones nuevas
(regla de velocidad). Los estados usan las familias de color del tema, y se comunican con texto e
icono, no solo con color.

---

## 6. Marketing para los clientes que usan Briela

Agentes (cargos de fábrica) que se apoyan en Redes, CRM, catálogo y correo masivo:

| Cargo | Qué hace | Qué deja |
|---|---|---|
| **Community manager** | Arma el calendario semanal desde el catálogo, las OP terminadas y las promociones | Publicaciones en **borrador** en Redes |
| **Casos de éxito** | Toma una OP despachada con **fotos reales** y redacta el caso | Borrador de publicación y de ficha web |
| **Reactivación** | Detecta clientes dormidos y redacta la campaña segmentada | Borrador de correo masivo, con aprobación |
| **Calificador de leads** | Puntúa y prioriza los leads entrantes | Etiqueta y propuesta de asignación |
| **Seguimiento comercial** | Redacta el siguiente contacto de cotizaciones y leads parados | Propuesta al vendedor |

Reglas que se mantienen:

- La **foto del producto es siempre la real**; la IA solo hace lo decorativo.
- **Nada sale al público sin aprobación** al principio. Una respuesta en un comentario público la
  lee cualquiera y la indexa Google.
- No se prometen **precios de lo que se fabrica a la medida**.
- Se respeta la ventana de 24 h de Meta y la lista de correos rebotados; la validación la hace
  el servidor al enviar, no el modelo.
- **Contacto en frío y datos personales:** conviene que lo revise un asesor legal antes de
  automatizar mensajes (Ley 1581 y consentimiento en WhatsApp). Esto no es asesoría legal.
- **Pruebas primero en `sistema.briela.app`**, la instalación propia que ya existe como banco de
  pruebas.

---

## 7. Plan por fases

Tamaños relativos (S < M < L < XL) y **sin calendario**: no tengo base para prometer fechas hasta
medir la Fase 0.

| Fase | Contenido | Tamaño | Sale de la fase |
|---|---|---|---|
| **0. Cimientos** | `IaService::chat()` con herramientas · lectura de `usage` y tabla `ia_consumo` · guardar `supported_parameters` · **verificar el proxy** · worker por cron · usuario de servicio · registro de herramientas | **L** | Un agente de prueba corre en cola, gasta tokens medidos y no escribe nada |
| **1. Agentes y cargos** | Tablas y modelos · cargos de fábrica · asistente de creación · editor de prompt por capas con versiones y prueba en seco · selector de modelo con recomendación · aprobaciones · oficina básica · 3 agentes (Analista del día, Vigía, Seguimiento comercial) | **XL** | Crear un agente desde la interfaz, elegir modelo y prompt, probarlo y aprobarle propuestas |
| **2. Automatizaciones (motor y lista)** | Tablas · emisión de eventos desde los *observers* · motor con esperas, condiciones y anti-bucles · editor **en lista** · nodos básicos · plantillas · integración con agentes | **XL** | Una regla «cuando… entonces…» corre con IA y sin IA, con trazabilidad por nodo |
| **3. Lienzo y marketing** | Lienzo visual sobre el mismo motor · ramas y esperas · más disparadores · cargos de marketing | **L** | Se dibuja una automatización y se prueba con un evento de ejemplo |
| **4. Madurez** | Comparador de modelos · autonomía por acción con tasa de aprobación · informe de consumo · exportar e importar plantillas · reglas de retención | **L** | Soltar una acción a `auto` con datos |

**Por qué este orden:** el motor de automatizaciones (Fase 2) reutiliza el catálogo de
herramientas, las aprobaciones y la bitácora de la Fase 1; hacer el lienzo (Fase 3) después del
motor evita construir la parte vistosa sobre algo que aún no funciona.

### Archivos que se tocarían (alto nivel)

**Nuevos:** migraciones (cargos, agentes, versiones de prompt, ejecuciones, pasos, propuestas,
consumo, automatizaciones y sus tablas) · modelos · `app/Services/Agentes/*` (ejecutor, catálogo
de herramientas, armado de prompt, recomendador de modelo) · `app/Services/Automatizaciones/*`
(motor, evaluador de condiciones, emisor de eventos) · `app/Jobs/*` · comandos
`agentes:despachar`, `automatizaciones:avanzar` y de limpieza · controladores · páginas
`resources/js/Pages/Agentes/*` y `Pages/Automatizaciones/*` · manuales
`docs/manual/agentes-internos.md` y `automatizaciones.md` · pruebas.

**Existentes (cambios aditivos):** `IaService` · `Permisos` · `Modulos` · `NotificacionService` ·
los *observers* que emitirán eventos · `routes/web.php` y `routes/console.php` ·
`Pages/Agentes/Index.vue` (pestañas) · el menú (`AppLayout.vue`, solo la entrada de
Automatizaciones, sin peticiones) · el pulso de la campana.

**Se vigila:** `HandleInertiaRequests::share()`, `Op::sincronizarEstado()`, `CierrePasoService`,
`RemisionService`. Nada nuevo toca estados por fuera de ellos.

---

## 8. Riesgos de esta ambición

| Riesgo | Mitigación |
|---|---|
| **Es muy grande.** Son dos productos (agentes y automatizaciones) y un lienzo | Fases con valor propio en cada una; el lienzo va al final sobre un motor ya probado |
| **Instrucciones editables = más superficie de error** | Capa 1 fija en código, prueba en seco obligatoria, versiones y restauración |
| **Un agente configurado a la ligera hace daño** | El catálogo de herramientas es cerrado, el techo del cargo manda y el techo de autonomía está en código |
| **Consumo desbocado** (agentes + automatizaciones por evento) | Topes por corrida/día/agente/automatización, límite por hora, interruptor global, cupo reservado para el asistente |
| **Bucles entre automatizaciones** | Profundidad máxima y marca de origen |
| **Cada evento cuesta una consulta** | `emitir()` consulta solo memoria de la petición; medir antes y después |
| **Lienzo en celular** | Vista de lista como equivalente móvil |
| **Actualizar 10 instalaciones** | Todo tablas nuevas; plantillas de fábrica versionadas y apagadas; el cliente edita copias, nunca el original |
| **Datos de clientes llegan a un modelo** | Solo agregados donde basta, bitácora recortada, catálogos cerrados por cargo |
| **El proxy no soporta herramientas** | Se verifica en la Fase 0 **antes** de construir encima |

---

## 9. Decisiones confirmadas (12 oct 2026)

| # | Tema | Decisión | Qué cambia en el plan |
|---|---|---|---|
| 1 | Acciones «nunca automáticas» | **De acuerdo** con la lista propuesta: enviar mensajes a clientes o redes, pagos, estado de la OP, despachar, borrar, cambiar precios o márgenes | Techo fijado en código |
| 2 | Quién gestiona el consumo | **El superadmin** | El costo real y el cobro viven allá. La instalación solo **etiqueta** cada llamada (agente, ejecución) para que el superadmin pueda agrupar, y guarda una copia local mínima en tokens para mostrar por agente. Ya no se calcula costo en la instalación |
| 3 | Cupo de IA | **Aparte** del asistente, y **módulo aparte**, apagable | Equipo de IA y Automatizaciones son módulos propios en `Modulos`, apagables desde la instalación y desde la ficha en el superadmin. El proxy necesita un **cupo separado para agentes** |
| 4 | Aprobaciones | **Humanas** | En 2.0 toda propuesta la decide una persona. El modo `auto` por acción queda **fuera de 2.0** y se evalúa después |
| 5 | Presupuesto | **Sin presupuesto fijado** | El tope en dinero lo pone el cupo del superadmin. Se conservan **topes técnicos** por corrida (pasos y tokens) y el interruptor global, porque no dependen del dinero: evitan bucles |
| 6 | Datos que no llegan a un modelo | **Los propuestos**: costos, márgenes, datos personales de operarios y retenciones | Ninguna herramienta los devuelve y una capa de redacción los elimina antes de armar el prompt. Aunque el rol del agente tuviera `costos.ver`, el catálogo de agentes **no** los expone |
| 7 | Versión | **Briela 2.0** | Ver sección 10 |

### Consecuencias técnicas de las decisiones 2 y 3 (trabajo en otro repo)

El proxy y el cobro están en `briela-superadmin`, así que la **Fase 0 incluye tareas allá**:

1. Aceptar y reenviar `tools`, `tool_choice` y `models`, y devolver `tool_calls` y `usage`.
2. Recibir una **etiqueta de origen** (por ejemplo, una cabecera `X-Briela-Origen: agentes` con agente y
   ejecución) para separar el consumo de los agentes del del asistente.
3. Un **cupo propio de agentes** por instalación, con su propio motivo de rechazo (el 402 que hoy
   habla de suscripción o límite diario debe distinguir «asistente» de «agentes»).
4. El interruptor del módulo en la ficha de la instalación, que ya existe para los módulos y se
   sincroniza por el latido.
5. Una vista de consumo de agentes por instalación, para el cobro.

Sin esto no se puede construir encima. **Es lo primero que se verifica y se hace.**

### Decisión de producto que esto implica

Como el cupo es aparte, **un cliente puede no contratar los agentes**: con el módulo apagado no
hay menú, ni cron, ni gasto, y el asistente sigue igual. Eso convierte los agentes en algo que se
puede **ofrecer y cobrar por separado**, que es consistente con que Briela gane con el consumo de IA.

---

## 10. Versión 2.0

Estado actual: la última versión es la **1.6.0** (12 oct 2026).

- **Es una versión mayor por producto, no por ruptura.** El `CHANGELOG.md` define «mayor» como
  cambios que rompen algo o exigen intervención. Todo lo de este plan es **aditivo**
  (tablas nuevas, módulos nuevos y apagados de fábrica), así que por semver estricto sería
  **1.7.0**. Llamarla **2.0.0** es válido como hito; hay que decirlo en las notas para que el
  cliente no espere un cambio que rompa algo.
- **No se despliega directo.** Hasta ahora, antes de declarar la primera versión, se desplegaba
  directo a `main`. Una 2.0 se desarrolla en **rama**, se prueba en `sistema.briela.app` y se
  libera con el flujo de `docs/manual/publicar-version.md`: notas en `CHANGELOG.md`, tag
  `v2.0.0`, paquete firmado, borrador en el superadmin y **Publicar** a mano.
- **Una versión puede salir por partes.** Cada fase es compatible y se puede publicar sola
  (por ejemplo, 2.0 con la Fase 0 y la 1; la Fase 2 como 2.1). Así el cliente no recibe todo de
  golpe ni nosotros esperamos a tener todo para entregar valor.
- **Migraciones solo hacia adelante y nunca destructivas**, como siempre. Los módulos nuevos
  nacen **apagados** en las instalaciones existentes.
- **Licencia:** la regla exige la versión vigente o la inmediatamente anterior. Si se publica la
  2.0.0 justo después de la 1.6.0, esa regla se cumple sin tocar nada.
- Al cerrar se actualizan `CLAUDE.md` (tabla de fases), `docs/manual/` (un manual por módulo) y
  `docs/MANUAL-BRIELA.md`.

---

## 11. Pendiente de decidir

1. **Número de la versión:** ¿2.0.0 como hito, o 1.7.0 por semver estricto?
2. **Alcance de 2.0.0:** ¿solo Fases 0 y 1 (agentes, cargos, aprobaciones), o también el motor de
   automatizaciones (Fase 2)? Recomendación: 0 y 1 como 2.0, y automatizaciones como 2.1.
3. **Acceso a `briela-superadmin`** para hacer ahí la parte del proxy y el cupo.
4. **Quién aprueba qué** y en cuánto vence una propuesta. Propuesta por defecto: la persona
   responsable del documento o cualquiera con `equipo_ia.aprobar` más el permiso de la acción,
   y vence a las 72 horas.
5. **Librería del lienzo** (validar licencia y peso) y si el celular edita o solo lee.
6. **Cierre de tarea y ramas:** trabajar en una rama de 2.0 aparte, sin tocar `main` hasta
   publicar.
