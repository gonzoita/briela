<?php

namespace App\Support;

use App\Models\Sede;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sede en la que está trabajando el usuario en este momento.
 *
 * Punto único para que todos los módulos filtren igual. La sede activa vive en
 * la sesión (la elige el usuario en el selector del encabezado):
 *
 *   - Un ID  → se ve solo esa sede.
 *   - null   → "Todas las sedes" (solo para quien tenga ese permiso).
 */
class ContextoSede
{
    /**
     * ID de la sede activa, o null si se están viendo todas.
     */
    public static function id(): ?int
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $enSesion = session('sede_activa_id');

        // "Todas las sedes" se guarda como 0 en la sesión para distinguirlo
        // de "todavía no ha elegido nada".
        if ($enSesion === 0 && $user->puedeVerTodasLasSedes()) {
            return null;
        }

        if ($enSesion && $user->puedeAccederASede((int) $enSesion)) {
            return (int) $enSesion;
        }

        // Sin elección válida: su sede propia, o la primera a la que acceda.
        if ($user->sede_id && $user->puedeAccederASede($user->sede_id)) {
            return $user->sede_id;
        }

        return $user->sedesAccesibles()->first()?->id;
    }

    /**
     * La sede activa como modelo (null si son todas).
     */
    public static function actual(): ?Sede
    {
        $id = static::id();

        return $id ? Sede::find($id) : null;
    }

    public static function viendoTodas(): bool
    {
        return static::id() === null;
    }

    /**
     * Limita una consulta a la sede activa. Si se están viendo todas, la
     * consulta se devuelve sin tocar.
     *
     *   ContextoSede::aplicar(SolicitudCompra::query())->get();
     */
    public static function aplicar(Builder $query, string $columna = 'sede_id'): Builder
    {
        $id = static::id();

        return $id ? $query->where($columna, $id) : $query;
    }

    /**
     * IDs de las sedes que se deben mostrar: la activa, o todas las
     * accesibles cuando se eligió "Todas las sedes".
     */
    public static function idsVisibles(): array
    {
        $id = static::id();

        if ($id) {
            return [$id];
        }

        return auth()->user()?->sedesAccesibles()->pluck('id')->all() ?? [];
    }

    /**
     * Sede que se debe asignar a un registro nuevo. Con "Todas las sedes"
     * activo se usa la sede propia del usuario, porque un documento siempre
     * tiene que nacer en una sede concreta.
     */
    public static function paraGuardar(): ?int
    {
        return static::id() ?? auth()->user()?->sede_id;
    }

    /**
     * Bodegas que el usuario puede ver ahora mismo: las que tiene permitidas,
     * recortadas a la sede activa.
     */
    public static function bodegasVisibles()
    {
        $user = auth()->user();

        if (! $user) {
            return collect();
        }

        $bodegas = $user->bodegasAccesibles();
        $sedeId  = static::id();

        return $sedeId ? $bodegas->where('sede_id', $sedeId)->values() : $bodegas;
    }

    public static function idsBodegasVisibles(): array
    {
        return static::bodegasVisibles()->pluck('id')->all();
    }

    /**
     * Las bodegas que se le pueden ofrecer a alguien para que elija una.
     *
     * Son las visibles de la sede activa y, **si esa lista sale vacía, todas las activas**.
     *
     * El respaldo no es un adorno. `bodegasVisibles()` cruza las bodegas con las sedes del
     * usuario, así que en una instalación donde las bodegas no tienen sede asignada —que es
     * el estado natural de una empresa de una sola sede que nunca tocó ese campo— devuelve
     * cero. Un selector vacío ahí no es «no hay bodegas»: es una pantalla que no deja
     * trabajar. Con la bodega de entrega obligatoria para confirmar una OP, sería no poder
     * confirmar ninguna.
     *
     * Es el mismo criterio que ya sigue `Producto::stockEnBodegas([])`, que ante una lista
     * vacía devuelve el stock total en vez de cero.
     */
    public static function bodegasParaElegir()
    {
        $visibles = static::bodegasVisibles();

        if ($visibles->isNotEmpty()) {
            return $visibles;
        }

        return \App\Models\Bodega::where('activa', true)->orderBy('nombre')->get();
    }
}
