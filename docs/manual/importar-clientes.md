# Importar clientes desde CSV

**Clientes → botón Importar.** Funciona igual que la importación de productos:
descargas la plantilla, la llenas y la subes.

## Cómo funciona

1. **Descarga la plantilla.** Trae los encabezados correctos y dos filas de
   ejemplo: una empresa con contacto y una persona natural.
2. **Llénala** en Excel o Google Sheets. Solo la columna **nombre** es
   obligatoria.
3. **Súbela.** El resultado dice cuántos se crearon, cuántos se actualizaron y
   qué filas fallaron, con el motivo de cada una.

## La identificación es la llave

Si el número de identificación del archivo **ya existe** en el sistema, ese
cliente se **actualiza**. Si no existe, se crea.

Eso significa que **puedes reimportar el mismo archivo sin duplicar nada**.
Sirve para cargar 200 clientes hoy, corregir el archivo, y volverlo a subir.

Las columnas que dejes vacías **no borran** lo que ya estaba: se conserva el
valor anterior.

El número puedes escribirlo con puntos y guiones (`901.195.995-8`); se limpia
solo. **El dígito de verificación no va en columna aparte** — se calcula.

## Sedes

La columna `sede` lleva el **nombre exacto** de la sede. Si la escribes mal, la
fila falla con un mensaje claro en vez de cargar el cliente en el lugar
equivocado, que sería peor.

Si la dejas vacía, el cliente queda en la **sede activa** (la del selector del
encabezado). Vale la pena revisar cuál está seleccionada antes de importar.

## Contactos

La plantilla trae seis columnas `contacto_*` para la persona de contacto de la
empresa.

**Si no pones contacto, la fila NO falla**: el cliente se carga igual y aparece
en una lista aparte de "Empresas sin contacto". Se hizo así a propósito —
perder toda la fila por un dato que puedes completar después sería peor. Pero
ten en cuenta que **a una empresa sin contacto no se le puede hacer una
cotización** hasta que le agregues uno.

Para personas naturales el contacto se crea solo con sus propios datos, igual
que cuando se crea desde el formulario.

## Detalles que evitan dolores de cabeza

- **Separador**: acepta punto y coma o coma. Excel en español guarda con punto
  y coma; se detecta solo.
- **Acentos**: la plantilla trae BOM, así que Excel abre bien las tildes y las
  eñes.
- **Segmentación**: son cuatro columnas y admiten **varias opciones separadas
  por coma** en la misma celda:

  | Columna | Es | Ejemplo |
  |---|---|---|
  | `tipos_contacto` | Tipo de contacto | `Cliente directo,Distribuidor` |
  | `industrias` | Industria | `Alimentos y bebidas,Supermercados` |
  | `proceso_seguimiento` | En qué va | `Primer contacto` |
  | `fuentes_contacto` | De dónde salió | `Referido,WhatsApp` |

  Se puede escribir **la etiqueta tal como aparece en pantalla** ("Cliente
  directo") o el valor interno (`cliente_directo`); da igual mayúsculas y
  tildes. Lo que no coincida con ninguna opción **se omite y se avisa** al
  final de la importación, en vez de guardarse mal.

  La pantalla de importación lista las opciones válidas de cada columna, para
  no tener que adivinarlas.
- **Sí/No**: se acepta `Si`, `Sí`, `1`, `true` o `x`. Cualquier otra cosa se
  lee como No.
- **Cada fila es independiente**: si una falla, las demás se cargan igual.

## Permisos

Requiere el permiso **Clientes → Crear**. Quien solo puede ver clientes no ve
el botón de Importar.
