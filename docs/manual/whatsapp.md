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
