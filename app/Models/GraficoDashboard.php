<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un gráfico guardado de un tablero. Guarda la pregunta, no la respuesta.
 */
class GraficoDashboard extends Model
{
    protected $table = 'graficos_dashboard';

    protected $fillable = [
        'titulo', 'modulo', 'fuente', 'tipo', 'medida', 'dimension', 'filtros', 'orden', 'creado_por',
    ];

    protected $casts = [
        'filtros' => 'array',
        'orden'   => 'integer',
    ];

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
