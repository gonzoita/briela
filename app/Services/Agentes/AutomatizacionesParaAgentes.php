<?php

namespace App\Services\Agentes;

use App\Models\Automatizacion;
use App\Models\AutomatizacionEjecucion;
use App\Models\AgenteEjecucion;
use App\Models\AgenteInterno;
use App\Models\User;
use App\Services\Automatizaciones\CatalogoAutomatizaciones;
use App\Services\Automatizaciones\EntidadesAutomatizacion;
use App\Services\Automatizaciones\EvaluadorCondiciones;
use App\Services\Automatizaciones\GrafoAutomatizacion;
use App\Services\Automatizaciones\LanzadorAutomatizaciones;
use App\Services\Automatizaciones\MotorAutomatizaciones;
use App\Support\ContextoSede;
use App\Support\Modulos;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lo que un agente puede hacer con las automatizaciones, y nada más.
 *
 * Un agente gasta tokens en cada paso, incluso en los mecánicos. Una automatización es el camino
 * barato: pasos fijos, sin modelo. Con esta puerta el agente pone el criterio —CUÁNDO y SOBRE QUÉ
 * documento— y la automatización hace el trabajo, que una persona ya armó y aprobó.
 *
 * Los límites no se negocian:
 * - Solo se ofrecen las **encendidas** y marcadas «disponible para agentes» por quien gestiona el
 *   Equipo de IA. El agente no ve ni lanza ninguna otra.
 * - La automatización corre con los permisos de **su autor**, como siempre; lo delicado sigue
 *   pasando por su paso de aprobación. El agente no los amplía.
 * - Solo los agentes que **proponen** pueden lanzarlas: uno de solo lectura no escribe nada.
 * - Sobre documentos que el agente puede ver (permiso y sede), nunca de colaboradores.
 * - Un tope por corrida: un agente que se enreda no dispara cien avisos.
 * - En una prueba en seco solo se simula, igual que el botón «Probar» de la automatización.
 */
class AutomatizacionesParaAgentes
{
    /** Cuántas automatizaciones puede lanzar un agente en UNA corrida. */
    public const MAX_POR_CORRIDA = 10;

    /** Cuántas se le describen al modelo: más que eso es una lista, no una herramienta. */
    public const MAX_OFRECIDAS = 25;

    /** @return Collection<int, Automatizacion> */
    public static function disponibles(): Collection
    {
        if (! Modulos::activo('automatizaciones')) {
            return collect();
        }

        return Automatizacion::where('activa', true)->where('disponible_agentes', true)
            ->orderBy('nombre')->limit(self::MAX_OFRECIDAS)->get();
    }

    /** Lo que se le cuenta al modelo de cada una: su número, qué es y sobre qué trabaja. */
    public static function describir(Collection $automatizaciones): string
    {
        return $automatizaciones->map(function (Automatizacion $a) {
            $entidad = GrafoAutomatizacion::entidadDe($a->grafo);
            $sobre   = $entidad ? 'sobre ' . mb_strtolower(EntidadesAutomatizacion::def($entidad)['label'] ?? $entidad) . ' (pasa su número o id en «documento»)' : 'sin documento';

            return "#{$a->id} «{$a->nombre}» — {$sobre}" . (filled($a->descripcion) ? ': ' . Str::limit($a->descripcion, 160) : '');
        })->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>  lo que se le muestra al modelo
     */
    public function lanzar(array $args, AgenteInterno $agente, ?AgenteEjecucion $ejecucion, bool $prueba, User $usuario): array
    {
        $id = (int) ($args['automatizacion_id'] ?? 0);
        $a  = self::disponibles()->firstWhere('id', $id);

        if (! $a) {
            return ['error' => "La automatización #{$id} no está disponible para ti. Usa solo las de la lista."];
        }

        $entidad = GrafoAutomatizacion::entidadDe($a->grafo);
        $ctx     = [];

        if ($entidad) {
            $fallo = $this->contextoDe($entidad, trim((string) ($args['documento'] ?? '')), $usuario, $ctx);

            if ($fallo) {
                return ['error' => $fallo];
            }

            // Las condiciones del principio deciden si la automatización tiene sentido para ESTE
            // documento. Se miran aquí para no dejar en cola una corrida que se detendría en el
            // primer paso, y para que el agente sepa que no aplicaba.
            $globales = CatalogoAutomatizaciones::globales();
            $cumple   = collect(LanzadorAutomatizaciones::condicionesIniciales($a->grafo))
                ->every(fn ($c) => EvaluadorCondiciones::cumple($c['reglas'] ?? [], $c['combinador'] ?? 'y', $ctx + $globales));

            if (! $cumple) {
                return ['lanzada' => false, 'motivo' => "El documento no cumple las condiciones de «{$a->nombre}»: no hacía falta lanzarla."];
            }
        }

        if ($prueba) {
            $autor = $a->creado_por ? User::withoutGlobalScopes()->find($a->creado_por) : null;
            $r     = app(MotorAutomatizaciones::class)->simular($a->grafo, $ctx, $autor ?? $usuario, $a->nombre);

            return [
                'simulacion' => true, 'automatizacion' => $a->nombre, 'termino' => $r['termino'], 'error' => $r['error'],
                'pasos' => collect($r['pasos'])->map(fn ($p) => $p['resumen'])->take(12)->values()->all(),
            ];
        }

        if ($ejecucion && $this->lanzadasEn($agente, $ejecucion) >= self::MAX_POR_CORRIDA) {
            return ['error' => 'Ya lanzaste ' . self::MAX_POR_CORRIDA . ' automatizaciones en esta corrida: es el máximo. Resume lo que falta para que lo haga una persona.'];
        }

        $corrida = app(LanzadorAutomatizaciones::class)->paraAgente($a, $usuario, $ctx, $ejecucion);

        if (! $corrida) {
            return ['lanzada' => false, 'motivo' => "No se lanzó «{$a->nombre}»: ya corrió con este documento hace poco o llegó a su límite por hora."];
        }

        return [
            'lanzada' => true, 'automatizacion' => $a->nombre, 'corrida' => $corrida->id,
            'nota' => 'Quedó en cola y corre sola. No es un hecho consumado: puede esperar o pedir una aprobación. No digas que «ya se hizo».',
        ];
    }

    /**
     * El documento sobre el que se lanza: existe, el agente puede verlo y no es de colaboradores.
     *
     * @param  array<string, mixed>  $ctx  se llena con lo que se sabe del documento
     * @return string|null  el motivo por el que no se puede, o null
     */
    private function contextoDe(string $entidad, string $ref, User $usuario, array &$ctx): ?string
    {
        $def = EntidadesAutomatizacion::def($entidad);

        if (! $def || ! EntidadesAutomatizacion::disponible($entidad)) {
            return 'Ese tipo de documento no está disponible en esta instalación.';
        }

        if ($def['sensible']) {
            return 'Esa automatización trabaja con datos de colaboradores: un agente no puede lanzarla.';
        }

        if ($ref === '') {
            return "Esa automatización trabaja sobre {$def['label']}: pasa su número o su id en «documento».";
        }

        $doc = EntidadesAutomatizacion::buscarParaProbar($entidad, $ref);

        if (! $doc || ! $usuario->tienePermiso($def['permiso'])) {
            return "No encontré {$def['label']} «{$ref}».";
        }

        $sede = ContextoSede::id();

        if ($sede && $doc->getAttribute('sede_id') && (int) $doc->getAttribute('sede_id') !== (int) $sede) {
            return "No encontré {$def['label']} «{$ref}».";
        }

        $ctx = EntidadesAutomatizacion::contexto($doc) ?? [];

        return $ctx === [] ? "No se pudo leer {$def['label']} «{$ref}»." : null;
    }

    private function lanzadasEn(AgenteInterno $agente, AgenteEjecucion $ejecucion): int
    {
        return AutomatizacionEjecucion::where('origen', 'agente')->where('agente_ejecucion_id', $ejecucion->id)->count();
    }
}
