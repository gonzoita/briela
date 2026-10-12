<?php

use App\Support\Modulos;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Las tareas de un módulo apagado no corren: `->when()` se evalúa en cada pasada del cron, así
// que apagar o encender el módulo surte efecto sin tocar el servidor.

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Todos los días a la 1:00 a.m. revisa cotizaciones vencidas — para que
// esto corra de verdad hace falta un cron en el servidor que ejecute
// "php artisan schedule:run" cada minuto (ver instrucciones de deploy).
Schedule::command('cotizaciones:marcar-vencidas')->dailyAt('01:00')->when(fn () => Modulos::activo('cotizaciones'));

// Cada quince minutos: una reserva de 24 horas no necesita más fino, y mientras tanto una vencida
// ya no aparta (`ReservaStock::vigentes()` mira la hora, no solo el estado).
Schedule::command('stock:liberar-reservas')->everyFifteenMinutes()->withoutOverlapping()
    ->when(fn () => Modulos::activo('inventario') && Modulos::activo('cotizaciones'));

// Todos los días a las 6:00 a.m. avisa a producción de las OPs con entrega
// próxima que aún no se han despachado (notificación interna, campanita).
Schedule::command('notificaciones:entregas-proximas')->dailyAt('06:00')->when(fn () => Modulos::activo('ops'));

// Todos los días a las 6:05 a.m. avisa a los colaboradores de cursos
// obligatorios cuya fecha límite se acerca.
Schedule::command('notificaciones:cursos-por-vencer')->dailyAt('06:05')->when(fn () => Modulos::activo('capacitacion'));

// Todos los días a las 6:10 a.m.: recordatorios de cotizaciones sin
// respuesta, insumos bajo el stock mínimo y cuotas de OP vencidas.
Schedule::command('notificaciones:recordatorios')->dailyAt('06:10');

// Leads que llevan una semana sin que nadie los toque. Va a las 6:15, junto con el
// resto de los avisos de la mañana: así quien abre el sistema encuentra en la
// campanita todo lo que tiene pendiente del día, en un solo grupo.
// (Los recordatorios de las 6:10 mezclan módulos; cada aviso lo descarta NotificacionService
// si su módulo está apagado.)
Schedule::command('crm:avisar-leads-quietos --dias=7')->dailyAt('06:15')->when(fn () => Modulos::activo('crm'));

// La TRM del día se publica la tarde del día hábil anterior. Corre dos veces porque
// una consulta que falla a primera hora no debe dejar el día entero con la de ayer; la
// segunda pasada no cambia nada si la primera ya la trajo.
Schedule::command('tasas:actualizar')->cron('30 6,13 * * *')->withoutOverlapping();

// Los rebotes y quejas que avisó el proveedor: a esas direcciones no se les vuelve a escribir.
Schedule::command('correo:sincronizar-eventos')->hourlyAt(20)->withoutOverlapping();

// El latido de la licencia: cuatro veces al día. Va por el cron y no en las cargas de
// página para que ninguna pantalla dependa de que el servidor de licencias responda.
Schedule::command('briela:latido')->cron('7 */6 * * *');

// Cada minuto: publica en redes sociales las publicaciones programadas cuya
// fecha ya se cumplió (módulo RRSS).
Schedule::command('rrss:publicar-programadas')->everyMinute()->when(fn () => Modulos::activo('rrss'));

// Los permisos de las redes vencen a los ~60 días. Una vez al día basta: lo que se puede
// renovar se renueva con 20 días de margen, y de lo que hay que reconectar a mano se avisa a
// los 15 — dos fines de semana para que alguien entre a hacerlo. Antes no se renovaba ni se
// avisaba: un día las publicaciones empezaban a fallar y el único rastro era un «parcial».
Schedule::command('rrss:revisar-tokens')->dailyAt('04:40')
    ->when(fn () => Modulos::activo('rrss'));

// Las plantillas de WhatsApp las aprueba, pausa y rechaza Meta por su cuenta y sin avisar. Sin
// sincronizar, el selector de la bandeja ofrecería plantillas que Meta ya rechazó, y eso se
// descubre cuando un mensaje a un cliente no sale.
Schedule::command('whatsapp:sincronizar-plantillas')->dailyAt('04:50')
    ->when(fn () => Modulos::activo('bandeja'));

// Todas las noches a las 2:00 a.m.: respaldo de la base de datos y limpieza
// de los que ya pasaron los 30 días.
//
// A esa hora no hay nadie trabajando, así que el volcado no compite con la
// operación. withoutOverlapping evita que dos respaldos se pisen si uno se
// demora más de lo normal, y onFailure deja rastro en el log aunque el
// comando reviente antes de poder avisar por sí mismo.
Schedule::command('backup:crear')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('El respaldo programado no terminó bien.'));

// Los recados que el panel de Briela dejó en el último latido: hoy, respaldos pedidos
// desde soporte. Se ejecutan aparte del latido porque un respaldo tarda, y el latido
// tiene que ser rápido. Media hora después de cada latido, para no coincidir.
Schedule::command('briela:ordenes')
    ->cron('37 */6 * * *')
    ->withoutOverlapping();

// El Equipo de IA: agentes internos que corren solos. El módulo nace apagado y, apagado, no hay
// despacho ni worker ni gasto.
//
// En un hosting compartido no existe un proceso que espere trabajo, así que el cron de cada minuto
// hace de worker: toma lo que haya en cola y se apaga (`--stop-when-empty`), sin pasar de los 55
// segundos para no pisar al minuto siguiente. Una corrida tarda segundos, y lo que no alcance
// espera su turno. `agentes:despachar` solo CREA el trabajo y limpia lo viejo.
Schedule::command('agentes:despachar')->everyFiveMinutes()->withoutOverlapping(10)
    ->when(fn () => Modulos::activo('equipo_ia'));

// El candado dura 5 minutos como máximo: con el de 24 horas por omisión, un proceso muerto
// dejaría al worker sin arrancar un día entero.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=1')->everyMinute()->withoutOverlapping(5)
    ->when(fn () => Modulos::activo('equipo_ia') || Modulos::activo('automatizaciones'));

// Automatizaciones: cada minuto crea las corridas por horario, retoma las que esperaban y rescata las
// que se quedaron colgadas. Una espera de cinco días no deja nada corriendo: queda guardada con su
// hora, y este comando la retoma.
Schedule::command('automatizaciones:avanzar')->everyMinute()->withoutOverlapping(5)
    ->when(fn () => Modulos::activo('automatizaciones'));
