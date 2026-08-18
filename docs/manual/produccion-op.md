# Producción — Orden de Producción (OP)

Ruta: `/produccion/ops`

## Qué es

El sistema real y activo de producción es `Op` + `OpItem` + `OpItemTrabajo`
(con sus pasos). Reemplazó por completo a un sistema anterior basado en
"3 líneas paralelas" (Puertas / Panelería / Almacén) que **ya no está en
uso** — ver la sección de código legacy más abajo.

Estados de una OP: `borrador → confirmada → en_produccion → calidad →
despachada`, con la posibilidad de que calidad la mande a `reproceso` en vez
de aprobarla, y desde ahí vuelva a `en_produccion`.

## Automatizaciones activas

1. **Trabajos de producción automáticos** al generar la OP desde una
   cotización (ver [Cotización → OP](./cotizacion-a-op.md)).
2. **Anticipo real** registrado como cuota/pago desde la generación de la OP.
3. **Consumo de inventario automático** al despachar.
4. **Paso a "Calidad" automático** *(nuevo, 22 jul 2026)*: antes, aunque
   producción ya hubiera terminado todos los ítems de la OP, el estado se
   quedaba en "En producción" hasta que alguien entraba manualmente a
   "Cambiar estado" y elegía "Calidad" a mano. Ahora, cada vez que se
   completa el último paso de trabajo de un ítem, el sistema revisa si con
   ese ítem ya quedaron **todos** los de la OP terminados — si es así, la OP
   pasa sola a "Calidad", lista para que jefe de producción/administrador
   revisen. El botón manual de "Cambiar estado" sigue existiendo como
   respaldo, pero ya no debería hacer falta usarlo para este paso.

   **Pendiente**: cuando calidad **rechaza** y la OP vuelve a "Reproceso",
   el regreso de "Reproceso" a "En producción" (o directo a "Calidad" otra
   vez, una vez corregido) todavía es manual — no hay hoy un marcador de
   "qué ítem específico falló" para saber cuándo el reproceso terminó. Es
   el siguiente punto de automatización pendiente en este módulo.
5. **"Confirmada" pasa directo a "En producción"** *(nuevo, 22 jul 2026)*:
   antes, después de confirmar la OP (con o sin anticipo), había que volver
   a entrar a "Cambiar estado" y elegir "En producción" a mano — un segundo
   clic sin ninguna decisión real detrás. Ahora, apenas se confirma (ya sea
   desde el modal de anticipo o desde "Cambiar estado" directo), la OP pasa
   sola a "En producción". Esto además corrige un problema silencioso: la
   transición automática a "Calidad" (punto 4) solo funciona si la OP está
   en "En producción" — si se quedaba pegada en "Confirmada", nunca iba a
   avanzar aunque toda la producción estuviera lista.
6. **La OP se pone al día sola si ya hay trabajo real** *(nuevo, 23 jul
   2026)*: se detectó que un operario podía estar avanzando pasos de un
   trabajo (43% completado, por ejemplo) mientras la OP seguía mostrando
   "Borrador" en el detalle — el trabajo real en planta no estaba
   actualizando el estado de la OP. Ahora, apenas se registra el primer
   avance real en cualquier trabajo, si la OP seguía en "Borrador" o
   "Confirmada" pasa sola a "En producción".
7. **Progreso general de la OP corregido** *(nuevo, 23 jul 2026)*: el
   porcentaje que se ve arriba del detalle de la OP ("Progreso general de
   la OP") se quedaba pegado en 0% cuando el avance se registraba desde
   Programador/Trabajos (el camino más usado) — solo se actualizaba desde
   un par de pantallas puntuales. Ahora se recalcula cada vez que cambia el
   avance de cualquier trabajo, sin importar desde dónde se registre.
8. **Control de calidad real, obligatorio para despachar**: antes
   el estado "Calidad" era solo una etiqueta sin ningún dato detrás — no
   había dónde registrar fotos, observaciones ni un motivo de rechazo, y
   además el despacho (remisión) no dependía para nada de este paso.
   Ahora, cuando una OP está en estado "Calidad", aparece un panel para
   adjuntar fotos de evidencia (desde el celular se puede elegir entre
   tomar la foto con la cámara o subir una ya existente de la galería, y se
   pueden subir varias de una vez), escribir observaciones (medidas,
   acabado, hermeticidad), y decidir:
   - **Aprobar calidad**: marca `calidad_aprobada_at` — con esto ya se puede
     generar la remisión desde Logística. La OP sigue en estado "Calidad"
     hasta que la remisión la despache de verdad.
   - **Rechazar**: pide motivo obligatorio y manda la OP a `reproceso`. Desde
     ahí producción la retoma y, al terminar, vuelve a pasar por "Calidad".

   Solo administrador y jefe de producción pueden decidir. Es un requisito
   duro: ni el cambio de estado manual a "Despachada" ni la generación de
   la remisión funcionan si la OP no tiene `calidad_aprobada_at`.

## Cómo se llega a "Despachada"

El consumo automático de inventario (descontar los insumos usados) se
dispara la primera vez que la OP llega a "Despachada", sin importar por cuál
de los dos caminos:

1. **Remisión completa** (el camino normal): cuando almacén remisiona el
   último ítem pendiente de una OP ya aprobada en calidad, el sistema marca
   la OP como despachada automáticamente.
2. **Cambio de estado manual**: solo permitido si la OP ya tiene calidad
   aprobada — pensado como vía de respaldo, no como el flujo normal.

## Quién puede ver qué

- Administrador y jefe de producción: ven todas las OPs.
- Vendedor: solo ve las OPs donde es responsable.

## Código legacy — no usar

Estos modelos/controladores existen en el repositorio pero **no tienen
ninguna ruta activa** — son restos de una versión anterior del sistema, de
antes de que se construyera el `Op` actual. No hay que tocarlos ni asumir
que reflejan el estado real de nada:

- `OrdenProduccion` / `LineaOP` (tabla `ordenes_produccion` / `lineas_op`) —
  el sistema viejo de 3 líneas paralelas. Sin controlador, sin rutas.
- `ItemOP` — ítems del sistema viejo.
- `resources/js/Pages/Seguimiento/Show.vue` — pantalla de resultado vieja,
  ya no se usa (ver abajo).

**Nota histórica**: hasta el 22 de julio de 2026, el **Dashboard principal**
también leía de `OrdenProduccion` en vez de `Op` — se corrigió ese día, pero
es un buen ejemplo de por qué es importante no dejar código muerto sin
marcar: nadie notó durante meses que las métricas de inicio no eran reales.


## Calidad, unidad por unidad

Calidad era una decisión de una sola pieza sobre la orden entera: aprobada o a reproceso, con
una foto y un comentario. En una orden de diez puertas eso no dice nada — no queda registro de
qué se revisó, ni de cuál unidad salió mal, ni de qué le faltaba.

Desde el 18 ago 2026, si el ensamble tiene lista de revisión, **cada unidad se revisa punto por
punto** desde su hoja de producción, y la orden no se puede aprobar mientras quede algo sin
resolver. El aviso dice cuántos puntos faltan, que es lo único que sirve cuando son diez
puertas.

Un ensamble sin lista de revisión sigue funcionando como siempre: calidad aprueba la orden
completa. La lista se define en la ficha del ensamble, sección «Revisión de calidad».


## Seguimiento público — dos caminos, un solo resultado *(reconstruido 23 jul 2026)*

Hay dos formas de que un cliente consulte el estado de su pedido, y ahora
las dos muestran exactamente la misma información (misma vista
`OpPublica/Show`, mismos datos — ver `Op::datosSeguimientoPublico()`):

1. **`/op/{token_publico}`** — el link directo de los QR impresos en
   etiquetas y PDFs de OP. Es el que hay que compartir con el cliente.
2. **`/seguimiento`** — un buscador manual donde el cliente escribe el
   número de OP (ej. `OP-0017`) o el número de serie de un ítem (ej.
   `IF-2026-045-P-001`), o escanea un QR con la cámara del navegador.
   Antes buscaba contra `OrdenProduccion` (modelo muerto) y nunca
   encontraba nada real — reconstruido para buscar contra `Op`/`OpItem`.

**Candado de privacidad** *(cerrado 24 jul 2026)*: como el número de OP es
secuencial y predecible, `/seguimiento` ahora exige, además del número, un
**dato del cliente** (apellido o número de documento, tal como figura en la
orden). Si no coincide, no muestra nada. Así nadie puede espiar pedidos
ajenos probando números. El link por QR (`/op/{token}`) no pide esto porque
el token ya es imposible de adivinar.
