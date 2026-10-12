<?php

namespace App\Http\Controllers;

use App\Models\Informe;
use App\Services\InformeService;
use App\Services\Informes\ExportadorInformes;
use App\Services\Informes\MotorInformes;
use App\Services\Informes\Periodo;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Informes: los que cada quien arma a mano y los que pide a la guía en el chat.
 *
 * Hay dos motores por historia. Las cuatro fuentes de siempre (órdenes, colaboradores,
 * cotizaciones, pasos) siguen en `InformeService`; las nuevas —leads, ventas, clientes,
 * producción, cartera, inventario— en `MotorInformes`, que además resume («por canal») y saca
 * cifras clave. La pantalla y las exportaciones tratan a los dos igual.
 */
class InformeController extends Controller
{
    private const LEGADAS = ['ops', 'colaboradores', 'cotizaciones', 'pasos'];

    /** Lo que se muestra en pantalla; el resto se descarga. */
    private const FILAS_EN_PANTALLA = 500;

    public function __construct(
        private InformeService $service,
        private MotorInformes $motor,
        private ExportadorInformes $exportador,
    ) {}

    public function index()
    {
        $user = Auth::user();

        $informes = Informe::where(fn ($q) => $q->where('created_by', $user->id)->orWhere('publico', true))
            ->with('creador')
            ->latest()
            ->latest('id')
            ->get()
            ->map(fn ($i) => [
                'id'          => $i->id,
                'nombre'      => $i->nombre,
                'descripcion' => $i->descripcion,
                'fuente'      => $i->fuente,
                'fuente_etiqueta' => $this->motor->fuente($i->fuente)?->etiqueta() ?? Str::ucfirst($i->fuente),
                'tipo_grafica'=> $i->tipo_grafica,
                'publico'     => $i->publico,
                'origen'      => $i->origen ?? 'manual',
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
        $metadatos = [];
        foreach (self::LEGADAS as $fuente) {
            $metadatos[$fuente] = [
                'motor'   => false,
                'campos'  => $this->service->camposDisponibles($fuente),
                'filtros' => $this->service->filtrosDisponibles($fuente),
            ];
        }

        foreach ($this->motor->metadatos(Auth::user()) as $clave => $meta) {
            $metadatos[$clave] = ['motor' => true] + $meta;
        }

        return Inertia::render('Informes/Create', [
            'metadatos' => $metadatos,
            'periodos'  => collect(Periodo::OPCIONES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $permitidas = array_merge(self::LEGADAS, array_keys($this->motor->disponiblesPara(Auth::user())));

        $data = $request->validate([
            'nombre'      => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:500',
            'fuente'      => 'required|in:'.implode(',', $permitidas),
            'campos'      => 'required|array|min:1',
            'campos.*'    => 'string',
            'filtros'     => 'nullable|array',
            'agrupar'     => 'nullable|array',
            'agrupar.*'   => 'string',
            'tipo_grafica'=> 'nullable|in:barras,lineas,torta,ninguna',
            'publico'     => 'boolean',
        ]);

        $informe = Informe::create([
            ...$data,
            'filtros'    => $data['filtros'] ?? [],
            'agrupar'    => $data['agrupar'] ?? [],
            'origen'     => 'manual',
            'created_by' => Auth::id(),
        ]);

        return redirect('/informes/'.$informe->id)
            ->with('success', 'Informe creado correctamente.');
    }

    public function show(Informe $informe)
    {
        $this->autorizarVer($informe);

        $fuente = $this->motor->fuente($informe->fuente);

        return Inertia::render('Informes/Show', [
            'informe' => [
                'id'          => $informe->id,
                'nombre'      => $informe->nombre,
                'descripcion' => $informe->descripcion,
                'fuente'      => $informe->fuente,
                'fuente_etiqueta' => $fuente?->etiqueta() ?? Str::ucfirst($informe->fuente),
                'motor'       => (bool) $fuente,
                'campos'      => $informe->campos,
                'filtros'     => $informe->filtros,
                'agrupar'     => $informe->agrupar ?? [],
                'tipo_grafica'=> $informe->tipo_grafica,
                'publico'     => $informe->publico,
                'origen'      => $informe->origen ?? 'manual',
                'pregunta'    => $informe->pregunta,
                'analisis'    => $informe->analisis,
                'analizado_at'=> $informe->analizado_at?->format('d/m/Y H:i'),
                'es_mio'      => $informe->created_by === Auth::id(),
            ],
            'etiquetas_campos' => $fuente
                ? collect($fuente->columnas())->map(fn ($c) => $c[0])->toArray()
                : collect($this->service->camposDisponibles($informe->fuente))->pluck('label', 'key')->toArray(),
            'xlsx_disponible' => \App\Services\Informes\EscritorXlsx::disponible(),
        ]);
    }

    public function ejecutar(Request $request, Informe $informe)
    {
        $this->autorizarVer($informe);

        if ($this->motor->esDelMotor($informe->fuente)) {
            $r = $this->resultadoDelMotor($informe);

            return response()->json([
                'motor'       => true,
                'columnas'    => $r['columnas'],
                'datos'       => array_slice($r['filas'], 0, self::FILAS_EN_PANTALLA),
                'total'       => $r['total_filas'],
                'recortado'   => $r['total_filas'] > self::FILAS_EN_PANTALLA,
                'metricas'    => $r['metricas'],
                'resumenes'   => $r['resumenes'],
                'periodo'     => $r['periodo'],
                'sobre'       => $r['sobre'],
                'campos'      => array_column($r['columnas'], 'clave'),
                'totales'     => [],
            ]);
        }

        $datos = $this->service->ejecutar($informe);

        return response()->json([
            'motor'   => false,
            'datos'   => $datos,
            'totales' => $this->service->calcularAgregados($informe->fuente, $informe->campos, $datos),
            'campos'  => $informe->campos,
            'total'   => count($datos),
        ]);
    }

    public function exportarPdf(Informe $informe)
    {
        $this->autorizarVer($informe);

        $nombre = 'informe-'.Str::slug($informe->nombre).'.pdf';

        if ($this->motor->esDelMotor($informe->fuente)) {
            $r = $this->resultadoDelMotor($informe);

            return Pdf::loadView('pdf.informe-motor', [
                'informe'   => $informe,
                'r'         => $r,
                'filas'     => array_slice($r['filas'], 0, self::FILAS_EN_PANTALLA),
                'fecha'     => now()->format('d/m/Y H:i'),
            ])->setPaper('a4', 'landscape')->download($nombre);
        }

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

        return $pdf->download($nombre);
    }

    /** CSV del detalle, o de un resumen con `?tabla=canal`. */
    public function exportarCsv(Request $request, Informe $informe)
    {
        $this->autorizarVer($informe);

        return $this->exportador->csv($this->resultadoParaExportar($informe), 'informe-'.$informe->nombre, $request->query('tabla'));
    }

    /** El libro de Excel con todas las hojas. Si el servidor no puede escribirlo, se ofrece el CSV. */
    public function exportarXlsx(Informe $informe)
    {
        $this->autorizarVer($informe);

        $respuesta = $this->exportador->xlsx(
            $this->resultadoParaExportar($informe),
            $informe->nombre,
            $informe->analisis,
            $informe->pregunta,
        );

        return $respuesta ?? redirect("/informes/{$informe->id}")
            ->with('error', 'Este servidor no puede generar archivos de Excel (falta la extensión zip de PHP). Descarga el CSV, que Excel abre igual.');
    }

    public function destroy(Informe $informe)
    {
        $this->autorizarEditar($informe);
        $informe->delete();

        return redirect('/informes')->with('success', 'Informe eliminado.');
    }

    // ─── Por dentro ────────────────────────────────────────────────────────────

    private function resultadoDelMotor(Informe $informe): array
    {
        try {
            return $this->motor->ejecutar($informe, Auth::user());
        } catch (\RuntimeException $e) {
            abort(403, $e->getMessage());
        }
    }

    private function resultadoParaExportar(Informe $informe): array
    {
        if ($this->motor->esDelMotor($informe->fuente)) {
            return $this->resultadoDelMotor($informe);
        }

        $datos = $this->service->ejecutar($informe);

        return ExportadorInformes::desdeLegado(
            $informe->fuente,
            $informe->campos,
            collect($this->service->camposDisponibles($informe->fuente))->pluck('label', 'key')->toArray(),
            $datos,
            $this->service->calcularAgregados($informe->fuente, $informe->campos, $datos),
        );
    }

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
