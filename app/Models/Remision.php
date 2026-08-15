<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Remision extends Model
{
    use SoftDeletes;

    protected $table = 'remisiones';

    protected $fillable = [
        'sede_id',
        'numero',
        'tipo',
        'op_id',
        'cliente_id',
        'estado',
        'fecha_remision',
        'notas',
        'transportista',
        'celular_transportista',
        'placa',
        'costo_flete',
        'fecha_salida',
        'fecha_entrega',
        'firma_despacho',
        'firma_recibido',
        'nombre_receptor',
        'created_by',
    ];

    protected $casts = [
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha_remision' => 'date:Y-m-d',
        'fecha_salida'   => 'datetime',
        'fecha_entrega'  => 'datetime',
        'costo_flete'    => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $remision) {
            // Despacha la sede de la OP; si la remisión nace suelta, la activa.
            $remision->sede_id ??= $remision->op?->sede_id ?? \App\Support\ContextoSede::paraGuardar();

            if (empty($remision->numero)) {
                $remision->numero = app(\App\Services\SecuenciaService::class)
                    ->siguiente('remision', $remision->sede_id);
            }
        });
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function op(): BelongsTo
    {
        return $this->belongsTo(Op::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RemisionItem::class);
    }

    public function scopePendientes($query)
    {
        return $query->whereIn('estado', ['borrador', 'confirmada']);
    }

    public function scopeEnCamino($query)
    {
        return $query->where('estado', 'en_camino');
    }

    public function estadoBadge(): array
    {
        return match ($this->estado) {
            'borrador'   => ['label' => 'Borrador',    'bg' => '#F3F4F6', 'text' => '#374151'],
            'confirmada' => ['label' => 'Confirmada',  'bg' => '#DBEAFE', 'text' => '#1D4ED8'],
            'en_camino'  => ['label' => 'En camino',   'bg' => '#FEF3C7', 'text' => '#92400E'],
            'entregada'  => ['label' => 'Entregada',   'bg' => '#D1FAE5', 'text' => '#065F46'],
            'anulada'    => ['label' => 'Anulada',     'bg' => '#FEE2E2', 'text' => '#991B1B'],
            default      => ['label' => $this->estado, 'bg' => '#F3F4F6', 'text' => '#374151'],
        };
    }
}
