<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * La firma con la que Meta sella cada webhook, comprobada en UN solo sitio.
 *
 * Estaba escrita dos veces, palabra por palabra, en el webhook de WhatsApp y en el de Instagram
 * y Facebook. Son la misma aplicación de Meta y el mismo App Secret, y una de las dos copias
 * ya había quedado con distinto texto de aviso: la siguiente corrección de seguridad habría
 * llegado a una sola.
 *
 * **Sin App Secret no pasa nada, salvo en `local`.** Estos endpoints no tienen login y lo que
 * entra por ellos crea leads que se reparten solos entre los vendedores: aceptar lo que no se
 * puede verificar es dejar la puerta abierta. En `local` se deja pasar porque ahí el webhook se
 * prueba a mano con un cliente HTTP que no sabe firmar.
 */
class FirmaMeta
{
    public static function valida(Request $peticion, string $origen): bool
    {
        $secreto = CredencialesRrss::valor('meta', 'secret');

        if ($secreto === '') {
            if (app()->environment('local')) {
                Log::warning("Webhook de {$origen} aceptado sin verificar: no hay App Secret y el entorno es local.");

                return true;
            }

            Log::error(
                "Webhook de {$origen} rechazado: falta el App Secret de Meta, así que no se puede "
                . 'comprobar que el mensaje venga de Meta. Se carga en Redes Sociales → Cuentas.'
            );

            return false;
        }

        $recibida = (string) $peticion->header('X-Hub-Signature-256');

        if ($recibida === '') {
            Log::warning("Webhook de {$origen} rechazado: llegó sin la cabecera de firma.");

            return false;
        }

        if (! hash_equals('sha256=' . hash_hmac('sha256', $peticion->getContent(), $secreto), $recibida)) {
            Log::warning("Webhook de {$origen} rechazado: la firma no coincide.");

            return false;
        }

        return true;
    }
}
