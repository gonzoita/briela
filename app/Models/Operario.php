<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Operario extends Model
{
    use SoftDeletes;

    protected $table = 'operarios';

    protected $fillable = [
        'sede_id',
        'user_id',
        'tipo_colaborador_id',
        'nombre',
        'documento',
        'telefono',
        'especialidad',
        'cargo',
        'estado',
        'fecha_nacimiento',
        'fecha_ingreso',
        'direccion',
        'ciudad',
        'email',
        'numero_eps',
        'nombre_eps',
        'numero_pension',
        'nombre_pension',
        'numero_cuenta_bancaria',
        'banco',
        'tipo_cuenta',
        'archivo_hoja_vida',
        'archivo_cedula',
        'archivo_eps',
        'archivo_pension',
        'archivo_antecedentes',
        'archivo_certificacion_bancaria',
        'archivo_otros',
        'puntos_totales',
        'nivel',
    ];

    protected $casts = [
        'estado'           => 'string',
        'fecha_nacimiento' => 'date',
        'fecha_ingreso'    => 'date',
        'archivo_otros'    => 'array',
        'puntos_totales'   => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $operario) {
            $operario->sede_id ??= \App\Support\ContextoSede::paraGuardar();
        });
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tipoColaborador(): BelongsTo
    {
        return $this->belongsTo(TipoColaborador::class, 'tipo_colaborador_id');
    }

    public function horasExtras(): HasMany
    {
        return $this->hasMany(OperarioHoraExtra::class);
    }

    public function permisos(): HasMany
    {
        return $this->hasMany(OperarioPermiso::class);
    }

    public function disciplinas(): HasMany
    {
        return $this->hasMany(OperarioDisciplina::class);
    }

    public function hitos(): HasMany
    {
        return $this->hasMany(OperarioHito::class);
    }

    public function bonos(): HasMany
    {
        return $this->hasMany(OperarioBono::class);
    }

    public function pasos(): HasMany
    {
        return $this->hasMany(OpItemTrabajoPaso::class);
    }
}
