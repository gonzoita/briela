<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpItemTrabajoPaso extends Model
{
    protected $table = 'op_item_trabajo_pasos';

    protected $fillable = [
        'op_item_trabajo_id',
        'template_paso_id',
        'nombre',
        'descripcion_resuelta',
        'peso_porcentaje',
        'completado',
        'completado_at',
        'iniciado_at',
        'operario_id',
        'tiempo_minutos',
        'fotos',
        'es_extra',
        'orden',
        'estacion_trabajo_id',
        'fecha_programada',
        'colaborador_programado_id',
        'tiempo_estimado_minutos',
        'depende_de',
        'es_paso_final',
    ];

    protected $casts = [
        'peso_porcentaje'  => 'decimal:2',
        'completado'       => 'boolean',
        'completado_at'    => 'datetime',
        'iniciado_at'      => 'datetime',
        'es_extra'         => 'boolean',
        'orden'            => 'integer',
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha_programada' => 'date:Y-m-d',
        'depende_de'       => 'array',
        'fotos'            => 'array',
        'es_paso_final'    => 'boolean',
    ];

    public function duracionRealMinutos(): ?int
    {
        if (!$this->iniciado_at || !$this->completado_at) return null;
        // diffInMinutes() devuelve float en las versiones recientes de
        // Carbon — se redondea acá para que nunca se filtre un decimal
        // largo a la vista (ej. "14.818557716666675").
        return (int) round($this->iniciado_at->diffInMinutes($this->completado_at));
    }

    public function puedeIniciarse(): bool
    {
        if (empty($this->depende_de)) {
            return true;
        }
        return $this->trabajo->pasos()
            ->whereIn('orden', $this->depende_de)
            ->where('completado', false)
            ->doesntExist();
    }

    public function trabajo(): BelongsTo
    {
        return $this->belongsTo(OpItemTrabajo::class, 'op_item_trabajo_id');
    }

    public function templatePaso(): BelongsTo
    {
        return $this->belongsTo(TemplateTrabajoPaso::class, 'template_paso_id');
    }

    public function operario(): BelongsTo
    {
        return $this->belongsTo(Operario::class);
    }

    public function operarios(): HasMany
    {
        return $this->hasMany(OpItemTrabajoPasoOperario::class, 'op_item_trabajo_paso_id');
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(EstacionTrabajo::class, 'estacion_trabajo_id');
    }

    public function colaboradorProgramado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'colaborador_programado_id');
    }
}
