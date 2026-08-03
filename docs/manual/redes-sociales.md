# Redes Sociales — Programador de publicaciones *(nuevo, 24 jul 2026)*

Módulo para programar publicaciones en las redes de Interfrigo desde un solo
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
   Google que administra las páginas de Interfrigo.
3. Para Instagram y Facebook, el sistema detecta automáticamente todas las
   páginas administradas (y su Instagram ligado, si tiene) y las deja listas.
4. Para LinkedIn y Google, la conexión solo funcionará una vez que la
   plataforma haya aprobado el acceso solicitado (ver siguiente sección).

## Credenciales pendientes de crear (Diego)

Antes de que el módulo funcione en producción, hay que crear estas apps y
poner las credenciales en el `.env` del servidor (ver `.env.example` para los
nombres exactos de las variables: `META_APP_ID`, `META_APP_SECRET`,
`LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET`, `GOOGLE_RRSS_CLIENT_ID`,
`GOOGLE_RRSS_CLIENT_SECRET`, y las `*_REDIRECT_URI` de cada una):

1. **Meta for Developers** (developers.facebook.com) → crear una app tipo
   "Business", agregar el producto "Facebook Login" e "Instagram Graph API".
   No requiere App Review porque solo se usan cuentas propias.
2. **LinkedIn Developer Portal** (developer.linkedin.com) → crear una app,
   asociarla a la página de empresa de Interfrigo, y solicitar el producto
   **"Community Management API"**. La aprobación no es automática.
3. **Google Cloud Console** → crear un proyecto, habilitar "My Business
   Business Information API" y "My Business API", y llenar el
   [formulario de acceso de Google Business Profile](https://support.google.com/business/contact/api_default)
   con un correo del dominio `@interfrigo.com.co` y el sitio web activo.

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

## Pendiente / siguientes pasos

- Bandeja unificada de mensajes (DMs/comentarios) — fase futura, distinta a
  este módulo.
- Autorespuestas con IA — depende de que exista la bandeja unificada primero.
- Carrusel de imágenes en Instagram (hoy solo soporta una imagen por post).
