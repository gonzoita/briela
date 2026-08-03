<?php

namespace App\Http\Controllers;

use App\Models\CuentaRrss;
use App\Services\Rrss\GoogleBusinessRrssService;
use App\Services\Rrss\LinkedinRrssService;
use App\Services\Rrss\MetaRrssService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CuentaRrssController extends Controller
{
    public function index()
    {
        return Inertia::render('Rrss/Cuentas', [
            'cuentas' => CuentaRrss::orderBy('red')->orderBy('nombre_cuenta')->get([
                'id', 'red', 'nombre_cuenta', 'activa', 'ultimo_error',
                'ultima_publicacion_en', 'token_expira_en', 'created_at',
            ]),
        ]);
    }

    /**
     * Credenciales que cada red necesita en el .env para poder conectarse.
     * Se revisan ANTES de armar la URL: sin ellas, el diálogo de la red
     * rechaza la petición (client_id vacío) y el usuario solo ve una página
     * de error sin explicación.
     */
    private const CREDENCIALES = [
        'meta'     => ['services.meta_rrss.app_id'           => 'META_APP_ID',
                       'services.meta_rrss.app_secret'       => 'META_APP_SECRET',
                       'services.meta_rrss.redirect_uri'     => 'META_REDIRECT_URI'],
        'linkedin' => ['services.linkedin_rrss.client_id'     => 'LINKEDIN_CLIENT_ID',
                       'services.linkedin_rrss.client_secret' => 'LINKEDIN_CLIENT_SECRET',
                       'services.linkedin_rrss.redirect_uri'  => 'LINKEDIN_REDIRECT_URI'],
        'google'   => ['services.google_business_rrss.client_id'     => 'GOOGLE_RRSS_CLIENT_ID',
                       'services.google_business_rrss.client_secret' => 'GOOGLE_RRSS_CLIENT_SECRET',
                       'services.google_business_rrss.redirect_uri'  => 'GOOGLE_RRSS_REDIRECT_URI'],
    ];

    /**
     * Redirige al diálogo de autorización de la red elegida.
     * $red = meta | linkedin | google
     */
    public function conectar(string $red, MetaRrssService $meta, LinkedinRrssService $linkedin, GoogleBusinessRrssService $google)
    {
        if (! isset(self::CREDENCIALES[$red])) {
            return back()->with('error', 'Red no soportada.');
        }

        // Sin credenciales no tiene sentido salir a la red: se avisa cuáles
        // faltan en vez de mandar al usuario a una pantalla de error ajena.
        $faltantes = [];
        foreach (self::CREDENCIALES[$red] as $clave => $variable) {
            if (blank(config($clave))) {
                $faltantes[] = $variable;
            }
        }

        if ($faltantes) {
            return back()->with('error',
                'Faltan credenciales en el .env del servidor para conectar esta red: '
                . implode(', ', $faltantes)
                . '. Ver docs/manual/redes-sociales.md.');
        }

        $url = match ($red) {
            'meta'     => $meta->urlAutorizacion(),
            'linkedin' => $linkedin->urlAutorizacion(),
            'google'   => $google->urlAutorizacion(),
        };

        // OJO: Inertia::location() y NO redirect()->away(). El botón llega acá
        // por una petición XHR de Inertia (router.visit); un 302 normal lo
        // seguiría el XHR, recibiría el HTML de Facebook/LinkedIn/Google en vez
        // de una respuesta Inertia, y la pantalla se quedaría muda sin hacer
        // nada. location() responde 409 + X-Inertia-Location para que el
        // navegador haga la navegación de verdad.
        return Inertia::location($url);
    }

    public function callback(
        Request $request,
        string $red,
        MetaRrssService $meta,
        LinkedinRrssService $linkedin,
        GoogleBusinessRrssService $google
    ) {
        if ($request->filled('error')) {
            return redirect()->route('rrss.cuentas.index')
                ->with('error', 'Autorización cancelada o rechazada: ' . $request->get('error_description', $request->error));
        }

        $code = $request->get('code');
        if (!$code) {
            return redirect()->route('rrss.cuentas.index')->with('error', 'No llegó el código de autorización.');
        }

        try {
            $cuentas = match ($red) {
                'meta'     => $meta->manejarCallback($code, auth()->id()),
                'linkedin' => $linkedin->manejarCallback($code, auth()->id()),
                'google'   => $google->manejarCallback($code, auth()->id()),
                default    => [],
            };
        } catch (\Throwable $e) {
            return redirect()->route('rrss.cuentas.index')->with('error', 'Error conectando la cuenta: ' . $e->getMessage());
        }

        $cantidad = count($cuentas);
        if ($cantidad === 0) {
            return redirect()->route('rrss.cuentas.index')
                ->with('error', 'Se autorizó pero no se encontró ninguna página/cuenta administrable.');
        }

        return redirect()->route('rrss.cuentas.index')
            ->with('success', "Se conectó correctamente: {$cantidad} cuenta(s).");
    }

    public function destroy(CuentaRrss $cuenta)
    {
        if ($cuenta->publicaciones()->exists()) {
            $cuenta->update(['activa' => false]);
            return back()->with('success', 'Cuenta desactivada (tiene publicaciones asociadas).');
        }

        $cuenta->delete();

        return back()->with('success', 'Cuenta desconectada.');
    }

    public function reactivar(CuentaRrss $cuenta)
    {
        $cuenta->update(['activa' => true]);

        return back()->with('success', 'Cuenta reactivada.');
    }
}
