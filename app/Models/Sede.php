<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sede extends Model
{
    protected $table = 'sedes';

    protected $fillable = [
        'nombre', 'codigo', 'tiene_ventas', 'tiene_produccion', 'es_principal',
        'nit', 'direccion', 'ciudad', 'telefono', 'email', 'activa',
    ];

    protected $casts = [
        'tiene_ventas'     => 'boolean',
        'tiene_produccion' => 'boolean',
        'es_principal'     => 'boolean',
        'activa'           => 'boolean',
    ];

    // ─── Relaciones ───────────────────────────────────────────────────────────

    public function bodegas(): HasMany
    {
        return $this->hasMany(Bodega::class);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function secuencias(): HasMany
    {
        return $this->hasMany(SecuenciaDocumento::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActivas($query)
    {
        return $query->where('activa', true);
    }

    public function scopeConVentas($query)
    {
        return $query->where('tiene_ventas', true);
    }

    public function scopeConProduccion($query)
    {
        return $query->where('tiene_produccion', true);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public static function principal(): ?self
    {
        return static::where('es_principal', true)->first();
    }

    /**
     * Etiqueta legible de para qué sirve la sede, para mostrar en pantalla.
     */
    public function getTipoLabelAttribute(): string
    {
        return match (true) {
            $this->tiene_ventas && $this->tiene_produccion => 'Ventas y fábrica',
            $this->tiene_produccion                        => 'Solo fábrica',
            $this->tiene_ventas                            => 'Solo ventas',
            default                                        => 'Sin operación',
        };
    }
}
