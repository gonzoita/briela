<?php

namespace App\Services;

use App\Models\Informe;
use App\Models\Op;
use App\Models\Operario;
use App\Models\Cotizacion;
use App\Models\OpItemTrabajoPaso;
use App\Models\User;
use App\Models\TipoColaborador;
use App\Models\EstacionTrabajo;
use App\Models\NivelColaborador;
use Illuminate\Support\Carbon;

class InformeService
{
    public function ejecutar(Informe $informe): array
    {
        return match ($informe->fuente) {
            'ops'           => $this->ejecutarOps($informe),
            'colaboradores' => $this->ejecutarColaboradores($informe),
            'cotizaciones'  => $this->ejecutarCotizaciones($informe),
            'pasos'         => $this->ejecutarPasos($informe),
            default         => [],
        };
    }

    // ─── OPs ──────────────────────────────────────────────────────────────────

    private function ejecutarOps(Informe $informe): array
    {
        $filtros = $informe->filtros ?? [];

        // Respeta la sede activa. Con "Todas las sedes" no filtra, y así el
        // informe sirve para comparar sedes entre sí.
        $query = \App\Support\ContextoSede::aplicar(Op::query())
            ->with(['cliente', 'items', 'sede:id,nombre'])
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($filtros['fecha_desde'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filtros['fecha_hasta'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filtros['cliente'] ?? null, fn ($q, $v) => $q->whereHas('cliente', fn ($qc) => $qc->where('nombre', 'like', "%$v%")));

        return $query->get()->map(function ($op) use ($informe) {
            $row = [];
            foreach ($informe->campos as $campo) {
                $row[$campo] = match ($campo) {
                    'numero'             => $op->numero,
                    'cliente'            => $op->cliente?->nombre ?? '—',
                    'estado'             => $op->estado,
                    'porcentaje_avance'  => $op->porcentaje_avance . '%',
                    'created_at'         => $op->created_at?->format('d/m/Y'),
                    'total_items'        => $op->items->count(),
                    'sede'               => $op->sede?->nombre ?? '—',
                    default              => '—',
                };
            }
            return $row;
        })->toArray();
    }

    // ─── Colaboradores ─────────────────────────────────────────────────────────

    private function ejecutarColaboradores(Informe $informe): array
    {
        $filtros = $informe->filtros ?? [];

        $query = \App\Support\ContextoSede::aplicar(Operario::query())
            ->with(['tipoColaborador', 'sede:id,nombre'])
            ->when($filtros['nivel'] ?? null, fn ($q, $v) => $q->where('nivel', $v))
            ->when($filtros['tipo_colaborador_id'] ?? null, fn ($q, $v) => $q->where('tipo_colaborador_id', $v));

        return $query->get()->map(function ($op) use ($informe) {
            $row = [];
            foreach ($informe->campos as $campo) {
                $row[$campo] = match ($campo) {
                    'nombre'            => $op->nombre,
                    'documento'         => $op->documento,
                    'cargo'             => $op->cargo,
                    'nivel'             => $op->nivel,
                    'puntos_totales'    => $op->puntos_totales,
                    'tipo_colaborador'  => $op->tipoColaborador?->nombre ?? '—',
                    'sede'              => $op->sede?->nombre ?? '—',
                    default             => '—',
                };
            }
            return $row;
        })->toArray();
    }

    // ─── Cotizaciones ──────────────────────────────────────────────────────────

    private function ejecutarCotizaciones(Informe $informe): array
    {
        $filtros = $informe->filtros ?? [];

        $query = \App\Support\ContextoSede::aplicar(Cotizacion::query())
            ->with(['cliente', 'responsable', 'sede:id,nombre'])
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($filtros['vendedor_id'] ?? null, fn ($q, $v) => $q->where('responsable_id', $v))
            ->when($filtros['fecha_desde'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filtros['fecha_hasta'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));

        return $query->get()->map(function ($cot) use ($informe) {
            $row = [];
            foreach ($informe->campos as $campo) {
                $row[$campo] = match ($campo) {
                    'numero'     => $cot->numero,
                    'cliente'    => $cot->cliente?->nombre ?? '—',
                    'vendedor'   => $cot->responsable?->name ?? '—',
                    'estado'     => $cot->estado,
                    'total'      => '$' . number_format((float) $cot->total, 0, ',', '.'),
                    'created_at' => $cot->created_at?->format('d/m/Y'),
                    'sede'       => $cot->sede?->nombre ?? '—',
                    default      => '—',
                };
            }
            return $row;
        })->toArray();
    }

    // ─── Pasos ─────────────────────────────────────────────────────────────────

    private function ejecutarPasos(Informe $informe): array
    {
        $filtros = $informe->filtros ?? [];

        $sedeActiva = \App\Support\ContextoSede::id();

        $query = OpItemTrabajoPaso::with(['trabajo.opItem.op.sede:id,nombre', 'estacion', 'operario'])
            ->when($sedeActiva, fn ($q) => $q->whereHas('trabajo.opItem.op', fn ($q2) => $q2->where('sede_id', $sedeActiva)))
            ->when($filtros['estacion_trabajo_id'] ?? null, fn ($q, $v) => $q->where('estacion_trabajo_id', $v))
            ->when(isset($filtros['completado']) && $filtros['completado'] !== '', fn ($q) => $q->where('completado', (bool) $filtros['completado']))
            ->when($filtros['fecha_desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha_programada', '>=', $v))
            ->when($filtros['fecha_hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha_programada', '<=', $v));

        return $query->get()->map(function ($paso) use ($informe) {
            $row = [];
            foreach ($informe->campos as $campo) {
                $row[$campo] = match ($campo) {
                    'nombre_paso'             => $paso->nombre,
                    'op_numero'               => $paso->trabajo?->opItem?->op?->numero ?? '-',
                    'estacion'                => $paso->estacion?->nombre ?? '-',
                    'colaborador'             => $paso->operario?->nombre ?? '-',
                    'completado'              => $paso->completado ? 'Sí' : 'No',
                    'fecha_programada'        => $paso->fecha_programada?->format('d/m/Y') ?? '-',
                    'tiempo_estimado_minutos' => $paso->tiempo_estimado_minutos ?? '-',
                    'sede'                    => $paso->trabajo?->opItem?->op?->sede?->nombre ?? '-',
                    default                   => '-',
                };
            }
            return $row;
        })->toArray();
    }

    // ─── Agregados (totales / promedios) ───────────────────────────────────────

    /**
     * Calcula el total (o promedio) de las columnas numéricas de un informe,
     * para mostrar una fila de resumen bajo la tabla. Antes el informe solo
     * listaba filas y no sumaba nada, así que no servía para responder
     * "cuánto vendí" o "tiempo promedio" de un vistazo.
     *
     * @return array<string, array{tipo:string, texto:string}>
     */
    public function calcularAgregados(string $fuente, array $campos, array $datos): array
    {
        // Qué columnas se agregan y cómo, por fuente.
        $agregables = [
            'ops'           => ['total_items' => 'suma', 'porcentaje_avance' => 'promedio'],
            'cotizaciones'  => ['total' => 'suma'],
            'colaboradores' => ['puntos_totales' => 'suma'],
            'pasos'         => ['tiempo_estimado_minutos' => 'suma'],
        ][$fuente] ?? [];

        $resultado = [];

        foreach ($campos as $campo) {
            if (! isset($agregables[$campo])) continue;
            $tipo = $agregables[$campo];

            $numeros = [];
            foreach ($datos as $fila) {
                $n = $this->parseValorCampo($campo, $fila[$campo] ?? null);
                if ($n !== null) $numeros[] = $n;
            }
            if (empty($numeros)) continue;

            $valor = $tipo === 'promedio'
                ? array_sum($numeros) / count($numeros)
                : array_sum($numeros);

            $resultado[$campo] = [
                'tipo'  => $tipo,
                'texto' => $this->formatearAgregado($campo, $tipo, $valor),
            ];
        }

        return $resultado;
    }

    private function parseValorCampo(string $campo, $valor): ?float
    {
        if ($valor === null || $valor === '' || $valor === '—' || $valor === '-') return null;
        if (is_numeric($valor)) return (float) $valor;

        $s = (string) $valor;

        if ($campo === 'total') {
            // "$1.234.000" — formato colombiano: el punto es separador de miles.
            $s = str_replace(['$', '.', ' '], '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($campo === 'porcentaje_avance') {
            // "75.00%" — el punto es decimal.
            $s = str_replace(['%', ' '], '', $s);
        } else {
            $s = preg_replace('/[^0-9.\-]/', '', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    private function formatearAgregado(string $campo, string $tipo, float $valor): string
    {
        if ($campo === 'total') {
            return '$' . number_format($valor, 0, ',', '.');
        }
        if ($campo === 'porcentaje_avance') {
            return round($valor, 1) . '%';
        }
        return $tipo === 'promedio'
            ? number_format($valor, 1, ',', '.')
            : number_format($valor, 0, ',', '.');
    }

    // ─── Metadatos ─────────────────────────────────────────────────────────────

    public function camposDisponibles(string $fuente): array
    {
        return match ($fuente) {
            'ops' => [
                ['key' => 'numero',            'label' => 'Número OP'],
                ['key' => 'cliente',           'label' => 'Cliente'],
                ['key' => 'estado',            'label' => 'Estado'],
                ['key' => 'porcentaje_avance', 'label' => '% Avance'],
                ['key' => 'created_at',        'label' => 'Fecha Creación'],
                ['key' => 'total_items',       'label' => 'Total Ítems'],
                ['key' => 'sede',              'label' => 'Sede'],
            ],
            'colaboradores' => [
                ['key' => 'nombre',           'label' => 'Nombre'],
                ['key' => 'documento',        'label' => 'Documento'],
                ['key' => 'cargo',            'label' => 'Cargo'],
                ['key' => 'nivel',            'label' => 'Nivel'],
                ['key' => 'puntos_totales',   'label' => 'Puntos'],
                ['key' => 'tipo_colaborador', 'label' => 'Tipo Colaborador'],
                ['key' => 'sede',             'label' => 'Sede'],
            ],
            'cotizaciones' => [
                ['key' => 'numero',     'label' => 'Número'],
                ['key' => 'cliente',    'label' => 'Cliente'],
                ['key' => 'vendedor',   'label' => 'Vendedor'],
                ['key' => 'estado',     'label' => 'Estado'],
                ['key' => 'total',      'label' => 'Total'],
                ['key' => 'created_at', 'label' => 'Fecha'],
                ['key' => 'sede',       'label' => 'Sede'],
            ],
            'pasos' => [
                ['key' => 'nombre_paso',             'label' => 'Nombre Paso'],
                ['key' => 'op_numero',               'label' => 'OP'],
                ['key' => 'estacion',                'label' => 'Estación'],
                ['key' => 'colaborador',             'label' => 'Colaborador'],
                ['key' => 'completado',              'label' => 'Completado'],
                ['key' => 'fecha_programada',        'label' => 'Fecha Programada'],
                ['key' => 'tiempo_estimado_minutos', 'label' => 'Tiempo Est. (min)'],
                ['key' => 'sede',                    'label' => 'Sede'],
            ],
            default => [],
        };
    }

    public function filtrosDisponibles(string $fuente): array
    {
        return match ($fuente) {
            'ops' => [
                ['key' => 'estado',      'label' => 'Estado',       'tipo' => 'select', 'opciones' => ['borrador', 'confirmada', 'en_produccion', 'calidad', 'despachada']],
                ['key' => 'fecha_desde', 'label' => 'Fecha desde',  'tipo' => 'date'],
                ['key' => 'fecha_hasta', 'label' => 'Fecha hasta',  'tipo' => 'date'],
                ['key' => 'cliente',     'label' => 'Cliente',      'tipo' => 'text'],
            ],
            'colaboradores' => [
                // Antes estos dos pedían escribir el ID/valor a mano (nadie
                // sabe el ID del tipo de colaborador). Ahora son listas con
                // los nombres reales.
                ['key' => 'nivel',               'label' => 'Nivel',            'tipo' => 'select', 'opciones' => $this->opcionesNiveles()],
                ['key' => 'tipo_colaborador_id', 'label' => 'Tipo Colaborador', 'tipo' => 'select', 'opciones' => $this->opcionesTiposColaborador()],
            ],
            'cotizaciones' => [
                ['key' => 'estado',      'label' => 'Estado',      'tipo' => 'select', 'opciones' => ['borrador', 'enviada', 'aprobada', 'rechazada', 'vencida', 'en_produccion']],
                ['key' => 'vendedor_id', 'label' => 'Vendedor',    'tipo' => 'select', 'opciones' => $this->opcionesVendedores()],
                ['key' => 'fecha_desde', 'label' => 'Fecha desde', 'tipo' => 'date'],
                ['key' => 'fecha_hasta', 'label' => 'Fecha hasta', 'tipo' => 'date'],
            ],
            'pasos' => [
                ['key' => 'estacion_trabajo_id', 'label' => 'Estación',    'tipo' => 'select', 'opciones' => $this->opcionesEstaciones()],
                ['key' => 'completado',          'label' => 'Completado',   'tipo' => 'select', 'opciones' => ['1' => 'Sí', '0' => 'No']],
                ['key' => 'fecha_desde',         'label' => 'Fecha desde',  'tipo' => 'date'],
                ['key' => 'fecha_hasta',         'label' => 'Fecha hasta',  'tipo' => 'date'],
            ],
            default => [],
        };
    }

    // ─── Opciones para filtros tipo lista (id => nombre) ───────────────────────

    private function opcionesVendedores(): array
    {
        return User::whereIn('rol', ['administrador', 'jefe_produccion', 'vendedor'])
            ->where('activo', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    private function opcionesTiposColaborador(): array
    {
        return TipoColaborador::orderBy('nombre')->pluck('nombre', 'id')->toArray();
    }

    private function opcionesEstaciones(): array
    {
        return EstacionTrabajo::orderBy('nombre')->pluck('nombre', 'id')->toArray();
    }

    private function opcionesNiveles(): array
    {
        // El filtro de colaboradores compara contra la columna `nivel` del
        // operario, así que las opciones usan el nombre del nivel como valor.
        return NivelColaborador::orderBy('orden')->pluck('nombre', 'nombre')->toArray();
    }
}
