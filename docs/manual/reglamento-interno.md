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
