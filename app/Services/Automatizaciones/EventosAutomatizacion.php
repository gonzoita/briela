<?php

namespace App\Services\Automatizaciones;

use App\Models\Automatizacion;
use App\Models\Cotizacion;
use App\Models\CrmLead;
use App\Models\Op;
use App\Models\OpPago;
use App\Support\Modulos;
use Illuminate\Database\Eloquent\Model;

/**
 * Por donde los eventos del negocio llegan a las automatizaciones.
 *
 * **Tiene que ser baratísimo**: se llama en cada guardado de una cotización, un lead, una orden o un
 * pago. Con el módulo apagado no hace nada; con el módulo encendido pero sin automatizaciones que
 * escuchen ese evento, tampoco —el mapa «evento → automatizaciones activas» sale de UNA consulta por
 * petición y se recuerda en el contenedor, nunca en una estática—. Y **jamás rompe lo que lo
 * disparó**: guardar una cotización no puede fallar porque una automatización esté mal armada.
 */
class EventosAutomatizacion
{
    /** Engancha los eventos a los modelos. Se llama una vez, desde `AppServiceProvider`. */
    public static function registrar(): void
    {
        // Crear un documento ya en un estado también es «cambiar de estado»: `wasChanged` no lo ve en una
        // inserción, y una cotización que nace «enviada» (una copia, una importación) debe contar.
        // Los oyentes NO devuelven nada: en Eloquent, un `false` corta a los demás oyentes del mismo
        // evento (los observers), y una automatización jamás puede frenar el guardado ni sus efectos.
        Cotizacion::saved(function (Cotizacion $c) {
            if ($c->wasRecentlyCreated || $c->wasChanged('estado')) {
                self::emitir('cotizacion.estado', $c);
            }
        });
        CrmLead::created(function (CrmLead $l) {
            self::emitir('lead.nuevo', $l);
        });
        CrmLead::saved(function (CrmLead $l) {
            if (! $l->wasRecentlyCreated && $l->wasChanged('etapa_id')) {
                self::emitir('lead.etapa', $l);
            }
        });
        Op::saved(function (Op $o) {
            if ($o->wasRecentlyCreated || $o->wasChanged('estado')) {
                self::emitir('op.estado', $o);
            }
        });
        OpPago::created(function (OpPago $p) {
            self::emitir('pago.registrado', $p);
        });
    }

    public static function emitir(string $evento, Model $modelo): bool
    {
        try {
            if (! Modulos::activo('automatizaciones')) {
                return false;
            }

            $ids = self::activasPara($evento);

            if ($ids === []) {
                return false;
            }

            // Una automatización que provoca un evento que dispara otra… tiene un tope de generaciones.
            $profundidad = app()->bound('briela.automatizacion.profundidad') ? (int) app('briela.automatizacion.profundidad') : 0;

            if ($profundidad >= LanzadorAutomatizaciones::PROFUNDIDAD_MAXIMA) {
                return false;
            }

            $contexto = CatalogoAutomatizaciones::contextoDe($modelo);

            if ($contexto === null) {
                return false;
            }

            $lanzador = app(LanzadorAutomatizaciones::class);

            foreach (Automatizacion::whereIn('id', $ids)->get() as $a) {
                $lanzador->desdeEvento($a, $evento, $contexto, $profundidad);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return true;
    }

    /** @return list<int> */
    private static function activasPara(string $evento): array
    {
        $clave = 'briela.automatizaciones.activas';

        if (! app()->bound($clave)) {
            try {
                $mapa = Automatizacion::where('activa', true)->where('disparador_tipo', 'evento')->get(['id', 'disparador_clave'])
                    ->groupBy('disparador_clave')->map(fn ($g) => $g->pluck('id')->all())->all();
            } catch (\Throwable) {
                // La tabla puede no existir todavía (una actualización a medias): no es motivo para fallar.
                $mapa = [];
            }

            app()->instance($clave, $mapa);
        }

        return app($clave)[$evento] ?? [];
    }
}
