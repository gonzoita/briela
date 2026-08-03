<?php

namespace App\Models;

use App\Support\ContextoSede;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstacionTrabajo extends Model
{
    protected $table = 'estaciones_trabajo';

    protected $fillable = ['sede_id', 'nombre', 'descripcion', 'color', 'capacidad_simultanea', 'activa', 'orden'];

    protected $casts = ['activa' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (self $estacion) {
            $estacion->sede_id ??= ContextoSede::paraGuardar();
        });
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function equipos(): HasMany
    {
        return $this->hasMany(EquipoMantenimiento::class, 'estacion_trabajo_id');
    }

    public function disponible(): bool
    {
        if ($this->equipos->isEmpty()) return true;
        return $this->equipos->contains(fn($e) => $e->estado !== 'en_mantenimiento');
    }
}
