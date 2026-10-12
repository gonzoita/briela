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
        'inventario_movido_at',
        'cerrada_at',
        'firma_despacho_por',
        'firma_despacho_at',
        'firma_recibido_at',
    ];

    /** Las transiciones permitidas. Una remisión entregada o anulada ya no cambia. */
    public const TRANSICIONES = [
        'borrador'   => ['confirmada', 'en_camino', 'entregada', 'anulada'],
        'confirmada' => ['en_camino', 'entregada', 'anulada'],
        'en_camino'  => ['entregada', 'anulada'],
        // Entregada → cerrada la pone el sistema cuando la orden queda pagada y verificada
        // (`RemisionService::sincronizarCierre`); nadie la elige en la pantalla.
        'entregada'  => [],
        'cerrada'    => [],
        'anulada'    => [],
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
        'inventario_movido_at' => 'datetime',
        'cerrada_at'           => 'datetime',
        'firma_despacho_at'    => 'datetime',
        'firma_recibido_at'    => 'datetime',
    ];

    /** Salió de la empresa: en camino, entregada o ya cerrada. */
    public const SALIDAS = ['en_camino', 'entregada', 'cerrada'];

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

    public function firmadoDespachoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'firma_despacho_por');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RemisionItem::class);
    }

    public function puedePasarA(string $estado): bool
    {
        return in_array($estado, self::TRANSICIONES[$this->estado] ?? [], true);
    }

    /**
     * Saca del almacén lo que lleva esta remisión, una sola vez (candado `inventario_movido_at`).
     * Ocurre al crearla; la regla vive en `InventarioRemisionService`.
     */
    public function sacarDelInventario(): void
    {
        app(\App\Services\InventarioRemisionService::class)->sacar($this);
    }

    /** Anulada o borrada: lo que sacó vuelve a la bodega. */
    public function devolverAlInventario(): void
    {
        app(\App\Services\InventarioRemisionService::class)->devolver($this);
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
        // Colores del tema (variables CSS), no fijos: cambian con el modo noche.
        $familia = fn (string $f) => ['bg' => "var(--pastel-{$f})", 'text' => "var(--texto-{$f})"];

        return match ($this->estado) {
            'borrador'   => ['label' => 'Borrador',   'bg' => 'var(--tinta-100)', 'text' => 'var(--texto-2)'],
            'confirmada' => ['label' => 'Confirmada'] + $familia('azul'),
            'en_camino'  => ['label' => 'En camino'] + $familia('ambar'),
            'entregada'  => ['label' => 'Entregada'] + $familia('verde'),
            // Entregada y pagada: el final del camino.
            'cerrada'    => ['label' => 'Cerrada'] + $familia('violeta'),
            'anulada'    => ['label' => 'Anulada'] + $familia('rojo'),
            default      => ['label' => $this->estado, 'bg' => 'var(--tinta-100)', 'text' => 'var(--texto-2)'],
        };
    }
}
