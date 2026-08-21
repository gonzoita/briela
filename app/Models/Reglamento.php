<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * El reglamento interno de trabajo de la empresa.
 *
 * Uno por instalación. `principal()` lo crea si no existe, para que la pantalla nunca tenga
 * que preguntarse si hay fila: entrar a escribir el reglamento por primera vez y entrar a
 * corregirlo son la misma acción.
 */
class Reglamento extends Model
{
    protected $table = 'reglamentos';

    protected $fillable = [
        'titulo', 'contenido', 'version', 'vigente_desde',
        'token_publico', 'publicado', 'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            // `date:Y-m-d` y no `date`: un `<input type="date">` exige «2026-08-15» y rechaza
            // el ISO con hora sin decir nada, dejando el campo vacío.
            'vigente_desde' => 'date:Y-m-d',
            'publicado'     => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $r) {
            if (empty($r->token_publico)) {
                $r->token_publico = static::tokenNuevo();
            }
        });
    }

    /** El reglamento de esta instalación, creándolo vacío la primera vez. */
    public static function principal(): self
    {
        return static::firstOrCreate([], ['titulo' => 'Reglamento Interno de Trabajo']);
    }

    public static function tokenNuevo(): string
    {
        return Str::random(48);
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    /** La dirección que se comparte y que va dentro del QR. */
    public function urlPublica(): string
    {
        return url('/reglamento/'.$this->token_publico);
    }

    /** ¿Hay algo que mostrar? Un reglamento en blanco no se publica. */
    public function tieneContenido(): bool
    {
        return trim(strip_tags((string) $this->contenido)) !== '';
    }
}
