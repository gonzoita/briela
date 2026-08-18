# Manual de uso

Guía de uso para quienes operan Briela día a día. No es un manual técnico: las reglas del
código están en [`CLAUDE.md`](../../CLAUDE.md) y la arquitectura en
[`docs/BRIELA-PLAN.md`](../BRIELA-PLAN.md).

**Para leer el sistema completo de una sola vez**, el recorrido de punta a punta con todos los
módulos está en [`docs/MANUAL-BRIELA.md`](../MANUAL-BRIELA.md). Este índice es el detalle,
módulo por módulo.

## Comercial

- [CRM — Pipeline de leads](./crm-pipeline.md)
- [Identificación de clientes (NIT, dígito de verificación y RUES)](./identificacion-clientes.md)
- [Importar clientes desde CSV](./importar-clientes.md)
- [Segmentación de clientes y su efecto en el precio](./segmentacion-y-precios.md)
- [Cotizaciones](./cotizaciones.md)
- [Cotización aprobada → OP (la venta)](./cotizacion-a-op.md)
- [Comisiones del vendedor](./comisiones.md)

## El cotizador de ensambles

- [Plantillas de Ensamble (el cotizador que arma productos)](./plantillas-ensamble.md)

## Producción

- [Producción — Orden de Producción y control de calidad](./produccion-op.md)
- [Trabajos y pasos de trabajo](./trabajos-pasos.md)
- [Alistamiento](./alistamiento.md) — lo que el almacenista deja listo para despachar
- [Programador de planta y pantalla de planta](./programador-planta.md)

## Inventario y compras

- [Productos e inventario](./productos-inventario.md)
- [Compras, inventario y faltantes](./compras-inventario.md)
- [Recetas de corte](./recetas-corte.md)
- [Unidades de medida](./unidades-medida.md)

## Logística y financiero

- [Logística y despachos (remisiones)](./logistica.md)
- [Cartera — lo que deben los clientes](./cartera.md)

## Personas

- [Recursos Humanos (colaboradores, disciplina, bonos, gamificación)](./rrhh.md)
- [Capacitación (cursos, evaluaciones y certificados)](./capacitacion.md)
- [Roles y permisos configurables](./roles-permisos.md)

## Inteligencia artificial

- [Integración de IA (fichas, recomendación, asistente y voz)](./ia.md)

## Transversal

- [Dashboard](./dashboard.md)
- [Buscador global y encadenamiento entre módulos](./buscador-global.md)
- [Notificaciones internas (la campanita)](./notificaciones.md)
- [Hilos internos — comentar sobre un documento](./hilos-internos.md)
- [Chat interno — mensajes directos y grupos](./chat-interno.md)
- [Informes](./informes.md)
- [Auditoría — bitácora de actividad](./auditoria.md)
- [Sedes y numeración de documentos](./sedes.md)
- [Marca — color, favicon y título de la pestaña](./marca.md)
- [Plantillas PDF — el diseño de cada documento](./plantillas-pdf.md)

## Hacia afuera

- [Catálogo público — fichas para compartir](./catalogo-publico.md)
- [Publicar productos y ensambles en el sitio web (plugin Briela Connect)](./publicar-en-la-web.md)
- [Redes Sociales — programador de publicaciones](./redes-sociales.md)
- [WhatsApp — conexión y números](./whatsapp.md)
- [Conexiones con servicios externos — índice de todas](./conexiones.md)

## Instalación y mantenimiento del sistema

- [Despliegue — el servidor jala los cambios](./deploy-automatico.md)
- [Copias de seguridad (backups)](./backups.md)
- [Operación — SSH, montaje en otro computador, git, grafo y chats](../OPERACION.md)
- [Mantenimiento de equipos](./mantenimiento.md)
- [Checklist de verificación (QA)](./verificacion-qa.md)

## Principio general del sistema

El objetivo de fondo de Briela es que la operación sea lo más automática posible: que cada
acción relevante —mover un lead, aprobar una cotización, cerrar el último paso de un trabajo—
dispare sola el siguiente paso del proceso, sin que nadie tenga que acordarse de hacerlo.

Cada módulo que se revisa se evalúa también bajo ese criterio: ¿qué debería pasar solo aquí?
