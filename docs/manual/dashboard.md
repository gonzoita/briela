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

## Ya no hay tarjetas fijas de conteo

Hasta el 21 de agosto de 2026 el dashboard traía seis tarjetas escritas en el código
—producción, por confirmar, control de calidad, despachadas del mes, cotizaciones enviadas,
cotizaciones del mes— y debajo una tabla con las últimas cinco OPs. Se retiraron.

El motivo es el mismo que dio origen a las secciones: **lo que una empresa necesita ver al
entrar no lo puede decidir el código**. Un taller de dos personas y uno de cuarenta no miran lo
mismo, y ninguno de los dos miraba exactamente esas seis cifras. Cualquiera de ellas se
reconstruye como gráfico —«OPs, conteo, agrupado por estado» da las cuatro primeras de una
sola vez—, y quien no la quiera ya no la tiene encima.

Lo que sí quedó fijo es lo que **no es un tablero sino un aviso**: el bloque de arriba y las
alertas de mantenimiento. Un aviso aparece solo cuando hay algo y pide una acción; no compite
con lo que tú configuraste.

Los accesos rápidos —Nueva OP, Verificar, Nueva Cot., Seguimiento— también se quedan: son
navegación, no datos.

## Mi tablero — las secciones que armas tú

Debajo de los accesos rápidos hay un espacio vacío que cada empresa llena a su manera. Se
organiza en **secciones**, y una sección es lo que tú digas que es: «Cotizaciones»,
«Producción», «Cartera», «Lo del lunes».

El orden de trabajo es el mismo siempre:

1. **+ Nueva sección** y le pones nombre.
2. Dentro de la sección, **+ Nuevo gráfico**, y armas el dato con las mismas cuatro
   decisiones de siempre: de dónde sale, qué se mide, cómo se agrupa y qué forma tiene.
   El detalle de esas cuatro decisiones está en [Gráficos del tablero](./graficos-tablero.md).
3. Repites por cada módulo que quieras ver al entrar.

Cada sección lleva sus botones al lado del título: **Renombrar**, **↑** y **↓** para moverla, y
**Quitar**.

### Una sección puede llevar dentro lo que sea

En el tablero de Cotizaciones solo se ofrecen fuentes de cotizaciones, porque ahí el módulo lo
puso el sistema. En el tablero de inicio el nombre lo pusiste tú, así que **no limita nada**:
una sección llamada «Cotizaciones» puede llevar adentro un gráfico de cartera si eso es lo que
quieres mirar. Las seis fuentes —cotizaciones, OPs, comisiones, recaudo, cartera y
alistamiento— están disponibles en cualquier sección.

### Lo que agregas lo ven todos

No es una preferencia personal: es la decisión de la empresa sobre qué se mira al entrar. Por eso
crear, renombrar, mover y quitar secciones exige el permiso **`graficos.gestionar`**, el mismo
que arma los gráficos de los demás tableros. Quien no lo tenga ve las secciones y sus números,
pero no los botones.

### Renombrar no rompe nada

Los gráficos de una sección no cuelgan de su nombre sino de una clave interna que se genera una
sola vez y nunca cambia. Puedes renombrar «Producción» a «Planta y producción» y sus gráficos
siguen ahí. **Quitar una sección sí se lleva sus gráficos**, y eso no se deshace: por eso se
pregunta antes.

## Qué ve cada rol

El dashboard se recorta según los permisos:

- Quien no puede ver todas las OPs solo cuenta las suyas.
- Un vendedor cuenta solo sus propias cotizaciones.
- Las alertas de mantenimiento son para administrador y jefe de producción.
- Crear, renombrar, mover y quitar secciones exige `graficos.gestionar`. Sin ese permiso se
  ven las secciones y sus números, pero no los botones.

## Detalles de uso

**Pull-to-refresh**: en el celular, hala hacia abajo desde arriba del todo para
recargar los números.

**Navegación instantánea**: los accesos rápidos y los avisos no recargan la aplicación
completa en cada toque. Antes cada uno era un enlace normal y volvía a bajar todo el
JavaScript; ahora navegan dentro de la misma sesión, que en celular con datos móviles se nota
bastante.
