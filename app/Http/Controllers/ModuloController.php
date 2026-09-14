<?php

namespace App\Http\Controllers;

use App\Models\RegistroActividad;
use App\Support\Modulos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración → Módulos: encender y apagar lo que la empresa usa.
 *
 * El cambio es inmediato aquí y llega al panel de Briela en el siguiente latido; si allá se
 * cambió después, gana lo de allá. Ver `App\Support\Modulos`.
 */
class ModuloController extends Controller
{
    public function index(): Response
    {
        $estado = Modulos::estado();

        return Inertia::render('Configuracion/Modulos', [
            'modulos'     => Modulos::paraMostrar(),
            'cambiado_at' => $estado['cambiado_at'],
            'origen'      => $estado['origen'],
            'puedeEditar' => (bool) request()->user()?->tienePermiso('configuracion.editar'),
        ]);
    }

    /**
     * Enciende o apaga uno. Las dependencias se arrastran aquí, no en la pantalla: la pantalla
     * las anuncia, pero la regla vive en un solo sitio.
     */
    public function cambiar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'clave'  => ['required', 'string', Rule::in(array_keys(Modulos::catalogo()))],
            'activo' => ['required', 'boolean'],
        ]);

        $clave    = $datos['clave'];
        $apagados = Modulos::estado()['apagados'];
        $label    = Modulos::catalogo()[$clave]['label'];

        if ($datos['activo']) {
            // Encender también enciende aquello de lo que depende, o seguiría apagado por arrastre.
            $encender = array_merge([$clave], Modulos::requeridosPor($clave));
            $apagados = array_values(array_diff($apagados, $encender));
            $mensaje  = "Módulo «{$label}» encendido.";
        } else {
            $apagados[] = $clave;
            $arrastra   = collect(Modulos::dependientesDe($clave))
                ->filter(fn ($c) => Modulos::activo($c))
                ->map(fn ($c) => Modulos::catalogo()[$c]['label']);

            $mensaje = "Módulo «{$label}» apagado."
                . ($arrastra->isNotEmpty() ? ' También quedaron apagados: ' . $arrastra->join(', ', ' y ') . '.' : '');
        }

        Modulos::guardar($apagados, 'instalacion');

        RegistroActividad::registrar(
            $datos['activo'] ? 'modulo_encendido' : 'modulo_apagado',
            'Modulo',
            null,
            $mensaje,
            ['modulo' => $clave, 'apagados' => Modulos::apagados()],
        );

        return back()->with('success', $mensaje);
    }
}
