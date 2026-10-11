# CRM — Pipeline de leads

Ruta: `/crm`

## Qué es

Un tablero tipo Kanban donde cada columna es una **etapa** (`crm_etapas`) y cada
tarjeta es un **lead** (`crm_leads`): una oportunidad de negocio, con o sin
cliente asociado todavía.

## Uso básico

- **Crear un lead**: botón "+" en cualquier columna. Se le puede asignar
  responsable, fuente, datos de contacto.
- **Mover un lead**: arrastrar la tarjeta entre columnas. Esto cambia su etapa
  y, si la etapa está marcada como "ganado" o "perdido", también cierra el
  lead automáticamente (`estado = ganado/perdido`, se guarda `fecha_cierre`).
- **Ver detalle**: clic en la tarjeta abre notas, tareas y el historial de
  actividad del lead (quién hizo qué y cuándo, específico de ese lead).
- **Convertir a cliente**: desde el detalle del lead, botón "Convertir a
  cliente". Crea un registro en `Clientes` y lo vincula al lead.

## Automatizaciones activas

1. **Etapa con `accion_automatica = cotizacion`**: cuando un lead entra a una
   etapa marcada así (por ejemplo "Cliente Nuevo"), el sistema:
   - Si el lead **ya tiene cliente asociado**, genera automáticamente una
     **cotización en borrador** vinculada al lead y al cliente, y ofrece
     abrirla de inmediato.
   - Si el lead **todavía no tiene cliente**, avisa que hace falta convertirlo
     primero (la cotización se genera automáticamente en cuanto se convierte).
2. **Convertir a cliente**: si la conversión también mueve el lead a una etapa
   de cotización, la cotización en borrador se genera en el mismo paso — no
   hace falta mover la tarjeta después.
3. **Vínculo Lead ↔ Cotización**: toda cotización generada desde un lead queda
   registrada con `lead_id`, visible tanto en el detalle del lead (actividad)
   como en el encabezado de la cotización ("Origen: lead...").

## La cotización mueve el lead *(14 oct 2026)*

Lo que pasa con la cotización de un lead lo mueve solo (`CrmLead::alCambiarCotizacion()`, desde
`CotizacionObserver`, así que vale por cualquier camino: la pantalla, el enlace del cliente, la guía
o «Generar OP»):

- **Aprobada** → el lead pasa a **ganado** y a la etapa marcada como ganado.
- **Rechazada** o **vencida** → **perdido**, con el motivo (el que escribió el cliente, o «venció sin
  respuesta»), pero solo si el lead no tiene otra cotización viva.
- Un lead ganado no se vuelve perdido porque se venza otra cotización suya.

La cotización automática usa las **condiciones comerciales que configuró la empresa** (antes llevaba
un texto fijo que decía «pesos colombianos» en cualquier país).

## Convertir a cliente sin duplicar *(14 oct 2026)*

Pide nombre, tipo y, opcional, documento. Si ya existe un cliente con ese **documento, correo o
celular**, se vincula en vez de crear otro y se avisa. Una empresa nueva nace con la persona del lead
como contacto principal.

## Las puertas de entrada

Todo lead entra por `LeadEntranteService`, que reconoce a quien ya estaba (correo, o los últimos 10
dígitos del teléfono) y registra cada canal por el que llegó: formularios públicos (`/f/{slug}`, con
reparto fijo, rotativo o ponderado entre vendedores), plugin de WordPress, WhatsApp, Instagram y
Messenger, el **agente de IA** (las novedades que reporta un cliente, con su propio canal), la guía y
la carga manual. Si la persona vuelve a escribir por un formulario, el aviso dice «volvió a
escribir» en vez de «nuevo lead».

Abrir, editar, mover o borrar un lead de **otra sede** por enlace directo devuelve «no encontrado».

## Qué queda registrado

Cada creación, movimiento de etapa, cierre (ganado/perdido) y conversión a
cliente se guarda en el historial de actividad propio del lead (pestaña
"Actividad" en el detalle), y además en la **bitácora general del sistema**
(ver [Auditoría](./auditoria.md)) para creación/edición/eliminación del lead
como registro.
