# Guía: conectar WhatsApp, Instagram y Facebook a Briela

Guía corta, en el orden en que hay que hacerlo. Al final hay un **prompt para Gemini** que te
acompaña paso a paso mientras la sigues.

> **Una regla antes de empezar:** nunca pegues en Gemini, en un chat ni en un correo el
> **App Secret** ni los **tokens**. Son las llaves de tu cuenta. Se pegan solo en Briela. Si
> Gemini te los pide, no se los des.

---

## 1. Lo que necesitas antes de empezar

| Qué | Para qué |
|---|---|
| Una cuenta de **Facebook** que sea **administradora** de la página de la empresa | Meta solo deja conectar páginas a quien las administra (no basta con ser editor) |
| Una **página de Facebook** de la empresa | Instagram y Messenger cuelgan de ella |
| Una cuenta de **Instagram profesional** (empresa o creador) **vinculada a esa página** | Una cuenta personal no sirve |
| Un **Business Manager** (business.facebook.com) | Ahí vive el token permanente de WhatsApp |
| Un **número de teléfono nuevo para WhatsApp**, que NO esté en WhatsApp ni en WhatsApp Business del celular | La API exige un número libre |
| Tu Briela con **dirección pública con HTTPS** (por ejemplo `https://sistema.briela.app`) | Meta llama a Briela desde internet: a `localhost` nunca llega |
| En Briela, entrar como **Administrador** | Es quien ve las pantallas de configuración |

> **WhatsApp normal y «WhatsApp Business» (la app de la tienda) NO sirven.** Hace falta la
> **WhatsApp Business API**, que se crea en Meta.

---

## 2. Las direcciones que vas a pegar en Meta

Cámbialas por la dirección real de tu instalación. Briela las muestra con botón de copiar en
cada pantalla; es mejor copiarlas de ahí que escribirlas.

| Para qué | Dirección | Dónde se pega en Meta |
|---|---|---|
| Retorno al conectar Facebook e Instagram | `https://sistema.briela.app/rrss/cuentas/callback/meta` | Inicio de sesión con Facebook → Configuración → **URI de redireccionamiento de OAuth válidos** |
| Webhook de **WhatsApp** | `https://sistema.briela.app/webhook/whatsapp` | WhatsApp → Configuración → Webhooks |
| Webhook de **Instagram y Facebook** | `https://sistema.briela.app/webhook/meta` | Webhooks → Instagram, y Webhooks → Página |

Las dos de webhook usan **el mismo token de verificación**, que lo inventas tú (el botón
**Generar** de Briela crea uno). Es la misma aplicación de Meta, así que es una sola contraseña.

---

## 3. Paso a paso, en orden

### Paso 1 — Crear la aplicación de Meta (una sola vez)

1. Entra a **developers.facebook.com** → **Mis aplicaciones** → **Crear aplicación**.
2. Tipo **Empresa** (Business). Ponle un nombre reconocible, por ejemplo «Briela — Mi Empresa».
3. Asóciala a tu **Business Manager**.
4. Agrega estos productos: **Inicio de sesión con Facebook**, **WhatsApp**, **Messenger** e
   **Instagram**.
5. En **Configuración → Básica**, copia el **Identificador de la aplicación** (App ID) y la
   **Clave secreta** (App Secret).

> Meta cambia los nombres de los menús seguido. Si algo no está donde dice esta guía, dile a
> Gemini lo que ves en pantalla y te ubica.

### Paso 2 — Cargar el App ID y el App Secret en Briela

**Marketing → Redes Sociales → Cuentas**, en el bloque de **Meta**: pega el App ID y el App
Secret y guarda. La URL de retorno ya aparece ahí.

> **Esto es lo más urgente.** Sin el App Secret **no entra ningún mensaje**, ni de WhatsApp ni
> de las redes: Briela rechaza todo lo que no puede verificar que venga de Meta.

### Paso 3 — WhatsApp

Pantalla de Briela: **Configuración → Números de WhatsApp**. Arriba tiene un semáforo que dice
exactamente qué falta, en orden.

1. **Token permanente.** En Business Manager → **Configuración del negocio → Usuarios →
   Usuarios del sistema** crea uno, dale acceso a tu aplicación y a tu cuenta de WhatsApp, y
   genera el token con los permisos `whatsapp_business_messaging` y
   `whatsapp_business_management`. Pégalo en Briela (paso 1 de la pantalla).
   > **No uses el token de la pantalla de pruebas:** vence en 24 horas y al día siguiente el
   > sistema queda mudo. Es la falla número uno.
2. **Token de verificación del webhook.** En Briela pulsa **Generar**, y guarda.
3. **Webhook en Meta.** En **WhatsApp → Configuración → Webhooks** pega la dirección
   `/webhook/whatsapp` y el token del punto 2. Luego **suscríbete al campo `messages`**.
   > Sin suscribir `messages` la dirección responde pero no llega nada. Es el segundo error
   > más común.
4. **El número.** En **WhatsApp → Configuración de la API** copia el **Identificador del número
   de teléfono** (Phone Number ID) y agrégalo en Briela, en «Números registrados».
5. **(Opcional) WABA ID.** En la misma pantalla de Meta, debajo del número. Sirve para traer
   las plantillas aprobadas, que son lo único que puedes enviar pasadas 24 horas del último
   mensaje del cliente.
6. **Probar.** En Briela usa **Probar el webhook**, **Probar** el número y **Enviar mensaje de
   prueba**. Este último recorre todo.

### Paso 4 — Instagram y Facebook

Pantalla de Briela: **Marketing → Redes Sociales → Cuentas**.

1. En Meta, **Inicio de sesión con Facebook → Configuración**, pega la URL de retorno del
   cuadro de arriba, **idéntica, carácter por carácter**. Si no, la conexión falla con un
   error de `redirect_uri`.
2. En Briela pulsa **Conectar** en Facebook/Instagram, inicia sesión con la cuenta que
   administra la página, y **acepta todos los permisos** que te pide (incluidos los de
   mensajes y comentarios). Briela detecta sola tus páginas y el Instagram ligado.
   > Si ya habías conectado esas cuentas antes del 7 oct 2026, **reconéctalas una vez**: los
   > permisos de mensajería se piden en la autorización y las cuentas viejas no los tienen.
   > Mientras no lo hagas, se publica igual y no llegan mensajes.
3. **Webhook en Meta.** Pega `/webhook/meta` con el mismo token de verificación del paso 3 y
   suscribe estos campos:

   | Red | Campos |
   |---|---|
   | Instagram | `messages` y `comments` |
   | Página de Facebook | `messages` y `feed` |

### Paso 5 — Encender la automatización

En Briela, en el bloque **Cuando alguien escribe** (Redes Sociales → Cuentas) y en
**Números de WhatsApp → Automatización**:

- **Avisar por la campanita:** sí.
- **Que conteste el agente de IA:** cuando ya lo tengas calibrado (ver **Configuración →
  Agentes** y marcar Instagram/Facebook/WhatsApp).
- **Crear el lead en el CRM:** sí, elige etapa y reparto.
- **Contestar comentarios públicos con IA:** déjalo **apagado** al principio. Una respuesta
  automática en un comentario la lee cualquiera y queda colgada ahí.

### Paso 6 — Comprobar de punta a punta

1. Desde **otro celular**, escribe por WhatsApp al número nuevo.
2. Escribe un mensaje directo a tu Instagram, y otro a tu página de Facebook.
3. Comenta una publicación.
4. En Briela abre **Bandeja** (menú Ventas): deben aparecer las cuatro cosas, con el canal en
   el renglón. Responde una.

---

## 4. Si algo falla

| Síntoma | Causa más probable | Qué hacer |
|---|---|---|
| No aparece **Bandeja** en el menú | Tu rol no tiene el permiso | Configuración → Usuarios y roles → tu rol → marca «Bandeja de mensajes» |
| Aviso rojo «Falta el App Secret» | No está cargado | Redes Sociales → Cuentas → bloque Meta |
| Funcionaba y dejó de funcionar al día siguiente | Se usó un token temporal de WhatsApp | Generar uno **permanente** de un Usuario del Sistema |
| No llegan mensajes entrantes | El webhook no está suscrito al campo, o el token de verificación no coincide | Revisar «Suscribir» en Meta y usar **Probar el webhook** |
| Error de `redirect_uri` al conectar | La URL de retorno no es idéntica | Copiarla desde Briela, sin espacios |
| «Página no encontrada» al conectar Instagram | La cuenta no es profesional o no está vinculada a la página | Convertirla y vincularla en Instagram → Configuración |
| El mensaje sale «enviado» pero no llega | Pasaron más de 24 horas desde que el cliente escribió | Enviar una **plantilla aprobada** (WhatsApp), o esperar a que escriba (Instagram/Messenger) |
| Te llegan los mensajes **tuyos** pero no los de clientes reales | La aplicación está en modo **Desarrollo**, o faltan permisos avanzados | Ver nota de abajo |
| A los ~60 días fallan Facebook/Instagram | El permiso de Meta venció | Briela avisa a los 15 días; reconectar en Redes Sociales → Cuentas |
| Un archivo no sale por Instagram/Messenger | Meta descarga el archivo desde tu servidor | Solo funciona si la instalación tiene dirección pública |

> **Sobre el modo Desarrollo.** Una aplicación de Meta nace en modo **Desarrollo**: solo
> reacciona a personas que tienen un rol en la aplicación (administradores, probadores). Para
> que lleguen mensajes de clientes reales hay que pasarla a modo **Publicada (Live)** y,
> según el permiso, Meta puede pedir **acceso avanzado** o verificación del negocio. Es la
> parte que más cambia en Meta y la que más conviene preguntarle a Gemini mirando tu
> pantalla. Prueba siempre primero con tu propio usuario.

LinkedIn y Google Business Profile son aparte y dependen de que **ellos aprueben** el acceso
(LinkedIn: días; Google: unas dos semanas). No los prometas en la primera versión.

---

## 5. Prompt para Gemini

Copia **todo** el bloque, pégalo como primer mensaje en Gemini, y luego cuéntale en qué paso
vas. Puedes adjuntarle capturas de pantalla de lo que ves en Meta.

```text
Eres mi guía paciente para conectar las redes sociales de mi empresa a un ERP llamado
Briela. Hablo español de Colombia: respóndeme siempre en español colombiano neutro,
tuteándome, sin voseo (nada de "vos", "tenés", "mirá"). Soy una persona que no es técnica:
explícame como si fuera la primera vez, con frases cortas.

CÓMO QUIERO QUE TRABAJES
1. Dame UN solo paso a la vez. Cuando termine, te digo "listo" y me das el siguiente.
2. Antes de cada paso, dime en qué pantalla de Meta debo estar y qué botón buscar.
3. Los menús de Meta cambian seguido. Si no estás seguro de dónde está algo, NO lo inventes:
   dímelo y pídeme que te cuente o te muestre una captura de lo que veo.
4. Si te adjunto una captura, léela y dime exactamente dónde hacer clic.
5. Si algo me da error, pídeme el texto exacto del error antes de sugerir una solución.
6. Al terminar cada bloque, dime cómo comprobar que quedó bien.

REGLA DE SEGURIDAD (MUY IMPORTANTE)
Nunca me pidas que te escriba aquí el App Secret, ni tokens, ni contraseñas. Esos datos se
pegan solamente dentro de Briela. Si te los llego a pegar por error, avísame que no debo
hacerlo y que los regenere en Meta.

LO QUE QUIERO LOGRAR
Que los mensajes que mis clientes me escriban por WhatsApp, Instagram y Facebook (mensajes
directos y comentarios) lleguen a Briela, y poder contestarlos desde allá.

MIS DATOS (los voy a completar yo si hace falta)
- Dirección de mi Briela: https://sistema.briela.app  (cambiar si es otra)
- Tengo cuenta de Facebook administradora de la página de la empresa: sí / no
- Mi Instagram es profesional y está vinculado a la página: sí / no
- Tengo Business Manager (business.facebook.com): sí / no
- Tengo un número de teléfono libre para WhatsApp (que no esté en la app del celular): sí / no

LAS DIRECCIONES QUE HAY QUE PEGAR EN META
- Retorno de inicio de sesión (Facebook/Instagram):
  https://sistema.briela.app/rrss/cuentas/callback/meta
  Va en: Inicio de sesión con Facebook > Configuración > "URI de redireccionamiento de OAuth
  válidos". Tiene que quedar IDÉNTICA, carácter por carácter.
- Webhook de WhatsApp: https://sistema.briela.app/webhook/whatsapp
  Hay que suscribirlo al campo "messages".
- Webhook de Instagram y Facebook: https://sistema.briela.app/webhook/meta
  Instagram: suscribir "messages" y "comments".
  Página de Facebook: suscribir "messages" y "feed".
- El "token de verificación" del webhook lo invento yo; es EL MISMO para los dos webhooks.
  Briela tiene un botón "Generar" que crea uno.

LOS PASOS, EN ESTE ORDEN
1. Crear una aplicación de Meta tipo "Empresa" en developers.facebook.com, asociada a mi
   Business Manager, y agregar los productos: Inicio de sesión con Facebook, WhatsApp,
   Messenger e Instagram.
2. En Configuración > Básica copiar el Identificador de la aplicación y la Clave secreta (App
   Secret) y pegarlos en Briela (Marketing > Redes Sociales > Cuentas). Sin el App Secret no
   entra ningún mensaje.
3. WhatsApp: crear un TOKEN PERMANENTE de un "Usuario del sistema" del Business Manager (el
   token de la pantalla de pruebas vence en 24 horas y NO sirve), con los permisos
   whatsapp_business_messaging y whatsapp_business_management. Luego copiar el
   "Identificador del número de teléfono" y, si quiero plantillas, el "Identificador de la
   cuenta de WhatsApp Business" (WABA ID). Pegarlos en Briela (Configuración > Números de
   WhatsApp).
4. Configurar el webhook de WhatsApp con su dirección y el token de verificación, y
   suscribirlo a "messages".
5. Instagram y Facebook: pegar la URL de retorno en Meta, ir a Briela y pulsar "Conectar",
   iniciar sesión con la cuenta que administra la página y aceptar TODOS los permisos
   (incluidos los de mensajes y comentarios).
6. Configurar el webhook de Instagram y Facebook con su dirección y suscribir los campos.
7. Pasar la aplicación de modo "Desarrollo" a "Publicada (Live)" si quiero recibir mensajes
   de clientes reales, y explicarme si Meta me pide "acceso avanzado" o verificación del
   negocio para algún permiso.
8. Probar de punta a punta: escribir desde otro celular por WhatsApp, por Instagram y por
   Facebook, y comprobar que aparece en la "Bandeja" de Briela.

ERRORES COMUNES QUE DEBES VIGILAR
- Usar el token temporal de WhatsApp (vence en 24 horas).
- Olvidar suscribir los campos del webhook (la dirección "funciona" pero no llega nada).
- Que la URL de retorno no quede idéntica.
- Que Instagram no sea una cuenta profesional o no esté vinculada a la página.
- Que la aplicación siga en modo Desarrollo (solo reacciona a personas con rol en la app).
- Escribir a un cliente pasadas 24 horas de su último mensaje: en WhatsApp solo se puede con
  una plantilla aprobada; en Instagram y Messenger no se puede escribir primero.

Empieza preguntándome lo que necesites de "MIS DATOS" y luego dame el Paso 1.
```

---

Más detalle en `docs/manual/whatsapp.md`, `docs/manual/redes-sociales.md` y
`docs/manual/bandeja.md`.
