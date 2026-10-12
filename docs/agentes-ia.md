# Agentes de IA internos para Briela — informe de análisis

> **Estado:** análisis. No se tocó código, no hay migraciones ni commits. Este archivo es lo único
> que se creó. Fecha: 12 oct 2026.
>
> **Aviso sobre el cierre de tarea de `CLAUDE.md`:** por pedido expreso, en esta fase no se corrió
> el grafo, no se hizo commit, no se hizo push y no se desplegó. El archivo queda sin
> comprometer en el árbol de trabajo.

---

## 0. Resumen ejecutivo

1. **Briela ya tiene el 70 % de la base**: un punto único de salida a IA (`IaService`) con proxy y
   licenciamiento, un catálogo cerrado de 16 consultas de solo lectura con permisos, un patrón
   de «proponer → confirmar» para transacciones (`TransaccionesIaService`), el motor de
   informes (`MotorInformes`, la IA elige y el servidor calcula), notificaciones, permisos por
   rol y un scheduler.
2. **Lo que falta es poco pero es estructural**, y son cuatro cosas:
   - `IaService` **no soporta *tool calling***. Hoy pide un JSON dentro del texto y lo interpreta
     (ver `AsistenteController::resolverDatos`). Un agente que razona en varios pasos necesita
     el protocolo nativo de herramientas.
   - **No hay registro local de consumo**. Los tokens y el costo los mide el proxy del
     superadmin por instalación, pero la instalación no sabe cuánto gastó cada módulo ni cada
     agente.
   - **No existe ni un solo Job**. La tabla `jobs` está migrada y `QUEUE_CONNECTION=database`,
     pero no hay `ShouldQueue` ni `dispatch(` en todo el código, y en Hostinger no hay un
     *worker* permanente: solo el cron de cada minuto.
   - **Casi todo el sistema asume `auth()->user()`** (permisos y sede). Un agente que corre por
     cron no tiene usuario.
3. **Choque de nombres:** ya existe «Agentes de IA» (`agentes_ia`, `/configuracion/agentes`,
   permiso `agentes.*`). Son los que **atienden a clientes** por WhatsApp y web. Lo que se propone
   aquí son agentes **internos** que trabajan para el equipo. **Decisión (12 oct 2026): viven
   dentro del mismo módulo «Agentes»** (`/configuracion/agentes`), no como un módulo aparte: una
   sola entrada de menú con dos secciones, **«Atención a clientes»** (lo que ya existe) y
   **«Equipo interno»** (lo nuevo, con la oficina visual). Eso sí, **siguen siendo tablas
   distintas** (ver sección 3.1). En este informe se les llama **«agentes internos»**.
4. **Recomendación de arranque:** una Fase 0 de infraestructura (herramientas, consumo, cola) y
   una Fase 1 con tres agentes que **solo leen y proponen**: *Vigía de producción y entregas*,
   *Seguimiento comercial* y *Analista del día*.
5. **Principio de diseño que se conserva:** la IA elige y redacta; **el servidor calcula y
   escribe**. Ningún agente toca SQL ni cambia un estado por su cuenta. Todo lo que cambie
   datos del negocio pasa por los mismos servicios que usan las pantallas, y con aprobación.

---

## PASO 1 — Diagnóstico

### 1.1 Qué es Briela y qué hace cada módulo

ERP **instalable, de un solo cliente por instalación** (Laravel 13 + PHP 8.3, Vue 3 + Inertia,
MySQL), para fabricantes por pedido. Cada cliente tiene su servidor y su base; el superadmin
(repo aparte) maneja seriales, suscripciones, proxy de IA y versiones. El manual completo tiene
42 módulos; agrupados por área:

| Área | Módulos y qué hacen |
|---|---|
| **Ventas** | CRM/leads con embudo; cotizaciones (con enlace público de aprobación); comisiones; bandeja unificada de WhatsApp, Instagram y Messenger; agentes de IA que atienden clientes |
| **Cotizador** | Plantillas de ensamble con campos, secciones y fórmulas (`FormulaEvaluatorService`, sin `eval`); ensambles directos; precios por canal con margen sobre costo |
| **Producción** | OP (`borrador → confirmada → en_produccion → calidad → despachada`, más `reproceso`); un trabajo por unidad física con pasos, tiempos y fotos; calidad con candado; programador y pantalla de planta |
| **Inventario y compras** | Productos, bodegas, stock, movimientos, reservas; solicitudes y órdenes de compra; proveedores y su calificación; importaciones liquidadas |
| **Logística y financiero** | Remisiones por unidad con firma; cartera (cuotas y pagos); verificador de pagos contra extracto bancario; retenciones; monedas y tasas |
| **Personas** | RRHH (operarios, horas extra, bonos, disciplina), capacitación (cursos, evaluaciones, certificados), puntos y niveles |
| **Transversal** | Notificaciones, auditoría (`RegistroActividad`), chat interno, hilos/comentarios con tareas, informes, buscador global, sedes, roles y permisos, módulos apagables, respaldos |
| **Publicación** | Catálogo web, redes sociales (publicación programada), PDFs por plantilla |

La regla de fondo del negocio, y que condiciona a los agentes: **cada acción real dispara sola el
siguiente paso del proceso**, y los estados de la OP los decide un solo sitio
(`Op::sincronizarEstado()`). Un agente jamás debe escribir `estado` a mano.

### 1.2 Cómo está hecha hoy la integración con OpenRouter

| Aspecto | Hallazgo |
|---|---|
| **Dónde vive** | `app/Services/IA/IaService.php` (878 líneas). Es **el único punto de salida**. Cuatro endpoints: `chat/completions` (con y sin *stream*), `audio/speech`, `GET /models` |
| **Por dónde sale** | Si la instalación tiene serial, **siempre por el proxy del superadmin** (`BRIELA_IA_URL`, por defecto `superadmin.briela.app/api/ia`) con el serial como `Bearer`. La llave de OpenRouter **no vive en la instalación**. Solo sin serial (desarrollo) sale directo con `ia_api_key` |
| **Cómo se elige el modelo** | **Global y configurable por el administrador**, guardado en `configuracion`: `ia_modelo_texto` (por defecto `anthropic/claude-sonnet-5`), `ia_modelo_rapido` (cae al de texto si está vacío), `ia_modelo_imagen`, más voz. Solo hay **dos niveles** (texto / rápido) y el que llama elige con el parámetro `rapido: true`. **No hay modelo por módulo, ni por función, ni por agente**, y no hay *fallback* entre modelos |
| **Lista de modelos** | `modelosDisponibles()` trae `/models`, lo separa en texto / imagen / voz y lo cachea un día. **No guarda `supported_parameters`**, así que hoy no se sabe qué modelo soporta herramientas |
| **Prompts** | Viven **en el código**, en cada servicio, salvo dos que el cliente puede reescribir desde la base: la ficha técnica (Perfil de marca) y las `instrucciones` de cada agente de atención (`agentes_ia.instrucciones`). El tono sale de `PerfilMarca`. Regla del producto: lo que cambia por cliente es configuración, no parche |
| **Errores** | Un solo sitio, `llamar()`: lanza `IaException`; el 402 se traduce según vaya por proxy (suscripción / límite diario) o directo (saldo); se escribe `Log::error`. Hay `priorizarVelocidad` (`provider.sort = throughput`). **No hay reintento ni *fallback* a otro modelo** |
| **Consumo de tokens y costo** | **No hay registro local.** `llamar()` no lee `usage`. El proxy mide y cobra por instalación (por eso la instalación no necesita la llave), pero la instalación no puede decir «el asistente gastó X, las fichas Y». Existe `ultimaDuracionMs` solo para diagnóstico de velocidad |
| ***Tool calling*** | **No existe.** No hay `tools`, `tool_calls` ni `tool_choice` en el código. El asistente funciona en dos llamadas: (1) modelo rápido que devuelve un JSON «qué consultas uso», (2) modelo de texto que redacta con los resultados. Es robusto y barato, pero es de **un solo paso** |
| **Qué usa IA hoy** | Asistente/guía (consultas, acciones, informes), agentes públicos y de cliente (WhatsApp/web/redes), ficha técnica, recomendador de productos, lector de RUT y documentos, paso de producción por IA, plantillas PDF por IA, imágenes, voz |

**Piezas de IA ya construidas que se reutilizan tal cual:**

- `ConsultasDatosService::catalogo()` — **16 consultas de solo lectura** (ventas, producción,
  entregas, stock bajo, cartera, compras, embudo…), cada una con descripción, parámetros y
  permiso. Respetan permisos y sede. Son, de hecho, **las herramientas de lectura del agente ya
  escritas**.
- `AccionesIaService` / `TransaccionesIaService` — escritura **solo con propuesta y
  confirmación**: `preparar…` arma el resumen sin escribir, `hacer…` ejecuta con los mismos
  servicios que las pantallas. Cubre generar OP, alistar, remisionar, pagos, cerrar pasos,
  aprobar calidad, mover leads, etc.
- `MotorInformes` — la IA elige un plan JSON, el servidor lo calcula y a la redacción **solo
  llegan totales, nunca filas** (`paraIa()`). Guarda el análisis en `informes.analisis`.
- `AgenteConversacionService` — el orquestador de los agentes de atención, con la lección más
  importante de seguridad: **catálogos separados por perfil, no un catálogo con filtro**.

### 1.3 Colas, scheduler, notificaciones y permisos

| Pieza | Estado | ¿Reutilizable? |
|---|---|---|
| **Colas** | `QUEUE_CONNECTION=database`, tablas `jobs`, `job_batches` y `failed_jobs` migradas. **Cero Jobs en el código.** No hay worker | Sí, pero hay que **diseñar cómo corre el worker en Hostinger** (ver 3.4) |
| **Scheduler** | `routes/console.php` con ~14 tareas (`dailyAt`, `everyMinute`, `withoutOverlapping`, `->when(Modulos::activo(...))`). Requiere cron cada minuto, que es lo que pide `docs/instalar-en-hostinger.md` | Sí, es el disparador natural |
| **Notificaciones** | `NotificacionService` es el punto único: catálogo por módulo, configurable por tipo, campanita, correo opcional, antiduplicado (`yaAvisado`), agrupa por responsable | Sí. Los agentes avisan por aquí; solo hay que **agregar tipos** al catálogo |
| **Roles y permisos** | Catálogo en código (`Permisos`), roles configurables, `User::permisos()`, middleware `permiso:`. Ya existe el grupo `agentes` (ver, gestionar) | Sí, ver 3.5 |
| **Módulos apagables** | `Modulos::activo()`; un módulo apagado quita permisos, menú y rutas. El catálogo dice que «el asistente y los agentes de IA van siempre» | Decisión pendiente: ¿el equipo interno es núcleo o módulo? (pregunta 4) |
| **Auditoría** | Trait `Auditable` + `RegistroActividad::registrar()` | Sí, para registrar **qué hizo un agente y a nombre de quién** |
| **Hilos y tareas** | `Comentario` (charla / solicitud / tarea con responsable y fecha) | Sí, una propuesta aprobada puede nacer como tarea asignada |
| **Informe de consumo** | No existe | Hay que construirlo |

**Restricciones del entorno que condicionan el diseño:**

- **Hostinger compartido:** sin procesos permanentes, sin Redis, sin Supervisor. Solo cron cada
  minuto y la base de datos como cola.
- **Producto instalable:** migraciones solo hacia adelante, nada de parchear un cliente, y todo
  lo que varíe por cliente debe ser configuración.
- **La IA es el modelo de ganancia:** sale por el proxy y se mide ahí. Un agente que gasta
  tokens en segundo plano consume el mismo cupo que el asistente interactivo.
- **Rendimiento:** nada nuevo en el layout puede pedir datos en `onMounted` ni costar una
  consulta por clic (regla «Velocidad» de `CLAUDE.md`).

---

## PASO 2 — Oportunidades para agentes

Se priorizó lo que **hoy se hace a mano o no se hace**, con datos que Briela ya tiene.

> **Advertencia honesta:** varias de estas tareas ya tienen un aviso determinista (por ejemplo
> `notificaciones:entregas-proximas` o `crm:avisar-leads-quietos`). **Detectar es código, no IA**:
> una consulta es más barata, más rápida y más fiable que un modelo. Lo que aporta el agente es
> lo que el código no hace: **diagnosticar la causa**, **priorizar entre muchos casos**,
> **redactar** el siguiente paso y **juntar varias señales en un solo resumen**. Por eso todos
> los agentes de la tabla separan «detectar (PHP)» de «razonar y redactar (IA)».

### Resumen comparativo

| # | Agente | Rol en una línea | Modelo | Disparador | Riesgo | Aprobación | Valor | Esfuerzo |
|---|---|---|---|---|---|---|---|---|
| 1 | **Vigía de producción y entregas** | Diagnostica por qué una OP no llega a tiempo | Económico | Diario + manual | Bajo | No (solo avisa) | **Alto** | Medio |
| 2 | **Seguimiento comercial** | Redacta el siguiente contacto de cotizaciones y leads parados | Económico → medio | Diario + evento | Medio | **Sí** (no envía) | **Alto** | Medio |
| 3 | **Analista del día** | Resumen ejecutivo diario/semanal con cifras reales | Potente | Diario + manual | Bajo | No | **Alto** | **Bajo** |
| 4 | **Cobranza** | Prioriza cartera vencida y redacta recordatorios | Económico | Semanal + manual | Medio | **Sí** | Alto | Medio |
| 5 | **Auditor de datos** | Detecta inconsistencias que dañan costos y precios | Económico | Nocturno | Bajo | No | Medio-alto | Medio |
| 6 | **Abastecimiento** | Propone solicitudes de compra según stock, OPs y proveedores | Potente | Semanal + manual | Medio | **Sí** | Medio-alto | Alto |
| 7 | **Copiloto de bandeja** | Deja un borrador de respuesta al asesor | Económico | Evento (mensaje entrante) | Medio | **Sí** (siempre humano) | Medio | Medio |
| 8 | **Analista de calidad** | Busca patrones en rechazos y reprocesos | Potente | Mensual + manual | Bajo | No | Medio | Medio |

### 2.1 Vigía de producción y entregas

- **Rol:** jefe de planta virtual que cada mañana revisa las OP vivas y explica **por qué** se
  atrasan, no solo **que** se atrasan.
- **Lee:** `ops`, `op_items`, `op_item_trabajos`, `op_item_trabajo_pasos` (tiempos y
  dependencias), `op_cuotas`/`op_pagos` (¿bloqueada por pago?), `producto_stock`
  (`material_faltante`), `calidad_aprobada_at`, `Urgencia::de()`.
- **Escribe:** una notificación agrupada por responsable y un resumen guardado como ejecución.
  Nada más. No cambia estados.
- **Herramientas:** `ops_por_entregar` (ya existe), `detalle_op(numero)`, `pasos_atrasados(op)`,
  `material_faltante(op)`, `estado_pago(op)`, `historial_de_tiempos_del_paso(plantilla)`
  (nuevas, de lectura).
- **Modelo:** económico con herramientas. Es clasificación y resumen sobre datos ya calculados.
- **Disparador:** diario a las 6:20 (junto a los avisos de la mañana) y botón manual.
- **Riesgo:** bajo. Si se equivoca, un aviso mal priorizado. Mitigación: cada afirmación cita la
  OP y el dato (y se puede abrir).
- **Aprobación:** no.
- **Valor:** alto — una entrega tarde es plata y reputación. **Esfuerzo:** medio, porque hay que
  escribir las herramientas de detalle.

### 2.2 Seguimiento comercial

- **Rol:** asistente de ventas que no deja morir una cotización ni un lead.
- **Lee:** `cotizaciones` (enviadas sin respuesta, por vencer), `cotizacion_seguimientos`,
  `crm_leads` y su actividad, `clientes`, historial de compras, `ComisionVendedor`.
- **Escribe (siempre como propuesta):** borrador de mensaje (WhatsApp/correo), tarea en el CRM
  (`crm_tareas`), nota en el lead.
- **Herramientas:** `cotizaciones_sin_respuesta`, `leads_quietos`, `historial_cliente`,
  `ficha_cotizacion` (lectura); `proponer_mensaje`, `proponer_tarea_crm` (propuesta, no
  ejecutan).
- **Modelo:** económico para elegir a quién y por qué; medio para redactar con el tono de la
  marca (`PerfilMarca`). Redactar no necesita razonamiento largo.
- **Disparador:** diario (después de `crm:avisar-leads-quietos`) y al vencer una cotización.
- **Riesgo:** medio. Un mensaje inoportuno a un cliente es dañino. Por eso **no envía**: el
  vendedor lo revisa y lo manda desde la bandeja.
- **Aprobación:** sí, siempre. **Valor:** alto (conversión). **Esfuerzo:** medio.
- **Ojo:** respetar la regla de las 24 h de Meta (`EsConversacion::ventanaAbierta()`) y la
  lista de supresión de correo; el agente propone, pero la validación de canal la hace el
  servidor al enviar, no el modelo.

### 2.3 Analista del día

- **Rol:** gerente de operaciones virtual que entrega un resumen de una pantalla: qué pasó,
  qué está en riesgo, qué decidir hoy.
- **Lee:** a través de `MotorInformes` y de las 16 consultas: ventas, producción, cartera,
  stock, embudo. Solo agregados.
- **Escribe:** un informe (`informes` con `origen = 'agente'`, `analisis`) y una notificación
  de «tu resumen está listo».
- **Herramientas:** las existentes (`ventas_resumen`, `produccion_resumen`,
  `cartera_pendiente`, `crm_embudo`, `inventario_bajo_stock`, `generarInforme`). **Casi no hay
  herramientas nuevas.**
- **Modelo:** **potente.** Aquí se paga el razonamiento: comparar contra el periodo anterior,
  distinguir ruido de tendencia, priorizar. Una llamada al día no pesa en el costo.
- **Disparador:** diario 6:30 y semanal los lunes; manual.
- **Riesgo:** bajo — es lectura. El riesgo real es **alucinar una causa**. Mitigación: a la
  redacción solo llegan cifras calculadas por el servidor (ya es así en `paraIa()`), y el
  prompt prohíbe afirmar causas que los datos no muestren.
- **Aprobación:** no. **Valor:** alto. **Esfuerzo:** **bajo** (es la tarea más barata de
  construir porque casi todo existe).

### 2.4 Cobranza

- **Rol:** analista de cartera que ordena a quién llamar primero y con qué mensaje.
- **Lee:** `op_cuotas`, `op_pagos`, `movimientos_bancarios` (abonos sin conciliar),
  `clientes`, historial de pago, `RetencionesService`.
- **Escribe (propuesta):** lista priorizada, borrador de recordatorio, tarea de cobro.
- **Herramientas:** `cartera_pendiente` (existe), `cuotas_vencidas(cliente)`,
  `abonos_sin_asignar` (nueva, lee el verificador sin escribir).
- **Modelo:** económico.
- **Disparador:** semanal (lunes) y manual.
- **Riesgo:** medio. Reclamarle una deuda a quien ya pagó es muy mal servicio. Mitigación:
  antes de proponer, la herramienta descarta lo que el verificador ya concilió (reutilizar la
  regla «ante dos explicaciones se pregunta, nunca se adivina»).
- **Aprobación:** sí. **Valor:** alto (flujo de caja). **Esfuerzo:** medio.
- **No debe** registrar ni confirmar pagos por su cuenta: eso ya es una transacción con
  confirmación en `TransaccionesIaService` y no se mezcla.

### 2.5 Auditor de datos

- **Rol:** contador de costos que revisa lo que otros no ven hasta que sale mal.
- **Qué busca (todo con consultas deterministas; la IA explica y prioriza):** productos sin
  costo o con costo desactualizado, ensambles sin receta o con componentes sin precio,
  márgenes por debajo del canal base, cotizaciones aprobadas con costos que cambiaron después,
  OP sin cliente o con bodegas sin declarar, pagos sin verificar, tasas de cambio sin
  actualizar, clientes duplicados.
- **Lee:** productos, ensambles, `canal_precios`, cotizaciones, ops, `tasas`.
- **Escribe:** informe de hallazgos y notificación a administradores. **Corregir lo hace una
  persona.**
- **Herramientas:** una por regla de auditoría (nuevas, de lectura, reutilizando los
  comandos `precios:recalcular` y `ops:sincronizar-estados` solo en modo informe).
- **Modelo:** económico. **Disparador:** nocturno (después del respaldo de las 2:00).
- **Riesgo:** bajo. **Aprobación:** no. **Valor:** medio-alto — evita el error que se paga
  silencioso (el caso del ensamble con tres precios). **Esfuerzo:** medio.

### 2.6 Abastecimiento

- **Rol:** comprador virtual que prepara la solicitud antes de que falte el material.
- **Lee:** `producto_stock`, `producto_movimientos` (consumo real), OP confirmadas (demanda
  comprometida: componentes de ensambles por unidad × cantidad), `producto_proveedores` y su
  historial de precios, `CalificacionProveedorService`, `ordenes_compra` abiertas, `Importacion`.
- **Escribe (propuesta):** `SolicitudCompra` **en borrador** con ítems, cantidades y proveedor
  sugerido, justificando cada línea.
- **Herramientas:** `inventario_bajo_stock` (existe), `demanda_comprometida(dias)`,
  `consumo_historico(producto)`, `proveedores_de(producto)`, `proponer_solicitud_compra`.
- **Modelo:** **potente.** Mezcla demanda, consumo, plazos de entrega y precio: es el caso
  donde razonar mejor ahorra plata de verdad.
- **Disparador:** semanal y manual. **Riesgo:** medio — comprar de más o de menos tiene costo.
  **Aprobación:** sí (la solicitud nace en borrador y sigue el flujo normal de aprobación de
  compras). **Valor:** medio-alto. **Esfuerzo:** alto (la demanda comprometida es nueva y
  delicada: hay que respetar reservas, bodegas y moneda).

### 2.7 Copiloto de bandeja

- **Rol:** asesor junior que deja listo un borrador y los datos de contexto.
- **Lee:** la conversación (`whatsapp_*` / `bandeja_*`), cliente asociado, sus cotizaciones y
  OP, catálogo (`RecomendadorProductosService`).
- **Escribe:** un **borrador sugerido** en la caja de respuesta. **Nunca envía.**
- **Modelo:** económico. **Disparador:** evento — llega un mensaje a una conversación ya
  tomada por una persona.
- **Riesgo:** medio (datos de clientes). Debe usar los **mismos catálogos por perfil** que ya
  separan lo público de lo del cliente, y no hablar de precios de lo que se fabrica a medida.
- **Aprobación:** sí, es humano por construcción. **Valor:** medio. **Esfuerzo:** medio.
- **Nota:** ya existen agentes que contestan solos; este es el complemento para cuando la
  conversación la tomó una persona. Es el único caso con **latencia sensible** (el asesor
  espera): sirve mejor un job inmediato que el cron de cada minuto.

### 2.8 Analista de calidad

- **Rol:** ingeniero de calidad que busca causas repetidas.
- **Lee:** `op_item_trabajo_checks`, rechazos y `mandarAReproceso`, pasos con retrabajo,
  ensambles y plantillas implicadas, tiempos por paso.
- **Escribe:** informe mensual con hipótesis y ensambles/pasos a revisar.
- **Modelo:** potente. **Disparador:** mensual y manual. **Riesgo:** bajo en datos,
  **sensible en personas**: debe agrupar por ensamble, paso y plantilla, y **no señalar
  operarios** salvo que RRHH lo configure expresamente. **Aprobación:** no.
  **Valor:** medio. **Esfuerzo:** medio.

### Qué se descarta o se pospone

- **Un agente que apruebe cotizaciones, cierre pasos, confirme pagos o despache** de forma
  autónoma: el valor no compensa el riesgo, y esas acciones ya existen como propuesta con
  confirmación en la guía.
- **Un agente que cambie precios o márgenes:** es una decisión de negocio y dispara
  `precios:recalcular` y `comisiones:recalcular`.
- **Agentes que hablen con clientes sin humano** más allá de los que ya existen (con su
  interruptor aparte para comentarios públicos).

---

## PASO 3 — Arquitectura propuesta

### 3.1 Principio rector: reutilizar, no reinventar

```
                       ┌──────────────────────────────────────────────┐
  Disparadores         │  EjecutorAgente (servicio, un solo punto)    │
  cron / evento /  ──► │   1. carga agente + herramientas permitidas  │
  botón manual         │   2. elige modelo (con fallback)             │
        │              │   3. bucle: modelo ⇄ herramientas (máx N)    │
        ▼              │   4. registra pasos y consumo                │
  Job en cola ───────► │   5. deja resultado: aviso / informe /       │
  (database)           │      PROPUESTA pendiente de aprobación       │
                       └───────┬───────────────┬──────────────────────┘
                               │               │
                  ┌────────────▼───┐   ┌───────▼─────────────────┐
                  │ IaService       │   │ Herramientas (PHP)      │
                  │ chat() + tools  │   │ lectura: catálogo       │
                  │ → proxy → OR    │   │ existente + nuevas      │
                  └─────────────────┘   │ escritura: SOLO propone │
                                        └───────┬─────────────────┘
                                                │ al aprobar
                                   ┌────────────▼──────────────┐
                                   │ TransaccionesIaService /  │
                                   │ servicios de las pantallas│
                                   └───────────────────────────┘
```

**Decisión de interfaz: un solo módulo «Agentes».** El menú, la ruta base y el módulo
apagable son los mismos; la pantalla `Agentes/Index.vue` pasa a tener dos pestañas. Quien solo
tenga `agentes.*` sigue viendo solo la atención a clientes; la pestaña interna exige además los
permisos `equipo_ia.*`.

**Decisión de modelado: tabla propia, no `agentes_ia`.** `agentes_ia` modela *quién atiende a un
cliente* (perfil `publico`/`cliente`, canales, horario, escalamiento). Un agente interno tiene
otra naturaleza (frecuencia, presupuesto, qué propone, a quién avisa). Meterlos en la misma tabla
obligaría a agregar columnas que no aplican a ninguno de los dos y mezclaría en una pantalla
cosas con riesgos opuestos (uno habla con desconocidos, el otro lee la cartera completa). Es la
misma razón por la que los catálogos público y de cliente son separados. Se pueden **mostrar
juntos** en la oficina visual sin compartir tabla.

### 3.2 Tablas necesarias (todas nuevas; ninguna migración toca tablas existentes)

Solo se agregan tablas y columnas, siempre con `Schema::hasTable/hasColumn`, como el resto del
proyecto. Nombres provisionales.

| Tabla | Para qué | Columnas clave |
|---|---|---|
| **`agentes_internos`** | Definición de cada agente | `clave` (único, ej. `vigia_produccion`), `nombre`, `rol`, `instrucciones` (texto editable), `herramientas` (json de claves de catálogo), `nivel_modelo` (`economico`/`potente`), `modelo` (nullable, anula el nivel), `modelo_respaldo` (nullable), `max_pasos`, `max_tokens_ejecucion`, `tope_diario_usd` o `tope_diario_tokens`, `frecuencia` (json: tipo, hora, días), `usuario_id` (identidad con la que actúa), `activo`, `modo` (`solo_lectura`/`propone`), `sede_id` nullable, `avatar`/`color`, `creado_por` |
| **`agente_ejecuciones`** | Una corrida | `agente_id`, `estado` (`en_cola`, `corriendo`, `terminada`, `fallida`, `cancelada`), `origen` (`cron`, `manual`, `evento`), `disparado_por` (user nullable), `entrada` (json), `resumen` (texto corto), `resultado` (json), `error`, `modelo_usado`, `pasos`, `tokens_entrada`, `tokens_salida`, `costo` (decimal), `iniciada_at`, `terminada_at`, `clave_idempotencia` (único) |
| **`agente_pasos`** | Bitácora detallada de cada ejecución (la «caja negra») | `ejecucion_id`, `orden`, `tipo` (`mensaje`, `herramienta`, `resultado`, `error`), `herramienta`, `argumentos` (json), `resultado_resumen` (**recortado**), `ms` |
| **`agente_propuestas`** | Cola de aprobación | `ejecucion_id`, `agente_id`, `accion` (clave de herramienta/transacción), `parametros` (json), `resumen`, `entidad_type/id` (qué documento toca), `estado` (`pendiente`, `aprobada`, `rechazada`, `vencida`, `fallida`), `decidida_por`, `decidida_at`, `motivo`, `vence_at`, `clave_idempotencia` |
| **`ia_consumo`** | Libro de consumo de **toda** la IA, no solo de los agentes | `fecha`, `origen` (`asistente`, `agente_publico`, `ficha`, `imagen`, `voz`, `agente_interno`…), `agente_id` nullable, `ejecucion_id` nullable, `user_id` nullable, `modelo`, `tokens_entrada`, `tokens_salida`, `costo` nullable, `ms`, `por_proxy`, `ok` |

Notas de diseño:

- **`ia_consumo` es lo que hoy falta y sirve a todo el producto**, no solo a los agentes:
  permite al administrador ver «¿en qué gasto la IA?». Se llena en **un solo lugar**
  (`IaService::llamar()`), que es el único punto de contacto, así que ninguna llamada queda
  fuera. Los agentes solo agregan `agente_id`/`ejecucion_id` mediante contexto.
- **`agente_pasos.resultado_resumen` se recorta.** Los resultados de herramientas pueden
  contener datos de clientes; guardar la fila completa duplicaría datos sensibles en una
  bitácora. Se guarda lo mínimo para auditar («consultó 12 OPs»), no el volcado.
- **Retención:** `agente_pasos` y `ia_consumo` crecen rápido. Hace falta un comando de
  limpieza programado (por ejemplo, detalle de 90 días y agregados mensuales para siempre), en
  la línea del respaldo que limpia los de más de 30 días.
- **Sin `tenant_id`**: es single-tenant por instalación, como todo lo demás.

### 3.3 Modelo por agente, con fallback

Hoy hay dos niveles globales. La propuesta los **mantiene como base** y agrega el modelo por
agente encima. Cadena de resolución, de más específico a más general:

```
1. agentes_internos.modelo              (si el administrador fijó uno para este agente)
2. nivel_modelo → ajustes globales      economico → ia_modelo_rapido
                                         potente   → ia_modelo_texto
3. valor por defecto de IaService       (hoy: anthropic/claude-sonnet-5)
```

Y la cadena de **respaldo ante fallo** (hoy no existe):

```
modelo principal → agentes_internos.modelo_respaldo → modelo del nivel contrario
```

- Se aplica ante error 5xx/timeout/modelo no disponible, **no** ante 402 (si el proxy corta por
  suscripción o límite, cambiar de modelo no ayuda y gasta otro intento).
- OpenRouter admite una lista `models` en la petición para hacer el *fallback* de su lado;
  **hay que confirmar que el proxy del superadmin la reenvíe** (pregunta 6). Si no, el
  respaldo se implementa en `IaService` con un segundo intento.
- Los ajustes globales siguen mandando para el asistente y todo lo demás: **no cambia nada para
  lo que ya funciona**.
- El administrador debe poder ver en la pantalla del agente: modelo efectivo, de dónde sale
  (propio / nivel / defecto), y si soporta herramientas.

### 3.4 Cómo se ejecutan las tareas (Hostinger y producto instalable)

**El problema:** el *worker* de colas de Laravel espera un proceso permanente. En hosting
compartido eso no existe. **La solución compatible**, sin depender de nada del cliente
(regla 5 del producto):

```php
// routes/console.php  (propuesta, no implementada)
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=1')
    ->everyMinute()->withoutOverlapping();

Schedule::command('agentes:despachar')->everyFiveMinutes()
    ->when(fn () => Modulos::activo('agentes_internos'));
```

- `agentes:despachar` mira qué agentes activos tienen su turno y encola un `EjecutarAgenteJob`
  por cada uno (con `clave_idempotencia` = agente + ventana, para que dos pasadas del cron no
  creen dos corridas).
- El worker de cada minuto procesa lo que haya y se apaga. Ya se hace así con `schedule:run`.
- **Latencia de hasta un minuto** para los trabajos en cola. Aceptable para todo salvo el
  copiloto de bandeja; para ese caso se puede despachar con `dispatchAfterResponse()` y
  `afterCommit`.
- **Botón «Ejecutar ahora»:** encola el job y la pantalla consulta el estado cada pocos segundos
  (mismo patrón que el chat con `?desde={id}`; **sin websockets**).
- **Límites de tiempo:** una corrida en CLI no tiene el límite de 30 s del web, pero la llamada
  HTTP al modelo sí: `timeout` por llamada (60–120 s) y `Job::$timeout` global. Cada
  ejecución tiene `max_pasos` (por ejemplo 8) para evitar bucles.
- **Robustez:**
  - `tries = 1` en el job y reintentos controlados **dentro** del ejecutor (un reintento de un
    job entero repetiría llamadas ya pagadas).
  - Un `failed()` que deje la ejecución en `fallida` con el mensaje y avise a los
    administradores.
  - Un barrido que marque como `fallida` toda ejecución `corriendo` hace más de N minutos (el
    proceso muere sin avisar en hosting compartido).
- **Dependencias del cliente:** si el cliente no configuró el cron, **nada corre**. Hay que
  detectarlo y avisarlo en la pantalla («último latido del cron: hace 3 días»), igual que se
  hace con otros requisitos. Se puede apoyar en `briela:latido`.
- **Concurrencia:** la base como cola con sesiones en archivo ya generó esperas en el pasado
  (ver «Velocidad»). Los jobs no usan sesión, pero **un agente largo no debe retener
  transacciones abiertas** mientras espera al modelo. Regla: leer → cerrar → llamar → escribir.

### 3.5 Permisos, identidad y aprobaciones

**Identidad con la que actúa el agente.** Todo el sistema de consultas depende de
`auth()->user()` (permisos y `ContextoSede`). En vez de reescribirlo, **cada agente actúa como
un usuario del sistema** (`agentes_internos.usuario_id`) con un **rol propio y mínimo**. Así:

- El techo de lo que puede leer lo fija el rol, que ya es configurable desde la interfaz.
- `ConsultasDatosService`, `MotorInformes` y los permisos de costos (`costos.ver`) funcionan
  sin cambios, porque `User::permisos()` ya los resuelve.
- El ejecutor hace `Auth::setUser($agente->usuario)` al empezar el job.
- La auditoría registra a ese usuario, así que `RegistroActividad` dice qué hizo un agente
  sin código nuevo.
- **No** debe poder iniciar sesión: sin contraseña usable, marcado como cuenta de servicio,
  excluido de los listados de personal, de comisiones y de cualquier reparto automático de
  leads. Ese filtro hay que revisarlo en cada lugar que recorre `User` (por ejemplo, el reparto
  de leads entre vendedores).

**Permisos nuevos** (en `App\Support\Permisos`, como los de `agentes`):

| Permiso | Quién |
|---|---|
| `equipo_ia.ver` | Ver la oficina, ejecuciones y resultados |
| `equipo_ia.ejecutar` | Lanzar una corrida manual |
| `equipo_ia.aprobar` | Aprobar o rechazar propuestas (además del permiso de la acción real) |
| `equipo_ia.gestionar` | Crear, editar y apagar agentes, cambiar modelo, herramientas y topes |
| `equipo_ia.consumo` | Ver costos (como `costos.ver`: el gasto no se manda a quien no lo debe ver) |

Nombre del grupo provisional. No reutilizar `agentes.*` evita que quien administra la atención
a clientes acabe, sin querer, con acceso a la cartera.

**Aprobaciones — la regla que no se negocia:**

1. **Los agentes no tienen herramientas de escritura sobre datos del negocio.** Solo tienen
   `proponer_*`, que insertan una fila en `agente_propuestas`. Es la misma idea de
   `TransaccionesIaService::proponer()`: *proponer no escribe*.
2. Al aprobar, el sistema **vuelve a resolver y revalidar** (permiso, sede, estado actual,
   idempotencia), como hace hoy `/api/asistente/confirmar`, y ejecuta con **los mismos
   servicios que las pantallas**. Quien aprueba debe tener también el permiso de la acción
   real. La acción queda a nombre de la persona que aprobó, no del agente.
3. Las propuestas **vencen** (`vence_at`): aprobar tres días después algo basado en datos
   viejos es un riesgo.
4. **Idempotencia:** no proponer dos veces lo mismo mientras haya una pendiente o ya
   decidida en la ventana (mismo patrón de `yaAvisado`).
5. Los datos que llegan de afuera (mensajes de clientes, notas, nombres) pueden contener
   instrucciones maliciosas (*prompt injection*). Regla: el contenido de datos va marcado como
   datos, **ninguna herramienta de lectura puede disparar una escritura**, y el catálogo de
   herramientas de cada agente es cerrado y por agente (no uno global con filtro).
6. **Modos** por agente, para ir soltando confianza: `solo_lectura` (avisa), `propone`
   (cola de aprobación). Un `autonomo` **no se construye** en las primeras fases.

**Notificaciones.** Agregar al catálogo de `NotificacionService` (con su módulo en
`MODULO_DE_TIPO`): `agente_propuesta` (necesita aprobación), `agente_resumen` (resumen listo),
`agente_fallo` (corrida fallida o tope alcanzado).

### 3.6 Herramientas (*tools*) y qué modelos las soportan

**Qué hay que construir en `IaService`:** un método de chat con herramientas (por ejemplo
`chat(array $mensajes, array $herramientas, ...)`) que envíe `tools` y `tool_choice`, devuelva
`tool_calls` y `usage`, y use el mismo `llamar()` para conservar el manejo de errores, el
proxy y el registro de consumo. No se reescribe lo existente: `texto()` y `textoStream()` siguen
iguales.

**Qué modelos de OpenRouter deben soportarlo:**

| Etapa del agente | ¿Exige *tool calling*? | Observación |
|---|---|---|
| Bucle de razonamiento con herramientas (el agente decide qué consultar y en qué orden) | **Sí, obligatorio** | Hay que filtrar los modelos que lo anuncian |
| Clasificar/decidir con un JSON cerrado (como hace hoy `resolverDatos`) | No | Funciona con cualquier modelo que siga instrucciones; es el camino barato |
| Redactar el resultado (mensaje, resumen) | No | Se puede hacer con otro modelo, más barato o mejor escritor |
| Salida estructurada (propuestas) | Conviene `response_format`/JSON *schema* | No todos lo soportan; validar siempre en el servidor |

- OpenRouter publica por modelo la lista `supported_parameters` en `GET /models`. Un modelo
  sirve para agentes con herramientas **solo si incluye `tools`** (y conviene `tool_choice`).
  `modelosDisponibles()` hoy descarta ese dato: hay que **guardarlo** y mostrar un distintivo
  «soporta herramientas» en el selector, y **rechazar al guardar** un agente con herramientas
  si el modelo elegido no lo soporta.
- **Cómo se elige, sin casarse con nombres:** los modelos cambian cada pocas semanas y
  este informe no fija nombres de modelos concretos más allá del que el código ya usa por
  defecto. Criterio: nivel *económico* = modelo pequeño/rápido de la familia que ya use la
  instalación con `tools` anunciado y contexto suficiente; nivel *potente* = el modelo de
  texto principal. **Probar cada candidato con un caso de verificación** (una corrida de
  prueba contra datos de ejemplo) antes de asignarlo a un agente.
- **Contexto y costo:** las descripciones de herramientas y sus resultados cuentan como tokens
  de entrada en **cada paso del bucle**. Reglas: herramientas que devuelven **agregados y
  topes** (como hoy las consultas), no filas; `max_pasos` bajo; `max_tokens` por respuesta.
- **El proxy es el punto crítico:** hoy el proxy recibe un chat simple. Hay que verificar —en
  el repo `briela-superadmin`, que no está en el alcance de este análisis— que **reenvíe
  `tools`, `tool_choice`, `models`, `provider`, y devuelva `tool_calls` y `usage`** sin
  recortarlos, y que cuente los tokens de todos los pasos del bucle contra la suscripción.

### 3.7 Cómo se mide el costo por agente

1. `IaService::llamar()` lee `usage` de la respuesta (tokens de entrada/salida y, si el proveedor
   lo da, el costo) y escribe **una fila en `ia_consumo`** por llamada. Un solo punto, una sola
   escritura, **tolerante a fallos** (si el registro falla, la llamada a la IA no falla).
2. El ejecutor fija el contexto (`agente_id`, `ejecucion_id`) antes de llamar; el resto del
   sistema deja esos campos vacíos y se atribuye por `origen`.
3. `agente_ejecuciones` acumula tokens y costo de su corrida, para verlos sin sumar filas.
4. **Si el proxy no devuelve costo**, se calcula con una tabla de precios por modelo (que cambia)
   o se muestra solo en tokens. Decisión pendiente (pregunta 7).
5. **Topes (freno de seguridad), por agente y por instalación:**
   - `max_pasos` y `max_tokens_ejecucion` por corrida.
   - Tope diario por agente: al alcanzarlo, el agente se pausa y avisa.
   - **El cupo diario del proxy es compartido** con el asistente interactivo. Un agente
     nocturno no debe poder dejar al equipo sin asistente por la mañana. Regla: los agentes
     programados corren fuera de horario laboral o con una cuota reservada.
6. **Vista de consumo:** tabla por agente/día/modelo, con tendencia, en la misma pantalla de la
   oficina. Protegida con `equipo_ia.consumo`.

### 3.8 Riesgos transversales y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Un agente alucina una causa o una cifra | Las cifras las calcula el servidor; el modelo solo recibe agregados; el prompt prohíbe inferir causas; cada afirmación cita su fuente |
| Fuga de datos a la IA | Solo agregados cuando basta; sin datos de clientes en prompts que no los necesiten; el proxy ya intermedia. Revisar qué se manda en cada herramienta antes de habilitarla |
| *Prompt injection* desde datos de clientes | Datos marcados como datos, escritura solo por propuesta, catálogo cerrado por agente |
| Consumo desbocado | Topes por corrida, por día y por agente; interruptor global |
| Cron del cliente no configurado | Detección y aviso visible |
| Propuestas que nadie revisa | Vencen; contador visible en la campana; resumen semanal de pendientes |
| Pérdida de confianza por avisos de más | Un solo resumen por responsable (no una notificación por caso), antiduplicado, y medir la tasa de propuestas aprobadas por agente |
| Actualización en 10 instalaciones | Todo es tabla nueva y configuración. Los agentes de fábrica son **plantillas en el seeder/migración** (apagados por defecto) y el prompt es editable. Nada de parchear por cliente |
| Aislamiento entre sedes | Cada agente tiene `sede_id` opcional o alcance de su rol; el ejecutor fija el contexto de sede |

---

## PASO 4 — Interfaz visual: la «oficina de agentes»

### 4.1 Concepto

Una pantalla donde cada agente aparece como un **personaje** (avatar ilustrado, nombre, rol) con
un **estado visible de un vistazo**. Es decorativa solo en la superficie: debajo es un tablero
operativo que muestra qué está haciendo cada agente, qué espera de una persona y cuánto cuesta.

### 4.2 Estados

Los estados se **derivan** de las tablas, no se guardan por separado:

| Estado | Cómo se calcula | Aspecto |
|---|---|---|
| **Libre** | Activo, sin ejecución en curso y sin propuestas pendientes | Quieto, tono neutro/azul |
| **Trabajando** | Hay una `agente_ejecuciones` en `corriendo`; el texto muestra el último `agente_pasos` («Consultando entregas próximas…») | Animación suave, tono ámbar |
| **Terminado** | Última ejecución `terminada` hace menos de N horas, con su `resumen` | Tono verde, con la hora |
| **Necesita aprobación** | Hay `agente_propuestas` en `pendiente` | Tono naranja, con **contador** y llamada a la acción |
| **Con error** | Última ejecución `fallida` o tope alcanzado | Tono rojo, con el motivo |
| **Apagado / en pausa** | `activo = false` o tope diario | Gris apagado |

Colores: las familias del tema (`bg-pastel-*`, `border-borde-aviso-*`, `text-aviso-*`), **nunca
grises ni colores fijos**, como exige `CLAUDE.md`, para que se lea igual de día y de noche. Las
animaciones respetan `prefers-reduced-motion`.

### 4.3 Diseño y comportamiento

- **Móvil primero:** lista vertical de tarjetas-personaje, con el estado y el botón principal
  (Ejecutar / Revisar). **Escritorio:** cuadrícula tipo «oficina», con los agentes que necesitan
  aprobación al frente.
- **Al tocar un personaje** se abre un panel lateral con cuatro pestañas:
  1. **Actividad:** línea de tiempo de la última ejecución (`agente_pasos`), resumen y
     resultado.
  2. **Aprobaciones:** propuestas pendientes con *Aprobar / Editar / Rechazar* y motivo.
  3. **Consumo:** tokens y costo de hoy / 7 / 30 días.
  4. **Configuración** (solo `equipo_ia.gestionar`): rol, instrucciones, herramientas
     permitidas, modelo con distintivo de herramientas, frecuencia, topes y modo.
- **Acciones globales:** «Ejecutar ahora», pausar todo (interruptor de emergencia), ver cola.
- **Ubicación:** pestaña «Equipo interno» de la pantalla Agentes que ya existe (la de la
  captura de `/configuracion/agentes`), sin entrada de menú nueva; la ruta propia
  (`/configuracion/agentes/oficina`) solo sirve para enlazarla.
- Se integra con lo que ya hay: las propuestas aprobadas pueden
  abrir el documento afectado (la OP, la cotización) con el enlace existente.

### 4.4 De dónde lee la información

- **Pantalla:** `resources/js/Pages/Agentes/Oficina.vue` (nueva), cargada con el `import.meta.glob`
  sin `eager` que ya usa Inertia, así que **no engorda el bundle de las demás**. URLs como
  strings, sin Ziggy.
- **Datos iniciales:** el controlador entrega por Inertia agentes + última ejecución + conteo de
  propuestas (un `withCount`, no N consultas).
- **Actualización en vivo sin websockets**, copiando el patrón de la campana:
  `GET /api/agentes/oficina/pulso` devuelve una **firma** (por ejemplo, el mayor `updated_at` y
  los conteos) y solo trae la lista completa si la firma cambió; sondeo cada 5–15 s **solo
  mientras la pantalla está abierta**, con `setInterval` cuyo `clearInterval` está en
  `onUnmounted`.
- **Reglas de velocidad de `CLAUDE.md`:**
  - **Nada en `AppLayout` que pida datos al montar.** El contador de «propuestas pendientes» de
    la campana se agrega al **pulso existente** (`/api/avisos/pulso` / `useAvisos.js`), no con
    una petición nueva ni un temporizador nuevo.
  - Si el panel de detalle es pesado, se monta al abrirse (`v-if`).
  - Todo `addEventListener` con función con nombre y su `removeEventListener`.
  - Nada nuevo en `HandleInertiaRequests::share()` salvo como `fn () =>`.
- **Lo que no hace falta en la primera versión:** animaciones elaboradas, arrastrar personajes,
  avatares generados con IA (la regla de oro de `ia.md` aplica: lo decorativo sí, pero no se
  gasta dinero en eso). Basta un conjunto de ilustraciones SVG o emoji accesibles.
- **Accesibilidad:** el estado nunca se comunica solo con color; lleva texto e icono.

---

## PASO 5 — Plan por fases

### Fase 0 — Cimientos (sin agentes visibles)

Nada de esto cambia el comportamiento actual; es la infraestructura que todo lo demás necesita.

1. **`ia_consumo`** y registro en `IaService::llamar()` (beneficia ya al asistente, a las fichas
   y a los agentes públicos).
2. **`IaService::chat()` con herramientas** y guardado de `supported_parameters`.
3. **Verificar el proxy** (reenvío de `tools`, `models`, `usage`).
4. **Worker por cron** y comando de despacho.
5. **Usuario de servicio** y su rol mínimo.
6. Prueba de humo con un agente de juguete que solo cuente OP.

### Fase 1 — Tres agentes de alto valor, bajo riesgo (solo lectura y propuesta)

| Agente | Modo | Qué entrega | Por qué en Fase 1 |
|---|---|---|---|
| **Analista del día** | Solo lectura | Informe con `analisis`, aviso «tu resumen está listo» | Casi no requiere herramientas nuevas; valida el bucle de punta a punta |
| **Vigía de producción y entregas** | Solo lectura | Aviso agrupado por responsable con causa probable y siguiente paso | Alto valor; solo lee |
| **Seguimiento comercial** | **Propone** (no envía) | Borradores y tareas en la cola de aprobación | Inaugura el flujo de aprobación con el menor riesgo posible |

Criterios de salida de la Fase 1:

- Una persona puede aprobar o rechazar una propuesta y queda auditado.
- El costo por agente y por día se ve en pantalla y coincide con el del proxy (con margen
  explicado).
- Medidas: **tasa de aprobación de propuestas**, costo mensual por agente, tiempo de corrida,
  tasa de corridas fallidas.
- Ninguna regresión en el tiempo de navegación (la regla de velocidad se verifica midiendo).
- Funciona en el servidor de pruebas (`sistema.briela.app`) durante al menos una semana antes
  de plantearse otra instalación.

### Fase 2 — Más agentes y primeras escrituras con aprobación

- Cobranza, Auditor de datos, Copiloto de bandeja.
- Propuestas que, al aprobarse, ejecutan transacciones existentes (`TransaccionesIaService`).
- Informe de consumo completo, topes diarios y modelo por agente con respaldo automático.
- Editor de instrucciones por agente en la interfaz.

### Fase 3 — Oficina completa y agentes complejos

- La interfaz visual completa de la sección anterior, con configuración y consumo.
- Abastecimiento y Analista de calidad.
- Un **agente coordinador** que reparta trabajo entre agentes (solo si las fases anteriores
  muestran que hace falta; es la parte más cara y más difícil de depurar).

### Fase 4 — Producto

- Plantillas de agentes publicables entre instalaciones (paquete de datos, como los países).
- Reglas de negocio por cliente como **configuración**, nunca como código.
- Evaluar cobro por consumo de agentes en el modelo de licencias.

### Archivos y módulos que tocaría la Fase 0 + Fase 1

**Nuevos**

| Ruta | Contenido |
|---|---|
| `database/migrations/…_create_agentes_internos.php` y las de ejecuciones, pasos, propuestas e `ia_consumo` | Solo tablas nuevas, hacia adelante |
| `app/Models/AgenteInterno.php`, `AgenteEjecucion.php`, `AgentePaso.php`, `AgentePropuesta.php`, `IaConsumo.php` | Modelos |
| `app/Services/Agentes/EjecutorAgente.php` | Bucle modelo ⇄ herramientas, topes, bitácora |
| `app/Services/Agentes/CatalogoHerramientas.php` | Registro de herramientas por agente (envuelve `ConsultasDatosService`) |
| `app/Services/Agentes/Herramientas/*` | Herramientas nuevas de lectura (detalle de OP, pasos atrasados, historial de cliente…) y `proponer_*` |
| `app/Services/Agentes/AgentesDeFabrica.php` | Plantillas de los tres agentes, apagadas por defecto |
| `app/Jobs/EjecutarAgenteJob.php` | Job de una corrida |
| `app/Console/Commands/DespacharAgentes.php`, `LimpiarBitacoraAgentes.php` | Despacho y retención |
| `app/Http/Controllers/OficinaAgentesController.php` | Pantalla, pulso, ejecutar, aprobar, rechazar |
| `resources/js/Pages/Agentes/Oficina.vue` y `resources/js/Components/…` (tarjeta de personaje, panel de actividad) | Interfaz (en Fase 1, versión sencilla) |
| `docs/manual/agentes-internos.md` | Manual del módulo y entrada en `docs/manual/00-indice.md` |
| `tests/Feature/…`, `tests/Unit/…` | Ejecutor con IA simulada, topes, aprobación, permisos, idempotencia |

**Existentes que se modifican (cambios aditivos)**

| Archivo | Cambio |
|---|---|
| `app/Services/IA/IaService.php` | Método `chat()` con herramientas; lectura de `usage`; escritura en `ia_consumo`; guardar `supported_parameters`; contexto de agente |
| `app/Support/Permisos.php` | Grupo de permisos del equipo de IA; asignación a los roles base |
| `app/Support/Modulos.php` | Entrada del módulo (si se decide que es apagable) con sus permisos, rutas y dependencias |
| `app/Services/NotificacionService.php` | Tres tipos nuevos y su módulo |
| `routes/web.php` | Rutas con `permiso:` |
| `routes/console.php` | Worker por cron y despacho de agentes |
| `resources/js/Pages/Agentes/Index.vue` | Dos pestañas: la actual y «Equipo interno» (sin tocar el menú) |
| `resources/js/composables/useAvisos.js` y el endpoint del pulso | Agregar el contador de propuestas **al pulso existente** |
| `app/Http/Controllers/IaController.php` y la pantalla de Configuración de IA | Mostrar distintivo «soporta herramientas» en el selector |
| `docs/manual/ia.md` y `docs/MANUAL-BRIELA.md` | Referencia cruzada |

**Se vigila, pero no se modifica en principio:** `HandleInertiaRequests::share()`, los modelos de
negocio (`Op`, `Cotizacion`…), `CierrePasoService`, `RemisionService`,
`Op::sincronizarEstado()`. Un agente nunca toca estados por fuera de ellos.

**Revisar que no rompa:** cualquier lugar que recorra `User` para repartir trabajo (reparto de
leads, listas de vendedores, comisiones, RRHH) debe excluir las cuentas de servicio.

### Pruebas (cuando se construya)

- Ejecutor con **IA simulada** (`Http::fake`), para no gastar tokens ni depender de la red:
  bucle, tope de pasos, tope de tokens, error 402, respaldo de modelo.
- **Permisos:** un agente con rol limitado no ve cartera; un usuario sin `equipo_ia.aprobar` no
  aprueba; aprobar exige además el permiso de la acción real.
- **Idempotencia:** dos pasadas del cron no duplican corridas ni propuestas.
- **Seguridad:** una herramienta de lectura jamás escribe; datos con instrucciones maliciosas no
  alteran el catálogo.
- Las pruebas corren contra `briela_test`, nunca contra `briela`.

---

## Preguntas y decisiones que necesito que tomes antes de empezar

### Producto y alcance

1. **Nombre de la sección nueva** dentro del módulo «Agentes» (ya decidido que va ahí).
   Propuesta: pestañas **«Atención a clientes»** y **«Equipo interno»**, y **«Oficina»** para la
   vista de personajes.
2. **¿Son para todos los clientes o solo para quienes los contraten?** Afecta si se mide el
   consumo para cobrarlo, si va como módulo apagable y cómo se comunica.
3. **Los tres de la Fase 1.** ¿Te sirven Analista del día, Vigía de producción y Seguimiento
   comercial, o prefieres cambiar alguno (por ejemplo, Cobranza en lugar de Seguimiento si la
   cartera duele más)?
4. **¿Núcleo o módulo?** `Modulos.php` hoy dice que el asistente y los agentes «van siempre». Como
   vive dentro de Agentes, ¿la pestaña «Equipo interno» se puede apagar por instalación?
   (Recomendado: sí, para que quien no la quiera no pague tokens por error.)

### Infraestructura y costos

5. **Proxy del superadmin.** Necesito que alguien confirme en `briela-superadmin` que reenvía
   `tools`/`tool_choice`/`models` y devuelve `tool_calls` y `usage`, y que cuenta los tokens de
   cada paso. Si no, la Fase 0 incluye cambios en ese repo, que es aparte. ¿Lo reviso yo en una
   sesión con acceso a ese repo?
6. **Fallback de modelo:** ¿lo hace OpenRouter (parámetro `models`, depende del proxy) o lo
   implementamos en `IaService` con un segundo intento?
7. **Costo:** ¿el proxy puede devolver el costo en dólares por llamada? Si no, ¿se muestra solo
   en tokens o se mantiene una tabla de precios por modelo?
8. **Cupo diario compartido.** ¿Los agentes consumen del mismo límite diario del asistente, o
   tendrán una cuota propia en el plan? Esto define si un agente puede dejar sin asistente a la
   gente.
9. **Presupuesto objetivo** por agente y por instalación (por ejemplo, «máximo X USD/mes») para
   fijar los topes por defecto.
10. **Cron en Hostinger.** ¿Confirmas que todas las instalaciones tienen el cron de cada
    minuto activo? (Sin él no corre nada; la propuesta es avisarlo en pantalla.)

### Seguridad y reglas de negocio

11. **Datos que pueden salir a la IA.** ¿Hay datos que no deban llegar a un modelo bajo ninguna
    circunstancia (costos, márgenes, datos personales de operarios, retenciones)? Esto define
    qué herramientas se habilitan.
12. **Analista de calidad y personas:** ¿se permite señalar operarios, o solo ensambles y pasos?
    (Recomendado: solo ensambles y pasos.)
13. **Aprobadores.** ¿Quién aprueba cada tipo de propuesta (el responsable del documento, un
    rol específico, cualquiera con el permiso)? ¿Cuánto tarda en vencer una propuesta?
14. **Envío.** En Fase 2, ¿se permite que una propuesta aprobada **se envíe sola** por WhatsApp o
    correo, o el humano siempre debe darle «enviar» desde la bandeja? (Recomendado: lo segundo
    hasta medir la calidad.)
15. **Idioma y tono.** ¿Los textos redactados por agentes deben usar siempre el perfil de marca
    (como las fichas) y el español sin voseo (regla de `CLAUDE.md`)? (Propuesta: sí, fijo en el
    sistema, no editable.)

### Interfaz

16. **Estilo de los personajes.** ¿Ilustrados (SVG propios), emojis, o iniciales con color?
    ¿Y debe ser una pantalla aparte o un panel dentro del tablero principal?
17. **¿Quién debe ver la oficina?** ¿Solo administración, o también los jefes de área para ver y
    aprobar lo de su área?

### Proceso

18. **Cierre de tarea.** `CLAUDE.md` exige actualizar el grafo, hacer commit/push y desplegar a
    `main` al terminar cada cambio. Para este informe lo omití por tu instrucción. ¿Quieres que
    este documento se comprometa y suba cuando lo apruebes, o que se quede solo en borrador
    hasta decidir las preguntas anteriores?
19. **Antes de escribir código, ¿lo desarrollamos en una rama aparte** (por ejemplo, la actual
    de trabajo) y se prueba en `sistema.briela.app` antes de llevarlo a `main`, dado que el push
    a `main` despliega automáticamente?

---

## Anexo — Lo verificado en el código

Para que se pueda comprobar cada afirmación del diagnóstico:

| Afirmación | Dónde mirarlo |
|---|---|
| IA con un solo punto de salida y proxy | `app/Services/IA/IaService.php` (cabecera, `porProxy()`, `url()`, `cabeceras()`) |
| Modelo global, dos niveles | `IaService::modeloTexto()`, `modeloRapido()`, `ajuste()` |
| No hay *tool calling* ni lectura de `usage` | Búsqueda de `tools`, `tool_calls`, `usage` en `IaService.php`: sin resultados relevantes |
| El asistente decide con JSON en el texto | `app/Http/Controllers/AsistenteController.php`, `resolverDatos()` |
| 16 consultas de solo lectura con permiso | `ConsultasDatosService::catalogo()` |
| Escritura solo con propuesta y confirmación | `TransaccionesIaService` (`proponer`/`ejecutar`), `POST /api/asistente/confirmar` |
| Agentes de atención ya existen | `agentes_ia`, `AgenteIa`, `AgenteConversacionService`, `docs/manual/agentes.md`, permiso `agentes.*` |
| Cola configurada pero sin uso | `config/queue.php` (`database`), migración `0001_01_01_000002_create_jobs_table.php`; ningún `ShouldQueue` ni `dispatch(` en `app/` ni `routes/` |
| Cron cada minuto como único mecanismo | `docs/instalar-en-hostinger.md` §7, `routes/console.php` |
| Todo depende de `auth()->user()` | `ConsultasDatosService::disponibles()`, `ContextoSede` |
| Notificaciones con catálogo por módulo | `NotificacionService::MODULO_DE_TIPO`, `catalogo()` |
| Módulos apagables | `app/Support/Modulos.php` |
| Permisos con grupo `agentes` | `app/Support/Permisos.php` |
