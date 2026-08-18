# Comisiones del vendedor

Ruta: `/comisiones` · Permiso: `comisiones.ver`

Lo que se le debe a cada vendedor por lo que vendió.

## Sobre qué se calcula

**Es un porcentaje del precio de venta del ítem.** Si el precio público de un ensamble es
1.428.000 y su comisión es del 5 %, el vendedor gana 71.400. Se dice así porque es como lo lee
un vendedor —«gano el 5 % de lo que vendo»— y porque con la misma unidad el descuento que puede
dar es una resta, no una regla de tres.

**De dónde sale esa plata sí depende del canal base**, que es el piso de utilidad de la empresa.
Vender a ese precio no genera comisión porque no hay nada que repartir; lo que se venda por
encima —el **excedente**— es lo único que se reparte. Por eso el tope que propone el sistema es
una parte del excedente, dicha en porcentaje del precio: 70 % de un excedente de 102.000 sobre
un precio de 1.428.000 son 71.400, que es el 5 %.

> Hasta el 17 ago 2026 el porcentaje guardado era del excedente y se multiplicaba por él. Era la
> misma plata, pero obligaba a mirar dos números para saber cuánto se ganaba, y hacía que el
> descuento no se pudiera deducir de la comisión. La migración
> `comision_como_porcentaje_del_precio` convirtió lo guardado sin cambiar un peso.

## El precio base se congela

Cada ítem de la cotización guarda el precio del canal base **del día en que se vendió**. Una
comisión que se liquida meses después tiene que calcularse con ese precio, no con el de hoy: si
el producto subió de precio en el medio, el vendedor no ganó más por eso.

## Lo que se ve

- **Resumen del mes**, con su PDF.
- **Detalle por documento**: qué ítems, a qué precio, qué excedente y cuánto de comisión.

## La escalera entre canales

Cada canal debe pagar **al menos la misma plata** que el anterior. La razón no es solo el
incentivo: vender un canal con su descuento máximo deja exactamente el precio del canal de
abajo sin descuento, y la misma venta no puede pagar dos comisiones distintas.

Como el porcentaje es de **su** precio, los de dos canales no se comparan crudos: el piso se
convierte por la plata. Si Distribuidor paga hasta 24.500 y el canal de arriba vale 1.428.000,
su piso es 1,72 % —que son esos mismos 24.500—, y no 1,78 %.

El botón «Sugerir comisiones» propone un reparto que respeta la escalera: hasta la mitad del
excedente para el vendedor, y hasta el 70 % en el canal de precio público, porque traer un
cliente nuevo cuesta más que atender a uno que ya compra. Nunca propone más que el excedente
entero: más que eso saldría de la utilidad garantizada de la empresa.

## El descuento sale de la comisión

Lo que el vendedor puede rebajarle al cliente es **exactamente lo que deja de ganar**: si su
tope es el 5 % de la venta y se conforma con el 3,57 %, el cliente se lleva el 1,43 %. Los dos
porcentajes son del mismo precio, así que es una resta.

Eso tiene una consecuencia que conviene conocer: **la utilidad de la empresa no cambia** mueva
el vendedor la barra donde la mueva. Siempre es `precio − costo − comisión máxima`. Lo único
que se decide con la barra es quién se queda con esa parte: el vendedor o el cliente.

## Arreglar el catálogo entero de una vez

El botón de la ficha solo toca el producto que se está editando, así que un cambio en la regla
del reparto deja atrás todo lo ya guardado. Para eso está el comando:

```bash
php artisan comisiones:recalcular --simular
```

Con `--simular` no escribe nada: enseña una tabla con lo que cambiaría, canal por canal.
Quitando la opción lo aplica, y vuelve a escribir también las columnas viejas por canal. Es
idempotente —correrlo dos veces no mueve nada la segunda— y **solo toca los canales que el ítem
ya tiene configurados**: no inventa filas.

Si nadie marcó un canal base en Segmentación, el comando se niega a correr. Sin ese piso, todo
el precio contaría como excedente y las comisiones saldrían disparadas.

> La regla del reparto está escrita dos veces: en `PreciosPorCanalService::sugerirComisiones()`
> para el comando, y en `resources/js/composables/usePreciosPorCanal.js` para el botón de la
> ficha, que tiene que responder mientras se teclean los márgenes. Si se cambia una hay que
> cambiar la otra, y `tests/Unit/SugerirComisionesTest.php` fija los números para que no se
> separen en silencio.

Ver [Segmentación y precios](./segmentacion-y-precios.md) y [Cotizaciones](./cotizaciones.md).
