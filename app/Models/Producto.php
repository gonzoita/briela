<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'categoria_id',
        'proveedor_id',
        'tipo',
        'es_vendible',
        'es_insumo',
        'es_padre',
        'producto_padre_id',
        'atributo_variante',
        'valor_variante',
        'nombre',
        'referencia',
        'unidad_medida',
        'descripcion_corta',
        'descripcion_larga',
        'inventariable',
        'stock_minimo',
        'stock_maximo',
        'precio_costo',
        'precio_promedio_compra',
        'precio_ultimo_compra',
        'margen_mayorista',
        'margen_distribuidor',
        'margen_cliente_final',
        'precio_mayorista',
        'precio_distribuidor',
        'precio_cliente_final',
        'comision_pct_minima',
        'comision_pct_maxima',
        'comision_min_distribuidor',
        'comision_max_distribuidor',
        'comision_min_cliente_final',
        'comision_max_cliente_final',
        'utilidad_minima_empresa_pct',
        'descuento_max_cliente_final',
        'descuento_max_distribuidor',
        'descuento_max_mayorista',
        'activo',
        // Si sale al sitio web del cliente. Lo lee el plugin Briela Connect.
        'publicado_web',
        'publicado_web_at',
    ];

    protected $casts = [
        'inventariable'              => 'boolean',
        'es_vendible'                => 'boolean',
        'es_insumo'                  => 'boolean',
        'es_padre'                   => 'boolean',
        'activo'                     => 'boolean',
        'precio_costo'               => 'decimal:2',
        'precio_promedio_compra'     => 'decimal:2',
        'precio_ultimo_compra'       => 'decimal:2',
        'margen_mayorista'           => 'decimal:2',
        'margen_distribuidor'        => 'decimal:2',
        'margen_cliente_final'       => 'decimal:2',
        'precio_mayorista'           => 'decimal:2',
        'precio_distribuidor'        => 'decimal:2',
        'precio_cliente_final'       => 'decimal:2',
        'comision_pct_minima'         => 'decimal:2',
        'comision_pct_maxima'         => 'decimal:2',
        'comision_min_distribuidor'   => 'decimal:2',
        'comision_max_distribuidor'   => 'decimal:2',
        'comision_min_cliente_final'  => 'decimal:2',
        'comision_max_cliente_final'  => 'decimal:2',
        'utilidad_minima_empresa_pct' => 'decimal:2',
        'descuento_max_cliente_final'=> 'decimal:2',
        'descuento_max_distribuidor' => 'decimal:2',
        'descuento_max_mayorista'    => 'decimal:2',
        'publicado_web'              => 'boolean',
        'publicado_web_at'           => 'datetime',
    ];

    // ─── Relaciones ───────────────────────────────────────────────────────────

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'categoria_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function imagenes(): HasMany
    {
        return $this->hasMany(ImagenProducto::class)->orderBy('orden');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductoStock::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(ProductoMovimiento::class);
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_padre_id');
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(Producto::class, 'producto_padre_id');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeVendibles($query)
    {
        return $query->where('es_vendible', true);
    }

    public function scopeInsumos($query)
    {
        return $query->where('es_insumo', true);
    }

    public function scopeSoloPadres($query)
    {
        return $query->where('es_padre', true);
    }

    public function scopeSoloVariantes($query)
    {
        return $query->whereNotNull('producto_padre_id');
    }

    /**
     * Productos que pueden elegirse en un selector (Cotizaciones, OP, Ensambles):
     * variantes, o productos simples que no son padre ni variante.
     */
    public function scopeSeleccionables($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('producto_padre_id')
              ->orWhere(function ($q2) {
                  $q2->where('es_padre', false)->whereNull('producto_padre_id');
              });
        });
    }

    // ─── Variantes ───────────────────────────────────────────────────────────

    public function getNombreCompletoAttribute(): string
    {
        if ($this->valor_variante) {
            return ($this->padre?->nombre ?? $this->nombre) . ' — ' . $this->valor_variante;
        }

        return $this->nombre;
    }

    public static function generarReferenciaVariante(Producto $padre, string $valorVariante): string
    {
        $base = $padre->referencia . '-' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $valorVariante));

        $referencia = $base;
        $sufijo     = 1;
        while (static::withTrashed()->where('referencia', $referencia)->exists()) {
            $sufijo++;
            $referencia = $base . '-' . $sufijo;
        }

        return $referencia;
    }

    // ─── Stock ───────────────────────────────────────────────────────────────

    public function stockTotal(): float
    {
        if ($this->es_padre) {
            return (float) $this->variantes()->get()->sum(fn ($v) => $v->stockTotal());
        }

        return (float) $this->stocks()->sum('cantidad');
    }

    public function stockEnBodega(int $bodegaId): float
    {
        return (float) ($this->stocks()->where('bodega_id', $bodegaId)->value('cantidad') ?? 0);
    }

    public function registrarMovimiento(
        string $tipo,
        float $cantidad,
        int $bodegaId,
        int $usuarioId,
        ?int $bodegaDestinoId = null,
        ?float $precioUnitario = null,
        string $origenTipo = 'ajuste_manual',
        ?int $origenId = null,
        ?string $notas = null
    ): void {
        if ($this->es_padre) {
            throw new \RuntimeException('Un producto padre no puede tener stock. Selecciona una de sus variantes.');
        }

        $stock = ProductoStock::firstOrCreate(
            ['producto_id' => $this->id, 'bodega_id' => $bodegaId],
            ['cantidad' => 0]
        );

        $stockAnterior = (float) $stock->cantidad;

        if ($tipo === 'transferencia') {
            $stockNuevo = max(0, $stockAnterior - $cantidad);
            $stock->update(['cantidad' => $stockNuevo]);

            $destino = ProductoStock::firstOrCreate(
                ['producto_id' => $this->id, 'bodega_id' => $bodegaDestinoId],
                ['cantidad' => 0]
            );
            $destino->increment('cantidad', $cantidad);
        } elseif (in_array($tipo, ['entrada', 'devolucion'])) {
            $stockNuevo = $stockAnterior + $cantidad;
            $stock->update(['cantidad' => $stockNuevo]);
        } elseif ($tipo === 'ajuste') {
            // cantidad puede ser positiva (incremento) o negativa (decremento)
            $stockNuevo = max(0, $stockAnterior + $cantidad);
            $stock->update(['cantidad' => $stockNuevo]);
        } else {
            // salida | consumo_ensamble | venta
            $stockNuevo = max(0, $stockAnterior - $cantidad);
            $stock->update(['cantidad' => $stockNuevo]);
        }

        if ($tipo === 'entrada' && $precioUnitario !== null) {
            $stockTotalAnterior = (float) $this->stocks()->sum('cantidad') - $cantidad;
            $totalConEntrada    = $stockTotalAnterior + $cantidad;
            $precioPromedio     = $totalConEntrada > 0
                ? (($stockTotalAnterior * (float) $this->precio_promedio_compra) + ($cantidad * $precioUnitario)) / $totalConEntrada
                : $precioUnitario;

            $this->update([
                'precio_promedio_compra' => $precioPromedio,
                'precio_ultimo_compra'   => $precioUnitario,
            ]);
        }

        ProductoMovimiento::create([
            'producto_id'      => $this->id,
            'bodega_id'        => $bodegaId,
            'tipo'             => $tipo,
            'cantidad'         => $cantidad,
            'stock_anterior'   => $stockAnterior,
            'stock_nuevo'      => $stockNuevo,
            'bodega_destino_id'=> $bodegaDestinoId,
            'precio_unitario'  => $precioUnitario,
            'origen_tipo'      => $origenTipo,
            'origen_id'        => $origenId,
            'usuario_id'       => $usuarioId,
            'notas'            => $notas,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public static function generarReferencia(string $tipo): string
    {
        $prefijo = match ($tipo) {
            'producto' => 'PROD',
            'servicio' => 'SERV',
            default    => 'PROD',
        };

        $ultimo = static::withTrashed()
            ->where('tipo', $tipo)
            ->where('referencia', 'like', "{$prefijo}-%")
            ->count();

        return $prefijo . '-' . str_pad($ultimo + 1, 4, '0', STR_PAD_LEFT);
    }

    public function tipoLabel(): string
    {
        return match ($this->tipo) {
            'producto' => 'Producto',
            'servicio' => 'Servicio',
            default    => $this->tipo,
        };
    }

    public function tipoColor(): string
    {
        return match ($this->tipo) {
            'producto' => 'blue',
            'servicio' => 'green',
            default    => 'gray',
        };
    }

    public function nombreParaAuditoria(): string
    {
        return $this->nombre . ($this->referencia ? " ({$this->referencia})" : '');
    }

    /**
     * Los precios por canal. Reemplazan a las columnas fijas por canal, que siguen
     * existiendo durante el período de compatibilidad de la regla 2.
     */
    public function preciosPorCanal(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(\App\Models\CanalPrecio::class, "precionable");
    }

    /** El precio de un canal concreto, o null si ese canal no tiene precio cargado. */
    public function precioDeCanal(?\App\Models\SegmentacionOpcion $canal): ?\App\Models\CanalPrecio
    {
        return $canal
            ? $this->preciosPorCanal->firstWhere("segmentacion_opcion_id", $canal->id)
            : null;
    }
}
