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
        self::olvidarCache();

        return Storage::disk('public')->url($ruta);
    }

    /** Borra el archivo y limpia el ajuste. */
    public static function eliminar(string $clave): void
    {
        static::borrarAnterior($clave);

        Configuracion::set("{$clave}_ruta", '');
        self::olvidarCache();
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
        return self::recordar("url:{$clave}", function () use ($clave) {
            $ruta = self::rutaGuardada($clave);

            return $ruta === null ? null : Storage::disk('public')->url($ruta);
        });
    }

    /**
     * Ruta en disco de la imagen guardada, o null si no hay.
     *
     * dompdf no puede leer una URL para incrustar una imagen en un PDF: necesita
     * la ruta del archivo. Por eso esto existe además de url().
     */
    public static function ruta(string $clave): ?string
    {
        return self::recordar("ruta:{$clave}", function () use ($clave) {
            $ruta = self::rutaGuardada($clave);

            return $ruta === null ? null : Storage::disk('public')->path($ruta);
        });
    }

    /**
     * La ruta guardada, si el archivo sigue existiendo.
     *
     * El `exists()` es un vistazo al disco. Uno no se siente; seis por navegación
     * —logo, logo oscuro, favicon, favicon oscuro y el logo otra vez para saber si
     * es propio— sí, y ninguno cambia dentro de la misma petición.
     */
    private static function rutaGuardada(string $clave): ?string
    {
        $ruta = trim((string) Configuracion::get("{$clave}_ruta", ''));

        return ($ruta === '' || ! Storage::disk('public')->exists($ruta)) ? null : $ruta;
    }

    /**
     * Memoria por petición, en el contenedor y no en una estática: ver la nota de
     * `Configuracion::mapa()`. Guarda también el null: «no hay logo oscuro» es una
     * respuesta, y volver a preguntarla cuesta lo mismo que la primera vez.
     */
    private const CACHE = 'briela.imagenes-marca';

    private static function recordar(string $clave, callable $calcular): ?string
    {
        $memoria = app()->bound(self::CACHE) ? app()->make(self::CACHE) : [];

        if (! array_key_exists($clave, $memoria)) {
            $memoria[$clave] = $calcular();
            app()->instance(self::CACHE, $memoria);
        }

        return $memoria[$clave];
    }

    /** Tras subir o borrar una imagen, lo recordado ya no sirve. */
    public static function olvidarCache(): void
    {
        app()->forgetInstance(self::CACHE);
    }

    private static function borrarAnterior(string $clave): void
    {
        $anterior = trim((string) Configuracion::get("{$clave}_ruta", ''));

        if ($anterior !== '' && Storage::disk('public')->exists($anterior)) {
            Storage::disk('public')->delete($anterior);
        }
    }
}
