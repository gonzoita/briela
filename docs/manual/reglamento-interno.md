# Reglamento interno de trabajo

Ruta interna: `/rrhh/reglamento` · Enlace público: `/reglamento/{token}`

El reglamento de la empresa se escribe adentro y se lee afuera, sin usuario ni contraseña. Un
colaborador nuevo tiene que poder leerlo **el primer día**, antes de que alguien le cree un
acceso — y por eso vive detrás de un enlace público y no de un login.

## Escribirlo

En RRHH → **Reglamento interno**. Se escribe con el editor de siempre: títulos, listas,
negrillas, tablas. También se puede **pegar** desde Word y conserva el formato.

Lleva además **versión** y **fecha de vigencia**, que salen en la portada de la versión
pública. Un reglamento sin versión se puede confundir con el anterior.

## El índice sale de los títulos

El índice que ve el colaborador **no se escribe**: se arma solo con los títulos del documento.
La página pública lee los `h2` y los `h3` del contenido y los lista en orden.

Por eso un documento **pegado de Word o de un PDF sale sin índice**: ahí los capítulos vienen
como párrafos en negrita y centrados. Se ven igual que un título, pero no lo son.

### Marcarlos a mano

En la barra del editor están **T1** (título de capítulo) y **T2** (subtítulo). Se pone el
cursor en la línea y se aprieta: la línea pasa a ser un título y aparece en el índice.

### Marcarlos de una vez

El botón **«Detectar títulos»** recorre el documento y marca lo que reconoce:

| Se convierte en | Cuándo |
|---|---|
| Título de capítulo (`T1`) | La línea empieza por **CAPÍTULO, TÍTULO, ANEXO o SECCIÓN** |
| Título de capítulo (`T1`) | Es una línea **corta y toda en negrita** — así vienen los encabezados de Word |
| Subtítulo (`T2`) | Empieza por **ARTÍCULO N** y lo que sigue es corto y en mayúsculas, o no sigue nada |

Lo que **no** convierte, a propósito: un artículo cuyo texto viene en el mismo párrafo
—«ARTÍCULO 2. Las condiciones de admisión son las siguientes…»—. Convertirlo dejaría un
encabezado enorme con un párrafo adentro y un índice ilegible; ahí el artículo se queda como
texto.

No cambia una palabra del documento: solo cambia la etiqueta de la línea. Y **no se guarda
solo** — se aprieta, se mira el resultado y, si no gustó, se sale sin guardar y queda como
estaba.


## Publicarlo

El interruptor **Publicado** es lo único que hace responder el enlace. Apagado, el enlace no
lleva a ninguna parte y el QR tampoco: hacia afuera el documento simplemente no existe, sin
que se borre nada.

No se puede publicar un reglamento vacío — el enlace mostraría una hoja en blanco con el
nombre de la empresa encima.

## Repartirlo

Dos formas, las dos en la misma pantalla:

- **El enlace**, con botón de copiar, para pegar en un correo o en un grupo de WhatsApp.
- **El código QR**, para imprimir y pegar en la cartelera o en la entrada. Se descarga en SVG,
  que no se pixela por grande que se imprima.

**Generar un enlace nuevo** es para cuando el enlace llegó a donde no debía: el anterior deja
de funcionar en el acto. Hay que repartir el QR otra vez, y por eso la pantalla lo pregunta.

## Cómo lo ve el colaborador

La página pública está hecha para una sola cosa: que se pueda leer.

- **Ancho de lectura, no ancho de pantalla.** Una línea de 120 caracteres obliga al ojo a
  buscar dónde empieza la siguiente.
- **Letra de 18 px con interlineado holgado.** En un texto de treinta páginas, 14 px cansan a
  la tercera.
- **Índice automático**, armado con los títulos del documento. Un reglamento se consulta más
  de lo que se lee de corrido: casi siempre alguien viene a buscar **un** artículo.
- **Barra de avance** arriba: saber cuánto falta es la diferencia entre seguir y cerrar.
- **Botón de imprimir**, que también sirve para guardarlo en PDF.

No hay menú, ni barra lateral, ni nada que tocar salvo el contenido. Se ve igual en el celular
y respeta el modo día/noche del sistema.

## Quién puede editarlo

Ver el reglamento lo puede cualquiera con permiso de RRHH. **Editarlo es un permiso aparte —
«Reglamento interno»— que por omisión solo tiene el administrador.**

Es un documento de la empresa entera, no la ficha de una persona: quien administra
colaboradores no necesariamente debe poder cambiar el reglamento. Si hace falta, se le asigna
a alguien más desde Roles.

## Lo que este módulo no hace todavía

- **No guarda versiones anteriores.** Al editar, se reemplaza. La tabla ya admite varias filas
  para el día que haga falta, pero la pantalla trabaja sobre una.
- **No registra quién lo leyó.** El siguiente paso natural sería que el colaborador confirme
  la lectura y quede constancia con fecha — que es lo que pediría un inspector.
