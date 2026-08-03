<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OrdenProduccion extends Model
{
    use SoftDeletes;

    protected $table = 'ordenes_produccion';

    protected $fillable = [
        'numero_op',
        'cotizacion_id',
        'vendedor_id',
        'cliente',
        'vendedor',
        'descripcion',
        'planos_medidas',
        'archivo_plano',
        'anticipo',
        'estado',
        'motivo_rechazo',
        'observaciones_calidad',
        'foto_calidad',
        'numero_remision',
        'fecha_anticipo',
        'fecha_verificacion',
        'fecha_inicio_produccion',
        'fecha_despacho',
    ];

    protected $casts = [
        'anticipo'               => 'decimal:2',
        'fecha_anticipo'         => 'datetime',
        'fecha_verificacion'     => 'datetime',
        'fecha_inicio_produccion'=> 'datetime',
        'fecha_despacho'         => 'datetime',
    ];

    public function lineas(): HasMany
    {
        return $this->hasMany(LineaOP::class, 'orden_produccion_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItemOP::class, 'orden_produccion_id');
    }

    public function archivos()
    {
        return $this->morphMany(Archivo::class, 'archivable')->orderBy('created_at', 'desc');
    }

    public static function generarNumero(): string
    {
        $ultimo = static::withTrashed()->max('id') ?? 0;

        return 'OP-' . str_pad($ultimo + 1, 3, '0', STR_PAD_LEFT);
    }

    public function abrirLineas(): void
    {
        foreach (['puertas', 'paneleria', 'almacen'] as $linea) {
            $this->lineas()->firstOrCreate(
                ['linea' => $linea],
                ['estado' => 'pendiente']
            );
        }
    }

    public function todasLineasCompletas(): bool
    {
        return $this->lineas()->where('estado', '!=', 'completada')->doesntExist();
    }

    public function estadoLabel(): string
    {
        return match ($this->estado) {
            'borrador'               => 'Borrador',
            'pendiente_verificacion' => 'Pendiente de verificación',
            'en_produccion'          => 'En producción',
            'control_calidad'        => 'Control de calidad',
            'reproceso'              => 'Reproceso',
            'empaque'                => 'Empaque',
            'despachada'             => 'Despachada',
            'cerrada'                => 'Cerrada',
            'rechazada'              => 'Rechazada',
            default                  => $this->estado,
        };
    }

    public function estadoColor(): string
    {
        return match ($this->estado) {
            'borrador'               => 'gray',
            'pendiente_verificacion' => 'yellow',
            'en_produccion'          => 'blue',
            'control_calidad'        => 'indigo',
            'reproceso'              => 'orange',
            'empaque'                => 'cyan',
            'despachada'             => 'green',
            'cerrada'                => 'dark',
            'rechazada'              => 'red',
            default                  => 'gray',
        };
    }
}
