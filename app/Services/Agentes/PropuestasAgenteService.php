<?php

namespace App\Services\Agentes;

use App\Models\AgenteEjecucion;
use App\Models\AgenteInterno;
use App\Models\AgentePropuesta;
use App\Models\Configuracion;
use App\Models\Cotizacion;
use App\Models\CrmLead;
use App\Support\ContextoSede;
use Illuminate\Support\Str;

/**
 * Lo que un agente PROPONE. Un agente nunca escribe en el negocio: deja una fila en
 * `agente_propuestas` y una persona la aprueba o la rechaza (`AprobacionService`).
 *
 * Aquí se validan los parámetros contra la base —que el lead exista, sea de una sede visible y
 * siga activo—, porque lo que escribe el modelo es texto y un identificador inventado no debe
 * llegar a una persona como si fuera real.
 */
class PropuestasAgenteService
{
    /**
     * Qué se puede proponer, y qué permiso exige APROBARLO (además de `equipo_ia.aprobar`).
     * Una acción nueva se agrega aquí, con su herramienta en `CatalogoHerramientas` y su
     * ejecución en `AprobacionService`.
     */
    public const ACCIONES = [
        'tarea_crm'       => ['etiqueta' => 'Crear una tarea de seguimiento en un lead', 'permiso' => 'crm.editar'],
        'nota_cotizacion' => ['etiqueta' => 'Agregar una nota de seguimiento a una cotización', 'permiso' => 'cotizaciones.editar'],
    ];

    /** Horas que vive una propuesta antes de vencer. Aprobar tarde algo basado en datos viejos es un riesgo. */
    public static function horasDeVida(): int
    {
        return max(1, (int) Configuracion::get('equipo_ia_vence_horas', 72));
    }

    /** @return array<string, mixed> lo que se le devuelve al modelo */
    public function tareaCrm(AgenteInterno $agente, ?AgenteEjecucion $ejecucion, array $a, bool $prueba): array
    {
        $titulo      = Str::limit(trim((string) ($a['titulo'] ?? '')), 190, '');
        $descripcion = Str::limit(trim((string) ($a['descripcion'] ?? '')), 1500, '');
        $dias        = max(0, min(30, (int) ($a['dias'] ?? 1)));

        if ($titulo === '') {
            return ['exito' => false, 'motivo' => 'Falta el título de la tarea.'];
        }

        $lead = ContextoSede::aplicar(CrmLead::query())->with('responsable:id,name')->find((int) ($a['lead_id'] ?? 0));

        if (! $lead) {
            return ['exito' => false, 'motivo' => 'No existe ese lead (usa el id que devuelve leads_quietos).'];
        }

        if ($lead->estado !== 'activo') {
            return ['exito' => false, 'motivo' => 'Ese lead ya está cerrado; no se le propone seguimiento.'];
        }

        return $this->crear($agente, $ejecucion, 'tarea_crm', [
            'lead_id' => $lead->id, 'titulo' => $titulo, 'descripcion' => $descripcion, 'dias' => $dias,
        ], "Tarea en el lead «{$lead->titulo}»: {$titulo}", 'crm_lead', $lead->id, $lead->responsable_id, $prueba);
    }

    /** @return array<string, mixed> */
    public function notaCotizacion(AgenteInterno $agente, ?AgenteEjecucion $ejecucion, array $a, bool $prueba): array
    {
        $nota = Str::limit(trim((string) ($a['nota'] ?? '')), 1500, '');

        if ($nota === '') {
            return ['exito' => false, 'motivo' => 'Falta el texto de la nota.'];
        }

        $cot = ContextoSede::aplicar(Cotizacion::query())->find((int) ($a['cotizacion_id'] ?? 0));

        if (! $cot) {
            return ['exito' => false, 'motivo' => 'No existe esa cotización (usa el id que devuelve cotizaciones_sin_respuesta).'];
        }

        if ($cot->estado !== 'enviada') {
            return ['exito' => false, 'motivo' => "La cotización {$cot->numero} ya no está en estado «enviada»."];
        }

        return $this->crear($agente, $ejecucion, 'nota_cotizacion', [
            'cotizacion_id' => $cot->id, 'nota' => $nota,
        ], "Nota de seguimiento en {$cot->numero}: " . Str::limit($nota, 120), 'cotizacion', $cot->id, $cot->responsable_id, $prueba);
    }

    /** @return array<string, mixed> */
    private function crear(
        AgenteInterno $agente,
        ?AgenteEjecucion $ejecucion,
        string $accion,
        array $parametros,
        string $resumen,
        string $entidadType,
        int $entidadId,
        ?int $destinatarioId,
        bool $prueba,
    ): array {
        // La misma propuesta no se repite: ni mientras hay una pendiente, ni la semana siguiente
        // a haber sido decidida. Sin esto, un agente diario le pediría cada mañana lo mismo a
        // quien ya dijo que no.
        $yaExiste = AgentePropuesta::where('agente_id', $agente->id)
            ->where('accion', $accion)
            ->where('entidad_type', $entidadType)
            ->where('entidad_id', $entidadId)
            ->where(fn ($q) => $q->where('estado', 'pendiente')->orWhere('created_at', '>=', now()->subDays(7)))
            ->exists();

        if ($yaExiste) {
            return ['exito' => false, 'motivo' => 'Ya hay una propuesta reciente para este documento; no se repite.'];
        }

        if ($prueba) {
            return ['exito' => true, 'simulada' => true, 'mensaje' => 'Prueba: la propuesta NO se guardó.'];
        }

        $p = AgentePropuesta::create([
            'agente_id'       => $agente->id,
            'ejecucion_id'    => $ejecucion?->id,
            'accion'          => $accion,
            'parametros'      => $parametros,
            'resumen'         => $resumen,
            'entidad_type'    => $entidadType,
            'entidad_id'      => $entidadId,
            'destinatario_id' => $destinatarioId,
            'estado'          => 'pendiente',
            'vence_at'        => now()->addHours(self::horasDeVida()),
        ]);

        return ['exito' => true, 'propuesta_id' => $p->id, 'mensaje' => 'Propuesta registrada. Una persona la revisará; todavía NO se ha hecho nada.'];
    }
}
