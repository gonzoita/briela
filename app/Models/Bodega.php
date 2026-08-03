<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bodega extends Model
{
    protected $table = 'bodegas';

    protected $fillable = ['sede_id', 'nombre', 'tipo', 'es_principal', 'activa'];

    protected $casts = [
        'es_principal' => 'boolean',
        'activa'       => 'boolean',
    ];

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductoStock::class);
    }

    public function scopeDeSede($query, ?int $sedeId)
    {
        return $sedeId ? $query->where('sede_id', $sedeId) : $query;
    }

    /**
     * Bodega principal. Con multi-sede cada sede tiene la suya; si no se pasa
     * sede se devuelve la de la sede principal, que es el comportamiento que
     * tenía el sistema antes de las sedes.
     */
    public static function principal(?int $sedeId = null): ?self
    {
        if ($sedeId) {
            return static::where('es_principal', true)->where('sede_id', $sedeId)->first();
        }

        $sedePrincipalId = Sede::principal()?->id;

        return static::where('es_principal', true)
                ->when($sedePrincipalId, fn ($q) => $q->where('sede_id', $sedePrincipalId))
                ->first()
            ?? static::where('es_principal', true)->first();
    }
}
