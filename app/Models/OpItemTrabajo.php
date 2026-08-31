<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpItemTrabajo extends Model
{
    protected $table = 'op_item_trabajos';

    protected $fillable = [
        'op_item_id',
        'template_id',
        'porcentaje_avance',
        'numero_unidad',
        'total_unidades',
        // Cuándo arrancó y cuándo salió de producción. La segunda es también la hora en que
        // llegó a calidad: es el mismo instante.
        'iniciado_at',
        'terminado_at',
        'token_trabajo',
        'remisionado',
        // Cuándo entró a bodega la unidad y a cuál. Es el candado que evita que volver a
        // marcar el último paso meta la misma unidad dos veces al inventario.
        'entregado_at',
        'bodega_entrega_id',
        // Y de cuál salió su material. Se guarda por unidad porque un lote se puede partir:
        // tres puertas con material de la principal y dos con el de la sucursal.
        'bodega_material_id',
        // Cuándo calidad firmó ESTA unidad, y quién. Es el candado del despacho.
        'calidad_revisada_at',
        'calidad_revisada_por',
    ];

    /** La bodega a la que entró esta unidad al terminarse. */
    public function bodegaEntrega(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Bodega::class, 'bodega_entrega_id');
    }

    /** La bodega de la que salieron sus insumos. */
    public function bodegaMaterial(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Bodega::class, 'bodega_material_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $t) {
            if (empty($t->token_trabajo)) {
                $t->token_trabajo = \Illuminate\Support\Str::random(40);
            }
        });
    }

    protected $casts = [
        'entregado_at'        => 'datetime',
        'calidad_revisada_at' => 'datetime',
        'iniciado_at'         => 'datetime',
        'terminado_at'        => 'datetime',
        'porcentaje_avance' => 'decimal:2',
        'remisionado'       => 'boolean',
    ];

    public function opItem(): BelongsTo
    {
        return $this->belongsTo(OpItem::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TemplateTrabajo::class, 'template_id');
    }

    public function pasos(): HasMany
    {
        return $this->hasMany(OpItemTrabajoPaso::class, 'op_item_trabajo_id')->orderBy('orden');
    }

    public function scopeCompletados($query)
    {
        return $query->where('porcentaje_avance', 100);
    }

    /**
     * Las unidades que se pueden despachar: armadas, sin remisionar y **firmadas por calidad**.
     *
     * El candado vive aquí, en la unidad, y no en el sello de la orden. Es lo que permite lo
     * que de verdad pasa en el mostrador: de una orden de diez puertas el cliente se lleva las
     * tres que ya están revisadas, y las otras siete siguen su curso.
     *
     * La firma es `calidad_revisada_at`, y **no** «no le quedan puntos pendientes». Esa segunda
     * versión dejaba fuera a las unidades sin lista de revisión, que en una instalación real
     * son la mayoría: no tenían nada que resolver, así que nunca quedaban listas, y no había
     * dónde firmarlas. Con un sello propio la regla es una sola y vale para las dos.
     *
     * La falla crítica se revisa aparte porque puede aparecer **después** de firmada: calidad
     * vuelve a mirar la unidad y marca un punto en falla. Ahí deja de poder despacharse aunque
     * la firma siga puesta, hasta que se resuelva.
     */
    public function scopeDisponiblesParaRemision($query)
    {
        return $query->where('porcentaje_avance', 100)
            ->where('remisionado', false)
            ->whereNotNull('calidad_revisada_at')
            ->whereDoesntHave('checks', function ($bloquea) {
                $bloquea->where('resultado', 'pendiente')
                    ->orWhere(function ($critico) {
                        $critico->where('resultado', 'falla')->where('es_critico', true);
                    });
            });
    }

    /** Quién firmó la revisión de esta unidad. */
    public function calidadRevisadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calidad_revisada_por');
    }

    /**
     * Firma la revisión de esta unidad, o la retira.
     *
     * Retirarla es tan importante como ponerla: una unidad que vuelve a reproceso, o a la que
     * le reabren un punto, deja de estar aprobada. Decir lo contrario es mentir en el único
     * sitio donde no se puede — es lo que abre el despacho.
     */
    public function firmarCalidad(bool $firmada = true): void
    {
        $this->update([
            'calidad_revisada_at'  => $firmada ? ($this->calidad_revisada_at ?? now()) : null,
            'calidad_revisada_por' => $firmada ? ($this->calidad_revisada_por ?? auth()->id()) : null,
        ]);
    }

    public function recalcularAvance(): void
    {
        // El % de avance se normaliza contra la suma real de pesos de los
        // pasos, no se asume que siempre sumen 100. Si algún paso quedó con
        // peso 0 (ej. trabajos generados antes de que la plantilla tuviera
        // pesos por dificultad), sin esto el avance se quedaba pegado en 0%
        // aunque los pasos estuvieran completados. Si la suma de pesos es 0,
        // se cae a un simple pasos_completados/pasos_total.
        $totalPeso       = (float) $this->pasos()->sum('peso_porcentaje');
        $completadosPeso = (float) $this->pasos()->where('completado', true)->sum('peso_porcentaje');

        if ($totalPeso > 0) {
            $avance = round(($completadosPeso / $totalPeso) * 100, 2);
        } else {
            $totalPasos = $this->pasos()->count();
            $pasosOk    = $this->pasos()->where('completado', true)->count();
            $avance     = $totalPasos > 0 ? round(($pasosOk / $totalPasos) * 100, 2) : 0;
        }

        $this->update(['porcentaje_avance' => $avance]);

        $this->sellarFechasDeProceso($avance);

        $item = $this->opItem;
        if (! $item) return;

        $totalTrabajos = $item->trabajos()->count();
        $trabajos100   = $item->trabajos()->where('porcentaje_avance', 100)->count();

        if ($totalTrabajos > 0 && $totalTrabajos === $trabajos100) {
            $item->update(['estado_item' => 'terminado']);
        }

        $op = $item->op;
        if (! $op) return;

        // Si ya hay avance real registrado y la OP seguía en "Borrador" o
        // "Confirmada" (nadie hizo el clic manual de "Cambiar estado"), el
        // trabajo real en planta es la prueba de que la producción ya
        // empezó — el estado se pone al día solo, en vez de mostrar
        // "Borrador" mientras un operario ya está avanzando pasos.
        if ($avance > 0) {
            $op->iniciarProduccionSiAplica();
        }

        // El progreso general de la OP (el que se ve en el detalle) se
        // recalcula cada vez que cambia el avance de cualquier trabajo —
        // antes solo se actualizaba desde algunos controladores puntuales y
        // se quedaba pegado en 0% si el cambio venía de otro camino (como
        // marcar un paso desde Programador/Trabajos).
        $op->recalcularProgreso();

        if ($totalTrabajos > 0 && $totalTrabajos === $trabajos100) {
            // Si con este ítem ya quedaron todos terminados, la OP pasa
            // sola a "calidad" — sin esto había que entrar manualmente a
            // cambiar el estado aunque la producción ya estuviera completa.
            $op->revisarTransicionCalidad();
        }
    }

    /**
     * Pone al día el arranque y el cierre de la unidad.
     *
     * Va aquí —dentro de `recalcularAvance()`— y no en cada pantalla, porque este es el punto
     * único por el que pasa cualquier cambio de avance, venga del código QR, del panel de la
     * orden, de la hoja o del tablero. Escrito en las cuatro, la fecha dependería de por dónde
     * entró quien marcó el paso.
     *
     * `terminado_at` se retira si la unidad deja de estar completa: una que volvió a reproceso
     * no terminó nada, y dejar la fecha puesta diría que sí.
     */
    private function sellarFechasDeProceso(float $avance): void
    {
        $primerToque = $this->pasos()
            ->where(fn ($q) => $q->whereNotNull('iniciado_at')->orWhereNotNull('completado_at'))
            ->orderByRaw('least(coalesce(iniciado_at, completado_at), coalesce(completado_at, iniciado_at))')
            ->first();

        $arranque = $primerToque
            ? ($primerToque->iniciado_at ?? $primerToque->completado_at)
            : null;

        $cierre = $avance >= 100
            ? $this->pasos()->max('completado_at')
            : null;

        if ($this->iniciado_at?->toDateTimeString() !== $arranque?->toDateTimeString()
            || (string) $this->terminado_at !== (string) $cierre) {
            $this->update([
                'iniciado_at'  => $arranque,
                'terminado_at' => $cierre,
            ]);
        }
    }

    /** Lo que calidad tiene que revisar en esta unidad. */
    public function checks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\OpItemTrabajoCheck::class, 'op_item_trabajo_id')->orderBy('orden');
    }

    /** Si a esta unidad todavía le falta calidad: algo sin revisar, o una falla crítica. */
    public function calidadPendiente(): bool
    {
        return $this->checks()->where(function ($q) {
            $q->where('resultado', 'pendiente')
              ->orWhere(fn ($q2) => $q2->where('resultado', 'falla')->where('es_critico', true));
        })->exists();
    }
}
