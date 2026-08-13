<?php

namespace App\Services;

use App\Models\Configuracion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Los recados que Briela le deja a esta instalación.
 *
 * **Nadie entra a este servidor desde afuera.** El recado viaja dentro de la respuesta
 * del latido de la licencia —la instalación ya pregunta cada pocas horas— y el resultado
 * se informa de vuelta por HTTP. Así funciona igual detrás de un cortafuegos, sin puerto
 * abierto y con IP cambiante, y Briela nunca necesita credenciales del servidor del
 * cliente (regla 5 del producto instalable).
 *
 * Hoy el único recado es respaldar la base de datos, que es lo que se pide cuando hay
 * que revisar un problema del cliente o antes de una migración delicada.
 *
 * **La ejecución no ocurre durante una petición web.** Un respaldo de una base grande
 * tarda, y hacerlo mientras alguien carga una pantalla le dejaría el navegador colgado.
 * Se guardan y las ejecuta `briela:ordenes` desde el cron. Si el hosting no tiene cron,
 * la pantalla de respaldos las muestra con un botón para ejecutarlas a mano: degradar
 * con elegancia es mejor que no funcionar.
 */
class OrdenesBrielaService
{
    private const CLAVE = 'briela_ordenes_pendientes';

    /** Cuántas se guardan como máximo, para que la clave no crezca sin control. */
    private const TOPE = 20;

    /**
     * Guarda los recados que llegaron en el latido.
     *
     * Se ignoran los repetidos por id: el servidor los marca como entregados al
     * mandarlos, pero si una respuesta llega dos veces —un reintento, dos pestañas— no
     * se debe respaldar dos veces.
     *
     * @param  array<int, array{id: int|string, tipo: string}>  $recados
     */
    public function recibir(array $recados): void
    {
        if ($recados === []) {
            return;
        }

        $pendientes = $this->pendientes();
        $conocidos  = collect($pendientes)->pluck('id')->all();
        $hechas     = $this->yaHechas();

        foreach ($recados as $recado) {
            $id = $recado['id'] ?? null;

            if ($id === null || in_array($id, $conocidos, true) || in_array($id, $hechas, true)) {
                continue;
            }

            $pendientes[] = [
                'id'         => $id,
                'tipo'       => $recado['tipo'] ?? 'desconocido',
                'recibida_at' => now()->toIso8601String(),
            ];
        }

        $this->guardar(array_slice($pendientes, -self::TOPE));
    }

    /** @return array<int, array<string, mixed>> */
    public function pendientes(): array
    {
        $json = (string) Configuracion::get(self::CLAVE, '');

        return $json !== '' ? (json_decode($json, true) ?: []) : [];
    }

    /**
     * Ejecuta todo lo pendiente y le informa el resultado a Briela.
     *
     * @return array<int, string> Lo que pasó con cada una, para poder mostrarlo.
     */
    public function ejecutarPendientes(): array
    {
        $resultados = [];

        foreach ($this->pendientes() as $orden) {
            $resultados[] = $this->ejecutar($orden);
        }

        return $resultados;
    }

    /**
     * Una sola orden.
     *
     * Un recado que falla también se informa: para quien lo pidió, «falló porque no hay
     * permisos en la carpeta de respaldos» es una respuesta útil, y el silencio no.
     */
    public function ejecutar(array $orden): string
    {
        $tipo = $orden['tipo'] ?? '';

        try {
            $resultado = match ($tipo) {
                'respaldo_bd' => $this->respaldarBase(),
                default       => throw new \RuntimeException("Esta versión de Briela no sabe hacer «{$tipo}». Actualiza el sistema."),
            };

            $this->informar($orden['id'], true, $resultado['mensaje'], $resultado['detalle']);
            $this->cerrar($orden['id']);

            return $resultado['mensaje'];
        } catch (Throwable $e) {
            $this->informar($orden['id'], false, $e->getMessage());

            // Se cierra igual, aunque haya fallado: reintentar solo un respaldo que falla
            // por permisos es repetir el mismo error cada hora. Quien lo pidió ve el
            // motivo en el panel y decide.
            $this->cerrar($orden['id']);

            Log::warning('Orden de Briela fallida: '.$e->getMessage(), ['orden' => $orden]);

            return 'Falló: '.$e->getMessage();
        }
    }

    /** @return array{mensaje: string, detalle: array<string, mixed>} */
    private function respaldarBase(): array
    {
        $respaldo = app(BackupService::class)->generar('briela');

        $mb = round($respaldo['bytes'] / 1048576, 2);

        return [
            'mensaje' => "Respaldo hecho: {$respaldo['nombre']} ({$mb} MB, por {$respaldo['metodo']}).",
            'detalle' => [
                'nombre' => $respaldo['nombre'],
                'bytes'  => $respaldo['bytes'],
                'metodo' => $respaldo['metodo'],
            ],
        ];
    }

    /**
     * Le cuenta a Briela cómo fue.
     *
     * No lanza si no se puede: el respaldo ya se hizo, y perder el aviso no debe
     * deshacerlo. Quedará como «recogida» en el panel y se puede pedir otro.
     */
    private function informar(int|string $id, bool $ok, string $mensaje, array $detalle = []): void
    {
        $serial = app(LicenciaService::class)->serial();

        if ($serial === null) {
            return;
        }

        try {
            Http::timeout(10)->acceptJson()->post(
                rtrim((string) config('briela.licencia_url'), '/').'/api/licencia/orden',
                [
                    'serial'    => $serial,
                    'orden_id'  => $id,
                    'ok'        => $ok,
                    'resultado' => mb_substr($mensaje, 0, 1000),
                    'detalle'   => $detalle ?: null,
                ]
            );
        } catch (Throwable $e) {
            Log::info('No se pudo informar el resultado de la orden '.$id.': '.$e->getMessage());
        }
    }

    /** Saca la orden de la lista de pendientes y la anota como hecha. */
    private function cerrar(int|string $id): void
    {
        $this->guardar(collect($this->pendientes())->reject(fn ($o) => ($o['id'] ?? null) === $id)->values()->all());

        // Se recuerdan las últimas para no repetirlas si el servidor las vuelve a mandar.
        $hechas = collect($this->yaHechas())->push($id)->unique()->take(-self::TOPE)->values()->all();

        Configuracion::set(self::CLAVE.'_hechas', json_encode($hechas));
    }

    /** @return array<int, int|string> */
    private function yaHechas(): array
    {
        $json = (string) Configuracion::get(self::CLAVE.'_hechas', '');

        return $json !== '' ? (json_decode($json, true) ?: []) : [];
    }

    private function guardar(array $pendientes): void
    {
        Configuracion::set(self::CLAVE, json_encode(array_values($pendientes)));
    }
}
