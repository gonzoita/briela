<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Services\CalificacionProveedorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración → Calificación de proveedores: cuánto pesa cada cosa en la nota.
 *
 * La nota la calcula el sistema (ver `CalificacionProveedorService`); lo único que la empresa
 * decide aquí es qué le importa más: llegar a tiempo, llegar completo, llegar con papel o ser
 * barato. Cambiarlo recalcula todas las notas al instante, porque no se guardan.
 */
class CalificacionProveedoresConfigController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Configuracion/CalificacionProveedores', [
            'ajustes'     => CalificacionProveedorService::ajustes(),
            'componentes' => collect(CalificacionProveedorService::COMPONENTES)
                ->map(fn ($c, $clave) => ['clave' => $clave, 'etiqueta' => $c['etiqueta'], 'defecto' => $c['peso']])
                ->values(),
            'defectos'    => [
                'gracia_dias'    => CalificacionProveedorService::GRACIA_DIAS,
                'muestra_minima' => CalificacionProveedorService::MUESTRA_MINIMA,
            ],
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $claves = array_keys(CalificacionProveedorService::COMPONENTES);

        $datos = $request->validate([
            'pesos'          => 'required|array',
            'pesos.*'        => 'required|integer|min:0|max:100',
            'gracia_dias'    => 'required|integer|min:0|max:30',
            'muestra_minima' => 'required|integer|min:1|max:50',
        ]);

        $pesos = collect($claves)->mapWithKeys(fn ($k) => [$k => (int) ($datos['pesos'][$k] ?? 0)]);

        if ($pesos->sum() <= 0) {
            return back()->withErrors(['pesos' => 'Al menos un componente tiene que pesar más de cero.']);
        }

        foreach ($pesos as $clave => $peso) {
            Configuracion::set("calificacion_peso_{$clave}", (string) $peso);
        }

        Configuracion::set('calificacion_gracia_dias', (string) $datos['gracia_dias']);
        Configuracion::set('calificacion_muestra_minima', (string) $datos['muestra_minima']);

        return back()->with('success', 'Calificación actualizada. Las notas de los proveedores ya usan estos pesos.');
    }
}
