<?php

namespace App\Http\Controllers;

use App\Exceptions\RrssApiException;
use App\Models\CuentaRrss;
use App\Services\Meta\EmbeddedSignupService;
use App\Services\Meta\SuscripcionesMeta;
use App\Support\ConexionMeta;
use App\Support\CredencialesRrss;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Los dos extremos de la conexión con Meta que el botón del navegador no puede hacer solo:
 * canjear el código de Embedded Signup y volver a suscribir los webhooks.
 *
 * Responde JSON porque lo llama el SDK de Facebook desde una ventana emergente, no un formulario
 * de Inertia: la pantalla pinta el resultado sin recargarse y recarga después, con los números
 * ya conectados a la vista.
 */
class MetaConexionController extends Controller
{
    /**
     * Recibe lo que entregó Embedded Signup y termina la vinculación de WhatsApp.
     *
     * El `code` vence a los pocos minutos y solo sirve una vez; por eso no se reintenta aquí
     * ante un fallo: la persona vuelve a oprimir el botón y Meta da uno nuevo.
     */
    public function embeddedSignup(Request $peticion, EmbeddedSignupService $servicio): JsonResponse
    {
        $datos = $peticion->validate([
            'code'            => 'required|string|max:2048',
            'waba_id'         => 'nullable|string|regex:/^\d{5,30}$/',
            'phone_number_id' => 'nullable|string|regex:/^\d{5,30}$/',
            'coexistencia'    => 'boolean',
        ]);

        if (! ConexionMeta::embeddedListo()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Falta el identificador de la aplicación, su clave secreta o el identificador de la configuración de Embedded Signup.',
            ], 422);
        }

        try {
            $resultado = $servicio->conectar(
                $datos['code'],
                $datos['waba_id'] ?? null,
                $datos['phone_number_id'] ?? null,
                (bool) ($datos['coexistencia'] ?? false),
            );
        } catch (RrssApiException $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        $cuantos = count($resultado['numeros']);

        return response()->json([
            'ok'      => true,
            'mensaje' => $cuantos > 0
                ? 'WhatsApp conectado: ' . implode(', ', $resultado['numeros']) . '.'
                : 'WhatsApp conectado.',
            'avisos'  => $resultado['avisos'],
        ]);
    }

    /**
     * Vuelve a suscribir todo: las páginas de Facebook, la cuenta de WhatsApp y la dirección del
     * webhook en la aplicación. Para cuando una conexión «salió bien» y no llega nada, que casi
     * siempre es una suscripción que falló en silencio.
     */
    public function suscribir(SuscripcionesMeta $suscripciones): JsonResponse
    {
        $resumen = [];

        foreach (CuentaRrss::where('red', 'facebook')->where('activa', true)->get() as $pagina) {
            $resumen[] = ($suscripciones->suscribirPagina($pagina) ? 'Suscrita' : 'Falló')
                . ': ' . $pagina->nombre_cuenta;
        }

        $waba = CredencialesRrss::valor('whatsapp', 'waba');
        $token = CredencialesRrss::valor('whatsapp', 'secret');

        if ($waba !== '' && $token !== '') {
            $r = $suscripciones->suscribirWaba($waba, $token);
            $resumen[] = ($r['ok'] ? 'Suscrita' : 'Falló') . ': cuenta de WhatsApp' . ($r['ok'] ? '' : " ({$r['mensaje']})");
        }

        $todoBien = true;

        foreach ($suscripciones->registrarWebhookDeLaApp() as $objeto => $r) {
            $todoBien = $todoBien && $r['ok'];
            $resumen[] = ($r['ok'] ? 'Registrado' : 'Falló') . ": webhook de {$objeto}" . ($r['ok'] ? '' : " ({$r['mensaje']})");
        }

        $fallos = count(array_filter($resumen, fn ($l) => str_starts_with($l, 'Falló')));

        return response()->json([
            'ok'      => $fallos === 0 && $todoBien,
            'mensaje' => $fallos === 0 ? 'Todo quedó suscrito.' : "{$fallos} suscripción(es) fallaron.",
            'detalle' => $resumen,
        ]);
    }
}
