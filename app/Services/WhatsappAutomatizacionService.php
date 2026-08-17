<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\CrmEtapa;
use App\Models\CrmLead;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappNumero;
use Illuminate\Support\Facades\Log;
use App\Services\LeadEntranteService;

/**
 * Qué pasa cuando un desconocido escribe por WhatsApp.
 *
 * Antes: se guardaba la conversación y nada más. Nadie se enteraba, el mensaje
 * no llegaba al CRM y el cliente se quedaba sin respuesta hasta que alguien
 * abriera la bandeja por su cuenta. Es justo lo que el resto del sistema evita.
 *
 * Ahora, y todo configurable desde la pantalla:
 *  1. Avisa por la campanita.
 *  2. Responde automáticamente con los mensajes que se hayan escrito.
 *  3. Crea el lead en el CRM y lo reparte, con el mismo mecanismo de los
 *     formularios web (fijo / round robin / ponderado).
 *
 * **El dueño del número manda sobre el reparto.** Si la línea tiene un usuario
 * asignado, el aviso y el lead son suyos: le escribieron a SU número. El reparto
 * configurado solo entra cuando el número no tiene dueño, que es el caso normal
 * del número central de la empresa.
 *
 * **Solo actúa con desconocidos.** Si el número ya es de un cliente registrado
 * o ya tiene un lead abierto, no crea nada: si no, el CRM se llenaría de leads
 * repetidos cada vez que un cliente escribe para preguntar por su pedido.
 */
class WhatsappAutomatizacionService
{
    public function __construct(
        private readonly NotificacionService $notificaciones,
    ) {}

    // ─── Configuración ────────────────────────────────────────────────────────

    public static function config(): array
    {
        return [
            'activo'            => Configuracion::get('whatsapp_auto_activo', '0') === '1',
            'avisar'            => Configuracion::get('whatsapp_auto_avisar', '1') === '1',
            'responder'         => Configuracion::get('whatsapp_auto_responder', '0') === '1',
            'respuestas'        => static::lista('whatsapp_auto_respuestas'),
            'crear_lead'        => Configuracion::get('whatsapp_auto_crear_lead', '0') === '1',
            'lead_etapa_id'     => (int) Configuracion::get('whatsapp_auto_lead_etapa_id', 0),
            'asignacion'        => Configuracion::get('whatsapp_auto_asignacion', 'fijo'),
            'responsables'      => static::lista('whatsapp_auto_responsables'),
        ];
    }

    /**
     * Configuracion::get solo decodifica JSON si la clave está marcada con
     * tipo 'json', y set() no marca el tipo. Se decodifica acá para que a la
     * pantalla siempre le llegue un arreglo, venga como venga de la base.
     */
    private static function lista(string $clave): array
    {
        $valor = Configuracion::get($clave, []);

        if (is_array($valor)) {
            return $valor;
        }

        $decodificado = json_decode((string) $valor, true);

        return is_array($decodificado) ? $decodificado : [];
    }

    // ─── Punto de entrada ─────────────────────────────────────────────────────

    /**
     * Se llama por cada mensaje entrante. `$esNueva` indica si la conversación
     * acababa de crearse: las respuestas de bienvenida y el lead solo salen en
     * el primer contacto, no en cada mensaje.
     */
    public function alRecibirMensaje(
        WhatsappNumero $numero,
        WhatsappConversacion $conversacion,
        string $texto,
        bool $esNueva
    ): void {
        $cfg = static::config();

        if (! $cfg['activo']) {
            return;
        }

        // Cada paso va aparte: que falle el aviso no puede impedir que se cree
        // el lead, ni que un error del CRM deje al cliente sin respuesta.
        try {
            if ($cfg['avisar'] && $esNueva) {
                $this->avisar($numero, $conversacion);
            }
        } catch (\Throwable $e) {
            Log::error('WhatsApp automatización: falló el aviso', ['error' => $e->getMessage()]);
        }

        try {
            if ($cfg['responder']) {
                $this->responder($numero, $conversacion, $texto, $esNueva, $cfg['respuestas']);
            }
        } catch (\Throwable $e) {
            Log::error('WhatsApp automatización: falló la respuesta automática', ['error' => $e->getMessage()]);
        }

        try {
            if ($cfg['crear_lead'] && $esNueva) {
                $this->crearLeadSiHaceFalta($numero, $conversacion, $texto, $cfg);
            }
        } catch (\Throwable $e) {
            Log::error('WhatsApp automatización: falló la creación del lead', ['error' => $e->getMessage()]);
        }
    }

    // ─── 1. Aviso ─────────────────────────────────────────────────────────────

    /**
     * Si el número tiene un dueño, el aviso es suyo y de nadie más: le
     * escribieron a SU línea. Avisarle a todo el rol convierte cada mensaje en
     * ruido para gente que no lo puede atender, y hace que nadie se sienta
     * responsable de contestarlo.
     *
     * Sin dueño —el número central, o uno recién creado— se cae al rol, que es
     * lo que había antes.
     */
    private function avisar(WhatsappNumero $numero, WhatsappConversacion $conversacion): void
    {
        $quien = $conversacion->nombre_contacto ?: $conversacion->numero_contacto;
        $dueno = $this->duenoDelNumero($numero);

        if ($dueno) {
            $this->notificaciones->crear($dueno, 'whatsapp_mensaje_nuevo',
                'Mensaje nuevo de WhatsApp',
                "{$quien} escribió a tu línea ({$numero->nombre}).",
                '/whatsapp'
            );

            return;
        }

        $this->notificaciones->paraRol('vendedor', 'whatsapp_mensaje_nuevo',
            'Mensaje nuevo de WhatsApp',
            "{$quien} escribió por WhatsApp.",
            '/whatsapp'
        );
    }

    /**
     * El usuario asignado al número, solo si sigue activo. Mandarle avisos a
     * alguien que ya no entra al sistema es peor que no asignar: el mensaje
     * queda con dueño y sin atender.
     */
    private function duenoDelNumero(WhatsappNumero $numero): ?int
    {
        if (! $numero->usuario_id) {
            return null;
        }

        $usuario = $numero->usuario;

        return ($usuario && $usuario->activo) ? $usuario->id : null;
    }

    // ─── 2. Respuestas automáticas ────────────────────────────────────────────

    /**
     * Las respuestas son una lista. Cada una puede llevar una palabra clave:
     *  - Sin palabra clave  → es de bienvenida: sale solo en el primer contacto.
     *  - Con palabra clave  → sale cuando el mensaje la contiene, siempre.
     *
     * Así se puede tener un saludo y además respuestas a preguntas repetidas
     * ("horario", "precio", "dirección") sin programar nada.
     */
    private function responder(
        WhatsappNumero $numero,
        WhatsappConversacion $conversacion,
        string $texto,
        bool $esNueva,
        array $respuestas
    ): void {
        $textoNormalizado = mb_strtolower(trim($texto));
        $whatsapp = app(WhatsAppService::class);

        // Si el agente de IA está encendido, contesta él: entiende la pregunta
        // en vez de comparar palabras. Los mensajes fijos quedan como respaldo
        // para cuando la IA no conteste — dejar a alguien sin respuesta es peor
        // que mandarle algo genérico.
        $agente = app(\App\Services\IA\AgentePublicoService::class);

        if ($agente->activo()) {
            $respuestaIa = $agente->responder($texto, $this->historial($conversacion));

            if ($respuestaIa !== null) {
                $whatsapp->enviarMensaje($numero, $conversacion->numero_contacto, $respuestaIa);
                return;
            }

            Log::warning('Agente público: sin respuesta, se usan los mensajes fijos.');
        }

        foreach ($respuestas as $r) {
            $mensaje = trim((string) ($r['mensaje'] ?? ''));
            $clave   = mb_strtolower(trim((string) ($r['palabra_clave'] ?? '')));

            if ($mensaje === '') {
                continue;
            }

            $aplica = $clave === ''
                ? $esNueva                                   // bienvenida
                : str_contains($textoNormalizado, $clave);   // por palabra clave

            if (! $aplica) {
                continue;
            }

            $whatsapp->enviarMensaje($numero, $conversacion->numero_contacto, $mensaje);

            // Una sola respuesta por mensaje entrante: encadenar varias seguidas
            // se ve como spam y Meta lo penaliza.
            return;
        }
    }

    // ─── 3. Lead en el CRM ────────────────────────────────────────────────────

    private function crearLeadSiHaceFalta(WhatsappNumero $numero, WhatsappConversacion $conversacion, string $texto, array $cfg): void
    {
        $telefono = $conversacion->numero_contacto;

        if ($this->yaEsConocido($telefono)) {
            return;
        }

        $etapaId = $cfg['lead_etapa_id'] ?: CrmEtapa::where('activa', true)->orderBy('orden')->value('id');

        if (! $etapaId) {
            Log::warning('WhatsApp automatización: no hay etapa de CRM donde poner el lead.');
            return;
        }

        $nombre = $conversacion->nombre_contacto ?: $telefono;

        $resultado = app(LeadEntranteService::class)->registrar([
            'canal'          => 'whatsapp',
            'nombre'         => $conversacion->nombre_contacto,
            'telefono'       => $telefono,
            'mensaje'        => $texto,
            'etapa_id'       => $etapaId,
            'responsable_id' => $this->resolverResponsable($numero, $cfg),
            // El identificador de la conversación evita registrar dos veces el
            // mismo contacto si el webhook de WhatsApp se repite, que pasa.
            'referencia_externa' => 'conv-' . $conversacion->id,
            'avisar'         => false,
        ]);

        $lead = $resultado['lead'];

        // Si el lead quedó sin responsable (no hay nadie configurado en el
        // reparto), el aviso va al rol para que no se quede sin dueño y sin
        // que nadie se entere.
        if ($lead->responsable_id) {
            $this->notificaciones->crear(
                $lead->responsable_id,
                'lead_nuevo',
                'Lead nuevo por WhatsApp',
                "{$nombre} escribió por WhatsApp y se creó un lead.",
                "/crm"
            );
        } else {
            $this->notificaciones->paraRol(['administrador', 'vendedor'], 'lead_nuevo',
                'Lead nuevo por WhatsApp SIN asignar',
                "{$nombre} escribió por WhatsApp. El lead quedó sin responsable: revisa el reparto.",
                '/crm'
            );
        }

        // Aviso hacia afuera, para quien quiera engancharse. Va al final y en
        // su propio servicio: si el destino externo falla, el lead ya quedó
        // creado y avisado por dentro.
        app(\App\Services\IA\AgenteWebhookService::class)->disparar('lead_creado', [
            'lead_id'   => $lead->id,
            'nombre'    => $nombre,
            'telefono'  => $telefono,
            'canal'     => 'whatsapp',
            'mensaje'   => $texto,
            'responsable_id' => $lead->responsable_id,
        ]);
    }

    /**
     * ¿El número ya es de un cliente registrado o tiene un lead abierto?
     * Se comparan solo los dígitos: en la base los teléfonos vienen escritos de
     * mil formas (con +57, con espacios, con guiones) y WhatsApp los manda sin
     * nada de eso.
     */
    private function yaEsConocido(string $telefono): bool
    {
        $digitos = preg_replace('/\D+/', '', $telefono);

        if ($digitos === '') {
            return false;
        }

        // Se compara por los últimos 10 dígitos: así "573001234567" y
        // "3001234567" se reconocen como el mismo número.
        $cola = substr($digitos, -10);

        $esCliente = Cliente::whereRaw(
            "REPLACE(REPLACE(REPLACE(COALESCE(celular, telefono), ' ', ''), '-', ''), '+', '') LIKE ?",
            ["%{$cola}"]
        )->exists();

        if ($esCliente) {
            return true;
        }

        return CrmLead::where('estado', 'activo')
            ->whereRaw(
                "REPLACE(REPLACE(REPLACE(COALESCE(telefono_contacto, ''), ' ', ''), '-', ''), '+', '') LIKE ?",
                ["%{$cola}"]
            )
            ->exists();
    }

    /**
     * A quién le queda el lead.
     *
     * Primero manda el dueño del número: si un cliente escribió a la línea de
     * un asesor, la venta es de ese asesor, y repartirla por rotación se la
     * quitaría delante de él.
     *
     * Si el número no tiene dueño se usa el reparto configurado, que es el
     * mismo de los formularios web: fijo o rotación. Se reutiliza el criterio
     * para no tener dos formas distintas de asignar leads en el mismo sistema.
     */
    private function resolverResponsable(WhatsappNumero $numero, array $cfg): ?int
    {
        if ($dueno = $this->duenoDelNumero($numero)) {
            return $dueno;
        }

        $ids = array_values(array_filter($cfg['responsables'] ?? []));

        if (empty($ids)) {
            return null;
        }

        if ($cfg['asignacion'] === 'fijo') {
            return (int) $ids[0];
        }

        $indice = (int) Configuracion::get('whatsapp_auto_round_robin', 0);
        Configuracion::set('whatsapp_auto_round_robin', (string) ($indice + 1));

        return (int) $ids[$indice % count($ids)];
    }
}
