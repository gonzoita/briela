<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrmEtapa;
use App\Models\CrmLead;
use App\Models\Sede;
use App\Services\NotificacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Punto de entrada del plugin de WordPress "Briela Connect".
 *
 * Protegido por el middleware `integracion.wordpress` (token opaco por
 * instalación, ver VerificarTokenIntegracion) — no por Sanctum ni por la
 * Fase 2 de licenciamiento. Ver docs/plugin-wordpress-contexto.md.
 *
 * Nunca confía en el Accept header del llamador: el plugin es PHP puro
 * hablando con wp_remote_post, así que todas las respuestas se arman a
 * mano en JSON.
 */
class WordpressIntegracionController extends Controller
{
    /**
     * El catálogo que el cliente marcó para publicar en su sitio.
     *
     * El plugin llama esto por cron y cuando el ERP le avisa que hubo un cambio. Devuelve
     * el estado completo, no un diario de cambios: es un catálogo de decenas o cientos de
     * fichas, cabe en una respuesta, y una lista completa no se puede desincronizar. Con
     * eventos incrementales, un aviso perdido deja el sitio mintiendo para siempre.
     *
     * Un ensamble viaja como un producto más, con `precio_es_desde` en verdadero.
     */
    public function catalogo(Request $request): JsonResponse
    {
        $servicio = app(\App\Services\PublicacionWebService::class);

        // El plugin se identifica de vuelta para que el ERP pueda avisarle cuando algo
        // cambie. Así la URL del sitio no se escribe a mano en dos lados.
        $servicio->recordarSitio($request->header('X-Briela-Sitio'));
        $servicio->registrarLectura();

        $items = $servicio->catalogo();

        return response()->json([
            'ok'        => true,
            'generado'  => now()->toIso8601String(),
            'moneda'    => 'COP',
            'total'     => count($items),
            'catalogo'  => $items,
        ]);
    }

    /**
     * Crea un CrmLead a partir de un formulario del sitio del cliente, con
     * la atribución UTM capturada por el plugin al aterrizar.
     */
    public function leads(Request $request): JsonResponse
    {
        $validador = Validator::make($request->all(), [
            'nombre'        => 'required|string|max:200',
            'telefono'      => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:150',
            'mensaje'       => 'nullable|string|max:5000',
            'empresa'       => 'nullable|string|max:200',
            'pagina_origen' => 'nullable|string|max:500',
            'fuente'        => 'nullable|string|max:100',
            'utm_source'    => 'nullable|string|max:150',
            'utm_medium'    => 'nullable|string|max:150',
            'utm_campaign'  => 'nullable|string|max:150',
        ]);

        if ($validador->fails()) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Datos incompletos.',
                'errores' => $validador->errors(),
            ], 422);
        }

        $data = $validador->validated();

        // Sin usuario autenticado no hay sede activa en sesión (ContextoSede
        // depende de auth()->user()): el lead se asigna a la sede principal,
        // igual que se hizo al retroalimentar los datos existentes cuando se
        // agregó sede_id a crm_leads.
        $sedeId  = Sede::principal()?->id;
        $etapaId = CrmEtapa::where('activa', true)->orderBy('orden')->value('id');

        if (! $etapaId) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'No hay etapas de CRM configuradas en este ERP.',
            ], 422);
        }

        $orden = CrmLead::where('etapa_id', $etapaId)->max('orden_en_etapa') + 1;

        // Pasa por la puerta única: si esa persona ya estaba en el embudo, se le
        // suma el origen en vez de crear un lead repetido.
        $resultado = app(\App\Services\LeadEntranteService::class)->registrar([
            'canal'        => 'web',
            'detalle'      => filled($data['fuente'] ?? null) ? $data['fuente'] : 'Sitio web',
            'nombre'       => $data['nombre'] ?? null,
            'email'        => $data['email'] ?? null,
            'telefono'     => $data['telefono'] ?? null,
            'empresa'      => $data['empresa'] ?? null,
            'mensaje'      => $data['mensaje'] ?? null,
            'pagina'       => $data['pagina_origen'] ?? null,
            'utm_source'   => $data['utm_source'] ?? null,
            'utm_medium'   => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'sede_id'      => $sedeId,
            'etapa_id'     => $etapaId,
            // El aviso lo manda este controlador con su propio texto.
            'avisar'       => false,
        ]);

        $lead = $resultado['lead'];

        // Sin responsable asignado (nadie lo eligió todavía): avisa a los
        // administradores, igual que hace el formulario público del CRM.
        app(NotificacionService::class)->paraRol(
            ['administrador'],
            'lead_nuevo',
            'Nuevo lead desde el sitio web',
            $lead->titulo,
            '/crm',
        );

        return response()->json(['ok' => true, 'lead_id' => $lead->id], 201);
    }
}
