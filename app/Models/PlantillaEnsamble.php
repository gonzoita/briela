<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\PlantillaSeccion;

class PlantillaEnsamble extends Model
{
    use SoftDeletes;

    protected $table = 'plantillas_ensamble';

    protected $fillable = [
        'nombre',
        'descripcion',
        'config_salida',
        'activo',
        'orden',
    ];

    protected $casts = [
        'activo'      => 'boolean',
        'orden'       => 'integer',
        'config_salida' => 'array',
    ];

    public function campos(): HasMany
    {
        return $this->hasMany(PlantillaCampo::class, 'plantilla_id')->orderBy('orden');
    }

    public function componentes(): HasMany
    {
        return $this->hasMany(PlantillaComponente::class, 'plantilla_id')->orderBy('orden');
    }

    public function secciones(): HasMany
    {
        return $this->hasMany(PlantillaSeccion::class, 'plantilla_id')->orderBy('orden');
    }

    public function ensambles(): HasMany
    {
        return $this->hasMany(Ensamble::class, 'plantilla_id');
    }

    // Fusión Plantillas de Ensamble <-> Plantillas de Trabajo: cada plantilla
    // de ensamble tiene emparejado 1 a 1 (por decisión de negocio) un único
    // TemplateTrabajo que guarda sus pasos de producción. Reutilizamos la
    // tabla existente (templates_trabajo) en vez de crear una nueva, para no
    // tocar el historial de OPs/trabajos ya generados con el sistema viejo.
    public function templateTrabajo(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TemplateTrabajo::class, 'plantilla_ensamble_id');
    }

    /**
     * Devuelve el TemplateTrabajo emparejado con esta plantilla, creándolo
     * vacío (sin pasos) la primera vez que se necesite. Así no hace falta
     * ninguna migración de backfill para las plantillas que ya existen.
     */
    public function obtenerOCrearTemplateTrabajo(): TemplateTrabajo
    {
        return $this->templateTrabajo ?? TemplateTrabajo::firstOrCreate(
            ['plantilla_ensamble_id' => $this->id],
            ['nombre' => $this->nombre, 'activo' => true]
        );
    }

    /**
     * Los puntos que calidad revisa en cada unidad.
     *
     * Misma regla que los pasos de producción: un ensamble directo los tiene propios; uno con
     * plantilla usa los de la plantilla, y los comparten todos los ensambles que la usan.
     */
    public function checksCalidad(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(\App\Models\ChecklistCalidad::class, 'checkeable')->orderBy('orden');
    }
}
