<?php

namespace App\Models\Concerns;

use App\Support\Canales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Lo que toda conversación sabe hacer, venga de WhatsApp o de una red.
 *
 * Vive en un trait y no en una clase base porque las dos tablas son distintas y ninguna hereda
 * de la otra —ver el encabezado de la migración de `bandeja_conversaciones`—, pero la **ventana
 * de servicio** y lo de «sin leer / archivada» son idénticos y tienen que seguir siéndolo. La
 * primera versión calculaba la ventana en dos sitios y daban respuestas distintas con el mismo
 * dato: una miraba el último mensaje y la otra el último entrante, y la de WhatsApp dejaba
 * escribir texto libre pasadas las 24 horas porque la empresa había contestado hace un rato.
 */
trait EsConversacion
{
    /** El canal al que pertenece. WhatsApp no guarda columna: es uno solo. */
    abstract public function canal(): string;

    /**
     * La clave que la pantalla usa para nombrar una conversación de cualquier canal.
     * Dos tablas, un solo identificador: «whatsapp:12», «instagram_dm:7».
     */
    public function claveBandeja(): string
    {
        return $this->canal() . ':' . $this->id;
    }

    // ─── La ventana de servicio de Meta ──────────────────────────────────────

    /**
     * ¿Se puede escribir texto libre ahora mismo?
     *
     * La cuenta corre desde el **último mensaje de la persona**, no desde el último mensaje de
     * la conversación: que la empresa haya contestado hace cinco minutos no reabre nada. Un
     * canal sin plazo —los comentarios— siempre está abierto.
     */
    public function ventanaAbierta(): bool
    {
        $horas = Canales::de($this->canal())['ventana'] ?? null;

        if ($horas === null) {
            return true;
        }

        $ultimo = $this->ultimo_entrante_at;

        return $ultimo instanceof Carbon && $ultimo->greaterThan(now()->subHours($horas));
    }

    /** Cuándo se cierra el plazo, o `null` si el canal no tiene ninguno. */
    public function ventanaVence(): ?Carbon
    {
        $horas = Canales::de($this->canal())['ventana'] ?? null;

        if ($horas === null || ! $this->ultimo_entrante_at instanceof Carbon) {
            return null;
        }

        return $this->ultimo_entrante_at->copy()->addHours($horas);
    }

    /**
     * El estado de la ventana, tal como lo necesita la pantalla.
     *
     * Devuelve también `motivo`, porque «no puedes escribir» sin explicación es lo que hace que
     * alguien reintente cinco veces y acabe llamando a soporte.
     *
     * @return array{abierta: bool, vence_at: ?string, restante: ?string, plantillas: bool, motivo: ?string}
     */
    public function estadoVentana(): array
    {
        $config  = Canales::de($this->canal()) ?? [];
        $abierta = $this->ventanaAbierta();
        $vence   = $this->ventanaVence();

        return [
            'abierta'    => $abierta,
            'vence_at'   => $vence?->toIso8601String(),
            'restante'   => $abierta && $vence ? $vence->diffForHumans(['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]) : null,
            'plantillas' => (bool) ($config['plantillas'] ?? false),
            'motivo'     => $abierta ? null : $this->motivoVentanaCerrada($config),
        ];
    }

    private function motivoVentanaCerrada(array $config): string
    {
        $horas = $config['ventana'] ?? Canales::VENTANA_HORAS;

        $base = $this->ultimo_entrante_at
            ? "Pasaron más de {$horas} horas desde el último mensaje de esta persona."
            : 'Esta persona todavía no ha escrito.';

        return $base . ' ' . (($config['plantillas'] ?? false)
            ? 'Meta solo permite escribir con una plantilla aprobada.'
            : 'Meta no permite escribir primero por este canal: hay que esperar a que escriban.');
    }

    // ─── Estado de la bandeja ────────────────────────────────────────────────

    public function estaArchivada(): bool
    {
        return $this->archivada_at !== null;
    }

    public function scopeSinArchivar(Builder $query): Builder
    {
        return $query->whereNull('archivada_at');
    }

    public function scopeSinLeer(Builder $query): Builder
    {
        return $query->where('leido', false);
    }

    /**
     * Marca el paso del tiempo con cada mensaje.
     *
     * Un mensaje entrante mueve las dos fechas y deja la conversación sin leer; uno saliente
     * solo mueve la última actividad. `ultimo_entrante_at` es lo que sostiene la ventana, así
     * que escribirlo desde un mensaje nuestro sería regalarnos 24 horas que Meta no dio.
     */
    public function registrarActividad(string $direccion, ?Carbon $cuando = null): void
    {
        $cuando  = $cuando ?? now();
        $cambios = ['ultimo_mensaje_at' => $cuando];

        if ($direccion === 'entrante') {
            $cambios['ultimo_entrante_at'] = $cuando;
            $cambios['leido']              = false;
            // Lo que vuelve a moverse vuelve a la bandeja: archivar no es bloquear.
            $cambios['archivada_at']       = null;
        }

        $this->forceFill($cambios)->save();
    }
}
