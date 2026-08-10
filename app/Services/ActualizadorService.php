<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

/**
 * Aplica una versión nueva sobre esta instalación.
 *
 * Es la operación más delicada del producto: hay que reemplazar 43.000 archivos del
 * sistema mientras el sistema los está usando, en el servidor de un cliente al que
 * nadie tiene acceso si algo sale mal.
 *
 * ## Por qué se copia encima en vez de reemplazar la carpeta
 *
 * Lo elegante sería extraer al lado y cambiar las carpetas de sitio. No se hace, por
 * dos razones concretas:
 *
 *   - En Windows no se puede renombrar una carpeta con archivos en uso, y el propio
 *     PHP que ejecuta la actualización está dentro de ella.
 *   - Un renombrado a medias deja la instalación sin carpeta, y ahí no hay vuelta
 *     atrás posible desde la web.
 *
 * Copiando encima, en ningún momento falta un archivo: en el peor de los casos queda
 * una mezcla de versiones, que es recuperable volviendo a aplicar el paquete. Lo que
 * sí queda son los archivos que la versión nueva ya no trae, pero un archivo de más
 * que nadie invoca es inofensivo; una carpeta a medio renombrar no lo es.
 *
 * ## La vuelta atrás
 *
 * Los datos se recuperan del respaldo, que se hace antes de migrar y es obligatorio.
 * El código se recupera volviendo a aplicar el paquete de la versión anterior, que
 * se conserva. No se copian los 43.000 archivos a un lado antes de empezar: en un
 * hosting compartido eso duplica el tiempo y el espacio, y el paquete anterior hace
 * el mismo trabajo.
 */
class ActualizadorService
{
    /** Archivos y carpetas que NUNCA se sobreescriben. */
    private const NO_TOCAR = [
        '.env',
        'storage',        // datos, respaldos, archivos subidos, caché
        'public/storage', // el enlace a lo subido
        'version.txt',    // se escribe al final, cuando todo salió bien
    ];

    /** Archivos por tanda al extraer y al copiar. */
    private const POR_TANDA = 400;

    public function __construct(
        private LicenciaService $licencias,
        private BackupService $respaldos,
    ) {}

    private function carpeta(): string
    {
        return storage_path('app/actualizaciones');
    }

    private function rutaEstado(): string
    {
        return $this->carpeta() . '/estado.json';
    }

    // ─── Paso 1: ¿se puede? ──────────────────────────────────────────────────

    /**
     * Comprueba lo necesario antes de tocar nada.
     *
     * Se hace primero y por separado: descubrir a mitad de la copia que una carpeta
     * no tiene permiso de escritura deja la instalación con media versión.
     */
    public function comprobar(): array
    {
        $problemas = [];
        $avisos    = [];

        if (! class_exists(ZipArchive::class)) {
            $problemas[] = 'Falta la extensión zip de PHP, que hace falta para abrir el paquete.';
        }

        // Las carpetas que se van a sobreescribir tienen que ser escribibles. Se
        // comprueban las de código, no las de datos.
        foreach (['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'vendor'] as $carpeta) {
            $ruta = base_path($carpeta);

            if (File::exists($ruta) && ! is_writable($ruta)) {
                $problemas[] = "La carpeta {$carpeta} no tiene permiso de escritura.";
            }
        }

        if (! is_writable(base_path())) {
            $problemas[] = 'La carpeta del sistema no tiene permiso de escritura.';
        }

        File::ensureDirectoryExists($this->carpeta());

        // Espacio: el paquete pesa unos 60 MB y al extraerlo ocupa unos 200 más.
        $libre = @disk_free_space(base_path());

        if ($libre !== false && $libre < 350 * 1024 * 1024) {
            $problemas[] = 'Queda menos de 350 MB libres en el disco, y la actualización necesita ese espacio.';
        }

        // El respaldo previo es obligatorio: si no se puede hacer, no se actualiza.
        $diag = $this->respaldos->diagnostico();

        if (! ($diag['carpeta']['ok'] ?? true)) {
            $problemas[] = 'No se puede escribir la carpeta de respaldos, y el respaldo antes de migrar es obligatorio.';
        }

        if (($diag['mysqldump']['ok'] ?? false) === false) {
            $avisos[] = 'No hay mysqldump en el servidor: el respaldo se armará desde PHP, que es más lento.';
        }

        return [
            'puede'     => $problemas === [],
            'problemas' => $problemas,
            'avisos'    => $avisos,
            'version_actual' => $this->licencias->versionInstalada(),
        ];
    }

    // ─── Paso 2: traer el paquete ────────────────────────────────────────────

    /**
     * Descarga el paquete de una versión desde el servidor de licencias.
     *
     * Se guarda en disco por trozos y no en memoria: 60 MB en una variable revientan
     * el límite de memoria de cualquier hosting compartido.
     */
    public function descargar(string $version): array
    {
        $serial = $this->licencias->serial();

        if ($serial === null) {
            return ['ok' => false, 'mensaje' => 'Esta instalación no tiene serial, así que no puede pedir actualizaciones.'];
        }

        File::ensureDirectoryExists($this->carpeta());
        $destino = $this->carpeta() . "/briela-{$version}.zip";

        try {
            $url = rtrim((string) config('briela.licencia_url'), '/')
                . "/api/actualizacion/{$version}/descargar";

            $resp = Http::timeout(600)
                ->withOptions(['sink' => $destino])
                ->asForm()
                ->post($url, ['serial' => $serial]);

            if (! $resp->successful()) {
                File::delete($destino);

                // El servidor explica por qué: suscripción vencida, versión que no
                // existe. Ese mensaje es más útil que un "falló la descarga".
                $mensaje = 'No se pudo descargar la actualización.';

                if ($resp->status() === 403) {
                    $mensaje = 'La suscripción no está al día, así que esta instalación no recibe actualizaciones.';
                } elseif ($resp->status() === 404) {
                    $mensaje = 'Esa versión no está disponible en el servidor.';
                }

                return ['ok' => false, 'mensaje' => $mensaje];
            }
        } catch (Throwable $e) {
            File::delete($destino);
            Log::error('Actualización: falló la descarga. ' . $e->getMessage());

            return ['ok' => false, 'mensaje' => 'No se pudo conectar con el servidor de actualizaciones.'];
        }

        if (! is_file($destino) || filesize($destino) < 1024) {
            File::delete($destino);

            return ['ok' => false, 'mensaje' => 'El archivo descargado está vacío o incompleto.'];
        }

        // Que el ZIP se pueda abrir es la prueba de que llegó completo. Un paquete
        // truncado que se aplica a medias es la peor forma de fallar.
        $zip = new ZipArchive();

        if ($zip->open($destino) !== true) {
            File::delete($destino);

            return ['ok' => false, 'mensaje' => 'El paquete descargado está dañado.'];
        }

        $total = $zip->numFiles;
        $zip->close();

        $this->guardarEstado([
            'version'  => $version,
            'zip'      => $destino,
            'total'    => $total,
            'extraidos'=> 0,
            'copiados' => 0,
            'fase'     => 'descargado',
            'anterior' => $this->licencias->versionInstalada(),
        ]);

        return [
            'ok'      => true,
            'bytes'   => filesize($destino),
            'archivos'=> $total,
        ];
    }

    // ─── Paso 3: respaldo, obligatorio ───────────────────────────────────────

    public function respaldar(): array
    {
        try {
            $resultado = $this->respaldos->generar('actualizacion');

            $estado = $this->estado();
            $estado['respaldo'] = $resultado['nombre'];
            $estado['fase']     = 'respaldado';
            $this->guardarEstado($estado);

            return [
                'ok'       => true,
                'respaldo' => $resultado['nombre'],
                'metodo'   => $resultado['metodo'],
            ];
        } catch (Throwable $e) {
            Log::error('Actualización: falló el respaldo. ' . $e->getMessage());

            // Sin respaldo no se sigue. Es la única red que hay.
            return ['ok' => false, 'mensaje' => 'No se pudo respaldar la base de datos: ' . $e->getMessage()];
        }
    }

    // ─── Paso 4: extraer, por tandas ─────────────────────────────────────────

    /**
     * Extrae una tanda de archivos a una carpeta aparte.
     *
     * Por tandas porque en un hosting compartido el límite de ejecución son treinta
     * segundos y aquí hay 43.000 archivos. Cada llamada avanza y guarda por dónde va.
     */
    public function extraerTanda(): array
    {
        $estado = $this->estado();

        if (! isset($estado['zip']) || ! is_file($estado['zip'])) {
            return ['ok' => false, 'mensaje' => 'No hay paquete descargado.'];
        }

        $temporal = $this->carpeta() . '/nueva';
        File::ensureDirectoryExists($temporal);

        $zip = new ZipArchive();

        if ($zip->open($estado['zip']) !== true) {
            return ['ok' => false, 'mensaje' => 'No se pudo abrir el paquete.'];
        }

        $desde = (int) ($estado['extraidos'] ?? 0);
        $hasta = min($desde + self::POR_TANDA, $zip->numFiles);
        $nombres = [];

        for ($i = $desde; $i < $hasta; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }

        if ($nombres !== []) {
            $zip->extractTo($temporal, $nombres);
        }

        $zip->close();

        $estado['extraidos'] = $hasta;
        $estado['fase'] = $hasta >= (int) $estado['total'] ? 'extraido' : 'extrayendo';
        $this->guardarEstado($estado);

        return [
            'ok'        => true,
            'extraidos' => $hasta,
            'total'     => (int) $estado['total'],
            'listo'     => $hasta >= (int) $estado['total'],
        ];
    }

    // ─── Paso 5: copiar encima, por tandas ───────────────────────────────────

    /**
     * Copia una tanda de archivos de la versión nueva sobre la instalación.
     *
     * Lo que no se toca nunca: el .env con las credenciales, storage con los datos y
     * los archivos subidos, y el enlace público. Sobreescribir cualquiera de esos
     * sería perder algo que no está en ningún paquete.
     */
    public function copiarTanda(): array
    {
        $estado   = $this->estado();
        $temporal = $this->carpeta() . '/nueva';

        if (! File::isDirectory($temporal)) {
            return ['ok' => false, 'mensaje' => 'No hay archivos extraídos que copiar.'];
        }

        // La lista se calcula una vez y se guarda: recorrer 43.000 archivos en cada
        // tanda costaría más que copiarlos.
        if (! isset($estado['lista']) || ! is_file($estado['lista'])) {
            $lista = $this->carpeta() . '/lista.txt';
            $mano  = fopen($lista, 'w');

            $iterador = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($temporal, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            $cuenta = 0;

            foreach ($iterador as $archivo) {
                if (! $archivo->isFile()) {
                    continue;
                }

                $relativa = str_replace('\\', '/', substr($archivo->getPathname(), strlen($temporal) + 1));

                if ($this->intocable($relativa)) {
                    continue;
                }

                fwrite($mano, $relativa . "\n");
                $cuenta++;
            }

            fclose($mano);

            $estado['lista'] = $lista;
            $estado['a_copiar'] = $cuenta;
            $estado['copiados'] = 0;
            $this->guardarEstado($estado);
        }

        $desde = (int) ($estado['copiados'] ?? 0);
        $lineas = file($estado['lista'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $hasta  = min($desde + self::POR_TANDA, count($lineas));

        for ($i = $desde; $i < $hasta; $i++) {
            $relativa = $lineas[$i];
            $origen   = $temporal . '/' . $relativa;
            $destino  = base_path($relativa);

            File::ensureDirectoryExists(dirname($destino));

            // copy() sobreescribe sin borrar antes: así el archivo nunca falta, ni
            // por un instante.
            if (! @copy($origen, $destino)) {
                return [
                    'ok'      => false,
                    'mensaje' => "No se pudo escribir {$relativa}. La actualización se detuvo aquí.",
                    'copiados'=> $i,
                ];
            }
        }

        $estado['copiados'] = $hasta;
        $estado['fase'] = $hasta >= count($lineas) ? 'copiado' : 'copiando';
        $this->guardarEstado($estado);

        return [
            'ok'       => true,
            'copiados' => $hasta,
            'total'    => count($lineas),
            'listo'    => $hasta >= count($lineas),
        ];
    }

    private function intocable(string $relativa): bool
    {
        foreach (self::NO_TOCAR as $protegido) {
            if ($relativa === $protegido || str_starts_with($relativa, $protegido . '/')) {
                return true;
            }
        }

        return false;
    }

    // ─── Paso 6: migrar y cerrar ─────────────────────────────────────────────

    /**
     * Corre las migraciones, limpia las cachés y deja constancia de la versión.
     *
     * La versión se escribe al final y no antes: si algo falló, la instalación tiene
     * que seguir diciendo que está en la anterior, o el latido informaría mal.
     */
    public function finalizar(): array
    {
        $estado = $this->estado();

        try {
            Artisan::call('migrate', ['--force' => true]);
            $salida = Artisan::output();

            foreach (['config:clear', 'route:clear', 'view:clear'] as $comando) {
                Artisan::call($comando);
            }

            File::put(base_path('version.txt'), ($estado['version'] ?? '') . PHP_EOL);

            // Se le dice al servidor de licencias en qué versión quedó, sin esperar
            // al siguiente latido del cron.
            try {
                $this->licencias->refrescar();
            } catch (Throwable) {
                // Que no se pueda avisar no invalida la actualización.
            }

            $this->limpiar();

            return [
                'ok'      => true,
                'version' => $estado['version'] ?? null,
                'salida'  => trim($salida),
            ];
        } catch (Throwable $e) {
            Log::error('Actualización: falló al migrar. ' . $e->getMessage());

            return [
                'ok'       => false,
                'mensaje'  => 'Los archivos se actualizaron pero las migraciones fallaron: ' . $e->getMessage(),
                'respaldo' => $estado['respaldo'] ?? null,
            ];
        }
    }

    /** Borra el paquete y lo extraído, que juntos ocupan cientos de megas. */
    public function limpiar(): void
    {
        $estado = $this->estado();

        File::deleteDirectory($this->carpeta() . '/nueva');
        File::delete($this->carpeta() . '/lista.txt');

        if (isset($estado['zip'])) {
            File::delete($estado['zip']);
        }

        File::delete($this->rutaEstado());
    }

    // ─── Estado del proceso ──────────────────────────────────────────────────

    public function estado(): array
    {
        return is_file($this->rutaEstado())
            ? (json_decode((string) file_get_contents($this->rutaEstado()), true) ?: [])
            : [];
    }

    private function guardarEstado(array $estado): void
    {
        File::ensureDirectoryExists($this->carpeta());
        File::put($this->rutaEstado(), json_encode($estado, JSON_PRETTY_PRINT));
    }

    /** ¿Quedó una actualización a medias de un intento anterior? */
    public function hayProcesoEmpezado(): bool
    {
        $fase = $this->estado()['fase'] ?? null;

        return $fase !== null && $fase !== 'terminado';
    }
}
