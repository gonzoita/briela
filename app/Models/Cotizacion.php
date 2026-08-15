<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Support\Marca;

class Cotizacion extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'cotizaciones';

    protected $fillable = [
        'sede_id',
        'numero',
        'lead_id',
        'cliente_id',
        'contacto_id',
        'nombre_contacto_override',
        'moneda',
        'tasa_cambio',
        'fecha_creacion',
        'fecha_validez',
        'responsable_id',
        'estado',
        'en_produccion',
        'subtotal',
        'descuento_total',
        'impuesto_total',
        'total',
        'condiciones_comerciales',
        'notas_internas',
        'token_publico',
        'motivo_rechazo',
    ];

    protected $casts = [
        // `date:Y-m-d` y no `date`: sin el formato, esto se serializa como
        // «2026-08-10T00:00:00.000000Z» y un `<input type="date">` exige «2026-08-10». El
        // navegador rechaza el valor en silencio —solo lo dice en la consola—, el campo se
        // ve vacío, y el usuario no puede leer ni corregir la fecha de su cotización.
        'fecha_creacion'   => 'date:Y-m-d',
        'fecha_validez'    => 'date:Y-m-d',
        'subtotal'         => 'decimal:2',
        'descuento_total'  => 'decimal:2',
        'impuesto_total'   => 'decimal:2',
        'total'            => 'decimal:2',
        'tasa_cambio'      => 'decimal:4',
        'en_produccion'    => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $cot) {
            // Nace en la sede de venta activa.
            $cot->sede_id ??= \App\Support\ContextoSede::paraGuardar();

            if (empty($cot->numero)) {
                $cot->numero = app(\App\Services\SecuenciaService::class)
                    ->siguiente('cotizacion', $cot->sede_id);
            }
            if (empty($cot->fecha_creacion)) {
                $cot->fecha_creacion = now()->toDateString();
            }
            if (empty($cot->fecha_validez)) {
                $cot->fecha_validez = now()->addDays(30)->toDateString();
            }
            if (empty($cot->token_publico)) {
                $cot->token_publico = Str::random(40);
            }
        });
    }

    /**
     * @deprecated La numeración vive en SecuenciaService y se configura por
     * sede en Configuración → Numeración. Se conserva por si algún código
     * viejo la llama.
     */
    public static function generarNumero(): string
    {
        return app(\App\Services\SecuenciaService::class)->siguiente('cotizacion');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function comision(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ComisionVendedor::class, 'cotizacion_id');
    }

    public function seguimientos(): HasMany
    {
        return $this->hasMany(CotizacionSeguimiento::class)->latest();
    }

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(ContactoCliente::class, 'contacto_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CotizacionItem::class)->orderBy('orden');
    }

    public function ordenProduccion(): HasOne
    {
        return $this->hasOne(\App\Models\Op::class, 'cotizacion_id');
    }

    public function recalcularTotales(): void
    {
        $subtotal        = 0;
        $descuentoTotal  = 0;
        $impuestoTotal   = 0;

        foreach ($this->items as $item) {
            $base             = $item->cantidad * $item->precio_unitario;
            $descuento        = $base * ($item->descuento_pct / 100);
            $baseConDescuento = $base - $descuento;
            $impuesto         = $baseConDescuento * ($item->impuesto_pct / 100);

            $subtotal       += $base;
            $descuentoTotal += $descuento;
            $impuestoTotal  += $impuesto;
        }

        $this->update([
            'subtotal'        => $subtotal,
            'descuento_total' => $descuentoTotal,
            'impuesto_total'  => $impuestoTotal,
            'total'           => $subtotal - $descuentoTotal + $impuestoTotal,
        ]);
    }

    public function diasSinRespuesta(): ?int
    {
        if ($this->estado !== 'enviada') {
            return null;
        }
        // Cuenta desde el último seguimiento registrado (si hay), no desde
        // cualquier actualización genérica — así un cambio menor no
        // "reinicia" el contador, pero registrar un contacto real sí.
        $ultimoContacto = $this->relationLoaded('seguimientos')
            ? $this->seguimientos->first()?->created_at
            : $this->seguimientos()->latest()->first()?->created_at;

        $desde = $ultimoContacto ?? $this->updated_at;
        return (int) \Carbon\Carbon::parse($desde)->diffInDays(now());
    }

    public function nombreParaAuditoria(): string
    {
        return $this->numero;
    }

    public function estadoBadge(): array
    {
        return match ($this->estado) {
            'borrador'      => ['label' => 'Borrador',      'bg' => '#F3F4F6', 'text' => '#6B7280'],
            'enviada'       => ['label' => 'Enviada',       'bg' => '#DBEAFE', 'text' => '#1D4ED8'],
            'aprobada'      => ['label' => 'Aprobada',      'bg' => '#D1FAE5', 'text' => '#065F46'],
            'rechazada'     => ['label' => 'Rechazada',     'bg' => '#FEE2E2', 'text' => '#991B1B'],
            'vencida'       => ['label' => 'Vencida',       'bg' => '#FEF3C7', 'text' => '#92400E'],
            'en_produccion' => ['label' => 'En Producción', 'bg' => '#DBEAFE', 'text' => Marca::color()],
            default         => ['label' => $this->estado,   'bg' => '#F3F4F6', 'text' => '#6B7280'],
        };
    }
}
