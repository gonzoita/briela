# Manual de uso — SGI Interfrigo

Este manual se va construyendo módulo por módulo, a medida que se revisa y mejora
cada parte del sistema. No es un manual técnico para desarrolladores (para eso
está `CLAUDE.md`), sino una guía de uso para quienes operan el sistema día a día.

## Módulos documentados

- [Marca — color, favicon y título de la pestaña](./marca.md)
- [Buscador global y encadenamiento entre módulos](./buscador-global.md)
- [Dashboard](./dashboard.md)
- [CRM — Pipeline de leads](./crm-pipeline.md)
- [Identificación de clientes (NIT, dígito de verificación y RUES)](./identificacion-clientes.md)
- [Importar clientes desde CSV](./importar-clientes.md)
- [Cotizaciones](./cotizaciones.md)
- [Cotización aprobada → OP (la venta)](./cotizacion-a-op.md)
- [Plantillas de Ensamble (el cotizador que arma productos)](./plantillas-ensamble.md)
- [Producción — Orden de Producción, control de calidad y código legacy](./produccion-op.md)
- [Trabajos y pasos de trabajo](./trabajos-pasos.md)
- [Auditoría — bitácora de actividad](./auditoria.md)
- [Checklist de verificación (QA) julio 2026](./verificacion-qa.md)
- [Productos e inventario](./productos-inventario.md)
- [Compras, inventario y faltantes](./compras-inventario.md)
- [Logística y despachos (remisiones)](./logistica.md)
- [Informes](./informes.md)
- [Capacitación (cursos, evaluaciones y certificados)](./capacitacion.md)
- [Recursos Humanos (colaboradores, disciplina, bonos, gamificación)](./rrhh.md)
- [Notificaciones internas (la campanita)](./notificaciones.md)
- [Redes Sociales — programador de publicaciones](./redes-sociales.md)
- [Sedes y numeración de documentos](./sedes.md)
- [Roles y permisos configurables](./roles-permisos.md)
- [Integración de IA (textos, imágenes y el asistente)](./ia.md)
- [Copias de seguridad (backups)](./backups.md)
- [Deploy automático (GitHub Actions)](./deploy-automatico.md)
- [Montar el proyecto en otra computadora](./montar-en-otra-pc.md)

## Pendientes por documentar

Ninguno por ahora — todos los módulos del sistema están documentados.

## Principio general del sistema

El objetivo de fondo del SGI es que la operación sea lo más automática posible:
que cada acción relevante (mover un lead, aprobar una cotización, completar un
paso de producción) dispare automáticamente el siguiente paso del proceso, sin
que un usuario tenga que acordarse de hacerlo manualmente. Cada módulo nuevo
que se revisa se evalúa también bajo ese criterio.
