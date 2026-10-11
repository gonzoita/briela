<?php

namespace App\Services\Informes;

use App\Models\Informe;
use App\Models\User;
use App\Services\Informes\Fuentes\FuenteCartera;
use App\Services\Informes\Fuentes\FuenteClientes;
use App\Services\Informes\Fuentes\FuenteInventario;
use App\Services\Informes\Fuentes\FuenteLeads;
use App\Services\Informes\Fuentes\FuenteProduccion;
use App\Services\Informes\Fuentes\FuenteVentas;
use Illuminate\Support\Str;

/**
 * El motor de los informes: ejecuta una fuente con su periodo y sus filtros, se queda con las
 * columnas pedidas, arma los resúmenes («por canal», «por vendedor») y las cifras clave.
 *
 * Es el mismo para el informe que se arma a mano en /informes y para el que pide la guía en el
 * chat: los dos guardan un `Informe` y los dos se exportan igual. Lo que la IA recibe del motor
 * son los TOTALES (`paraIa()`), nunca las filas: los nombres y teléfonos van solo al archivo que
 * descarga quien tiene permiso.
 */
class MotorInformes
{
    /** Más filas que esto no caben en una hoja que alguien vaya a leer, y tumban el servidor. */
    public const MAX_FILAS = 20000;

    /** Los resúmenes que se le muestran a la IA no necesitan más renglones. */
    private const FILAS_RESUMEN_IA = 15;

    /** @var array<string, Fuente>|null */
    private ?array $fuentes = null;

    /** @return array<string, Fuente> */
    public function fuentes(): array
    {
        return $this->fuentes ??= collect([
            new FuenteLeads(),
            new FuenteVentas(),
            new FuenteClientes(),
            new FuenteProduccion(),
            new FuenteCartera(),
            new FuenteInventario(),
        ])->keyBy(fn (Fuente $f) => $f->clave())->all();
    }

    public function fuente(string $clave): ?Fuente
    {
        return $this->fuentes()[$clave] ?? null;
    }

    /** ¿La fuente es de este motor? Las cuatro de siempre (ops, cotizaciones…) siguen en InformeService. */
    public function esDelMotor(?string $clave): bool
    {
        return $clave !== null && isset($this->fuentes()[$clave]);
    }

    /** @return array<string, Fuente> las que puede usar esta persona */
    public function disponiblesPara(?User $usuario): array
    {
        return array_filter($this->fuentes(), fn (Fuente $f) => $f->permitida($usuario));
    }

    /**
     * Lo que la pantalla de armar necesita saber de cada fuente.
     *
     * @return array<string, array<string, mixed>>
     */
    public function metadatos(?User $usuario): array
    {
        return collect($this->disponiblesPara($usuario))->map(function (Fuente $f) use ($usuario) {
            $ocultas = $f->columnasOcultas($usuario);

            return [
                'etiqueta'    => $f->etiqueta(),
                'descripcion' => $f->descripcion(),
                'sobre'       => $f->sobreQueFecha(),
                'campos'      => collect($f->columnas())->except($ocultas)
                    ->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0], 'tipo' => $c[1]])->values()->all(),
                'por_defecto' => array_values(array_diff($f->columnasPorDefecto(), $ocultas)),
                'dimensiones' => collect($f->dimensiones())->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
                'filtros'     => collect($f->filtros())->map(fn ($c, $k) => ['key' => $k, 'label' => $c['etiqueta'], 'tipo' => 'select', 'opciones' => $c['opciones']])->values()->all(),
            ];
        })->all();
    }

    public function ejecutar(Informe $informe, ?User $usuario = null): array
    {
        return $this->ejecutarConfig(
            $informe->fuente,
            $informe->campos ?? [],
            $informe->filtros ?? [],
            $informe->agrupar ?? [],
            $usuario ?? auth()->user(),
        );
    }

    /**
     * @param  array<int,string>  $campos   columnas a mostrar (vacío: las de siempre)
     * @param  array<string,mixed>  $filtros  periodo, desde, hasta y los de la fuente
     * @param  array<int,string>  $agrupar  dimensiones de los resúmenes
     */
    public function ejecutarConfig(string $clave, array $campos, array $filtros, array $agrupar, ?User $usuario): array
    {
        $fuente = $this->fuente($clave);

        if (! $fuente || ! $fuente->permitida($usuario)) {
            throw new \RuntimeException('No tienes acceso a esa fuente de datos.');
        }

        $periodo  = Periodo::resolver($filtros['periodo'] ?? null, $filtros['desde'] ?? null, $filtros['hasta'] ?? null);
        $propios  = array_intersect_key($filtros, $fuente->filtros());
        $filas    = $fuente->filas($periodo['desde'], $periodo['hasta'], $propios);
        $total    = count($filas);
        $truncado = $total > self::MAX_FILAS;

        if ($truncado) {
            $filas = array_slice($filas, 0, self::MAX_FILAS);
        }

        $columnas = $this->columnasVisibles($fuente, $campos, $usuario);

        return [
            'fuente'          => $fuente->clave(),
            'fuente_etiqueta' => $fuente->etiqueta(),
            'periodo'         => $periodo['etiqueta'],
            'sobre'           => $fuente->sobreQueFecha(),
            'columnas'        => $columnas,
            'filas'           => array_map(
                fn ($fila) => array_intersect_key($fila, array_flip(array_column($columnas, 'clave'))),
                $filas,
            ),
            'total_filas'     => $total,
            'truncado'        => $truncado,
            // Las cifras clave salen de TODAS las filas, no solo de las columnas elegidas.
            'metricas'        => $this->sinOcultas($fuente->metricas($filas), $fuente, $usuario),
            'resumenes'       => array_values(array_filter(array_map(
                fn ($dim) => $this->resumir($fuente, $filas, $dim, $usuario),
                array_values(array_unique($agrupar)),
            ))),
            'generado'        => now()->toIso8601String(),
        ];
    }

    /**
     * Lo que se le manda a la IA: totales y resúmenes, nunca las filas.
     */
    public function paraIa(array $resultado): array
    {
        return [
            'informe'     => $resultado['fuente_etiqueta'],
            'periodo'     => $resultado['periodo'],
            'filtrado_por'=> $resultado['sobre'],
            'filas'       => $resultado['total_filas'],
            'cifras'      => collect($resultado['metricas'])->mapWithKeys(fn ($m) => [$m['etiqueta'] => $m['valor']])->all(),
            'resumenes'   => collect($resultado['resumenes'])->map(fn ($r) => [
                'titulo' => $r['titulo'],
                'filas'  => collect(array_slice($r['filas'], 0, self::FILAS_RESUMEN_IA))->map(
                    fn ($fila) => collect($r['columnas'])->mapWithKeys(fn ($c) => [$c['etiqueta'] => $fila[$c['clave']] ?? null])->all()
                )->all(),
                'total'  => collect($r['columnas'])->skip(1)->mapWithKeys(fn ($c) => [$c['etiqueta'] => $r['total'][$c['clave']] ?? null])->all(),
            ])->all(),
        ];
    }

    /**
     * El catálogo para que la IA elija: cada fuente con sus columnas, resúmenes y filtros.
     */
    public function catalogoParaIa(?User $usuario): string
    {
        $periodos = implode(', ', array_keys(Periodo::OPCIONES));

        $fuentes = collect($this->disponiblesPara($usuario))->map(function (Fuente $f) use ($usuario) {
            $columnas = implode(', ', array_diff(array_keys($f->columnas()), $f->columnasOcultas($usuario)));
            $dims     = implode(', ', array_keys($f->dimensiones()));
            $filtros  = collect($f->filtros())->map(fn ($c, $k) => "{$k} (".implode('|', array_keys($c['opciones'])).')')->implode(', ');

            return "- {$f->clave()}: {$f->descripcion()} El periodo filtra por {$f->sobreQueFecha()}.\n"
                ."  columnas: {$columnas}\n"
                ."  agrupar_por: {$dims}"
                .($filtros !== '' ? "\n  filtros: {$filtros}" : '');
        })->implode("\n");

        return $fuentes === '' ? '' : $fuentes."\n  periodos: {$periodos} (personalizado con desde/hasta en AAAA-MM-DD)";
    }

    /**
     * Deja un plan de la IA en algo que se puede ejecutar: solo lo que existe y se puede ver.
     *
     * @return array{fuente:string, campos:array, filtros:array, agrupar:array, nombre:string}|null
     */
    public function normalizarPlan(array $plan, ?User $usuario): ?array
    {
        $fuente = $this->fuente((string) ($plan['fuente'] ?? ''));

        if (! $fuente || ! $fuente->permitida($usuario)) {
            return null;
        }

        $validas = array_diff(array_keys($fuente->columnas()), $fuente->columnasOcultas($usuario));
        $campos  = array_values(array_intersect((array) ($plan['columnas'] ?? []), $validas));
        $agrupar = array_values(array_intersect((array) ($plan['agrupar_por'] ?? []), array_keys($fuente->dimensiones())));

        $filtros = ['periodo' => array_key_exists((string) ($plan['periodo'] ?? ''), Periodo::OPCIONES) ? $plan['periodo'] : 'ultimos_30'];
        if ($filtros['periodo'] === 'personalizado') {
            foreach (['desde', 'hasta'] as $f) {
                if (! empty($plan[$f]) && strtotime((string) $plan[$f])) {
                    $filtros[$f] = date('Y-m-d', strtotime((string) $plan[$f]));
                }
            }
        }

        foreach ($fuente->filtros() as $clave => $cfg) {
            $valor = $plan['filtros'][$clave] ?? null;
            if ($valor !== null && array_key_exists((string) $valor, $cfg['opciones'])) {
                $filtros[$clave] = (string) $valor;
            }
        }

        $periodo = Periodo::OPCIONES[$filtros['periodo']];
        $nombre  = trim((string) ($plan['nombre'] ?? '')) ?: "{$fuente->etiqueta()} · {$periodo}";

        return [
            'fuente'  => $fuente->clave(),
            'campos'  => $campos ?: array_values(array_diff($fuente->columnasPorDefecto(), $fuente->columnasOcultas($usuario))),
            'filtros' => $filtros,
            'agrupar' => $agrupar,
            'nombre'  => Str::limit($nombre, 120, ''),
        ];
    }

    // ─── Por dentro ──────────────────────────────────────────────────────────

    private function columnasVisibles(Fuente $fuente, array $campos, ?User $usuario): array
    {
        $todas   = $fuente->columnas();
        $ocultas = $fuente->columnasOcultas($usuario);
        $pedidas = array_values(array_intersect($campos ?: $fuente->columnasPorDefecto(), array_keys($todas)));
        $pedidas = array_values(array_diff($pedidas ?: $fuente->columnasPorDefecto(), $ocultas));

        return array_map(fn ($k) => ['clave' => $k, 'etiqueta' => $todas[$k][0], 'tipo' => $todas[$k][1]], $pedidas);
    }

    private function sinOcultas(array $metricas, Fuente $fuente, ?User $usuario): array
    {
        $ocultas = $fuente->columnasOcultas($usuario);

        return array_values(array_filter($metricas, fn ($m) => ! in_array($m['clave'], $ocultas, true)));
    }

    /**
     * Un resumen: las filas agrupadas por una dimensión, con las medidas de la fuente.
     */
    private function resumir(Fuente $fuente, array $filas, string $dimension, ?User $usuario): ?array
    {
        $dims = $fuente->dimensiones();

        if (! isset($dims[$dimension])) {
            return null;
        }

        $ocultas  = $fuente->columnasOcultas($usuario);
        $medidas  = array_filter($fuente->medidas(), fn ($m) => ! in_array($m['columna'] ?? '', $ocultas, true));
        $columnas = $fuente->columnas();

        $grupos = [];
        foreach ($filas as $fila) {
            $grupos[$this->valorDimension($fuente, $fila, $dimension, $columnas)][] = $fila;
        }

        $renglones = [];
        foreach ($grupos as $valor => $deGrupo) {
            $renglones[] = ['grupo' => (string) $valor] + $this->medir($medidas, $deGrupo);
        }

        // Por mes, en orden de calendario; lo demás, de mayor a menor por la primera medida.
        $primera = array_key_first($medidas);
        usort($renglones, $dimension === 'mes'
            ? fn ($a, $b) => strcmp($a['grupo'], $b['grupo'])
            : fn ($a, $b) => ($b[$primera] ?? 0) <=> ($a[$primera] ?? 0) ?: strcmp($a['grupo'], $b['grupo']));

        return [
            'dimension' => $dimension,
            'titulo'    => 'Por '.mb_strtolower($dims[$dimension]),
            'columnas'  => array_merge(
                [['clave' => 'grupo', 'etiqueta' => $dims[$dimension], 'tipo' => 'texto']],
                array_map(fn ($k, $m) => ['clave' => $k, 'etiqueta' => $m['etiqueta'], 'tipo' => $m['tipo']], array_keys($medidas), $medidas),
            ),
            'filas'     => $renglones,
            'total'     => ['grupo' => 'Total'] + $this->medir($medidas, $filas),
        ];
    }

    private function valorDimension(Fuente $fuente, array $fila, string $dimension, array $columnas): string
    {
        if ($dimension === 'mes') {
            $fecha = $fila[$fuente->columnaFecha()] ?? null;

            return $fecha ? substr((string) $fecha, 0, 7) : 'Sin fecha';
        }

        $valor = $fila[$dimension] ?? null;

        if (($columnas[$dimension][1] ?? null) === 'bool') {
            return $valor ? 'Sí' : 'No';
        }

        return ($valor === null || $valor === '') ? 'Sin dato' : (string) $valor;
    }

    private function medir(array $medidas, array $filas): array
    {
        $resultado = [];
        $n = count($filas);

        foreach ($medidas as $clave => $m) {
            $col = $m['columna'] ?? null;
            $valores = $col ? array_column($filas, $col) : [];

            $resultado[$clave] = match ($m['agregado']) {
                'contar'   => $n,
                'sumar'    => round(array_sum(array_map('floatval', array_filter($valores, fn ($v) => $v !== null))), 2),
                'promedio' => ($nn = array_filter($valores, fn ($v) => $v !== null && $v !== '')) ? round(array_sum($nn) / count($nn), 1) : null,
                'tasa'     => $n > 0 ? round(count(array_filter($valores)) * 100 / $n, 1) : null,
                default    => null,
            };
        }

        return $resultado;
    }
}
