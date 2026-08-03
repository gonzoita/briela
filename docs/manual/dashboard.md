# Dashboard

Es la primera pantalla al entrar. Está pensada para responder dos preguntas en
orden: **¿qué necesita que yo actúe hoy?** y después **¿cómo va todo?**

## Los números son los de la sede activa

Hasta julio de 2026 el dashboard sumaba las tres sedes aunque el selector del
encabezado dijera "Bogotá". Era el único módulo que no respetaba la sede
activa, así que los conteos nunca cuadraban con lo que salía al entrar a cada
listado.

Ya no. Si el encabezado dice Bogotá, todo lo que ves es de Bogotá. Si eliges
"Todas las sedes", ves el consolidado. El subtítulo bajo el saludo siempre dice
sobre cuál sede son los números que estás mirando.

## Requiere tu atención

Es el bloque de arriba y **solo aparece cuando hay algo**. Un bloque fijo que
diga "todo bien" se vuelve paisaje y la gente deja de mirarlo.

Hoy avisa de dos cosas:

- **OPs con la entrega vencida** — pasó la fecha estimada y todavía no se
  despachan. Al tocarlo abre el listado con exactamente esas OPs.
- **Cotizaciones por vencer** — enviadas a las que se les acaba la validez en
  los próximos 7 días. Es plata que se puede perder por no llamar a tiempo.

Cada aviso lleva al listado ya filtrado, así que el número que ves en el
dashboard es el mismo que cuentas al entrar.

## Tarjetas de conteo

Producción (en producción, por confirmar, control de calidad, despachadas del
mes) y Cotizaciones (enviadas, del mes). Cada una abre su listado filtrado.

Las alertas de mantenimiento aparecen solo para administrador y jefe de
producción, y solo si hay equipos vencidos o próximos a vencer.

## Qué ve cada rol

El dashboard se recorta según los permisos:

- Quien no puede ver todas las OPs solo cuenta las suyas.
- Las tarjetas de cotizaciones son para administrador y vendedor.
- Un vendedor cuenta solo sus propias cotizaciones.
- Las alertas de mantenimiento son para administrador y jefe de producción.

## Detalles de uso

**Pull-to-refresh**: en el celular, hala hacia abajo desde arriba del todo para
recargar los números.

**Navegación instantánea**: las tarjetas ya no recargan la aplicación completa
en cada toque. Antes cada tarjeta era un enlace normal y volvía a bajar todo el
JavaScript; ahora navegan dentro de la misma sesión, que en celular con datos
móviles se nota bastante.
