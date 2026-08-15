# Mantenimiento de equipos

Rutas: `/mantenimiento` · `/mantenimiento/equipos` · Permiso: `mantenimiento.ver`

Para que una máquina no pare la producción por falta de un mantenimiento que nadie recordó.

## Equipos

Cada equipo lleva su ficha: fecha de instalación, **frecuencia en días** y su próxima revisión.
Puede tener una lista de **componentes** con cantidades — lo que lleva por dentro, para saber qué
repuesto pedir.

## Mantenimientos

Cada intervención se registra con su fecha programada, cuándo empezó, cuándo terminó, el
**tiempo en horas**, el **costo de mano de obra** y los **repuestos** usados con su cantidad y
precio.

Con la frecuencia y la última intervención, el sistema calcula la próxima revisión y avisa
cuando se acerca.

## El tablero

`/mantenimiento` muestra lo que viene: equipos con revisión próxima o vencida.

## Lo que conviene saber

El tiempo de un mantenimiento se guarda en **horas enteras** (la columna es entera). Si hace
falta media hora, hay que cambiar la columna con una migración.

Los repuestos se registran con su precio pero **no descuentan inventario todavía**: si el
repuesto salió de una bodega, hay que registrar la salida aparte.
