# Recursos Humanos (colaboradores, disciplina, bonos y gamificación)

Ruta: `/rrhh/operarios` · Configuración: `/rrhh/configuracion`

## Qué es

La ficha completa de cada colaborador (operario) y todo lo que gira a su
alrededor: datos, documentos, disciplina, horas extras, permisos, metas
(hitos), cálculo de bono mensual y un sistema de puntos/estrellas
(gamificación) con niveles y ranking.

## Ficha del colaborador

Además de los datos básicos, en la ficha se registran:
- **Disciplina**: llamado de atención, memorando o falla. Queda para
  firma (por el propio colaborador o un jefe) y puede llevar un descuento
  en pesos que se resta del bono del mes.
- **Horas extras**: diurnas, nocturnas o dominicales, con su tarifa.
- **Permisos**: rango de fechas con motivo.
- **Hitos**: metas (por pasos, tiempo u OPs) con un bono en pesos si se
  cumplen.
- **Archivos**: documentos del colaborador (contratos, hojas de vida, etc.).

## Bono mensual

Se calcula por colaborador y mes con el botón "Calcular bono". La fórmula:

```
bono = (horas trabajadas × tarifa base)     ← por pasos completados
     + bonos de hitos cumplidos
     + horas extras (según su tarifa)
     − descuentos disciplinarios (en pesos)
```

Los hitos "de sistema" se verifican solos al calcular (si el colaborador
alcanzó la meta de pasos/tiempo/OPs del mes, el hito se marca cumplido). El
resultado queda guardado con su desglose. La tarifa base y las tarifas de
horas extras se configuran en `/rrhh/configuracion`.

## Gamificación: puntos / estrellas *(así funciona, confirmado 24 jul 2026)*

Los puntos **suben por lo positivo** — nunca bajan por sanciones (ver la
nota de disciplina abajo):

- **Por trabajo**: al completar un paso de producción, el colaborador gana
  puntos según la **dificultad** del paso (configurable por nivel de
  dificultad), más un **bonus por terminar antes del tiempo estimado** y un
  **bonus por completar el paso final** de un trabajo.
- **Por capacitación**: cada curso puede configurarse con una cantidad de
  puntos (campo "Puntos que otorga", solo para cursos de colaboradores). Al
  aprobar el curso, esos puntos se suman **automáticamente**. Ejemplo: el
  curso de bienvenida se crea con 10 puntos y todo el que lo apruebe recibe
  esas 10 estrellas solo.
- **Niveles automáticos**: el total de puntos ubica al colaborador en un
  nivel (Bronce, Plata, etc., configurables en la sección de niveles). Se
  recalcula solo cada vez que gana puntos.
- **Ranking semanal**: se puede ver quién sumó más puntos en la semana.

### Disciplina y puntos *(corregido 24 jul 2026)*

Una sanción disciplinaria **ya no descuenta puntos** de gamificación. Antes
había un enganche heredado que, por un mapeo equivocado, penalizaba un
"llamado de atención" como si fuera una "inasistencia" y le bajaba las
estrellas al colaborador. Eso se quitó: el efecto de una sanción es su
registro firmado y, si aplica, el descuento en pesos del bono — no tocar el
ranking de estrellas.

## Doble conteo de puntos — resuelto *(24 jul 2026)*

Antes, si un paso se completaba, se desmarcaba y se volvía a completar, los
puntos se sumaban más de una vez (y al desmarcar no se devolvían). Se
cerró con dos candados: (1) otorgar puntos por un paso es **idempotente** —
si ese paso ya le dio puntos a ese operario, no repite; y (2) al **desmarcar**
un paso (tanto desde el portal del operario como desde la edición de admin),
los puntos que había otorgado se **devuelven** y se recalcula el total. Así
un recompletado legítimo vuelve a otorgar, pero nunca se duplica.

## Cálculo de bono masivo *(nuevo, 24 jul 2026)*

En la lista de Colaboradores hay un botón "Calcular bonos del mes": se elige
mes y año, y el sistema calcula el bono de **todos los colaboradores
activos** de una sola vez (con la misma fórmula del cálculo individual), en
vez de tener que entrar uno por uno. A cada colaborador le llega su aviso de
"bono calculado" en la campanita.
- **Recordatorio de curso obligatorio por vencer** (ver
  [Capacitación](./capacitacion.md)).
