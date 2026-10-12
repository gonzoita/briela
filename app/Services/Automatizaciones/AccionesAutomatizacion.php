<?php

namespace App\Services\Automatizaciones;

use App\Models\AgenteInterno;
use App\Models\CrmLead;
use App\Models\CrmTarea;
use App\Models\Cotizacion;
use App\Models\CotizacionSeguimiento;
use App\Models\User;
use App\Services\Agentes\LanzadorAgentes;
use App\Services\NotificacionService;
use App\Support\Modulos;

/**
 * Lo que hace cada acción de una automatización. El catálogo está en `CatalogoAutomatizaciones`.
 *
 * Todas pasan por los modelos y servicios de siempre, y todas se vuelven a preguntar **en el
 * momento de ejecutar** si el autor todavía puede hacerlo: una automatización armada hace meses por
 * alguien que hoy ya no tiene ese permiso deja de poder usarlo, en vez de seguir actuando con un
 * permiso que ya no existe.
 *
 * Lanza `\RuntimeException` con un motivo entendible cuando algo no se puede; el motor lo deja en
 * la caja negra de la corrida.
 */
class AccionesAutomatizacion
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $ctx      los datos del documento
     * @return string lo que hizo (o, en simulación, lo que haría)
     */
    public function ejecutar(string $accion, array $config, array $ctx, User $autor, bool $simular): string
    {
        $def = CatalogoAutomatizaciones::acciones()[$accion] ?? null;

        if (! $def) {
            throw new \RuntimeException("La acción «{$accion}» no existe.");
        }

        if ($def['permiso'] && ! $autor->tienePermiso($def['permiso'])) {
            throw new \RuntimeException("Quien armó esta automatización ya no tiene el permiso {$def['permiso']}.");
        }

        return match ($accion) {
            'notificar'       => $this->notificar($config, $ctx, $simular),
            'crear_tarea_crm' => $this->crearTarea($config, $ctx, $autor, $simular),
            'nota_cotizacion' => $this->crearNota($config, $ctx, $autor, $simular),
            'ejecutar_agente' => $this->lanzarAgente($config, $autor, $simular),
        };
    }

    private function notificar(array $c, array $ctx, bool $simular): string
    {
        $ids = match ($c['destinatario'] ?? '') {
            'responsable' => array_filter([(int) ($ctx['responsable_id'] ?? 0)]),
            'usuarios'    => array_map('intval', (array) ($c['usuarios'] ?? [])),
            'permiso'     => User::where('activo', true)->get()
                                ->filter(fn (User $u) => $u->tienePermiso((string) ($c['permiso'] ?? '')))
                                ->pluck('id')->all(),
            default       => [],
        };

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            // Sin a quién avisar no es un fallo de la automatización: el documento simplemente
            // no tiene responsable (una cotización suelta, por ejemplo).
            return 'No había a quién avisar.';
        }

        $titulo  = EvaluadorCondiciones::rellenar((string) ($c['titulo'] ?? ''), $ctx);
        $mensaje = EvaluadorCondiciones::rellenar((string) ($c['mensaje'] ?? ''), $ctx);

        if (! $simular) {
            $servicio = app(NotificacionService::class);

            foreach ($ids as $id) {
                $servicio->crear($id, 'automatizacion_aviso', $titulo, $mensaje ?: null, $ctx['_url'] ?? null);
            }
        }

        return ($simular ? 'Avisaría' : 'Avisó') . ' a ' . count($ids) . (count($ids) === 1 ? ' persona' : ' personas') . ": «{$titulo}».";
    }

    private function crearTarea(array $c, array $ctx, User $autor, bool $simular): string
    {
        $leadId = ($ctx['_entidad'] ?? null) === 'lead' ? ($ctx['_id'] ?? null) : ($ctx['lead_id'] ?? null);
        $lead   = $leadId ? CrmLead::find($leadId) : null;

        if (! $lead) {
            throw new \RuntimeException('No hay un lead al que ponerle la tarea.');
        }

        if ($lead->estado !== 'activo') {
            return "El lead «{$lead->titulo}» ya está cerrado; no se creó la tarea.";
        }

        $titulo = EvaluadorCondiciones::rellenar((string) $c['titulo'], $ctx);
        $dias   = max(0, min(90, (int) ($c['dias'] ?? 1)));

        if (! $simular) {
            CrmTarea::create([
                'lead_id'           => $lead->id,
                'responsable_id'    => $lead->responsable_id ?: $autor->id,
                'titulo'            => mb_substr($titulo, 0, 190),
                'descripcion'       => EvaluadorCondiciones::rellenar((string) ($c['descripcion'] ?? ''), $ctx) ?: null,
                'tipo'              => 'seguimiento',
                'fecha_vencimiento' => now()->addDays($dias)->toDateString(),
            ]);
        }

        return ($simular ? 'Crearía' : 'Creó') . " la tarea «{$titulo}» en el lead «{$lead->titulo}».";
    }

    private function crearNota(array $c, array $ctx, User $autor, bool $simular): string
    {
        $cot = ($ctx['_entidad'] ?? null) === 'cotizacion' ? Cotizacion::find($ctx['_id'] ?? 0) : null;

        if (! $cot) {
            throw new \RuntimeException('No hay una cotización a la que agregarle la nota.');
        }

        if (! $simular) {
            CotizacionSeguimiento::create([
                'cotizacion_id' => $cot->id,
                'user_id'       => $autor->id,
                'nota'          => mb_substr(EvaluadorCondiciones::rellenar((string) $c['texto'], $ctx), 0, 1500),
            ]);
        }

        return ($simular ? 'Agregaría' : 'Agregó') . " una nota a la cotización {$cot->numero}.";
    }

    private function lanzarAgente(array $c, User $autor, bool $simular): string
    {
        if (! Modulos::activo('equipo_ia')) {
            throw new \RuntimeException('El módulo Equipo de IA está apagado.');
        }

        $agente = AgenteInterno::find((int) ($c['agente_id'] ?? 0));

        if (! $agente) {
            throw new \RuntimeException('El agente ya no existe.');
        }

        if (! $agente->activo) {
            throw new \RuntimeException("El agente «{$agente->nombre}» está apagado.");
        }

        if (! $simular) {
            app(LanzadorAgentes::class)->manual($agente, $autor, filled($c['tarea'] ?? null) ? (string) $c['tarea'] : null);
        }

        return ($simular ? 'Pondría' : 'Puso') . " a trabajar a «{$agente->nombre}».";
    }
}
