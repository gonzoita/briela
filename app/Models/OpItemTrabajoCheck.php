<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que calidad revisó de UNA unidad física.
 *
 * Se copia de la plantilla al generar el trabajo y se queda congelado: cambiar la lista después
 * no reescribe lo que alguien ya miró. Guarda su propio título y descripción por eso mismo — si
 * dependiera de la plantilla, editar un punto cambiaría el historial de lo ya revisado.
 */
class OpItemTrabajoCheck extends Model
{
    protected $table = 'op_item_trabajo_checks';

    protected $fillable = [
        'op_item_trabajo_id',
        'checklist_calidad_id',
        'titulo',
        'descripcion',
        'orden',
        'exige_foto',
        'es_critico',
        'resultado',
        'observaciones',
        'fotos',
        'revisado_por',
        'revisado_at',
    ];

    protected $casts = [
        'orden'       => 'integer',
        'exige_foto'  => 'boolean',
        'es_critico'  => 'boolean',
        'fotos'       => 'array',
        'revisado_at' => 'datetime',
    ];

    public function trabajo(): BelongsTo
    {
        return $this->belongsTo(OpItemTrabajo::class, 'op_item_trabajo_id');
    }

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    /** Un punto sin resolver, o uno crítico que falló, impide dar la unidad por buena. */
    public function bloquea(): bool
    {
        return $this->resultado === 'pendiente' || ($this->resultado === 'falla' && $this->es_critico);
    }
}
