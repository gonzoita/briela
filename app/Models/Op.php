<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\OpCuota;
use App\Models\OpPago;

class Op extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'ops';

    protected $fillable = [
        'sede_id',
        'numero',
        'token_publico',
        'cliente_id',
        'cotizacion_id',
        'responsable_id',
        'bodega_entrega_id',
        'bodega_material_id',
        'estado',
        'fecha_creacion',
        'fecha_entrega_estimada',
        'anticipo',
        'condiciones',
        'notas_internas',
        'observaciones_calidad',
        'motivo_rechazo',
        'calidad_aprobada_at',
        'subtotal',
        'descuento_total',
        'impuesto_total',
        'total',
        'porcentaje_avance',
    ];

    protected $casts = [
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha_creacion'         => 'date:Y-m-d',
        'fecha_entrega_estimada' => 'date:Y-m-d',
        'anticipo'               => 'decimal:2',
        'subtotal'               => 'decimal:2',
        'descuento_total'        => 'decimal:2',
        'impuesto_total'         => 'decimal:2',
        'total'                  => 'decimal:2',
        'porcentaje_avance'      => 'decimal:2',
        'calidad_aprobada_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $op) {
            // La OP se fabrica en la sede activa del usuario.
            $op->sede_id ??= \App\Support\ContextoSede::paraGuardar();

            if (empty($op->numero)) {
                // Consecutivo configurable por sede (Configuración → Numeración).
                $op->numero = app(\App\Services\SecuenciaService::class)
                    ->siguiente('op', $op->sede_id);
            }
            if (empty($op->token_publico)) {
                $op->token_publico = \Illuminate\Support\Str::random(40);
            }
        });
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    /**
     * A qué bodega entra lo que fabrica esta OP.
     *
     * Es la decisión de quien planea la producción, y se toma al confirmar la orden. Antes la
     * tomaba el operario al cerrar el último paso de cada unidad.
     */
    public function bodegaEntrega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_entrega_id');
    }

    /**
     * De qué bodega sale el material que consume esta OP.
     *
     * Es la pareja de `bodegaEntrega()`: una dice a dónde entra lo fabricado, esta de dónde
     * salen los insumos. Confundirlas produce un descuento que no descuenta nada.
     */
    public function bodegaMaterial(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_material_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OpItem::class)->orderBy('orden');
    }

    public function recalcularTotales(): void
    {
        $this->load('items');
        $this->subtotal       = $this->items->sum('subtotal');
        $this->descuento_total = $this->items->sum(fn ($i) => $i->subtotal * ($i->descuento_pct / 100));
        $this->impuesto_total  = $this->items->sum('impuesto_valor');
        $this->total           = $this->items->sum('total_linea');
        $this->save();
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(OpCuota::class)->orderBy('numero_cuota');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(OpPago::class)->orderBy('created_at');
    }

    public function archivos(): MorphMany
    {
        return $this->morphMany(Archivo::class, 'archivable')->latest();
    }

    public function getTotalPagadoAttribute(): float
    {
        return (float) $this->pagos()->sum('valor');
    }

    public function getSaldoPendienteAttribute(): float
    {
        return (float) ($this->total ?? 0) - $this->total_pagado;
    }

    public function sincronizarSaldoPendiente(): void
    {
        $totalOp = (float) ($this->total ?? 0);
        if ($totalOp <= 0) return;

        $totalCuotas = (float) $this->cuotas()
            ->where('es_saldo_automatico', false)
            ->sum('valor');

        $diferencia = $totalOp - $totalCuotas;

        $cuotaAuto = $this->cuotas()
            ->where('es_saldo_automatico', true)
            ->first();

        if ($diferencia > 0.01) {
            $pagadoAuto = $cuotaAuto ? (float) $cuotaAuto->valor_pagado : 0;
            $estadoAuto = $pagadoAuto >= $diferencia ? 'pagado'
                : ($pagadoAuto > 0 ? 'parcial' : 'pendiente');

            if ($cuotaAuto) {
                $cuotaAuto->update([
                    'valor'  => $diferencia,
                    'estado' => $estadoAuto,
                ]);
            } else {
                $numero = ($this->cuotas()->max('numero_cuota') ?? 0) + 1;
                OpCuota::create([
                    'op_id'               => $this->id,
                    'numero_cuota'        => $numero,
                    'concepto'            => 'Saldo pendiente',
                    'valor'               => $diferencia,
                    'fecha_vencimiento'   => null,
                    'estado'              => 'pendiente',
                    'valor_pagado'        => 0,
                    'es_saldo_automatico' => true,
                ]);
            }
        } else {
            if ($cuotaAuto && (float) $cuotaAuto->valor_pagado == 0) {
                $cuotaAuto->delete();
            }
        }
    }

    /**
     * Descuenta del inventario los insumos usados según la receta de cada
     * ensamble. Antes vivía como método privado en OpController y solo se
     * llamaba desde el cambio manual de estado a "despachada" — ahora que
     * ese estado también se puede alcanzar automáticamente al completar una
     * remisión (ver RemisionController::revisarEstadoOp), vive aquí para
     * que ambos caminos lo disparen de la misma forma.
     */
    /**
     * ¿Los ítems de esta orden ya no se pueden tocar?
     *
     * Deja de poder cambiarse cuando hay trabajo hecho: cambiar la receta con pasos
     * completados dejaría los tiempos y las fotos de los operarios apuntando a algo que ya no
     * es lo que se está fabricando, y si una unidad entró a bodega, cambiarla descuadraría el
     * inventario hacia atrás.
     *
     * Se mira el estado **y** el trabajo real: una OP puede estar en `confirmada` y tener un
     * operario que ya cerró un paso, y ahí también hay algo que proteger.
     */
    public function itemsBloqueados(): bool
    {
        if (in_array($this->estado, ['en_produccion', 'calidad', 'reproceso', 'despachada'], true)) {
            return true;
        }

        return OpItemTrabajoPaso::whereHas('trabajo.opItem', fn ($q) => $q->where('op_id', $this->id))
            ->where('completado', true)
            ->exists();
    }

    public function consumirMaterialesInventario(): void
    {
        $bodegaPrincipal = Bodega::principal();
        if (! $bodegaPrincipal) return;

        $this->load(['items.ensamble']);

        \Illuminate\Support\Facades\DB::transaction(function () use ($bodegaPrincipal) {
            foreach ($this->items as $item) {
                if (! $item->ensamble_id) continue;

                // Lo que se fabricó en esta OP ya descontó su material al terminarse, cuando
                // la unidad entró a bodega (ver `EntregaAlmacenService`). Volver a consumirlo
                // aquí lo descontaría dos veces.
                if ($item->trabajos()->whereNotNull('entregado_at')->exists()) {
                    $this->descontarDelTerminado($item, $bodegaPrincipal);

                    continue;
                }

                // Y si no se fabricó aquí pero hay unidades armadas en bodega, se despacha de
                // ahí: su material se gastó el día que se armaron.
                if ($this->descontarDelTerminado($item, $bodegaPrincipal)) continue;

                $componentes = $item->componentes_snapshot ?? [];
                if (empty($componentes) && $item->ensamble) {
                    $componentes = $item->ensamble->componentes_resultado ?? [];
                }

                foreach ($componentes as $comp) {
                    $componenteId = $comp['producto_id'] ?? $comp['componente_id'] ?? null;
                    $cantReceta   = (float) ($comp['cantidad'] ?? 0);
                    if (! $componenteId || $cantReceta <= 0) continue;

                    $producto = Producto::find($componenteId);
                    if (! $producto || ! $producto->es_insumo) continue;

                    $cantTotal = $cantReceta * (float) $item->cantidad;

                    $producto->registrarMovimiento(
                        tipo: 'consumo_ensamble',
                        cantidad: $cantTotal,
                        bodegaId: $bodegaPrincipal->id,
                        usuarioId: auth()->id(),
                        origenTipo: 'op',
                        origenId: $this->id,
                        notas: "Consumo por OP #{$this->id}"
                    );
                }
            }
        });
    }

    /**
     * Despacha un ítem desde el stock de unidades ya armadas, si alcanza.
     *
     * Devuelve true cuando lo hizo, y entonces los materiales de ese ítem **no** se
     * consumen: se gastaron el día que la unidad se armó.
     *
     * Si el ensamble se guarda en bodega pero no hay suficientes armadas, devuelve false y se
     * consumen los materiales como siempre — esa vez se fabricó contra pedido. Es la opción
     * honesta: la alternativa sería dejar el stock en negativo para sostener la ficción de
     * que estaba armado.
     */
    private function descontarDelTerminado(OpItem $item, Bodega $bodega): bool
    {
        $ensamble = $item->ensamble;

        if (! $ensamble || ! $ensamble->maneja_stock) {
            return false;
        }

        $terminado = $ensamble->productoTerminado()->first();

        if (! $terminado) {
            return false;
        }

        $piden = (float) $item->cantidad;

        // Se saca de la bodega donde entraron las unidades al fabricarse, que puede no ser la
        // principal: el último paso del flujo decide a cuál entran.
        $entregada = $item->trabajos()->whereNotNull('bodega_entrega_id')->value('bodega_entrega_id');

        if ($entregada) {
            $bodega = Bodega::find($entregada) ?? $bodega;
        }

        if ($piden <= 0 || $terminado->stockEnBodega($bodega->id) < $piden) {
            return false;
        }

        $terminado->registrarMovimiento(
            tipo: 'salida',
            cantidad: $piden,
            bodegaId: $bodega->id,
            usuarioId: auth()->id(),
            origenTipo: 'op',
            origenId: $this->id,
            notas: "Despacho de unidades armadas · OP #{$this->id}"
        );

        return true;
    }

    /**
     * Suma cuánto insumo pide cada ítem (según la receta del ensamble) y lo
     * compara contra el stock real disponible — antes esto no existía en el
     * sistema nuevo (Op/OpItem): nadie se enteraba de que faltaba material
     * hasta que un operario lo notaba en planta. Solo avisa, no bloquea
     * nada; no reserva stock contra otras OPs pendientes, es una foto del
     * momento.
     *
     * @return array<int, array{producto_id:int, nombre:string, unidad:string, necesario:float, disponible:float, faltante:float}>
     */
    public function insumosFaltantes(): array
    {
        $this->loadMissing(['items.ensamble']);

        $necesarios = []; // producto_id => cantidad total requerida

        foreach ($this->items as $item) {
            if (! $item->ensamble_id) continue;

            $componentes = $item->componentes_snapshot ?? [];
            if (empty($componentes) && $item->ensamble) {
                $componentes = $item->ensamble->componentes_resultado ?? [];
            }

            foreach ($componentes as $comp) {
                $componenteId = $comp['producto_id'] ?? $comp['componente_id'] ?? null;
                $cantReceta   = (float) ($comp['cantidad'] ?? 0);
                if (! $componenteId || $cantReceta <= 0) continue;

                $cantTotal = $cantReceta * (float) $item->cantidad;
                $necesarios[$componenteId] = ($necesarios[$componenteId] ?? 0) + $cantTotal;
            }
        }

        if (empty($necesarios)) return [];

        $productos = Producto::whereIn('id', array_keys($necesarios))
            ->where('es_insumo', true)
            ->get()
            ->keyBy('id');

        $faltantes = [];
        foreach ($necesarios as $productoId => $cantNecesaria) {
            $producto = $productos->get($productoId);
            if (! $producto) continue;

            $disponible = $producto->stockTotal();
            if ($disponible >= $cantNecesaria) continue;

            $faltantes[] = [
                'producto_id' => $producto->id,
                'nombre'      => $producto->nombre,
                'unidad'      => $producto->unidad_medida,
                'necesario'   => round($cantNecesaria, 3),
                'disponible'  => round($disponible, 3),
                'faltante'    => round($cantNecesaria - $disponible, 3),
            ];
        }

        return $faltantes;
    }

    /**
     * Si ya no queda ningún ítem pendiente de terminar producción, la OP
     * pasa sola de "en_produccion" a "calidad" — antes había que entrar a
     * la OP y cambiar el estado a mano aunque el trabajo ya estuviera
     * completo.
     */
    public function revisarTransicionCalidad(): void
    {
        if ($this->estado !== 'en_produccion') return;

        $totalItems = $this->items()->count();
        if ($totalItems === 0) return;

        $terminados = $this->items()->where('estado_item', 'terminado')->count();
        if ($terminados === $totalItems) {
            $this->update(['estado' => 'calidad']);

            // Aviso a producción/calidad: la OP terminó y espera revisión.
            app(\App\Services\NotificacionService::class)->paraRol(
                ['administrador', 'jefe_produccion'],
                'op_a_calidad',
                "OP {$this->numero} lista para calidad",
                'Terminó producción y espera control de calidad.',
                "/produccion/ops/{$this->id}",
            );
        }
    }

    /**
     * Si ya se registró avance real en algún trabajo (un operario completó
     * un paso), la OP ya no debería seguir mostrando "Borrador" o
     * "Confirmada" — el trabajo en planta es la prueba de que la
     * producción arrancó, con o sin el clic manual de "Cambiar estado".
     */
    public function iniciarProduccionSiAplica(): void
    {
        if (in_array($this->estado, ['borrador', 'confirmada'], true)) {
            $this->update(['estado' => 'en_produccion']);
        }
    }

    /**
     * Progreso general de la OP = promedio del avance de todos los
     * trabajos de todos sus ítems. Antes se recalculaba solo desde algunos
     * controladores puntuales (asignar template, registrar tiempo) y se
     * quedaba en 0% si el cambio venía de otro camino — ahora se llama
     * siempre desde OpItemTrabajo::recalcularAvance(), que es el punto
     * único por el que pasa cualquier cambio de avance.
     */
    public function recalcularProgreso(): void
    {
        $promedio = OpItemTrabajo::whereHas('opItem', fn ($q) => $q->where('op_id', $this->id))
            ->avg('porcentaje_avance');

        $this->update(['porcentaje_avance' => round($promedio ?? 0, 2)]);
    }

    public function nombreParaAuditoria(): string
    {
        return $this->numero;
    }

    /**
     * Forma de datos usada por las dos pantallas de seguimiento público
     * (por token QR en /op/{token} y por número en /seguimiento) — vive acá
     * para que ambas muestren siempre la misma información y no se
     * desincronicen entre sí. Requiere 'cliente', 'responsable' e
     * 'items.trabajos' cargados de antemano.
     */
    public function datosSeguimientoPublico(): array
    {
        return [
            'numero'             => $this->numero,
            'estado'             => $this->estado,
            'estado_badge'       => $this->estadoBadge(),
            'cliente_nombre'     => $this->cliente
                ? trim($this->cliente->nombre . ' ' . $this->cliente->apellido)
                : null,
            'responsable_nombre' => $this->responsable?->name,
            'fecha_creacion'     => $this->fecha_creacion?->format('d/m/Y'),
            'fecha_entrega'      => $this->fecha_entrega_estimada?->format('d/m/Y'),
            'porcentaje_avance'  => (float) ($this->porcentaje_avance ?? 0),
            'items'              => $this->items->map(fn ($item) => [
                'descripcion'  => $item->descripcion,
                'cantidad'     => (float) $item->cantidad,
                'estado_item'  => $item->estado_item,
                'trabajos'     => $item->trabajos->map(fn ($t) => [
                    'numero_unidad'     => $t->numero_unidad,
                    'total_unidades'    => $t->total_unidades,
                    'porcentaje_avance' => (float) $t->porcentaje_avance,
                ])->values(),
            ])->values(),
        ];
    }

    public function estadoBadge(): array
    {
        return match ($this->estado) {
            'borrador'     => ['label' => 'Borrador',      'color' => 'gray'],
            'confirmada'   => ['label' => 'Confirmada',    'color' => 'blue'],
            'en_produccion'=> ['label' => 'En producción', 'color' => 'yellow'],
            'calidad'      => ['label' => 'Calidad',       'color' => 'purple'],
            'reproceso'    => ['label' => 'Reproceso',     'color' => 'orange'],
            'despachada'   => ['label' => 'Despachada',    'color' => 'green'],
            default        => ['label' => $this->estado,   'color' => 'gray'],
        };
    }
}
