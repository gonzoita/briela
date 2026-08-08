# Redes Sociales — Programador de publicaciones *(nuevo, 24 jul 2026)*

Módulo para programar publicaciones en las redes de la empresa desde un solo
lugar, sin depender de ninguna herramienta externa de pago (Postiz, Ayrshare,
etc.). Cada red se conecta con su propia cuenta/API oficial. Acceso: solo
**administrador**, desde el menú "Marketing → Redes Sociales" (`/rrss`).

## Redes soportadas

| Red | ¿Requiere aprobación externa? | Estado |
|-----|-------------------------------|--------|
| **Instagram** | No (cuenta propia, Standard Access de Meta) | Listo para conectar |
| **Facebook** | No (cuenta propia, Standard Access de Meta) | Listo para conectar |
| **LinkedIn** (página de empresa) | **Sí** — LinkedIn debe aprobar la Community Management API para esta app | Pendiente de aprobación de LinkedIn |
| **Google Business Profile** | **Sí** — Google debe aprobar el acceso a la Business Profile API (~14 días de revisión) | Pendiente de aprobación de Google |

WhatsApp Business **no** está en este módulo — no es una red donde se
"programen publicaciones" (es mensajería directa). Ya existe un módulo aparte
de conversaciones de WhatsApp (ver números en Configuración).

## Cómo conectar una cuenta

1. Entrar a `/rrss/cuentas` ("Gestionar cuentas conectadas").
2. Elegir la red y autorizar con la cuenta de Meta Business / LinkedIn /
   Google que administra las páginas de la empresa.
3. Para Instagram y Facebook, el sistema detecta automáticamente todas las
   páginas administradas (y su Instagram ligado, si tiene) y las deja listas.
4. Para LinkedIn y Google, la conexión solo funcionará una vez que la
   plataforma haya aprobado el acceso solicitado (ver siguiente sección).

## Por qué hay que registrar una aplicación

Ninguna red permite publicar desde otro sistema con solo usuario y contraseña.
Todas exigen registrar una **aplicación** una vez y autorizarla. Automatizar un
inicio de sesión con la contraseña violaría sus términos y puede hacer que
bloqueen la cuenta.

Herramientas como Metricool o Buffer tampoco hacen "conexión directa": usan
estas mismas APIs. La diferencia es que ellas hicieron el registro una sola vez
del lado de ellas.

**La buena noticia:** el trámite es una sola vez por red. Después de eso,
conectar una cuenta es iniciar sesión y autorizar.

## Configurar las credenciales *(desde la interfaz, ago 2026)*

**Ya no hay que entrar al servidor.** En `/rrss/cuentas`, cada red muestra si
está **"Listo para conectar"** o **"Falta configurar"**, y el desplegable
**"¿Primera vez? Cómo dejar lista una red"** trae, para cada una:

- Los requisitos previos.
- El paso a paso del portal correspondiente.
- La **URL de retorno** exacta, con botón de copiar.
- Los campos para pegar el identificador y la clave secreta.

La clave secreta se guarda **cifrada** en `configuraciones` y no se vuelve a
mostrar. Para corregir el identificador sin volver a escribir la clave, se deja
ese campo vacío.

El `.env` sigue funcionando como respaldo: lo guardado desde la interfaz manda,
pero si una instalación ya tenía las variables en el servidor, siguen sirviendo
(ver `.env.example`).

### Paso a paso — Meta (Instagram y Facebook)

No requiere App Review, porque solo se usan páginas propias.

**Antes de empezar:** ser **administrador** de la página de Facebook (no basta
con editor). Para Instagram, la cuenta debe ser **profesional** y estar
**vinculada** a esa página.

1. Entrar a [developers.facebook.com](https://developers.facebook.com) → *Mis
   aplicaciones* → **Crear aplicación**.
2. Tipo **Empresa**, con un nombre reconocible.
3. Agregar el producto **Inicio de sesión con Facebook**.
4. En *Inicio de sesión con Facebook → Configuración*, pegar la URL de retorno
   en **URI de redireccionamiento de OAuth válidos** y guardar. Debe quedar
   **idéntica, carácter por carácter**; si no, la conexión falla con un error
   de `redirect_uri`.
5. Si se va a publicar en Instagram, agregar también el producto de Instagram.
6. En *Configuración → Básica*, copiar el **Identificador de la aplicación** y
   la **Clave secreta**, y pegarlos en la pantalla de cuentas.

### Paso a paso — LinkedIn

La aprobación **no es automática** y puede tardar días.

1. En [developer.linkedin.com](https://developer.linkedin.com), crear una app
   asociada a la página de empresa.
2. En *Products*, solicitar **Community Management API** y esperar aprobación.
3. En *Auth → Redirect URLs*, pegar la URL de retorno.
4. Copiar el **Client ID** y el **Client Secret**.

### Paso a paso — Google Business Profile

Google revisa la solicitud y suele tardar unas dos semanas.

1. Crear un proyecto en [Google Cloud Console](https://console.cloud.google.com).
2. Habilitar **las dos** APIs (si falta una, la conexión falla diciendo que la
   API "no está habilitada"):
   - **My Business Account Management API** — para listar las cuentas.
   - **My Business Business Information API** — para listar las ubicaciones.
3. Configurar la pantalla de consentimiento OAuth y crear credenciales de tipo
   **ID de cliente de OAuth** para aplicación web.
4. En *URI de redirección autorizados*, pegar la URL de retorno.
5. Llenar el [formulario de acceso de Google Business Profile](https://support.google.com/business/contact/api_default)
   con un correo del dominio de la empresa.
6. Copiar el **ID de cliente** y el **Secreto del cliente**.

> **Si sale "Quota exceeded", no es tráfico.** Mientras Google no apruebe la
> solicitud, deja la cuota del proyecto en **cero**, y la API responde
> "Quota exceeded ... Requests per minute" desde la primera llamada. Se puede
> confirmar en *IAM y administración → Cuotas*: si "Requests per minute" está
> en 0, lo único que falta es la aprobación. El sistema ya traduce ese error
> para que no confunda.

## Cómo se publica

- Al crear una publicación se elige el texto, una imagen opcional, la fecha y
  hora, y en qué cuentas debe salir (se puede mandar a varias redes a la
  vez).
- **Instagram exige imagen obligatoria** — sin foto, esa red no se puede
  marcar.
- Un comando programado (`rrss:publicar-programadas`) corre **cada minuto**
  (aprovechando el mismo cron del servidor que ya corre `schedule:run`) y
  publica automáticamente las que ya llegaron a su fecha.
- También hay un botón "Publicar ahora" para saltarse la espera.
- Si falla en alguna cuenta, la publicación queda en estado **parcial** o
  **fallida** y le llega un aviso por la campanita a los administradores
  (tipo `rrss_publicacion_fallida`) para que lo revisen — sigue el mismo
  principio del sistema de no dejar nada colgado sin que alguien se entere.

## Esquema de datos

- `cuentas_rrss` — cada cuenta/página conectada, con su token cifrado.
- `publicaciones_rrss` — el contenido y la fecha programada.
- `publicaciones_rrss_cuentas` — el resultado de la publicación por cada
  cuenta destino (una publicación puede triunfar en Facebook y fallar en
  Instagram, por ejemplo).
- Las imágenes se guardan reutilizando la tabla `archivos` (igual que el
  resto del sistema), con `categoria = 'rrss'`.

## Ojo: el permiso de Meta vence a los ~60 días

El token que Meta entrega al conectar dura unos **60 días**. Hoy el sistema
**no lo renueva solo ni avisa** cuando está por vencer (Google sí tiene
renovación automática; Meta no la tiene implementada).

En la práctica: si un día las publicaciones de Facebook o Instagram empiezan a
fallar, lo primero que hay que revisar es la fecha de vencimiento en
`/rrss/cuentas` y volver a conectar la cuenta. Está anotado como pendiente
abajo.

## Pendiente / siguientes pasos

- **Renovar o avisar del token de Meta antes de que venza** — hoy hay que
  reconectar a mano cuando falla.

- Bandeja unificada de mensajes (DMs/comentarios) — fase futura, distinta a
  este módulo.
- Autorespuestas con IA — depende de que exista la bandeja unificada primero.
- Carrusel de imágenes en Instagram (hoy solo soporta una imagen por post).
