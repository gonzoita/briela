<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cada cambio de instrucciones de un agente o de un cargo, para compararlo y deshacerlo.
 */
class PromptVersionIa extends Model
{
    protected $table = 'prompt_versiones_ia';

    public $timestamps = false;

    protected $fillable = [
        'origen_tipo', 'origen_id', 'version', 'instrucciones', 'tarea', 'nota', 'cambiado_por', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    /**
     * Guarda una versión nueva si el texto cambió respecto a la última. Devuelve null si no
     * hubo cambio: guardar la misma versión dos veces solo ensucia el historial.
     */
    public static function registrar(string $tipo, int $id, ?string $instrucciones, ?string $tarea, ?int $userId, ?string $nota = null): ?self
    {
        $ultima = static::where('origen_tipo', $tipo)->where('origen_id', $id)->orderByDesc('version')->first();

        if ($ultima && (string) $ultima->instrucciones === (string) $instrucciones && (string) $ultima->tarea === (string) $tarea) {
            return null;
        }

        return static::create([
            'origen_tipo'   => $tipo,
            'origen_id'     => $id,
            'version'       => ($ultima?->version ?? 0) + 1,
            'instrucciones' => $instrucciones,
            'tarea'         => $tarea,
            'nota'          => $nota,
            'cambiado_por'  => $userId,
            'created_at'    => now(),
        ]);
    }
}
