<?php

namespace App\Support;

/**
 * Los canales por los que la empresa conversa con quien está afuera, y las reglas de cada uno.
 *
 * Existe porque **las reglas no son nuestras, son de Meta**, y son distintas por canal: cuánto
 * dura la ventana para contestar, si hace falta una plantilla aprobada para iniciar, si se
 * pueden mandar adjuntos. Escribirlas en cada pantalla y en cada servicio era garantizar que
 * una bandeja ofreciera un botón que la API iba a rechazar.
 *
 * **La ventana es lo que más cuesta explicar y lo que más se rompe.** WhatsApp y los directos de
 * Instagram y Messenger solo dejan escribir texto libre dentro de las 24 horas siguientes al
 * último mensaje de la persona. Pasado ese plazo la API devuelve un error que no dice «se te
 * venció el plazo», así que sin esto la bandeja parecía estar enviando y el cliente nunca
 * recibía nada. Un comentario no tiene ventana: se responde cuando sea.
 *
 * `clave` es lo que se guarda en la base y viaja en la URL. Nunca se traduce ni se renombra: al
 * otro lado hay conversaciones guardadas en instalaciones a las que no tenemos acceso.
 */
class Canales
{
    /** Horas de la ventana de servicio de Meta. Es su regla, no una decisión nuestra. */
    public const VENTANA_HORAS = 24;

    /**
     * El catálogo.
     *
     * - `label`     — cómo se llama en pantalla.
     * - `grupo`     — con qué otros canales se agrupa en los filtros.
     * - `icono`     — el de Font Awesome que ya usa el menú.
     * - `color`     — la familia del tema, NO un color fijo (ver `docs/manual/marca.md`).
     * - `tabla`     — dónde viven sus conversaciones: cada canal entra por su adaptador.
     * - `ventana`   — horas para responder con texto libre, o `null` si no tiene plazo.
     * - `plantillas`— si fuera de la ventana se puede seguir escribiendo con una plantilla.
     * - `adjuntos`  — si se pueden mandar archivos.
     * - `modulos`   — los módulos que tienen que estar encendidos para que el canal exista.
     */
    public static function catalogo(): array
    {
        return [
            'whatsapp' => [
                'label'      => 'WhatsApp',
                'grupo'      => 'WhatsApp',
                'icono'      => 'whatsapp',
                'color'      => 'verde',
                'tabla'      => 'whatsapp_conversaciones',
                'ventana'    => self::VENTANA_HORAS,
                'plantillas' => true,
                'adjuntos'   => true,
                'modulos'    => ['bandeja'],
            ],

            'instagram_dm' => [
                'label'      => 'Instagram · Directos',
                'grupo'      => 'Instagram',
                'icono'      => 'instagram',
                'color'      => 'violeta',
                'tabla'      => 'bandeja_conversaciones',
                'ventana'    => self::VENTANA_HORAS,
                // Instagram no tiene plantillas. Fuera de la ventana no hay forma de escribir
                // primero, y la pantalla tiene que decirlo en vez de ofrecer un botón que falla.
                'plantillas' => false,
                'adjuntos'   => true,
                'modulos'    => ['bandeja', 'rrss'],
            ],

            'facebook_dm' => [
                'label'      => 'Messenger',
                'grupo'      => 'Facebook',
                'icono'      => 'facebook-messenger',
                'color'      => 'azul',
                'tabla'      => 'bandeja_conversaciones',
                'ventana'    => self::VENTANA_HORAS,
                'plantillas' => false,
                'adjuntos'   => true,
                'modulos'    => ['bandeja', 'rrss'],
            ],

            'instagram_comentario' => [
                'label'      => 'Instagram · Comentarios',
                'grupo'      => 'Instagram',
                'icono'      => 'comment',
                'color'      => 'violeta',
                'tabla'      => 'bandeja_conversaciones',
                // Un comentario público no tiene plazo: la respuesta queda colgada de la
                // publicación, no en el buzón de nadie.
                'ventana'    => null,
                'plantillas' => false,
                'adjuntos'   => false,
                'modulos'    => ['bandeja', 'rrss'],
            ],

            'facebook_comentario' => [
                'label'      => 'Facebook · Comentarios',
                'grupo'      => 'Facebook',
                'icono'      => 'comment',
                'color'      => 'azul',
                'tabla'      => 'bandeja_conversaciones',
                'ventana'    => null,
                'plantillas' => false,
                'adjuntos'   => false,
                'modulos'    => ['bandeja', 'rrss'],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function claves(): array
    {
        return array_keys(static::catalogo());
    }

    public static function existe(string $canal): bool
    {
        return array_key_exists($canal, static::catalogo());
    }

    /** @return array<string, mixed>|null */
    public static function de(string $canal): ?array
    {
        return static::catalogo()[$canal] ?? null;
    }

    public static function label(string $canal): string
    {
        return static::catalogo()[$canal]['label'] ?? $canal;
    }

    /**
     * Los canales que esta instalación tiene de verdad: los de módulos encendidos.
     *
     * Un canal de un módulo apagado no se filtra en la pantalla, no se cuenta en los avisos y
     * su webhook no entra. Es la misma regla del resto del sistema: un módulo apagado se lleva
     * todo lo suyo.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function activos(): array
    {
        return array_filter(
            static::catalogo(),
            fn (array $config) => collect($config['modulos'])->every(fn ($m) => Modulos::activo($m)),
        );
    }

    public static function activo(string $canal): bool
    {
        return array_key_exists($canal, static::activos());
    }

    /** Los que son de redes sociales: todo menos WhatsApp. */
    public static function deRedes(): array
    {
        return array_keys(array_filter(
            static::catalogo(),
            fn (array $config) => $config['tabla'] === 'bandeja_conversaciones',
        ));
    }

    public static function esComentario(string $canal): bool
    {
        return str_ends_with($canal, '_comentario');
    }

    /**
     * La red de Meta a la que pertenece un canal: con qué cuenta conectada se contesta.
     * `null` para WhatsApp, que no sale por `cuentas_rrss`.
     */
    public static function red(string $canal): ?string
    {
        return match ($canal) {
            'instagram_dm', 'instagram_comentario' => 'instagram',
            'facebook_dm',  'facebook_comentario'  => 'facebook',
            default                                => null,
        };
    }

    /**
     * Para la pantalla: el catálogo activo con su clave adentro, listo para un selector.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paraVista(): array
    {
        return collect(static::activos())
            ->map(fn (array $config, string $clave) => [
                'clave'      => $clave,
                'label'      => $config['label'],
                'grupo'      => $config['grupo'],
                'icono'      => $config['icono'],
                'color'      => $config['color'],
                'ventana'    => $config['ventana'],
                'plantillas' => $config['plantillas'],
                'adjuntos'   => $config['adjuntos'],
            ])
            ->values()
            ->all();
    }
}
