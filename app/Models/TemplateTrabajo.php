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
        // Un flujo de trabajo cuelga de una plantilla del cotizador, o —cuando el ensamble
        // es directo y no tiene plantilla— del ensamble mismo.
        'ensamble_id',
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

    public function ensamble(): BelongsTo
    {
        return $this->belongsTo(Ensamble::class, 'ensamble_id');
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
     * Reemplaza todos los pasos de este template por el array recibido.
     *
     * Lo usan los dos sitios donde se editan pasos: la ficha del ensamble —cuando es
     * directo— y la pestaña de producción de la plantilla. Borra y recrea, así que quien
     * guarda manda la lista completa, no un parche.
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
                'bodega_destino_id'=> $paso['bodega_destino_id'] ?? null,
                'imagen'           => $paso['imagen'] ?? null,
                'archivo_plano'    => $paso['archivo_plano'] ?? null,
            ]);
        }
    }
}
