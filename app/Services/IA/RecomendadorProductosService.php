<?php

namespace App\Services\IA;

use App\Models\Ensamble;
use App\Models\Producto;
use App\Services\PreciosPorCanalService;
use Illuminate\Support\Str;

/**
 * Encuentra los productos que podrían servir para lo que el cliente pide.
 *
 * **Lo que hace cara a una recomendación con IA es mandarle el catálogo entero al modelo.**
 * Con doscientos productos son decenas de miles de tokens por pregunta, y la mitad son
 * fichas técnicas que no tienen nada que ver con lo que se preguntó. Aquí el filtro lo hace
 * la base de datos —que es gratis— y al modelo solo le llegan los candidatos.
 *
 * Cómo filtra: de la pregunta se sacan las palabras con contenido y los números —«2.4»,
 * «1.2 x 2.3», «2400»— y se buscan en el nombre, la referencia, la categoría y sobre todo
 * en el **resumen técnico para cotizaciones**, que es el campo donde están las medidas y
 * los materiales en una línea. Cada coincidencia suma; se devuelven los mejores.
 *
 * Las medidas se buscan en varias formas porque nadie las escribe igual: «1.2» aparece en
 * las fichas como «1.2», «1,2» y «1200».
 */
class RecomendadorProductosService
{
    /** Cuántos candidatos ve el modelo. Suficiente para elegir, barato de leer. */
    private const TOPE = 12;

    /**
     * Palabras que no aportan a la búsqueda.
     *
     * Sin esta lista, «que producto me recomiendas para» hace que cada término genérico
     * traiga medio catálogo y el puntaje deje de significar algo.
     */
    private const VACIAS = [
        'que', 'qué', 'cual', 'cuál', 'como', 'cómo', 'para', 'por', 'con', 'sin', 'los', 'las',
        'del', 'una', 'uno', 'unos', 'unas', 'este', 'esta', 'esto', 'ese', 'esa', 'algo',
        'producto', 'productos', 'articulo', 'artículo', 'servicio', 'me', 'mi', 'tu', 'su',
        'recomiendas', 'recomienda', 'recomendar', 'necesito', 'quiero', 'busco', 'tienes',
        'tengo', 'hay', 'sirve', 'sirva', 'ademas', 'además', 'tambien', 'también', 'mas',
        'más', 'muy', 'bien', 'todo', 'toda', 'cosa', 'cosas', 'dime', 'ayuda', 'ayudame',
        'ayúdame', 'seria', 'sería', 'puede', 'pueda', 'debe', 'sea', 'son', 'esta', 'está',
        'tiene', 'tenga', 'dimensiones', 'medidas', 'tamaño', 'tamano',
    ];

    public function __construct(private PreciosPorCanalService $precios) {}

    /**
     * @return array<string, mixed> Lo que ve el modelo: los términos usados y los candidatos.
     */
    public function candidatos(string $necesidad): array
    {
        $terminos = $this->terminos($necesidad);

        if ($terminos === []) {
            return [
                'error' => 'La pregunta no trae ninguna palabra con la que buscar. Pide más detalle: '
                    .'para qué lo necesita, medidas, material o temperatura.',
            ];
        }

        $productos = $this->buscarProductos($terminos);
        $ensambles = $this->buscarEnsambles($terminos);

        $candidatos = $productos->concat($ensambles)
            ->sortByDesc('coincidencias')
            ->take(self::TOPE)
            ->values();

        return [
            'terminos_buscados' => $terminos,
            'total_candidatos'  => $candidatos->count(),
            'candidatos'        => $candidatos->all(),
            // El modelo no debe inventar productos: si la lista viene corta, hay que decirlo
            // en vez de rellenar con algo parecido.
            'instruccion'       => 'Recomienda ÚNICAMENTE de esta lista. Si ninguno cumple lo que '
                .'piden, dilo claramente y explica qué falta. Cita nombre y referencia, y di por '
                .'qué ese y no otro, apoyándote en su resumen técnico.',
        ];
    }

    /**
     * Las palabras y los números con los que vale la pena buscar.
     *
     * @return array<int, string>
     */
    public function terminos(string $texto): array
    {
        $limpio = Str::lower(Str::ascii($texto));

        // Los números se sacan primero y con su forma original: «1.2» y «2,3» son medidas, y
        // partirlos por el separador decimal los volvería un «1» y un «2» inservibles.
        preg_match_all('/\d+(?:[.,]\d+)?/', $limpio, $coincidencias);
        $numeros = $coincidencias[0] ?? [];

        $palabras = collect(preg_split('/[^a-z0-9]+/', $limpio) ?: [])
            ->filter(fn ($p) => mb_strlen($p) >= 4 && ! in_array($p, self::VACIAS, true))
            ->reject(fn ($p) => is_numeric($p))
            ->values();

        return $palabras->concat($numeros)->unique()->take(10)->values()->all();
    }

    /**
     * Las formas en que un mismo número puede estar escrito en una ficha.
     *
     * «1.2» metros puede aparecer como «1.2», «1,2» o «1200» milímetros. Buscar solo la
     * forma que escribió el usuario deja fuera al producto correcto.
     *
     * @return array<int, string>
     */
    private function formasDe(string $termino): array
    {
        if (! is_numeric(str_replace(',', '.', $termino))) {
            return [$termino];
        }

        $normal = str_replace(',', '.', $termino);
        $formas = [$termino, $normal, str_replace('.', ',', $normal)];

        // Metros a milímetros, que es como se escriben las medidas de fabricación.
        if (str_contains($normal, '.')) {
            $mm = (float) $normal * 1000;

            if ($mm >= 100 && $mm == floor($mm)) {
                $formas[] = (string) (int) $mm;
            }
        }

        return array_values(array_unique($formas));
    }

    private function buscarProductos(array $terminos): \Illuminate\Support\Collection
    {
        $consulta = Producto::query()
            ->where('activo', true)
            ->where('es_vendible', true)
            ->where('es_padre', false)
            ->with('categoria:id,nombre');

        $this->aplicarTerminos($consulta, $terminos, ['nombre', 'referencia', 'descripcion_cotizacion', 'descripcion_corta']);

        return $consulta->limit(40)->get()->map(fn (Producto $p) => [
            'tipo'            => 'producto',
            'id'              => $p->id,
            'nombre'          => $p->nombre,
            'referencia'      => $p->referencia,
            'categoria'       => $p->categoria?->nombre,
            'unidad'          => $p->unidad_medida,
            // El resumen técnico es la fuente: una línea con medidas y materiales. Si el
            // producto no lo tiene todavía, va la comercial, que también es corta. La ficha
            // larga NO se manda: es lo que haría cara esta consulta.
            'resumen'         => $p->descripcion_cotizacion ?: $p->descripcion_corta,
            'precio'          => $this->precios->precioPublicoDe($p),
            'stock'           => $p->inventariable ? (float) $p->stockTotal() : null,
            'coincidencias'   => $this->contarCoincidencias($p->nombre.' '.$p->referencia.' '.$p->descripcion_cotizacion.' '.$p->descripcion_corta.' '.$p->categoria?->nombre, $terminos),
        ]);
    }

    private function buscarEnsambles(array $terminos): \Illuminate\Support\Collection
    {
        $consulta = Ensamble::query()->with('categoria:id,nombre');

        $this->aplicarTerminos($consulta, $terminos, ['nombre', 'descripcion_cotizacion', 'descripcion_corta']);

        return $consulta->limit(40)->get()->map(fn (Ensamble $e) => [
            'tipo'          => 'ensamble',
            'id'            => $e->id,
            'nombre'        => $e->nombre,
            'referencia'    => 'ENS-'.$e->id,
            'categoria'     => $e->categoria?->nombre,
            'unidad'        => 'unidad',
            'resumen'       => $e->descripcion_cotizacion ?: $e->descripcion_corta,
            // Un ensamble se cotiza por medidas: su precio es un punto de partida.
            'precio'        => $this->precios->precioPublicoDe($e),
            'precio_es_desde' => true,
            'stock'         => null,
            'coincidencias' => $this->contarCoincidencias($e->nombre.' '.$e->descripcion_cotizacion.' '.$e->descripcion_corta.' '.$e->categoria?->nombre, $terminos),
        ]);
    }

    /** Cualquier término en cualquiera de los campos: el puntaje decide después. */
    private function aplicarTerminos($consulta, array $terminos, array $campos): void
    {
        $consulta->where(function ($q) use ($terminos, $campos) {
            foreach ($terminos as $termino) {
                foreach ($this->formasDe($termino) as $forma) {
                    foreach ($campos as $campo) {
                        $q->orWhere($campo, 'like', "%{$forma}%");
                    }
                }
            }
        });
    }

    /** Cuántos términos distintos aparecen. Es el orden en que los ve el modelo. */
    private function contarCoincidencias(?string $texto, array $terminos): int
    {
        $texto = Str::lower(Str::ascii((string) $texto));

        return collect($terminos)
            ->filter(function ($termino) use ($texto) {
                foreach ($this->formasDe($termino) as $forma) {
                    if (str_contains($texto, Str::lower(Str::ascii($forma)))) {
                        return true;
                    }
                }

                return false;
            })
            ->count();
    }
}
