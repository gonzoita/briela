<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TemplateTrabajo extends Model
{
    use SoftDeletes;

    protected $table = 'templates_trabajo';

    protected $fillable = [
        'plantilla_ensamble_id',
        'nombre',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function plantillaEnsamble(): BelongsTo
    {
        return $this->belongsTo(PlantillaEnsamble::class, 'plantilla_ensamble_id');
    }

    public function pasos(): HasMany
    {
        return $this->hasMany(TemplateTrabajoPaso::class, 'template_id')->orderBy('orden');
    }

    public function trabajos(): HasMany
    {
        return $this->hasMany(OpItemTrabajo::class, 'template_id');
    }

    public function sumaPesos(): float
    {
        return (float) $this->pasos->sum('peso_porcentaje');
    }

    /**
     * Reemplaza todos los pasos de este template por el array recibido
     * (mismo contrato que usaba TemplateTrabajoController::syncPasos).
     * Compartido entre el módulo viejo de Plantillas de Trabajo y la nueva
     * pestaña "Pasos de producción" dentro de Plantillas de Ensamble.
     */
    public function sincronizarPasos(array $pasos): void
    {
        $this->pasos()->delete();
        foreach ($pasos as $idx => $paso) {
            TemplateTrabajoPaso::create([
                'template_id'      => $this->id,
                'nombre'           => $paso['nombre'],
                'objetivo'         => $paso['objetivo'] ?? null,
                'descripcion'      => $paso['descripcion'] ?? '',
                'peso_porcentaje'  => $paso['peso_porcentaje'],
                'orden'            => $paso['orden'] ?? $idx,
                'nivel_dificultad' => $paso['nivel_dificultad'] ?? 1,
                'depende_de'       => $paso['depende_de'] ?? [],
                'es_paso_final'    => $paso['es_paso_final'] ?? false,
                'imagen'           => $paso['imagen'] ?? null,
                'archivo_plano'    => $paso['archivo_plano'] ?? null,
            ]);
        }
    }
}
