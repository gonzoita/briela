<?php

namespace App\Services;

use App\Models\GraficoDashboard;
use App\Support\ContextoSede;
use Illuminate\Support\Facades\DB;

/**
 * De dónde salen los números de un gráfico, y qué se puede preguntarle a cada fuente.
 *
 * **Este catálogo es el que impide que armar un gráfico sea escribir SQL desde el navegador.**
 * La pantalla manda claves —`cotizaciones`, `estado`, `suma_total`— y aquí se traducen a
 * columnas. Una clave que no esté en el catálogo se ignora, igual que hace `App\Support\Orden`
 * con el ordenamiento de las listas. Sin esa traducción, un gráfico sería una consulta abierta
 * contra la base con el nombre de columna llegando del cliente.
 *
 * Son pocas fuentes y a propósito. Un constructor que puede graficar cualquier tabla se ve
 * impresionante y termina produciendo gráficos que nadie sabe leer; estas responden preguntas
 * que alguien de verdad hace un lunes.
 */
class FuentesGraficoService
{
    /**
     * El catálogo. Cada fuente declara qué mide, cómo se agrupa y qué se puede filtrar.
     *
     * @return array<string, array<string, mixed>>
     */
    public function catalogo(): array
    {
        return [
            'cotizaciones' => [
                'label'   => 'Cotizaciones',
                'modulo'  => 'cotizaciones',
                'tabla'   => 'cotizaciones',
                'sede'    => 'sede_id',
                'fecha'   => 'fecha_creacion',
                'medidas' => [
                    'conteo'     => ['label' => 'Cantidad de cotizaciones', 'sql' => 'COUNT(*)'],
                    'suma_total' => ['label' => 'Valor total', 'sql' => 'SUM(total)', 'dinero' => true],
                    'promedio'   => ['label' => 'Ticket promedio', 'sql' => 'AVG(total)', 'dinero' => true],
                ],
                'dimensiones' => [
                    'estado'      => ['label' => 'Estado',      'sql' => 'estado'],
                    'mes'         => ['label' => 'Mes',         'sql' => "DATE_FORMAT(fecha_creacion, '%Y-%m')", 'orden' => 'asc'],
                    'responsable' => ['label' => 'Responsable', 'sql' => '(SELECT name FROM users u WHERE u.id = cotizaciones.responsable_id)'],
                ],
                'filtros' => ['estado' => 'estado', 'desde' => 'fecha_creacion', 'hasta' => 'fecha_creacion'],
            ],

            'ops' => [
                'label'   => 'Órdenes de producción',
                'modulo'  => 'ops',
                'tabla'   => 'ops',
                'sede'    => 'sede_id',
                'fecha'   => 'fecha_creacion',
                'medidas' => [
                    'conteo'     => ['label' => 'Cantidad de órdenes', 'sql' => 'COUNT(*)'],
                    'suma_total' => ['label' => 'Valor total', 'sql' => 'SUM(total)', 'dinero' => true],
                    'promedio_avance' => ['label' => 'Avance promedio (%)', 'sql' => 'AVG(porcentaje_avance)'],
                ],
                'dimensiones' => [
                    'estado' => ['label' => 'Estado', 'sql' => 'estado'],
                    'mes'    => ['label' => 'Mes',    'sql' => "DATE_FORMAT(fecha_creacion, '%Y-%m')", 'orden' => 'asc'],
                ],
                'filtros' => ['estado' => 'estado', 'desde' => 'fecha_creacion', 'hasta' => 'fecha_creacion'],
            ],

            'comisiones' => [
                'label'   => 'Comisiones',
                'modulo'  => 'comisiones',
                'tabla'   => 'comisiones_vendedor',
                'fecha'   => 'created_at',
                'medidas' => [
                    'suma_total' => ['label' => 'Comisión total', 'sql' => 'SUM(total_comision)', 'dinero' => true],
                    'conteo'     => ['label' => 'Cantidad de comisiones', 'sql' => 'COUNT(*)'],
                ],
                'dimensiones' => [
                    'estado'   => ['label' => 'Estado',   'sql' => 'estado'],
                    'mes'      => ['label' => 'Mes',      'sql' => 'periodo_mes', 'orden' => 'asc'],
                    'vendedor' => ['label' => 'Vendedor', 'sql' => '(SELECT name FROM users u WHERE u.id = comisiones_vendedor.user_id)'],
                ],
                'filtros' => ['estado' => 'estado', 'desde' => 'created_at', 'hasta' => 'created_at'],
            ],

            'recaudo' => [
                'label'   => 'Recaudo',
                'modulo'  => 'financiero',
                'tabla'   => 'op_pagos',
                'fecha'   => 'fecha_pago',
                'medidas' => [
                    'suma_total' => ['label' => 'Recaudado', 'sql' => 'SUM(valor)', 'dinero' => true],
                    'conteo'     => ['label' => 'Cantidad de pagos', 'sql' => 'COUNT(*)'],
                    'promedio'   => ['label' => 'Pago promedio', 'sql' => 'AVG(valor)', 'dinero' => true],
                ],
                'dimensiones' => [
                    'mes'        => ['label' => 'Mes',          'sql' => "DATE_FORMAT(fecha_pago, '%Y-%m')", 'orden' => 'asc'],
                    'dia'        => ['label' => 'Día',          'sql' => 'DATE(fecha_pago)', 'orden' => 'asc'],
                    'medio_pago' => ['label' => 'Medio de pago','sql' => 'medio_pago'],
                ],
                'filtros' => ['desde' => 'fecha_pago', 'hasta' => 'fecha_pago'],
            ],

            'cartera' => [
                'label'   => 'Cartera',
                'modulo'  => 'financiero',
                'tabla'   => 'op_cuotas',
                'fecha'   => 'fecha_vencimiento',
                'medidas' => [
                    'suma_total'    => ['label' => 'Valor de las cuotas', 'sql' => 'SUM(valor)', 'dinero' => true],
                    'suma_pagado'   => ['label' => 'Pagado', 'sql' => 'SUM(valor_pagado)', 'dinero' => true],
                    'suma_pendiente'=> ['label' => 'Por cobrar', 'sql' => 'SUM(valor - valor_pagado)', 'dinero' => true],
                    'conteo'        => ['label' => 'Cantidad de cuotas', 'sql' => 'COUNT(*)'],
                ],
                'dimensiones' => [
                    'estado' => ['label' => 'Estado', 'sql' => 'estado'],
                    'mes'    => ['label' => 'Mes de vencimiento', 'sql' => "DATE_FORMAT(fecha_vencimiento, '%Y-%m')", 'orden' => 'asc'],
                ],
                'filtros' => ['estado' => 'estado', 'desde' => 'fecha_vencimiento', 'hasta' => 'fecha_vencimiento'],
            ],

            'alistamiento' => [
                'label'   => 'Ítems de producción',
                'modulo'  => 'alistamiento',
                'tabla'   => 'op_items',
                'fecha'   => 'created_at',
                'medidas' => [
                    'conteo'        => ['label' => 'Cantidad de ítems', 'sql' => 'COUNT(*)'],
                    'suma_cantidad' => ['label' => 'Unidades', 'sql' => 'SUM(cantidad)'],
                ],
                'dimensiones' => [
                    'estado_item' => ['label' => 'Estado de alistamiento', 'sql' => 'estado_item'],
                    'tipo'        => ['label' => 'Tipo',                   'sql' => 'tipo'],
                    'mes'         => ['label' => 'Mes',                    'sql' => "DATE_FORMAT(created_at, '%Y-%m')", 'orden' => 'asc'],
                ],
                'filtros' => ['desde' => 'created_at', 'hasta' => 'created_at'],
            ],
        ];
    }

    /** Lo que necesita la pantalla para armar el formulario, sin exponer una sola columna. */
    public function paraPantalla(?string $modulo = null): array
    {
        return collect($this->catalogo())
            ->when($modulo, fn ($c) => $c->where('modulo', $modulo))
            ->map(fn ($f, $clave) => [
                'clave'       => $clave,
                'label'       => $f['label'],
                'medidas'     => collect($f['medidas'])->map(fn ($m, $k) => ['clave' => $k, 'label' => $m['label']])->values(),
                'dimensiones' => collect($f['dimensiones'])->map(fn ($d, $k) => ['clave' => $k, 'label' => $d['label']])->values(),
                'filtros'     => array_keys($f['filtros'] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * Calcula un gráfico.
     *
     * @return array{titulo: string, tipo: string, dinero: bool, datos: array<int, array{etiqueta: string, valor: float}>, total: float}
     */
    public function calcular(GraficoDashboard $g): array
    {
        $fuente = $this->catalogo()[$g->fuente] ?? null;

        if (! $fuente) {
            return $this->vacio($g, 'Esa fuente ya no existe.');
        }

        $medida = $fuente['medidas'][$g->medida] ?? null;

        if (! $medida) {
            return $this->vacio($g, 'Esa medida ya no existe en la fuente.');
        }

        $dim = $fuente['dimensiones'][$g->dimension] ?? null;

        $q = DB::table($fuente['tabla']);

        // La sede activa manda, igual que en las listas. Se aplica a mano y no con
        // `ContextoSede::aplicar()` porque esto es un query builder, no uno de Eloquent: el
        // gráfico no necesita modelos, y armarlos para contar filas es trabajo de más.
        // Una fuente sin columna de sede —las comisiones— no se filtra: no la tiene.
        if (! empty($fuente['sede']) && ($sede = ContextoSede::id())) {
            $q->where($fuente['sede'], $sede);
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn($fuente['tabla'], 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        foreach (($g->filtros ?? []) as $clave => $valor) {
            $columna = $fuente['filtros'][$clave] ?? null;

            if (! $columna || $valor === null || $valor === '') {
                continue;
            }

            match ($clave) {
                'desde' => $q->whereDate($columna, '>=', $valor),
                'hasta' => $q->whereDate($columna, '<=', $valor),
                default => $q->where($columna, $valor),
            };
        }

        // Sin dimensión es un solo número: el total. Con dimensión, una fila por grupo.
        if (! $dim) {
            $total = (float) ($q->selectRaw($medida['sql'] . ' as v')->value('v') ?? 0);

            return [
                'titulo' => $g->titulo,
                'tipo'   => 'numero',
                'dinero' => (bool) ($medida['dinero'] ?? false),
                'datos'  => [['etiqueta' => $medida['label'], 'valor' => $total]],
                'total'  => $total,
                'aviso'  => null,
            ];
        }

        $filas = $q->selectRaw($dim['sql'] . ' as etiqueta, ' . $medida['sql'] . ' as valor')
            ->groupBy(DB::raw($dim['sql']))
            ->orderBy('etiqueta', $dim['orden'] ?? 'asc')
            ->limit(60)
            ->get()
            ->map(fn ($f) => ['etiqueta' => (string) ($f->etiqueta ?? '—'), 'valor' => (float) $f->valor])
            ->all();

        return [
            'titulo' => $g->titulo,
            'tipo'   => $g->tipo,
            'dinero' => (bool) ($medida['dinero'] ?? false),
            'datos'  => $filas,
            'total'  => array_sum(array_column($filas, 'valor')),
            'aviso'  => null,
        ];
    }

    private function vacio(GraficoDashboard $g, string $aviso): array
    {
        return ['titulo' => $g->titulo, 'tipo' => $g->tipo, 'dinero' => false, 'datos' => [], 'total' => 0, 'aviso' => $aviso];
    }
}
