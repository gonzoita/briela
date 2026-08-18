<?php

namespace App\Models;

use Database\Factories\UserFactory;
use App\Support\Permisos;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'telefono',
        'password',
        'rol',
        'rol_id',
        'sede_id',
        'activo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'activo'            => 'boolean',
        ];
    }

    // ─── Relaciones ───────────────────────────────────────────────────────────

    public function operario(): HasOne
    {
        return $this->hasOne(Operario::class);
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function rolConfigurable(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function sedes(): BelongsToMany
    {
        return $this->belongsToMany(Sede::class, 'usuario_sede');
    }

    public function bodegas(): BelongsToMany
    {
        return $this->belongsToMany(Bodega::class, 'usuario_bodega');
    }

    public function inscripciones(): MorphMany
    {
        return $this->morphMany(Inscripcion::class, 'inscribible');
    }

    // ─── Helpers de rol ────────────────────────────────────────────────────────

    public function esAdmin(): bool
    {
        return $this->rol === 'administrador';
    }

    public function esJefeProduccion(): bool
    {
        return $this->rol === 'jefe_produccion';
    }

    public function esVendedor(): bool
    {
        return $this->rol === 'vendedor';
    }

    public function esOperario(): bool
    {
        return $this->rol === 'operario';
    }

    // ─── Permisos ───────────────────────────────────────────────────────────────

    public function puedeVerTodasOps(): bool
    {
        return $this->esAdmin() || $this->esJefeProduccion();
    }

    public function puedeCrearOps(): bool
    {
        return $this->esAdmin() || $this->esVendedor();
    }

    public function puedeVerificarOps(): bool
    {
        return $this->esAdmin() || $this->esJefeProduccion();
    }

    public function puedeActualizarLineas(): bool
    {
        return $this->esAdmin() || $this->esJefeProduccion() || $this->esOperario();
    }

    // ─── Permisos configurables ────────────────────────────────────────────────

    /**
     * Lista de permisos efectivos del usuario.
     *
     * Si tiene un rol configurable asignado, manda ese rol. Si no (usuarios
     * viejos o creados por fuera), se cae al catálogo del rol histórico — así
     * nadie se queda sin acceso.
     */
    public function permisos(): array
    {
        static $cache = [];

        if (isset($cache[$this->id])) {
            return $cache[$this->id];
        }

        $rol = $this->relationLoaded('rolConfigurable')
            ? $this->rolConfigurable
            : $this->rolConfigurable()->with('permisosAsignados')->first();

        $permisos = $rol && $rol->activo
            ? $rol->permisos()
            : Permisos::porRolLegado($this->rol);

        return $cache[$this->id] = $permisos;
    }

    /**
     * ¿Puede hacer esto? Acepta "modulo.accion" o solo "modulo" (cualquier
     * acción de ese módulo).
     */
    public function tienePermiso(string $permiso): bool
    {
        $permisos = $this->permisos();

        if (str_contains($permiso, '.')) {
            return in_array($permiso, $permisos, true);
        }

        foreach ($permisos as $p) {
            if (str_starts_with($p, $permiso . '.')) {
                return true;
            }
        }

        return false;
    }

    public function tieneAlgunPermiso(array $permisos): bool
    {
        foreach ($permisos as $permiso) {
            if ($this->tienePermiso($permiso)) {
                return true;
            }
        }

        return false;
    }

    // ─── Sedes ─────────────────────────────────────────────────────────────────

    public function puedeVerTodasLasSedes(): bool
    {
        $rol = $this->rolConfigurable;

        return $rol ? (bool) $rol->todas_las_sedes : $this->esAdmin();
    }

    /**
     * Sedes activas a las que este usuario tiene acceso: las del pivote
     * usuario_sede, o su sede directa si no tiene ninguna asignada.
     */
    public function sedesAccesibles()
    {
        if ($this->puedeVerTodasLasSedes()) {
            return Sede::activas()->orderByDesc('es_principal')->orderBy('nombre')->get();
        }

        $ids = $this->sedes()->pluck('sedes.id')->all();

        if (empty($ids) && $this->sede_id) {
            $ids = [$this->sede_id];
        }

        return Sede::activas()
            ->whereIn('id', $ids)
            ->orderByDesc('es_principal')
            ->orderBy('nombre')
            ->get();
    }

    public function puedeAccederASede(?int $sedeId): bool
    {
        if ($sedeId === null) {
            return false;
        }

        return $this->sedesAccesibles()->contains('id', $sedeId);
    }

    /**
     * Bodegas permitidas. Sin restricción explícita, son todas las de las
     * sedes a las que accede.
     */
    public function bodegasAccesibles()
    {
        $ids = $this->bodegas()->pluck('bodegas.id')->all();

        if (!empty($ids)) {
            return Bodega::whereIn('id', $ids)->where('activa', true)->orderBy('nombre')->get();
        }

        return Bodega::whereIn('sede_id', $this->sedesAccesibles()->pluck('id'))
            ->where('activa', true)
            ->orderBy('nombre')
            ->get();
    }

    /** Las comisiones de este vendedor. */
    public function comisiones(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\ComisionVendedor::class, 'user_id');
    }
}
