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
