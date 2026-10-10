<?php

namespace App\Services\Bandeja;

use Illuminate\Database\Eloquent\Model;

/**
 * Un mensaje, con la misma forma venga de donde venga.
 *
 * La pantalla del hilo es una sola para los cinco canales, así que lo que recibe tiene que ser
 * una sola cosa. Las dos tablas ya tienen los mismos nombres de columna a propósito —ver la
 * migración de `bandeja_mensajes`—, y lo único que cambia es cómo se llama el identificador del
 * mensaje en la red. Esto lo traduce y de paso resuelve el adjunto.
 */
trait NormalizaMensajes
{
    /**
     * @param  Model  $mensaje  un WhatsappMensaje o un BandejaMensaje
     * @return array<string, mixed>
     */
    protected function normalizarMensaje(Model $mensaje): array
    {
        $archivo = $mensaje->archivo;

        return [
            'id'        => $mensaje->id,
            'direccion' => $mensaje->direccion,
            'tipo'      => $mensaje->tipo,
            'texto'     => $mensaje->contenido,
            'estado'    => $mensaje->estado,
            'error'     => $mensaje->error,
            'plantilla' => $mensaje->plantilla ?? null,
            // Quién lo escribió de nuestro lado. Un mensaje del agente de IA o de una respuesta
            // automática no tiene usuario, y decir «Sistema» evita que alguien crea que un
            // compañero contestó algo que no contestó.
            'autor'     => $mensaje->direccion === 'saliente'
                ? ($mensaje->usuario?->name ?? ($mensaje->es_echo ? 'Desde el celular' : 'Automático'))
                : null,
            'es_echo'   => (bool) $mensaje->es_echo,
            'archivo'   => $archivo ? [
                'id'        => $archivo->id,
                'url'       => $archivo->url,
                'nombre'    => $archivo->nombre_original,
                'extension' => $archivo->extension,
                'es_imagen' => $archivo->es_imagen,
                'tamano'    => $archivo->tamano_formateado,
            ] : null,
            'at'   => $mensaje->created_at?->toIso8601String(),
            'hora' => $mensaje->created_at?->format('H:i'),
            'dia'  => $mensaje->created_at?->format('Y-m-d'),
        ];
    }
}
