<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class CapacitacionEstudiante extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'tipo',
        'cliente_id',
        'telefono',
        'activo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'activo'   => 'boolean',
        'password' => 'hashed',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function inscripciones(): MorphMany
    {
        return $this->morphMany(Inscripcion::class, 'inscribible');
    }
}
