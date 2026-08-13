<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Todos los días a la 1:00 a.m. revisa cotizaciones vencidas — para que
// esto corra de verdad hace falta un cron en el servidor que ejecute
// "php artisan schedule:run" cada minuto (ver instrucciones de deploy).
Schedule::command('cotizaciones:marcar-vencidas')->dailyAt('01:00');

// Todos los días a las 6:00 a.m. avisa a producción de las OPs con entrega
// próxima que aún no se han despachado (notificación interna, campanita).
Schedule::command('notificaciones:entregas-proximas')->dailyAt('06:00');

// Todos los días a las 6:05 a.m. avisa a los colaboradores de cursos
// obligatorios cuya fecha límite se acerca.
Schedule::command('notificaciones:cursos-por-vencer')->dailyAt('06:05');

// Todos los días a las 6:10 a.m.: recordatorios de cotizaciones sin
// respuesta, insumos bajo el stock mínimo y cuotas de OP vencidas.
Schedule::command('notificaciones:recordatorios')->dailyAt('06:10');

// Leads que llevan una semana sin que nadie los toque. Va a las 6:15, junto con el
// resto de los avisos de la mañana: así quien abre el sistema encuentra en la
// campanita todo lo que tiene pendiente del día, en un solo grupo.
Schedule::command('crm:avisar-leads-quietos --dias=7')->dailyAt('06:15');

// El latido de la licencia: cuatro veces al día. Va por el cron y no en las cargas de
// página para que ninguna pantalla dependa de que el servidor de licencias responda.
Schedule::command('briela:latido')->cron('7 */6 * * *');

// Cada minuto: publica en redes sociales las publicaciones programadas cuya
// fecha ya se cumplió (módulo RRSS).
Schedule::command('rrss:publicar-programadas')->everyMinute();

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
