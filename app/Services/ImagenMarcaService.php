<?php

namespace App\Services;

use App\Models\Configuracion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Guarda logo y favicon en el propio servidor.
 *
 * Antes esto iba a Google Drive, pero Drive entrega enlaces de *vista previa*,
 * no imágenes directas: el navegador no los puede poner en un <img> y salían
 * rotos. Además dependía de credenciales externas para algo tan básico como
 * el logo de la empresa.
 *
 * Ahora van a storage/app/public/marca, servidos por el enlace public/storage.
 */
class ImagenMarcaService
{
    private const CARPETA = 'marca';

    /**
     * Guarda la imagen y devuelve su URL pública.
     *
     * El nombre lleva la marca de tiempo a propósito: así la URL cambia en
     * cada subida y el navegador se ve obligado a bajar la nueva. Sin eso, el
     * favicon viejo se queda pegado en caché durante días.
     */
    public static function guardar(UploadedFile $archivo, string $clave): string
    {
        static::borrarAnterior($clave);

        $extension = strtolower($archivo->getClientOriginalExtension() ?: 'png');
        $nombre    = "{$clave}-" . now()->format('YmdHis') . ".{$extension}";

        $ruta = $archivo->storeAs(self::CARPETA, $nombre, 'public');

        Configuracion::set("{$clave}_ruta", $ruta);

        return Storage::disk('public')->url($ruta);
    }

    /** Borra el archivo y limpia el ajuste. */
    public static function eliminar(string $clave): void
    {
        static::borrarAnterior($clave);

        Configuracion::set("{$clave}_ruta", '');
    }

    /**
     * URL pública de la imagen guardada, o null si no hay.
     *
     * Se recalcula desde la ruta en vez de guardar la URL completa, porque si
     * cambia el dominio (o se monta el sistema para otra empresa) las URLs
     * guardadas quedarían apuntando al sitio anterior.
     */
    public static function url(string $clave): ?string
    {
        $ruta = trim((string) Configuracion::get("{$clave}_ruta", ''));

        if ($ruta === '' || ! Storage::disk('public')->exists($ruta)) {
            return null;
        }

        return Storage::disk('public')->url($ruta);
    }

    private static function borrarAnterior(string $clave): void
    {
        $anterior = trim((string) Configuracion::get("{$clave}_ruta", ''));

        if ($anterior !== '' && Storage::disk('public')->exists($anterior)) {
            Storage::disk('public')->delete($anterior);
        }
    }
}
