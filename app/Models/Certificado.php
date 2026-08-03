<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Certificado extends Model
{
    protected $fillable = [
        'inscripcion_id',
        'codigo_verificacion',
        'pdf_path',
        'emitido_at',
    ];

    protected $casts = [
        'emitido_at' => 'datetime',
    ];

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(Inscripcion::class);
    }

    public static function generarCodigo(): string
    {
        do {
            $codigo = 'CERT-' . strtoupper(Str::random(8));
        } while (self::where('codigo_verificacion', $codigo)->exists());

        return $codigo;
    }
}
