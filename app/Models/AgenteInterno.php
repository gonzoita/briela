<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un agente interno: quien ocupa un cargo del equipo de IA.
 *
 * No es `AgenteIa`, que atiende a clientes por fuera. Este lee los datos del negocio con el rol
 * de su usuario de servicio y deja avisos, resúmenes y propuestas.
 */
class AgenteInterno extends Model
{
    use SoftDeletes;

    protected $table = 'agentes_internos';

    public const MODOS = ['solo_lectura', 'propone'];

    /** Horas después de su hora en las que un agente programado todavía arranca solo. */
    private const VENTANA_MINUTOS = 180;

    protected $fillable = [
        'clave', 'cargo_id', 'nombre', 'descripcion', 'avatar', 'color', 'instrucciones', 'tarea',
        'herramientas', 'modelo', 'modelo_respaldo', 'nivel_modelo', 'temperatura', 'max_tokens',
        'max_pasos', 'max_tokens_ejecucion', 'frecuencia', 'modo', 'avisar_a', 'usuario_id', 'sede_id',
        'activo', 'ultima_ejecucion_at', 'orden', 'creado_por',
    ];

    protected $casts = [
        'herramientas'         => 'array',
        'frecuencia'           => 'array',
        'avisar_a'             => 'array',
        'activo'               => 'boolean',
        'temperatura'          => 'float',
        'max_tokens'           => 'integer',
        'max_pasos'            => 'integer',
        'max_tokens_ejecucion' => 'integer',
        'ultima_ejecucion_at'  => 'datetime',
    ];

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(CargoIa::class, 'cargo_id');
    }

    /** El usuario de servicio. Fuera del alcance global de `User`, que lo esconde de las listas. */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id')->withoutGlobalScopes();
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function ejecuciones(): HasMany
    {
        return $this->hasMany(AgenteEjecucion::class, 'agente_id');
    }

    public function ultimaEjecucion(): HasOne
    {
        return $this->hasOne(AgenteEjecucion::class, 'agente_id')->where('prueba', false)->latestOfMany();
    }

    public function propuestas(): HasMany
    {
        return $this->hasMany(AgentePropuesta::class, 'agente_id');
    }

    // ─── Lo que el agente hereda del cargo ────────────────────────────────────

    /**
     * Las herramientas con las que puede trabajar: las del agente, **recortadas al techo del
     * cargo**. Subir por encima del cargo exige editar el cargo, no el agente.
     *
     * @return list<string>
     */
    public function herramientasEfectivas(): array
    {
        $techo = $this->cargo?->herramientas;

        if ($techo === null) {
            return array_values($this->herramientas ?? []);
        }

        return array_values($this->herramientas === null
            ? $techo
            : array_values(array_intersect($this->herramientas, $techo)));
    }

    public function nivelEfectivo(): string
    {
        return $this->nivel_modelo ?: ($this->cargo?->nivel_modelo ?: 'economico');
    }

    public function maxPasosEfectivo(): int
    {
        return max(1, min(20, (int) ($this->max_pasos ?: ($this->cargo?->max_pasos ?: 8))));
    }

    public function maxTokensEfectivo(): int
    {
        return max(200, (int) ($this->max_tokens ?: ($this->cargo?->max_tokens ?: 1500)));
    }

    // ─── Cuándo le toca ───────────────────────────────────────────────────────

    /**
     * La clave de la ventana en la que debe correr, o null si ahora no le toca.
     *
     * Es también la clave de idempotencia de la corrida: dos pasadas del cron dentro de la
     * misma ventana producen la misma clave, y la segunda se descarta sola.
     */
    public function ventanaActual(CarbonInterface $ahora): ?string
    {
        $f    = $this->frecuencia ?? [];
        $tipo = $f['tipo'] ?? 'manual';

        if (! $this->activo || ! in_array($tipo, ['diaria', 'semanal'], true) || empty($f['hora'])) {
            return null;
        }

        if ($tipo === 'semanal' && ! in_array($ahora->dayOfWeekIso, array_map('intval', $f['dias'] ?? []), true)) {
            return null;
        }

        [$h, $m] = array_pad(array_map('intval', explode(':', (string) $f['hora'])), 2, 0);

        $desde = $ahora->copy()->setTime($h, $m);

        if ($ahora->lt($desde) || $ahora->gt($desde->copy()->addMinutes(self::VENTANA_MINUTOS))) {
            return null;
        }

        return "agente-{$this->id}:" . $ahora->toDateString();
    }

    // ─── Estado para la oficina ───────────────────────────────────────────────

    /**
     * libre | trabajando | terminado | aprobacion | error | apagado
     *
     * Se **deriva** de las ejecuciones y las propuestas, no se guarda: un estado guardado se
     * desincroniza el día que un proceso muere sin avisar.
     */
    public function estadoOficina(bool $enCurso, int $pendientes, ?AgenteEjecucion $ultima): string
    {
        if (! $this->activo) {
            return 'apagado';
        }

        if ($enCurso) {
            return 'trabajando';
        }

        if ($ultima && $ultima->estado === 'fallida' && $ultima->terminada_at?->gt(now()->subDay())) {
            return 'error';
        }

        if ($pendientes > 0) {
            return 'aprobacion';
        }

        if ($ultima && $ultima->estado === 'terminada' && $ultima->terminada_at?->gt(now()->subHours(12))) {
            return 'terminado';
        }

        return 'libre';
    }
}
