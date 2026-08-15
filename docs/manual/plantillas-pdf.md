# Plantillas PDF — el diseño de cada documento

Rutas: `/configuracion/plantillas-pdf` y `/configuracion/pdf-templates` (el editor anterior)

Cada documento que sale del sistema —cotización, remisión, orden de compra, ficha de producto,
certificado— tiene su plantilla PDF: tamaño de hoja, logo, colores, tipografía y qué bloques
lleva.

## Dos editores

- **Plantillas PDF** (`/configuracion/plantillas-pdf`): el editor por bloques. Se arma el
  documento arrastrando bloques —encabezado, tabla de ítems, totales, firma, notas— y cada uno
  tiene sus propias opciones.
- **Estilos por módulo** (`/configuracion/pdf-templates`): el editor anterior, más simple. Sigue
  vivo porque hay documentos que todavía lo usan.

## El asistente

Hay un asistente que arma una plantilla a partir de una descripción en palabras («una cotización
sobria, logo arriba a la izquierda, con firma al final»), y de ahí se ajusta a mano.

## Vista previa

Cada plantilla tiene su previsualización con datos de ejemplo, para no descubrir el problema
después de mandarle el PDF a un cliente.

## Lo que hereda de la marca

El color y el logo salen del [perfil de marca](./marca.md) salvo que la plantilla los
sobreescriba: cambiar el color de la empresa cambia sus documentos sin tocar cada plantilla.
