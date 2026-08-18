<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un agente que atiende por fuera: la web, WhatsApp, lo que venga.
 *
 * El campo que manda es `perfil`, y no es una etiqueta: decide **qué catálogo de consultas** ve.
 * `publico` solo alcanza lo que ya es público por otro lado —quién es la empresa, contacto,
 * catálogo—; `cliente` alcanza los datos de UNA persona, y solo después de que esa persona
 * demuestre quién es.
 */
class AgenteIa extends Model
{
    protected $table = 'agentes_ia';

    protected $fillable = [
        'nombre', 'descripcion', 'activo', 'perfil', 'canales', 'herramientas',
        'instrucciones', 'saludo', 'escalamiento', 'horario', 'orden', 'creado_por',
    ];

    protected $casts = [
        'activo'       => 'boolean',
        'canales'      => 'array',
        'herramientas' => 'array',
        'escalamiento' => 'array',
        'horario'      => 'array',
        'orden'        => 'integer',
    ];

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function atiendeCanal(string $canal): bool
    {
        return in_array($canal, $this->canales ?? [], true);
    }

    public function escala(string $motivo): bool
    {
        return in_array($motivo, $this->escalamiento ?? [], true);
    }

    /**
     * Está dentro de su horario de atención.
     *
     * Sin horario configurado atiende siempre: un agente que se apaga sin que nadie se lo haya
     * pedido es peor que uno que contesta de madrugada.
     */
    public function enHorario(): bool
    {
        $h = $this->horario ?? [];

        if (empty($h['desde']) || empty($h['hasta'])) {
            return true;
        }

        $ahora = now()->format('H:i');

        return $ahora >= $h['desde'] && $ahora <= $h['hasta'];
    }

    /** El que atiende un canal, del perfil pedido. El orden decide cuando hay varios. */
    public static function paraCanal(string $canal, string $perfil): ?self
    {
        return static::where('activo', true)
            ->where('perfil', $perfil)
            ->orderBy('orden')
            ->get()
            ->first(fn ($a) => $a->atiendeCanal($canal));
    }
}
