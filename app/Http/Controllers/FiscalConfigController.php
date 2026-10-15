<?php

namespace App\Http\Controllers;

use App\Exceptions\IaException;
use App\Models\Configuracion;
use App\Models\Sede;
use App\Services\IA\LectorRutService;
use App\Support\Fiscal;
use App\Support\Paises;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración → Perfil fiscal: quién es la empresa ante su autoridad tributaria y cómo le
 * retienen.
 *
 * La empresa puede ser de cualquier país: el país es el de la sede principal (`Paises::casa()`),
 * y su identificación se revisa con el paquete de ese país (NIT, RFC, RUC, EIN…). El número sigue
 * en `empresa_nit`, la clave que ya leen los PDF; el tipo va en `empresa_tipo_identificacion`.
 * Las responsabilidades y las reglas de retención solo aplican a un país que las tenga en su
 * paquete (hoy, Colombia).
 */
class FiscalConfigController extends Controller
{
    private const EMPRESA = ['nombre', 'nit', 'ciudad', 'direccion', 'telefono', 'email'];

    public function index(): Response
    {
        $identificaciones = [];
        $conRetencion     = [];

        foreach (array_keys(Paises::CATALOGO) as $iso) {
            $perfil = Paises::perfil($iso);
            $identificaciones[$iso] = array_map(fn ($i) => [
                'codigo' => $i['codigo'], 'nombre' => $i['nombre'], 'juridica' => (bool) ($i['juridica'] ?? false),
                'dv' => $i['dv'] ?? null, 'ayuda' => $i['ayuda'] ?? null,
            ], $perfil['identificaciones']);

            if ($perfil['retenciones']) {
                $conRetencion[] = $iso;
            }
        }

        return Inertia::render('Configuracion/Fiscal', [
            'empresa'  => collect(self::EMPRESA)->mapWithKeys(fn ($c) => [$c => (string) Configuracion::get("empresa_{$c}", '')])
                ->put('pais', Paises::casa())
                ->put('tipo_identificacion', Paises::identificacionEmpresa()['tipo']),
            'fiscal'   => Fiscal::ajustes(),
            'catalogo' => Fiscal::catalogo(),
            'paises'   => Paises::lista(),
            'identificaciones_pais' => $identificaciones,
            'paises_con_retencion'  => $conRetencion,
            'documentos_fiscales'   => collect(Paises::CATALOGO)->keys()->mapWithKeys(fn ($iso) => [$iso => Paises::perfil($iso)['documento_fiscal']]),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'empresa'             => 'required|array',
            'empresa.nombre'      => 'nullable|string|max:200',
            'empresa.nit'         => 'nullable|string|max:30',
            'empresa.pais'        => ['required', 'string', Rule::in(array_keys(Paises::CATALOGO))],
            'empresa.tipo_identificacion' => 'nullable|string|max:10',
            'empresa.ciudad'      => 'nullable|string|max:100',
            'empresa.direccion'   => 'nullable|string|max:200',
            'empresa.telefono'    => 'nullable|string|max:30',
            'empresa.email'       => 'nullable|email|max:150',
            'responsabilidades'   => 'nullable|array',
            'responsabilidades.*' => 'string|max:5',
            'actividad'           => 'nullable|string|max:10',
            'uvt'                 => 'nullable|numeric|min:0',
            'conceptos'           => 'nullable|array',
            'conceptos.*.clave'   => 'required|in:compras,servicios',
            'conceptos.*.nombre'  => 'required|string|max:60',
            'conceptos.*.tarifa'  => 'required|numeric|min:0|max:100',
            'conceptos.*.base_uvt'=> 'required|numeric|min:0',
            'reteiva_pct'         => 'nullable|numeric|min:0|max:100',
            'reteica_por_mil'     => 'nullable|numeric|min:0|max:100',
        ]);

        $pais = $datos['empresa']['pais'];
        $tipo = $datos['empresa']['tipo_identificacion'] ?? '';

        if (! Sede::principal() && $pais !== Paises::casa()) {
            return back()->withErrors(['empresa.pais' => 'Crea primero la sede principal: el país de la empresa es el de esa sede.']);
        }

        if ($tipo !== '' && Paises::identificacion($pais, $tipo) === null) {
            return back()->withErrors(['empresa.tipo_identificacion' => 'Ese tipo de identificación no es de ' . Paises::nombre($pais) . '.']);
        }

        foreach (self::EMPRESA as $campo) {
            Configuracion::set("empresa_{$campo}", (string) ($datos['empresa'][$campo] ?? ''));
        }
        Configuracion::set('empresa_tipo_identificacion', $tipo);

        // El país de la empresa es el de su sede principal: cambiarlo aquí es cambiarlo allá.
        $principal = Sede::principal();
        if ($principal && $principal->pais !== $pais) {
            $principal->update(['pais' => $pais]);
            Paises::olvidar();
        }

        // Las reglas de retención solo se guardan donde existen: un país sin ellas no las manda.
        if (in_array($pais, $this->paisesConRetencion(), true) && ! empty($datos['conceptos'])) {
            Fiscal::guardarJson('fiscal_responsabilidades', Fiscal::codigos($datos['responsabilidades'] ?? []));
            Fiscal::guardarJson('fiscal_retefuente_conceptos', array_values($datos['conceptos']));
            Configuracion::set('fiscal_actividad', (string) ($datos['actividad'] ?? ''));
            Configuracion::set('fiscal_uvt', isset($datos['uvt']) && $datos['uvt'] > 0 ? (string) $datos['uvt'] : '');
            Configuracion::set('fiscal_reteiva_pct', (string) ($datos['reteiva_pct'] ?? 0));
            Configuracion::set('fiscal_reteica_por_mil', (string) ($datos['reteica_por_mil'] ?? 0));
        }

        // El formato se revisa con el paquete del país y se avisa, sin bloquear: un patrón mal
        // copiado de un decreto no puede impedir guardar a la empresa.
        $aviso = $this->avisoIdentificacion($pais, $tipo, (string) ($datos['empresa']['nit'] ?? ''));

        return back()->with('success', 'Perfil fiscal guardado.' . ($aviso ? " Revisa la identificación: {$aviso}" : ''));
    }

    /** @return list<string> */
    private function paisesConRetencion(): array
    {
        return array_values(array_filter(array_keys(Paises::CATALOGO), fn ($iso) => Paises::perfil($iso)['retenciones']));
    }

    /** El número se escribe con su dígito de verificación al final («900123456-7»); se separa para revisarlo. */
    private function avisoIdentificacion(string $pais, string $tipo, string $numero): ?string
    {
        if ($tipo === '' || trim($numero) === '') {
            return null;
        }

        $dv = null;
        if (Paises::llevaDv($pais, $tipo) && preg_match('/^(.+)-([0-9Kk])$/', trim($numero), $m)) {
            [$numero, $dv] = [$m[1], strtoupper($m[2])];
        }

        // Los puntos de miles son de forma, no de fondo: «900.123.456» es el mismo NIT.
        return Paises::avisoIdentificacion($pais, $tipo, str_replace('.', '', $numero), $dv);
    }

    /** Lee el documento fiscal de la empresa (RUT, RFC, RUC…) y devuelve los datos para el formulario, sin guardar. */
    public function leerRut(Request $request, LectorRutService $lector): JsonResponse
    {
        $request->validate([
            'archivo' => 'required|file|max:10240|mimetypes:' . implode(',', LectorRutService::TIPOS),
        ], [
            'archivo.mimetypes' => 'El archivo tiene que ser un PDF o una foto (JPG, PNG o WEBP).',
        ]);

        try {
            $leido = $lector->leer($request->file('archivo'), $request->input('pais') ?: Paises::casa());
        } catch (IaException $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        $d = $leido['datos'];

        return response()->json([
            'ok'     => true,
            'avisos' => $leido['avisos'],
            'datos'  => [
                'empresa' => [
                    'tipo_identificacion' => $d['tipo_identificacion'] ?? '',
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
