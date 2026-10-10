<?php

namespace App\Services;

use App\Exceptions\WhatsAppApiException;
use App\Models\Archivo;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappNumero;
use App\Models\WhatsappPlantilla;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    /**
     * Envía un mensaje de texto por WhatsApp Cloud API desde el número dado.
     *
     * `$quien` es la persona que lo escribió desde la bandeja. Llega por parámetro y no de
     * `auth()` porque los mensajes que manda la automatización —el saludo, el agente de IA— se
     * disparan desde el webhook, donde no hay nadie autenticado: con `auth()` quedaban
     * atribuidos a nadie o, peor, al usuario de la petición que coincidiera.
     *
     * @throws \RuntimeException si falta configuración (token no definido)
     */
    public function enviarMensaje(WhatsappNumero $numero, string $numeroDestino, string $texto, ?User $quien = null): array
    {
        $data = $this->llamar($numero, $numeroDestino, [
            'type' => 'text',
            'text' => ['body' => $texto],
        ]);

        $conversacion = $this->conversacionPara($numero, $numeroDestino);

        $mensaje = $this->registrarSaliente($conversacion, [
            'wa_message_id' => $data['messages'][0]['id'] ?? null,
            'tipo'          => 'texto',
            'contenido'     => $texto,
        ], $quien);

        return [
            'mensaje' => $mensaje,
            'conversacion' => $conversacion,
            'respuesta' => $data,
        ];
    }

    /**
     * Manda un archivo, con un pie de foto opcional.
     *
     * El archivo se sube a Meta y se manda por identificador, no por enlace: ver
     * `WhatsappMediaService::subir()`. Y el pie solo va donde Meta lo admite —un `caption` en
     * un audio hace que rechace el mensaje entero—, así que cuando no cabe se manda aparte
     * como texto. Perder el comentario que acompaña a una foto es perder la mitad del mensaje.
     */
    public function enviarAdjunto(
        WhatsappNumero $numero,
        string $numeroDestino,
        Archivo $archivo,
        ?string $pie = null,
        ?User $quien = null,
    ): WhatsappMensaje {
        $media = app(WhatsappMediaService::class);
        $id    = $media->subir($numero, $archivo);

        if ($id === null) {
            throw new \RuntimeException('No se pudo subir el archivo a WhatsApp. Revisa la conexión y el tamaño del archivo.');
        }

        ['tipo' => $tipo, 'admite_pie' => $admitePie] = WhatsappMediaService::claseDeEnvio($archivo);

        $cuerpo = ['id' => $id];

        if ($admitePie && filled($pie)) {
            $cuerpo['caption'] = $pie;
        }

        if ($tipo === 'document') {
            // Sin esto el cliente recibe un archivo llamado con el identificador de Meta.
            $cuerpo['filename'] = $archivo->nombre_original;
        }

        $data = $this->llamar($numero, $numeroDestino, [
            'type' => $tipo,
            $tipo  => $cuerpo,
        ]);

        $conversacion = $this->conversacionPara($numero, $numeroDestino);

        // El pie que no cupo se manda como un mensaje aparte, antes de registrar el adjunto,
        // para que en el hilo se lea en el mismo orden en que llegó al cliente.
        if (! $admitePie && filled($pie)) {
            $this->enviarMensaje($numero, $numeroDestino, $pie, $quien);
        }

        return $this->registrarSaliente($conversacion, [
            'wa_message_id' => $data['messages'][0]['id'] ?? null,
            'tipo'          => WhatsappMediaService::tipoLocal($tipo),
            'contenido'     => $admitePie ? $pie : null,
            'archivo_id'    => $archivo->id,
        ], $quien);
    }

    /**
     * Manda una plantilla aprobada.
     *
     * **Es la única forma de escribir primero.** Fuera de la ventana de 24 horas Meta rechaza
     * el texto libre, así que todo aviso que sale del sistema sin que el cliente haya escrito
     * —una cotización lista, una orden despachada— tiene que salir por acá.
     *
     * @param  array<int, string>  $variables  en el orden de {{1}}, {{2}}...
     */
    public function enviarPlantilla(
        WhatsappNumero $numero,
        string $numeroDestino,
        WhatsappPlantilla $plantilla,
        array $variables = [],
        ?User $quien = null,
    ): WhatsappMensaje {
        if (! $plantilla->aprobada()) {
            throw new \RuntimeException("La plantilla «{$plantilla->nombre}» no está aprobada por Meta: no se puede enviar.");
        }

        $valores = array_values($variables);

        if (count($valores) < $plantilla->variables) {
            throw new \RuntimeException(
                "La plantilla «{$plantilla->nombre}» pide {$plantilla->variables} dato(s) y llegaron " . count($valores) . '.'
            );
        }

        $componentes = [];

        if ($plantilla->variables > 0) {
            $componentes[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    fn ($valor) => ['type' => 'text', 'text' => (string) $valor],
                    array_slice($valores, 0, $plantilla->variables),
                ),
            ];
        }

        $data = $this->llamar($numero, $numeroDestino, [
            'type'     => 'template',
            'template' => array_filter([
                'name'       => $plantilla->nombre,
                'language'   => ['code' => $plantilla->idioma],
                'components' => $componentes ?: null,
            ]),
        ]);

        $conversacion = $this->conversacionPara($numero, $numeroDestino);

        return $this->registrarSaliente($conversacion, [
            'wa_message_id' => $data['messages'][0]['id'] ?? null,
            'tipo'          => 'texto',
            // Se guarda el texto ya armado, no el nombre de la plantilla: dentro de un año nadie
            // va a saber qué decía «aviso_despacho_v2», y el hilo tiene que poder leerse.
            'contenido'     => $plantilla->previsualizar($valores),
            'plantilla'     => $plantilla->nombre,
        ], $quien);
    }

    // ─── Las dos piezas que comparten los tres envíos ────────────────────────

    /**
     * La llamada a Meta, con el token y los errores traducidos.
     *
     * Estaba escrita dentro de `enviarMensaje`, y al agregar adjuntos y plantillas habrían
     * quedado tres copias del mismo manejo de errores: tres sitios donde arreglar el día que
     * Meta cambie un código.
     *
     * @param  array<string, mixed>  $cuerpo  lo propio del tipo de mensaje
     * @return array<string, mixed>  la respuesta de Meta
     */
    private function llamar(WhatsappNumero $numero, string $numeroDestino, array $cuerpo): array
    {
        // El token se carga desde la pantalla de configuración (y si no hay,
        // cae al .env). Ver App\Support\CredencialesRrss.
        $token = \App\Support\CredencialesRrss::valor('whatsapp', 'secret');
        if (empty($token)) {
            throw new \RuntimeException(
                'WhatsApp no está conectado todavía: falta el token de acceso. '
                . 'Se carga en Configuración → Números de WhatsApp.'
            );
        }

        $version = config('services.whatsapp.api_version', 'v21.0');

        $response = Http::withToken($token)
            ->timeout(15)
            ->post("https://graph.facebook.com/{$version}/{$numero->phone_number_id}/messages", array_merge([
                'messaging_product' => 'whatsapp',
                'to' => $numeroDestino,
            ], $cuerpo));

        $data = $response->json() ?? [];

        if (!$response->successful()) {
            Log::error('WhatsApp: error al enviar mensaje', [
                'numero_id' => $numero->id,
                'destino' => $numeroDestino,
                'status' => $response->status(),
                'respuesta' => $data,
                'error_data_details' => $data['error']['error_data']['details'] ?? null,
            ]);

            $mensajeError = $data['error']['message'] ?? $response->body();
            $detalles = $data['error']['error_data']['details'] ?? null;
            if ($detalles) {
                $mensajeError .= " ({$detalles})";
            }

            throw new WhatsAppApiException(
                "Error al enviar mensaje de WhatsApp: " . $this->traducir((int) ($data['error']['code'] ?? 0), $mensajeError),
                $data
            );
        }

        return $data;
    }

    /**
     * Los errores de Meta que más confunden, dichos en términos de qué hacer.
     *
     * El código 131047 es el que más llamadas a soporte genera: el mensaje de Meta habla de
     * «re-engagement message» y lo que de verdad pasó es que se venció el plazo de 24 horas.
     */
    private function traducir(int $codigo, string $original): string
    {
        return match ($codigo) {
            131047 => 'pasaron más de 24 horas desde el último mensaje de esta persona, '
                . 'así que Meta solo permite escribirle con una plantilla aprobada.',
            131026 => 'ese número no tiene WhatsApp, o no puede recibir mensajes.',
            131030 => 'ese número no está en la lista de destinatarios permitidos de la cuenta de pruebas de Meta.',
            190    => 'el token de acceso venció o fue revocado. Hay que generar uno nuevo y permanente.',
            133010 => 'el número de la empresa no está registrado en la API. Revisa el identificador de la línea.',
            default => $original,
        };
    }

    /** La conversación de este contacto en esta línea. La crea si es la primera vez. */
    private function conversacionPara(WhatsappNumero $numero, string $numeroDestino): WhatsappConversacion
    {
        return WhatsappConversacion::firstOrCreate(
            [
                'whatsapp_numero_id' => $numero->id,
                'numero_contacto' => $numeroDestino,
            ],
            ['ultimo_mensaje_at' => now()]
        );
    }

    /**
     * Deja el mensaje guardado y mueve la última actividad de la conversación.
     *
     * **No toca `ultimo_entrante_at`**: la ventana de 24 horas la abre el cliente, no nosotros.
     * Antes esto era un `updateOrCreate` que escribía `ultimo_mensaje_at` a mano, y al agregar
     * la ventana habría sido muy fácil escribir la otra fecha ahí mismo y regalarnos un plazo
     * que Meta no dio. Por eso la escritura pasa por `registrarActividad()`.
     *
     * @param  array<string, mixed>  $datos
     */
    private function registrarSaliente(WhatsappConversacion $conversacion, array $datos, ?User $quien): WhatsappMensaje
    {
        $mensaje = WhatsappMensaje::create(array_merge([
            'whatsapp_conversacion_id' => $conversacion->id,
            'direccion'  => 'saliente',
            'estado'     => 'enviado',
            'usuario_id' => $quien?->id,
        ], $datos));

        $conversacion->registrarActividad('saliente');

        return $mensaje;
    }

    /**
     * Procesa el payload de un webhook entrante de Meta. Nunca debe lanzar
     * excepción: Meta reintenta el webhook si no recibe 200 rápido.
     */
    public function procesarWebhookEntrante(array $payload): void
    {
        try {
            foreach ($payload['entry'] ?? [] as $entry) {
                foreach ($entry['changes'] ?? [] as $change) {
                    $value = $change['value'] ?? [];

                    if (!empty($value['messages'])) {
                        $this->procesarMensajesEntrantes($value);
                    }

                    if (!empty($value['statuses'])) {
                        $this->procesarStatuses($value['statuses']);
                    }

                    if (!empty($value['message_echoes'])) {
                        $this->procesarMessageEchoes($value);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('WhatsApp: error al procesar webhook entrante', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }
    }

    private function procesarMensajesEntrantes(array $value): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        $numero = WhatsappNumero::where('phone_number_id', $phoneNumberId)->first();
        if (!$numero) {
            Log::error('WhatsApp: mensaje entrante para phone_number_id desconocido', ['phone_number_id' => $phoneNumberId]);
            return;
        }

        $contactos = collect($value['contacts'] ?? [])->keyBy('wa_id');

        foreach ($value['messages'] as $msg) {
            $numeroContacto = $msg['from'] ?? null;
            if (!$numeroContacto) continue;

            $nombreContacto = $contactos->get($numeroContacto)['profile']['name'] ?? null;

            // Se mira ANTES de guardar: si la conversación no existía, este es
            // un primer contacto, y de eso depende si se saluda y si se crea
            // el lead. Después del updateOrCreate ya no habría cómo saberlo.
            $esNueva = ! WhatsappConversacion::where('whatsapp_numero_id', $numero->id)
                ->where('numero_contacto', $numeroContacto)
                ->exists();

            $conversacion = WhatsappConversacion::updateOrCreate(
                [
                    'whatsapp_numero_id' => $numero->id,
                    'numero_contacto' => $numeroContacto,
                ],
                ['nombre_contacto' => $nombreContacto]
            );

            // Un webhook repetido es normal: Meta reintenta cuando no recibe el 200 a tiempo.
            // Sin esta guarda, el mismo mensaje entraba dos veces al hilo y la automatización
            // contestaba dos veces.
            //
            // **Va antes de marcar actividad, y el orden importa.** Al revés, un reintento de
            // Meta volvía a poner como «sin leer» una conversación ya atendida, la sacaba del
            // archivo y corría `ultimo_entrante_at` hacia adelante — estirando la ventana de
            // 24 horas más allá del último mensaje real de la persona.
            if (! empty($msg['id']) && WhatsappMensaje::where('wa_message_id', $msg['id'])->exists()) {
                continue;
            }

            // Las fechas y el «sin leer» los pone un solo sitio, que además es el que sostiene
            // la ventana de 24 horas. Ver `EsConversacion::registrarActividad()`.
            $conversacion->registrarActividad('entrante');

            $tipoMeta = (string) ($msg['type'] ?? 'text');
            $texto    = $this->textoDelEntrante($msg, $tipoMeta);
            $archivo  = $this->archivoDelEntrante($msg, $tipoMeta, $conversacion);

            WhatsappMensaje::create([
                'whatsapp_conversacion_id' => $conversacion->id,
                'wa_message_id' => $msg['id'] ?? null,
                'direccion' => 'entrante',
                'tipo' => WhatsappMediaService::esTipoConArchivo($tipoMeta)
                    ? WhatsappMediaService::tipoLocal($tipoMeta)
                    : 'texto',
                'contenido' => $texto,
                'archivo_id' => $archivo?->id,
            ]);

            // La automatización va aparte y no puede tumbar el webhook: si
            // falla, el mensaje igual quedó guardado y Meta recibe su 200.
            try {
                app(WhatsappAutomatizacionService::class)
                    ->alRecibirMensaje($numero, $conversacion, (string) $texto, $esNueva);
            } catch (\Throwable $e) {
                Log::error('WhatsApp: falló la automatización del mensaje entrante', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * El texto de un mensaje entrante, cualquiera que sea su tipo.
     *
     * Antes solo se leía `text.body`, así que una foto, una nota de voz, una ubicación o un
     * toque a un botón entraban con el contenido **vacío**: en la bandeja se veía un renglón en
     * blanco y nadie sabía si el cliente había escrito algo o si el sistema se había roto.
     *
     * Lo que no es texto se describe en una línea, y el archivo va aparte en `archivo_id`.
     */
    private function textoDelEntrante(array $msg, string $tipo): ?string
    {
        return match ($tipo) {
            'text'     => $msg['text']['body'] ?? null,
            // Un pie de foto es lo que la persona escribió: vale más que cualquier descripción.
            'image'    => $msg['image']['caption'] ?? '📷 Foto',
            'video'    => $msg['video']['caption'] ?? '🎥 Video',
            'document' => $msg['document']['caption']
                ?? ('📄 ' . ($msg['document']['filename'] ?? 'Documento')),
            'audio'    => ($msg['audio']['voice'] ?? false) ? '🎤 Nota de voz' : '🎵 Audio',
            'sticker'  => '😀 Sticker',
            'location' => $this->textoDeUbicacion($msg['location'] ?? []),
            'contacts' => '👤 Contacto compartido: ' . collect($msg['contacts'] ?? [])
                ->pluck('name.formatted_name')->filter()->implode(', '),
            // Un botón o una opción de lista: lo que importa es lo que eligió, y la
            // automatización tiene que poder compararlo como si lo hubiera escrito.
            'button'      => $msg['button']['text'] ?? null,
            'interactive' => $msg['interactive']['button_reply']['title']
                ?? $msg['interactive']['list_reply']['title']
                ?? null,
            // Un tipo que todavía no sabemos leer no puede quedar en blanco: que diga qué era.
            default    => '[' . $tipo . ']',
        };
    }

    /**
     * La ubicación, con su enlace al mapa.
     *
     * Un par de coordenadas sueltas no le sirve a nadie en la bandeja; el enlace sí, porque se
     * toca y abre el mapa.
     */
    private function textoDeUbicacion(array $ubicacion): string
    {
        $lat = $ubicacion['latitude'] ?? null;
        $lon = $ubicacion['longitude'] ?? null;

        if ($lat === null || $lon === null) {
            return '📍 Ubicación';
        }

        $nombre = trim(($ubicacion['name'] ?? '') . ' ' . ($ubicacion['address'] ?? ''));

        return '📍 Ubicación' . ($nombre !== '' ? ": {$nombre}" : '')
            . " — https://maps.google.com/?q={$lat},{$lon}";
    }

    /**
     * Baja el archivo de un mensaje entrante, si trae.
     *
     * Falla en silencio a propósito: el mensaje ya se va a guardar con su tipo y su
     * descripción, y dejar de registrar que alguien escribió porque no se pudo bajar su foto
     * sería cambiar un problema pequeño por uno grave.
     */
    private function archivoDelEntrante(array $msg, string $tipo, WhatsappConversacion $conversacion): ?\App\Models\Archivo
    {
        if (! WhatsappMediaService::esTipoConArchivo($tipo)) {
            return null;
        }

        $mediaId = $msg[$tipo]['id'] ?? null;

        if (! $mediaId) {
            return null;
        }

        return app(WhatsappMediaService::class)->descargar(
            (string) $mediaId,
            $conversacion,
            $msg[$tipo]['filename'] ?? null,
        );
    }

    private function procesarStatuses(array $statuses): void
    {
        foreach ($statuses as $status) {
            $waMessageId = $status['id'] ?? null;
            if (!$waMessageId) continue;

            WhatsappMensaje::where('wa_message_id', $waMessageId)
                ->update(['estado' => $status['status'] ?? null]);
        }
    }

    /**
     * Coexistencia: mensajes enviados por el asesor desde su propio celular
     * (no desde Briela). Mantiene el historial sincronizado.
     */
    private function procesarMessageEchoes(array $value): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        $numero = WhatsappNumero::where('phone_number_id', $phoneNumberId)->first();
        if (!$numero) {
            Log::error('WhatsApp: message_echo para phone_number_id desconocido', ['phone_number_id' => $phoneNumberId]);
            return;
        }

        foreach ($value['message_echoes'] as $echo) {
            $numeroContacto = $echo['to'] ?? null;
            if (!$numeroContacto) continue;

            $conversacion = $this->conversacionPara($numero, $numeroContacto);

            // Lo que mandamos nosotros desde la bandeja vuelve también como eco. Sin esta
            // guarda, cada respuesta aparecía dos veces en el hilo.
            if (! empty($echo['id']) && WhatsappMensaje::where('wa_message_id', $echo['id'])->exists()) {
                continue;
            }

            $tipoMeta = (string) ($echo['type'] ?? 'text');

            WhatsappMensaje::create([
                'whatsapp_conversacion_id' => $conversacion->id,
                'wa_message_id' => $echo['id'] ?? null,
                'direccion' => 'saliente',
                'tipo' => WhatsappMediaService::esTipoConArchivo($tipoMeta)
                    ? WhatsappMediaService::tipoLocal($tipoMeta)
                    : 'texto',
                'contenido' => $this->textoDelEntrante($echo, $tipoMeta),
                'archivo_id' => $this->archivoDelEntrante($echo, $tipoMeta, $conversacion)?->id,
                'estado' => 'enviado',
                'es_echo' => true,
            ]);

            // Un eco es un mensaje nuestro: mueve la última actividad y nada más. Escribir
            // `ultimo_entrante_at` aquí abriría una ventana de 24 horas que Meta no dio.
            $conversacion->registrarActividad('saliente');
        }
    }

    public function verificarWebhook(string $mode, string $token, string $challenge): ?string
    {
        $verifyToken = \App\Support\CredencialesRrss::valor('whatsapp', 'redirect');

        if ($mode === 'subscribe' && $verifyToken && hash_equals($verifyToken, $token)) {
            return $challenge;
        }

        return null;
    }
}
