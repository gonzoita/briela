# Automatizaciones

«Cuando pase esto, haz aquello». Está en **Sistema → Automatizaciones** y **viene apagado**: se enciende en
Configuración → Módulos. Permisos: `automatizaciones.ver`, `.gestionar` (crear y editar) y `.ejecutar` (lanzar a mano).

## Cómo arranca

- **Un evento** de cualquier módulo encendido:

  | Módulo | Eventos |
  |---|---|
  | Cotizaciones | cambia el estado (con el estado anterior) |
  | CRM | lead nuevo, cambia de etapa (con la etapa anterior), se gana o se pierde |
  | Clientes | cliente nuevo |
  | Producción | cambia el estado de la OP; una unidad termina en planta; calidad firma una unidad |
  | Remisiones | cambia el estado |
  | Cartera | se registra un pago; se verifica un pago |
  | Inventario | un producto **cruza** hacia abajo su stock mínimo (no avisa cada vez que se mueve algo ya bajo) |
  | Compras | cambia el estado de una solicitud o de una orden de compra |
  | Mantenimiento | cambia el estado |
  | RRHH | un colaborador pide un permiso |
  | Capacitación | avanza o termina una inscripción |
  | Bandeja | entra un mensaje (WhatsApp, Instagram o Facebook) |
  | Equipo de IA | un agente deja una propuesta |

- **Un horario**: todos los días, ciertos días, una vez al mes o cada pocas horas en un rango. Arranca dentro de
  las 3 horas siguientes a la hora fijada, cuando el cron lo toma.
- **Un horario que revisa documentos** («barrido»): «cada mañana, para cada cotización abierta…». Se puede barrer
  cotizaciones, leads, tareas del CRM, clientes, órdenes de producción, unidades esperando calidad, remisiones,
  pagos sin verificar, productos bajo el mínimo, solicitudes y órdenes de compra, mantenimientos, permisos e
  inscripciones. Crea una corrida por cada documento que **pase las condiciones del principio**, y esas
  condiciones se miran antes de crear nada: mil cotizaciones no dejan mil corridas vacías. Mira hasta 2.000
  documentos por vez y respeta el tope por hora.
- **A mano**: alguien pulsa «Ejecutar».

«No repetir con el mismo documento en N días» evita que la cotización que se reenvía tres veces reciba tres seguimientos.

## Los pasos

- **Condición**: compara un dato del documento (es igual, mayor, contiene, es alguno de una lista, está vacío…).
  Siempre hay además la hora, el día de la semana y lo que dejaron los pasos de IA. Si no se cumple, se detiene.
- **Espera**: minutos, horas o días (hasta 90). No deja nada corriendo; al volver **lee otra vez el documento**,
  así que decide con los datos de ese día.
- **Pedir aprobación**: la corrida se detiene y se le pregunta a una persona (el responsable, personas elegidas,
  quienes tengan un permiso o el autor). El aviso lleva a una pantalla, apta para celular, con los datos del
  documento y dos botones. Decide el primero que conteste. Si dice que no o **nadie contesta a tiempo**, se
  detiene: lo que nadie aprobó no se hace. Funciona aunque esa persona no tenga acceso al módulo.
- **Acción**.

## Las acciones

| Acción | Qué hace |
|---|---|
| Avisar a alguien | Aviso en la campana con enlace al documento |
| Comentario o tarea en el documento | En OP, cotización, cliente u orden de compra; como tarea, le queda pendiente al responsable |
| Crear una tarea en el lead | Del lead, de la cotización o de la conversación |
| Nota de seguimiento en la cotización | |
| Mover el lead de etapa | Solo a etapas abiertas: una automatización no gana ni pierde leads |
| Asignar el lead / la conversación | Reparte al que tenga menos abiertos; no le quita nada a quien ya lo tiene |
| Solicitud de compra del faltante | En borrador; si el producto ya tiene una solicitud abierta, no crea otra |
| Redactar con IA | El texto queda en `{{texto_ia}}` para los pasos siguientes |
| Clasificar con IA | Elige una de tus categorías; queda en `{{categoria_ia}}` y una condición decide con ella |
| Poner a trabajar a un agente | Con una tarea sobre este documento; sus propuestas esperan aprobación |
| Webhook | Manda los datos en JSON a Zapier, Make, n8n o un sistema propio, firmado si se da una clave (`X-Briela-Firma: sha256=…`) |

**Nunca**: escribir a clientes ni publicar en redes, registrar pagos, cambiar el estado de una OP o de una
remisión, despachar, borrar ni cambiar precios. Cuando hace falta algo de eso, el camino es una aprobación
seguida de un aviso a quien lo hace, o un agente del Equipo de IA.

## Los textos

`{{campo}}` con cualquier dato del documento, `{{enlace}}` (la dirección completa del documento), `{{hoy}}`,
`{{texto_ia}}`, `{{categoria_ia}}`, y formatos: `{{total|dinero}}`, `{{cliente|mayusculas}}`.

## Reglas de seguridad

- **Los permisos de quien la guardó son su techo**, al guardar y en cada corrida: tiene que poder hacer cada
  acción **y ver el tipo de documento** (no se barren órdenes de compra sin `ordenes.ver`). Si pierde el
  permiso, la corrida falla en vez de seguir actuando. Si otra persona la edita o la enciende, pasa a ser suya.
- **Datos de colaboradores** (permisos, capacitación): sirven para avisar, pero ningún paso los manda a la IA ni a
  un webhook. A la IA y a los webhooks tampoco llegan costos ni márgenes: no están entre los datos.
- **Webhook**: solo `https`, sin usuario ni clave en la dirección, sin puertos raros, sin seguir redirecciones,
  y se rechaza cualquier dirección que resuelva a la red interna.
- La IA gasta del **cupo del Equipo de IA** y necesita ese módulo encendido.
- Nace **apagada**. «Probar» la corre con datos de ejemplo (editables) o con un documento real por su número, sin
  escribir ni avisar; las aprobaciones se dan por aprobadas y la IA no se consulta.
- Cada corrida guarda su propia copia del grafo: editar la automatización no cambia lo que ya está esperando.
- Tope por hora (60 por omisión; lo que sobra se descarta y se avisa una vez), tope de 3 generaciones (una
  automatización que dispara otra) y detección de ciclos.
- Cinco fallas seguidas la pausan sola y avisan a quien la creó.
- Pausar o eliminar cancela lo que estaba en cola o esperando, aprobaciones incluidas.

## Plantillas de fábrica

Agrupadas por área, y solo las de módulos encendidos: cotización sin respuesta, cotizaciones frías al CRM,
reparto de leads nuevos, lead ganado, clientes dormidos (con IA), mensaje sin responder en 30 minutos, reclamos
detectados por IA, orden a calidad, órdenes atrasadas, unidades esperando calidad, remisión entregada, stock bajo
con aprobación, compras atrasadas, mantenimiento de mañana, permiso pedido, curso por vencer y un agente cada
mañana. La lista dice qué falta completar en cada una antes de usarla.

## Para quien programa

`App\Services\Automatizaciones\`:

- `EntidadesAutomatizacion`: **los documentos**. Módulo, permiso para verlos, si son sensibles, sus campos, su
  ejemplo, cómo se arma su contexto y su consulta de barrido. Un documento nuevo se agrega aquí.
- `CatalogoAutomatizaciones`: eventos (apuntan a un documento) y acciones. Un evento o acción nueva se agrega aquí.
- `EventosAutomatizacion`: los oyentes de modelo. Escuchan `created` y `updated` por separado —**nunca** `saved`
  con `wasRecentlyCreated`, que sigue en `true` en la misma instancia— y **nunca devuelven `false`**, que en
  Eloquent corta a los demás observers. El contexto se arma solo si alguien escucha.
- `GrafoAutomatizacion` (validación), `MotorAutomatizaciones` (recorrido, esperas, aprobaciones),
  `LanzadorAutomatizaciones` (único sitio donde se crea una corrida; horarios y barridos), `AccionesAutomatizacion`,
  `Webhook`, `PlantillasAutomatizacion`.

El cron corre `automatizaciones:avanzar` cada minuto: rescata corridas atascadas, lanza horarios y barridos, y
retoma esperas y aprobaciones vencidas.
