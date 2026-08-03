<?php

namespace App\Services\IA;

use App\Exceptions\IaException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Punto único de contacto con la IA, a través de OpenRouter.
 *
 * OpenRouter es una pasarela: con UNA sola credencial y UN solo saldo se puede
 * usar Claude para texto y modelos de imagen, sin abrir cuenta en cada
 * proveedor. Su API es compatible con la de OpenAI.
 *
 * Requiere en .env: OPENROUTER_API_KEY.
 * Sin credencial el módulo queda apagado: lanza un error claro y el resto del
 * sistema sigue funcionando igual.
 */
class IaService
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * La configuración se lee primero de Ajustes (base de datos) y, si no está
     * ahí, del .env. Así se puede cambiar la credencial o el modelo desde la
     * app sin entrar al servidor — igual que ya se hace con el SMTP.
     */
    private function ajuste(string $clave, string $env, string $porDefecto = ''): string
    {
        $enBd = \App\Models\Configuracion::get($clave, '');

        return $enBd !== '' && $enBd !== null
            ? (string) $enBd
            : (string) (config($env) ?: $porDefecto);
    }

    public function apiKey(): string
    {
        return $this->ajuste('ia_api_key', 'services.ia.api_key');
    }

    public function modeloTexto(): string
    {
        return $this->ajuste('ia_modelo_texto', 'services.ia.modelo_texto', 'anthropic/claude-sonnet-5');
    }

    /**
     * Modelo para tareas internas y rápidas (decidir qué consulta usar), donde
     * no importa la calidad de redacción sino la velocidad.
     *
     * Si no se configura uno, se usa el mismo de redacción.
     */
    public function modeloRapido(): string
    {
        $configurado = $this->ajuste('ia_modelo_rapido', 'services.ia.modelo_rapido', '');

        return $configurado !== '' ? $configurado : $this->modeloTexto();
    }

    public function modeloImagen(): string
    {
        return $this->ajuste('ia_modelo_imagen', 'services.ia.modelo_imagen', 'openai/gpt-image-2');
    }

    public function configurado(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Pide un texto a la IA.
     *
     * @param  string  $prompt         Lo que se le pide.
     * @param  string  $instrucciones  Rol y reglas de redacción.
     * @param  int     $maxTokens      Techo de la respuesta (controla el costo).
     */
    /**
     * @param  bool  $rapido  usa el modelo rápido en vez del de redacción
     */
    /** Milisegundos que tardó la última llamada. Sirve para diagnosticar. */
    public int $ultimaDuracionMs = 0;

    public function texto(string $prompt, string $instrucciones = '', int $maxTokens = 700, bool $rapido = false): string
    {
        $mensajes = [];

        if ($instrucciones !== '') {
            $mensajes[] = ['role' => 'system', 'content' => $instrucciones];
        }

        $mensajes[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model'      => $rapido ? $this->modeloRapido() : $this->modeloTexto(),
            'messages'   => $mensajes,
            'max_tokens' => $maxTokens,
        ];

        // Sin "modo pensamiento" en ninguna de las dos llamadas.
        //
        // No es solo por velocidad. Los tokens de razonamiento se descuentan
        // del mismo max_tokens que la respuesta: un modelo razonador como Kimi
        // K2.6 se gastaba el presupuesto pensando y devolvía el contenido
        // VACÍO — el chat mostraba una burbuja en blanco después de 7 segundos.
        //
        // Para lo que hace el asistente (elegir una consulta y redactar unas
        // cifras que ya vienen calculadas) el razonamiento no aporta.
        $payload['reasoning'] = ['enabled' => false];

        if ($rapido) {
            // Decidir qué consultar debe dar siempre el mismo resultado.
            $payload['temperature'] = 0;
        }

        $data    = $this->llamar($payload);
        $mensaje = $data['choices'][0]['message'] ?? [];
        $texto   = trim($mensaje['content'] ?? '');

        if ($texto !== '') {
            return $texto;
        }

        // Llegar aquí significa que la llamada fue exitosa pero no trajo texto.
        // Antes esto devolvía '' y el usuario veía una burbuja vacía sin saber
        // por qué. Ahora se explica qué pasó.
        $razon = $data['choices'][0]['finish_reason'] ?? null;

        Log::error('IA: respuesta sin contenido', [
            'modelo'        => $payload['model'],
            'finish_reason' => $razon,
            'tenia_razonamiento' => ! empty($mensaje['reasoning']),
        ]);

        if ($razon === 'length') {
            throw new IaException(
                'El modelo se quedó sin espacio antes de terminar la respuesta. '
                . 'Suele pasar con modelos que "razonan" mucho: prueba con uno más directo '
                . 'en Configuración → Perfil de marca → Modelo de texto.'
            );
        }

        throw new IaException(
            "El modelo {$payload['model']} no devolvió texto. Prueba con otro modelo de texto."
        );
    }

    /**
     * Igual que texto(), pero va entregando la respuesta por pedazos.
     *
     * No hace la respuesta más rápida: hace que se vea a los 2 segundos en vez
     * de mirar unos puntos suspensivos durante 7. Es la diferencia entre "está
     * pensando" y "está escribiendo".
     *
     * @param  callable $alRecibir  se llama con cada pedazo de texto nuevo
     * @return string   la respuesta completa, para guardarla y leerla en voz alta
     */
    public function textoStream(string $prompt, string $instrucciones, int $maxTokens, callable $alRecibir): string
    {
        if (! $this->configurado()) {
            throw new IaException('La IA no está configurada.');
        }

        $mensajes = [];

        if ($instrucciones !== '') {
            $mensajes[] = ['role' => 'system', 'content' => $instrucciones];
        }

        $mensajes[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model'      => $this->modeloTexto(),
            'messages'   => $mensajes,
            'max_tokens' => $maxTokens,
            'stream'     => true,
            'reasoning'  => ['enabled' => false],
        ];

        if ($this->priorizarVelocidad()) {
            $payload['provider'] = ['sort' => 'throughput'];
        }

        $inicio = microtime(true);

        $resp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey(),
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => config('app.url'),
                'X-Title'       => 'Briela',
            ])
            ->withOptions(['stream' => true])
            ->timeout(120)
            ->post(self::API_URL, $payload);

        if (! $resp->successful()) {
            $data = $resp->json() ?? [];

            Log::error('IA: error al abrir el stream', ['status' => $resp->status(), 'respuesta' => $data]);

            if ($resp->status() === 402) {
                throw new IaException('La cuenta de IA se quedó sin saldo. Recarga en openrouter.ai.');
            }

            throw new IaException('La IA respondió con un error: ' . ($data['error']['message'] ?? $resp->body()));
        }

        $cuerpo      = $resp->toPsrResponse()->getBody();
        $completo    = '';
        $buffer      = '';
        $razonamiento = 0;   // cuánto "pensó" sin producir respuesta
        $motivoFin   = null;

        // OpenRouter manda "Server-Sent Events": líneas "data: {json}" y al
        // final "data: [DONE]". Puede partir un JSON entre dos lecturas, por
        // eso se acumula en un buffer y solo se procesan líneas completas.
        while (! $cuerpo->eof()) {
            $buffer .= $cuerpo->read(1024);

            while (($corte = strpos($buffer, "\n")) !== false) {
                $linea  = trim(substr($buffer, 0, $corte));
                $buffer = substr($buffer, $corte + 1);

                if ($linea === '' || ! str_starts_with($linea, 'data: ')) {
                    continue;
                }

                $json = substr($linea, 6);

                if ($json === '[DONE]') {
                    break 2;
                }

                $trozo = json_decode($json, true);
                $delta = $trozo['choices'][0]['delta']['content'] ?? '';

                $motivoFin = $trozo['choices'][0]['finish_reason'] ?? $motivoFin;

                // Algunos modelos razonan siempre, aunque se les pida que no.
                // Ese texto no es la respuesta y se descuenta del mismo cupo,
                // así que hay que contarlo para poder explicar qué pasó.
                $razonamiento += strlen($trozo['choices'][0]['delta']['reasoning'] ?? '');

                if ($delta !== '' && $delta !== null) {
                    $completo .= $delta;
                    $alRecibir($delta);
                }
            }
        }

        $this->ultimaDuracionMs = (int) ((microtime(true) - $inicio) * 1000);

        if (trim($completo) === '') {
            Log::error('IA: stream sin contenido', [
                'modelo'        => $payload['model'],
                'finish_reason' => $motivoFin,
                'chars_razonamiento' => $razonamiento,
                'max_tokens'    => $maxTokens,
            ]);

            if ($razonamiento > 0) {
                throw new IaException(
                    "El modelo {$payload['model']} gastó todo su presupuesto razonando y no alcanzó a escribir la respuesta. "
                    . 'Sube el "Máximo de palabras por respuesta" en Configuración, o usa un modelo que no razone '
                    . '(por ejemplo google/gemini-3.5-flash).'
                );
            }

            throw new IaException(
                "El modelo {$payload['model']} no devolvió texto (motivo: " . ($motivoFin ?? 'desconocido') . '). '
                . 'Prueba con otro modelo de texto en Configuración.'
            );
        }

        return trim($completo);
    }

    /**
     * Genera una imagen y la devuelve como contenido binario.
     *
     * OpenRouter devuelve la imagen como "data URL" en base64 dentro del
     * mensaje; aquí se decodifica para poder guardarla como archivo.
     *
     * @return array{contenido: string, extension: string}
     */
    public function imagen(string $prompt): array
    {
        $data = $this->llamar([
            'model'      => $this->modeloImagen(),
            'messages'   => [['role' => 'user', 'content' => $prompt]],
            'modalities' => ['image', 'text'],
        ], timeout: 120);

        $dataUrl = $data['choices'][0]['message']['images'][0]['image_url']['url'] ?? null;

        if (! $dataUrl) {
            Log::error('IA: la respuesta no traía ninguna imagen', ['respuesta' => $data]);

            throw new IaException('La IA no devolvió ninguna imagen. Intenta de nuevo o cambia la descripción.');
        }

        return $this->decodificarDataUrl($dataUrl);
    }

    public function vozNaturalActiva(): bool
    {
        return \App\Models\Configuracion::get('ia_voz_natural', '0') === '1';
    }

    /**
     * Si se le pide a OpenRouter el proveedor más rápido en vez del más barato.
     * Encendido por defecto: la diferencia de precio es mínima y la de
     * velocidad se siente en cada pregunta.
     */
    public function priorizarVelocidad(): bool
    {
        return \App\Models\Configuracion::get('ia_priorizar_velocidad', '1') === '1';
    }

    /**
     * Cupo de tokens para la respuesta del asistente.
     *
     * Estaba fijo en 1200 y eso alcanzaba para una respuesta corta, pero no
     * para un informe de tres temas — y con modelos que razonan es peor,
     * porque lo que "piensan" se descuenta del mismo cupo y la respuesta
     * llegaba vacía.
     */
    public function maxTokensRespuesta(): int
    {
        $valor = (int) \App\Models\Configuracion::get('ia_max_tokens', 0);

        return $valor >= 500 ? min($valor, 16000) : 3000;
    }

    public function modeloVoz(): string
    {
        return $this->ajuste('ia_modelo_voz', 'services.ia.modelo_voz', 'openai/gpt-audio-mini');
    }

    public function nombreVoz(): string
    {
        return $this->ajuste('ia_voz', 'services.ia.voz', 'nova');
    }

    /**
     * Cómo debe sonar: acento, tono y ritmo.
     *
     * Los modelos de voz aceptan instrucciones en lenguaje natural, y es lo
     * que más cambia el resultado — mucho más que elegir otra voz de la lista.
     * Por defecto se pide español colombiano y tono conversacional, porque las
     * voces "de fábrica" tienden a sonar neutras o españolas.
     */
    public function instruccionesVoz(): string
    {
        $porDefecto = 'Habla en español latinoamericano con acento colombiano neutro, '
            . 'de Bogotá. Tono cálido, cercano y profesional, como una compañera de trabajo '
            . 'explicando algo. Ritmo conversacional y natural, ni lento ni acelerado. '
            . 'Entonación viva, no monótona. No exageres la emoción.';

        $guardado = \App\Models\Configuracion::get('ia_voz_instrucciones', '');

        return $guardado !== '' && $guardado !== null ? (string) $guardado : $porDefecto;
    }

    /**
     * Convierte texto en audio hablado.
     *
     * Usa el endpoint de voz de OpenRouter, que es compatible con el de
     * OpenAI. Devuelve los bytes del MP3.
     */
    /**
     * Tipo de audio que produce el camino que se va a usar. Sirve para
     * declararlo bien al navegador.
     */
    public function mimeVoz(): string
    {
        return str_contains($this->modeloVoz(), 'tts') ? 'audio/mpeg' : 'audio/wav';
    }

    public function voz(string $texto, ?string $voz = null): string
    {
        if (! $this->configurado()) {
            throw new IaException('La IA no está configurada.');
        }

        // OpenRouter tiene DOS caminos para generar voz y no son intercambiables:
        //
        //  - Modelos TTS dedicados (su id lleva "tts"): endpoint /audio/speech,
        //    que devuelve el audio como archivo.
        //  - Modelos de conversación con audio (gpt-audio y similares): van por
        //    /chat/completions con modalities ["text","audio"], y el audio llega
        //    en trozos por streaming.
        //
        // Si se usa el camino equivocado, la API responde "Model does not exist"
        // aunque el modelo sí exista.
        return str_contains($this->modeloVoz(), 'tts')
            ? $this->vozPorTts($texto, $voz)
            : $this->vozPorConversacion($texto, $voz);
    }

    /** Camino para modelos TTS dedicados. */
    private function vozPorTts(string $texto, ?string $voz = null): string
    {
        $resp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey(),
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => config('app.url'),
                'X-Title'       => 'Briela',
            ])
            ->timeout(90)
            ->post('https://openrouter.ai/api/v1/audio/speech', [
                'model'           => $this->modeloVoz(),
                'voice'           => $voz ?: $this->nombreVoz(),
                'input'           => $texto,
                // Sin esto el formato por defecto es PCM crudo, que el
                // navegador no sabe reproducir.
                'response_format' => 'mp3',
                // El acento y el tono van como opción del proveedor, no en la
                // raíz. Los proveedores que no la soportan la ignoran.
                'provider'        => [
                    'options' => [
                        'openai' => ['instructions' => $this->instruccionesVoz()],
                    ],
                ],
            ]);

        if (! $resp->successful()) {
            $data = $resp->json() ?? [];

            Log::error('IA: error generando la voz', [
                'status'    => $resp->status(),
                'respuesta' => $data,
            ]);

            $detalle = $data['error']['message'] ?? 'No se pudo generar el audio.';

            throw new IaException($detalle, is_array($data) ? $data : []);
        }

        return $resp->body();
    }

    /**
     * Camino para modelos de conversación con salida de audio (gpt-audio).
     *
     * Estos exigen streaming: el audio llega en trozos base64 dentro de eventos
     * SSE, que hay que juntar y decodificar.
     *
     * Ventaja de este camino: como es un modelo conversacional, el acento y el
     * tono se le piden en el mensaje de sistema, que funciona mejor que un
     * parámetro.
     */
    private function vozPorConversacion(string $texto, ?string $voz = null): string
    {
        $instrucciones = 'Eres un lector de textos. Lee EN VOZ ALTA, palabra por palabra, '
            . 'exactamente el texto que te da el usuario. No lo resumas, no lo comentes, '
            . 'no agregues saludos ni despedidas, no respondas nada más. '
            . $this->instruccionesVoz();

        $resp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey(),
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => config('app.url'),
                'X-Title'       => 'Briela',
            ])
            ->timeout(120)
            ->post(self::API_URL, [
                'model'      => $this->modeloVoz(),
                'modalities' => ['text', 'audio'],
                'audio'      => [
                    'voice'  => $voz ?: $this->nombreVoz(),
                    // Con streaming el proveedor SOLO acepta pcm16. Es audio
                    // crudo, sin cabecera; se le agrega abajo para que el
                    // navegador pueda reproducirlo.
                    'format' => 'pcm16',
                ],
                'messages'   => [
                    ['role' => 'system', 'content' => $instrucciones],
                    ['role' => 'user',   'content' => $texto],
                ],
                // Obligatorio: sin streaming estos modelos no devuelven audio.
                'stream'     => true,
            ]);

        if (! $resp->successful()) {
            $data = $resp->json() ?? [];

            Log::error('IA: error generando la voz (conversación)', [
                'status'    => $resp->status(),
                'respuesta' => $data,
            ]);

            throw new IaException($this->detalleDelError($data, $resp->body()), is_array($data) ? $data : []);
        }

        // Con streaming, el error del proveedor puede venir DENTRO del cuerpo
        // con estado 200. Hay que mirarlo antes de intentar juntar el audio.
        if (str_contains($resp->body(), '"error"') && ! str_contains($resp->body(), '"audio"')) {
            $primero = json_decode(trim(str_replace('data:', '', explode("\n", $resp->body())[0] ?? '')), true);

            throw new IaException($this->detalleDelError($primero ?? [], $resp->body()));
        }

        // El resultado es PCM crudo: hay que envolverlo en un WAV para que el
        // navegador lo pueda reproducir.
        return $this->envolverEnWav($this->juntarAudioDeSse($resp->body()));
    }

    /**
     * Convierte audio PCM de 16 bits en un archivo WAV reproducible.
     *
     * El PCM crudo no tiene cabecera: son solo las muestras. Sin los 44 bytes
     * de cabecera, el navegador no sabe a qué frecuencia ni con cuántos
     * canales reproducirlo, y no suena nada.
     *
     * Los modelos de audio de OpenAI entregan 24 kHz, mono, 16 bits.
     */
    private function envolverEnWav(string $pcm, int $frecuencia = 24000, int $canales = 1): string
    {
        $bitsPorMuestra = 16;
        $bytesPorBloque = $canales * ($bitsPorMuestra / 8);
        $bytesPorSegundo = $frecuencia * $bytesPorBloque;
        $tamano = strlen($pcm);

        $cabecera = 'RIFF'
            . pack('V', 36 + $tamano)   // tamaño total menos 8
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)             // tamaño del bloque de formato
            . pack('v', 1)              // 1 = PCM sin comprimir
            . pack('v', $canales)
            . pack('V', $frecuencia)
            . pack('V', $bytesPorSegundo)
            . pack('v', $bytesPorBloque)
            . pack('v', $bitsPorMuestra)
            . 'data'
            . pack('V', $tamano);

        return $cabecera . $pcm;
    }

    /**
     * Saca el mensaje más útil posible del error.
     *
     * OpenRouter suele devolver "Provider returned error" y el motivo real del
     * proveedor queda escondido en metadata.raw. Sin esto, el usuario ve un
     * mensaje genérico que no sirve para arreglar nada.
     */
    private function detalleDelError(array $data, string $cuerpo): string
    {
        $mensaje = $data['error']['message'] ?? 'No se pudo generar el audio.';
        $crudo   = $data['error']['metadata']['raw'] ?? null;

        if (is_array($crudo)) {
            $crudo = $crudo['error']['message'] ?? json_encode($crudo);
        }

        if (is_string($crudo) && $crudo !== '') {
            // El crudo a veces trae otro JSON adentro.
            $interno = json_decode($crudo, true);
            $crudo   = $interno['error']['message'] ?? $crudo;

            return $mensaje . ' — ' . \Illuminate\Support\Str::limit($crudo, 300);
        }

        return $mensaje;
    }

    /**
     * Extrae y junta los trozos de audio de una respuesta SSE.
     */
    private function juntarAudioDeSse(string $cuerpo): string
    {
        $trozos = '';

        foreach (explode("\n", $cuerpo) as $linea) {
            $linea = trim($linea);

            if (! str_starts_with($linea, 'data:')) {
                continue;
            }

            $json = trim(substr($linea, 5));

            if ($json === '' || $json === '[DONE]') {
                continue;
            }

            $evento = json_decode($json, true);
            $dato   = $evento['choices'][0]['delta']['audio']['data'] ?? null;

            if ($dato) {
                $trozos .= $dato;
            }
        }

        if ($trozos === '') {
            Log::error('IA: la respuesta de voz no traía audio', ['cuerpo' => substr($cuerpo, 0, 500)]);

            throw new IaException(
                'El modelo respondió pero no devolvió audio. '
                . 'Puede que no sea un modelo con salida de voz.'
            );
        }

        $audio = base64_decode($trozos, true);

        if ($audio === false) {
            throw new IaException('El audio llegó dañado.');
        }

        return $audio;
    }

    /**
     * Lista de modelos disponibles en OpenRouter, separados por lo que hacen.
     * Se cachea un día: la lista cambia poco y no vale la pena pedirla en cada
     * carga de la pantalla.
     *
     * @return array{texto: array<string,string>, imagen: array<string,string>}
     */
    public function modelosDisponibles(): array
    {
        if (! $this->configurado()) {
            return ['texto' => [], 'imagen' => [], 'voz' => []];
        }

        return \Cache::remember('ia_modelos_openrouter_v3', now()->addDay(), function () {
            try {
                $resp = Http::withHeaders(['Authorization' => 'Bearer ' . $this->apiKey()])
                    ->timeout(20)
                    ->get('https://openrouter.ai/api/v1/models');

                if (! $resp->successful()) {
                    return ['texto' => [], 'imagen' => []];
                }

                $texto = [];
                $imagen = [];
                $voz = [];

                foreach ($resp->json('data', []) as $m) {
                    $id     = $m['id'] ?? null;
                    $nombre = $m['name'] ?? $id;

                    if (! $id) {
                        continue;
                    }

                    $salidas = $m['architecture']['output_modalities'] ?? ['text'];

                    // Un modelo se clasifica por lo que PRODUCE. La modalidad de
                    // los modelos de voz es "speech"; "audio" incluye también
                    // generadores de música (Lyria), que no sirven aquí.
                    if (in_array('speech', $salidas, true)) {
                        $voz[$id] = $nombre;
                    } elseif (in_array('image', $salidas, true)) {
                        $imagen[$id] = $nombre;
                    } elseif (in_array('text', $salidas, true)) {
                        $texto[$id] = $nombre;
                    }
                }

                // Respaldo: si la API no marca la modalidad, se detectan por el
                // nombre. Se excluye Lyria porque genera música, no voz.
                if (empty($voz)) {
                    foreach ($resp->json('data', []) as $m) {
                        $id = $m['id'] ?? '';
                        if ($id && preg_match('/tts|speech/i', $id) && ! preg_match('/lyria|music/i', $id)) {
                            $voz[$id] = $m['name'] ?? $id;
                        }
                    }
                }

                asort($texto);
                asort($imagen);
                asort($voz);

                return ['texto' => $texto, 'imagen' => $imagen, 'voz' => $voz];
            } catch (\Throwable $e) {
                Log::warning('IA: no se pudo leer la lista de modelos', ['error' => $e->getMessage()]);

                return ['texto' => [], 'imagen' => [], 'voz' => []];
            }
        });
    }

    /**
     * Llamada común a OpenRouter, con el manejo de errores en un solo sitio.
     */
    private function llamar(array $payload, int $timeout = 60): array
    {
        if (! $this->configurado()) {
            throw new IaException(
                'La IA no está configurada. Agrega la credencial de OpenRouter en Configuración → Perfil de marca y asistente.'
            );
        }

        // OpenRouter reparte el mismo modelo entre varios proveedores
        // (DeepInfra, Together, Fireworks...) y por defecto elige por precio,
        // no por velocidad. La diferencia es grande: el mismo modelo puede ir
        // al doble de rápido según a quién le toque. Con sort=throughput se
        // prioriza el más rápido disponible.
        //
        // Se puede apagar desde Ajustes si algún día conviene el más barato.
        if ($this->priorizarVelocidad() && ! isset($payload['provider'])) {
            $payload['provider'] = ['sort' => 'throughput'];
        }

        $inicio = microtime(true);

        $resp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey(),
                'Content-Type'  => 'application/json',
                // OpenRouter pide identificar la aplicación que consume la API.
                'HTTP-Referer'  => config('app.url'),
                'X-Title'       => 'Briela',
            ])
            ->timeout($timeout)
            ->post(self::API_URL, $payload);

        $this->ultimaDuracionMs = (int) ((microtime(true) - $inicio) * 1000);

        if (! $resp->successful()) {
            $data = $resp->json() ?? [];

            Log::error('IA: error en la llamada a OpenRouter', [
                'status'    => $resp->status(),
                'respuesta' => $data,
            ]);

            $detalle = $data['error']['message'] ?? $resp->body();

            // El caso más común en producción: se acabó el saldo.
            if ($resp->status() === 402) {
                throw new IaException('La cuenta de IA se quedó sin saldo. Recarga en openrouter.ai.', $data);
            }

            throw new IaException("La IA respondió con un error: {$detalle}", $data);
        }

        return $resp->json() ?? [];
    }

    /**
     * "data:image/png;base64,AAAA..." → binario + extensión.
     *
     * @return array{contenido: string, extension: string}
     */
    private function decodificarDataUrl(string $dataUrl): array
    {
        if (! preg_match('/^data:image\/(\w+);base64,(.+)$/s', $dataUrl, $m)) {
            throw new IaException('La imagen llegó en un formato que no se pudo leer.');
        }

        $contenido = base64_decode($m[2], true);

        if ($contenido === false) {
            throw new IaException('La imagen llegó dañada.');
        }

        return [
            'contenido' => $contenido,
            'extension' => strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]),
        ];
    }
}
