# Informes

Ruta: `/informes`

## Qué es

Un generador de informes a medida. En vez de tener reportes fijos, cada
usuario arma el suyo eligiendo, paso a paso: la **fuente** de datos, los
**campos** (columnas) que quiere ver, los **filtros**, y opcionalmente una
**gráfica**. El informe queda guardado para volver a ejecutarlo cuando se
quiera, y se puede exportar a PDF o CSV (Excel).

## Fuentes disponibles

- **Órdenes de Producción**: número, cliente, estado, % de avance, fecha,
  total de ítems.
- **Colaboradores**: nombre, documento, cargo, nivel, puntos, tipo.
- **Cotizaciones**: número, cliente, vendedor, estado, total, fecha.
- **Pasos de Trabajo**: paso, OP, estación, colaborador, si está
  completado, fecha programada, tiempo estimado.

Todas leen de los datos reales y vivos del sistema (los mismos modelos que
usa el resto de la app), así que los números siempre están al día.

## Fuentes nuevas, resúmenes y Excel *(16 oct 2026)*

Además de las cuatro de siempre, hay seis fuentes que calcula `App\Services\Informes\MotorInformes`,
cada una una clase en `app/Services/Informes/Fuentes/`:

| Fuente | Para qué | Permiso |
|---|---|---|
| **Leads del CRM** | Quién llegó, por qué canal, si se cotizó, en cuántos días, si se ganó | `crm.ver` |
| **Cotizaciones y ventas** | Cuánto se cotizó y aprobó, ticket promedio, cliente nuevo o recurrente | `cotizaciones.ver` |
| **Clientes** | Historia de compra, recurrentes, días sin comprar | `clientes.ver` |
| **Órdenes de producción** | Valor, avance, entrega prometida, atrasos | `ops.ver` |
| **Cartera** | Saldo por cuota, verificado con el banco, días de mora por rango | `cartera.ver` |
| **Inventario** | Existencia, mínimo, entradas y salidas del periodo; costo solo con `costos.ver` | `inventario.ver` |

Lo que traen de más:

- **Periodo con nombre** (`Periodo::OPCIONES`): «el mes pasado», «últimos 30 días», «este año»…
  Se guarda el nombre y no las fechas: el informe vuelto a correr el mes siguiente trae ese mes.
- **Resumir por** canal, vendedor, estado, mes… Cada resumen es una tabla con su fila de total.
- **Cifras clave** calculadas sobre todas las filas: conversión, días hasta cotizar, saldo
  vencido, etc.
- **Excel (`.xlsx`) con varias hojas**: Resumen (cifras clave), Análisis (si lo pidió la guía),
  Detalle y una hoja por cada resumen. Las celdas llevan su tipo —número, moneda, fecha,
  porcentaje—, así que Excel suma y filtra sin convertir nada. Lo escribe `EscritorXlsx` sin
  librerías; si el servidor no tiene la extensión `zip` de PHP, se ofrece el CSV.
- **CSV que Excel en español abre bien**: UTF-8 con BOM, separador `;`, decimales con coma. Un
  texto que empieza por `=`, `+`, `-` o `@` va con un apóstrofo delante (inyección de CSV). Cada
  resumen tiene también su propio CSV (`?tabla=canal`).
- En pantalla se ven hasta 500 filas del detalle; el resto, en el archivo (hasta 20.000).

## Pedírselo a la guía en el chat *(16 oct 2026)*

En el chat se puede pedir algo como «¿cuántos clientes llegaron el último mes, cuáles cotizaron y
cómo mejoramos la conversión?». La guía:

1. **elige** una fuente del catálogo, el periodo, las columnas y por qué resumir. No escribe
   consultas: el plan se limpia (`MotorInformes::normalizarPlan`) y lo que no existe o la persona
   no puede ver se descarta;
2. el **servidor calcula** todo con los permisos y la sede de quien pregunta, y **guarda el
   informe** aquí, marcado «Pedido a la guía», con la pregunta;
3. a la IA le llegan **solo los totales y los resúmenes** (`MotorInformes::paraIa`), nunca las
   filas: los nombres y teléfonos van únicamente al archivo que descarga quien tiene permiso;
4. la respuesta de la guía es el **análisis** —cifra principal, hallazgos y recomendaciones— y
   queda guardado en el informe;
5. en el chat aparece una **tarjeta** con las cifras clave, un resumen, unas filas de muestra y
   los botones **Excel**, **CSV** y **Abrir en Informes**. La tarjeta sigue ahí al recargar el
   chat (`asistente_mensajes.informe`).

Si el módulo de Informes está apagado, la guía no ofrece informes. Una fuente nueva se agrega
escribiendo su clase y poniéndola en `MotorInformes::fuentes()`: aparece sola en la pantalla de
armar y en el catálogo de la guía.

## Cómo se usa

1. "Nuevo informe" → elegir la fuente.
2. Marcar las columnas que se quieren ver.
3. (Opcional) Poner filtros — por estado, fechas, vendedor, estación, etc.
4. Ponerle nombre, elegir tipo de gráfica (o ninguna) y si es visible para
   todos o solo para uno mismo.
5. Guardar. Desde el detalle se ejecuta, se ve la tabla y se exporta a
   PDF/CSV.

## Automatizaciones / mejoras

- **Filtros con nombres, no con IDs** *(corregido 23 jul 2026)*: antes,
  varios filtros pedían escribir un número de ID a mano — "Vendedor ID",
  "Estación ID", "Tipo Colaborador" — y nadie se sabe esos IDs de memoria,
  así que en la práctica no se podían usar. Ahora son listas desplegables
  con los nombres reales (el vendedor Juan, la estación Corte, etc.). El
  filtro de nivel de colaborador también pasó a lista.
- **Permisos**: cada quien ve sus propios informes y los marcados como
  públicos; el administrador ve todos. Solo el dueño (o el admin) puede
  borrar uno.

## Totales y promedios *(nuevo, 24 jul 2026)*

Al ejecutar un informe, ahora aparece una **fila de totales** al final de la
tabla (y también en el PDF y el CSV). Suma las columnas de monto/cantidad y
promedia los porcentajes, según la fuente:

- **Cotizaciones** → suma del Total (responde "cuánto vendí").
- **OPs** → suma de ítems y promedio del % de avance.
- **Colaboradores** → suma de puntos.
- **Pasos** → suma del tiempo estimado.

Así el informe ya sirve para decidir de un vistazo, sin tener que llevar los
datos a Excel para sumarlos a mano.

## Pendiente de mejorar (backlog)

- **Valores individuales como número en Excel**: la fila de totales ya da la
  suma calculada, pero cada celda de monto sigue saliendo como texto
  ("$1.234.000"). Si se quisiera graficar/sumar columna por columna dentro
  de Excel, convendría además exportar el número puro por celda.
