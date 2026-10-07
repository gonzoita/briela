<?php

namespace App\Services;

use App\Models\WhatsappPlantilla;
use App\Support\CredencialesRrss;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trae de Meta las plantillas aprobadas y las deja en el espejo local.
 *
 * **Por qué un espejo y no preguntar cada vez.** El selector de plantillas se pinta cada vez
 * que alguien abre una conversación con el plazo vencido. Preguntarle a Meta en ese momento
 * sería una llamada a internet —con su latencia y su cuota— dentro de una pantalla que tiene
 * que sentirse instantánea, y además dejaría la bandeja sin plantillas cuando Meta esté lento.
 * Así que se sincroniza: a mano desde la pantalla, y sola una vez al día.
 *
 * **Las plantillas no se crean desde acá, y es a propósito.** Meta las revisa una por una y la
 * aprobación tarda. Un formulario que las creara haría creer que la plantilla queda lista para
 * usar, cuando lo que queda es una solicitud en cola. Se escriben en el Business Manager, que
 * es donde se ve el estado de la revisión, y aquí se refleja lo que allá exista.
 */
class WhatsappPlantillaService
{
    /**
     * Sincroniza, y dice qué pasó.
     *
     * @return array{ok: bool, mensaje: string, creadas?: int, actualizadas?: int, total?: int}
     */
    public function sincronizar(): array
    {
        $token = CredencialesRrss::valor('whatsapp', 'secret');
        $waba  = CredencialesRrss::valor('whatsapp', 'waba');

        if ($token === '') {
            return [
                'ok' => false,
                'mensaje' => 'WhatsApp no está conectado: falta el token de acceso.',
            ];
        }

        if ($waba === '') {
            return [
                'ok' => false,
                'mensaje' => 'Falta el identificador de la cuenta de WhatsApp Business (WABA ID). '
                    . 'Está en Meta, en WhatsApp → Configuración de la API, debajo del número.',
            ];
        }

        $version = config('services.whatsapp.api_version', 'v21.0');
        $creadas = 0;
        $actualizadas = 0;
        $vistas = [];

        // Meta pagina. Sin seguir el cursor, una cuenta con más de 25 plantillas sincronizaba
        // solo las primeras y las demás no aparecían nunca en el selector.
        $url = "https://graph.facebook.com/{$version}/{$waba}/message_templates";
        $parametros = ['limit' => 100, 'fields' => 'id,name,language,category,status,components'];

        try {
            while ($url !== null) {
                $respuesta = Http::withToken($token)->timeout(30)->get($url, $parametros);
                $parametros = []; // el enlace de la página siguiente ya los trae dentro

                if (! $respuesta->successful()) {
                    return [
                        'ok' => false,
                        'mensaje' => $this->traducir($respuesta->json() ?? [], $respuesta->body()),
                    ];
                }

                foreach ($respuesta->json('data', []) as $cruda) {
                    $resultado = $this->guardar($cruda);

                    $vistas[] = $resultado['clave'];
                    $resultado['nueva'] ? $creadas++ : $actualizadas++;
                }

                $url = $respuesta->json('paging.next');
            }
        } catch (\Throwable $e) {
            Log::error('WhatsApp plantillas: no se pudo sincronizar.', ['error' => $e->getMessage()]);

            return ['ok' => false, 'mensaje' => 'No se pudo hablar con Meta: ' . $e->getMessage()];
        }

        $borradas = $this->marcarLasQueYaNoExisten($vistas);

        return [
            'ok' => true,
            'creadas' => $creadas,
            'actualizadas' => $actualizadas,
            'total' => count($vistas),
            'mensaje' => $this->resumen($creadas, $actualizadas, $borradas),
        ];
    }

    /**
     * Guarda una plantilla de Meta.
     *
     * @param  array<string, mixed>  $cruda
     * @return array{clave: string, nueva: bool}
     */
    private function guardar(array $cruda): array
    {
        $partes = $this->leerComponentes($cruda['components'] ?? []);

        $plantilla = WhatsappPlantilla::firstOrNew([
            'nombre' => $cruda['name'] ?? '',
            'idioma' => $cruda['language'] ?? 'es',
        ]);

        $nueva = ! $plantilla->exists;

        $plantilla->fill([
            'categoria'  => $cruda['category'] ?? null,
            'estado'     => $cruda['status'] ?? null,
            'externo_id' => $cruda['id'] ?? null,
            'encabezado' => $partes['encabezado'],
            'cuerpo'     => $partes['cuerpo'],
            'pie'        => $partes['pie'],
            'variables'  => WhatsappPlantilla::contarVariables($partes['encabezado'], $partes['cuerpo']),
            // Se guarda tal cual lo que manda Meta: así no se pierde lo que todavía no sabemos
            // leer —botones, cabeceras con archivo— y se puede usar después sin volver a pedir.
            'componentes' => $cruda['components'] ?? null,
            'sincronizada_at' => now(),
        ])->save();

        return ['clave' => $plantilla->nombre . '|' . $plantilla->idioma, 'nueva' => $nueva];
    }

    /**
     * Separa encabezado, cuerpo y pie de los componentes de Meta.
     *
     * Solo se lee el texto. Una cabecera de imagen o de documento no tiene texto que mostrar,
     * y poner ahí el tipo («IMAGE») en la vista previa haría creer que el cliente va a recibir
     * esa palabra.
     *
     * @param  array<int, array<string, mixed>>  $componentes
     * @return array{encabezado: ?string, cuerpo: ?string, pie: ?string}
     */
    private function leerComponentes(array $componentes): array
    {
        $partes = ['encabezado' => null, 'cuerpo' => null, 'pie' => null];

        foreach ($componentes as $componente) {
            $tipo = strtoupper((string) ($componente['type'] ?? ''));
            $texto = $componente['text'] ?? null;

            if ($texto === null) {
                continue;
            }

            match ($tipo) {
                'HEADER' => $partes['encabezado'] = $texto,
                'BODY'   => $partes['cuerpo'] = $texto,
                'FOOTER' => $partes['pie'] = $texto,
                default  => null,
            };
        }

        return $partes;
    }

    /**
     * Las que ya no están en Meta se marcan como borradas, no se eliminan.
     *
     * Eliminarlas rompería el historial: un mensaje guardado dice con qué plantilla salió, y
     * además alguien puede borrar una plantilla en Meta por error. Marcada, deja de ofrecerse
     * —`scopeAprobadas` solo trae las APPROVED— y sigue estando si vuelve.
     *
     * @param  array<int, string>  $vistas  «nombre|idioma» de las que sí llegaron
     */
    private function marcarLasQueYaNoExisten(array $vistas): int
    {
        $marcadas = 0;

        foreach (WhatsappPlantilla::whereNot('estado', 'BORRADA_EN_META')->get() as $plantilla) {
            if (in_array($plantilla->nombre . '|' . $plantilla->idioma, $vistas, true)) {
                continue;
            }

            $plantilla->update(['estado' => 'BORRADA_EN_META', 'sincronizada_at' => now()]);
            $marcadas++;
        }

        return $marcadas;
    }

    private function resumen(int $creadas, int $actualizadas, int $borradas): string
    {
        if ($creadas === 0 && $actualizadas === 0) {
            return 'No hay plantillas en esta cuenta de WhatsApp Business todavía. Se crean en Meta, '
                . 'en WhatsApp Manager → Plantillas de mensajes.';
        }

        $partes = [];

        if ($creadas > 0)      $partes[] = $creadas . ($creadas === 1 ? ' nueva' : ' nuevas');
        if ($actualizadas > 0) $partes[] = $actualizadas . ' al día';
        if ($borradas > 0)     $partes[] = $borradas . ' que ya no están en Meta';

        return 'Listo: ' . implode(', ', $partes) . '.';
    }

    /**
     * Los errores de Meta al pedir plantillas, dichos en términos de qué hacer.
     *
     * @param  array<string, mixed>  $datos
     */
    private function traducir(array $datos, string $cuerpo): string
    {
        $codigo = (int) ($datos['error']['code'] ?? 0);
        $detalle = $datos['error']['message'] ?? $cuerpo;

        return match ($codigo) {
            190 => 'El token de acceso venció o fue revocado. Hay que generar uno nuevo y permanente.',
            // El 803 sale cuando el identificador es de un número y no de la cuenta: es el
            // error más fácil de cometer, porque los dos son números largos y están cerca en
            // la misma pantalla de Meta.
            803 => 'Ese identificador no es de una cuenta de WhatsApp Business. Revisa que sea el '
                . 'WABA ID de la cuenta, no el Phone Number ID de un número.',
            200, 10 => 'Al token le faltan permisos para leer las plantillas '
                . '(whatsapp_business_management). Hay que agregárselos en Meta y generarlo otra vez.',
            default => 'Meta respondió con un error: ' . $detalle,
        };
    }
}
