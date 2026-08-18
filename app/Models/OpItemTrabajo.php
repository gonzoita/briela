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
        'token_trabajo',
        'remisionado',
        // Cuándo entró a bodega la unidad y a cuál. Es el candado que evita que volver a
        // marcar el último paso meta la misma unidad dos veces al inventario.
        'entregado_at',
        'bodega_entrega_id',
    ];

    /** La bodega a la que entró esta unidad al terminarse. */
    public function bodegaEntrega(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Bodega::class, 'bodega_entrega_id');
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
        'entregado_at'      => 'datetime',
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

    public function scopeDisponiblesParaRemision($query)
    {
        return $query->where('porcentaje_avance', 100)->where('remisionado', false);
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
