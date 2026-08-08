<?php

namespace App\Services\IA;

use App\Models\Configuracion;
use App\Support\Marca;
use Illuminate\Support\Facades\Log;

/**
 * El agente que atiende a quien escribe SIN que sepamos todavía quién es:
 * por WhatsApp, por el chat de la web, por donde llegue.
 *
 * Solo ve lo que hay en ConsultasPublicasService — quién es la empresa, cómo
 * contactarla y qué vende. **Ni un dato de ningún cliente.** Ver el manual de
 * IA, sección "Perfiles de acceso".
 *
 * Su trabajo no es cerrar la venta: es atender bien mientras el sistema crea
 * el lead y se lo reparte a un vendedor de carne y hueso.
 */
class AgentePublicoService
{
    public function __construct(
        private readonly IaService $ia,
        private readonly ConsultasPublicasService $publicas,
    ) {}

    public static function config(): array
    {
        return [
            'activo'      => Configuracion::get('agente_publico_activo', '0') === '1',
            'nombre'      => (string) Configuracion::get('agente_publico_nombre', 'Asistente'),
            'indicaciones'=> (string) Configuracion::get('agente_publico_prompt', ''),
        ];
    }

    public function activo(): bool
    {
        return static::config()['activo'];
    }

    /**
     * Redacta la respuesta a un mensaje entrante.
     *
     * Devuelve null si el agente está apagado o si la IA no contesta: quien
     * llama debe tener siempre un camino alterno (los mensajes fijos), porque
     * dejar a alguien sin respuesta es peor que responder algo genérico.
     */
    public function responder(string $mensaje, array $historial = []): ?string
    {
        $cfg = static::config();

        if (! $cfg['activo'] || trim($mensaje) === '') {
            return null;
        }

        try {
            $respuesta = $this->ia->texto(
                $this->armarPrompt($mensaje, $historial),
                $this->instrucciones($cfg),
                400,
                true // rápido: al otro lado hay alguien esperando en un chat
            );
        } catch (\Throwable $e) {
            Log::error('Agente público: la IA no respondió', ['error' => $e->getMessage()]);
            return null;
        }

        $respuesta = trim($respuesta);

        return $respuesta !== '' ? $respuesta : null;
    }

    // ─── Prompt ───────────────────────────────────────────────────────────────

    private function instrucciones(array $cfg): string
    {
        $empresa = Marca::nombreEmpresa();
        $nombre  = $cfg['nombre'] ?: 'Asistente';

        $base = <<<TXT
        Te llamas {$nombre} y atiendes por chat a quien escribe a {$empresa}.

        Con quién hablas:
        - Es alguien de AFUERA: un posible cliente que acaba de escribir. NO es
          personal de la empresa.
        - Todavía no sabes quién es. Nunca supongas que ya es cliente.

        Cómo respondes:
        - En español colombiano neutro. PROHIBIDO el voseo: "dime", no "decime".
        - Breve: dos o tres frases. Es un chat, no un correo.
        - Cordial y directo, como una persona del equipo. Sin sonar a robot y
          sin "¡Claro!", "Por supuesto", "Espero que esto te ayude".
        - Nunca digas que eres una inteligencia artificial genérica.

        Lo que NO puedes hacer, y es lo más importante:
        - **No inventes nada.** Si no está en los datos que te pasan, no lo sabes.
        - **No des precios de productos a la medida.** Dependen de las medidas y
          los cotiza una persona. Ofrece que un asesor lo contacte.
        - **No prometas plazos de entrega, descuentos ni condiciones de pago.**
        - No pidas datos sensibles. Basta con el nombre y qué necesita.
        - Si te preguntan por un pedido, una factura o algo de una cuenta
          concreta, di que un asesor lo revisa y lo contacta. **No tienes acceso
          a esa información y no debes decir que sí la tienes.**

        Qué buscas lograr:
        - Entender qué necesita y dejarlo tranquilo de que ya lo van a atender.
        - Un asesor va a tomar la conversación: puedes decirlo con naturalidad.
        TXT;

        // Las indicaciones del negocio van al final para que pesen más que las
        // generales, pero después de los límites: esos no se negocian.
        if (trim($cfg['indicaciones']) !== '') {
            $base .= "\n\nIndicaciones propias de la empresa:\n" . trim($cfg['indicaciones']);
        }

        return $base;
    }

    private function armarPrompt(string $mensaje, array $historial): string
    {
        $datos = [
            'empresa'   => $this->publicas->ejecutar('empresa'),
            'contacto'  => $this->publicas->ejecutar('contacto'),
            'productos' => $this->publicas->ejecutar('productos', ['buscar' => $this->palabraClave($mensaje)]),
        ];

        $partes = ['DATOS DISPONIBLES (lo único que sabes):', json_encode($datos, JSON_UNESCAPED_UNICODE)];

        if ($historial) {
            $partes[] = "\nCONVERSACIÓN HASTA AHORA:";
            foreach (array_slice($historial, -6) as $h) {
                $quien = ($h['direccion'] ?? '') === 'saliente' ? 'Tú' : 'Cliente';
                $partes[] = "{$quien}: " . ($h['contenido'] ?? '');
            }
        }

        $partes[] = "\nMENSAJE NUEVO DEL CLIENTE:\n{$mensaje}";
        $partes[] = "\nResponde solo con el texto del mensaje, sin comillas ni encabezados.";

        return implode("\n", $partes);
    }

    /**
     * Saca la palabra más larga del mensaje para acotar la búsqueda de
     * productos. Es tosco a propósito: mandar el catálogo entero en cada
     * mensaje gasta tokens sin mejorar la respuesta.
     */
    private function palabraClave(string $mensaje): ?string
    {
        $palabras = preg_split('/\W+/u', mb_strtolower($mensaje), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $utiles = array_filter($palabras, fn ($p) => mb_strlen($p) >= 5);

        if (empty($utiles)) {
            return null;
        }

        usort($utiles, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $utiles[0];
    }
}
