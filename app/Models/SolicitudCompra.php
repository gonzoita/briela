<?php

namespace App\Models;

use App\Services\SecuenciaService;
use App\Support\ContextoSede;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SolicitudCompra extends Model
{
    protected $table = 'solicitudes_compra';

    protected $fillable = [
        'sede_id', 'numero', 'estado', 'solicitado_por', 'aprobado_por',
        'fecha_aprobacion', 'fecha_requerida', 'motivo', 'notas', 'op_id',
    ];

    protected $casts = [
        'fecha_aprobacion' => 'datetime',
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha_requerida'  => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            // Nace en la sede activa y toma el consecutivo configurado para
            // esa sede (Configuración → Numeración).
            $model->sede_id ??= ContextoSede::paraGuardar();
            $model->numero = app(SecuenciaService::class)->siguiente('solicitud_compra', $model->sede_id);
        });
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function op(): BelongsTo
    {
        return $this->belongsTo(Op::class, 'op_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SolicitudCompraItem::class, 'solicitud_id');
    }

    public function ordenCompra(): HasOne
    {
        return $this->hasOne(OrdenCompra::class, 'solicitud_id');
    }
}
