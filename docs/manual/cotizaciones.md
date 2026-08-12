# Cotizaciones

Ruta: `/cotizaciones`

## Qué es

El listado y editor de cotizaciones comerciales. Cada cotización tiene un
número automático (`COT-{año}-{correlativo}`), ítems (productos, servicios,
ensambles configurados), totales calculados y un estado:

`borrador → enviada → aprobada / rechazada / vencida`

Cuando se aprueba y se genera una Orden de Producción, queda marcada como
`en_produccion` y ya no se puede editar.

## Uso básico

- **Crear**: "Nueva cotización". Si viene generada automáticamente desde un
  lead del CRM, aparece un aviso azul arriba indicando de cuál lead proviene
  y el cliente ya queda preseleccionado.
- **Cambiar estado**: desde el detalle de la cotización.
- **Duplicar**: crea una copia en borrador con los mismos ítems.
- **Generar orden de producción**: desde una cotización aprobada.

## Automatizaciones activas

1. **Generación automática desde CRM** (ver
   [CRM — Pipeline](./crm-pipeline.md)): evita tener que crear la cotización
   a mano después de mover un lead.
2. **Alerta de seguimiento con link directo** *(actualizado 22 jul 2026)*: si
   una cotización lleva **5 días o más** en estado "Enviada" sin respuesta,
   aparece un aviso amarillo en el listado. Antes solo mostraba un número
   ("3 cotizaciones sin respuesta") sin decir cuáles — ahora muestra un chip
   por cada una (número + días) que lleva directo a su detalle al hacer clic.
3. **Comisión siempre visible** *(nuevo, 22 jul 2026)*: en el detalle de la
   cotización hay una tarjeta que muestra el estado de la comisión del
   vendedor (Proyectada / Confirmada / Sin comisión asignada). Antes, si la
   comisión calculada era $0 o la cotización era anterior a esta función, no
   aparecía nada — daba la impresión de que la comisión se había perdido.
   Ahora siempre se ve algún estado, nunca queda en blanco.
4. **Panel de Seguimiento** *(nuevo, 22 jul 2026)*: en el detalle de la
   cotización hay una sección para registrar notas de contacto con el
   cliente (llamadas, correos, visitas) con fecha y quién la escribió. Cada
   nota que se agrega reinicia el contador de "días sin respuesta" — así el
   cálculo de la alerta de seguimiento (punto 2) refleja el último contacto
   real, no solo la última edición de la cotización.
5. **"Enviada" ya no requiere un clic aparte** *(nuevo, 22 jul 2026)*: antes,
   después de copiar el link o mandarlo por WhatsApp desde "Enviar al
   cliente", había que apretar un botón extra ("Marcar como Enviada") para
   que el estado cambiara — un paso que se olvidaba fácil. Ahora, copiar el
   link (desde el botón de arriba o desde el modal) o tocar "Enviar por
   WhatsApp" ya marca la cotización como "Enviada" sola, porque esa acción
   ES el envío. El botón de estado sigue disponible como respaldo manual.
6. **"Aprobada"/"Rechazada" ya son automáticas**: cuando el cliente entra al
   link público y decide, el sistema cambia el estado solo — nadie de
   la empresa tiene que hacerlo a mano.
7. **"Vencida" automática** *(nuevo, 23 jul 2026)*: todos los días a la
   1:00 a.m. corre `cotizaciones:marcar-vencidas`, que pasa a "Vencida"
   cualquier cotización que siga en "Enviada" con la fecha de validez ya
   pasada. Requiere que el cron del servidor esté configurado — ver
   sección de deploy más abajo.

## Qué precio se le muestra a cada cliente *(cambiado 12 ago 2026)*

Al elegir el cliente, la cotización muestra **solo el precio de su canal**.
Los demás no aparecen. Junto al nombre del cliente sale una etiqueta con el
canal que se está usando.

Antes se mostraban los tres precios y se resaltaba el del cliente. Eso pone
el precio mayorista delante de quien está cotizando a un cliente final, y un
clic en la tarjeta equivocada es una venta por debajo.

**Si el cliente no está segmentado no se muestra ningún precio**, y la pantalla
explica qué hacer. Cuál canal le corresponde a cada cliente se decide en
[Segmentación](./segmentacion-y-precios.md).

## Cómo se calcula la comisión *(corregido 23 jul 2026)*

La comisión del vendedor **no** se calcula sobre el precio de venta
completo del ítem. Se calcula sobre el **excedente** por encima del precio
del canal base:

```
excedente   = precio de venta del ítem − precio del canal base
comisión    = excedente × cantidad × % de comisión aplicado
```

El canal base es la utilidad garantizada e intocable de la empresa —de fábrica
es Mayorista, y se cambia marcándolo en Segmentación. Cualquier venta por
encima de ese valor en los otros canales genera un excedente, que se reparte
entre más utilidad para la empresa y comisión para el vendedor. Por eso **el
canal base nunca genera comisión**: ahí no hay excedente que repartir.

Antes la fórmula usaba el precio de venta completo en vez del excedente,
lo que inflaba muchísimo la comisión mostrada (ej. 28,5% de $1.630.000 =
$464.550, cuando el excedente real sobre el mayorista era mucho menor). El
precio del canal base queda guardado en la cotización al momento de agregar
cada ítem, y no se recalcula después: una comisión que se liquida meses más
tarde tiene que calcularse con el precio que había al vender.

**Dónde se configura el % de comisión por producto/ensamble**: en el
detalle del producto o ensamble → botón "Editar" → sección "Comisión
Vendedor por Canal". Ahí se define el rango mínimo/máximo de comisión para
Distribuidor y Cliente final (Mayorista nunca tiene comisión). El botón
"Sugerir comisiones" calcula un rango automático según el margen
disponible. La vista previa en pesos de esa pantalla también se corrigió
para mostrar el valor sobre el excedente, igual que en la cotización.

## Cron del servidor (una sola vez)

El comando `cotizaciones:marcar-vencidas` (punto 7 arriba) solo corre si el
servidor tiene configurado el scheduler de Laravel. Esto se configura **una
sola vez** por SSH, no en cada deploy:

```bash
crontab -e
```

Y agregar esta línea (ajustar la ruta de PHP si es distinta en Hostinger):

```
* * * * * cd /home/USUARIO/domains/TUDOMINIO/public_html/briela && php artisan schedule:run >> /dev/null 2>&1
```

Para probar que el comando funciona sin esperar al cron:

```bash
php artisan cotizaciones:marcar-vencidas
```

## Pendiente de automatizar (backlog)

- Notificación proactiva (no solo visual al entrar a la página) cuando una
  cotización cumple el umbral de días sin respuesta.
