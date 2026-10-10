<?php

namespace App\Http\Controllers;

use App\Models\Sede;
use App\Support\Paises;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración → Países: lo que rige en cada país donde la empresa tiene una sede.
 *
 * Muestra el paquete del país (documento fiscal, identificaciones, impuestos) y deja ajustar
 * las tarifas sin esperar una versión nueva. Lo ajustado se guarda aparte del paquete, así
 * que una actualización de Briela no lo pisa.
 */
class PaisesConfigController extends Controller
{
    public function index(Request $request): Response
    {
        $enUso = Sede::query()->pluck('pais')->push(Paises::casa())->filter()->unique()->values()->all();
        $pais  = strtoupper((string) $request->query('pais', $enUso[0] ?? Paises::casa()));

        if (! Paises::existe($pais)) {
            $pais = Paises::casa();
        }

        return Inertia::render('Configuracion/Paises', [
            'pais'     => Paises::perfil($pais),
            'ajustado' => isset(Paises::ajustes()[$pais]),
            'en_uso'   => $enUso,
            'paises'   => Paises::lista(),
            'casa'     => Paises::casa(),
        ]);
    }

    public function guardar(Request $request, string $iso): RedirectResponse
    {
        abort_unless(Paises::existe($iso), 404);

        $datos = $request->validate([
            'impuestos'                => 'required|array|min:1|max:10',
            'impuestos.*.clave'        => 'required|string|max:20|alpha_dash',
            'impuestos.*.nombre'       => 'required|string|max:40',
            'impuestos.*.tarifas'      => 'required|array|min:1|max:12',
            'impuestos.*.tarifas.*'    => 'required|numeric|min:0|max:100',
            'impuestos.*.defecto'      => 'nullable|numeric|min:0|max:100',
            'impuestos.*.nota'         => 'nullable|string|max:300',
            'iva_descontable'          => 'required|boolean',
        ]);

        Paises::guardarAjuste($iso, [
            'impuestos' => collect($datos['impuestos'])->map(fn ($i) => [
                'clave'   => $i['clave'],
                'nombre'  => $i['nombre'],
                'tarifas' => collect($i['tarifas'])->map(fn ($t) => (float) $t)->unique()->sortDesc()->values()->all(),
                'defecto' => isset($i['defecto']) ? (float) $i['defecto'] : null,
                'nota'    => $i['nota'] ?? null,
            ])->values()->all(),
            'importacion' => ['iva_descontable' => (bool) $datos['iva_descontable']],
        ]);

        return back()->with('success', 'Los ajustes de ' . Paises::nombre($iso) . ' quedaron guardados.');
    }

    /** Vuelve a lo que trae el paquete. */
    public function restablecer(string $iso): RedirectResponse
    {
        abort_unless(Paises::existe($iso), 404);

        $todos = Paises::ajustes();
        unset($todos[strtoupper($iso)]);
        \App\Models\Configuracion::set('paises_ajustes', json_encode($todos, JSON_UNESCAPED_UNICODE));

        return back()->with('success', 'Se restableció lo que trae el paquete de ' . Paises::nombre($iso) . '.');
    }
}
