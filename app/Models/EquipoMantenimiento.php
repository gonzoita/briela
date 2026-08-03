<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EquipoMantenimiento extends Model
{
    use SoftDeletes;

    protected $table = 'equipos_mantenimiento';

    protected $fillable = [
        'sede_id', 'nombre', 'codigo', 'tipo', 'marca', 'modelo', 'serial',
        'fecha_instalacion', 'proxima_revision', 'frecuencia_dias',
        'ubicacion', 'estacion_trabajo_id', 'responsable', 'observaciones', 'estado',
    ];

    protected $casts = [
        'fecha_instalacion' => 'date',
        'proxima_revision'  => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $equipo) {
            $equipo->sede_id ??= \App\Support\ContextoSede::paraGuardar();
        });
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(EstacionTrabajo::class, 'estacion_trabajo_id');
    }

    public function componentes()
    {
        return $this->hasMany(EquipoComponente::class, 'equipo_id');
    }

    public function mantenimientos()
    {
        return $this->hasMany(Mantenimiento::class, 'equipo_id');
    }

    public function ultimoMantenimiento()
    {
        return $this->hasOne(Mantenimiento::class, 'equipo_id')
            ->where('estado', 'completado')
            ->latest('fecha_fin');
    }
}
