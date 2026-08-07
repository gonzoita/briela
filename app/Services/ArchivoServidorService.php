<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda archivos en el propio servidor (storage/app/public), servidos por el
 * enlace public/storage.
 *
 * Reemplaza a GoogleDriveService para todo lo que se sube de aquí en adelante.
 * El motivo es el mismo que ya había obligado a sacar el logo y el favicon de
 * Drive (ver ImagenMarcaService): **Drive entrega enlaces de vista previa, que
 * son páginas web, no imágenes**. El navegador no las puede poner en un <img>
 * y salen rotas. Encima, ataba archivos básicos del sistema a credenciales de
 * un servicio externo.
 *
 * Devuelve la misma forma de arreglo que GoogleDriveService::upload() para que
 * los sitios que lo llamaban cambien lo mínimo. Los campos propios de Drive
 * ('id' y 'view') vienen en null: sirven justamente para distinguir un archivo
 * del servidor de uno heredado de Drive.
 *
 * OJO: los archivos que ya viven en Drive se siguen leyendo por su URL
 * guardada. Este servicio solo cambia dónde se guardan los nuevos.
 */
class ArchivoServidorService
{
    /**
     * @param  string       $carpeta  Subcarpeta dentro de storage/app/public (ej. 'cursos').
     * @param  string|null  $nombre   Nombre del archivo. Si no se da, se genera uno único.
     * @return array{id:null,name:string,mime:string|null,url:string,view:null,ruta:string}
     */
    public static function subir(UploadedFile $archivo, string $carpeta, ?string $nombre = null): array
    {
        $carpeta = trim($carpeta, '/');

        if ($nombre === null) {
            $extension = strtolower($archivo->getClientOriginalExtension() ?: 'bin');
            // La marca de tiempo hace que la URL cambie en cada subida, así el
            // navegador no se queda mostrando la imagen anterior por caché.
            $nombre = Str::uuid() . '-' . now()->format('YmdHis') . '.' . $extension;
        }

        $ruta = $archivo->storeAs($carpeta, $nombre, 'public');

        return [
            'id'   => null,
            'name' => $nombre,
            'mime' => $archivo->getClientMimeType(),
            'url'  => Storage::disk('public')->url($ruta),
            'view' => null,
            'ruta' => $ruta,
        ];
    }

    /**
     * Borra un archivo del servidor. Acepta tanto la ruta relativa del disco
     * (`ensambles/foto.png`) como la URL pública (`/storage/ensambles/foto.png`).
     *
     * Admitir las dos formas no es un capricho: lo que se guarda en la base es
     * la URL, no la ruta, y llamar a Storage::delete() con la URL tal cual
     * nunca borra nada — el archivo queda huérfano ocupando disco en silencio.
     *
     * Silencioso si el archivo ya no está, o si la ruta es de Drive (http),
     * que se borra por su propio servicio.
     */
    public static function borrar(?string $rutaOUrl): void
    {
        $valor = trim((string) $rutaOUrl);

        if ($valor === '') {
            return;
        }

        // Una URL absoluta puede ser nuestra (Storage::url() devuelve el
        // dominio completo) o de un servicio externo como Drive. Se distingue
        // por el segmento /storage/: lo de afuera lo borra su propio servicio.
        if (Str::startsWith($valor, ['http://', 'https://']) && ! Str::contains($valor, '/storage/')) {
            return;
        }

        $ruta = static::rutaDesdeUrl($valor);

        if ($ruta !== '' && Storage::disk('public')->exists($ruta)) {
            Storage::disk('public')->delete($ruta);
        }
    }

    /**
     * Convierte una URL pública en la ruta relativa del disco 'public'.
     * Si ya viene como ruta relativa, la devuelve igual.
     */
    public static function rutaDesdeUrl(string $rutaOUrl): string
    {
        // Quita el dominio si viene una URL absoluta del propio sitio.
        $ruta = parse_url($rutaOUrl, PHP_URL_PATH) ?: $rutaOUrl;

        // Str::after devuelve la cadena completa si no encuentra '/storage/',
        // así que esto sirve igual para una ruta relativa que ya venga limpia.
        return ltrim(Str::after($ruta, '/storage/'), '/');
    }
}
