<?php

namespace App\Console\Commands;

use App\Models\Configuracion;
use App\Models\CorreoSupresion;
use App\Services\LicenciaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Baja del panel de Briela los rebotes, quejas y bajas de esta instalación.
 *
 * Se pregunta y no se espera un aviso, igual que con los recados del latido: el panel no
 * llama a los servidores de los clientes. Se recuerda hasta qué aviso se trajo, así que
 * cada pasada solo trae lo nuevo.
 */
class SincronizarEventosCorreo extends Command
{
    protected $signature = 'correo:sincronizar-eventos';

    protected $description = 'Trae del panel de Briela los rebotes, quejas y bajas, y los suprime';

    public function handle(LicenciaService $licencias): int
    {
        $serial = $licencias->serial();

        if ($serial === null || $licencias->correo() === null) {
            $this->line('El correo de esta instalación no sale por Briela: nada que traer.');

            return self::SUCCESS;
        }

        $desde = (int) Configuracion::get('correo_ultimo_evento', 0);
        $nuevos = 0;

        try {
            do {
                $resp = Http::timeout(20)->acceptJson()
                    ->withHeaders(['X-Briela-Serial' => $serial])
                    ->get(rtrim((string) config('briela.licencia_url'), '/') . '/api/correo/eventos', ['desde' => $desde]);

                if (! $resp->successful()) {
                    $this->warn('El panel respondió ' . $resp->status() . '.');
                    break;
                }

                $eventos = (array) ($resp->json('eventos') ?? []);

                foreach ($eventos as $e) {
                    if (in_array($e['tipo'] ?? '', ['rebote_duro', 'queja', 'baja'], true)) {
                        CorreoSupresion::firstOrCreate(
                            ['email' => strtolower((string) $e['email'])],
                            ['motivo' => $e['tipo'], 'detalle' => mb_substr((string) ($e['detalle'] ?? ''), 0, 500) ?: null],
                        );
                        $nuevos++;
                    }

                    $desde = max($desde, (int) $e['id']);
                }

                Configuracion::set('correo_ultimo_evento', (string) $desde);
            } while (count($eventos) >= 500);
        } catch (Throwable $e) {
            $this->warn('No se pudo consultar el panel: ' . $e->getMessage());
        }

        $this->info("Direcciones suprimidas nuevas: {$nuevos}.");

        return self::SUCCESS;
    }
}
