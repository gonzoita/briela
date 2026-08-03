# Notificaciones internas (la campanita)

Centro de avisos para el equipo interno. Aparece como una **campanita** 🔔 en
la barra superior (arriba a la derecha en computador, en el encabezado en
celular), con un contador rojo de avisos sin leer. Cada evento importante le
llega solo a la persona que le corresponde, sin tener que entrar a buscar.

Todos los avisos son **internos** (dentro de la app). La antigua integración
con GoHighLevel se eliminó por completo el 24 de julio de 2026 y no se va a
usar.

## Cómo funciona

- La campanita muestra las últimas notificaciones; las no leídas quedan
  resaltadas.
- Al hacer clic en una, se marca leída y te lleva directo a lo que la
  originó (la OP, el trabajo, etc.).
- Botón "Marcar todas leídas".
- Se refresca sola cada minuto mientras la app está abierta.

## Avisos activos — Fase 1 (Producción) *(nuevo, 24 jul 2026)*

| Aviso | Le llega a | Cuándo |
|-------|-----------|--------|
| **Nueva OP** | Admin, jefe de producción | Al generar una OP desde una cotización |
| **Material faltante** | Admin, jefe de producción | Al generar una OP que nace sin stock suficiente |
| **OP lista para calidad** | Admin, jefe de producción | Cuando la producción de la OP se completa sola |
| **OP lista para despachar** | Admin, jefe de producción | Al aprobar el control de calidad |
| **Trabajo asignado** | El colaborador asignado | Al programarle un paso en el Programador |
| **Entrega próxima** | Admin, jefe de producción | Revisión diaria (6 a.m.): OPs con entrega cercana sin despachar |

Los primeros cinco son "en el momento" (el evento los dispara al instante).
El de **entrega próxima** corre con la tarea programada diaria — necesita el
cron del servidor configurado (el mismo de las cotizaciones vencidas, ver
[Cotizaciones](./cotizaciones.md)).

## Avisos activos — Fase 3 (resto de módulos) *(nuevo, 24 jul 2026)*

| Aviso | Le llega a | Cuándo |
|-------|-----------|--------|
| **Cotización aprobada** | Vendedor responsable | El cliente aprueba por el link público |
| **Cotización rechazada** | Vendedor responsable | El cliente rechaza por el link público |
| **Lead nuevo desde la web** | Vendedor asignado (o admin) | Llega un formulario público |
| **Solicitud de compra** | Admin, jefe de producción | Se crea una solicitud de compra |
| **Mercancía recibida** | Admin, jefe de producción | Se registra la recepción de una orden de compra |
| **Evaluación por calificar** | Admin, jefe de producción | Un intento con preguntas abiertas queda pendiente |
| **Certificado emitido** | El colaborador | Aprueba un curso y se genera su certificado |
| **Documento por firmar** | El colaborador | Se le registra una sanción disciplinaria |
| **Bono calculado** | El colaborador | Se le calcula el bono del mes |
| **Curso obligatorio por vencer** | El colaborador | Revisión diaria (6:05 a.m.) |
| **Cotización sin respuesta** | Vendedor responsable | Revisión diaria (6:10): cotizaciones enviadas sin respuesta pasado el umbral |
| **Insumo bajo stock mínimo** | Admin, jefe de producción | Revisión diaria (6:10): resumen de insumos por debajo del mínimo |
| **Cuota/saldo vencido** | Administrador | Revisión diaria (6:10): cuotas de OP vencidas sin pagar |

## Configuración *(panel visual desde 24 jul 2026)*

En **Ajustes → pestaña "Notificaciones"** hay un panel (solo administrador)
con todos los avisos agrupados por módulo y **dos switches** por aviso:

- **Campanita**: el aviso dentro de la app (activado por defecto).
- **Email**: además manda el aviso por correo al usuario (apagado por
  defecto). Requiere tener el SMTP configurado en la pestaña "Email"; si el
  correo falla, el aviso interno igual se guarda y nada se rompe.

## Estado

Sistema completo: campanita + canal email opcional + panel de configuración
+ recordatorios diarios, cubriendo todos los módulos. No queda nada
pendiente en notificaciones.
