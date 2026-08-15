<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * El orden de una lista, pedido desde la pantalla.
 *
 * Todas las listas ordenaban por lo mismo —lo más reciente primero— y no había forma de
 * cambiarlo. Para encontrar un producto en un catálogo de cuatrocientos, alfabético no es un
 * lujo: es la diferencia entre buscar y adivinar.
 *
 * **Lo que hace que esto sea seguro:** el campo llega del navegador, así que jamás se mete en
 * el SQL tal cual. Cada lista declara qué columnas se pueden ordenar, y lo que no esté en esa
 * lista se ignora en silencio y se cae al orden por omisión. Sin eso, `?orden=` sería una
 * inyección con nombre propio.
 *
 * Uso, en el `index()` de cada controlador:
 *
 *     $orden = Orden::aplicar($query, $request, [
 *         'nombre'       => 'nombre',
 *         'referencia'   => 'referencia',
 *         'precio_costo' => 'precio_costo',
 *     ], 'created_at', 'desc');
 *
 * y `$orden` va a la vista para que los encabezados sepan qué flecha pintar.
 */
class Orden
{
    /**
     * Ordena la consulta con lo que pidió la pantalla, o con el orden por omisión.
     *
     * @param  array<string, string|array<int, string>>  $permitidos  clave que llega → columna
     *         real, o varias columnas cuando ordenar por una sola deja empates raros.
     * @return array{campo: string, dir: string}  El orden efectivo, para la vista.
     */
    public static function aplicar(
        Builder $query,
        Request $request,
        array $permitidos,
        string $porDefecto = 'created_at',
        string $dirPorDefecto = 'desc',
    ): array {
        $pedido = (string) $request->query('orden', '');
        $dir    = strtolower((string) $request->query('dir', ''));

        // Solo dos direcciones existen. Cualquier otra cosa es basura o un intento.
        $dir = in_array($dir, ['asc', 'desc'], true) ? $dir : null;

        $campo = array_key_exists($pedido, $permitidos) ? $pedido : $porDefecto;

        // Si el campo cayó al de omisión, la dirección también: «nombre asc» tiene sentido y
        // «lo más nuevo asc» casi nunca, así que no se heredan entre campos distintos.
        if ($campo === $porDefecto && $pedido !== $porDefecto) {
            $dir ??= $dirPorDefecto;
        }

        $dir ??= self::direccionNatural($campo);

        $columnas = $permitidos[$campo] ?? $porDefecto;

        foreach ((array) $columnas as $columna) {
            $query->orderBy($columna, $dir);
        }

        // El desempate: dos productos con el mismo nombre tienen que salir siempre en el
        // mismo orden, o la paginación repite y esconde filas entre página y página.
        if (! in_array('id', (array) $columnas, true)) {
            $query->orderBy($query->getModel()->getQualifiedKeyName(), 'desc');
        }

        return ['campo' => $campo, 'dir' => $dir];
    }

    /**
     * Con qué dirección arranca un campo la primera vez que se hace clic.
     *
     * Un texto se lee de la A a la Z; una fecha o un número interesan de mayor a menor —lo
     * último que pasó, lo más caro—. Hacer que todo arranque ascendente obliga a dos clics
     * en la mitad de los casos.
     */
    private static function direccionNatural(string $campo): string
    {
        $deMayorAMenor = ['created_at', 'updated_at', 'fecha', 'total', 'stock', 'precio', 'numero', 'avance'];

        foreach ($deMayorAMenor as $pista) {
            if (str_contains($campo, $pista)) {
                return 'desc';
            }
        }

        return 'asc';
    }
}
