<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OpItem extends Model
{
    protected $table = 'op_items';

    protected $fillable = [
        'op_id',
        'tipo',
        'producto_id',
        'ensamble_id',
        'orden',
        'descripcion',
        'descripcion_larga',
        'cantidad',
        'precio_unitario',
        'descuento_pct',
        'subtotal',
        'impuesto_pct',
        'impuesto_valor',
        'total_linea',
        'variables_instancia',
        'imagenes_instancia',
        'componentes_snapshot',
        'numero_serie',
        'operario_id',
        'estado_item',
        'notas_item',
        'remisionado',
        'remision_id',
        'cantidad_remisionada',
    ];

    protected $casts = [
        'cantidad'             => 'decimal:2',
        'precio_unitario'      => 'decimal:2',
        'descuento_pct'        => 'decimal:2',
        'subtotal'             => 'decimal:2',
        'impuesto_pct'         => 'decimal:2',
        'impuesto_valor'       => 'decimal:2',
        'total_linea'          => 'decimal:2',
        'variables_instancia'  => 'array',
        'imagenes_instancia'   => 'array',
        'componentes_snapshot' => 'array',
        'remisionado'          => 'boolean',
        'cantidad_remisionada' => 'decimal:3',
    ];

    /**
     * Un ítem que nadie fabrica: un producto de bodega o un concepto libre.
     *
     * Los trabajos se generan solo para los ensambles —uno por unidad física, con sus pasos—,
     * así que un producto suelto de la OP no tiene ninguno. Eso no significa que no se pueda
     * despachar: significa que su estado lo lleva el ítem, no sus trabajos.
     */
    public function sinTrabajos(): bool
    {
        return $this->trabajos()->count() === 0;
    }

    /**
     * Cuántas unidades se pueden meter hoy en una remisión.
     *
     * Un ítem con unidades en planta las cuenta por sus trabajos: terminadas, firmadas por
     * calidad y todavía sin remisionar. Un producto sin planta se puede remisionar **aunque nadie
     * lo haya alistado**: meterlo en la remisión es la prueba de que está listo, y la remisión lo
     * alista sola. Un servicio no viaja nunca.
     *
     * Hasta el 18 ago 2026 esto solo miraba los trabajos, y por eso **un producto nunca
     * aparecía en el remisionador**: la OP lo listaba y la remisión no lo dejaba escoger.
     */
    public function cantidadDisponible(): int
    {
        if (in_array($this->tipo, Op::TIPOS_SIN_DESPACHO, true)) {
            return 0;
        }

        if ($this->sinTrabajos()) {
            return max(0, (int) $this->cantidad - (int) $this->cantidad_remisionada);
        }

        return $this->trabajos()->disponiblesParaRemision()->count();
    }

    /**
     * Cuántas unidades están **listas**: alistadas a mano, o terminadas en planta y firmadas.
     *
     * Es lo que muestran Alistamiento y la pestaña «Por remisionar»: lo que ya está en el
     * muelle. `cantidadDisponible()` es más ancho —deja remisionar un producto sin alistar—,
     * pero listar ahí todo lo pendiente convertiría la pestaña en la lista de todas las órdenes.
     */
    public function cantidadLista(): int
    {
        if ($this->sinTrabajos() && $this->estado_item !== 'terminado') {
            return 0;
        }

        return $this->cantidadDisponible();
    }

    /**
     * Marca o desmarca el ítem como alistado, y mueve la orden.
     *
     * Es el único sitio que lo hace: lo llaman Alistamiento y el botón de la orden. Antes cada
     * uno escribía `estado_item` por su cuenta y **ninguno avisaba a la orden**: se alistaban
     * todos los productos y la orden seguía «En producción».
     *
     * Un ensamble con unidades no se alista a mano: queda listo solo cuando sus unidades
     * terminan en planta. Alistarlo antes metería en la remisión algo que no existe todavía, y
     * desalistarlo con todo terminado lo dejaría pendiente de algo que ya está hecho.
     *
     * @return string|null  el motivo si no se pudo; null si quedó hecho
     */
    public function alistar(bool $alistar = true): ?string
    {
        if (! $this->sinTrabajos()) {
            return $alistar
                ? 'Este ensamble se alista solo cuando terminan sus unidades en planta (van '
                    .$this->trabajos()->completados()->count().' de '.$this->trabajos()->count().').'
                : 'Este ensamble está listo porque sus unidades terminaron en planta: para devolverlo, se reabre su trabajo.';
        }

        if (! $alistar && $this->unidadesRemisionadas() > 0) {
            return 'Este ítem ya tiene unidades remisionadas: no se puede devolver a pendiente.';
        }

        $this->update(['estado_item' => $alistar ? 'terminado' : 'pendiente']);

        $this->op?->sincronizarEstado();

        return null;
    }

    public function estaRemisionado(): bool
    {
        if ($this->sinTrabajos()) {
            return (int) $this->cantidad_remisionada >= (int) $this->cantidad && (int) $this->cantidad > 0;
        }

        $total = $this->trabajos()->count();
        if ($total === 0) return false;
        return $this->trabajos()->disponiblesParaRemision()->count() === 0
            && $this->trabajos()->where('porcentaje_avance', 100)->count() > 0;
    }

    public function unidadesCompletadas(): int
    {
        if ($this->sinTrabajos()) {
            return $this->estado_item === 'terminado' ? (int) $this->cantidad : 0;
        }

        return $this->trabajos()->completados()->count();
    }

    public function unidadesRemisionadas(): int
    {
        if ($this->sinTrabajos()) {
            return (int) $this->cantidad_remisionada;
        }

        return $this->trabajos()->where('remisionado', true)->count();
    }

    public function op(): BelongsTo
    {
        return $this->belongsTo(Op::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function ensamble(): BelongsTo
    {
        return $this->belongsTo(Ensamble::class);
    }

    public function operario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operario_id');
    }

    public function componentes(): HasMany
    {
        return $this->hasMany(OpItemComponente::class);
    }

    public function trabajo(): HasOne
    {
        return $this->hasOne(OpItemTrabajo::class);
    }

    public function trabajos(): HasMany
    {
        return $this->hasMany(OpItemTrabajo::class);
    }

    public function remision(): BelongsTo
    {
        return $this->belongsTo(Remision::class);
    }
}
