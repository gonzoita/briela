# Conexiones con servicios externos

Todo lo que el sistema conecta con el mundo de afuera, en un solo lugar: para
qué sirve cada una, dónde se configura, si depende de una aprobación ajena y
dónde está su manual.

## Resumen

| Conexión | Para qué | Dónde se configura | ¿Aprobación externa? |
|---|---|---|---|
| **Correo (SMTP)** | Enviar avisos y cotizaciones por email | Configuración → Correo | No |
| **Inteligencia artificial** | El asistente, redacción e imágenes | Configuración → IA | No |
| **Instagram / Facebook** | Publicar en las redes de la empresa | Marketing → Redes Sociales → Cuentas | No |
| **LinkedIn** | Publicar en la página de empresa | Marketing → Redes Sociales → Cuentas | **Sí**, LinkedIn |
| **Google Business Profile** | Publicar novedades en la ficha de Google | Marketing → Redes Sociales → Cuentas | **Sí**, Google (~2 semanas) |
| **WhatsApp** | Enviar y recibir mensajes | Configuración → Números de WhatsApp | No, pero exige WhatsApp Business **API** |
| **WordPress** (plugin Briela Connect) | Leads del sitio al CRM y publicar el catálogo en la web | Configuración → Integraciones → WordPress | No |
| **Google Drive** | *(en retirada)* archivos antiguos | `.env` del servidor | No |

## Cómo funcionan todas

Desde agosto de 2026 las conexiones siguen el mismo patrón, para que ninguna
obligue a entrar al servidor:

- **Se configuran desde la interfaz.** Lo que se guarda ahí manda; el `.env`
  queda como respaldo para instalaciones antiguas.
- **Las claves se guardan cifradas** y no se vuelven a mostrar. Para corregir un
  identificador sin reescribir la clave, se deja ese campo vacío.
- **La pantalla dice el estado**: "Listo para conectar" / "Conectado" o "Falta
  configurar".
- **Traen la guía adentro**: los pasos del portal correspondiente y las URLs que
  hay que pegar allá, con botón de copiar.

## Dónde está el manual de cada una

- **Correo (SMTP)** → [Notificaciones](./notificaciones.md). Tiene botón de
  envío de prueba.
- **Inteligencia artificial** → [IA y asistente](./ia.md).
- **Redes sociales** (Instagram, Facebook, LinkedIn, Google Business) →
  [Redes Sociales](./redes-sociales.md).
- **WhatsApp** → [WhatsApp](./whatsapp.md).
- **WordPress** → [Publicar productos y ensambles en el sitio web](./publicar-en-la-web.md).
  Es la única conexión que **no** depende de un portal ajeno: el token lo genera
  Briela y se pega en el plugin, y es el sitio el que llama al ERP.

## Lo que ninguna conexión permite

**Ninguna red social ni WhatsApp deja publicar con solo usuario y contraseña.**
Todas exigen registrar una aplicación una vez y autorizarla. Automatizar un
inicio de sesión con la contraseña violaría sus términos y puede hacer que
bloqueen la cuenta.

Herramientas como Metricool o Buffer tampoco hacen "conexión directa": usan
estas mismas APIs; solo que hicieron el registro una vez del lado de ellas.

## Probar y desconectar

Las conexiones que se pueden probar **sin afectar a nadie**:

- **Correo** — manda un mensaje de prueba a la dirección que se indique.
- **WhatsApp** — pregunta a Meta por el número y devuelve su nombre verificado,
  sin enviarle un mensaje a ningún cliente.

Y las que se pueden desconectar y volver a conectar:

- **Redes sociales** — cada cuenta se desconecta por separado. Si tiene
  publicaciones asociadas, se desactiva en vez de borrarse, para no perder el
  historial; después se puede reactivar.
- **WhatsApp** — se borran las credenciales, pero **los números y todas las
  conversaciones se conservan**. Es lo que hay que usar para probar con una
  cuenta y luego pasar a la definitiva.

## Vencimientos que hay que tener presentes

| Conexión | Vence | ¿El sistema lo renueva? |
|---|---|---|
| **Meta** (Instagram/Facebook) | ~60 días | **No.** Hay que reconectar a mano |
| **WhatsApp** | El token temporal, a las 24 h | **No.** Usar siempre un token permanente de un Usuario del Sistema |
| **Google Business** | Se renueva solo | Sí, con el *refresh token* |
| **LinkedIn** | ~60 días | No |

Si un día las publicaciones o los mensajes empiezan a fallar sin haber cambiado
nada, **lo primero que hay que mirar es si venció el permiso**.

## Nota técnica

- Credenciales: `App\Support\CredencialesRrss` (pese al nombre, también maneja
  WhatsApp). Guarda en la tabla `configuraciones`, cifrando los secretos, y cae
  al `.env` cuando no hay nada guardado.
- Google Drive es la excepción: sigue leyéndose desde el `.env` porque está en
  retirada — ya no se sube nada nuevo ahí, solo se leen y borran los archivos
  antiguos. Ver [Marca](./marca.md) para el motivo por el que se salió de Drive.
