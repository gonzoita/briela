# Integración de IA *(nuevo, 26 jul 2026)*

La IA del SGI hace dos cosas hoy: **redactar textos** y **generar imágenes**.
Todo pasa por una sola cuenta y un solo saldo.

## Cómo está montado: una sola cuenta (OpenRouter)

En vez de abrir cuenta en cada proveedor, el sistema se conecta a
**OpenRouter**, que es una pasarela: con una sola credencial y un solo saldo se
usa Claude para los textos y un modelo de Google para las imágenes.

**Importante: no es una suscripción mensual.** Es saldo prepagado que se va
descontando por uso. OpenRouter cobra un 5.5% sobre cada recarga y el saldo no
vence. La ventaja no es el precio, es que hay una sola factura y una sola clave
que administrar.

Ni Claude Pro ni ChatGPT Plus sirven para esto: esas suscripciones son para
usar el chat en el navegador, no dan acceso por API para un sistema propio.

## Redactar descripciones

Hay dos botones junto a "Descripción corta", y hacen cosas distintas.

**"Redactar"** —solo al editar un producto— lee los datos del producto y propone
una frase para la descripción corta. Es lo rápido.

**"Ficha técnica con IA"** —en productos y ensambles, al crear y al editar— arma
la ficha completa y llena **los dos** campos: la introducción comercial en la
descripción corta y las especificaciones, ventajas, beneficios y componentes en
la larga. Ver abajo.

En los dos casos:

- **No guarda nada solo.** Pone el texto en el campo; se guarda cuando le das
  Guardar, como siempre.
- **No inventa datos técnicos.** Usa solo los datos que se le pasan; si un dato
  falta, no lo menciona.
- Español colombiano neutro, sin voseo, sin emojis.

Acceso: permiso **Productos → Editar** para el rápido; para la ficha, cualquiera
de crear o editar productos o ensambles.

## La ficha técnica con IA

Abre una ventana con un campo grande: **datos técnicos en bruto**. Ahí se pega lo
que se tenga —medidas, materiales, potencia, voltaje, acabados, normas— como
venga, en desorden. Eso es la materia prima de la ficha.

El resto lo toma del formulario: nombre, referencia, categoría y unidad. **En un
ensamble además lee sus medidas y su receta de componentes**, que ya están
calculadas: son datos técnicos de verdad y nadie tiene que volver a escribirlos.

Lo que devuelve se muestra antes de aplicarlo, con «Otra versión» para volver a
pedirla y «Usar esto» para llenar los campos. Reemplaza lo que hubiera; no guarda
el producto.

### El prompt es tuyo

Las instrucciones con las que redacta viven en **Configuración → Perfil de marca
→ Cómo redacta las fichas técnicas**, y se pueden reescribir completas. De fábrica
viene una ficha de siete bloques —nombre, referencia, introducción, especificaciones
agrupadas por subtítulos, ventajas, beneficios y componentes—, pero cada rubro
describe distinto.

Está ahí y no en el código a propósito: si estuviera adentro, cambiar la estructura
de la ficha sería parchear la instalación de un cliente, y esa instalación ya no se
podría actualizar.

Tres cosas **no** se pueden cambiar desde ahí porque son del sistema:

- El español colombiano sin voseo.
- La prohibición de inventar especificaciones.
- El formato con el que la respuesta llega a los dos campos.

**El tono lo pone tu perfil de marca**, no el prompt. Se le pasan las secciones que
afectan la redacción —tono y voz, identidad, propuesta de valor, mensaje clave y
promesa— y no las demás: la DOFA y los KPIs no ayudan a escribir una ficha y se
pagan en tokens.

### Si la IA responde raro

La respuesta se pide en un formato preciso para poder repartirla en los dos campos.
Cuando un modelo no lo respeta, la ficha completa queda en la descripción larga, la
corta queda vacía, y la pantalla lo dice con un aviso amarillo en vez de perder el
resultado y cobrar otra llamada. Si pasa seguido, el modelo de texto configurado no
es bueno siguiendo instrucciones: cámbialo en Configuración → IA.

## Generar imágenes

Hay dos puntos de entrada, y en los dos se describe lo que se quiere y se elige
un estilo (fotográfico, ilustración, minimalista o render 3D):

- **Multimedia → "Generar con IA"** — el generador de uso general. Sirve para
  banners, fondos, piezas de marketing e ilustraciones de capacitación. Lo
  generado aparece en la misma biblioteca, con el filtro *Generadas con IA*.
- **Redes Sociales → nueva publicación o editar** — genera la imagen y la deja
  adjunta a la publicación de una vez.

En ambos casos la imagen queda **guardada en Multimedia**, así que se genera
una vez y se reutiliza donde haga falta sin volver a generarla — ni volver a
pagarla.

Acceso: permiso **Multimedia → Crear**.

### "Mejorar mi descripción con IA" (viene activado)

Cuando generas una imagen en ChatGPT, tu texto **no** le llega tal cual al
generador: el chat primero lo reescribe agregando composición, encuadre,
iluminación y materiales. Por API eso no ocurre, y por eso la misma idea puede
dar un resultado más pobre desde el SGI que desde ChatGPT, aunque el modelo sea
el mismo.

Esta casilla replica ese paso: el modelo de texto expande tu descripción corta
en un prompt visual detallado, y con eso se genera la imagen.

- Viene **activada** porque en general mejora bastante el resultado.
- **Desmárcala** para enviar tu texto tal cual, si quieres control total o
  comparar los dos caminos.
- Se le instruye a no cambiar tu idea ni agregar objetos que no pediste: solo
  añade detalles visuales.
- Si ese paso falla, se usa tu texto original — nunca te deja sin imagen.

Costo: agrega una llamada de texto (barata) antes de cada imagen.

### Regla de oro: nunca uses IA para fotos de producto

Una imagen generada de un cuarto frío **no es tu cuarto frío**: es una
invención parecida. Si eso termina en una cotización o en el catálogo, el
cliente está viendo algo que no se le va a entregar, y ahí se vuelve un
problema comercial y hasta legal.

Usa la IA para lo decorativo: fondos, texturas, piezas de redes, ilustraciones
de apoyo, banners genéricos. **La foto del producto siempre es la real.**

Por eso el generador incluye la instrucción de no meter texto ni logotipos
dentro de la imagen: los modelos escriben mal las letras y el logo de
la empresa se pone aparte, con el diseño real.

## Cómo activarlo

1. Crear cuenta en [openrouter.ai](https://openrouter.ai).
2. Cargar saldo (recomendado: empezar con poco y mirar el consumo real).
3. Generar una API key.
4. Pegarla en **Configuración → Perfil de marca y asistente → Conexión con la
   IA**, y darle **Probar conexión** para confirmar que quedó bien.

No hace falta entrar al servidor ni tocar el `.env`: la credencial se guarda en
la base de datos, igual que la configuración de SMTP.

Sin la credencial el módulo queda apagado y no falla nada: los botones muestran
un aviso claro.

### Cambiar de modelo

En esa misma pantalla se eligen el **modelo de texto** y el **modelo de
imagen**, de una lista con **todos los modelos disponibles en OpenRouter**, que
se trae de su API y se separa según si generan texto o imagen. Como son
cientos, cada lista tiene un buscador (escribe "claude", "gpt", "gemini"...).

La lista se guarda en caché un día. Si acabas de configurar la credencial y
aparece vacía, guarda de nuevo y se recarga.

### La respuesta aparece palabra por palabra

El texto se va escribiendo en pantalla a medida que el modelo lo genera, con un
cursor parpadeando al final.

Esto **no** hace la respuesta más rápida: hace que empieces a leer a los 2
segundos en vez de mirar unos puntos suspensivos durante 7. La diferencia en
cómo se siente es enorme aunque el reloj marque lo mismo.

El primer paso —decidir qué consultar y consultarlo— no se puede transmitir,
hay que esperarlo. Lo que se transmite es la redacción, que es donde están el
80% de los segundos.

**Si el hosting no lo soporta**, el navegador cae solo al modo de siempre y la
respuesta llega completa de una vez. No hay que configurar nada ni te enteras:
solo notarías que volvió a aparecer todo junto.

La **voz se lee al final**, cuando el texto está completo. Es a propósito: leer
en voz alta pedazos sueltos suena entrecortado.

### Si el asistente se siente lento

Debajo de cada respuesta que tarde más de 4 segundos aparece el **desglose del
tiempo**: cuánto tardó en decidir qué consultar, cuánto la consulta a la base y
cuánto en redactar. Sin ese dato, "se demora" es una sensación y se termina
cambiando cosas al azar.

Con el desglose a la vista:

- **Decidir tarda mucho** → el modelo rápido no es tan rápido. Cámbialo por uno
  tipo *flash* o *lite*.
- **Redactar tarda mucho** → es el modelo de texto. Los modelos grandes (Kimi
  K2.6, por ejemplo, con un billón de parámetros) son mejores redactando pero
  inherentemente más lentos. Es un intercambio, no una falla.
- **Consultar tarda mucho** → el cuello de botella es la base de datos, no la
  IA. Ahí no ayuda cambiar de modelo.

Tres cosas que ya vienen aplicadas para acelerar:

1. **Proveedor más rápido.** OpenRouter reparte el mismo modelo entre varias
   empresas y por defecto elige la más barata. Se le pide explícitamente la más
   rápida, lo que puede duplicar la velocidad por unos centavos más. Se apaga
   con el interruptor "Priorizar el proveedor más rápido".
2. **Sin razonamiento en el paso de decisión.** Varios modelos *flash* traen el
   "modo pensamiento" encendido, y eso agrega segundos a una decisión que es
   básicamente buscar un nombre en un menú.
3. **Contexto recortado al decidir.** Para entender a qué se refiere un "¿y en
   Cali?" basta el final de la conversación, no el historial completo.

### Modelo rápido: para que el asistente responda más ágil

Cada pregunta al asistente hace **dos llamadas**: una corta para decidir qué
consultar, y otra larga para redactar la respuesta. Si ambas usan el modelo
bueno, la primera gasta tiempo de más en una tarea trivial.

En **Modelo rápido** se puede poner uno pequeño y veloz solo para ese primer
paso. La redacción sigue usando el modelo de texto, así que la calidad de la
respuesta no cambia — solo se acorta la espera. Dejarlo vacío usa el mismo de
texto para todo.

Por defecto: `anthropic/claude-sonnet-5` para texto y `openai/gpt-image-2` para
imágenes.

> El `.env` sigue funcionando como respaldo: si en Ajustes está vacío, se usa lo
> que haya ahí. Lo de Ajustes tiene prioridad.

## Cuánto cuesta

Se paga por uso:

- **Textos**: una descripción de producto mueve muy poco texto. Redactar un
  catálogo de varios cientos de productos está en el orden de unos pocos
  dólares.
- **Imágenes**: alrededor de USD 0.04 por imagen según el modelo. Cien
  imágenes para redes ≈ 4 dólares.

Si el saldo se acaba, el sistema lo dice con un mensaje claro ("La cuenta de IA
se quedó sin saldo") en vez de fallar de forma rara.

## Perfil de marca

En **Configuración → Perfil de marca y asistente** vive la identidad de la
empresa, repartida en secciones: identidad, historia, propósito, promesa,
propuesta de valor, misión, visión, valores, elevator pitch, mensaje clave,
tono y voz, clientes ideales, KPIs y DOFA.

**Ya viene cargado** con el contenido del documento "Perfil de Marca
la empresa", así que el módulo nace funcionando.

Cada sección se puede:

- **Escribir a mano**, como cualquier campo.
- **Redactar con IA**: se abre una pregunta guiada ("¿cómo nació la empresa?"),
  respondes con tus palabras sin preocuparte por la redacción, y la IA la
  escribe usando el resto del perfil para no contradecirse. El texto se
  propone en el campo; se guarda solo cuando le das Guardar.
- **Importar**: pegas el texto de un documento de marca y la IA lo reparte en
  las secciones. Esto sí reemplaza lo que haya en las secciones que reconozca.

### El tono y voz manda sobre toda la redacción

La sección **Tono y voz** no es decorativa: se le inyecta a la IA cada vez que
redacta una descripción de producto. Si la cambias, cambia cómo escribe todo el
sistema. Es la forma de que no queden dos voces distintas.

## El asistente

En el menú aparece el **asistente**, con el nombre que le pongas. Responde
sobre la marca **y consulta los datos reales del sistema**.

Se le configura (en Configuración → Perfil de marca y asistente):

- **Nombre** — bautízalo como quieras.
- **Rol** — qué papel cumple: analista de datos, asesor comercial, jefe de
  producción, asistente administrativo… Hay atajos con roles sugeridos, o lo
  escribes tú.
- **Personalidad** — cómo suena (directo, técnico, cercano).

### Cómo se comporta

Está construida para sonar como alguien del equipo, no como un manual:

- **Se presenta por su nombre** al iniciar una conversación y saluda a quien
  pregunta por su nombre de pila.
- **Responde a su nombre**: si le escribes "Ofe, ¿cómo van las ventas?", lo
  entiende y sigue natural, sin repetir su nombre en cada respuesta.
- **No dice que es un modelo de lenguaje.** Es la asistente del SGI.
- **Habla, no formatea.** Para preguntas simples responde en una o dos frases;
  los títulos y viñetas los reserva para los informes.
- **Sin muletillas de robot** ("¡Claro!", "Aquí tienes", "Espero que te ayude").
- **Dice las cosas como son.** Si algo va atrasado o falta información lo señala
  directo, y puede dar su lectura de los datos siempre separándola de las cifras.

### Qué datos puede consultar

Ventas y cotizaciones, ventas por vendedor, estado de la producción, entregas
próximas y vencidas, **informe de productividad** (pasos completados por
colaborador, tiempo real contra estimado y desviación), insumos bajo mínimo,
**stock de un producto concreto**, cartera pendiente y vencida, compras
abiertas, embudo del CRM, clientes principales, remisiones y colaboradores.

Ejemplos de lo que se le puede preguntar:

- "¿Cómo va la producción?" · "¿Cómo van las ventas?"
- "Dame un informe de productividad"
- "¿Cuánto tenemos de panel de 100mm?"
- **"¿Puedo entregar la OP 191?"**

### La pregunta de si se puede entregar una OP

Es un caso especial y vale la pena explicarlo. Cuando se pregunta por una OP
concreta, el sistema reúne su avance, si el control de calidad está aprobado,
el saldo por cobrar y las remisiones existentes, y **calcula en código** si se
puede despachar según la regla del negocio: *la calidad aprobada es obligatoria
antes de despachar*.

La IA no deduce ese veredicto: solo lo comunica y explica qué falta. Así la
respuesta es consistente con lo que hace el sistema cuando intentas crear la
remisión.

### Cómo evita inventar cifras (lo importante)

**La IA nunca escribe consultas a la base de datos.** El sistema tiene un
catálogo cerrado de consultas ya programadas y verificadas; la IA solo elige
cuál usar, el cálculo lo hace el código, y después redacta la respuesta con
esas cifras. Por eso los números del asistente son los mismos que ves en las
pantallas.

Además:

- Bajo cada respuesta con datos aparece **de qué consulta salieron**, para
  poder verificarlo en la pantalla correspondiente.
- Se le instruye explícitamente a no estimar: si no hay datos, lo dice.
- Si el resultado viene en cero, lo dice tal cual en vez de maquillarlo.
- Si algo no está en el perfil de marca, lo dice en vez de inventarlo.

### Crear cotizaciones por conversación

Se le puede pedir: *"hazme una cotización para IK Colombia de 20 paneles de
100mm"*. Ofe identifica el cliente y los productos y **crea la cotización en
borrador**.

Reglas que no se rompen:

- **Siempre queda en BORRADOR.** Nunca deja algo listo para enviarle a un
  cliente sin que una persona lo revise.
- **Los precios los pone el sistema, no la IA.** Ella elige qué productos; el
  cuánto vale sale del catálogo con tus márgenes. Si la IA pudiera proponer
  precios, tendrías cotizaciones con números inventados.
- **Si el cliente no existe, no lo crea**: te avisa. Y si hay varios parecidos,
  te pregunta cuál. Así no se llena la base de clientes duplicados.
- **Si un producto no aparece en el catálogo, no lo inventa**: te dice cuál no
  encontró para que le des el nombre exacto.
- Si algún producto quedó **sin precio cargado**, te lo advierte para que lo
  corrijas antes de enviar.

Requiere el permiso **Cotizaciones → Crear**. Es la única cosa que Ofe puede
escribir en el sistema; todo lo demás es solo lectura.

Qué revisar siempre antes de enviarla: que la variante del producto sea la
correcta (100mm y no 150mm), las cantidades, y los descuentos, que Ofe deja
en cero.

### Respeta permisos y sede

El asistente **no es una puerta trasera a los datos**. Cada consulta:

- Solo está disponible si el usuario tiene el permiso correspondiente. Si
  alguien no puede ver Cartera en el sistema, el asistente tampoco se la cuenta.
- Respeta la **sede activa**: las cifras son de la sede en la que estés parado,
  o de todas si tienes ese alcance — y el asistente lo menciona para que no
  haya confusión.
- Es de **solo lectura**: el asistente no puede modificar nada.

El **rol** que configures cambia cómo responde, no a qué accede. El acceso lo
definen siempre los permisos de cada usuario.

### Formato de las respuestas

Las respuestas se muestran con negritas, títulos y viñetas. Por seguridad el
texto de la IA se escapa antes de darle formato, así que aunque devolviera
código HTML nunca se ejecutaría en el navegador.

### Dónde está

- **Burbuja flotante**, abajo a la derecha, disponible sobre cualquier pantalla
  del sistema. Es la forma recomendada de usarlo: puedes preguntarle mientras
  miras una OP o una cotización, sin salirte de donde estás. La conversación
  **no se pierde al navegar** entre módulos.
- **Pantalla completa** en el menú lateral, para sesiones más largas.

Se puso a la derecha y no a la izquierda porque en computador el menú lateral
ocupa esa franja, y en celular la esquina inferior izquierda es el botón de
Inicio de la barra de navegación.

### Hablarle y que te hable

- **Dictado**: el botón de micrófono junto al campo de texto. Le hablas, se
  transcribe y la pregunta se envía sola al terminar. Usa el reconocimiento de
  voz del navegador: **no consume saldo**. Funciona en Chrome, Edge y Android;
  en Firefox el botón no aparece porque no lo soporta.
- **Que te lea las respuestas**: el ícono de bocina en la cabecera activa la
  lectura automática, y bajo cada respuesta hay un "Escuchar".

**La voz se configura en Configuración → Perfil de marca y asistente**, en la
tarjeta "Tu asistente", junto al nombre, el rol y la personalidad. Es parte de
su identidad: el asistente tiene UNA voz, igual que tiene un nombre. Ahí se
elige si usa voz natural y cuál voz (mujer, hombre o neutra).

Lo único que decide cada usuario, desde el engranaje del chat, es **si quiere
que le lean las respuestas en voz alta**. Esa preferencia se guarda en su
navegador y no afecta a los demás.

Hay dos calidades de voz:

| | Voz del navegador | Voz natural |
|---|---|---|
| Costo | Gratis | Consume saldo |
| Calidad | Robótica pero clara | Natural |
| Género | Busca una voz femenina del sistema; depende de lo instalado | Se elige |

Voces disponibles con voz natural:

| Voz | Género |
|---|---|
| Nova (recomendada), Shimmer, Coral, Sage | Mujer |
| Alloy | Neutra |
| Echo, Onyx, Fable | Hombre |

### El acento se pide, no se elige

Las voces de fábrica hablan español con acento neutro o español de España. Para
que suene latinoamericana **hay un campo "Cómo debe hablar"** donde se le
describe con palabras: acento colombiano de Bogotá, tono cálido, ritmo
conversacional. Eso cambia el resultado mucho más que elegir otra voz.

Viene con una descripción por defecto que pide acento colombiano. Se puede
ajustar y **escuchar el resultado ahí mismo** con el botón "Escuchar cómo
suena", sin salir de la pantalla.

> Si al escuchar suena robótica y española, casi seguro la voz natural no se
> está usando: revisa el modelo de voz. El sistema ahora avisa cuando falla en
> vez de caer en silencio a la voz del navegador.

La voz natural se genera con el mismo OpenRouter, así que **no hace falta otra
cuenta**. Si por algún motivo falla, cae automáticamente a la voz del
navegador: nunca se queda muda.

### La conversación se guarda

El historial vive **en el servidor, por usuario**. Eso significa que:

- Sobrevive a recargar la página, a cerrar el navegador y a apagar el
  computador.
- Es la misma conversación en la burbuja flotante, en la pantalla completa del
  asistente y en el celular.
- El asistente recuerda de qué venían hablando: si preguntas "¿y en Cali?"
  después de "¿cómo va la producción?", entiende a qué te refieres.

Se conservan los **últimos 100 mensajes** por usuario; los más viejos se
borran solos para no acumular filas que nadie va a leer. Como contexto para
responder se le mandan a la IA los **últimos 10**.

El botón **Borrar conversación** la elimina del servidor y pide confirmación,
porque no se puede recuperar.

**Cada usuario ve solo la suya.** No es una decisión estética: las respuestas
traen cifras filtradas por los permisos y la sede de quien pregunta, así que
el chat de un vendedor no debe ser visible para otro.

El contexto que se le manda a la IA se lee de la base de datos, no de lo que
envíe el navegador. Antes lo mandaba el cliente, lo que significaba que
cualquiera podía inventarse un historial falso desde la consola.

Acceso: cualquier usuario autenticado puede usarlo. Editar el perfil y el
asistente requiere permiso de **Configuración → Editar**.

## Perfiles de acceso: qué puede consultar la IA según a quién atiende

No todos los que hablan con la IA pueden ver lo mismo. Hay **dos catálogos de
consulta separados a propósito**, no uno recortado.

| Perfil | A quién atiende | Qué puede consultar |
|---|---|---|
| **Interno** | Un usuario del sistema con sesión iniciada | El catálogo completo, **filtrado por los permisos de su rol**. Un vendedor sin acceso a compras no puede preguntar por órdenes de compra |
| **Público** | Cualquiera que escriba sin identificarse: WhatsApp, chat de la web, lo que sea | Solo **quién es la empresa**, **datos de contacto** y **qué productos ofrece**. Nada más |

### Por qué son dos catálogos y no uno con filtro

El catálogo interno (`ConsultasDatosService`) decide qué mostrar según los
permisos del usuario. Pero **un desconocido no tiene usuario ni permisos**, así
que usar ese mismo catálogo significaría confiar en que el filtro nunca falle.
Un solo descuido y alguien de afuera vería cotizaciones, cartera o inventario.

Por eso el agente público usa un catálogo aparte
(`ConsultasPublicasService`) que **no conoce** las consultas internas: no es que
las tenga bloqueadas, es que no existen para él. Pedirle una devuelve nulo.

Todo lo que ese catálogo entrega **ya es público por otro lado** (el catálogo en
`/catalogo` y los datos de contacto), así que responderlo por chat no expone
nada nuevo. No incluye costos, márgenes ni existencias.

### El flujo con un desconocido

1. Alguien escribe por WhatsApp (o por donde sea) y **no sabemos quién es**.
2. El agente lo atiende con lo público: qué hace la empresa, qué vende, horarios.
3. En paralelo, el sistema **crea el lead y lo reparte** a un vendedor (ver
   [WhatsApp](./whatsapp.md)).
4. El vendedor toma la conversación desde ahí.

El agente **nunca** promete precios de productos a la medida: esos dependen de
las dimensiones y los tiene que cotizar una persona.

### Pendiente: el modo "cliente identificado"

Falta un tercer perfil para cuando alguien **sí demuestre** quién es (como ya
hace el portal de seguimiento, que exige el número de OP más el apellido o el
documento). Ese perfil podría responder "¿cómo va mi pedido?" mirando
únicamente **los datos de esa persona**. No está construido todavía.

## Siguientes pasos posibles

No están construidos todavía:

- Descripciones para ensambles y OPs (el servicio ya está preparado).
- Más consultas en el catálogo del asistente: basta con agregarlas en
  `app/Services/IA/ConsultasDatosService.php` y quedan disponibles solas.
- Análisis de tiempos de producción: cuellos de botella y pasos que se demoran
  más de lo estimado, por sede.
- Sugerencias de precio y comisión. Toca dinero: conviene dejarlo de último y
  siempre como sugerencia revisable, nunca aplicando cambios solo.
