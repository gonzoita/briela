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
        'inventario_consumido_at',
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
        'inventario_consumido_at' => 'datetime',
    ];

    /** Lo que no viaja en una remisión: se declara hecho, pero no hay nada que cargar. */
    public const TIPOS_SIN_DESPACHO = ['texto_libre', 'servicio'];

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
        // Una sola vez por orden. Una remisión anulada devuelve la orden a calidad, y al volver a
        // despacharla el material se descontaba otra vez: el inventario bajaba el doble.
        if ($this->inventario_consumido_at || ! \App\Support\Modulos::activo('inventario')) return;

        $bodegaPrincipal = Bodega::principal();
        if (! $bodegaPrincipal) return;

        $this->update(['inventario_consumido_at' => now()]);

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
    /**
     * Las unidades que calidad rechazó: las que tienen algún punto en falla.
     *
     * No hace falta una columna que lo marque — la revisión ya lo dice, punto por punto y por
     * unidad. Un campo aparte sería una segunda versión de la misma verdad, y las dos se
     * separarían el día que alguien corrija una falla sin acordarse de bajar la bandera.
     */
    public function unidadesEnReproceso()
    {
        return OpItemTrabajo::whereHas('opItem', fn ($q) => $q->where('op_id', $this->id))
            ->whereHas('checks', fn ($q) => $q->where('resultado', 'falla'))
            ->get();
    }

    /** Compatibilidad: el paso a calidad ahora es parte del estado único. */
    public function revisarTransicionCalidad(): void
    {
        $this->sincronizarEstado();
    }

    /**
     * Pone el estado de la orden de acuerdo con lo que de verdad pasó con sus ítems.
     *
     * **Es el punto único del flujo.** Lo llaman todas las acciones que mueven la orden —alistar
     * un ítem, cerrar el último paso de una unidad, firmar calidad, que salga una remisión— para
     * que el estado no dependa de por qué pantalla entró cada cosa. Antes cada camino hacía su
     * parte: cerrar un paso pasaba la orden a calidad, pero **alistar un producto no la movía**,
     * y una orden de productos se quedaba en «En producción» con todo listo en la bodega.
     *
     *  - borrador o confirmada → **en producción** en cuanto algo queda listo;
     *  - todo listo → **calidad** (y se sella sola si no hay nada que revisar, como una orden de
     *    solo productos, que no tiene unidades);
     *  - algo vuelve a quedar pendiente estando en calidad → regresa a **en producción**;
     *  - todo lo que viaja ya salió en una remisión → **despachada**.
     *
     * Un borrador sin nada listo no se toca: todavía se está armando. Pero si alguien ya alistó
     * algo o remisionó, la orden arranca sola —el trabajo real manda— y se le precargan las
     * bodegas que se habrían pedido al confirmar. Y nunca se devuelve una orden despachada: eso solo lo hace anular la remisión que la
     * despachó (el cambio manual a «despachada» es un respaldo legítimo y no se deshace solo).
     */
    public function sincronizarEstado(): void
    {
        $this->refresh();

        if ($this->estado === 'despachada') {
            return;
        }

        $items = $this->items()
            ->withCount([
                'trabajos',
                'trabajos as trabajos_terminados_count' => fn ($q) => $q->where('porcentaje_avance', 100),
            ])
            ->get(['id', 'op_id', 'tipo', 'cantidad', 'estado_item']);

        if ($items->isEmpty()) {
            return;
        }

        // Un ítem con unidades está listo cuando terminan todas, digan lo que digan los datos
        // viejos: se corrige aquí la marca del ítem si quedó desfasada. Uno sin unidades
        // (producto, servicio) lo dice su marca, que pone Alistamiento.
        foreach ($items as $item) {
            if ($item->trabajos_count > 0) {
                $listo = $item->trabajos_count === $item->trabajos_terminados_count ? 'terminado' : 'pendiente';

                if ($item->estado_item !== $listo) {
                    $item->update(['estado_item' => $listo]);
                }
            }
        }

        $terminados = $items->where('estado_item', 'terminado')->count();
        $todos      = $terminados === $items->count();

        // Algo quedó listo: la orden está en producción, venga de donde venga. Una en borrador
        // también —alguien ya la está trabajando, y el trabajo real manda sobre el clic de
        // «Confirmar» que nadie hizo—. Un borrador sin nada listo se sigue armando en paz.
        if (in_array($this->estado, ['borrador', 'confirmada'], true)) {
            if ($terminados === 0) {
                return;
            }

            $this->iniciarProduccionSiAplica();
        }

        if ($todos && in_array($this->estado, ['en_produccion', 'reproceso'], true)) {
            $this->pasarACalidad();
        } elseif (! $todos && $this->estado === 'calidad') {
            // Se desalistó un producto, o una unidad volvió a abrirse: ya no está todo listo, y
            // el sello de calidad no puede seguir diciendo que sí.
            $this->update(['estado' => 'en_produccion', 'calidad_aprobada_at' => null]);
        }

        if ($this->estado === 'calidad') {
            $this->sellarCalidadSiCorresponde();
        }

        $this->despacharSiSalioTodo($items, $todos);
    }

    /** Todo quedó listo: la orden espera revisión, o se aprueba sola si no hay módulo de calidad. */
    private function pasarACalidad(): void
    {
        // Sin el módulo de Calidad, la orden terminada queda aprobada: el sello es el candado
        // del despacho, y no hay pantalla donde ponerlo.
        if (! \App\Support\Modulos::activo('calidad')) {
            $this->update(['estado' => 'calidad', 'calidad_aprobada_at' => $this->calidad_aprobada_at ?? now()]);

            return;
        }

        $this->update(['estado' => 'calidad']);

        app(\App\Services\NotificacionService::class)->paraRol(
            ['administrador', 'jefe_produccion'],
            'op_a_calidad',
            "OP {$this->numero} lista para calidad",
            'Todo quedó listo y espera control de calidad.',
            "/produccion/ops/{$this->id}",
        );
    }

    /**
     * Sella `calidad_aprobada_at` cuando ya no queda nada por revisar en la orden.
     *
     * «Nada por revisar» es: todos los ítems listos, **todas** las unidades firmadas, y ningún
     * punto pendiente ni falla crítica. Antes bastaba con que no hubiera puntos pendientes, y
     * en una orden de unidades sin lista de revisión eso es cierto desde el principio: la orden
     * quedaba sellada con la primera puerta revisada y las otras nueve sin mirar.
     *
     * Una orden de solo productos no tiene unidades: se sella al quedar todo listo, porque no
     * hay nada que calidad pueda firmar.
     */
    public function sellarCalidadSiCorresponde(): void
    {
        if ($this->calidad_aprobada_at || $this->estado === 'despachada') {
            return;
        }

        if ($this->items()->where('estado_item', '!=', 'terminado')->exists()) {
            return;
        }

        $unidades = OpItemTrabajo::whereHas('opItem', fn ($q) => $q->where('op_id', $this->id));

        if ((clone $unidades)->whereNull('calidad_revisada_at')->exists()) {
            return;
        }

        $bloquean = OpItemTrabajoCheck::whereHas('trabajo.opItem', fn ($q) => $q->where('op_id', $this->id))
            ->where(fn ($q) => $q->where('resultado', 'pendiente')
                ->orWhere(fn ($q2) => $q2->where('resultado', 'falla')->where('es_critico', true)))
            ->exists();

        if ($bloquean) {
            return;
        }

        $this->update(['calidad_aprobada_at' => now(), 'motivo_rechazo' => null]);

        app(\App\Services\NotificacionService::class)->paraRol(
            ['administrador', 'jefe_produccion'],
            'op_lista_despacho',
            "OP {$this->numero} lista para despachar",
            'Calidad aprobada. Ya se puede generar la remisión.',
            "/produccion/ops/{$this->id}",
        );
    }

    /**
     * Cuántas unidades de un ítem ya salieron de verdad: las de remisiones que están en camino o
     * entregadas. Una remisión en borrador o confirmada todavía está en la bodega.
     */
    public static function unidadesQueSalieron(int $opItemId): float
    {
        return (float) RemisionItem::where('op_item_id', $opItemId)
            ->whereHas('remision', fn ($q) => $q->whereIn('estado', ['en_camino', 'entregada']))
            ->sum('cantidad');
    }

    /**
     * La orden se despacha cuando **todo lo que viaja ya salió**, no cuando se crea la remisión.
     *
     * Antes bastaba con crear la remisión, aunque quedara en borrador y el camión no hubiera
     * salido. Y se contaban también los servicios, que nunca se remisionan: una orden con una
     * instalación incluida no llegaba nunca a «despachada».
     *
     * @param  \Illuminate\Support\Collection<int, OpItem>  $items
     */
    private function despacharSiSalioTodo($items, bool $todos): void
    {
        if ($this->estado === 'despachada' || ! in_array($this->estado, ['en_produccion', 'calidad', 'reproceso'], true)) {
            return;
        }

        $viajan = $items->reject(fn ($i) => in_array($i->tipo, self::TIPOS_SIN_DESPACHO, true));

        // Una orden de solo servicios no lleva remisión: termina cuando todo está hecho.
        $salio = $viajan->isEmpty()
            ? $todos
            : $viajan->every(fn ($i) => static::unidadesQueSalieron($i->id) >= (float) $i->cantidad - 0.0001);

        if (! $salio) {
            return;
        }

        $this->update(['estado' => 'despachada']);
        $this->consumirMaterialesInventario();
    }

    /**
     * Anularon la remisión que la despachó: la orden vuelve a donde estaba antes de salir.
     */
    public function revertirDespacho(): void
    {
        $this->refresh();

        if ($this->estado !== 'despachada') {
            return;
        }

        $todos = ! $this->items()->where('estado_item', '!=', 'terminado')->exists();

        $this->update(['estado' => $todos ? 'calidad' : 'en_produccion']);
    }

    /**
     * Si ya se registró avance real en algún trabajo (un operario completó
     * un paso), la OP ya no debería seguir mostrando "Borrador" o
     * "Confirmada" — el trabajo en planta es la prueba de que la
     * producción arrancó, con o sin el clic manual de "Cambiar estado".
     */
    public function iniciarProduccionSiAplica(): void
    {
        if (! in_array($this->estado, ['borrador', 'confirmada'], true)) {
            return;
        }

        $cambios = ['estado' => 'en_produccion'];

        // Una orden que arranca desde borrador nunca pasó por «Confirmar», que es donde se piden
        // las dos bodegas. Se precargan con la principal para que el inventario sepa de dónde
        // sacar y a dónde meter; se pueden cambiar en la orden, y el paso final las pregunta
        // otra vez con lo que haya aquí ya elegido.
        if (\App\Support\Modulos::activo('inventario') && (! $this->bodega_entrega_id || ! $this->bodega_material_id)) {
            $bodega = Bodega::principal($this->sede_id)
                ?? Bodega::principal()
                ?? Bodega::where('activa', true)->orderBy('id')->first();

            if ($bodega) {
                $cambios['bodega_entrega_id']  = $this->bodega_entrega_id  ?: $bodega->id;
                $cambios['bodega_material_id'] = $this->bodega_material_id ?: $bodega->id;
            }
        }

        $this->update($cambios);
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
