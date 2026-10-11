<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Support\Str;
use App\Support\Marca;

#[ObservedBy(\App\Observers\CotizacionObserver::class)]
class Cotizacion extends Model
{
    /**
     * Una cotización sin cliente no se guarda, no se envía, no se aprueba ni genera orden.
     * La columna sigue siendo nulable por las que ya existen: lo exige cada puerta de entrada.
     */
    public const SIN_CLIENTE = 'Esta cotización no tiene cliente. Asígnale uno antes de seguir.';

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
        // Los ítems y los totales se guardan SIEMPRE en pesos; la moneda y la tasa dicen
        // cómo mostrárselos al cliente. Ver App\Support\Monedas.
        'tasa_cambio',
        'tasa_fecha',
        'fecha_creacion',
        'fecha_validez',
        // El plazo prometido, en días hábiles: de aquí sale la fecha de entrega de la orden.
        'dias_entrega',
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
        'dias_entrega'     => 'integer',
        'subtotal'         => 'decimal:2',
        'descuento_total'  => 'decimal:2',
        'impuesto_total'   => 'decimal:2',
        'total'            => 'decimal:2',
        'tasa_cambio'      => 'decimal:8',
        'tasa_fecha'       => 'date:Y-m-d',
        'en_produccion'    => 'boolean',
    ];

    /** Un valor en pesos, escrito en la moneda de esta cotización. */
    public function enSuMoneda(float|int|string|null $valorEnPesos): string
    {
        return \App\Support\Monedas::formatear($valorEnPesos, (string) ($this->moneda ?: \App\Support\Monedas::base()), (float) ($this->tasa_cambio ?: 1));
    }

    public function esEnOtraMoneda(): bool
    {
        return ($this->moneda ?: \App\Support\Monedas::base()) !== \App\Support\Monedas::base() && (float) $this->tasa_cambio > 0;
    }

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

        // Se recalcula cuando cambian las líneas —crear, editar, duplicar—, así que es el punto
        // por el que pasa todo cambio de lo que se promete. Si la cotización ya está enviada,
        // lo apartado la sigue. Nunca rompe el guardado: apartar es secundario.
        try {
            app(\App\Services\ReservaStockService::class)->alCambiarLineas($this);
        } catch (\Throwable $e) {
            report($e);
        }
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
        // `bg`/`text` son colores fijos para el PDF, que no entiende variables CSS. La pantalla
        // usa `bg_tema`/`text_tema`, que cambian con el modo noche: con los fijos, la etiqueta
        // quedaba como una mancha clara sobre la tabla oscura.
        [$label, $bg, $text, $familia] = match ($this->estado) {
            'borrador'      => ['Borrador',      '#F3F4F6', '#6B7280', null],
            'enviada'       => ['Enviada',       '#DBEAFE', '#1D4ED8', 'azul'],
            'aprobada'      => ['Aprobada',      '#D1FAE5', '#065F46', 'verde'],
            'rechazada'     => ['Rechazada',     '#FEE2E2', '#991B1B', 'rojo'],
            'vencida'       => ['Vencida',       '#FEF3C7', '#92400E', 'ambar'],
            'en_produccion' => ['En Producción', '#DBEAFE', Marca::color(), 'violeta'],
            default         => [$this->estado,   '#F3F4F6', '#6B7280', null],
        };

        return [
            'label'     => $label,
            'bg'        => $bg,
            'text'      => $text,
            'bg_tema'   => $familia ? "var(--pastel-{$familia})" : 'var(--tinta-100)',
            'text_tema' => $familia ? "var(--texto-{$familia})" : 'var(--texto-2)',
        ];
    }

    /**
     * La fecha de entrega que sale del plazo prometido, contando desde `$desde` (hoy, si no).
     *
     * Días hábiles de lunes a viernes: el plazo corre cuando arranca la orden, no cuando se
     * cotizó. Los festivos no se descuentan; la fecha es una estimación y se ajusta al generar
     * la orden o en la orden misma.
     */
    public function fechaEntregaSugerida(?\Carbon\CarbonInterface $desde = null): ?\Carbon\CarbonInterface
    {
        if (! $this->dias_entrega) {
            return null;
        }

        return \Carbon\Carbon::parse($desde ?? today())->addWeekdays($this->dias_entrega);
    }
}
