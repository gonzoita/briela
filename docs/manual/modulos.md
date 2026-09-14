# Módulos: encender y apagar lo que la empresa usa

`/configuracion/modulos` en la instalación · ficha de la instalación en el superadmin

**Todos los clientes tienen todos los módulos.** No hay planes que los restrinjan: cada empresa
apaga los que no usa, para que su menú no le muestre treinta cosas de las que usa diez.
**Apagar nunca borra datos**: al encender otra vez, todo está donde estaba.

## Qué se puede apagar y qué no

El catálogo vive en código, en `App\Support\Modulos::catalogo()`:

| Grupo | Módulo | Necesita |
|---|---|---|
| Ventas | CRM | — |
| Ventas | Cotizaciones | — |
| Ventas | Comisiones | Cotizaciones |
| Productos y Existencias | Ensambles y cotizador | — |
| Productos y Existencias | Stock y movimientos | — |
| Compras | Compras (proveedores, solicitudes, órdenes) | Stock |
| Producción y Entrega | Órdenes de producción | Ensambles |
| Producción y Entrega | Trabajos y pasos | Órdenes, Colaboradores |
| Producción y Entrega | Calidad | Trabajos |
| Producción y Entrega | Alistamiento | Órdenes, Stock |
| Producción y Entrega | Programador | Órdenes |
| Producción y Entrega | Remisiones | Órdenes |
| Producción y Entrega | Cartera | Órdenes |
| Mantenimiento | Mantenimiento | — |
| Talento Humano | Colaboradores y reglamento | — |
| Talento Humano | Capacitación | Colaboradores |
| Marketing y Contenidos | Redes sociales | — |
| Marketing y Contenidos | Multimedia | — |
| Sistema | Informes | — |

**Núcleo, no se apaga:** Dashboard, Clientes, Productos, costos, Usuarios y roles,
Configuración, Sedes, Auditoría, los gráficos del tablero, y el asistente y los agentes de IA.

**Las dependencias se arrastran en las dos direcciones.** Apagar Cotizaciones apaga Comisiones;
encender Comisiones enciende Cotizaciones. La pantalla lo anuncia antes de confirmar. Lo guardado
son los que alguien apagó; los que quedaron apagados por dependencia se marcan como tales, y
vuelven solos cuando se enciende aquello de lo que dependen.

## Cómo se corta

**Un solo punto de corte: los permisos.** `User::permisos()` descarta los de los módulos apagados,
sin importar el rol. Con eso desaparecen solos el menú, los botones, las rutas con `permiso:`, el
buscador Ctrl+K y lo que el asistente de IA puede consultar.

Lo que no pasa por permisos se corta aparte:

| Qué | Dónde |
|---|---|
| Portales públicos, QR del operario, pantalla de planta, formularios del CRM, certificados | `BloquearModuloApagado`, por el prefijo de la URL (`rutas` en el catálogo). Público → 404; con sesión → al tablero con el motivo |
| Accesos y alertas del Dashboard (salen del rol, no de permisos) | `DashboardController` y `HandleInertiaRequests` |
| Avisos de la campanita | `NotificacionService::MODULO_DE_TIPO`: no se crean, y no aparecen en Configuración → Notificaciones |
| Fuentes de gráficos | `FuentesGraficoService`: no se ofrecen; un gráfico ya armado dice «módulo desactivado» y vuelve al encenderlo |
| Tareas del cron | `routes/console.php`, con `->when(Modulos::activo(...))` |
| Enlaces sin permiso (Mi capacitación, Mi panel) | `modulo:` en `navItems` de `AppLayout.vue` |
| Pantallas que cambian de forma | `useModulos()` en Vue, que lee `modulosApagados` |

## El flujo se salta los pasos apagados

El principio del sistema es que cada acción real dispara sola el siguiente paso. Un paso apagado
no puede quedarse esperando algo que nadie puede hacer:

- **Sin Calidad**, la unidad terminada queda firmada sola (`calidad_revisada_at`, en
  `OpItemTrabajo::recalcularAvance`) y la orden terminada queda aprobada (`calidad_aprobada_at`,
  en `Op::revisarTransicionCalidad`). Sin esto, nada se podría remisionar nunca.
- **Sin Stock**, la orden no pide bodegas al confirmar, el paso final no las pide y cerrar la
  unidad no descuenta material ni suma producto terminado (`CierrePasoService`).
- **Sin Remisiones**, la orden aprobada se marca como despachada a mano desde su estado.
- **Sin CRM**, las cotizaciones se hacen sin lead; **sin Cotizaciones**, las órdenes se crean
  directamente.

## Sincronización con el superadmin

Se puede cambiar desde la instalación —efecto inmediato— o desde la ficha de la instalación en el
panel de Briela —efecto en el siguiente latido, cada seis horas o al comprobar la licencia—.
**Gana el cambio más reciente.**

1. En cada latido, la instalación manda sus apagados, la fecha de su último cambio y su catálogo.
2. El superadmin guarda el catálogo siempre (así muestra los módulos de la versión que corre ese
   cliente) y los apagados solo si el cambio de allá es posterior al suyo.
3. La respuesta devuelve lo que tiene el panel; la instalación lo aplica solo si es posterior a lo
   suyo (`Modulos::sincronizarDesde`).

Una versión vieja que no manda `modulos` sigue validando igual. Las fechas las pone cada servidor
con su reloj: si están muy desfasados, dos cambios casi simultáneos pueden resolverse al revés.

## Pruebas

`tests/Feature/ModulosTest.php` (arrastre, permisos, rutas, sincronización, salto de calidad y
stock) y, en el superadmin, `tests/Feature/ModulosInstalacionTest.php`.

> Las pruebas corren en un solo proceso y `Modulos` recuerda el estado en una variable estática:
> `Tests\TestCase::setUp()` la limpia. Sin eso, lo que apagó una prueba seguía apagado en la
> siguiente.
