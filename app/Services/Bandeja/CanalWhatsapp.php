<?php

namespace App\Services\Bandeja;

use App\Models\Archivo;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappNumero;
use App\Services\WhatsAppService;
use Illuminate\Database\Eloquent\Model;

/**
 * WhatsApp dentro de la bandeja.
 *
 * Lo propio de este canal, y por lo que no se puede tratar como «un chat más»:
 *
 * - **La ventana de 24 horas.** Fuera de ella Meta rechaza el texto libre y hay que mandar una
 *   plantilla aprobada. La bandeja lo pregunta antes de intentarlo, porque el error que
 *   devuelve Meta no dice «se te venció el plazo» y deja a quien atiende creyendo que envió.
 * - **El número de salida importa.** La conversación ya sabe por qué línea entró, y por esa
 *   misma tiene que salir la respuesta: contestar desde otra le aparece al cliente como un
 *   desconocido. Es el error que `WhatsappDiagnosticoService` existe para atrapar.
 */
class CanalWhatsapp implements CanalBandeja
{
    use NormalizaMensajes;

    public function __construct(private readonly WhatsAppService $whatsapp)
    {
    }

    public function canales(): array
    {
        return ['whatsapp'];
    }

    public function encontrar(string $canal, int $id): ?Model
    {
        return WhatsappConversacion::with(['numero', 'asignado', 'lead', 'cliente'])->find($id);
    }

    public function hilo(Model $conversacion, int $limite = 200): array
    {
        return $conversacion->mensajes()
            ->with(['archivo', 'usuario'])
            // Los últimos N, pero mostrados del más viejo al más nuevo: un hilo largo se lee
            // hacia abajo, y traer los primeros de una conversación de dos años dejaría la
            // pantalla en lo que pasó hace dos años.
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => $this->normalizarMensaje($m))
            ->all();
    }

    public function responder(Model $conversacion, string $texto, ?Archivo $archivo, User $quien): array
    {
        $numero = $this->numeroDeSalida($conversacion);

        if (! $conversacion->ventanaAbierta()) {
            throw new \RuntimeException(
                $conversacion->estadoVentana()['motivo']
                . ' Usa una plantilla para volver a abrir la conversación.'
            );
        }

        $mensaje = $archivo
            ? $this->whatsapp->enviarAdjunto($numero, $conversacion->numero_contacto, $archivo, $texto, $quien)
            : $this->whatsapp->enviarMensaje($numero, $conversacion->numero_contacto, $texto, $quien)['mensaje'];

        return $this->normalizarMensaje($mensaje->fresh(['archivo', 'usuario']));
    }

    /**
     * La línea por la que salió y entra esta conversación.
     *
     * Si quedó inactiva no se responde: una línea desactivada suele estar desactivada porque su
     * token ya no sirve, y el mensaje se perdería en silencio.
     */
    private function numeroDeSalida(WhatsappConversacion $conversacion): WhatsappNumero
    {
        $numero = $conversacion->numero;

        if (! $numero) {
            throw new \RuntimeException('Esta conversación no tiene una línea de WhatsApp asociada.');
        }

        if (! $numero->activo) {
            throw new \RuntimeException("La línea «{$numero->nombre}» está desactivada: actívala para poder responder.");
        }

        return $numero;
    }

    public function cabecera(Model $conversacion): array
    {
        return [
            'origen'       => $conversacion->numero?->nombre,
            'origen_detalle' => $conversacion->numero?->numero_telefono,
            'handle'       => $conversacion->numero_contacto,
            // El enlace a WhatsApp Web deja seguir la conversación desde el celular cuando hace
            // falta mandar algo que la API no permite.
            'enlace_externo' => 'https://wa.me/' . preg_replace('/\D+/', '', (string) $conversacion->numero_contacto),
            'contexto'     => null,
        ];
    }
}
