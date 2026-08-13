# Plugin de WordPress de Briela — contexto y arquitectura

> Documento de arranque para el plugin "Briela Connect". Nace en el repo de
> Briela (no en el SGI) porque es un **módulo nuevo**, y la regla
> de `docs/sincronizacion-sgi.md` es que lo nuevo se construye aquí primero.

Fecha: 9 ago 2026.

---

## 1. Qué es

Un plugin de WordPress que conecta el sitio público de cada cliente de Briela
con su instalación del ERP. No asume que el cliente vende en línea: WooCommerce
y Elementor son **módulos opcionales** que el plugin activa solo si los
detecta instalados. El núcleo (captura de leads, SEO estructurado, reseñas)
funciona en cualquier sitio de WordPress, tenga tienda o no.

**Decisiones ya tomadas (9 ago 2026):**

| Decisión | Resuelto como |
|---|---|
| ¿Requiere WooCommerce? | No — agnóstico de tienda, WooCommerce es un módulo opcional |
| ¿Profundidad con Elementor? | Completa: widgets propios + Dynamic Tags + acción "Enviar a Briela" en el Form nativo |
| ¿Licencia propia o incluido? | Incluido en toda instalación Briela — se activa con el token de la instalación, no con un candado aparte |
| Prioridad v1 | Leads a CRM con atribución UTM · Schema.org automático · Reseñas post-entrega |

---

## 2. Las dos piezas

### 2.1 Plugin de WordPress (`briela-connect`)

PHP puro, sin build step obligatorio. Vive en el sitio del cliente.

### 2.2 API del lado Briela (nueva)

Hoy **no existe `routes/api.php`** en Briela (se revisó: Sanctum está
instalado pero no hay rutas API activas). Para este plugin no hace falta
levantar la API general de Sanctum — basta un namespace pequeño y dedicado,
protegido con un token simple por instalación. Si más adelante se construye
la API general, este namespace puede quedar debajo de ella sin romper nada.

---

## 3. Autenticación — sin depender de la Fase 2 de licencias

`BRIELA-PLAN.md` sección 4 diseña el licenciamiento por serial, pero es
**Fase 2, todavía no construida** (`CHANGELOG.md` solo tiene la Fase 0 y 1
hechas). Este plugin no puede esperar a eso, así que usa su propio mecanismo,
diseñado para no chocar con la Fase 2 cuando llegue:

- Nueva pantalla en el ERP: **Configuración → Integraciones → WordPress**.
  Genera un token opaco (`integracion_wordpress_token`, guardado en
  `configuraciones`) y muestra la URL base del sitio a copiar en el plugin.
- El plugin guarda `url_base` + `token` en sus opciones de WordPress.
- Toda llamada del plugin al ERP manda el token en `Authorization: Bearer`.
- Middleware nuevo `VerificarTokenIntegracion` en el ERP compara contra el
  valor guardado. Como Briela **no es multi-tenant** (una instalación por
  cliente, ver `project-briela-saas`), un solo token por instalación alcanza.
- **Camino de mejora futuro:** cuando exista la Fase 2, este token puede
  pasar a derivarse del serial en vez de generarse aparte — no diseñar nada
  aquí que lo impida.

---

## 4. Alcance de la v1

### 4.1 Núcleo — siempre activo

**Leads a CRM con atribución UTM**
- El plugin engancha el envío de formularios (Elementor Pro Form, y
  adaptadores para Contact Form 7 / WPForms / Gravity Forms) y hace
  `POST /api/wp/leads` con nombre, teléfono, mensaje, página de origen y
  `utm_source` / `utm_medium` / `utm_campaign` capturados por JS al aterrizar.
- En el ERP: crea un `CrmLead` con `origen = 'sitio_web'` y esos UTM guardados
  para que el módulo de Informes pueda medir qué canal trae clientes reales.

**Datos estructurados (schema.org) automáticos**
- El plugin pide `GET /api/wp/productos` (cron cada X horas, cacheado en
  transient) y con eso imprime JSON-LD de `Product` en `wp_head` de las
  páginas correspondientes (de WooCommerce si existe, o vía shortcode
  `[briela_producto id="X"]` si el cliente no tiene tienda y solo usa fichas
  informativas).
- Si el cliente tiene varias sedes, agrega `LocalBusiness` por sede — ojo:
  la app del cliente debe alimentar bien `Sede` (dirección, teléfono) para
  que esto no salga vacío.
- No reemplaza Yoast/RankMath: completa lo que ellos no saben (el ERP es la
  fuente de precio/stock reales).

**Reseñas / reputación post-entrega**
- Se dispara del lado del ERP (Listener sobre `Remision` al quedar firmada),
  no necesita ida y vuelta con WordPress salvo que el cliente quiera que el
  correo salga con la plantilla/SMTP del sitio — eso queda como opción, no
  como requisito de v1.

### 4.2 Módulo WooCommerce (opcional)

Se activa solo si `class_exists('WooCommerce')`. Retoma lo ya definido en la
conversación anterior:
- Sync de catálogo (nombre, precio, stock, imágenes, variantes) SGI→Woo.
- Webhook de orden completada → `POST /api/wp/pedidos` → descuenta stock real
  vía `Producto::registrarMovimiento('venta', ...)`.

### 4.3 Módulo Elementor (opcional)

Se activa solo si `did_action('elementor/loaded')`.
- **Widgets**: Catálogo Briela, Cotizador rápido, Seguimiento de pedido,
  Verificación de certificado, Casos de éxito (carrusel).
- **Dynamic Tags** (requieren Elementor **Pro**): precio y stock en vivo de
  un producto, para usarlos en cualquier landing sin sync completo.
- **Acción de formulario** (requiere Elementor Pro): "Enviar a Briela" como
  action-after-submit del Form widget nativo — igual a como Elementor ya
  trae Mailchimp/ActiveCampaign de fábrica.
- Si el sitio no tiene Elementor Pro, los widgets básicos igual funcionan
  (son solo lectura de datos); lo que se cae con gracia es dynamic tags y la
  acción de formulario.

---

## 5. Estructura de archivos propuesta

### 5.1 Plugin de WordPress

```
briela-connect/
  briela-connect.php            # bootstrap, activation hooks
  includes/
    class-settings.php          # pantalla admin: URL + token
    class-api-client.php        # wrapper de wp_remote_*, reintentos, log de errores
    class-cron.php              # sync de catálogo, heartbeat
    class-webhooks.php          # REST routes que reciben eventos de WooCommerce
    class-schema.php            # emite JSON-LD
    integrations/
      class-woocommerce.php     # solo se carga si WooCommerce existe
      class-elementor.php       # solo se carga si Elementor existe
      class-forms-bridge.php    # adaptadores: Elementor Pro Forms, CF7, WPForms, Gravity
  elementor/
    widgets/
      class-widget-catalogo.php
      class-widget-cotizador.php
      class-widget-seguimiento.php
      class-widget-certificado.php
      class-widget-casos-exito.php
    dynamic-tags/
      class-tag-precio.php
      class-tag-stock.php
  admin/
    settings-page.php
  assets/
    admin.css / admin.js
```

### 5.2 Lado Briela (Laravel)

- `routes/api.php` (nuevo, registrado en `bootstrap/app.php`).
- `app/Http/Controllers/Api/WordpressIntegracionController.php`
  - `GET  /api/wp/productos` — vendibles, con stock, variantes, imágenes.
  - `POST /api/wp/leads` — crea `CrmLead` con UTM.
  - `POST /api/wp/pedidos` — recibe venta de WooCommerce, descuenta stock.
- `app/Http/Middleware/VerificarTokenIntegracion.php`.
- Config nueva en `configuraciones`: `integracion_wordpress_token`.
- Vista nueva: `resources/js/Pages/Configuracion/Integraciones/Wordpress.vue`.

---

## 6. Fases de construcción

| Fase | Contenido | Estado |
|---|---|---|
| **A** | Núcleo: token de integración, leads con UTM → CRM | **Hecha** (9 ago 2026) |
| **C.1** | Publicación de catálogo: marca en el ERP, `GET /api/wp/catalogo`, sincronización del plugin, productos de WooCommerce o fichas propias | **Hecha** (13 ago 2026) |
| **B** | Schema.org automático + reseñas post-entrega | Pendiente |
| **C.2** | Vuelta del pedido de WooCommerce → descontar inventario (`POST /api/wp/pedidos`) | Pendiente |
| **D** | Módulo Elementor completo (widgets + dynamic tags + acción de formulario) | Pendiente |
| **E** *(futuro, no en v1)* | Eventos de conversión a Meta/Google Ads, recuperación de cotizaciones sin aprobar, chat de IA embebido | Pendiente |

### C.1 — cómo quedó la publicación de catálogo

Se adelantó a la fase B porque es lo que el negocio pidió primero. Las decisiones
tomadas, todas visibles en el sitio del cliente:

- **La marca vive en el ERP**, no en WordPress: `productos.publicado_web` y
  `ensambles.publicado_web`. Un ensamble viaja como un producto más, con
  `precio_es_desde` en verdadero.
- **El sitio llama al ERP, no al revés.** El aviso inmediato
  (`POST {sitio}/wp-json/briela/v1/sincronizar`) es un lujo que puede fallar sin
  consecuencias; la sincronización horaria del plugin es la que garantiza.
- **Lista completa, no diario de cambios.** `GET /api/wp/catalogo` devuelve todo lo
  publicado: un aviso perdido no deja el sitio mintiendo para siempre.
- **Briela manda precio y existencias; el sitio manda texto y fotos** después de la
  primera vez.
- **Una ficha por unidad vendible**: las variantes se publican una a una, el padre no.
- **Retirar pasa a borrador**, nunca borra: el posicionamiento y el texto del sitio
  valen más que la limpieza.
- La URL del sitio **se aprende sola** (cabecera `X-Briela-Sitio` en cada lectura).

Detalle funcional en `docs/manual/publicar-en-la-web.md`.

---

## 7. Graphify — antes de leer código a mano

Briela tiene un grafo de conocimiento generado en `graphify-out/` (5161 nodos,
8699 relaciones, generado el 8 ago 2026 — vigente, un día antes de este
documento). Dos cosas importantes que **corrigen lo heredado del SGI**:

- **No es un binario de terminal.** `graphify query "..."` no existe como
  comando — así lo tenía escrito el `CLAUDE.md` del SGI, y está mal. En Briela
  es el **skill de Claude Code `/graphify`**, se invoca desde el chat.
- `graphify-out/` está en `.gitignore` (se regenera, no se versiona).

**Cómo usarlo para este trabajo:**
- Antes de explorar a mano dónde vive `Producto`, `CrmLead`, `Remision`,
  `Configuracion`, o cómo se relacionan, usa `/graphify` con la pregunta
  puntual (ej. "¿qué controladores tocan Producto y CrmLead?") — normalmente
  da un subgrafo más chico y más útil que grep a ciegas.
- Después de crear los archivos nuevos del plugin/API (Fase A en adelante),
  regenerar con `/graphify` para que el grafo quede al día — un grafo viejo
  después de mover estructura es peor que no tenerlo.

---

## 8. Decisiones que quedan abiertas

- ¿Un plugin único con módulos que se activan solos, o varios plugins chicos?
  Recomendado: uno solo — más fácil de vender e instalar en un solo paso.
- Nombre comercial: propuesto **"Briela Connect"**.
- Distribución del plugin (zip desde el superadmin vs. repositorio propio de
  actualizaciones) depende de la Fase 5 del plan general de Briela ("botón de
  actualizar estilo WordPress") — no bloquea empezar Fase A.
- Confirmar qué plugin de formularios usan los primeros clientes piloto
  (Contact Form 7 / WPForms / Gravity / Elementor Pro Forms), para priorizar
  el primer adaptador de `forms-bridge`.

---

## 9. Reglas que aplican también aquí

- Español colombiano neutro, prohibido el voseo — UI, código, commits.
- Se construye en `C:\laragon\www\briela`, repo `gonzoita/briela`. **No tocar
  el SGI** en este trabajo.
- No asumir que existe la API general con Sanctum ni el licenciamiento por
  serial (Fase 2) — ambos siguen sin construirse.
