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
        'fecha' => 'date',
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
