<?php

namespace App\Services;

use App\Models\Configuracion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * El lado de la instalación: pregunta por su licencia y guarda la respuesta.
 *
 * Dos decisiones que dan forma a todo esto:
 *
 * **La gracia sin conexión.** Si el servidor de licencias no responde, la instalación
 * sigue trabajando con lo último que supo durante los días de gracia. Sin eso, una
 * caída del servidor de Briela dejaría a todos los clientes sin sistema a la vez, y
 * ese daño es peor que el de un pago atrasado.
 *
 * **Vencer no bloquea.** Una suscripción vencida avisa en cada carga y deja de
 * recibir actualizaciones y de usar la IA, pero no detiene la operación: quien está
 * facturando a las once de la mañana no puede quedarse sin sistema por una fecha.
 */
class LicenciaService
{
    /** Días que la instalación aguanta sin poder consultar. */
    public const DIAS_GRACIA = 7;

    /** Cada cuántas horas se vuelve a preguntar. */
    private const HORAS_ENTRE_LATIDOS = 6;

    public function serial(): ?string
    {
        $serial = trim((string) (Configuracion::get('briela_serial', '') ?: config('briela.serial', '')));

        return $serial !== '' ? $serial : null;
    }

    public function guardarSerial(string $serial): void
    {
        Configuracion::set('briela_serial', trim($serial));
        Cache::forget('briela.licencia');
    }

    private function url(): string
    {
        return rtrim((string) config('briela.licencia_url', 'https://superadmin.briela.app'), '/');
    }

    /**
     * El estado de la licencia, tal como se conoce ahora.
     *
     * No consulta en cada llamada: usa lo guardado y solo pregunta cuando toca. Lo
     * pide cada pantalla, y una petición HTTP en cada carga volvería el sistema
     * lento y dependiente de que el servidor de licencias siempre responda.
     */
    public function estado(): array
    {
        $guardado = $this->guardado();

        if ($this->tocaPreguntar($guardado)) {
            $fresco = $this->preguntar();

            if ($fresco !== null) {
                return $fresco;
            }
        }

        return $this->conGracia($guardado);
    }

    /** Fuerza la consulta, para el botón de "comprobar ahora". */
    public function refrescar(): array
    {
        Cache::forget('briela.licencia');

        return $this->preguntar() ?? $this->conGracia($this->guardado());
    }

    private function guardado(): array
    {
        $json = (string) Configuracion::get('briela_licencia_estado', '');

        return $json !== '' ? (json_decode($json, true) ?: []) : [];
    }

    private function tocaPreguntar(array $guardado): bool
    {
        if ($this->serial() === null) {
            return false;
        }

        $ultima = $guardado['consultado_at'] ?? null;

        return $ultima === null
            || now()->diffInHours(\Illuminate\Support\Carbon::parse($ultima), true) >= self::HORAS_ENTRE_LATIDOS;
    }

    /**
     * Pregunta al servidor de licencias. Devuelve null si no se pudo.
     *
     * Cualquier fallo devuelve null y no lanza: que el servidor de licencias esté
     * caído no puede tumbar la instalación de un cliente.
     */
    private function preguntar(): ?array
    {
        $serial = $this->serial();

        if ($serial === null) {
            return null;
        }

        try {
            $resp = Http::timeout(8)
                ->acceptJson()
                ->post($this->url() . '/api/licencia/validar', [
                    'serial'  => $serial,
                    'version' => $this->versionInstalada(),
                    'dominio' => request()?->getHost(),
                ]);

            if (! $resp->successful() || ! ($resp->json('ok') ?? false)) {
                // Un serial que el servidor no reconoce sí se guarda: es una
                // respuesta legítima y hay que mostrarla.
                if ($resp->status() === 404) {
                    return $this->guardar([
                        'valido'  => false,
                        'estado'  => 'desconocido',
                        'mensaje' => 'El serial no está registrado.',
                    ]);
                }

                return null;
            }

            $licencia = $resp->json('licencia');

            // Los recados que Briela dejó viajan en la misma respuesta. Aquí solo se
            // guardan: ejecutarlos durante una petición web dejaría a alguien mirando una
            // pantalla en blanco mientras se respalda la base. Los corre `briela:ordenes`.
            app(OrdenesBrielaService::class)->recibir((array) ($licencia['ordenes'] ?? []));

            return $this->guardar([
                'valido'           => true,
                'estado'           => $licencia['estado'] ?? 'activa',
                'al_dia'           => (bool) ($licencia['al_dia'] ?? false),
                'puede_ia'         => (bool) ($licencia['puede_ia'] ?? false),
                'vence_el'         => $licencia['vence_el'] ?? null,
                'dias_para_vencer' => $licencia['dias_para_vencer'] ?? null,
                'cliente'          => $licencia['cliente'] ?? null,
                'actualizacion'    => $licencia['actualizacion'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::info('Licencia: no se pudo consultar. ' . $e->getMessage());

            return null;
        }
    }

    private function guardar(array $estado): array
    {
        $estado['consultado_at'] = now()->toIso8601String();

        Configuracion::set('briela_licencia_estado', json_encode($estado));

        return $estado;
    }

    /**
     * Aplica la gracia a lo último que se supo.
     *
     * Mientras la gracia dure, la instalación se comporta como en la última respuesta.
     * Cuando se agota, se avisa de que no se puede verificar — pero sin bloquear.
     */
    private function conGracia(array $guardado): array
    {
        if ($guardado === []) {
            return [
                'valido'         => false,
                'estado'         => 'sin_serial',
                'al_dia'         => true,
                'puede_ia'       => false,
                'sin_verificar'  => true,
                'mensaje'        => 'Esta instalación todavía no tiene serial.',
            ];
        }

        $consultado = $guardado['consultado_at'] ?? null;
        $dias = $consultado
            ? now()->diffInDays(\Illuminate\Support\Carbon::parse($consultado))
            : 999;

        $guardado['dias_sin_verificar'] = $dias;
        $guardado['sin_verificar']      = $dias > self::DIAS_GRACIA;

        if ($guardado['sin_verificar']) {
            $guardado['mensaje'] = "No se ha podido verificar la licencia en {$dias} días. "
                . 'Comprueba que el servidor tenga salida a internet.';
        }

        return $guardado;
    }

    public function versionInstalada(): ?string
    {
        $archivo = base_path('version.txt');

        return is_file($archivo) ? trim((string) file_get_contents($archivo)) : null;
    }

    /** La actualización disponible, si la hay y si la licencia da derecho. */
    public function actualizacionDisponible(): ?array
    {
        $estado = $this->estado();

        return $estado['actualizacion'] ?? null;
    }

    /**
     * Lo que necesita la interfaz para decidir si muestra el aviso.
     *
     * **Solo lee lo guardado, nunca consulta.** Esto se llama en cada carga de
     * página, y si preguntara por HTTP, una caída del servidor de licencias haría
     * que cada pantalla del cliente tardara los ocho segundos del tiempo de espera.
     * De preguntar se encarga el comando briela:latido, desde el cron.
     *
     * Se resume aquí y no en cada pantalla, para que el aviso diga lo mismo en todas.
     */
    public function paraInterfaz(): array
    {
        $estado = $this->conGracia($this->guardado());
        $dias   = $estado['dias_para_vencer'] ?? null;

        return [
            'estado'          => $estado['estado'] ?? 'sin_serial',
            'al_dia'          => (bool) ($estado['al_dia'] ?? true),
            'vence_el'        => $estado['vence_el'] ?? null,
            'dias'            => $dias,
            // Se avisa desde una semana antes: da tiempo a pagar sin prisa.
            'por_vencer'      => $dias !== null && $dias >= 0 && $dias <= 7,
            'sin_verificar'   => (bool) ($estado['sin_verificar'] ?? false),
            'mensaje'         => $estado['mensaje'] ?? null,
            'version'         => $this->versionInstalada(),
            'actualizacion'   => $estado['actualizacion'] ?? null,
        ];
    }
}
