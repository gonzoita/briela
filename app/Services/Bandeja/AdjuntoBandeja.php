<?php

namespace App\Services\Bandeja;

use App\Models\Archivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Los archivos que entran por cualquier canal de la bandeja.
 *
 * **Por qué se bajan y no se guarda el enlace.** Ninguna API de Meta manda el archivo en el
 * webhook: manda una URL, y esas URLs vencen en minutos u horas. Guardar el enlace deja un
 * historial de imágenes rotas al día siguiente, que es peor que no tener el archivo: quien
 * atiende ve que «había una foto» y no puede abrirla.
 *
 * **Por qué está aquí y no en el servicio de cada canal.** WhatsApp pide el archivo con un
 * identificador y un token; Messenger e Instagram lo mandan con una URL directa. Eso es lo
 * único que cambia. Guardarlo —el nombre, la extensión desde el mime, el tope de tamaño, la
 * fila en `archivos` con su morph y su categoría— es idéntico, y escrito dos veces la segunda
 * copia se olvida de mantener.
 */
class AdjuntoBandeja
{
    /**
     * Tope de lo que se baja. Los límites de Meta llegan a 100 MB en documentos, y bajar eso
     * en el servidor de un cliente —sin preguntarle— llena el disco de una instalación que no
     * dimensionó para guardar videos.
     */
    public const MAXIMO_BYTES = 32 * 1024 * 1024;

    /**
     * Baja un archivo de una URL y lo deja guardado.
     *
     * @param  array<string, string>  $cabeceras  por ejemplo el token, cuando la URL lo exige
     * @param  string|null  $mime  el tipo que ya dijo la API. WhatsApp lo da en una consulta
     *        aparte y es más fiable que la cabecera de la descarga, que a veces llega como
     *        `application/octet-stream` y dejaría la foto guardada con extensión `.bin`.
     * @return Archivo|null  null cuando no se pudo: el mensaje se guarda sin archivo
     */
    public function descargar(
        string $url,
        Model $conversacion,
        ?string $nombreOriginal = null,
        array $cabeceras = [],
        ?string $mime = null,
    ): ?Archivo {
        try {
            // **En flujo, no de un bocado.** Comprobar el tamaño después de `body()` es
            // comprobarlo cuando el archivo ya está entero en memoria: un adjunto de 100 MB
            // —los documentos de Meta llegan a eso— tumbaba la petición del webhook por falta
            // de memoria, y Meta la reintentaba una y otra vez. Así se corta antes.
            $respuesta = Http::withHeaders($cabeceras)
                ->withOptions(['stream' => true])
                ->timeout(60)
                ->get($url);

            if (! $respuesta->successful()) {
                Log::warning('Bandeja: no se pudo bajar el adjunto.', [
                    'url'    => Str::limit($url, 120),
                    'status' => $respuesta->status(),
                ]);

                return null;
            }

            // Si el servidor dice de entrada cuánto pesa, no hace falta leer ni un byte.
            $anunciado = (int) $respuesta->header('Content-Length');

            if ($anunciado > self::MAXIMO_BYTES) {
                Log::warning('Bandeja: adjunto más grande de lo que se baja.', ['bytes' => $anunciado]);

                return null;
            }

            $cuerpo = $this->leerConTope($respuesta->toPsrResponse()->getBody());

            if ($cuerpo === null) {
                Log::warning('Bandeja: adjunto más grande de lo que se baja.', [
                    'tope' => self::MAXIMO_BYTES,
                ]);

                return null;
            }

            return $this->guardar(
                contenido: $cuerpo,
                mime: $mime ?: ($respuesta->header('Content-Type') ?: 'application/octet-stream'),
                conversacion: $conversacion,
                nombreOriginal: $nombreOriginal ?: $this->nombreDesdeUrl($url),
            );
        } catch (\Throwable $e) {
            Log::error('Bandeja: error inesperado al bajar un adjunto.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Lee el cuerpo hasta el tope, y se rinde si lo pasa.
     *
     * Devuelve `null` cuando el archivo es más grande, sin haberlo cargado entero: se lee un
     * trozo a la vez y se corta en cuanto se pasa, que es justo lo que no hacía comprobar
     * `strlen()` sobre el cuerpo ya buffereado.
     */
    private function leerConTope(\Psr\Http\Message\StreamInterface $flujo): ?string
    {
        $cuerpo = '';

        while (! $flujo->eof()) {
            $cuerpo .= $flujo->read(256 * 1024);

            if (strlen($cuerpo) > self::MAXIMO_BYTES) {
                return null;
            }
        }

        return $cuerpo;
    }

    /**
     * Guarda unos bytes como `Archivo` del sistema.
     *
     * Se escribe con `Storage` y no con `ArchivoServidorService` porque ese recibe un
     * `UploadedFile` —lo que llega de un formulario— y acá lo que hay es el cuerpo de una
     * respuesta HTTP. El resto es idéntico: mismo disco, misma forma de URL.
     *
     * `subido_por` queda en nulo a propósito: no lo subió nadie del sistema. Ver la migración
     * que lo hizo nulable.
     */
    public function guardar(string $contenido, string $mime, Model $conversacion, ?string $nombreOriginal = null): Archivo
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
    public function extensionDe(string $mime, ?string $nombreOriginal): string
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
            'video/quicktime' => 'mov',
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
     * El nombre que sugiere la URL, si trae alguno con pinta de archivo.
     *
     * Las URLs de Meta traen una ristra de parámetros de firma; quedarse con el último tramo
     * tal cual dejaría nombres de doscientos caracteres en la lista de Multimedia.
     */
    private function nombreDesdeUrl(string $url): ?string
    {
        $camino = parse_url($url, PHP_URL_PATH) ?: '';
        $base   = basename($camino);

        return (str_contains($base, '.') && mb_strlen($base) <= 80) ? $base : null;
    }
}
