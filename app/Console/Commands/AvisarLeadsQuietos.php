<?php

namespace App\Console\Commands;

use App\Models\CrmLead;
use App\Services\NotificacionService;
use Illuminate\Console\Command;

/**
 * Avisa de los leads que llevan días sin que nadie los toque.
 *
 * Es el aviso que sostiene el embudo. Un lead no se pierde porque alguien decida
 * dejarlo: se pierde porque nadie se acordó. Y en un embudo con doscientas tarjetas
 * nadie nota cuál lleva dos semanas quieta.
 *
 * Se considera movimiento cualquier señal de vida: una nota, una tarea, una
 * actividad registrada, un cambio de etapa o un contacto nuevo por cualquier canal.
 * Si nada de eso pasó en el plazo, el lead está quieto.
 */
class AvisarLeadsQuietos extends Command
{
    protected $signature = 'crm:avisar-leads-quietos {--dias=7 : Días sin movimiento para avisar}';

    protected $description = 'Avisa al responsable de los leads que llevan días sin movimiento';

    public function handle(NotificacionService $notificaciones): int
    {
        $dias  = max(1, (int) $this->option('dias'));
        $corte = now()->subDays($dias);

        $quietos = CrmLead::query()
            ->where('estado', 'activo')
            // Un lead recién llegado no está quieto: está esperando su turno.
            ->where('created_at', '<', $corte)
            ->whereDoesntHave('actividades', fn ($q) => $q->where('created_at', '>=', $corte))
            ->whereDoesntHave('notas', fn ($q) => $q->where('created_at', '>=', $corte))
            ->whereDoesntHave('tareas', fn ($q) => $q->where('created_at', '>=', $corte))
            ->whereDoesntHave('origenes', fn ($q) => $q->where('created_at', '>=', $corte))
            ->where('updated_at', '<', $corte)
            ->with('responsable:id,name')
            ->get();

        if ($quietos->isEmpty()) {
            $this->info("Ningún lead lleva más de {$dias} días quieto.");

            return self::SUCCESS;
        }

        // Un aviso por responsable con todos sus leads, no uno por lead: veinte
        // notificaciones seguidas se ignoran en bloque, y con eso se pierde el
        // sentido de avisar.
        foreach ($quietos->groupBy('responsable_id') as $responsableId => $leads) {
            $cuantos = $leads->count();
            $titulo  = $cuantos === 1
                ? 'Un lead lleva ' . $dias . ' días sin movimiento'
                : "{$cuantos} leads llevan {$dias} días sin movimiento";

            $mensaje = $leads->take(5)->map(fn ($l) => $l->nombre_contacto ?: $l->titulo)->implode(' · ')
                . ($cuantos > 5 ? " y {$cuantos} más" : '');

            if ($responsableId) {
                $notificaciones->crear((int) $responsableId, 'lead_quieto', $titulo, $mensaje, '/crm');
            } else {
                // Sin responsable, el aviso va a administración: un lead sin dueño
                // y sin movimiento es el que se pierde más rápido.
                $notificaciones->paraRol('administrador', 'lead_quieto', $titulo . ' (sin responsable)', $mensaje, '/crm');
            }
        }

        $this->info("Avisados {$quietos->count()} leads quietos en " . $quietos->groupBy('responsable_id')->count() . ' avisos.');

        return self::SUCCESS;
    }
}
