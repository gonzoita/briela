<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PublicacionRrss extends Model
{
    use SoftDeletes;

    protected $table = 'publicaciones_rrss';

    protected $fillable = [
        'contenido', 'fecha_programada', 'estado', 'creado_por', 'publicado_en',
    ];

    protected $casts = [
        'fecha_programada' => 'datetime',
        'publicado_en'     => 'datetime',
    ];

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    // Estado de la publicación por cada cuenta destino.
    public function cuentasDestino(): HasMany
    {
        return $this->hasMany(PublicacionRrssCuenta::class);
    }

    // Imágenes/video adjuntos, reutilizando el sistema de archivos existente
    // (tabla polimórfica "archivos").
    public function archivos(): MorphMany
    {
        return $this->morphMany(Archivo::class, 'archivable');
    }

    public function scopePendientesDePublicar($query)
    {
        return $query->where('estado', 'programada')
            ->where('fecha_programada', '<=', now());
    }
}
