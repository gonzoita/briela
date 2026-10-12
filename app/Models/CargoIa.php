<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un cargo del equipo de IA: la plantilla de un puesto.
 *
 * Reúne instrucciones base, el techo de herramientas y el rol de permisos. Se crea una vez y se
 * asigna a varios agentes. Los de fábrica (`de_fabrica`) llegan con la actualización y se pueden
 * copiar; lo que se edita es la copia, no el original.
 */
class CargoIa extends Model
{
    protected $table = 'cargos_ia';

    protected $fillable = [
        'clave', 'nombre', 'descripcion', 'area', 'color', 'avatar', 'instrucciones', 'herramientas',
        'rol_id', 'nivel_modelo', 'max_pasos', 'max_tokens', 'de_fabrica', 'activo',
    ];

    protected $casts = [
        'herramientas' => 'array',
        'de_fabrica'   => 'boolean',
        'activo'       => 'boolean',
        'max_pasos'    => 'integer',
        'max_tokens'   => 'integer',
    ];

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function agentes(): HasMany
    {
        return $this->hasMany(AgenteInterno::class, 'cargo_id');
    }
}
