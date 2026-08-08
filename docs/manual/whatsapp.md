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
WhatsApp** dice si está **Conectado** o **Sin conectar**, y trae la guía con
todo lo que se necesita.

### Antes de empezar

- Tener una cuenta de **WhatsApp Business API** en Meta.
- Ser **administrador** del Business Manager donde vive el número.

### Los pasos

1. Entrar a [developers.facebook.com](https://developers.facebook.com) y abrir
   (o crear) una aplicación tipo **Empresa**.
2. Agregarle el producto **WhatsApp**.
3. En *WhatsApp → Configuración de la API*, copiar el **Identificador del número
   de teléfono** (Phone Number ID) y generar un **token de acceso**.
4. **Usar un token permanente** de un *Usuario del Sistema* del Business
   Manager. Los que se generan en la pantalla de pruebas **vencen en 24 horas**
   y dejan el sistema mudo al día siguiente.
5. En *WhatsApp → Configuración → Webhooks*, pegar la **URL de devolución de
   llamada** que muestra la pantalla, y un **token de verificación** que uno
   mismo inventa (una contraseña cualquiera, la misma acá y allá).
6. Suscribir el webhook al campo **`messages`**, que es lo que hace llegar los
   mensajes entrantes.
7. Pegar en la pantalla del sistema el identificador, el token y el token de
   verificación, y **Guardar conexión**.

El token se guarda **cifrado** y no se vuelve a mostrar. Para corregir el
identificador sin volver a escribir el token, se deja ese campo vacío.

## Probar, desconectar y volver a conectar

- **Probar conexión** — pregunta a Meta por el número y responde con su nombre
  verificado. Sirve para saber que el token es válido **sin mandarle un mensaje
  a nadie**.
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

Cuando alguien escribe **por primera vez**, les llega el aviso a los vendedores.
Es lo mínimo para que ningún mensaje se quede sin ver.

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

Se elige **en qué etapa** entra y **cómo se reparte**, con el mismo criterio que
los formularios web:

- **Fijo** — siempre al primero de la lista.
- **Rotando** — se van alternando los seleccionados.

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
| **Usuario** | A qué persona del sistema pertenece, si es de asesor |
| **Activo** | Si puede enviar y recibir |

Un número con conversaciones **no se borra**: se desactiva, para no perder el
historial.

## Si algo falla

| Síntoma | Causa más probable |
|---|---|
| "WhatsApp no está conectado todavía" | Falta cargar el token en esta pantalla |
| Funcionaba y dejó de funcionar al día siguiente | Se usó un token temporal (vence en 24 h). Hay que generar uno permanente |
| Meta rechaza la conexión al probar | El token no corresponde a ese número, o fue revocado |
| No llegan los mensajes entrantes | El webhook no está suscrito a `messages`, o el token de verificación no coincide |

## Nota técnica

- Tablas: `whatsapp_numeros`, `whatsapp_conversaciones`, `whatsapp_mensajes`.
- Servicio: `App\Services\WhatsAppService` (envío y verificación del webhook).
- Webhook: `GET/POST /webhook/whatsapp` — el `GET` es el que Meta usa para
  verificar, comparando el token con el guardado.
- Las credenciales viven en `configuraciones` a través de
  `App\Support\CredencialesRrss` (clave `whatsapp`), igual que las redes
  sociales. El `.env` sigue funcionando como respaldo.
