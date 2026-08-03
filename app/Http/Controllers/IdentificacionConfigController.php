<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Services\ConsultaNitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración de la consulta de identificación de clientes.
 *
 * Solo controla la parte del RUES, que es la única que depende de un servicio
 * externo. El dígito de verificación y la detección de duplicados son código
 * nuestro: no se apagan ni se configuran porque no tiene sentido hacerlo.
 */
class IdentificacionConfigController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Configuracion/Identificacion', [
            'rues' => [
                // Igual que la IA: manda lo guardado en la app; si está vacío,
                // se usa lo del .env.
                'activo'  => (bool) Configuracion::get('rues_activo', config('services.rues.activo')),
                'url'     => Configuracion::get('rues_url', '') ?: config('services.rues.url'),
                'token'   => Configuracion::get('rues_token', '') ?: config('services.rues.token', ''),
                'timeout' => (int) (Configuracion::get('rues_timeout', 0) ?: config('services.rues.timeout', 6)),
            ],
            'url_por_defecto' => config('services.rues.url'),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'activo'  => 'required|boolean',
            'url'     => 'required|url|max:255',
            'token'   => 'nullable|string|max:100',
            'timeout' => 'required|integer|min:2|max:30',
        ]);

        Configuracion::set('rues_activo', $data['activo'] ? '1' : '0');
        Configuracion::set('rues_url', $data['url']);
        Configuracion::set('rues_token', $data['token'] ?? '');
        Configuracion::set('rues_timeout', (string) $data['timeout']);

        // Si cambió la dirección, lo que hay en caché ya no sirve.
        $this->olvidarCache();

        return back()->with('success', 'Configuración guardada.');
    }

    /**
     * Consulta un NIT de verdad y devuelve lo que pasó, sin adornos.
     *
     * La idea es que se pueda diagnosticar desde la app en vez de tener que
     * entrar por SSH a leer los logs.
     */
    public function probar(Request $request, ConsultaNitService $consulta)
    {
        $data = $request->validate([
            'nit' => 'required|string|max:30',
        ]);

        // Sin caché: probar tiene que consultar de verdad, si no no sirve.
        $this->olvidarCache();

        $inicio    = microtime(true);
        $resultado = $consulta->consultar($data['nit'], 'NIT');
        $ms        = (int) ((microtime(true) - $inicio) * 1000);

        return response()->json([
            'ok'          => $resultado['rues'] !== null,
            'milisegundos'=> $ms,
            'dv'          => $resultado['dv'],
            'rues'        => $resultado['rues'],
            'mensaje'     => $resultado['rues']
                ? 'El RUES respondió correctamente.'
                : 'El RUES no devolvió datos para ese NIT. Puede ser que el NIT no exista, o que el servicio no esté respondiendo.',
        ]);
    }

    /**
     * Invalida la caché del RUES.
     *
     * No hay forma limpia de borrar por prefijo en todos los drivers de caché,
     * así que subimos la versión que va dentro de la llave: lo viejo queda
     * huérfano y expira solo a los 30 días.
     */
    private function olvidarCache(): void
    {
        Configuracion::set('rues_cache_version', (string) time());
    }
}
