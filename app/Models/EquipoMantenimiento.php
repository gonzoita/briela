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
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha_instalacion' => 'date:Y-m-d',
        'proxima_revision'  => 'date:Y-m-d',
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
