<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerfilMarca extends Model
{
    protected $table = 'perfil_marca';

    protected $fillable = ['seccion', 'contenido', 'orden', 'generado_ia_at'];

    protected $casts = [
        'generado_ia_at' => 'datetime',
    ];

    /**
     * Secciones del perfil, con su etiqueta y la pregunta que se le hace al
     * usuario cuando quiere que la IA la redacte por él.
     */
    public static function catalogo(): array
    {
        return [
            'identidad' => [
                'label'   => 'Nombre y slogan',
                'ayuda'   => 'Cómo se llama la empresa y su frase corta.',
                'pregunta'=> '¿Cómo se llama la empresa y qué frase la resume en una línea?',
            ],
            'historia' => [
                'label'   => 'Historia',
                'ayuda'   => 'De dónde viene la empresa y cómo llegó hasta hoy.',
                'pregunta'=> '¿Cómo nació la empresa, quién la fundó y qué retos superó?',
            ],
            'proposito' => [
                'label'   => 'Propósito / Manifiesto',
                'ayuda'   => 'Para qué existe la empresa, en frases cortas.',
                'pregunta'=> '¿Para qué existe la empresa? ¿Qué problema le resuelve al mercado?',
            ],
            'promesa' => [
                'label'   => 'Promesa',
                'ayuda'   => 'Qué se le promete al cliente y qué la respalda.',
                'pregunta'=> '¿Qué le prometes al cliente y con qué respaldas esa promesa?',
            ],
            'propuesta_valor' => [
                'label'   => 'Propuesta de valor',
                'ayuda'   => 'Por qué elegirte a ti y no a la competencia.',
                'pregunta'=> '¿Qué ofreces que la competencia no ofrece?',
            ],
            'mision' => [
                'label'   => 'Misión',
                'ayuda'   => 'Qué hace la empresa hoy.',
                'pregunta'=> '¿A qué se dedica la empresa y para quién?',
            ],
            'vision' => [
                'label'   => 'Visión',
                'ayuda'   => 'Dónde quiere estar la empresa.',
                'pregunta'=> '¿Dónde quieres que esté la empresa en unos años?',
            ],
            'valores' => [
                'label'   => 'Valores',
                'ayuda'   => 'Cómo se comporta la empresa.',
                'pregunta'=> '¿Qué principios no se negocian en tu empresa?',
            ],
            'elevator_pitch' => [
                'label'   => 'Elevator pitch',
                'ayuda'   => 'La empresa explicada en un párrafo.',
                'pregunta'=> 'Si tuvieras 30 segundos para explicar la empresa, ¿qué dirías?',
            ],
            'mensaje_clave' => [
                'label'   => 'Mensaje clave',
                'ayuda'   => 'La idea que debe quedar en la cabeza del cliente.',
                'pregunta'=> '¿Qué idea quieres que le quede al cliente sobre la empresa?',
            ],
            'tono_voz' => [
                'label'   => 'Tono y voz',
                'ayuda'   => 'Cómo habla la marca. Esta sección guía toda la redacción con IA.',
                'pregunta'=> '¿Cómo quieres que suene la marca? ¿Formal, cercana, técnica?',
            ],
            'clientes_ideales' => [
                'label'   => 'Clientes ideales',
                'ayuda'   => 'A quién le vendes y qué le duele.',
                'pregunta'=> '¿Quiénes son tus mejores clientes, en qué sectores y qué problema tienen?',
            ],
            'kpis' => [
                'label'   => 'KPIs',
                'ayuda'   => 'Cómo se mide el éxito.',
                'pregunta'=> '¿Con qué números mides que al negocio le va bien?',
            ],
            'dofa' => [
                'label'   => 'DOFA',
                'ayuda'   => 'Fortalezas, debilidades, oportunidades y amenazas.',
                'pregunta'=> '¿Cuáles son tus fortalezas, debilidades, oportunidades y amenazas?',
            ],
        ];
    }

    /**
     * Todo el perfil en un solo texto, para dárselo a la IA como contexto.
     * Se omiten las secciones vacías.
     */
    public static function comoContexto(): string
    {
        $catalogo = static::catalogo();

        return static::orderBy('orden')
            ->get()
            ->filter(fn (self $s) => filled($s->contenido))
            ->map(function (self $s) use ($catalogo) {
                $titulo = $catalogo[$s->seccion]['label'] ?? $s->seccion;

                return "## {$titulo}\n{$s->contenido}";
            })
            ->implode("\n\n");
    }

    /**
     * Solo el tono y la voz: es lo que necesita cualquier redacción para sonar
     * como la marca, sin gastar tokens en el perfil completo.
     */
    public static function tonoVoz(): ?string
    {
        return static::where('seccion', 'tono_voz')->value('contenido');
    }
}
