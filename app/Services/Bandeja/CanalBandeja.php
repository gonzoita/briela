<?php

namespace App\Services\Bandeja;

use App\Models\Archivo;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que la bandeja necesita saber hacer con un canal.
 *
 * La lista la arma `BandejaService` con una sola consulta (ver su encabezado). Acá vive lo que
 * de verdad cambia de un canal a otro: **cómo sale un mensaje**. WhatsApp manda a
 * `graph.facebook.com/{phone_number_id}/messages` con el teléfono del destinatario; un directo
 * de Instagram va a `/{page_id}/messages` con un PSID; un comentario no se «manda» a nadie, se
 * cuelga de otro comentario. Son tres APIs distintas con tres formas de fallar.
 *
 * Un canal nuevo se agrega escribiendo un adaptador y registrándolo en `BandejaServiceProvider`.
 * Nada más de la bandeja tiene que cambiar.
 */
interface CanalBandeja
{
    /** @return array<int, string> las claves de `Canales` que este adaptador atiende */
    public function canales(): array;

    /** La conversación, o `null` si no existe. */
    public function encontrar(string $canal, int $id): ?Model;

    /**
     * El hilo, del más viejo al más nuevo, ya normalizado para la pantalla.
     *
     * @return array<int, array<string, mixed>>
     */
    public function hilo(Model $conversacion, int $limite = 200): array;

    /**
     * Manda un mensaje y lo deja guardado.
     *
     * Devuelve el mensaje ya normalizado, igual que los de `hilo()`, para que la pantalla lo
     * pinte sin volver a pedir el hilo entero.
     *
     * @throws \RuntimeException con un motivo legible cuando no se puede responder
     * @return array<string, mixed>
     */
    public function responder(Model $conversacion, string $texto, ?Archivo $archivo, User $quien): array;

    /**
     * Los datos de contexto del encabezado del hilo: por dónde entró, de qué publicación sale
     * un comentario, a qué lead o cliente está pegada la conversación.
     *
     * @return array<string, mixed>
     */
    public function cabecera(Model $conversacion): array;
}
