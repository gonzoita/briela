<?php

namespace App\Services\Bandeja;

use App\Models\BandejaConversacion;
use App\Models\BandejaMensaje;
use App\Models\Configuracion;
use App\Models\CrmEtapa;
use App\Models\User;
use App\Services\IA\AgenteConversacionService;
use App\Services\LeadEntranteService;
use App\Services\NotificacionService;
use App\Services\Rrss\MetaMensajeriaService;
use App\Support\Canales;
use Illuminate\Support\Facades\Log;

/**
 * Qué pasa cuando alguien escribe por Instagram o Facebook.
 *
 * Hermana de `WhatsappAutomatizacionService`, y separada a propósito: lo que decide el reparto
 * en WhatsApp es el **dueño del número**, y en las redes no hay números ni dueños —hay una
 * cuenta de la empresa—. Juntarlas habría obligado a que cada paso preguntara de qué canal
 * viene, y a que apagar las respuestas de WhatsApp apagara también las de Instagram.
 *
 * Los tres pasos son los mismos, y van aislados por lo mismo: que falle el aviso no puede
 * impedir que se cree el lead, ni un error del CRM dejar al cliente sin respuesta.
 *
 * **Los comentarios son públicos, y eso cambia todo.** Una respuesta automática en un chat la
 * lee una persona; en un comentario la lee cualquiera que pase por la publicación, queda
 * colgada ahí y la indexa Google. Por eso contestar comentarios con el agente de IA es un
 * interruptor **aparte y apagado de fábrica**: encenderlo es una decisión de la empresa, no un
 * valor por omisión que alguien descubre cuando ya pasó.
 */
class BandejaAutomatizacionService
{
    public function __construct(
        private readonly NotificacionService $notificaciones,
        private readonly MetaMensajeriaService $meta,
        private readonly AgenteConversacionService $agentes,
    ) {
    }

    // ─── Configuración ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'activo'        => Configuracion::get('bandeja_auto_activo', '0') === '1',
            'avisar'        => Configuracion::get('bandeja_auto_avisar', '1') === '1',
            'responder'     => Configuracion::get('bandeja_auto_responder', '0') === '1',
            // Aparte y apagado de fábrica: una respuesta automática en público es otra cosa.
            'comentarios'   => Configuracion::get('bandeja_auto_comentarios', '0') === '1',
            'crear_lead'    => Configuracion::get('bandeja_auto_crear_lead', '0') === '1',
            'lead_etapa_id' => (int) Configuracion::get('bandeja_auto_lead_etapa_id', 0),
            'asignacion'    => Configuracion::get('bandeja_auto_asignacion', 'fijo'),
            'responsables'  => static::lista('bandeja_auto_responsables'),
        ];
    }

    /**
     * `Configuracion::get` solo decodifica JSON si la clave está marcada con tipo 'json', y
     * `set()` no marca el tipo. Se decodifica acá para que a la pantalla siempre le llegue un
     * arreglo, venga como venga de la base.
     *
     * @return array<int, mixed>
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

    // ─── Punto de entrada ────────────────────────────────────────────────────

    /**
     * Se llama por cada mensaje o comentario entrante.
     *
     * `$esNueva` dice si la conversación acababa de nacer: el saludo y el lead solo salen en el
     * primer contacto, no en cada mensaje.
     */
    public function alRecibir(BandejaConversacion $conversacion, string $texto, bool $esNueva): void
    {
        $cfg = static::config();

        if (! $cfg['activo']) {
            return;
        }

        try {
            if ($cfg['avisar'] && $esNueva) {
                $this->avisar($conversacion);
            }
        } catch (\Throwable $e) {
            Log::error('Bandeja automatización: falló el aviso.', ['error' => $e->getMessage()]);
        }

        try {
            if ($cfg['responder']) {
                $this->responder($conversacion, $texto, $cfg);
            }
        } catch (\Throwable $e) {
            Log::error('Bandeja automatización: falló la respuesta.', ['error' => $e->getMessage()]);
        }

        try {
            if ($cfg['crear_lead'] && $esNueva) {
                $this->crearLead($conversacion, $texto, $cfg);
            }
        } catch (\Throwable $e) {
            Log::error('Bandeja automatización: falló la creación del lead.', ['error' => $e->getMessage()]);
        }
    }

    // ─── 1. Aviso ────────────────────────────────────────────────────────────

    /**
     * El aviso va al rol de vendedores, con la conversación en el enlace.
     *
     * No hay a quién más dirigirlo: una cuenta de Instagram es de la empresa, no de una
     * persona, igual que el número central de WhatsApp. Quien la tome se la asigna desde la
     * bandeja, y desde ahí el agente de IA deja de contestar.
     */
    private function avisar(BandejaConversacion $conversacion): void
    {
        $quien = $conversacion->comoSeLlama();
        $canal = Canales::label($conversacion->canal());

        $this->notificaciones->paraRol('vendedor', 'bandeja_mensaje_nuevo',
            Canales::esComentario($conversacion->canal())
                ? "Comentario nuevo en {$canal}"
                : "Mensaje nuevo por {$canal}",
            "{$quien} escribió por {$canal}.",
            '/bandeja?conv=' . $conversacion->claveBandeja(),
        );
    }

    // ─── 2. Respuesta ────────────────────────────────────────────────────────

    /**
     * Contesta con el agente de IA, o con los mensajes fijos si la IA no contesta.
     *
     * **Nunca contesta una conversación que ya tomó una persona**: dos voces en el mismo chat
     * son peores que ninguna, y en un comentario público son peores todavía.
     *
     * @param  array<string, mixed>  $cfg
     */
    private function responder(BandejaConversacion $conversacion, string $texto, array $cfg): void
    {
        if ($conversacion->escalada_at !== null || $conversacion->asignado_a !== null) {
            return;
        }

        $esComentario = Canales::esComentario($conversacion->canal());

        // Contestar en público es una decisión aparte, y está apagada de fábrica.
        if ($esComentario && ! $cfg['comentarios']) {
            return;
        }

        if (trim($texto) === '') {
            return;
        }

        $respuesta = $this->agentes->responderPublico(
            canal: Canales::red($conversacion->canal()) ?? 'web',
            texto: $texto,
            historial: $this->historial($conversacion),
        );

        if ($respuesta === null) {
            return;
        }

        // El envío pasa por el mismo adaptador que usa una persona desde la bandeja, para que
        // la respuesta del agente quede guardada igual y el hilo se lea completo.
        try {
            $cuenta = $conversacion->cuenta;

            if (! $cuenta || ! $cuenta->activa) {
                return;
            }

            $externoId = $esComentario
                ? $this->meta->responderComentario($cuenta, $conversacion->externo_id, $respuesta, $conversacion->canal())
                : $this->meta->enviarDirecto($cuenta, $conversacion->externo_id, $respuesta);

            BandejaMensaje::create([
                'bandeja_conversacion_id' => $conversacion->id,
                'externo_id' => $externoId ?: null,
                'direccion'  => 'saliente',
                'tipo'       => $esComentario ? 'comentario' : 'texto',
                'contenido'  => $respuesta,
                'estado'     => 'enviado',
                // Sin `usuario_id`: no lo escribió una persona, y la bandeja lo muestra como
                // «Automático» para que nadie crea que un compañero contestó eso.
            ]);

            $conversacion->registrarActividad('saliente');
        } catch (\Throwable $e) {
            // Si Meta rechaza la respuesta del agente, el mensaje del cliente ya quedó
            // guardado y con su aviso: alguien lo va a atender a mano.
            Log::warning('Bandeja automatización: Meta rechazó la respuesta del agente.', [
                'conversacion' => $conversacion->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Los últimos mensajes, para que el agente no conteste como si fuera el primero.
     *
     * Seis: lo suficiente para seguir el hilo de una conversación corta, y poco para que el
     * contexto no crezca sin límite en una conversación de meses —que se paga en cada mensaje—.
     *
     * @return array<int, array{rol: string, texto: string}>
     */
    private function historial(BandejaConversacion $conversacion): array
    {
        return $conversacion->mensajes()
            ->whereNotNull('contenido')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->reverse()
            ->map(fn (BandejaMensaje $m) => [
                'rol'   => $m->direccion === 'saliente' ? 'agente' : 'cliente',
                'texto' => (string) $m->contenido,
            ])
            ->values()
            ->all();
    }

    // ─── 3. Lead ─────────────────────────────────────────────────────────────

    /**
     * Crea el lead del primer contacto.
     *
     * **Sin teléfono ni correo.** Por Instagram no llega ninguno de los dos: lo que hay es un
     * nombre y un usuario. Se registra igual, porque un lead sin teléfono al que hay que
     * contestarle por Instagram sigue siendo una oportunidad de venta; el canal y la referencia
     * externa dicen por dónde seguirlo.
     *
     * No hace falta la comprobación de «¿ya es cliente?» que sí hace WhatsApp: ahí se compara
     * por número de teléfono, y acá no hay número con el que comparar. Lo que evita el
     * duplicado es la `referencia_externa` de la conversación.
     *
     * @param  array<string, mixed>  $cfg
     */
    private function crearLead(BandejaConversacion $conversacion, string $texto, array $cfg): void
    {
        $etapaId = $cfg['lead_etapa_id'] ?: CrmEtapa::where('activa', true)->orderBy('orden')->value('id');

        if (! $etapaId) {
            Log::warning('Bandeja automatización: no hay etapa de CRM donde poner el lead.');

            return;
        }

        $canal  = Canales::red($conversacion->canal()) ?? 'rrss';
        $nombre = $conversacion->comoSeLlama();

        $resultado = app(LeadEntranteService::class)->registrar([
            'canal'    => $canal,
            'nombre'   => $conversacion->nombre_contacto ?: $conversacion->usuario_externo,
            'mensaje'  => $texto,
            'etapa_id' => $etapaId,
            'responsable_id' => $this->resolverResponsable($cfg),
            // Lo que evita registrar dos veces el mismo contacto si el webhook se repite.
            'referencia_externa' => 'bandeja-' . $conversacion->id,
            'avisar'   => false,
        ]);

        $lead = $resultado['lead'];

        $conversacion->update(['crm_lead_id' => $lead->id]);

        if ($lead->responsable_id) {
            $this->notificaciones->crear(
                $lead->responsable_id,
                'lead_nuevo',
                'Lead nuevo por ' . Canales::label($conversacion->canal()),
                "{$nombre} escribió y se creó un lead.",
                '/crm',
            );

            return;
        }

        $this->notificaciones->paraRol(['administrador', 'vendedor'], 'lead_nuevo',
            'Lead nuevo por ' . Canales::label($conversacion->canal()) . ' SIN asignar',
            "{$nombre} escribió. El lead quedó sin responsable: revisa el reparto.",
            '/crm',
        );
    }

    /**
     * A quién le queda el lead: fijo o rotando, con el mismo criterio de los formularios web.
     *
     * @param  array<string, mixed>  $cfg
     */
    private function resolverResponsable(array $cfg): ?int
    {
        $ids = array_values(array_filter($cfg['responsables'] ?? []));

        if ($ids === []) {
            return null;
        }

        // Solo quien sigue activo: dejarle el lead a alguien que ya no entra al sistema es
        // dejarlo con dueño y sin atender.
        $activos = User::whereIn('id', $ids)->where('activo', true)->pluck('id')->all();

        if ($activos === []) {
            return null;
        }

        // Se respeta el orden configurado, no el de la base: en «fijo» el primero de la lista
        // es el que la empresa puso primero.
        $ordenados = array_values(array_filter($ids, fn ($id) => in_array((int) $id, $activos, true)));

        if ($cfg['asignacion'] === 'fijo') {
            return (int) $ordenados[0];
        }

        $indice = (int) Configuracion::get('bandeja_auto_round_robin', 0);
        Configuracion::set('bandeja_auto_round_robin', (string) ($indice + 1));

        return (int) $ordenados[$indice % count($ordenados)];
    }
}
