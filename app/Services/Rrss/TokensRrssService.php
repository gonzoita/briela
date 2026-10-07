<?php

namespace App\Services\Rrss;

use App\Models\CuentaRrss;
use App\Models\User;
use App\Services\NotificacionService;
use Illuminate\Support\Facades\Log;

/**
 * Vigila que no se venza el permiso de ninguna red.
 *
 * **El problema que resuelve.** El token que Meta entrega al conectar una página dura unos 60
 * días. No se renovaba ni se avisaba: a los dos meses las publicaciones de Facebook e
 * Instagram empezaban a fallar, y el único rastro era un «parcial» en la lista de
 * publicaciones. Una empresa que programa su contenido con dos semanas de anticipación se
 * enteraba cuando ya se habían dejado de publicar tres cosas.
 *
 * **Renovar primero, avisar después.** Lo que se puede renovar sin molestar a nadie se renueva
 * —Meta estirando el token, Google con su refresh_token—, y solo se avisa de lo que de verdad
 * necesita que una persona vuelva a autorizar. Un aviso que llega cuando el sistema podía
 * haberlo arreglado solo es ruido, y el ruido enseña a ignorar los avisos.
 *
 * **Se avisa con antelación, no cuando ya falló.** A los 15 días quedan dos fines de semana
 * para que alguien entre a reconectar. Avisar el día del vencimiento es avisar tarde.
 */
class TokensRrssService
{
    /** Desde cuándo se intenta renovar. Con margen: renovar sirve solo mientras el token vive. */
    private const DIAS_PARA_RENOVAR = 20;

    /** Desde cuándo se le avisa a una persona. */
    private const DIAS_PARA_AVISAR = 15;

    public function __construct(
        private readonly MetaRrssService $meta,
        private readonly GoogleBusinessRrssService $google,
        private readonly NotificacionService $notificaciones,
    ) {
    }

    /**
     * Revisa todas las cuentas conectadas.
     *
     * @return array{renovadas: array<int, string>, avisadas: array<int, string>, vencidas: array<int, string>}
     */
    public function revisar(): array
    {
        $resultado = ['renovadas' => [], 'avisadas' => [], 'vencidas' => []];

        // Instagram se publica con el token de la página de Facebook a la que está ligado, así
        // que se renueva con ella: pedirle a Meta que renueve el mismo token dos veces es una
        // llamada de más y un riesgo de pisar el valor recién guardado.
        $cuentas = CuentaRrss::where('activa', true)
            ->whereNotNull('token_expira_en')
            ->whereNot('red', 'instagram')
            ->get();

        foreach ($cuentas as $cuenta) {
            $dias = $this->diasQueFaltan($cuenta->token_expira_en);

            if ($dias > self::DIAS_PARA_RENOVAR) {
                continue;
            }

            if ($this->intentarRenovar($cuenta, $dias)) {
                $resultado['renovadas'][] = $this->nombre($cuenta);

                continue;
            }

            if ($dias < 0) {
                $resultado['vencidas'][] = $this->nombre($cuenta);
                $this->avisar($cuenta, $dias);

                continue;
            }

            if ($dias <= self::DIAS_PARA_AVISAR) {
                $resultado['avisadas'][] = $this->nombre($cuenta);
                $this->avisar($cuenta, $dias);
            }
        }

        return $resultado;
    }

    /**
     * Cuántos días faltan, redondeando hacia arriba.
     *
     * `diffInDays` devuelve un decimal y truncar lo deja corto: un token que vence en 10 días
     * menos unos microsegundos daba 9, y el aviso decía «vence en 9 días» el mismo día en que
     * se conectó la cuenta. No es un detalle: alguien que lee «9 días» y cuenta en el
     * calendario encuentra que no cuadra, y deja de confiar en el aviso.
     */
    private function diasQueFaltan(\Illuminate\Support\Carbon $vence): int
    {
        return (int) ceil(now()->diffInDays($vence, false));
    }

    /**
     * Intenta renovar. Devuelve si lo logró.
     *
     * LinkedIn no entra: sus tokens de página duran 60 días y renovarlos exige un permiso que
     * LinkedIn aprueba aparte. Mientras no esté aprobado, lo único honesto es avisar para que
     * alguien reconecte, en vez de intentar una llamada que se sabe que va a fallar.
     */
    private function intentarRenovar(CuentaRrss $cuenta, int $dias): bool
    {
        // Un token ya vencido no se puede renovar con él mismo: hay que reconectar a mano.
        if ($dias < 0) {
            return false;
        }

        try {
            match ($cuenta->red) {
                'facebook'        => $this->meta->renovarToken($cuenta),
                'google_business' => $this->google->renovarToken($cuenta),
                default           => throw new \RuntimeException('sin renovación automática'),
            };

            Log::info('RRSS: token renovado solo.', ['cuenta' => $cuenta->id, 'red' => $cuenta->red]);

            return true;
        } catch (\Throwable $e) {
            // No es un error del sistema: es que hay que reconectar. Se anota en la cuenta para
            // que la pantalla lo muestre, y se cae al aviso.
            $cuenta->update(['ultimo_error' => 'No se pudo renovar el permiso: ' . $e->getMessage()]);

            return false;
        }
    }

    /**
     * Avisa a quien pueda arreglarlo.
     *
     * Va a los administradores y no al rol de quien publica: reconectar una cuenta exige entrar
     * al Business Manager de la empresa, y eso no lo puede hacer quien solo programa
     * contenido. Un aviso dirigido a alguien que no puede resolverlo es un aviso que nadie
     * atiende.
     */
    private function avisar(CuentaRrss $cuenta, int $dias): void
    {
        $nombre = $this->nombre($cuenta);

        [$titulo, $mensaje] = $dias < 0
            ? [
                "Se venció el permiso de {$nombre}",
                "Las publicaciones a {$nombre} están fallando. Hay que volver a conectar la cuenta.",
            ]
            : [
                "El permiso de {$nombre} vence en {$dias} día" . ($dias === 1 ? '' : 's'),
                "Cuando venza, las publicaciones a {$nombre} van a empezar a fallar. "
                    . 'Entra a Redes Sociales → Cuentas y vuelve a conectarla.',
            ];

        foreach (User::where('rol', 'administrador')->where('activo', true)->get() as $admin) {
            $this->notificaciones->crear(
                $admin->id,
                'rrss_token_por_vencer',
                $titulo,
                $mensaje,
                '/rrss/cuentas',
            );
        }
    }

    private function nombre(CuentaRrss $cuenta): string
    {
        $red = match ($cuenta->red) {
            'facebook'        => 'Facebook',
            'instagram'       => 'Instagram',
            'linkedin'        => 'LinkedIn',
            'google_business' => 'Google Business',
            default           => $cuenta->red,
        };

        return "{$red} · {$cuenta->nombre_cuenta}";
    }
}
