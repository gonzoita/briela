<?php

namespace App\Services\Automatizaciones;

use App\Models\AgentePropuesta;
use App\Models\Automatizacion;
use App\Models\BandejaMensaje;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CrmLead;
use App\Models\Inscripcion;
use App\Models\Mantenimiento;
use App\Models\Op;
use App\Models\OpItemTrabajo;
use App\Models\OpPago;
use App\Models\OperarioPermiso;
use App\Models\OrdenCompra;
use App\Models\ProductoStock;
use App\Models\Remision;
use App\Models\SolicitudCompra;
use App\Models\WhatsappMensaje;
use App\Support\Modulos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Por donde los eventos del negocio llegan a las automatizaciones.
 *
 * **Tiene que ser baratísimo**: se llama en cada guardado de los documentos que escucha. Con el
 * módulo apagado no hace nada; con el módulo encendido pero sin automatizaciones que escuchen ese
 * evento, tampoco —el mapa «evento → automatizaciones activas» sale de UNA consulta por petición y
 * se recuerda en el contenedor, nunca en una estática—. Por eso el contexto del documento (que sí
 * consulta) se arma DESPUÉS de saber que alguien escucha. Y **jamás rompe lo que lo disparó**:
 * guardar una cotización no puede fallar porque una automatización esté mal armada.
 *
 * Los oyentes NO devuelven nada: en Eloquent, un `false` corta a los demás oyentes del mismo evento
 * (los observers), y una automatización jamás puede frenar el guardado ni sus efectos.
 */
class EventosAutomatizacion
{
    /** Engancha los eventos a los modelos. Se llama una vez, desde `AppServiceProvider`. */
    public static function registrar(): void
    {
        // «Cambia el estado» también es nacer en un estado: una cotización que nace «enviada» (una
        // copia, una importación) debe contar. Se escuchan `created` y `updated` por separado, NO
        // `saved` con `wasRecentlyCreated`: esa marca sigue en `true` en la misma instancia después de
        // crearla, y un `update()` posterior en la misma petición pasaba por «recién creado».
        $porEstado = [
            Cotizacion::class      => 'cotizacion.estado',
            Op::class              => 'op.estado',
            Remision::class        => 'remision.estado',
            SolicitudCompra::class => 'solicitud_compra.estado',
            OrdenCompra::class     => 'orden_compra.estado',
            Mantenimiento::class   => 'mantenimiento.estado',
            Inscripcion::class     => 'inscripcion.estado',
        ];

        foreach ($porEstado as $clase => $evento) {
            $clase::created(function (Model $m) use ($evento) {
                self::emitir($evento, $m, ['estado_anterior' => '']);
            });
            $clase::updated(function (Model $m) use ($evento) {
                if ($m->wasChanged('estado')) {
                    self::emitir($evento, $m, ['estado_anterior' => (string) $m->getOriginal('estado')]);
                }
            });
        }

        CrmLead::created(function (CrmLead $l) {
            self::emitir('lead.nuevo', $l);
        });
        CrmLead::updated(function (CrmLead $l) {
            if ($l->wasChanged('etapa_id')) {
                $anterior = $l->getOriginal('etapa_id');
                self::emitir('lead.etapa', $l, fn () => ['etapa_anterior' => $anterior ? (string) DB::table('crm_etapas')->where('id', $anterior)->value('nombre') : '']);
            }

            if ($l->wasChanged('estado') && in_array($l->estado, ['ganado', 'perdido'], true)) {
                self::emitir('lead.cerrado', $l);
            }
        });

        Cliente::created(function (Cliente $c) {
            self::emitir('cliente.nuevo', $c);
        });

        OpItemTrabajo::updated(function (OpItemTrabajo $t) {
            if ($t->wasChanged('terminado_at') && $t->terminado_at && ! $t->getOriginal('terminado_at')) {
                self::emitir('unidad.terminada', $t);
            }

            if ($t->wasChanged('calidad_revisada_at') && $t->calidad_revisada_at && ! $t->getOriginal('calidad_revisada_at')) {
                self::emitir('unidad.firmada', $t);
            }
        });

        OpPago::created(function (OpPago $p) {
            self::emitir('pago.registrado', $p);
        });
        OpPago::updated(function (OpPago $p) {
            if ($p->wasChanged('verificado_at') && $p->verificado_at && ! $p->getOriginal('verificado_at')) {
                self::emitir('pago.verificado', $p);
            }
        });

        // El mínimo es del producto (la suma de todas sus bodegas): avisa cuando el total CRUZA hacia
        // abajo, no cada vez que se mueve algo que ya estaba bajo.
        $stock = function (ProductoStock $s, bool $nuevo) {
            if (! $nuevo && ! $s->wasChanged('cantidad')) {
                return;
            }

            self::emitir('stock.bajo', null, null, function () use ($s, $nuevo) {
                $p = $s->producto;

                if (! $p || (float) $p->stock_minimo <= 0) {
                    return null;
                }

                $total    = (float) DB::table('producto_stock')->where('producto_id', $p->id)->sum('cantidad');
                $antes    = $total - (float) $s->cantidad + ($nuevo ? 0 : (float) $s->getOriginal('cantidad'));
                $minimo   = (float) $p->stock_minimo;

                return $total <= $minimo && $antes > $minimo ? $p : null;
            });
        };
        ProductoStock::created(fn (ProductoStock $s) => $stock($s, true));
        ProductoStock::updated(fn (ProductoStock $s) => $stock($s, false));

        OperarioPermiso::created(function (OperarioPermiso $p) {
            self::emitir('permiso.solicitado', $p);
        });

        // Un mensaje que ENTRA (no los que mandamos, ni los ecos de lo que mandamos desde el celular).
        foreach ([WhatsappMensaje::class, BandejaMensaje::class] as $clase) {
            $clase::created(function (Model $m) {
                if ($m->direccion === 'entrante' && ! $m->es_echo) {
                    self::emitir('mensaje.entrante', null, null, fn () => $m->conversacion);
                }
            });
        }

        AgentePropuesta::created(function (AgentePropuesta $p) {
            self::emitir('propuesta.nueva', $p);
        });
    }

    /**
     * @param  Model|null  $modelo  el documento; null si hay que buscarlo (solo si alguien escucha)
     * @param  array<string, mixed>|callable|null  $extra  lo que el evento sabe y la base ya no
     * @param  (callable(): ?Model)|null  $resolver  cómo llegar al documento cuando no es el que se guardó
     */
    public static function emitir(string $evento, ?Model $modelo, array|callable|null $extra = null, ?callable $resolver = null): void
    {
        try {
            if (! Modulos::activo('automatizaciones')) {
                return;
            }

            $ids = self::activasPara($evento);

            if ($ids === []) {
                return;
            }

            // Una automatización que provoca un evento que dispara otra… tiene un tope de generaciones.
            $profundidad = app()->bound('briela.automatizacion.profundidad') ? (int) app('briela.automatizacion.profundidad') : 0;

            if ($profundidad >= LanzadorAutomatizaciones::PROFUNDIDAD_MAXIMA) {
                return;
            }

            $modelo ??= $resolver ? $resolver() : null;

            if ($modelo === null) {
                return;
            }

            $contexto = EntidadesAutomatizacion::contexto($modelo, is_callable($extra) ? $extra() : ($extra ?? []));

            if ($contexto === null) {
                return;
            }

            $lanzador = app(LanzadorAutomatizaciones::class);

            foreach (Automatizacion::whereIn('id', $ids)->get() as $a) {
                $lanzador->desdeEvento($a, $evento, $contexto, $profundidad);
            }
        } catch (\Throwable $e) {
            report($e);
        }
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
