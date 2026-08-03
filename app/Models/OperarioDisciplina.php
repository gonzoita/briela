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
        'fecha'              => 'date',
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
