<?php

namespace App\Services;

use App\Models\CanalPrecio;
use App\Models\Ensamble;
use App\Models\Producto;
use App\Models\SegmentacionOpcion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Guarda y lee los precios por canal de un producto o un ensamble.
 *
 * Escribe en los dos formatos a la vez: las filas nuevas de `canal_precios` y las
 * columnas viejas por canal. Eso es el período de compatibilidad de la regla 2 — hay
 * cincuenta y seis lugares en el sistema que todavía leen las columnas, y cambiarlos
 * todos de golpe es la forma de romper algo sin darse cuenta.
 *
 * Las columnas solo existen para los tres canales originales. Un canal que la empresa
 * cree vive únicamente en las filas, que es donde ya lee el código nuevo.
 */
class PreciosPorCanalService
{
    /**
     * Qué columna vieja le corresponde a cada canal original, por su clave.
     *
     * Sirve para las instalaciones que conservaron los nombres de fábrica. Las que
     * renombraron o rehicieron sus canales tienen otras claves, y para esas el puente lo
     * resuelve `columnaDe()` por el PAPEL del canal.
     */
    private const COLUMNAS_POR_CLAVE = [
        'mayorista'       => 'mayorista',
        'distribuidor'    => 'distribuidor',
        'cliente_directo' => 'cliente_final',
    ];

    /**
     * Qué columna vieja le corresponde a un canal.
     *
     * **Este método es el arreglo de un error de fondo.** Todo el puente entre las columnas
     * viejas y los canales configurables estaba atado a tres claves internas escritas aquí:
     * `mayorista`, `distribuidor`, `cliente_directo`. Funcionaba en una instalación de
     * fábrica y fallaba en silencio en cuanto la empresa creaba sus propios canales —que es
     * justamente lo que el sistema le ofrece hacer—: sus claves son otras, ninguna coincidía,
     * y el resultado era que **los productos se cotizaban en cero** teniendo sus precios a la
     * vista en la ficha.
     *
     * Se resuelve por el papel, que es lo que de verdad significa cada columna:
     *
     * - `precio_mayorista` era el piso de utilidad → el **canal base**.
     * - `precio_cliente_final` era lo que ve un desconocido → el **precio público**.
     * - `precio_distribuidor` era el canal del medio → el primer canal que no es ninguno de
     *   los dos anteriores, en el orden que la empresa puso.
     *
     * Un cuarto canal no tiene columna: nunca existió una para él. Su precio vive solo en
     * `canal_precios`, y si está vacío la pantalla lo dice en vez de mostrar cero.
     */
    public function columnaDe(SegmentacionOpcion $canal): ?string
    {
        if (isset(self::COLUMNAS_POR_CLAVE[$canal->valor])) {
            return self::COLUMNAS_POR_CLAVE[$canal->valor];
        }

        if ($canal->es_canal_base) {
            return 'mayorista';
        }

        if ($canal->es_precio_publico) {
            return 'cliente_final';
        }

        $intermedio = $this->canales->canales()
            ->reject(fn ($c) => $c->es_canal_base || $c->es_precio_publico)
            ->first();

        return $intermedio && $intermedio->id === $canal->id ? 'distribuidor' : null;
    }

    public function __construct(private CanalesPrecioService $canales) {}

    /**
     * Lo que necesita el formulario: una fila por canal configurado, con lo que ya esté
     * guardado o ceros si es nuevo.
     *
     * Devuelve TODOS los canales, no solo los que tienen precio: si un canal se creó
     * después del producto, tiene que aparecer vacío para poder llenarlo.
     */
    public function paraFormulario(?Model $item): array
    {
        $guardados = $item
            ? $item->preciosPorCanal()->get()->keyBy('segmentacion_opcion_id')
            : collect();

        return $this->canales->canales()->map(function ($canal) use ($guardados) {
            $fila = $guardados->get($canal->id);

            return [
                'segmentacion_opcion_id' => $canal->id,
                'etiqueta'               => $canal->etiqueta,
                'color'                  => $canal->color,
                'es_canal_base'          => (bool) $canal->es_canal_base,
                'es_precio_publico'      => (bool) $canal->es_precio_publico,
                'margen_pct'             => (float) ($fila->margen_pct ?? $this->margenSugerido($canal)),
                'precio'                 => (float) ($fila->precio ?? 0),
                // El canal base no paga comisión: es el piso de utilidad. Se manda en
                // cero y la pantalla ni siquiera muestra los campos.
                'comision_min_pct'       => (float) ($fila->comision_min_pct ?? 0),
                'comision_max_pct'       => (float) ($fila->comision_max_pct ?? 0),
                'descuento_max_pct'      => (float) ($fila->descuento_max_pct ?? 0),
            ];
        })->values()->all();
    }

    /**
     * Con qué margen nace un canal en un producto nuevo.
     *
     * Lo pone la empresa en Segmentación. Estuvo escrito primero en la pantalla (25/30/35) y
     * después aquí: en los dos sitios era un número que la empresa no podía cambiar, y es un
     * número que depende del mercado y del rubro.
     *
     * Sigue siendo ajustable producto por producto al crearlo: esto es con qué arranca el
     * formulario, no un tope.
     */
    private function margenSugerido(SegmentacionOpcion $canal): float
    {
        return (float) $canal->margen_sugerido;
    }

    /**
     * El precio de un canal, mirando primero las filas nuevas y después la columna vieja.
     *
     * Existe por el período de compatibilidad: los ensambles guardados antes de que
     * hubiera canales configurables **no tienen filas** en `canal_precios`, solo sus tres
     * columnas de siempre. Sin este respaldo, un ensamble publicado en la web sale sin
     * precio y el catálogo público tampoco lo muestra — se veía como un dato faltante
     * cuando en realidad el precio estaba guardado en la columna de al lado.
     *
     * Cuando toda pantalla escriba filas, se borra el segundo `if` y esto queda en una
     * línea. No antes: al otro lado hay bases de clientes con ensambles viejos.
     */
    public function precioDe(Model $item, ?SegmentacionOpcion $canal): ?float
    {
        if (! $canal) {
            return null;
        }

        if ($fila = $item->precioDeCanal($canal)) {
            return (float) $fila->precio;
        }

        $columna = $this->columnaDe($canal);

        if ($columna && isset($item->{"precio_{$columna}"})) {
            return (float) $item->{"precio_{$columna}"};
        }

        return null;
    }

    /**
     * La fila efectiva de un canal: lo guardado, o lo que se pueda reconstruir.
     *
     * Existe porque la cotización leía **solo** las filas nuevas de `canal_precios`, y un
     * producto sin fila para el canal de ese cliente se cotizaba en **cero sin avisar**:
     * pasaba con los ítems guardados desde pantallas que todavía mandan las columnas
     * viejas, y con cualquier canal que la empresa haya creado después del producto.
     *
     * Un precio en cero que nadie pidió es peor que un error: se firma.
     *
     * @return array{precio: float, comision_min_pct: float, comision_max_pct: float, descuento_max_pct: float, desde_columnas_viejas: bool}
     */
    public function filaEfectiva(Model $item, SegmentacionOpcion $canal): array
    {
        $fila = $item->precioDeCanal($canal);

        if ($fila) {
            return [
                'precio'                => (float) $fila->precio,
                'comision_min_pct'      => (float) $fila->comision_min_pct,
                'comision_max_pct'      => (float) $fila->comision_max_pct,
                'descuento_max_pct'     => (float) $fila->descuento_max_pct,
                'desde_columnas_viejas' => false,
            ];
        }

        $columna = $this->columnaDe($canal);

        if (! $columna) {
            // Un canal que la empresa creó después y al que nadie le puso precio en este
            // ítem: no hay de dónde sacarlo. Va en cero, pero marcado, para que la
            // pantalla lo pueda decir en vez de mostrar «$0» como si fuera un precio.
            return [
                'precio' => 0.0, 'comision_min_pct' => 0.0, 'comision_max_pct' => 0.0,
                'descuento_max_pct' => 0.0, 'desde_columnas_viejas' => false,
            ];
        }

        return [
            'precio'                => (float) ($item->{"precio_{$columna}"} ?? 0),
            // Mayorista nunca tuvo columnas de comisión: es el canal base y no las paga.
            'comision_min_pct'      => (float) ($item->{"comision_min_{$columna}"} ?? 0),
            'comision_max_pct'      => (float) ($item->{"comision_max_{$columna}"} ?? 0),
            'descuento_max_pct'     => (float) ($item->{"descuento_max_{$columna}"} ?? 0),
            'desde_columnas_viejas' => true,
        ];
    }

    /** El precio que ve alguien que no ha entrado: el del canal marcado como público. */
    public function precioPublicoDe(Model $item): ?float
    {
        return $this->precioDe($item, $this->canales->publico());
    }

    /**
     * Guarda los canales que llegan del formulario.
     *
     * Se ignoran los que no correspondan a un canal configurado: si alguien manda un id
     * inventado, no se crea una fila fantasma que después nadie sabe de dónde salió.
     *
     * @param  array<int, array<string, mixed>>  $filas
     */
    public function guardar(Model $item, array $filas): void
    {
        $validos = $this->canales->canales()->keyBy('id');
        $base    = $this->canales->base();

        DB::transaction(function () use ($item, $filas, $validos, $base) {
            foreach ($filas as $fila) {
                $canal = $validos->get((int) ($fila['segmentacion_opcion_id'] ?? 0));

                if (! $canal) {
                    continue;
                }

                $esBase = $base && $canal->id === $base->id;

                CanalPrecio::updateOrCreate(
                    [
                        'precionable_type'       => $item->getMorphClass(),
                        'precionable_id'         => $item->getKey(),
                        'segmentacion_opcion_id' => $canal->id,
                    ],
                    [
                        'margen_pct'  => (float) ($fila['margen_pct'] ?? 0),
                        'precio'      => (float) ($fila['precio'] ?? 0),
                        // El canal base no lleva comisión, aunque el formulario mande algo:
                        // es el piso de utilidad de la empresa, no una venta con margen
                        // para repartir.
                        'comision_min_pct'  => $esBase ? 0 : (float) ($fila['comision_min_pct'] ?? 0),
                        'comision_max_pct'  => $esBase ? 0 : (float) ($fila['comision_max_pct'] ?? 0),
                        'descuento_max_pct' => (float) ($fila['descuento_max_pct'] ?? 0),
                    ]
                );
            }

            $this->espejarEnColumnasViejas($item);
        });
    }

    /**
     * Copia los tres canales originales a sus columnas de siempre.
     *
     * Mientras haya código leyendo `precio_mayorista` y compañía, las dos
     * representaciones tienen que decir lo mismo. Cuando ese código ya no exista, esto se
     * borra y las columnas se retiran — no antes: al otro lado hay instalaciones de
     * clientes con versiones anteriores.
     */
    private function espejarEnColumnasViejas(Model $item): void
    {
        $porCanal = $item->preciosPorCanal()->with('canal')->get()
            ->keyBy('segmentacion_opcion_id');

        $cambios   = [];
        $esProducto = $item instanceof Producto;

        // Se recorren los canales CONFIGURADOS y se pregunta a cuál columna le toca cada
        // uno. Antes se recorrían las tres claves de fábrica, así que en una instalación
        // con canales propios el espejo no escribía nada y las columnas viejas —que la
        // ficha del producto todavía muestra— se quedaban en cero.
        foreach ($this->canales->canales() as $canal) {
            $sufijo = $this->columnaDe($canal);
            $fila   = $porCanal->get($canal->id);

            if (! $sufijo || ! $fila) {
                continue;
            }

            $cambios["precio_{$sufijo}"] = $fila->precio;

            // Los márgenes por canal solo existen en productos: el precio de un ensamble
            // lo calcula el cotizador desde su receta, no un margen sobre el costo.
            if ($esProducto) {
                $cambios["margen_{$sufijo}"] = $fila->margen_pct;
            }

            // El canal base nunca tuvo columnas de comisión: es el piso de utilidad.
            if (! $canal->es_canal_base) {
                $cambios["comision_min_{$sufijo}"] = $fila->comision_min_pct;
                $cambios["comision_max_{$sufijo}"] = $fila->comision_max_pct;
            }

            $cambios["descuento_max_{$sufijo}"] = $fila->descuento_max_pct;
        }

        if ($cambios !== []) {
            // Sin eventos ni timestamps: es un espejo interno, no un cambio del usuario, y
            // no tiene por qué aparecer en la auditoría dos veces.
            $item->newQuery()->whereKey($item->getKey())->update($cambios);
        }
    }

    /**
     * Traduce el formato viejo del formulario al nuevo.
     *
     * Sirve para que las pantallas que todavía mandan `precio_mayorista` y compañía sigan
     * funcionando mientras se cambian una por una. Sin esto habría que cambiar productos,
     * ensambles y sus variantes en el mismo commit, y un error se llevaría los tres.
     */
    public function desdeCamposViejos(array $entrada): array
    {
        $filas = [];

        // Por papel y no por clave: una instalación con canales propios no tiene ninguna
        // opción llamada «mayorista», y esta conversión no creaba ni una fila. Ese era el
        // motivo real de que guardar un producto desde la pantalla de editar dejara sus
        // precios solo en las columnas viejas, invisibles para la cotización.
        foreach ($this->canales->canales() as $canal) {
            $sufijo = $this->columnaDe($canal);

            if (! $sufijo) {
                continue;
            }

            $filas[] = [
                'segmentacion_opcion_id' => $canal->id,
                'margen_pct'        => (float) ($entrada["margen_{$sufijo}"] ?? 0),
                'precio'            => (float) ($entrada["precio_{$sufijo}"] ?? 0),
                'comision_min_pct'  => (float) ($entrada["comision_min_{$sufijo}"] ?? 0),
                'comision_max_pct'  => (float) ($entrada["comision_max_{$sufijo}"] ?? 0),
                'descuento_max_pct' => (float) ($entrada["descuento_max_{$sufijo}"] ?? 0),
            ];
        }

        return $filas;
    }
}
