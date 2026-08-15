<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperarioDisciplina extends Model
{
    protected $table = 'operario_disciplina';

    protected $fillable = [
        'operario_id',
        'tipo',
        'descripcion',
        'fecha',
        'creado_por',
        'firmado',
        'firmado_at',
        'penalizacion_valor',
    ];

    protected $casts = [
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha'              => 'date:Y-m-d',
        'firmado'            => 'boolean',
        'firmado_at'         => 'datetime',
        'penalizacion_valor' => 'decimal:2',
    ];

    public function operario(): BelongsTo
    {
        return $this->belongsTo(Operario::class);
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function tipoLabel(): string
    {
        return match ($this->tipo) {
            'falla'           => 'Falla',
            'memorando'       => 'Memorando',
            'llamado_atencion'=> 'Llamado de atención',
            default           => $this->tipo,
        };
    }
}
