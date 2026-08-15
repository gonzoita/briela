<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperarioHoraExtra extends Model
{
    protected $table = 'operario_horas_extras';

    protected $fillable = [
        'operario_id',
        'fecha',
        'tipo',
        'horas',
        'aprobado_por',
        'observacion',
    ];

    protected $casts = [
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha' => 'date:Y-m-d',
        'horas' => 'decimal:2',
    ];

    public function operario(): BelongsTo
    {
        return $this->belongsTo(Operario::class);
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }
}
