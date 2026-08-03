<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgresoLeccion extends Model
{
    protected $table = 'progreso_lecciones';

    protected $fillable = [
        'inscripcion_id',
        'curso_leccion_id',
        'completado',
        'completado_at',
    ];

    protected $casts = [
        'completado'    => 'boolean',
        'completado_at' => 'datetime',
    ];

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(Inscripcion::class);
    }

    public function leccion(): BelongsTo
    {
        return $this->belongsTo(CursoLeccion::class, 'curso_leccion_id');
    }
}
