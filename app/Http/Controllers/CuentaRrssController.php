<?php

namespace App\Http\Controllers;

use App\Models\CuentaRrss;
use App\Services\Rrss\GoogleBusinessRrssService;
use App\Services\Rrss\LinkedinRrssService;
use App\Services\Rrss\MetaRrssService;
use App\Support\CredencialesRrss;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CuentaRrssController extends Controller
{
    public function index()
    {
        // Se le dice a la pantalla qué redes están listas para conectar y cuál
        // es la URL de retorno de cada una. Sin esto, el administrador tenía
        // que buscar ese dato en la documentación y pegarlo a mano en Meta —
        // y si lo escribía distinto, la conexión fallaba sin explicar por qué.
        $configuracion = [];

        foreach (CredencialesRrss::redesSociales() as $red) {
            $configuracion[$red] = [
                'lista'       => CredencialesRrss::lista($red),
                'faltantes'   => CredencialesRrss::faltantes($red),
                'url_retorno' => url("/rrss/cuentas/callback/{$red}"),
                // El secreto NO se devuelve nunca a la pantalla: solo si ya hay
                // uno guardado, para poder mostrar "configurado" sin exponerlo.
                'id_actual'     => CredencialesRrss::valor($red, 'id'),
                'tiene_secreto' => CredencialesRrss::valor($red, 'secret') !== '',
            ];
        }

        return Inertia::render('Rrss/Cuentas', [
            'cuentas' => CuentaRrss::orderBy('red')->orderBy('nombre_cuenta')->get([
                'id', 'red', 'nombre_cuenta', 'activa', 'ultimo_error',
                'ultima_publicacion_en', 'token_expira_en', 'created_at',
            ]),
            'configuracion' => $configuracion,
            // Qué hace el sistema cuando alguien escribe por Instagram o Facebook. Vive en
            // esta pantalla —y no en la bandeja— porque es configuración de la conexión, igual
            // que la automatización de WhatsApp vive en la pantalla de su conexión.
            'automatizacion' => \App\Services\Bandeja\BandejaAutomatizacionService::config(),
            'etapas' => \App\Models\CrmEtapa::where('activa', true)
                ->orderBy('orden')->get(['id', 'nombre']),
            'usuarios' => \App\Models\User::where('activo', true)
                ->orderBy('name')->get(['id', 'name']),
            'url_webhook' => url('/webhook/meta'),
            // Sin App Secret el webhook rechaza lo que no puede verificar, así que la pantalla
            // tiene que decirlo donde se carga el App Secret y no solo en la de WhatsApp.
            'webhook_listo' => CredencialesRrss::valor('meta', 'secret') !== ''
                && CredencialesRrss::valor('whatsapp', 'redirect') !== '',
        ]);
    }

    /**
     * Guarda qué hace el sistema cuando alguien escribe por Instagram o Facebook.
     *
     * Todo vive en `configuraciones`, así que no hace falta migración. Ver
     * `BandejaAutomatizacionService` para por qué contestar comentarios es un interruptor
     * aparte.
     */
    public function guardarAutomatizacion(Request $request)
    {
        $datos = $request->validate([
            'activo'        => 'boolean',
            'avisar'        => 'boolean',
            'responder'     => 'boolean',
            'comentarios'   => 'boolean',
            'crear_lead'    => 'boolean',
            'lead_etapa_id' => 'nullable|integer|exists:crm_etapas,id',
            'asignacion'    => 'required|in:fijo,round_robin',
            'responsables'  => 'nullable|array',
            'responsables.*' => 'integer|exists:users,id',
        ]);

        // Las reglas `boolean` no son `required`, así que una propiedad que no venga en el
        // cuerpo no llega en `$datos`. Leerla directo daba «Undefined array key» y un 500 en
        // vez de guardarla apagada, que es lo que significa que no venga.
        foreach ([
            'bandeja_auto_activo'      => 'activo',
            'bandeja_auto_avisar'      => 'avisar',
            'bandeja_auto_responder'   => 'responder',
            'bandeja_auto_comentarios' => 'comentarios',
            'bandeja_auto_crear_lead'  => 'crear_lead',
        ] as $clave => $campo) {
            \App\Models\Configuracion::set($clave, ! empty($datos[$campo]) ? '1' : '0');
        }

        \App\Models\Configuracion::set('bandeja_auto_lead_etapa_id', (string) ($datos['lead_etapa_id'] ?? ''));
        \App\Models\Configuracion::set('bandeja_auto_asignacion',  $datos['asignacion']);
        \App\Models\Configuracion::set('bandeja_auto_responsables', json_encode(array_values($datos['responsables'] ?? [])));

        return back()->with('success', 'Automatización de redes guardada.');
    }

    /**
     * Guarda las credenciales de una red desde la interfaz, sin tocar el .env.
     * El secreto solo se reemplaza si se envía uno nuevo: así se puede
     * corregir el App ID sin tener que volver a escribir el secreto.
     */
    public function guardarCredenciales(Request $request, string $red)
    {
        abort_unless(in_array($red, CredencialesRrss::redesSociales(), true), 404);

        $datos = $request->validate([
            'id'       => 'nullable|string|max:255',
            'secret'   => 'nullable|string|max:255',
            'redirect' => 'nullable|string|max:255',
        ]);

        CredencialesRrss::guardar($red, 'id', $datos['id'] ?? '');
        CredencialesRrss::guardar($red, 'redirect', $datos['redirect'] ?? '');

        if (filled($datos['secret'] ?? null)) {
            CredencialesRrss::guardar($red, 'secret', $datos['secret']);
        }

        return back()->with('success', 'Credenciales guardadas. Ya puedes conectar la cuenta.');
    }

    /**
     * Redirige al diálogo de autorización de la red elegida.
     * $red = meta | linkedin | google
     */
    public function conectar(string $red, MetaRrssService $meta, LinkedinRrssService $linkedin, GoogleBusinessRrssService $google)
    {
        if (! in_array($red, CredencialesRrss::redesSociales(), true)) {
            return back()->with('error', 'Red no soportada.');
        }

        // Sin credenciales no tiene sentido salir a la red: se avisa qué falta
        // en vez de mandar al usuario a una pantalla de error ajena.
        if ($faltantes = CredencialesRrss::faltantes($red)) {
            return back()->with('error',
                'Faltan credenciales para conectar esta red: ' . implode(', ', $faltantes)
                . '. Puedes cargarlas aquí mismo, en "¿Primera vez? Cómo dejar lista una red".');
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
