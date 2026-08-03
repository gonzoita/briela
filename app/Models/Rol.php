<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rol extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'nombre', 'descripcion', 'rol_base', 'es_sistema', 'todas_las_sedes', 'activo',
    ];

    protected $casts = [
        'es_sistema'      => 'boolean',
        'todas_las_sedes' => 'boolean',
        'activo'          => 'boolean',
    ];

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'rol_id');
    }

    public function permisosAsignados(): HasMany
    {
        return $this->hasMany(RolPermiso::class, 'rol_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    /**
     * Lista plana de los permisos de este rol.
     */
    public function permisos(): array
    {
        return $this->permisosAsignados()->pluck('permiso')->all();
    }

    public function tienePermiso(string $permiso): bool
    {
        return $this->permisosAsignados()->where('permiso', $permiso)->exists();
    }

    /**
     * Reemplaza por completo los permisos del rol.
     */
    public function sincronizarPermisos(array $permisos): void
    {
        $this->permisosAsignados()->delete();

        $filas = collect($permisos)
            ->unique()
            ->map(fn ($p) => ['rol_id' => $this->id, 'permiso' => $p])
            ->all();

        if ($filas) {
            RolPermiso::insert($filas);
        }
    }
}
