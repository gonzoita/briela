<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Una sección del tablero de inicio: un título y los gráficos que cuelgan de él.
 *
 * No guarda gráficos. Guarda una **clave**, y esa clave es lo que los gráficos usan como
 * `modulo` — así el tablero de inicio reusa entero el motor que ya mueve los tableros de
 * Cotizaciones, Comisiones, Alistamiento y Financiero.
 */
class DashboardSeccion extends Model
{
    protected $table = 'dashboard_secciones';

    protected $fillable = ['titulo', 'clave', 'orden', 'activa', 'creado_por'];

    protected function casts(): array
    {
        return [
            'orden'  => 'integer',
            'activa' => 'boolean',
        ];
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /** Los gráficos de esta sección, que cuelgan de su clave. */
    public function graficos()
    {
        return GraficoDashboard::where('modulo', $this->clave)->orderBy('orden');
    }

    /**
     * Una clave estable a partir del título, sin repetir.
     *
     * Va con prefijo `panel.` para que no choque con los módulos de siempre —`cotizaciones`,
     * `financiero`— y para que se vea de un vistazo, mirando la base, que ese gráfico es del
     * tablero de inicio y no del tablero del módulo.
     */
    public static function generarClave(string $titulo): string
    {
        $base  = 'panel.'.(Str::slug($titulo) ?: 'seccion');
        $clave = $base;
        $n     = 1;

        while (static::where('clave', $clave)->exists()) {
            $n++;
            $clave = $base.'-'.$n;
        }

        return $clave;
    }

    /**
     * Al borrar la sección se borran sus gráficos.
     *
     * Si se quedaran, seguirían ocupando lugar en la base sin ninguna pantalla que los muestre,
     * y volverían a aparecer el día que alguien creara una sección con el mismo nombre.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $seccion) {
            GraficoDashboard::where('modulo', $seccion->clave)->delete();
        });
    }
}
