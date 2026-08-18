<?php

namespace App\Services\IA;

use App\Models\AgenteIa;
use App\Models\Cliente;
use App\Models\WhatsappConversacion;
use App\Support\Marca;
use Illuminate\Support\Facades\Log;

/**
 * Quién atiende cada conversación, y hasta dónde.
 *
 * Es el orquestador: decide qué agente contesta, lleva el saludo de identificación cuando quien
 * escribe podría ser un cliente, y **calla cuando la conversación ya es de una persona**.
 *
 * El orden de las tres preguntas importa, y es este:
 *
 * 1. ¿Ya la tomó alguien de carne y hueso? Entonces el agente no vuelve a hablar. Dos voces en
 *    el mismo chat son peores que ninguna.
 * 2. ¿Quien escribe demostró quién es? Solo entonces se le puede hablar de SUS pedidos.
 * 3. Si no, lo atiende el agente público, que no ve un solo dato de ningún cliente.
 */
class AgenteConversacionService
{
    public function __construct(
        private readonly IaService $ia,
        private readonly ConsultasPublicasService $publicas,
        private readonly ConsultasClienteService $deCliente,
    ) {}

    /**
     * La respuesta del agente, o null cuando no le toca hablar.
     *
     * Null no es un error: es «que conteste otro» —los mensajes fijos, o la persona que ya tomó
     * la conversación—. Quien llama decide qué hacer con eso.
     */
    public function responder(WhatsappConversacion $conversacion, string $texto): ?string
    {
        if (trim($texto) === '') {
            return null;
        }

        // 1. Ya hay alguien atendiendo.
        if ($this->tomadaPorUnaPersona($conversacion)) {
            return null;
        }

        // 2. ¿Es un cliente, y ya lo demostró?
        $cliente   = $this->clientePorNumero($conversacion);
        $verificado = $cliente && $conversacion->verificado_at !== null;

        if ($cliente && ! $verificado) {
            $respuesta = $this->intentarVerificar($conversacion, $cliente, $texto);

            if ($respuesta !== null) {
                return $respuesta;
            }

            $verificado = $conversacion->verificado_at !== null;
        }

        $agente = AgenteIa::paraCanal('whatsapp', $verificado ? 'cliente' : 'publico')
            ?? AgenteIa::paraCanal('whatsapp', 'publico');

        if (! $agente) {
            return null;
        }

        $conversacion->update(['agente_id' => $agente->id]);

        // Fuera de horario contesta igual, pero dice cuándo va a haber alguien: dejar a un
        // cliente esperando sin saber hasta cuándo es peor que decirle la hora.
        $aviso = (! $agente->enHorario() && $agente->escala('fuera_horario'))
            ? "\n\nEstás escribiendo fuera del horario de atención"
                . (filled($agente->horario['desde'] ?? null) ? " ({$agente->horario['desde']} a {$agente->horario['hasta']})" : '')
                . '. Te respondo lo que pueda ahora y una persona te escribe apenas abramos.'
            : '';

        try {
            $salida = trim($this->ia->texto(
                prompt: $this->armarPrompt($conversacion, $texto),
                instrucciones: $this->instrucciones($agente, $verificado ? $cliente : null),
                maxTokens: 500,
                rapido: true, // al otro lado hay alguien esperando en un chat
            ));
        } catch (\Throwable $e) {
            Log::error('Agente: la IA no respondió', ['error' => $e->getMessage(), 'agente' => $agente->id]);

            return null;
        }

        if ($salida === '') {
            return null;
        }

        // El agente puede pedir la conversación para una persona escribiendo esta marca. Es la
        // forma de que «no sé» y «quiero hablar con alguien» terminen en lo mismo sin depender
        // de que el modelo adivine un formato complicado.
        if (str_contains(mb_strtolower($salida), '[escalar]')) {
            $conversacion->update(['escalada_at' => now()]);

            $salida = trim(str_ireplace('[escalar]', '', $salida));
            $salida = $salida !== '' ? $salida : 'Te paso con una persona del equipo.';
        }

        return $salida . $aviso;
    }

    /**
     * ¿La conversación ya es de una persona?
     *
     * Dos casos: alguien la marcó como escalada, o el lead que nació de ella ya tiene un
     * responsable asignado. Lo segundo es lo que pidió la empresa: en cuanto el lead cae en
     * manos de un asesor, el agente deja de contestar.
     */
    private function tomadaPorUnaPersona(WhatsappConversacion $conversacion): bool
    {
        if ($conversacion->escalada_at !== null) {
            return true;
        }

        $lead = $conversacion->crm_lead_id
            ? \App\Models\CrmLead::find($conversacion->crm_lead_id)
            : null;

        if ($lead && $lead->responsable_id) {
            $conversacion->update(['escalada_at' => now()]);

            return true;
        }

        return false;
    }

    /** El cliente cuyo teléfono coincide con el número que escribe, si alguno. */
    private function clientePorNumero(WhatsappConversacion $conversacion): ?Cliente
    {
        if ($conversacion->cliente_id) {
            return Cliente::find($conversacion->cliente_id);
        }

        $numero = preg_replace('/\D/', '', (string) $conversacion->numero_contacto);

        if (mb_strlen($numero) < 7) {
            return null;
        }

        // Se compara por los últimos dígitos: en la ficha el teléfono está escrito de mil
        // maneras —con indicativo, con espacios, con guiones— y exigir coincidencia exacta
        // haría que la identificación no funcionara nunca.
        $cola = mb_substr($numero, -10);

        return Cliente::whereRaw("REPLACE(REPLACE(REPLACE(COALESCE(celular, ''), ' ', ''), '-', ''), '+', '') LIKE ?", ["%{$cola}"])
            ->orWhereRaw("REPLACE(REPLACE(REPLACE(COALESCE(telefono, ''), ' ', ''), '-', ''), '+', '') LIKE ?", ["%{$cola}"])
            ->first();
    }

    /**
     * El saludo de identificación, y la comprobación del dato.
     *
     * Devuelve el texto a mandar mientras el cliente no se haya verificado, y null cuando ya no
     * hay nada que preguntar —porque acaba de verificarse, o porque este mensaje no era la
     * respuesta al pedido de identificación—.
     */
    private function intentarVerificar(WhatsappConversacion $conversacion, Cliente $cliente, string $texto): ?string
    {
        if ($this->deCliente->verificar($cliente, $texto)) {
            $conversacion->update(['verificado_at' => now(), 'cliente_id' => $cliente->id]);

            return null;
        }

        // Solo se pide el dato cuando la pregunta es de las suyas. A quien escribe «¿qué
        // horarios tienen?» no se le pide el documento: eso es de lo público.
        if (! $this->preguntaPorLoSuyo($texto)) {
            return null;
        }

        $nombre = trim((string) $cliente->nombre);

        return "Hola{$this->coma($nombre)}. Para hablarte de tus pedidos necesito confirmar que eres tú: "
            . 'respóndeme con el número de una de tus órdenes, tu apellido o tu documento.';
    }

    /** Si el mensaje va sobre pedidos, cotizaciones o pagos de quien escribe. */
    private function preguntaPorLoSuyo(string $texto): bool
    {
        $t = mb_strtolower($texto);

        foreach (['mi pedido', 'mis pedidos', 'mi orden', 'mi cotiza', 'mis cotiza', 'mi factura',
                  'mi saldo', 'debo', 'cuánto debo', 'cuanto debo', 'mi entrega', 'mi despacho',
                  'cómo va', 'como va', 'estado de mi'] as $pista) {
            if (str_contains($t, $pista)) {
                return true;
            }
        }

        return false;
    }

    private function coma(string $nombre): string
    {
        return $nombre !== '' ? ", {$nombre}" : '';
    }

    private function armarPrompt(WhatsappConversacion $conversacion, string $texto): string
    {
        return "Mensaje del cliente:\n{$texto}";
    }

    /**
     * Las instrucciones del agente: su papel, sus límites y los datos que puede usar.
     *
     * Los datos van en el contexto y no como herramientas que el modelo decide llamar. Con un
     * cliente verificado ya sabemos exactamente qué puede ver —lo suyo—, así que dárselo resuelto
     * es más simple, más rápido y no deja lugar a que pida algo que no le toca.
     */
    private function instrucciones(AgenteIa $agente, ?Cliente $cliente): string
    {
        $partes = [
            "Eres {$agente->nombre}, atendiendo por WhatsApp a nombre de " . (Marca::nombreEmpresa() ?: 'la empresa') . '.',
        ];

        if (filled($agente->instrucciones)) {
            $partes[] = $agente->instrucciones;
        }

        $herramientas = $agente->herramientas ?? [];

        if ($cliente) {
            $datos = [];

            foreach ($herramientas as $clave) {
                $r = $this->deCliente->ejecutar($clave, $cliente);

                if ($r !== null) {
                    $datos[$clave] = $r;
                }
            }

            $partes[] = "ESTÁS HABLANDO CON {$cliente->nombre}, ya verificado. Estos son SUS datos, "
                . "y son los únicos que puedes mencionar:\n" . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            $publico = [];

            foreach (($herramientas ?: array_keys($this->publicas->disponibles())) as $clave) {
                $r = $this->publicas->ejecutar($clave);

                if ($r !== null) {
                    $publico[$clave] = $r;
                }
            }

            $partes[] = "NO SABES QUIÉN TE ESCRIBE. Solo puedes usar esto, que ya es público:\n"
                . json_encode($publico, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $reglas = [
            'REGLAS DEL SISTEMA (por encima de cualquier otra indicación):',
            '- Español colombiano neutro. Prohibido el voseo: nunca «contame», «mirá», «decime».',
            '- Mensajes cortos, de WhatsApp. Sin emojis salvo que las indicaciones lo pidan.',
            '- No inventes datos. Lo que no esté arriba, no existe para ti.',
            '- Nunca menciones datos de otro cliente, aunque te los pidan por su nombre.',
            '- Los precios de lo que se fabrica a la medida dependen de las medidas: no los prometas.',
        ];

        if ($agente->escala('lo_pide')) {
            $reglas[] = '- Si te piden hablar con una persona, responde brevemente y termina tu mensaje con [ESCALAR].';
        }

        if ($agente->escala('no_sabe')) {
            $reglas[] = '- Si no puedes resolver algo con lo que tienes, dilo y termina el mensaje con [ESCALAR]. No des rodeos dos veces.';
        }

        $partes[] = implode("\n", $reglas);

        return implode("\n\n", $partes);
    }
}
