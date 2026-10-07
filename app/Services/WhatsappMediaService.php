<?php

namespace App\Services;

use App\Models\Archivo;
use App\Models\WhatsappNumero;
use App\Support\CredencialesRrss;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Los archivos que entran y salen por WhatsApp.
 *
 * **Por qué hay que descargarlos y no guardar el enlace.** Meta no manda el archivo en el
 * webhook: manda un identificador. Con ese identificador se pide una URL, y esa URL **vence en
 * minutos** y además exige el token en la cabecera. Guardar el enlace deja un historial de
 * imágenes rotas al día siguiente, que es peor que no tener el archivo: quien atiende ve que
 * «había una foto» y no puede abrirla.
 *
 * Así que se baja al servidor y se guarda en `archivos`, igual que todo lo demás del sistema.
 * De paso queda en Multimedia con `categoria='bandeja'`, y pegado a su conversación por el
 * morph, que es lo que permite ver después todo lo que mandó un cliente.
 *
 * **Degrada con elegancia** (regla 5 del producto instalable): si la descarga falla, el mensaje
 * se guarda igual con su tipo y sin archivo. Perder la foto es malo; perder el mensaje y no
 * saber que alguien escribió es peor.
 */
class WhatsappMediaService
{
    /**
     * Tope de lo que se baja. Los límites de Meta llegan a 100 MB en documentos, y bajar eso
     * en el servidor de un cliente —sin preguntarle— llena el disco de una instalación que no
     * dimensionó para guardar videos. Lo que pase de aquí queda anotado en el mensaje.
     */
    private const MAXIMO_BYTES = 32 * 1024 * 1024;

    /** Los tipos de mensaje que traen archivo, y con qué extensión se guardan si Meta no la da. */
    private const TIPOS = [
        'image'    => ['imagen',    'jpg'],
        'audio'    => ['audio',     'ogg'],
        'video'    => ['video',     'mp4'],
        'document' => ['documento', 'bin'],
        'sticker'  => ['imagen',    'webp'],
    ];

    public static function esTipoConArchivo(string $tipo): bool
    {
        return array_key_exists($tipo, self::TIPOS);
    }

    /** El tipo que guardamos en `mensajes.tipo` para un tipo de Meta. */
    public static function tipoLocal(string $tipoMeta): string
    {
        return self::TIPOS[$tipoMeta][0] ?? 'texto';
    }

    /**
     * Baja un archivo de Meta y lo deja guardado, colgado de su conversación.
     *
     * @param  Model  $conversacion  a qué conversación pertenece (para el morph)
     * @return Archivo|null  null cuando no se pudo: el mensaje se guarda sin archivo
     */
    public function descargar(string $mediaId, Model $conversacion, ?string $nombreOriginal = null): ?Archivo
    {
        $token = CredencialesRrss::valor('whatsapp', 'secret');

        if ($token === '') {
            Log::warning('WhatsApp media: no hay token para descargar el archivo.', ['media_id' => $mediaId]);

            return null;
        }

        $version = config('services.whatsapp.api_version', 'v21.0');

        try {
            // 1. El identificador se cambia por una URL temporal y sus metadatos.
            $meta = Http::withToken($token)->timeout(15)
                ->get("https://graph.facebook.com/{$version}/{$mediaId}");

            if (! $meta->successful() || ! $meta->json('url')) {
                Log::warning('WhatsApp media: Meta no dio la URL del archivo.', [
                    'media_id' => $mediaId,
                    'respuesta' => $meta->json(),
                ]);

                return null;
            }

            $tamano = (int) $meta->json('file_size');

            if ($tamano > self::MAXIMO_BYTES) {
                Log::warning('WhatsApp media: archivo más grande de lo que se baja.', [
                    'media_id' => $mediaId,
                    'bytes'    => $tamano,
                ]);

                return null;
            }

            // 2. La descarga exige el token igual que la consulta: la URL sola no sirve.
            $descarga = Http::withToken($token)->timeout(60)->get($meta->json('url'));

            if (! $descarga->successful()) {
                Log::warning('WhatsApp media: la descarga falló.', [
                    'media_id' => $mediaId,
                    'status'   => $descarga->status(),
                ]);

                return null;
            }

            return $this->guardar(
                contenido: $descarga->body(),
                mime: (string) ($meta->json('mime_type') ?: 'application/octet-stream'),
                conversacion: $conversacion,
                nombreOriginal: $nombreOriginal,
            );
        } catch (\Throwable $e) {
            Log::error('WhatsApp media: error inesperado al descargar.', [
                'media_id' => $mediaId,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Guarda unos bytes como `Archivo` del sistema.
     *
     * Se escribe con `Storage` y no con `ArchivoServidorService` porque ese recibe un
     * `UploadedFile` —lo que llega de un formulario— y acá lo que hay es el cuerpo de una
     * respuesta HTTP. El resto es idéntico: mismo disco, misma forma de URL.
     */
    private function guardar(string $contenido, string $mime, Model $conversacion, ?string $nombreOriginal): Archivo
    {
        $extension = $this->extensionDe($mime, $nombreOriginal);
        $nombre    = Str::uuid() . '-' . now()->format('YmdHis') . '.' . $extension;
        $ruta      = 'bandeja/' . now()->format('Y-m') . '/' . $nombre;

        Storage::disk('public')->put($ruta, $contenido);

        return Archivo::create([
            'nombre_original' => $nombreOriginal ?: $nombre,
            'nombre_archivo'  => $nombre,
            'ruta'            => $ruta,
            'storage'         => 'local',
            'tipo_mime'       => $mime,
            'extension'       => $extension,
            'tamano'          => strlen($contenido),
            'categoria'       => 'bandeja',
            'archivable_type' => $conversacion->getMorphClass(),
            'archivable_id'   => $conversacion->getKey(),
        ]);
    }

    /**
     * La extensión con la que se guarda.
     *
     * El nombre que manda el cliente manda, cuando viene: un PDF que se llama
     * «cotización.pdf» tiene que seguir llamándose así al descargarlo. Si no viene, se deduce
     * del mime, y el mime de WhatsApp trae parámetros (`audio/ogg; codecs=opus`) que hay que
     * cortar o la extensión queda con un punto y coma adentro.
     */
    private function extensionDe(string $mime, ?string $nombreOriginal): string
    {
        if ($nombreOriginal && ($ext = pathinfo($nombreOriginal, PATHINFO_EXTENSION))) {
            return strtolower(preg_replace('/[^a-z0-9]/i', '', $ext)) ?: 'bin';
        }

        $limpio = trim(explode(';', $mime)[0]);

        return match ($limpio) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'audio/ogg', 'audio/opus' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/aac'  => 'm4a',
            'audio/amr'  => 'amr',
            'video/mp4'  => 'mp4',
            'video/3gpp' => '3gp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
            default      => 'bin',
        };
    }

    /**
     * Sube un archivo nuestro a Meta y devuelve su identificador, para poder mandarlo.
     *
     * **Se sube en vez de mandar el enlace** aunque la API acepte `link`, porque el enlace
     * obligaría a que la instalación sea alcanzable desde internet con el archivo público. Hay
     * clientes detrás de una VPN y archivos que no deberían quedar expuestos en una URL
     * adivinable; subirlos funciona en los dos casos.
     *
     * @return string|null  el id en Meta, o null si no se pudo
     */
    public function subir(WhatsappNumero $numero, Archivo $archivo): ?string
    {
        $token = CredencialesRrss::valor('whatsapp', 'secret');

        if ($token === '') {
            return null;
        }

        $ruta = \App\Services\ArchivoServidorService::rutaDesdeUrl((string) $archivo->ruta);

        if (! Storage::disk('public')->exists($ruta)) {
            Log::warning('WhatsApp media: el archivo a enviar no está en el disco.', ['archivo_id' => $archivo->id]);

            return null;
        }

        $version = config('services.whatsapp.api_version', 'v21.0');

        try {
            $respuesta = Http::withToken($token)
                ->timeout(60)
                ->attach('file', Storage::disk('public')->get($ruta), $archivo->nombre_original, [
                    'Content-Type' => $archivo->tipo_mime ?: 'application/octet-stream',
                ])
                ->post("https://graph.facebook.com/{$version}/{$numero->phone_number_id}/media", [
                    'messaging_product' => 'whatsapp',
                    'type'              => $archivo->tipo_mime ?: 'application/octet-stream',
                ]);

            if (! $respuesta->successful() || ! $respuesta->json('id')) {
                Log::error('WhatsApp media: no se pudo subir el archivo a Meta.', [
                    'archivo_id' => $archivo->id,
                    'respuesta'  => $respuesta->json(),
                ]);

                return null;
            }

            return (string) $respuesta->json('id');
        } catch (\Throwable $e) {
            Log::error('WhatsApp media: error al subir.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Qué clase de mensaje de WhatsApp le corresponde a un archivo nuestro.
     *
     * Importa porque el campo del JSON cambia (`image`, `audio`, `document`...) y porque solo
     * `document` e `image` admiten un pie de foto: mandar `caption` en un audio hace que Meta
     * rechace el mensaje entero.
     *
     * @return array{tipo: string, admite_pie: bool}
     */
    public static function claseDeEnvio(Archivo $archivo): array
    {
        $mime = trim(explode(';', (string) $archivo->tipo_mime)[0]);

        return match (true) {
            str_starts_with($mime, 'image/') => ['tipo' => 'image',    'admite_pie' => true],
            str_starts_with($mime, 'video/') => ['tipo' => 'video',    'admite_pie' => true],
            str_starts_with($mime, 'audio/') => ['tipo' => 'audio',    'admite_pie' => false],
            default                          => ['tipo' => 'document', 'admite_pie' => true],
        };
    }
}
