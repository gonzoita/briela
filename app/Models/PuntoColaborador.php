<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PuntoColaborador extends Model
{
    protected $table = 'puntos_colaborador';

    protected $fillable = [
        'operario_id',
        'puntos',
        'concepto',
        'tipo',
        'op_item_trabajo_paso_id',
        'registrado_por',
    ];

    protected $casts = ['puntos' => 'integer'];

    public function operario(): BelongsTo
    {
        return $this->belongsTo(Operario::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
