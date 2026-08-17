# WhatsApp — conexión y números

Ruta: `/configuracion/whatsapp-numeros` · Acceso: administrador

## Qué es

Permite que el sistema **envíe y reciba mensajes de WhatsApp** desde los números
de la empresa: avisos a clientes, conversaciones atendidas por los asesores y
respuestas registradas dentro del sistema.

No es el WhatsApp del celular. Funciona con **WhatsApp Business API** (la API en
la nube de Meta), que es la única forma en que una plataforma puede mandar
mensajes de forma automática sin violar los términos de WhatsApp.

> **Ojo con la confusión más común:** WhatsApp normal y "WhatsApp Business" (la
> app de la tienda) **no sirven**. Hace falta una cuenta de WhatsApp Business
> **API**, que se crea en Meta y va asociada a un número que no esté usándose en
> la app del celular.

## Conectar (se hace una sola vez)

En `/configuracion/whatsapp-numeros`, arriba, el bloque **Conexión con
WhatsApp** dice si está **Conectado** o **Sin conectar**, y muestra una lista de
lo que falta, en el orden en que hay que resolverlo:

| Chulo | Qué es |
|---|---|
| **Token de acceso de Meta** | La llave de la aplicación. Paso 1 |
| **Token de verificación del webhook** | La contraseña del webhook. Paso 2 |
| **Al menos un número activo** | Sin número no hay desde dónde enviar. Paso 3 |

Debajo pueden salir dos avisos ámbar que **no impiden conectar**, pero conviene
leer: que esta instalación no es alcanzable desde internet, y que falta el App
Secret (ver *La firma del webhook*, más abajo).

### Antes de empezar

- Tener una cuenta de **WhatsApp Business API** en Meta.
- Ser **administrador** del Business Manager donde vive el número.
- Que la instalación tenga **dirección pública con HTTPS**. Meta llama al
  webhook desde internet: a `localhost` no va a llegar nunca. La pantalla lo
  detecta y lo dice.

### Los tres pasos

**1. El token de acceso.** En
[developers.facebook.com](https://developers.facebook.com), abrir (o crear) una
aplicación tipo **Empresa**, agregarle el producto **WhatsApp** y generar un
token.

> **Usar un token permanente** de un *Usuario del Sistema* del Business Manager.
> Los que se generan en la pantalla de pruebas **vencen en 24 horas** y dejan el
> sistema mudo al día siguiente. Es la falla número uno.

**2. El webhook.** En *WhatsApp → Configuración → Webhooks*, pegar la **URL de
devolución de llamada** que muestra la pantalla (con botón de copiar) y el
**token de verificación**. Ese token es una contraseña que uno inventa y que
debe quedar **igual acá y allá**; el botón **Generar** produce una, para no
tener que pensarla.

Después hay que **suscribir el webhook al campo `messages`**. Sin eso la
dirección funciona pero no llega ningún mensaje, y es el segundo error más común.

**3. El número.** En *WhatsApp → Configuración de la API*, copiar el
**Identificador del número de teléfono** (Phone Number ID) y agregarlo abajo, en
**Números registrados**.

> **El identificador va en el número, no en la conexión.** Hasta el 17 ago 2026
> se pedía en los dos sitios con nombres distintos, y el de la conexión solo
> servía para el botón de probar: el que de verdad decide desde qué línea sale
> cada mensaje es el del número. Pegar el mismo dato dos veces hacía creer que
> todo estaba bien mientras los mensajes salían por otra línea. El campo viejo
> se sigue leyendo en instalaciones que ya lo tenían, pero no se vuelve a pedir.

El token se guarda **cifrado** y no se vuelve a mostrar. Para cambiar el token de
verificación sin volver a escribir el de acceso, se deja ese campo vacío.

## Los probadores

Cuando algo falla, las cuatro piezas fallan calladas y del mismo modo. Cada
probador responde por **una** y dice qué hacer si falla. El resultado sale ahí
mismo, sin recargar.

| Probador | Dónde | Qué comprueba | ¿Manda mensajes? |
|---|---|---|---|
| **Probar el webhook** | En la conexión | Que la dirección responda y que el token de verificación coincida | No |
| **Probar** | En cada número | Que Meta reconozca ese identificador, y que sea de la línea que dice el sistema | No |
| **Enviar mensaje de prueba** | En cada número | El circuito completo | **Sí** |
| **Probar** el agente | En la automatización | Qué contestaría la IA | No |

**Probar el webhook** repite exactamente lo que hace Meta al suscribirse. Si la
dirección es local, no llama a nada: dice que Meta no puede llegar ahí, que es
la respuesta útil. Cuando funciona, recuerda las dos cosas que solo se pueden
hacer del lado de Meta: suscribirse a `messages` y cargar el App Secret.

**Probar un número** compara lo que responde Meta con lo escrito en el sistema.
Si el identificador es de otra línea, lo dice en ámbar en vez de en verde: la
consulta funcionó, pero hay algo que corregir. Es la prueba que atrapa el error
más caro —mensajes saliendo desde el número equivocado sin que nadie se entere—
y traduce los códigos de error de Meta a lo que hay que hacer (token vencido,
identificador equivocado, faltan permisos).

**Enviar un mensaje de prueba** es la única que recorre todo. Si no llega, casi
siempre es la **ventana de 24 horas**: Meta solo deja escribir libremente a
quien escribió primero en las últimas 24 horas; para iniciar en frío hace falta
una plantilla aprobada. Existía como comando de terminal (`whatsapp:test`), que
sigue funcionando.

**Probar el agente** funciona **con el agente apagado** y **con lo escrito en
pantalla aunque no se haya guardado**: calibrar las indicaciones es justo lo que
uno hace antes de encenderlo. No manda nada y no crea leads.

### La firma del webhook (App Secret)

El webhook no tiene login: lo llama Meta. Para saber que un mensaje viene de
verdad de Meta se comprueba la firma con el **App Secret** de la misma
aplicación. Si no hay App Secret guardado, los mensajes se aceptan igual y queda
anotado en el registro — pero **cualquiera que sepa la dirección puede inventar
mensajes y meter leads falsos al CRM**, que además se repartirían solos.

Se carga en **Marketing → Redes Sociales → Cuentas**, porque es la credencial de
la aplicación de Meta y ahí viven las de las redes. La pantalla de WhatsApp
avisa cuando falta y dice dónde ponerla.

## Desconectar y volver a conectar

- **Desconectar** — borra las credenciales. **Los números y todo el historial de
  conversaciones se conservan**: no se pierde nada. Es lo que hay que usar para
  hacer pruebas con una cuenta y después pasar a la definitiva.
- **Volver a conectar** — se cargan las credenciales nuevas y la conexión queda
  activa otra vez, con los mismos números y conversaciones de antes.

## Automatización: qué pasa cuando alguien escribe

En la misma pantalla, el bloque **Automatización** define qué hace el sistema
con un mensaje entrante. Antes no hacía nada: guardaba la conversación y, si
nadie abría la bandeja, el cliente se quedaba sin respuesta.

Hay un interruptor general (**Activar la automatización**); si está apagado,
nada de lo de abajo ocurre.

### 1. Avisar por la campanita

Cuando alguien escribe **por primera vez**, sale el aviso. Es lo mínimo para que
ningún mensaje se quede sin ver.

**A quién le llega depende del dueño del número.** Si la línea tiene alguien
asignado, el aviso es **solo suyo**: le escribieron a su número. Si no tiene
dueño —el número central, o uno recién creado—, va a todos los vendedores.

Avisarle a todo el rol convierte cada mensaje en ruido para gente que no lo
puede atender, y hace que nadie se sienta responsable de contestarlo.

> Si el usuario asignado está **inactivo**, el aviso vuelve al rol. Mandarle
> avisos a alguien que ya no entra al sistema es peor que no asignar: el mensaje
> queda con dueño y sin atender.

### 2. Responder automáticamente

Se arma una **lista de respuestas**, y cada una puede llevar una palabra clave:

| Palabra clave | Cuándo sale |
|---|---|
| *(vacía)* | Es el **saludo de bienvenida**: sale solo en el primer contacto |
| `horario` | Cada vez que el mensaje contenga esa palabra |

Se pueden tener varias: un saludo, y respuestas a las preguntas de siempre
("horario", "dirección", "precio"). **Solo se envía la primera que coincida** —
mandar varias seguidas se ve como spam y Meta lo penaliza.

#### Que responda el agente de IA

Dentro de "Responder automáticamente" se puede activar el **agente de IA**. En
vez de comparar palabras clave, entiende la pregunta y contesta.

Se le pone un **nombre** (cómo se presenta) e **indicaciones propias del
negocio** — por ejemplo *"Somos fabricantes, no vendemos al detal"*. Esas
indicaciones se suman a las reglas de fondo, que no se pueden desactivar.

**Qué sabe y qué no:**

- Solo conoce **quién es la empresa, cómo contactarla y qué vende**. Nada de
  ningún cliente. Ver [IA — perfiles de acceso](./ia.md).
- Tiene **prohibido** dar precios de productos a la medida, prometer plazos,
  descuentos o condiciones de pago, e inventar lo que no está en sus datos.
- Si le preguntan por un pedido o una factura, responde que un asesor lo
  revisa — **y no finge tener acceso**.

**Si la IA no contesta** (está caída, sin cupo, lo que sea), salen los mensajes
fijos. Nunca se deja a alguien sin respuesta.

### 3. Crear el lead en el CRM

Si se activa, el mensaje entra al pipeline con `fuente: whatsapp`.

> **Solo se crea si el número es desconocido.** Si ya es un cliente registrado
> o ya tiene un lead abierto, no se crea nada. Sin esa regla, el CRM se
> llenaría de leads repetidos cada vez que un cliente escribe para preguntar
> por su pedido. La comparación se hace por los últimos 10 dígitos, porque los
> teléfonos están escritos de mil formas (con +57, con espacios, con guiones) y
> WhatsApp los manda sin nada de eso.

Se elige **en qué etapa** entra y **cómo se reparte**:

- **El dueño del número manda.** Si el cliente escribió a la línea de un asesor,
  el lead es de ese asesor. Repartirlo por rotación se lo quitaría delante de él.
- Si el número **no tiene dueño**, se usa el reparto configurado, con el mismo
  criterio que los formularios web: **fijo** (siempre al primero de la lista) o
  **rotando** (alternando los seleccionados).

Si no se elige a nadie, el lead se crea sin responsable y el aviso va a los
administradores, para que no quede huérfano en silencio.

### Si algo falla, no se cae el resto

Cada paso está aislado: que falle el aviso no impide crear el lead, y un error
del CRM no deja al cliente sin respuesta. Y si toda la automatización falla, el
mensaje **igual queda guardado** — Meta recibe su confirmación y no reintenta.

## Los números

Debajo de la conexión se administran los números. Cada uno tiene:

| Campo | Para qué |
|---|---|
| **Nombre** | Cómo se identifica dentro del sistema |
| **Número de teléfono** | El número tal como lo ve el cliente |
| **Identificador (Phone Number ID)** | El que da Meta para ese número |
| **Rol** | `central` (el número principal de la empresa) o `asesor` |
| **Quién atiende** | La persona del sistema dueña de esa línea |
| **Activo** | Si puede enviar y recibir |

Un número con conversaciones **no se borra**: se desactiva, para no perder el
historial.

### Quién atiende cada número

No es una etiqueta: **decide quién recibe los avisos de esa línea y quién se
queda con sus leads**. En la lista cada número dice si tiene dueño o no, y el que
no tiene sale marcado.

Al **número central** normalmente no se le asigna nadie: es de la empresa, no de
una persona, y por eso sus mensajes van a todo el rol de vendedores.

En el selector aparecen los usuarios activos **más los que ya tengan un número
asignado aunque estén inactivos**. Sin ese segundo grupo, abrir el número de
alguien que se fue mostraría el campo vacío, y al guardar cualquier otro cambio
se perdería la asignación sin que nadie lo pidiera.

## Si algo falla

| Síntoma | Causa más probable | Qué probar |
|---|---|---|
| "WhatsApp no está conectado todavía" | Falta cargar el token en esta pantalla | — |
| Funcionaba y dejó de funcionar al día siguiente | Se usó un token temporal (vence en 24 h). Hay que generar uno permanente | **Probar** el número |
| Meta rechaza la conexión al probar | El token no corresponde a ese número, o fue revocado | **Probar** el número |
| No llegan los mensajes entrantes | El webhook no está suscrito a `messages`, o el token de verificación no coincide | **Probar el webhook** |
| Los mensajes salen desde otro número | El identificador de Meta está en el número equivocado | **Probar** el número |
| El mensaje sale sin error pero no llega | La ventana de 24 horas | **Enviar mensaje de prueba** |
| Un vendedor no se entera de sus mensajes | El número no tiene dueño, o el asignado está inactivo | Revisar «Quién atiende» |

## Nota técnica

- Tablas: `whatsapp_numeros`, `whatsapp_conversaciones`, `whatsapp_mensajes`.
- Servicios:
  - `App\Services\WhatsAppService` — envío y verificación del webhook.
  - `App\Services\WhatsappDiagnosticoService` — los probadores y el semáforo de
    la pantalla (`estado()`).
  - `App\Services\WhatsappAutomatizacionService` — qué pasa al recibir un
    mensaje. `duenoDelNumero()` es lo que hace que la asignación signifique algo.
  - `App\Services\IA\AgentePublicoService::previsualizar()` — la prueba del
    agente, sin exigir que esté encendido.
- Webhook: `GET/POST /webhook/whatsapp` — el `GET` es el que Meta usa para
  verificar, comparando el token con el guardado. El `POST` valida la firma
  `X-Hub-Signature-256` contra el App Secret de `meta`.
- Las credenciales viven en `configuraciones` a través de
  `App\Support\CredencialesRrss` (clave `whatsapp`), igual que las redes
  sociales. El `.env` sigue funcionando como respaldo.
- `whatsapp_phone_number_id` (el identificador global) **ya no se pide ni se
  escribe**, pero se sigue leyendo: instalaciones anteriores lo tienen cargado y
  borrarlo al guardar las rompería. El que se usa para enviar siempre fue el de
  `whatsapp_numeros.phone_number_id`.
- Los probadores responden JSON y se pintan con
  `resources/js/Components/ResultadoPrueba.vue`, que tiene tres estados: falla,
  aviso y correcto. Una prueba puede funcionar y aun así dejar algo que
  corregir; pintar eso de verde hace que nadie lo lea.
