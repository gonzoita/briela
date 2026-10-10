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
        // Cuando este producto es el producto TERMINADO de un ensamble: lo que se guarda en
        // bodega de algo que la empresa fabrica. Nulo en un producto comprado.
        'ensamble_id',
        'es_padre',
        'producto_padre_id',
        'atributo_variante',
        'valor_variante',
        'nombre',
        'referencia',
        'unidad_medida',
        'descripcion_corta',
        'descripcion_larga',
        // El texto técnico corto: cotizaciones y órdenes de producción.
        'descripcion_cotizacion',
        'inventariable',
        'stock_minimo',
        'stock_maximo',
        'precio_costo',
        // Si se compra en otra moneda: cuánto cobra el proveedor en ella. El costo en
        // pesos de arriba se recalcula con la tasa de cada día.
        'moneda_costo',
        'costo_moneda',
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
        'costo_moneda'               => 'decimal:4',
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

    /**
     * El proveedor preferido, en la columna de siempre.
     *
     * Se conserva porque muchas pantallas y las órdenes de compra la leen. Ya no es el único:
     * la lista completa con precios está en `proveedores()`, y esta columna sigue apuntando
     * al preferido.
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * Todos los proveedores que venden este producto, con su precio.
     *
     * Es lo que permite comparar antes de comprar, que es lo que se hacía por fuera del
     * sistema. Ordenados por precio: el primero es el más barato de la lista.
     */
    public function proveedores(): HasMany
    {
        return $this->hasMany(ProductoProveedor::class, 'producto_id')->orderBy('precio');
    }

    /**
     * El proveedor más conveniente, o null si no hay ninguno cargado.
     *
     * **El más barato no gana solo.** Se descartan los que exigen comprar más de lo que se
     * necesita —un precio bueno comprando cien no es un precio bueno comprando dos— y, entre
     * los que quedan, gana el precio. Si `$necesito` no se pasa, no se descarta a nadie.
     *
     * Deliberadamente NO usa la fecha del precio para decidir: un precio viejo puede seguir
     * siendo el bueno, y adivinarlo sería peor que mostrarlo con su fecha y dejar que la
     * persona juzgue. La pantalla avisa cuándo se actualizó.
     */
    public function mejorProveedor(?float $necesito = null): ?ProductoProveedor
    {
        return $this->proveedores
            ->filter(fn (ProductoProveedor $p) => (float) $p->precio > 0)
            ->filter(fn (ProductoProveedor $p) => $necesito === null
                || $p->minimo_compra === null
                || (float) $p->minimo_compra <= $necesito)
            ->sortBy(fn (ProductoProveedor $p) => (float) $p->precio)
            ->first();
    }

    /** Cuánto se ahorra comprándole al más barato en vez de al más caro. */
    public function ahorroEntreProveedores(): float
    {
        $precios = $this->proveedores->pluck('precio')->map(fn ($v) => (float) $v)->filter(fn ($v) => $v > 0);

        return $precios->count() < 2 ? 0.0 : round($precios->max() - $precios->min(), 2);
    }

    public function imagenes(): HasMany
    {
        return $this->hasMany(ImagenProducto::class)->orderBy('orden');
    }

    /**
     * Las imágenes que se ven de este producto.
     *
     * Una variante es un producto más y puede tener las suyas; si no tiene ninguna, donde se
     * muestre sola —al cotizar, en el catálogo, en su ficha— se usa la del producto principal.
     * No se copian archivos ni filas: se mira al padre al momento de mostrar, así que si el
     * padre cambia su foto, las variantes que la heredan cambian con él.
     *
     * Quien llame debe traer `imagenes` y `padre.imagenes`, o cada variante cuesta consultas.
     */
    public function imagenesVisibles(): \Illuminate\Support\Collection
    {
        if ($this->imagenes->isNotEmpty() || ! $this->producto_padre_id) {
            return $this->imagenes;
        }

        return $this->padre?->imagenes ?? $this->imagenes;
    }

    public function imagenVisible(): ?ImagenProducto
    {
        $imagenes = $this->imagenesVisibles();

        return $imagenes->firstWhere('es_principal', true) ?? $imagenes->first();
    }

    /** Si lo que se ve es del producto principal y no propio: para decírselo a la persona. */
    public function heredaImagenes(): bool
    {
        return $this->producto_padre_id
            && $this->imagenes->isEmpty()
            && $this->padre?->imagenes->isNotEmpty();
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

    /** El ensamble del que este producto es el producto terminado, si lo es. */
    public function ensamble(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Ensamble::class, 'ensamble_id');
    }

    /** Lo que la empresa fabrica y guarda, frente a lo que compra. */
    public function esProductoTerminado(): bool
    {
        return $this->ensamble_id !== null;
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

    /** Lo que cabe en `productos.referencia`. */
    public const REFERENCIA_MAX = 60;

    /**
     * La referencia de una variante: la del padre, un guion y su valor.
     *
     * **Nunca puede pasarse de 60 caracteres**, que es lo que acepta la columna. El valor
     * de la variante admite 60 por sí solo, así que «PROD-0001-» más un valor largo daba
     * 70 y MySQL cortaba la inserción con un 1406: la pantalla mostraba un 500 sin decir
     * qué pasó, y el padre ya había quedado creado. Se recorta el valor, no el prefijo:
     * el prefijo es lo que emparenta la variante con su padre a simple vista.
     *
     * El desempate también cabe: se reserva el espacio del sufijo antes de recortar, para
     * que «-2» no vuelva a pasarse del tope.
     */
    public static function generarReferenciaVariante(Producto $padre, string $valorVariante): string
    {
        $limpio = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $valorVariante));
        $raiz   = $padre->referencia . '-' . $limpio;
        $sufijo = 1;

        // El sufijo se reserva ANTES de recortar, no se recorta después: recortar el
        // sufijo devolvería la misma referencia una y otra vez y el bucle no terminaría.
        do {
            $cola       = $sufijo === 1 ? '' : '-' . $sufijo;
            $referencia = static::recortarReferencia($raiz, strlen($cola)) . $cola;
            $sufijo++;
        } while (static::withTrashed()->where('referencia', $referencia)->exists());

        return $referencia;
    }

    /**
     * Deja una referencia dentro del límite de la columna, guardando sitio para el sufijo
     * de desempate que pueda venir después.
     */
    private static function recortarReferencia(string $referencia, int $reserva = 0): string
    {
        $tope = static::REFERENCIA_MAX - $reserva;

        return strlen($referencia) <= $tope ? $referencia : rtrim(substr($referencia, 0, $tope), '-');
    }

    // ─── Stock ───────────────────────────────────────────────────────────────

    public function stockTotal(): float
    {
        if ($this->es_padre) {
            return (float) $this->variantes()->get()->sum(fn ($v) => $v->stockTotal());
        }

        return (float) $this->stocks()->sum('cantidad');
    }

    /**
     * El stock contando solo ciertas bodegas.
     *
     * Lo que hace falta para no mezclar sedes: `stockTotal()` suma todas las bodegas del
     * sistema, y en una empresa con dos sucursales eso le dice a quien cotiza que hay once
     * unidades cuando en su bodega hay tres. El inventario ya filtraba así; el buscador de
     * productos no, y es el que se usa al cotizar.
     *
     * Una lista **vacía** significa «no se pudo determinar la sede» —no hay usuario, o no
     * tiene bodegas asignadas—, y entonces cuenta todas. Devolver cero ahí sería peor que
     * el total: pintaría todo el catálogo en rojo como si no hubiera nada, y quien cotiza
     * dejaría de vender lo que sí tiene. Que el número incluya otra sede es la deuda
     * conocida del filtrado opt-in; decir «no hay» cuando hay es un error nuevo.
     *
     * @param  array<int, int>  $bodegaIds
     */
    public function stockEnBodegas(array $bodegaIds): float
    {
        if ($bodegaIds === []) {
            return $this->stockTotal();
        }

        if ($this->es_padre) {
            return (float) $this->variantes()->get()->sum(fn ($v) => $v->stockEnBodegas($bodegaIds));
        }

        return (float) $this->stocks()->whereIn('bodega_id', $bodegaIds)->sum('cantidad');
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
        ?string $notas = null,
        // El papel que respalda el movimiento: 'factura', 'remision' u 'otro', con su número
        // y su fecha. Al final y opcionales: quien ya llama con nombres no cambia.
        ?string $documentoTipo = null,
        ?string $documentoNumero = null,
        ?string $documentoFecha = null
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
            'documento_tipo'   => $documentoNumero ? $documentoTipo : null,
            'documento_numero' => $documentoNumero ?: null,
            'documento_fecha'  => $documentoNumero ? $documentoFecha : null,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Deja libre una referencia que solo la tiene algo ya eliminado.
     *
     * Eliminar un producto es un borrado suave y la columna `referencia` es única, así que
     * un producto eliminado seguía ocupando su referencia para siempre: la persona lo
     * borraba, volvía a crear el mismo producto con la misma referencia y el sistema le
     * decía que ya estaba en uso —de algo que ella ya no veía—. La única salida era una
     * consulta a mano en la base, en un hosting donde ni siquiera hay terminal.
     *
     * No se borra nada: al eliminado se le cambia la referencia por «REF~elim123», con su
     * id al final para que nunca choque. Se hace **al reutilizarla**, no al eliminar, por
     * dos razones: así las cotizaciones y órdenes viejas siguen mostrando la referencia
     * original mientras nadie la reclame, y vale también para lo que ya estaba eliminado
     * antes de esta regla, sin ninguna migración que correr en cada instalación.
     *
     * Lo que está vivo no se toca: eso lo rechaza la validación.
     */
    public static function liberarReferencia(string $referencia, ?int $exceptoId = null): void
    {
        static::onlyTrashed()
            ->where('referencia', $referencia)
            ->when($exceptoId, fn ($q) => $q->where('id', '!=', $exceptoId))
            ->get()
            ->each(function (Producto $eliminado) {
                $cola = '~elim'.$eliminado->id;

                $eliminado->update([
                    'referencia' => mb_substr($eliminado->referencia, 0, static::REFERENCIA_MAX - mb_strlen($cola)).$cola,
                ]);
            });
    }

    /**
     * La siguiente referencia libre de su tipo: PROD-0001, SERV-0012.
     *
     * Sale del **número más alto ya usado**, no de cuántas filas hay. Contar fallaba de
     * dos maneras en cuanto la instalación llevaba tiempo: las variantes también empiezan
     * por «PROD-» —«PROD-0007-3M»— así que inflaban la cuenta y el contador saltaba de
     * cuatro en cuatro, y una referencia escrita a mano hacía que la cuenta se topara con
     * un número ya ocupado: el formulario respondía «la referencia ya está en uso» en un
     * campo que la persona había dejado en blanco.
     *
     * El `while` final es el cinturón: con referencias escritas a mano siempre puede
     * haber un hueco ocupado más arriba.
     */
    public static function generarReferencia(string $tipo): string
    {
        $prefijo = match ($tipo) {
            'producto' => 'PROD',
            'servicio' => 'SERV',
            default    => 'PROD',
        };

        // Solo «PREFIJO-1234»: una variante lleva otro guion detrás y no entra en la cuenta.
        $ultimo = (int) static::withTrashed()
            ->where('referencia', 'REGEXP', '^' . $prefijo . '-[0-9]+$')
            ->selectRaw('MAX(CAST(SUBSTRING(referencia, ?) AS UNSIGNED)) as maximo', [strlen($prefijo) + 2])
            ->value('maximo');

        do {
            $ultimo++;
            $referencia = $prefijo . '-' . str_pad((string) $ultimo, 4, '0', STR_PAD_LEFT);
        } while (static::withTrashed()->where('referencia', $referencia)->exists());

        return $referencia;
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
