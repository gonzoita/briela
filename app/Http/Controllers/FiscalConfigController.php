<?php

namespace App\Http\Controllers;

use App\Exceptions\IaException;
use App\Models\Configuracion;
use App\Services\IA\LectorRutService;
use App\Support\Fiscal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración → Perfil fiscal: quién es la empresa ante la DIAN y cómo le retienen.
 *
 * Los datos de identificación son los mismos que ya usan los PDF (`empresa_nombre`,
 * `empresa_nit`…): se editan aquí o se llenan leyendo el RUT de la empresa.
 */
class FiscalConfigController extends Controller
{
    private const EMPRESA = ['nombre', 'nit', 'ciudad', 'direccion', 'telefono', 'email'];

    public function index(): Response
    {
        return Inertia::render('Configuracion/Fiscal', [
            'empresa'  => collect(self::EMPRESA)->mapWithKeys(fn ($c) => [$c => (string) Configuracion::get("empresa_{$c}", '')]),
            'fiscal'   => Fiscal::ajustes(),
            'catalogo' => Fiscal::catalogo(),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'empresa'             => 'required|array',
            'empresa.nombre'      => 'nullable|string|max:200',
            'empresa.nit'         => 'nullable|string|max:30',
            'empresa.ciudad'      => 'nullable|string|max:100',
            'empresa.direccion'   => 'nullable|string|max:200',
            'empresa.telefono'    => 'nullable|string|max:30',
            'empresa.email'       => 'nullable|email|max:150',
            'responsabilidades'   => 'nullable|array',
            'responsabilidades.*' => 'string|max:5',
            'actividad'           => 'nullable|string|max:10',
            'uvt'                 => 'nullable|numeric|min:0',
            'conceptos'           => 'required|array|min:1',
            'conceptos.*.clave'   => 'required|in:compras,servicios',
            'conceptos.*.nombre'  => 'required|string|max:60',
            'conceptos.*.tarifa'  => 'required|numeric|min:0|max:100',
            'conceptos.*.base_uvt'=> 'required|numeric|min:0',
            'reteiva_pct'         => 'required|numeric|min:0|max:100',
            'reteica_por_mil'     => 'required|numeric|min:0|max:100',
        ]);

        foreach (self::EMPRESA as $campo) {
            Configuracion::set("empresa_{$campo}", (string) ($datos['empresa'][$campo] ?? ''));
        }

        Fiscal::guardarJson('fiscal_responsabilidades', Fiscal::codigos($datos['responsabilidades'] ?? []));
        Fiscal::guardarJson('fiscal_retefuente_conceptos', array_values($datos['conceptos']));
        Configuracion::set('fiscal_actividad', (string) ($datos['actividad'] ?? ''));
        Configuracion::set('fiscal_uvt', isset($datos['uvt']) && $datos['uvt'] > 0 ? (string) $datos['uvt'] : '');
        Configuracion::set('fiscal_reteiva_pct', (string) $datos['reteiva_pct']);
        Configuracion::set('fiscal_reteica_por_mil', (string) $datos['reteica_por_mil']);

        return back()->with('success', 'Perfil fiscal guardado.');
    }

    /** Lee el RUT de la empresa y devuelve los datos para el formulario, sin guardar. */
    public function leerRut(Request $request, LectorRutService $lector): JsonResponse
    {
        $request->validate([
            'archivo' => 'required|file|max:10240|mimetypes:' . implode(',', LectorRutService::TIPOS),
        ], [
            'archivo.mimetypes' => 'El archivo tiene que ser un PDF o una foto (JPG, PNG o WEBP).',
        ]);

        try {
            $leido = $lector->leer($request->file('archivo'));
        } catch (IaException $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        $d = $leido['datos'];

        return response()->json([
            'ok'     => true,
            'avisos' => $leido['avisos'],
            'datos'  => [
                'empresa' => [
                    'nombre'    => trim($d['nombre'] . ' ' . $d['apellido']),
                    'nit'       => $d['numero_identificacion'] !== ''
                        ? $d['numero_identificacion'] . ($d['digito_verificacion'] !== null ? "-{$d['digito_verificacion']}" : '')
                        : '',
                    'ciudad'    => $d['ciudad'],
                    'direccion' => $d['direccion'],
                    'telefono'  => $d['telefono'],
                    'email'     => $d['email'],
                ],
                'responsabilidades' => $d['responsabilidades_fiscales'],
                'actividad'         => $d['actividad_economica'],
            ],
        ]);
    }
}
