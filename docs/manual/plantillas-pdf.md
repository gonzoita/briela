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

## Cómo se dibuja un PDF

Un solo camino: `PdfPlantillaRenderer`. Lo usan la vista previa del editor y los documentos
reales —cotización, OP, remisión y recibo de pago—, así que **lo que se ve en el editor es lo que
sale**. Hasta el 23 sep 2026 eran dos, y una plantilla hecha en modo visual o con papel de
etiqueta se veía bien en la vista previa y salía en blanco (o rompía) al descargarla.

| Pieza | Qué hace |
|---|---|
| `PdfVariablesEngine::prepararDatos($modulo, $registro)` | Arma los datos: claves planas (`cliente.nombre`) y listas (`items`) |
| `PdfVariablesEngine::variablesDisponibles($modulo)` | El diccionario del editor, sacado de las mismas fuentes |
| `PdfPlantillaMotor` | Interpreta la plantilla: lee una vez, arma un árbol y lo recorre |
| `PdfPlantillaRenderer` | Encabezado + cuerpo + pie → documento dompdf, con el papel |
| `PdfPlantillaIaService` | El meta-prompt (uno solo para Briela y para IA externa) y el separador de la respuesta |

**Un módulo nuevo** se agrega en `config/pdf_modulos.php`, con su caso en `prepararDatos()` y sus
variables en `variablesExtras()`; su controlador llama `PdfPlantillaRenderer::paraRegistro()` y
cae a su vista Blade si devuelve `null`.

## Sintaxis

| Escribe | Sale |
|---|---|
| `{{cliente.nombre}}` | El valor, escapado |
| `{{cotizacion.total\|moneda}}` | Con filtro: `moneda`, `numero`, `entero`, `pct`, `fecha`, `fecha_hora`, `hora`, `upper`, `lower`, `nl2br`. Se encadenan |
| `{{!imagen_base64}}` o `{{{x}}}` | Sin escapar: imágenes base64 o HTML guardado |
| `{{#each items}} … {{/each}}` | Una vez por fila. Dentro: los campos de la fila, `@numero` (desde 1), `@index` (desde 0), `@first`, `@last`, y cualquier variable global. `{{#items}}…{{/items}}` sigue valiendo |
| `{{#if x}} … {{else}} … {{/if}}` | Se anida. Vacío, `0` y `0.00` son falsos. Compara: `{{#if op.estado == "despachada"}}`, `!=`, `>`, `<`, `>=`, `<=` |
| `{{#unless x}} … {{/unless}}` | Lo contrario de `#if` |
| `{{qr:op.numero}}` | Imagen QR del valor (o del texto escrito, si no es variable) |
| `{{salto_pagina}}` · `{{pagina}}` · `{{total_paginas}}` | Página nueva · número de página · total |

Una variable que **no existe sale vacía**, nunca como `{{texto}}` en el PDF del cliente, y el
botón **Validar** del editor la señala contra el último registro real del módulo, junto con las
etiquetas mal cerradas. Los datos nunca se vuelven a leer como plantilla: un cliente llamado
«{{empresa.nit}}» imprime eso, no el NIT.

## Encabezado, cuerpo y pie

En el modo código hay tres piezas (`html_header`, `html`, `html_footer`), igual que en el visual.
Encabezado y pie se repiten en **cada página** (`position: fixed` de dompdf) con el alto que se
les dé en milímetros; vacíos, no ocupan espacio. El margen de la hoja lo pone el sistema (12 mm,
el mismo que dompdf ponía solo: las plantillas viejas salen igual). Para numerar, en el pie:
`Página {{pagina}} de {{total_paginas}}`.

El motor es **dompdf**: CSS 2.1. Nada de flex, grid, `calc()` ni variables CSS; columnas con
`<table>` o `display: table-cell`; fuente `DejaVu Sans` para tildes y eñes. `class="evitar-corte"`
impide que un bloque se parta entre páginas, y los `<thead>` se repiten solos.
`{{empresa.logo_url}}` va incrustado en base64, porque dompdf no descarga imágenes por URL.

## Generar con IA

- **Con Briela**: se describe lo que se quiere y Briela lo maqueta por el proxy de IA, partiendo de
  la plantilla actual si se marca. El resultado cae en el modo código, para revisarlo antes de guardar.
- **Con otra IA**: «Compilar y copiar» deja en el portapapeles el meta-prompt —diccionario de
  variables, CSS soportado, reglas de maquetación y formato de respuesta—. Lo que devuelva se pega
  y se reparte solo en encabezado, cuerpo y pie (marcas `<!-- ENCABEZADO -->`, `<!-- CUERPO -->`,
  `<!-- PIE -->`; sin marcas, todo va al cuerpo).

## Lo que hereda de la marca

El color y el logo salen del [perfil de marca](./marca.md) salvo que la plantilla los
sobreescriba: cambiar el color de la empresa cambia sus documentos sin tocar cada plantilla.
