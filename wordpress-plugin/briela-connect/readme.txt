=== Briela Connect ===
Contributors: briela
Tags: crm, leads, utm, briela
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.0
License: proprietary

Conecta este sitio de WordPress con tu instalación de Briela.

== Description ==

Briela Connect envía los leads que llegan por los formularios del sitio
(Contact Form 7, WPForms, Gravity Forms, Elementor Forms) al CRM de tu
instalación de Briela, con la atribución utm_source / utm_medium /
utm_campaign de la visita que originó el contacto.

No requiere tienda en línea: WooCommerce y Elementor son módulos
opcionales que se activan solo si el sitio los tiene instalados. El
núcleo (leads con atribución UTM) funciona en cualquier sitio de
WordPress.

= Fase actual =

Fase A: token de integración + leads con UTM al CRM. Las siguientes fases
(datos estructurados schema.org, reseñas post-entrega, sincronización de
catálogo con WooCommerce, widgets de Elementor) se agregan en versiones
posteriores — ver docs/plugin-wordpress-contexto.md en el repositorio de
Briela.

== Installation ==

1. Sube la carpeta `briela-connect` a `/wp-content/plugins/`.
2. Actívalo desde el panel de Plugins de WordPress.
3. Entra a Ajustes → Briela Connect y pega la URL y el token generados en
   el ERP, en Configuración → Integraciones → WordPress.

== Changelog ==

= 0.2.0 =
* Un producto con variantes se publica como UN producto variable de WooCommerce; el cliente
  elige la variante («Medida», «Color») en la ficha. Las fichas sueltas por variante de la
  versión anterior pasan solas a borrador.
* Marca, origen y características especiales como atributos visibles del producto.
* Pestaña «Planos y fichas técnicas» con los documentos adjuntos en el ERP.
* Un producto con el precio oculto en el ERP muestra «Cotizar» en vez de una cifra.
* Catálogo en PDF, siempre sin precios: se baja solo del ERP y se ofrece con el shortcode
  `[briela_catalogo_pdf]`.

= 0.1.0 =
* Fase A: token de integración por instalación, leads con atribución UTM
  hacia el CRM.
