<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'sede_id',
        'tipo',
        'tipo_identificacion',
        'numero_identificacion',
        'digito_verificacion',
        'datos_rues',
        'nombre',
        'apellido',
        'email',
        'telefono',
        'celular',
        'ciudad',
        'direccion',
        'notas',
        'activo',
        'requiere_anticipo',
        'tipos_contacto',
        'industrias',
        'intereses',
        'proceso_seguimiento',
        'fuentes_contacto',
    ];

    protected $casts = [
        'activo'             => 'boolean',
        'requiere_anticipo'  => 'boolean',
        'tipos_contacto'     => 'array',
        'industrias'         => 'array',
        'proceso_seguimiento'=> 'array',
        'fuentes_contacto'   => 'array',
        'datos_rues'         => 'array',
    ];

    /**
     * NIT con su dígito de verificación, como se escribe en las facturas.
     */
    public function identificacionCompleta(): string
    {
        if (! $this->numero_identificacion) {
            return '';
        }

        return $this->digito_verificacion
            ? "{$this->numero_identificacion}-{$this->digito_verificacion}"
            : $this->numero_identificacion;
    }

    protected static function booted(): void
    {
        static::creating(function (self $cliente) {
            $cliente->sede_id ??= \App\Support\ContextoSede::paraGuardar();
        });
    }

    public function sede()
    {
        return $this->belongsTo(Sede::class);
    }

    public function nombreCompleto(): string
    {
        if ($this->tipo === 'persona' && $this->apellido) {
            return "{$this->nombre} {$this->apellido}";
        }
        return $this->nombre;
    }

    public function contactos(): HasMany
    {
        return $this->hasMany(ContactoCliente::class);
    }

    public function archivos(): MorphMany
    {
        return $this->morphMany(Archivo::class, 'archivable')->latest();
    }

    // ─── Historial del cliente ───────────────────────────────────────────────
    // Estas relaciones no existían: las llaves foráneas siempre estuvieron en
    // las tablas hijas, pero desde el cliente no había cómo llegar a ellas.
    // Sin esto, para saber qué le habíamos vendido a alguien tocaba ir módulo
    // por módulo filtrando a mano.

    public function cotizaciones(): HasMany
    {
        return $this->hasMany(Cotizacion::class)->latest();
    }

    public function ops(): HasMany
    {
        return $this->hasMany(Op::class)->latest();
    }

    public function remisiones(): HasMany
    {
        return $this->hasMany(Remision::class)->latest();
    }

    public function leads(): HasMany
    {
        return $this->hasMany(CrmLead::class)->latest();
    }

    public function contactoPrincipal(): ?ContactoCliente
    {
        return $this->contactos()->where('es_principal', true)->first()
            ?? $this->contactos()->first();
    }

    public function nombreParaAuditoria(): string
    {
        return $this->nombreCompleto();
    }
}
