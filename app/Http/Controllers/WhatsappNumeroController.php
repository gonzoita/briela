<?php

namespace App\Http\Controllers;

use App\Models\WhatsappNumero;
use App\Support\CredencialesRrss;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class WhatsappNumeroController extends Controller
{
    public function index()
    {
        return Inertia::render('Configuracion/WhatsappNumeros', [
            'numeros' => WhatsappNumero::with('usuario:id,name')
                ->orderByDesc('rol')
                ->orderBy('nombre')
                ->get(),
            'usuarios' => \App\Models\User::orderBy('name')->get(['id', 'name']),
            // Mismo trato que las redes sociales: la pantalla dice si la
            // conexión está lista y da los datos que hay que pegar en Meta.
            'conexion' => [
                'lista'          => CredencialesRrss::lista('whatsapp'),
                'faltantes'      => CredencialesRrss::faltantes('whatsapp'),
                'id_actual'      => CredencialesRrss::valor('whatsapp', 'id'),
                'tiene_secreto'  => CredencialesRrss::valor('whatsapp', 'secret') !== '',
                'verify_actual'  => CredencialesRrss::valor('whatsapp', 'redirect'),
                'url_webhook'    => url('/webhook/whatsapp'),
            ],
            'automatizacion' => \App\Services\WhatsappAutomatizacionService::config(),
            'etapas'         => \App\Models\CrmEtapa::where('activa', true)
                ->orderBy('orden')->get(['id', 'nombre']),
        ]);
    }

    /**
     * Guarda la automatización: aviso, respuestas y creación de leads.
     * Todo vive en `configuraciones`, así que no hace falta migración.
     */
    public function guardarAutomatizacion(Request $request)
    {
        $datos = $request->validate([
            'activo'                    => 'boolean',
            'avisar'                    => 'boolean',
            'responder'                 => 'boolean',
            'respuestas'                => 'nullable|array|max:20',
            'respuestas.*.palabra_clave'=> 'nullable|string|max:60',
            'respuestas.*.mensaje'      => 'nullable|string|max:1000',
            'crear_lead'                => 'boolean',
            'lead_etapa_id'             => 'nullable|integer|exists:crm_etapas,id',
            'asignacion'                => 'required|in:fijo,round_robin',
            'responsables'              => 'nullable|array',
            'responsables.*'            => 'integer|exists:users,id',
        ]);

        // Se descartan las respuestas vacías para que no queden filas fantasma
        // que el motor tendría que saltarse en cada mensaje.
        $respuestas = collect($datos['respuestas'] ?? [])
            ->filter(fn ($r) => filled($r['mensaje'] ?? null))
            ->map(fn ($r) => [
                'palabra_clave' => trim((string) ($r['palabra_clave'] ?? '')),
                'mensaje'       => trim((string) $r['mensaje']),
            ])
            ->values()
            ->all();

        \App\Models\Configuracion::set('whatsapp_auto_activo',       !empty($datos['activo']) ? '1' : '0');
        \App\Models\Configuracion::set('whatsapp_auto_avisar',       !empty($datos['avisar']) ? '1' : '0');
        \App\Models\Configuracion::set('whatsapp_auto_responder',    !empty($datos['responder']) ? '1' : '0');
        \App\Models\Configuracion::set('whatsapp_auto_crear_lead',   !empty($datos['crear_lead']) ? '1' : '0');
        \App\Models\Configuracion::set('whatsapp_auto_lead_etapa_id', (string) ($datos['lead_etapa_id'] ?? ''));
        \App\Models\Configuracion::set('whatsapp_auto_asignacion',   $datos['asignacion']);
        \App\Models\Configuracion::set('whatsapp_auto_respuestas',   json_encode($respuestas));
        \App\Models\Configuracion::set('whatsapp_auto_responsables', json_encode(array_values($datos['responsables'] ?? [])));

        return back()->with('success', 'Automatización guardada.');
    }

    /**
     * Guarda las credenciales de WhatsApp desde la interfaz, sin tocar el .env.
     * El token solo se reemplaza si se envía uno nuevo.
     */
    public function guardarCredenciales(Request $request)
    {
        $datos = $request->validate([
            'id'       => 'nullable|string|max:255',
            'secret'   => 'nullable|string|max:500',
            'redirect' => 'nullable|string|max:255',
        ]);

        CredencialesRrss::guardar('whatsapp', 'id', $datos['id'] ?? '');
        CredencialesRrss::guardar('whatsapp', 'redirect', $datos['redirect'] ?? '');

        if (filled($datos['secret'] ?? null)) {
            CredencialesRrss::guardar('whatsapp', 'secret', $datos['secret']);
        }

        return back()->with('success', 'Conexión de WhatsApp guardada.');
    }

    /**
     * Comprueba contra Meta que el token y el número sirven de verdad.
     * Sin esto solo se sabe si algo está mal cuando falla un envío real.
     */
    public function probarConexion()
    {
        $token   = CredencialesRrss::valor('whatsapp', 'secret');
        $phoneId = CredencialesRrss::valor('whatsapp', 'id');

        if ($token === '' || $phoneId === '') {
            return back()->with('error', 'Faltan datos: carga primero el identificador del número y el token.');
        }

        try {
            $resp = Http::withToken($token)->timeout(15)
                ->get("https://graph.facebook.com/v21.0/{$phoneId}", [
                    'fields' => 'display_phone_number,verified_name',
                ]);
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo contactar a Meta: ' . $e->getMessage());
        }

        if (! $resp->successful()) {
            $detalle = $resp->json('error.message') ?? $resp->body();
            return back()->with('error', 'Meta rechazó la conexión: ' . $detalle);
        }

        $nombre  = $resp->json('verified_name') ?? '';
        $numero  = $resp->json('display_phone_number') ?? '';

        return back()->with('success', trim("Conexión correcta con {$nombre} {$numero}") . '.');
    }

    /**
     * Desconecta borrando las credenciales guardadas. Los números y sus
     * conversaciones NO se tocan: al volver a conectar, todo sigue ahí.
     */
    public function desconectar()
    {
        foreach (['id', 'secret', 'redirect'] as $campo) {
            CredencialesRrss::guardar('whatsapp', $campo, '');
        }

        return back()->with('success',
            'WhatsApp desconectado. Los números y el historial de conversaciones se conservan; '
            . 'al volver a cargar el token, la conexión queda activa otra vez.');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'numero_telefono' => 'required|string|max:30',
            'phone_number_id' => 'required|string|max:50|unique:whatsapp_numeros,phone_number_id',
            'rol' => 'required|in:central,asesor',
            'usuario_id' => 'nullable|exists:users,id',
            'activo' => 'boolean',
        ]);

        WhatsappNumero::create($data);

        return redirect()->back()->with('success', 'Número de WhatsApp creado.');
    }

    public function update(Request $request, WhatsappNumero $whatsappNumero)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'numero_telefono' => 'required|string|max:30',
            'phone_number_id' => 'required|string|max:50|unique:whatsapp_numeros,phone_number_id,' . $whatsappNumero->id,
            'rol' => 'required|in:central,asesor',
            'usuario_id' => 'nullable|exists:users,id',
            'activo' => 'boolean',
        ]);

        $whatsappNumero->update($data);

        return redirect()->back()->with('success', 'Número de WhatsApp actualizado.');
    }

    public function destroy(WhatsappNumero $whatsappNumero)
    {
        if ($whatsappNumero->conversaciones()->exists()) {
            $whatsappNumero->update(['activo' => false]);
            return redirect()->back()->with('success', 'Número desactivado (tiene conversaciones asociadas).');
        }

        $whatsappNumero->delete();
        return redirect()->back()->with('success', 'Número eliminado.');
    }
}
