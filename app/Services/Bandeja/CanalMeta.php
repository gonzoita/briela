<?php

namespace App\Services\Bandeja;

use App\Models\Archivo;
use App\Models\BandejaConversacion;
use App\Models\BandejaMensaje;
use App\Models\CuentaRrss;
use App\Models\User;
use App\Services\Rrss\MetaMensajeriaService;
use App\Support\Canales;
use Illuminate\Database\Eloquent\Model;

/**
 * Instagram y Facebook dentro de la bandeja: directos y comentarios.
 *
 * Los cuatro canales entran por un solo adaptador porque lo que cambia entre ellos es **una
 * línea**: a qué endpoint va la respuesta. Escribir cuatro adaptadores habría hecho que la
 * cuarta copia se olvidara de un arreglo hecho en la primera.
 *
 * Lo propio de este canal, y por lo que no es «un chat más»:
 *
 * - **Un comentario es público.** La respuesta queda colgada de la publicación y la lee
 *   cualquiera, así que el hilo muestra de qué publicación salió: «¿cuánto vale?» no se puede
 *   responder sin saber qué foto estaba mirando quien lo escribió.
 * - **Sin cuenta conectada no se responde.** Si alguien desconectó la página, la conversación
 *   se conserva como historial y queda sin poder contestarse, que es exactamente la verdad.
 * - **Los adjuntos se enlazan, no se suben**: Meta viene a descargarlos, así que necesitan una
 *   instalación alcanzable desde internet. Ver `MetaMensajeriaService`.
 */
class CanalMeta implements CanalBandeja
{
    use NormalizaMensajes;

    public function __construct(private readonly MetaMensajeriaService $meta)
    {
    }

    public function canales(): array
    {
        return Canales::deRedes();
    }

    public function encontrar(string $canal, int $id): ?Model
    {
        return BandejaConversacion::with(['cuenta', 'asignado', 'lead', 'cliente', 'agente'])
            ->where('canal', $canal)
            ->find($id);
    }

    public function hilo(Model $conversacion, int $limite = 200): array
    {
        return $conversacion->mensajes()
            ->with(['archivo', 'usuario'])
            // Los últimos N, mostrados del más viejo al más nuevo: un hilo se lee hacia abajo.
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
        $cuenta = $this->cuentaDeSalida($conversacion);

        if (! Canales::esComentario($conversacion->canal()) && ! $conversacion->ventanaAbierta()) {
            throw new \RuntimeException($conversacion->estadoVentana()['motivo']);
        }

        $externoId = Canales::esComentario($conversacion->canal())
            ? $this->responderComentario($conversacion, $cuenta, $texto)
            : $this->responderDirecto($conversacion, $cuenta, $texto, $archivo);

        $mensaje = BandejaMensaje::create([
            'bandeja_conversacion_id' => $conversacion->id,
            'externo_id' => $externoId ?: null,
            'direccion'  => 'saliente',
            'tipo'       => Canales::esComentario($conversacion->canal())
                ? 'comentario'
                : $this->tipoDe($archivo),
            'contenido'  => $texto !== '' ? $texto : null,
            'archivo_id' => $archivo?->id,
            'estado'     => 'enviado',
            'usuario_id' => $quien->id,
        ]);

        $conversacion->registrarActividad('saliente');

        return $this->normalizarMensaje($mensaje->fresh(['archivo', 'usuario']));
    }

    /**
     * Qué clase de mensaje es, según lo que se adjuntó.
     *
     * Guardaba todo como «imagen», así que un PDF o una nota de voz salían del lado de la
     * empresa marcados como foto: en el hilo se intentaba mostrar la miniatura de algo que no
     * es una imagen.
     */
    private function tipoDe(?Archivo $archivo): string
    {
        if (! $archivo) {
            return 'texto';
        }

        $mime = trim(explode(';', (string) $archivo->tipo_mime)[0]);

        return match (true) {
            str_starts_with($mime, 'image/') => 'imagen',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default                          => 'documento',
        };
    }

    /**
     * La respuesta a un comentario se cuelga del comentario **más reciente** del hilo, no del
     * primero.
     *
     * En Facebook, responder siempre al comentario raíz hace que todas las respuestas queden
     * una debajo de otra sin relación con lo último que dijo la persona, y una conversación de
     * cinco idas y venidas se vuelve ilegible para cualquiera que la lea desde afuera.
     */
    private function responderComentario(BandejaConversacion $conversacion, CuentaRrss $cuenta, string $texto): string
    {
        $ultimoEntrante = $conversacion->mensajes()
            ->where('direccion', 'entrante')
            ->whereNotNull('externo_id')
            ->orderByDesc('id')
            ->value('externo_id');

        return $this->meta->responderComentario(
            $cuenta,
            $ultimoEntrante ?: $conversacion->externo_id,
            $texto,
            $conversacion->canal(),
        );
    }

    private function responderDirecto(
        BandejaConversacion $conversacion,
        CuentaRrss $cuenta,
        string $texto,
        ?Archivo $archivo,
    ): string {
        if ($archivo) {
            $id = $this->meta->enviarAdjuntoDirecto($cuenta, $conversacion->externo_id, $archivo);

            // El texto que acompaña al archivo va en un mensaje aparte, porque la API de
            // mensajes de Meta no admite texto y adjunto en el mismo envío. Perder el
            // comentario que acompaña a una foto es perder la mitad del mensaje.
            if ($texto !== '') {
                $this->meta->enviarDirecto($cuenta, $conversacion->externo_id, $texto);
            }

            return $id;
        }

        return $this->meta->enviarDirecto($cuenta, $conversacion->externo_id, $texto);
    }

    /**
     * La cuenta por la que entró y sale esta conversación.
     *
     * Si quedó desactivada o desconectada no se responde: el mensaje se perdería en silencio,
     * y en un comentario público quedarse sin contestar es peor que en un chat.
     */
    private function cuentaDeSalida(BandejaConversacion $conversacion): CuentaRrss
    {
        $cuenta = $conversacion->cuenta;

        if (! $cuenta) {
            throw new \RuntimeException(
                'La cuenta por la que entró esta conversación ya no está conectada. '
                . 'Vuelve a conectarla en Redes Sociales → Cuentas para poder responder.'
            );
        }

        if (! $cuenta->activa) {
            throw new \RuntimeException(
                "La cuenta «{$cuenta->nombre_cuenta}» está desconectada: reactívala para poder responder."
            );
        }

        return $cuenta;
    }

    public function cabecera(Model $conversacion): array
    {
        $contexto = $conversacion->contexto ?? [];

        return [
            'origen'         => $conversacion->cuenta?->nombre_cuenta,
            'origen_detalle' => Canales::label($conversacion->canal()),
            'handle'         => $conversacion->usuario_externo,
            'enlace_externo' => $contexto['permalink'] ?? null,
            // De qué publicación salió el comentario. Para un directo no hay publicación, y la
            // pantalla no pinta la caja.
            'contexto'       => Canales::esComentario($conversacion->canal())
                ? [
                    'titulo' => 'Comentario en una publicación',
                    'texto'  => $contexto['texto_publicacion'] ?? null,
                    'enlace' => $contexto['permalink'] ?? null,
                ]
                : null,
        ];
    }
}
