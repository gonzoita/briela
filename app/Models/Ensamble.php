<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ensamble extends Model
{
    use SoftDeletes;

    protected $table = 'ensambles';

    protected $fillable = [
        'plantilla_id',
        // «plantilla» (medidas y fórmulas) o «directo» (líneas con cantidades exactas).
        'tipo_armado',
        'nombre',
        // El código con el que se busca y se dicta. Antes las pantallas escribían
        // «ENS-{id}» a mano: un identificador de base disfrazado de referencia.
        'referencia',
        'unidad_medida',
        // Si de este ensamble se guardan unidades armadas en bodega. Al prenderlo nace su
        // producto terminado en el catálogo, y con él todo el inventario que ya existe.
        'maneja_stock',
        'categoria_id',
        'descripcion_corta',
        'descripcion_larga',
        // El texto técnico corto: cotizaciones y órdenes de producción.
        'descripcion_cotizacion',
        'imagen_principal',
        'imagen_principal_drive_id',
        'imagenes_secundarias',
        'variables',
        'componentes_resultado',
        'precio_costo',
        'precio_mayorista',
        'precio_distribuidor',
        'precio_cliente_final',
        'margen_aplicado',
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
        'creado_por',
        // Si sale al sitio web del cliente. Lo lee el plugin Briela Connect.
        'publicado_web',
        'publicado_web_at',
    ];

    protected $casts = [
        'variables'                  => 'array',
        'componentes_resultado'      => 'array',
        'imagenes_secundarias'       => 'array',
        'precio_costo'               => 'decimal:2',
        'precio_mayorista'           => 'decimal:2',
        'precio_distribuidor'        => 'decimal:2',
        'precio_cliente_final'       => 'decimal:2',
        'margen_aplicado'            => 'decimal:2',
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

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaEnsamble::class, 'plantilla_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'categoria_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function calcularPrecios(float $margen = null): void
    {
        $m     = $margen ?? (float) $this->margen_aplicado;
        $costo = (float) $this->precio_costo;

        $this->precio_mayorista     = round($costo * (1 + $m / 100), 0);
        $this->precio_distribuidor  = round($costo * (1 + ($m + 2.5) / 100), 0);
        $this->precio_cliente_final = round($costo * (1 + ($m + 5) / 100), 0);
    }

    /**
     * Los precios por canal. Reemplazan a las columnas fijas por canal, que siguen
     * existiendo durante el período de compatibilidad de la regla 2.
     */
    /**
     * La siguiente referencia libre, con el formato ENS-0001.
     *
     * Cuenta las que ya existen —incluidas las borradas— para no repetir un código que
     * todavía aparece en una cotización vieja, y avanza hasta encontrar uno libre: contar
     * solas no alcanza si alguien escribió una referencia a mano.
     */
    public static function generarReferencia(): string
    {
        $consecutivo = static::withTrashed()->where('referencia', 'like', 'ENS-%')->count();

        do {
            $consecutivo++;
            $referencia = 'ENS-'.str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT);
        } while (static::withTrashed()->where('referencia', $referencia)->exists());

        return $referencia;
    }

    /**
     * ¿Cuántas unidades de este ensamble se pueden armar con lo que hay en bodega?
     *
     * Es la respuesta honesta a «¿está disponible?» para algo que se fabrica: un ensamble no
     * vive en un estante —cada uno se arma cuando se vende—, así que lo que se puede saber no
     * es cuántos hay guardados sino **cuántos alcanzan a armarse hoy**. Sale del componente
     * que primero se agota: si la receta pide 4 bisagras y hay 10, alcanza para 2.
     *
     * Los conceptos libres —mano de obra, transporte— no limitan nada: no se agotan.
     *
     * @param  array<int, int>  $bodegaIds  Bodegas a contar; vacío cuenta todas.
     * @return array{unidades: int|null, cuello: ?string, faltantes: array<int, array<string, mixed>>}
     */
    public function unidadesArmables(array $bodegaIds = []): array
    {
        $lineas = collect((array) $this->componentes_resultado)
            ->filter(fn ($c) => ($c['producto_id'] ?? null) && (float) ($c['cantidad_real'] ?? $c['cantidad'] ?? 0) > 0);

        if ($lineas->isEmpty()) {
            // Sin materiales de inventario no hay nada que limite: puede ser un ensamble de
            // pura mano de obra, o uno con la receta sin calcular. No se inventa un número.
            return ['unidades' => null, 'cuello' => null, 'faltantes' => []];
        }

        $productos = Producto::whereIn('id', $lineas->pluck('producto_id')->unique())->get()->keyBy('id');

        $unidades  = null;
        $cuello    = null;
        $faltantes = [];

        foreach ($lineas as $linea) {
            $producto = $productos->get((int) $linea['producto_id']);

            if (! $producto) {
                continue;
            }

            $necesita = (float) ($linea['cantidad_real'] ?? $linea['cantidad']);
            $hay      = $producto->stockEnBodegas($bodegaIds);
            $alcanza  = (int) floor($hay / $necesita);

            if ($unidades === null || $alcanza < $unidades) {
                $unidades = $alcanza;
                $cuello   = $linea['nombre'] ?? $producto->nombre;
            }

            if ($hay < $necesita) {
                $faltantes[] = [
                    'nombre'   => $linea['nombre'] ?? $producto->nombre,
                    'necesita' => $necesita,
                    'hay'      => $hay,
                    'falta'    => round($necesita - $hay, 4),
                    'unidad'   => $linea['unidad'] ?? $producto->unidad_medida,
                ];
            }
        }

        return ['unidades' => $unidades, 'cuello' => $cuello, 'faltantes' => $faltantes];
    }

    /**
     * ¿Es un ensamble armado a mano, sin plantilla ni fórmulas?
     *
     * Se pregunta por el tipo y no por si tiene plantilla: deducirlo de `plantilla_id`
     * funcionaría hoy y se rompería el día que un ensamble directo se asocie a una plantilla
     * como referencia.
     */
    public function esDirecto(): bool
    {
        return ($this->tipo_armado ?? 'plantilla') === 'directo';
    }

    /** El producto terminado de este ensamble: lo que se guarda en bodega. */
    public function productoTerminado(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\Producto::class, 'ensamble_id');
    }

    /**
     * Crea o pone al día el producto terminado de este ensamble.
     *
     * Un ensamble que se guarda en bodega **es** un producto terminado, así que en vez de una
     * tabla de stock propia obtiene su fila en `productos` y hereda el módulo de inventario
     * completo: stock por bodega, movimientos, traslados, mínimos, el aviso de stock bajo y
     * la etiqueta de disponibles al cotizar.
     *
     * Nace **no vendible**: lo que se cotiza es el ensamble, con sus medidas y su receta. Si
     * el producto terminado también se pudiera cotizar, el mismo artículo aparecería dos
     * veces en el buscador y nadie sabría cuál de los dos elegir.
     *
     * No borra nada al apagar `maneja_stock`: el producto se desactiva. Sus movimientos son
     * historia de inventario, y el stock que tenga es algo que existe en una estantería.
     */
    public function sincronizarProductoTerminado(): ?\App\Models\Producto
    {
        // Consulta fresca, no la propiedad: la relación se cachea en el modelo, y si se leyó
        // antes de que el producto existiera —lo que pasa en el mismo guardado que lo crea—
        // queda en null. Con eso, apagar el interruptor no desactivaba nada y el producto
        // seguía apareciendo en el inventario.
        $producto = $this->productoTerminado()->first();

        if (! $this->maneja_stock) {
            $producto?->update(['activo' => false]);

            return $producto;
        }

        $datos = [
            'nombre'            => $this->nombre,
            'unidad_medida'     => $this->unidad_medida ?: 'unidad',
            'categoria_id'      => $this->categoria_id,
            'descripcion_corta' => $this->descripcion_corta,
            'precio_costo'      => $this->precio_costo,
            'activo'            => true,
        ];

        if ($producto) {
            $producto->update($datos);

            return $producto;
        }

        return \App\Models\Producto::create(array_merge($datos, [
            'ensamble_id'   => $this->id,
            'tipo'          => 'producto',
            // La referencia del ensamble, con un sufijo: son dos filas distintas en dos
            // tablas distintas y la de productos exige que no se repita.
            'referencia'    => $this->referenciaParaProductoTerminado(),
            'inventariable' => true,
            // Lo que se cotiza es el ensamble. Ver el comentario de arriba.
            'es_vendible'   => false,
            'es_insumo'     => false,
            'es_padre'      => false,
        ]));
    }

    private function referenciaParaProductoTerminado(): string
    {
        $base = ($this->referencia ?: 'ENS-'.$this->id).'-T';
        $ref  = $base;
        $n    = 1;

        while (\App\Models\Producto::withTrashed()->where('referencia', $ref)->exists()) {
            $n++;
            $ref = $base.$n;
        }

        return $ref;
    }

    /**
     * El flujo de producción propio de un ensamble directo, creándolo si no existe.
     *
     * En un ensamble con plantilla, los pasos cuelgan de la plantilla —una por producto que
     * se fabrica por medidas—. Un ensamble directo no tiene plantilla, así que el flujo cuelga
     * de él. Nace con un solo paso que pesa el 100%: es lo mínimo para que el operario pueda
     * escanear su QR, marcar terminado, y que la OP avance sola hasta calidad. El usuario
     * puede reemplazarlo por los pasos que quiera desde la misma pantalla de pasos.
     *
     * Sin esto, una OP con un ensamble directo nacía sin trabajos y se quedaba quieta.
     */
    public function obtenerOCrearTemplateTrabajo(): \App\Models\TemplateTrabajo
    {
        $template = \App\Models\TemplateTrabajo::firstOrCreate(
            ['ensamble_id' => $this->id],
            ['nombre' => $this->nombre, 'activo' => true]
        );

        if ($template->pasos()->count() === 0) {
            \App\Models\TemplateTrabajoPaso::create([
                'template_id'     => $template->id,
                'nombre'          => 'Fabricación',
                'descripcion'     => 'Armar el ensamble con los componentes de su lista de materiales.',
                'peso_porcentaje' => 100,
                'orden'           => 0,
                'es_paso_final'   => true,
            ]);
        }

        return $template;
    }

    /** El costo de un ensamble directo: la suma de sus líneas. */
    public function costoDeLineas(): float
    {
        return collect((array) $this->componentes_resultado)
            ->sum(fn ($c) => (float) ($c['subtotal'] ?? 0));
    }

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
