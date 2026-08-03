<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlantillaSeccion extends Model
{
    protected $table = 'plantilla_secciones';

    protected $fillable = [
        'plantilla_id',
        'nombre',
        'orden',
    ];

    protected $casts = [
        'orden' => 'integer',
    ];

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaEnsamble::class, 'plantilla_id');
    }

    public function componentes(): HasMany
    {
        return $this->hasMany(PlantillaComponente::class, 'seccion_id')->orderBy('orden');
    }
}
