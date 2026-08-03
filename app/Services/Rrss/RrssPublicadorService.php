<?php

namespace App\Services\Rrss;

use App\Exceptions\RrssApiException;
use App\Models\PublicacionRrss;
use App\Models\PublicacionRrssCuenta;
use App\Services\NotificacionService;
use App\Services\Rrss\GoogleBusinessRrssService;
use App\Services\Rrss\LinkedinRrssService;
use App\Services\Rrss\MetaRrssService;
use Illuminate\Support\Facades\Log;

/**
 * Punto único que sabe a qué servicio de red social despachar cada
 * publicación. La usa tanto el comando programado (rrss:publicar-programadas)
 * como el botón "publicar ahora" del controlador.
 */
class RrssPublicadorService
{
    public function __construct(
        private readonly MetaRrssService $meta,
        private readonly LinkedinRrssService $linkedin,
        private readonly GoogleBusinessRrssService $googleBusiness,
        private readonly NotificacionService $notificaciones,
    ) {
    }

    /**
     * Publica una PublicacionRrss en todas sus cuentas destino pendientes.
     * Cada cuenta se intenta de forma independiente: si una falla, no
     * bloquea a las demás.
     */
    public function publicar(PublicacionRrss $publicacion): void
    {
        $publicacion->update(['estado' => 'publicando']);

        $primeraImagen = $publicacion->archivos()->where('extension', '!=', 'pdf')->first();
        $urlImagen = $primeraImagen?->url;

        $pendientes = $publicacion->cuentasDestino()->where('estado', 'pendiente')->with('cuenta')->get();

        $huboExito = false;
        $huboFallo = false;

        foreach ($pendientes as $destino) {
            try {
                $resultado = $this->publicarEnCuenta($destino, $publicacion->contenido, $urlImagen);

                $destino->update([
                    'estado'                  => 'publicada',
                    'publicado_en'            => now(),
                    'id_publicacion_externa'  => $resultado['id_publicacion_externa'] ?? null,
                    'url_publicacion'         => $resultado['url_publicacion'] ?? null,
                    'error'                   => null,
                ]);
                $destino->cuenta?->update(['ultima_publicacion_en' => now(), 'ultimo_error' => null]);
                $huboExito = true;
            } catch (\Throwable $e) {
                Log::error('RRSS: falló la publicación en una cuenta', [
                    'publicacion_id' => $publicacion->id,
                    'cuenta_id'      => $destino->cuenta_rrss_id,
                    'error'          => $e->getMessage(),
                ]);

                $destino->update(['estado' => 'fallida', 'error' => $e->getMessage()]);
                $destino->cuenta?->update(['ultimo_error' => $e->getMessage()]);
                $huboFallo = true;
            }
        }

        $estadoFinal = match (true) {
            $huboExito && !$huboFallo => 'publicada',
            $huboExito && $huboFallo  => 'parcial',
            default                   => 'fallida',
        };

        $publicacion->update([
            'estado'       => $estadoFinal,
            'publicado_en' => $huboExito ? now() : null,
        ]);

        if ($huboFallo) {
            $this->avisarFalla($publicacion, $estadoFinal);
        }
    }

    private function publicarEnCuenta(PublicacionRrssCuenta $destino, string $contenido, ?string $urlImagen): array
    {
        $cuenta = $destino->cuenta;
        if (!$cuenta || !$cuenta->activa) {
            throw new RrssApiException('La cuenta ya no está activa o fue desconectada.');
        }

        return match ($cuenta->red) {
            'facebook'        => $this->meta->publicarFacebook($cuenta, $contenido, $urlImagen),
            'instagram'       => $urlImagen
                ? $this->meta->publicarInstagram($cuenta, $contenido, $urlImagen)
                : throw new RrssApiException('Instagram exige al menos una imagen; esta publicación no tiene ninguna adjunta.'),
            'linkedin'        => $this->linkedin->publicar($cuenta, $contenido, $urlImagen),
            'google_business' => $this->googleBusiness->publicar($cuenta, $contenido, $urlImagen),
            default           => throw new RrssApiException("Red no soportada: {$cuenta->red}"),
        };
    }

    private function avisarFalla(PublicacionRrss $publicacion, string $estado): void
    {
        // Avisa a los administradores para que revisen manualmente — sigue el
        // principio del sistema de no dejar pasos colgados sin que alguien
        // se entere.
        foreach (\App\Models\User::where('rol', 'administrador')->where('activo', true)->get() as $admin) {
            $this->notificaciones->crear(
                $admin->id,
                'rrss_publicacion_fallida',
                $estado === 'parcial' ? 'Publicación RRSS parcialmente fallida' : 'Publicación RRSS falló',
                "Revisa la publicación programada para el " . $publicacion->fecha_programada->format('d/m/Y H:i'),
                '/rrss/' . $publicacion->id,
            );
        }
    }
}
