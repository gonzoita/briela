<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperarioPermiso extends Model
{
    protected $table = 'operario_permisos';

    protected $fillable = [
        'operario_id',
        'fecha_inicio',
        'fecha_fin',
        'motivo',
        'aprobado',
        'aprobado_por',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
        'aprobado'     => 'boolean',
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
