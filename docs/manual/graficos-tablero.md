# Gráficos del tablero

Permiso para armarlos: `graficos.gestionar` · Verlos: cualquiera con acceso al módulo

Cada tablero —Cotizaciones, Comisiones, Alistamiento— tiene una sección donde la empresa arma
sus propios gráficos.

## Cómo se arma uno

Cuatro decisiones, y ninguna es escribir una consulta:

| Decisión | Ejemplo |
|---|---|
| **Fuente** | Cotizaciones, Órdenes de producción, Comisiones, Ítems de producción |
| **Qué se mide** | Cantidad, valor total, ticket promedio, unidades, avance promedio |
| **Agrupado por** | Estado, mes, responsable, vendedor, tipo — o sin agrupar, y es un solo número |
| **Forma** | Barras, línea, dona o número |

Más un rango de fechas opcional. **Lo que uno agrega lo ven todos**: por eso armarlos exige
permiso y verlos no.

## Guarda la pregunta, no la respuesta

Un gráfico guarda qué se preguntó, y los números se calculan al abrir la pantalla. Guardar el
resultado sería más rápido y sería peor: un gráfico con datos congelados envejece sin avisar, y
nadie lo nota hasta que toma una decisión con él.

## Por qué son pocas fuentes

Porque un constructor que puede graficar cualquier tabla se ve impresionante y termina
produciendo gráficos que nadie sabe leer. Estas cuatro responden preguntas que alguien hace de
verdad un lunes por la mañana.

Agregar una fuente nueva es agregarla al catálogo de `App\Services\FuentesGraficoService`: ahí
se declara qué mide, cómo se agrupa y qué se puede filtrar, y la pantalla se arma sola con eso.

## Lo que la pantalla manda no son columnas

Son **claves de ese catálogo** —`cotizaciones`, `estado`, `suma_total`—, y el servidor las
traduce a columnas. Una clave que no esté en el catálogo se rechaza. Es la misma regla del
ordenamiento de las listas (`App\Support\Orden`), y por la misma razón: sin esa traducción,
armar un gráfico sería escribir SQL desde el navegador.

La sede activa se respeta en las fuentes que tienen sede, igual que en el resto del sistema.
