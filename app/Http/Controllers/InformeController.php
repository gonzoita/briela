<?php

namespace App\Http\Controllers;

use App\Models\Informe;
use App\Services\InformeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class InformeController extends Controller
{
    public function __construct(private InformeService $service) {}

    public function index()
    {
        $user = Auth::user();

        $informes = Informe::where('created_by', $user->id)
            ->orWhere('publico', true)
            ->with('creador')
            ->latest()
            ->get()
            ->map(fn ($i) => [
                'id'          => $i->id,
                'nombre'      => $i->nombre,
                'descripcion' => $i->descripcion,
                'fuente'      => $i->fuente,
                'tipo_grafica'=> $i->tipo_grafica,
                'publico'     => $i->publico,
                'es_mio'      => $i->created_by === $user->id,
                'creador'     => $i->creador?->name,
                'created_at'  => $i->created_at?->format('d/m/Y'),
            ]);

        return Inertia::render('Informes/Index', [
            'informes' => $informes,
        ]);
    }

    public function create()
    {
        $user    = Auth::user();
        $fuentes = ['ops', 'colaboradores', 'cotizaciones', 'pasos'];

        $metadatos = [];
        foreach ($fuentes as $fuente) {
            $metadatos[$fuente] = [
                'campos'  => $this->service->camposDisponibles($fuente),
                'filtros' => $this->service->filtrosDisponibles($fuente),
            ];
        }

        return Inertia::render('Informes/Create', [
            'metadatos' => $metadatos,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'      => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:500',
            'fuente'      => 'required|in:ops,colaboradores,cotizaciones,pasos',
            'campos'      => 'required|array|min:1',
            'campos.*'    => 'string',
            'filtros'     => 'nullable|array',
            'tipo_grafica'=> 'nullable|in:barras,lineas,torta,ninguna',
            'publico'     => 'boolean',
        ]);

        $informe = Informe::create([
            ...$data,
            'filtros'    => $data['filtros'] ?? [],
            'created_by' => Auth::id(),
        ]);

        return redirect('/informes/' . $informe->id)
            ->with('success', 'Informe creado correctamente.');
    }

    public function show(Informe $informe)
    {
        $this->autorizarVer($informe);

        return Inertia::render('Informes/Show', [
            'informe' => [
                'id'          => $informe->id,
                'nombre'      => $informe->nombre,
                'descripcion' => $informe->descripcion,
                'fuente'      => $informe->fuente,
                'campos'      => $informe->campos,
                'filtros'     => $informe->filtros,
                'tipo_grafica'=> $informe->tipo_grafica,
                'publico'     => $informe->publico,
                'es_mio'      => $informe->created_by === Auth::id(),
            ],
            'etiquetas_campos' => collect($this->service->camposDisponibles($informe->fuente))
                ->pluck('label', 'key')
                ->toArray(),
        ]);
    }

    public function ejecutar(Request $request, Informe $informe)
    {
        $this->autorizarVer($informe);

        $datos = $this->service->ejecutar($informe);

        return response()->json([
            'datos'   => $datos,
            'totales' => $this->service->calcularAgregados($informe->fuente, $informe->campos, $datos),
            'campos'  => $informe->campos,
            'total'   => count($datos),
        ]);
    }

    public function exportarPdf(Informe $informe)
    {
        $this->autorizarVer($informe);

        $datos = $this->service->ejecutar($informe);
        $etiquetas = collect($this->service->camposDisponibles($informe->fuente))
            ->pluck('label', 'key')
            ->toArray();

        $pdf = Pdf::loadView('pdf.informe', [
            'informe'   => $informe,
            'datos'     => $datos,
            'totales'   => $this->service->calcularAgregados($informe->fuente, $informe->campos, $datos),
            'etiquetas' => $etiquetas,
            'fecha'     => now()->setTimezone('America/Bogota')->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        $nombre = 'informe-' . \Illuminate\Support\Str::slug($informe->nombre) . '.pdf';

        return $pdf->download($nombre);
    }

    public function exportarCsv(Informe $informe)
    {
        $this->autorizarVer($informe);

        $datos = $this->service->ejecutar($informe);
        $etiquetas = collect($this->service->camposDisponibles($informe->fuente))
            ->pluck('label', 'key')
            ->toArray();
        $totales = $this->service->calcularAgregados($informe->fuente, $informe->campos, $datos);

        $nombre = 'informe-' . \Illuminate\Support\Str::slug($informe->nombre) . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nombre}\"",
        ];

        $callback = function () use ($datos, $informe, $etiquetas, $totales) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8

            // Cabecera
            fputcsv($handle, array_map(fn ($c) => $etiquetas[$c] ?? $c, $informe->campos), ';');

            // Filas
            foreach ($datos as $fila) {
                fputcsv($handle, array_values($fila), ';');
            }

            // Fila de totales al final (si hay columnas agregables)
            if (! empty($totales)) {
                $filaTotales = array_map(function ($campo) use ($totales) {
                    return isset($totales[$campo])
                        ? ($totales[$campo]['tipo'] === 'promedio' ? 'Prom: ' : 'Total: ') . $totales[$campo]['texto']
                        : '';
                }, $informe->campos);
                fputcsv($handle, $filaTotales, ';');
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function destroy(Informe $informe)
    {
        $this->autorizarEditar($informe);
        $informe->delete();

        return redirect('/informes')->with('success', 'Informe eliminado.');
    }

    // ─── Helpers de autorización ───────────────────────────────────────────────

    private function autorizarVer(Informe $informe): void
    {
        $user = Auth::user();
        if ($informe->created_by !== $user->id && ! $informe->publico && $user->rol !== 'administrador') {
            abort(403);
        }
    }

    private function autorizarEditar(Informe $informe): void
    {
        $user = Auth::user();
        if ($informe->created_by !== $user->id && $user->rol !== 'administrador') {
            abort(403);
        }
    }
}
