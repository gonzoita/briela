<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistroActividad extends Model
{
    public $timestamps = false;

    protected $table = 'registros_actividad';

    protected $fillable = [
        'user_id', 'accion', 'modelo', 'modelo_id', 'descripcion', 'cambios', 'ip', 'created_at',
    ];

    protected $casts = [
        'cambios'    => 'array',
        'created_at' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Registra una entrada libre en la bitácora (para acciones que no son
     * un simple create/update/delete de Eloquent, ej. "movido de etapa").
     */
    public static function registrar(string $accion, string $modelo, ?int $modeloId, string $descripcion, array $cambios = []): void
    {
        static::create([
            'user_id'     => auth()->id(),
            'accion'      => $accion,
            'modelo'      => $modelo,
            'modelo_id'   => $modeloId,
            'descripcion' => $descripcion,
            'cambios'     => $cambios ?: null,
            'ip'          => request()?->ip(),
            'created_at'  => now(),
        ]);
    }
}
