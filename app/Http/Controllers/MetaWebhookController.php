<?php

namespace App\Http\Controllers;

use App\Models\BandejaConversacion;
use App\Models\BandejaMensaje;
use App\Models\CuentaRrss;
use App\Services\Bandeja\AdjuntoBandeja;
use App\Services\Bandeja\BandejaAutomatizacionService;
use App\Services\Rrss\MetaMensajeriaService;
use App\Support\Canales;
use App\Support\CredencialesRrss;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Lo que llega de Instagram y Facebook: mensajes directos y comentarios.
 *
 * **Una sola dirección para las dos redes y los cuatro canales**, porque Meta manda todo por el
 * mismo webhook de la misma aplicación: lo que cambia es el `object` del cuerpo (`page` o
 * `instagram`) y el campo que cambió. Montar cuatro direcciones habría obligado a configurar
 * cuatro en Meta y a que un error de configuración apagara un canal en silencio.
 *
 * **Nunca lanza excepción y siempre responde 200 rápido.** Meta reintenta el webhook cuando no
 * recibe respuesta a tiempo, y un error que escape se traduce en el mismo comentario entrando
 * cuatro veces al CRM. Lo que falle se anota y se sigue.
 *
 * La firma se comprueba igual que en WhatsApp —es la misma aplicación y el mismo App Secret— y
 * sin ella no entra nada: este endpoint no tiene login y lo que entra por aquí crea leads que
 * se reparten solos entre los vendedores.
 */
class MetaWebhookController extends Controller
{
    public function __construct(
        private readonly MetaMensajeriaService $meta,
        private readonly AdjuntoBandeja $adjuntos,
        private readonly BandejaAutomatizacionService $automatizacion,
    ) {
    }

    /**
     * La verificación de Meta al suscribirse.
     *
     * Usa el mismo token de verificación que WhatsApp, a propósito: es la misma aplicación de
     * Meta, y pedir dos contraseñas distintas para dos webhooks de la misma app es una más que
     * olvidar.
     */
    public function verify(Request $peticion): Response
    {
        $esperado = CredencialesRrss::valor('whatsapp', 'redirect');
        $recibido = (string) $peticion->query('hub_verify_token');

        if ($peticion->query('hub_mode') !== 'subscribe' || $esperado === '' || ! hash_equals($esperado, $recibido)) {
            return response('Forbidden', 403);
        }

        return response((string) $peticion->query('hub_challenge'), 200);
    }

    public function receive(Request $peticion): Response
    {
        if (! $this->firmaValida($peticion)) {
            return response('Forbidden', 403);
        }

        try {
            $this->procesar($peticion->all());
        } catch (\Throwable $e) {
            // Meta reintenta lo que no recibe 200, así que un error que escape multiplica el
            // mismo mensaje. Se anota y se le dice que sí.
            Log::error('Webhook de Meta: error al procesar.', [
                'error'   => $e->getMessage(),
                'payload' => $peticion->all(),
            ]);
        }

        return response('OK', 200);
    }

    /**
     * Mismo mecanismo que el webhook de WhatsApp: HMAC-SHA256 del cuerpo con el App Secret.
     *
     * En `local` se deja pasar sin App Secret, porque ahí esto se prueba a mano con un cliente
     * que no sabe firmar. Fuera de `local` no hay excusa: Meta siempre firma.
     */
    private function firmaValida(Request $peticion): bool
    {
        $secreto = CredencialesRrss::valor('meta', 'secret');

        if ($secreto === '') {
            if (app()->environment('local')) {
                Log::warning('Webhook de Meta aceptado sin verificar: no hay App Secret y el entorno es local.');

                return true;
            }

            Log::error(
                'Webhook de Meta rechazado: falta el App Secret, así que no se puede comprobar que '
                . 'el mensaje venga de Meta. Se carga en Redes Sociales → Cuentas.'
            );

            return false;
        }

        $recibida = (string) $peticion->header('X-Hub-Signature-256');

        if ($recibida === '') {
            Log::warning('Webhook de Meta rechazado: llegó sin la cabecera de firma.');

            return false;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $peticion->getContent(), $secreto), $recibida);
    }

    // ─── El reparto ──────────────────────────────────────────────────────────

    /**
     * Meta mete dos formas distintas en el mismo cuerpo, y hay que distinguirlas:
     *
     * - `entry[].messaging[]` → mensajes directos.
     * - `entry[].changes[]`   → comentarios (y todo lo demás del muro, que no nos interesa).
     *
     * El `object` del nivel de arriba dice de qué red es: `instagram` o `page`.
     *
     * @param  array<string, mixed>  $cuerpo
     */
    private function procesar(array $cuerpo): void
    {
        $red = ($cuerpo['object'] ?? '') === 'instagram' ? 'instagram' : 'facebook';

        foreach ($cuerpo['entry'] ?? [] as $entrada) {
            foreach ($entrada['messaging'] ?? [] as $evento) {
                $this->procesarDirecto($red, $entrada, $evento);
            }

            foreach ($entrada['changes'] ?? [] as $cambio) {
                $this->procesarCambio($red, $entrada, $cambio);
            }
        }
    }

    // ─── Mensajes directos ───────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $entrada
     * @param  array<string, mixed>  $evento
     */
    private function procesarDirecto(string $red, array $entrada, array $evento): void
    {
        $canal = $red === 'instagram' ? 'instagram_dm' : 'facebook_dm';

        if (! Canales::activo($canal)) {
            return;
        }

        $mensaje = $evento['message'] ?? null;

        // Las confirmaciones de entrega y de lectura llegan por el mismo sitio y no son
        // mensajes. Sin este corte, cada «visto» del cliente creaba un renglón vacío.
        if (! $mensaje) {
            return;
        }

        // `is_echo` marca lo que mandó la propia página: lo que sale de la bandeja vuelve por
        // aquí, y también lo que alguien escribió desde la app de Instagram en su celular. Lo
        // primero ya está guardado; lo segundo hay que guardarlo para que el hilo esté
        // completo. El identificador del mensaje distingue los dos casos más abajo.
        $esEcho = (bool) ($mensaje['is_echo'] ?? false);

        // En un eco, el «otro» es el destinatario; en un mensaje normal, el remitente.
        $persona = $esEcho
            ? ($evento['recipient']['id'] ?? null)
            : ($evento['sender']['id'] ?? null);

        // La página o cuenta de Instagram que recibió. `entry.id` es lo que Meta garantiza en
        // los dos casos; el `recipient` de un eco es la persona, no la cuenta.
        $cuentaExterna = $esEcho
            ? ($evento['sender']['id'] ?? ($entrada['id'] ?? null))
            : ($evento['recipient']['id'] ?? ($entrada['id'] ?? null));

        if (! $persona) {
            return;
        }

        $cuenta = $this->cuentaDe($red, [$cuentaExterna, $entrada['id'] ?? null]);

        if (! $cuenta) {
            Log::warning('Webhook de Meta: mensaje para una cuenta que no está conectada.', [
                'red'    => $red,
                'cuenta' => $cuentaExterna,
            ]);

            return;
        }

        [$conversacion, $esNueva] = $this->conversacion($canal, $cuenta, (string) $persona);

        $externoId = $mensaje['mid'] ?? null;

        if ($externoId && BandejaMensaje::where('externo_id', $externoId)->exists()) {
            return;
        }

        $texto    = $mensaje['text'] ?? null;
        $adjuntos = $mensaje['attachments'] ?? [];
        $archivo  = $this->bajarPrimerAdjunto($adjuntos, $conversacion);

        BandejaMensaje::create([
            'bandeja_conversacion_id' => $conversacion->id,
            'externo_id' => $externoId,
            'direccion'  => $esEcho ? 'saliente' : 'entrante',
            'tipo'       => $this->tipoDeAdjuntos($adjuntos, $texto),
            'contenido'  => $texto ?: $this->describirAdjuntos($adjuntos),
            'archivo_id' => $archivo?->id,
            'estado'     => $esEcho ? 'enviado' : null,
            'es_echo'    => $esEcho,
        ]);

        $conversacion->registrarActividad($esEcho ? 'saliente' : 'entrante');

        if ($esEcho) {
            return;
        }

        $this->automatizacion->alRecibir($conversacion, (string) $texto, $esNueva);
    }

    // ─── Comentarios ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $entrada
     * @param  array<string, mixed>  $cambio
     */
    private function procesarCambio(string $red, array $entrada, array $cambio): void
    {
        $campo = $cambio['field'] ?? '';
        $valor = $cambio['value'] ?? [];

        // Instagram manda los comentarios en `comments`; Facebook los mete en `feed` junto con
        // las reacciones, las publicaciones y todo lo demás del muro.
        $esComentario = $campo === 'comments'
            || ($campo === 'feed' && ($valor['item'] ?? '') === 'comment');

        if (! $esComentario) {
            return;
        }

        // Un comentario borrado o editado llega por el mismo campo. Solo nos interesa el nuevo.
        if (($valor['verb'] ?? 'add') !== 'add') {
            return;
        }

        $canal = $red === 'instagram' ? 'instagram_comentario' : 'facebook_comentario';

        if (! Canales::activo($canal)) {
            return;
        }

        $comentarioId = $valor['comment_id'] ?? $valor['id'] ?? null;
        $texto        = $valor['message'] ?? $valor['text'] ?? null;
        $quien        = $valor['from'] ?? [];

        if (! $comentarioId) {
            return;
        }

        $cuenta = $this->cuentaDe($red, [$entrada['id'] ?? null]);

        if (! $cuenta) {
            Log::warning('Webhook de Meta: comentario en una cuenta que no está conectada.', ['red' => $red]);

            return;
        }

        // Un comentario de la propia página es nuestra respuesta volviendo. Sin este corte, el
        // agente de IA se contestaba a sí mismo en bucle, en público.
        if ((string) ($quien['id'] ?? '') === (string) $cuenta->cuenta_id_externo) {
            return;
        }

        if (BandejaMensaje::where('externo_id', $comentarioId)->exists()) {
            return;
        }

        // El hilo se agrupa por el comentario raíz: una conversación de cinco respuestas es UNA
        // conversación, no cinco. `parent_id` apunta al comentario al que se respondió.
        $raiz = $valor['parent_id'] ?? $comentarioId;

        [$conversacion, $esNueva] = $this->conversacion($canal, $cuenta, (string) $raiz, [
            'nombre'  => $quien['name'] ?? null,
            'usuario' => $quien['username'] ?? null,
            'publicacion' => $valor['post_id'] ?? ($valor['media']['id'] ?? null),
        ]);

        BandejaMensaje::create([
            'bandeja_conversacion_id' => $conversacion->id,
            'externo_id' => $comentarioId,
            'direccion'  => 'entrante',
            'tipo'       => 'comentario',
            'contenido'  => $texto,
        ]);

        $conversacion->registrarActividad('entrante');

        $this->automatizacion->alRecibir($conversacion, (string) $texto, $esNueva);
    }

    // ─── Piezas comunes ─────────────────────────────────────────────────────

    /**
     * La cuenta conectada que corresponde a uno de estos identificadores.
     *
     * Se prueban varios porque Meta no es consistente: en un directo de Instagram la cuenta
     * viene en `recipient.id`, en un comentario en `entry.id`, y en un eco el `sender` es la
     * cuenta. Buscar por el primero que exista evita una rama por cada forma.
     *
     * @param  array<int, string|null>  $identificadores
     */
    private function cuentaDe(string $red, array $identificadores): ?CuentaRrss
    {
        $limpios = array_values(array_filter(array_map(
            fn ($id) => $id === null ? null : (string) $id,
            $identificadores,
        )));

        if ($limpios === []) {
            return null;
        }

        return CuentaRrss::where('red', $red)
            ->where('activa', true)
            ->where(function ($consulta) use ($limpios) {
                $consulta->whereIn('cuenta_id_externo', $limpios)
                    // Para Instagram, la página de Facebook ligada también identifica la cuenta.
                    ->orWhereIn('cuenta_id_secundario', $limpios);
            })
            ->first();
    }

    /**
     * La conversación de esta persona en este canal y esta cuenta. La crea si es la primera vez.
     *
     * Devuelve también si acababa de nacer, porque de eso depende si se saluda y si se crea el
     * lead. Preguntarlo después del `firstOrCreate` ya no se puede.
     *
     * @param  array{nombre?: ?string, usuario?: ?string, publicacion?: ?string}  $datos
     * @return array{0: BandejaConversacion, 1: bool}
     */
    private function conversacion(string $canal, CuentaRrss $cuenta, string $externoId, array $datos = []): array
    {
        $existente = BandejaConversacion::where('canal', $canal)
            ->where('cuenta_rrss_id', $cuenta->id)
            ->where('externo_id', $externoId)
            ->first();

        if ($existente) {
            // El nombre puede llegar después: en el primer mensaje Meta a veces no lo manda.
            if (! $existente->nombre_contacto && filled($datos['nombre'] ?? null)) {
                $existente->update(['nombre_contacto' => $datos['nombre']]);
            }

            return [$existente, false];
        }

        $conversacion = BandejaConversacion::create([
            'canal'           => $canal,
            'cuenta_rrss_id'  => $cuenta->id,
            'externo_id'      => $externoId,
            'nombre_contacto' => $datos['nombre'] ?? null,
            'usuario_externo' => $datos['usuario'] ?? null,
            'leido'           => false,
        ]);

        $this->completarContexto($conversacion, $cuenta, $datos);

        return [$conversacion->fresh(), true];
    }

    /**
     * Le pregunta a Meta lo que el webhook no trae: el nombre de quien escribe un directo, y de
     * qué publicación salió un comentario.
     *
     * Va aparte y en silencio si falla: una conversación sin nombre se atiende igual —aparece
     * como «Sin nombre»— y perder el mensaje por no saber cómo se llama la persona sería
     * absurdo. Solo se pregunta al crear la conversación, no en cada mensaje.
     *
     * @param  array{nombre?: ?string, usuario?: ?string, publicacion?: ?string}  $datos
     */
    private function completarContexto(BandejaConversacion $conversacion, CuentaRrss $cuenta, array $datos): void
    {
        $cambios = [];

        if (! filled($datos['nombre'] ?? null) && ! Canales::esComentario($conversacion->canal)) {
            $perfil = $this->meta->perfil($cuenta, $conversacion->externo_id);

            if (filled($perfil['nombre']))  $cambios['nombre_contacto'] = $perfil['nombre'];
            if (filled($perfil['usuario'])) $cambios['usuario_externo'] = $perfil['usuario'];
        }

        if (filled($datos['publicacion'] ?? null)) {
            $publicacion = $this->meta->publicacion($cuenta, $datos['publicacion']);

            $cambios['contexto'] = [
                'publicacion_id'    => $datos['publicacion'],
                'permalink'         => $publicacion['permalink'],
                'texto_publicacion' => $publicacion['texto'],
            ];
        }

        if ($cambios !== []) {
            $conversacion->update($cambios);
        }
    }

    /**
     * Baja el primer adjunto que traiga el mensaje.
     *
     * Solo el primero: Meta manda cada foto de un carrusel como un adjunto aparte del mismo
     * mensaje, y bajar diez archivos dentro de un webhook que tiene que responder rápido es la
     * forma de que Meta reintente y todo entre dos veces. El resto se describe en el texto.
     *
     * @param  array<int, array<string, mixed>>  $adjuntos
     */
    private function bajarPrimerAdjunto(array $adjuntos, BandejaConversacion $conversacion): ?\App\Models\Archivo
    {
        $url = $adjuntos[0]['payload']['url'] ?? null;

        if (! $url) {
            return null;
        }

        return $this->adjuntos->descargar(url: (string) $url, conversacion: $conversacion);
    }

    /** @param  array<int, array<string, mixed>>  $adjuntos */
    private function tipoDeAdjuntos(array $adjuntos, ?string $texto): string
    {
        if ($adjuntos === []) {
            return 'texto';
        }

        return match ($adjuntos[0]['type'] ?? '') {
            'image' => 'imagen',
            'video' => 'video',
            'audio' => 'audio',
            'file'  => 'documento',
            default => 'texto',
        };
    }

    /**
     * Un mensaje que es solo un adjunto no puede quedar con el contenido vacío: en la bandeja
     * se vería un renglón en blanco y nadie sabría si el cliente escribió algo.
     *
     * @param  array<int, array<string, mixed>>  $adjuntos
     */
    private function describirAdjuntos(array $adjuntos): ?string
    {
        if ($adjuntos === []) {
            return null;
        }

        $cuantos = count($adjuntos);

        $etiqueta = match ($adjuntos[0]['type'] ?? '') {
            'image'    => '📷 Foto',
            'video'    => '🎥 Video',
            'audio'    => '🎤 Audio',
            'file'     => '📄 Archivo',
            'share'    => '🔗 Enlace compartido',
            'story_mention' => '📸 Te mencionó en una historia',
            default    => '📎 Adjunto',
        };

        return $cuantos > 1 ? "{$etiqueta} (y " . ($cuantos - 1) . " más)" : $etiqueta;
    }
}
