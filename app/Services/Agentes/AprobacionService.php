<?php

namespace App\Services\Agentes;

use App\Models\AgentePropuesta;
use App\Models\Cotizacion;
use App\Models\CotizacionSeguimiento;
use App\Models\CrmLead;
use App\Models\CrmTarea;
use App\Models\RegistroActividad;
use App\Models\User;
use App\Support\ContextoSede;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La decisión de una persona sobre una propuesta de un agente.
 *
 * Al aprobar se **vuelve a resolver todo** —permiso, sede, estado actual del documento, plazo—,
 * porque entre la propuesta y el clic pasaron horas y los datos pudieron cambiar. Lo que se
 * ejecuta sale a nombre de **quien aprobó**, no del agente, y queda en la auditoría.
 *
 * Exige DOS permisos: `equipo_ia.aprobar` (decidir sobre lo que propone un agente) y el de la
 * acción real (`crm.editar`, `cotizaciones.editar`…). Poder aprobar propuestas no debe ser una
 * puerta lateral a algo que la persona no podría hacer en la pantalla.
 */
class AprobacionService
{
    /** @return array{exito: bool, mensaje: string} */
    public function aprobar(AgentePropuesta $propuesta, User $quien): array
    {
        if (! $quien->tienePermiso('equipo_ia.aprobar')) {
            return $this->no('No tienes permiso para aprobar propuestas de los agentes.');
        }

        $accion = PropuestasAgenteService::ACCIONES[$propuesta->accion] ?? null;

        if (! $accion) {
            return $this->no('Esta propuesta es de una acción que esta versión no conoce.');
        }

        if (! $quien->tienePermiso($accion['permiso'])) {
            return $this->no('No tienes permiso para hacer lo que propone el agente (' . $accion['permiso'] . ').');
        }

        return DB::transaction(function () use ($propuesta, $quien) {
            // El candado evita que dos personas aprueben la misma propuesta a la vez.
            $p = AgentePropuesta::lockForUpdate()->find($propuesta->id);

            if (! $p || $p->estado !== 'pendiente') {
                return $this->no('Esta propuesta ya fue decidida.');
            }

            if ($p->estaVencida()) {
                $p->update(['estado' => 'vencida']);

                return $this->no('La propuesta venció: los datos pudieron cambiar. Pídele al agente que la vuelva a hacer.');
            }

            try {
                $resultado = match ($p->accion) {
                    'tarea_crm'       => $this->crearTarea($p, $quien),
                    'nota_cotizacion' => $this->crearNota($p, $quien),
                };
            } catch (\RuntimeException $e) {
                // Lo que ya no se puede hacer (el lead se cerró, la cotización cambió de estado)
                // no deja la propuesta pendiente para siempre: queda como fallida con el motivo.
                $p->update(['estado' => 'fallida', 'decidida_por' => $quien->id, 'decidida_at' => now(), 'resultado' => $e->getMessage()]);

                return $this->no($e->getMessage());
            }

            $p->update(['estado' => 'aprobada', 'decidida_por' => $quien->id, 'decidida_at' => now(), 'resultado' => $resultado]);

            RegistroActividad::registrar('aprobar_propuesta_agente', 'AgentePropuesta', $p->id, "Aprobó una propuesta de un agente: {$p->resumen}", ['accion' => $p->accion]);

            return ['exito' => true, 'mensaje' => $resultado];
        });
    }

    /** @return array{exito: bool, mensaje: string} */
    public function rechazar(AgentePropuesta $propuesta, User $quien, ?string $motivo = null): array
    {
        if (! $quien->tienePermiso('equipo_ia.aprobar')) {
            return $this->no('No tienes permiso para decidir propuestas de los agentes.');
        }

        $filas = AgentePropuesta::where('id', $propuesta->id)->where('estado', 'pendiente')->update([
            'estado'       => 'rechazada',
            'decidida_por' => $quien->id,
            'decidida_at'  => now(),
            'motivo'       => $motivo ? Str::limit(trim($motivo), 500, '') : null,
        ]);

        if (! $filas) {
            return $this->no('Esta propuesta ya fue decidida.');
        }

        RegistroActividad::registrar('rechazar_propuesta_agente', 'AgentePropuesta', $propuesta->id, "Rechazó una propuesta de un agente: {$propuesta->resumen}");

        return ['exito' => true, 'mensaje' => 'Propuesta rechazada. El agente no la repetirá esta semana.'];
    }

    /** Marca como vencidas las propuestas pendientes cuyo plazo pasó. Devuelve cuántas. */
    public function vencerLasViejas(): int
    {
        return AgentePropuesta::where('estado', 'pendiente')->whereNotNull('vence_at')->where('vence_at', '<', now())
            ->update(['estado' => 'vencida']);
    }

    // ─── Lo que hace cada acción ─────────────────────────────────────────────

    private function crearTarea(AgentePropuesta $p, User $quien): string
    {
        $lead = ContextoSede::aplicar(CrmLead::query())->find($p->parametros['lead_id'] ?? 0);

        if (! $lead) {
            throw new \RuntimeException('El lead ya no existe o es de una sede que no puedes ver.');
        }

        if ($lead->estado !== 'activo') {
            throw new \RuntimeException('El lead ya se cerró; no tiene sentido crear la tarea.');
        }

        CrmTarea::create([
            'lead_id'           => $lead->id,
            'responsable_id'    => $lead->responsable_id ?: $quien->id,
            'titulo'            => $p->parametros['titulo'],
            'descripcion'       => $p->parametros['descripcion'] ?? null,
            'tipo'              => 'seguimiento',
            'fecha_vencimiento' => now()->addDays((int) ($p->parametros['dias'] ?? 1))->toDateString(),
        ]);

        return "Tarea creada en el lead «{$lead->titulo}».";
    }

    private function crearNota(AgentePropuesta $p, User $quien): string
    {
        $cot = ContextoSede::aplicar(Cotizacion::query())->find($p->parametros['cotizacion_id'] ?? 0);

        if (! $cot) {
            throw new \RuntimeException('La cotización ya no existe o es de una sede que no puedes ver.');
        }

        if ($cot->estado !== 'enviada') {
            throw new \RuntimeException("La cotización {$cot->numero} ya no está «enviada» (hoy: {$cot->estado}).");
        }

        CotizacionSeguimiento::create([
            'cotizacion_id' => $cot->id,
            'user_id'       => $quien->id,
            'nota'          => $p->parametros['nota'],
        ]);

        return "Nota agregada a la cotización {$cot->numero}.";
    }

    /** @return array{exito: false, mensaje: string} */
    private function no(string $mensaje): array
    {
        return ['exito' => false, 'mensaje' => $mensaje];
    }
}
