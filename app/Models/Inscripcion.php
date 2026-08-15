<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Inscripcion extends Model
{
    protected $table = 'inscripciones';

    protected $fillable = [
        'inscribible_id',
        'inscribible_type',
        'curso_id',
        'obligatorio',
        'asignado_por',
        'fecha_limite',
        'estado',
        'iniciado_at',
        'completado_at',
    ];

    protected $casts = [
        'obligatorio'    => 'boolean',
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha_limite'   => 'date:Y-m-d',
        'iniciado_at'    => 'datetime',
        'completado_at'  => 'datetime',
    ];

    public function inscribible(): MorphTo
    {
        return $this->morphTo();
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function progresos(): HasMany
    {
        return $this->hasMany(ProgresoLeccion::class);
    }

    public function intentos(): HasMany
    {
        return $this->hasMany(EvaluacionIntento::class);
    }

    public function certificado(): HasOne
    {
        return $this->hasOne(Certificado::class);
    }

    public function porcentajeAvance(): int
    {
        $totalLecciones = CursoLeccion::whereHas('modulo', fn ($q) => $q->where('curso_id', $this->curso_id))->count();

        if ($totalLecciones === 0) return 0;

        $completadas = $this->progresos()->where('completado', true)->count();

        return (int) round(($completadas / $totalLecciones) * 100);
    }

    public function marcarEnProgreso(): void
    {
        if ($this->estado !== 'pendiente') return;

        $this->update([
            'estado'      => 'en_progreso',
            'iniciado_at' => $this->iniciado_at ?? now(),
        ]);
    }

    public function marcarCompletado(): void
    {
        $this->update([
            'estado'         => 'completado',
            'completado_at'  => now(),
        ]);
    }

    public function moduloDesbloqueado(CursoModulo $modulo): bool
    {
        $modulos = $modulo->curso->modulos;
        $indice  = $modulos->search(fn (CursoModulo $m) => $m->id === $modulo->id);

        if ($indice === false || $indice === 0) {
            return true;
        }

        $anterior           = $modulos[$indice - 1];
        $evaluacionAnterior = $anterior->evaluacion;

        if (! $evaluacionAnterior) {
            return true;
        }

        return $this->intentos()
            ->where('curso_evaluacion_id', $evaluacionAnterior->id)
            ->where('aprobado', true)
            ->exists();
    }

    public function moduloCompletado(CursoModulo $modulo): bool
    {
        $idsLecciones   = $modulo->lecciones()->pluck('id');
        $totalLecciones = $idsLecciones->count();

        if ($totalLecciones === 0) return false;

        $completadas = $this->progresos()
            ->whereIn('curso_leccion_id', $idsLecciones)
            ->where('completado', true)
            ->count();

        return $completadas >= $totalLecciones;
    }
}
