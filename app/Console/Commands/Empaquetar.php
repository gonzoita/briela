<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Arma el paquete que se instala en el servidor de un cliente.
 *
 * Lo que sale de aquí es lo único que ve el cliente, así que hay dos criterios
 * al decidir qué entra:
 *
 *   - Que la instalación funcione sin composer ni Node: por eso viajan `vendor/`
 *     y `public/build/` ya compilados.
 *   - Que no viaje nada interno: la documentación del proyecto, las
 *     instrucciones de desarrollo, los tests ni las herramientas de compilación.
 */
class Empaquetar extends Command
{
    // `--version` no se puede usar: Artisan ya la tiene como opción global.
    protected $signature = 'briela:empaquetar
                            {version? : Versión del paquete (por defecto, la fecha)}
                            {--forzar : Empaquetar aunque vendor tenga paquetes de desarrollo}';

    protected $description = 'Genera el ZIP instalable de Briela';

    /**
     * Carpetas y archivos que NO viajan al servidor del cliente.
     *
     * Las rutas son relativas a la raíz del proyecto.
     */
    private const EXCLUIR = [
        // Nunca: credenciales y datos
        '.env', '.env.backup', '.env.production',
        // Herramientas y estado local
        '.git', '.github', '.claude', 'node_modules', 'graphify-out', '.prueba-instalador',
        // Interno del proyecto: no es del cliente
        'docs', 'CLAUDE.md', 'tests', 'phpunit.xml',
        // Solo sirven para compilar, y el build ya viaja hecho
        'package.json', 'package-lock.json', 'vite.config.js', 'tailwind.config.js',
        'postcss.config.js', 'jsconfig.json', 'scripts', 'resources/css', 'resources/js',
        // El instalador se entrega aparte, no dentro del paquete
        'installer',
        // Basura de trabajo
        'subir.ps1', 'storage/app/paquetes',
    ];

    /** Se incluye la carpeta pero no su contenido (datos y cachés de otra instalación). */
    private const SOLO_ESTRUCTURA = [
        'storage/app', 'storage/logs', 'storage/framework/cache',
        'storage/framework/sessions', 'storage/framework/views',
        'storage/framework/testing', 'bootstrap/cache',
    ];

    public function handle(): int
    {
        $version = $this->argument('version') ?: now()->format('Y.m.d');

        if (! $this->vendorEsDeProduccion() && ! $this->option('forzar')) {
            $this->error('vendor/ tiene paquetes de desarrollo instalados.');
            $this->line('');
            $this->line('  Un paquete de cliente no debe llevar herramientas de desarrollo');
            $this->line('  (whoops, por ejemplo, muestra el código fuente en las pantallas de error).');
            $this->line('');
            $this->line('  Corre esto y vuelve a empaquetar:');
            $this->line('    composer install --no-dev --optimize-autoloader');
            $this->line('');
            $this->line('  Después, para volver a trabajar en local: composer install');
            $this->line('');
            $this->line('  Si sabes lo que haces: --forzar');

            return self::FAILURE;
        }

        $destino = storage_path('app/paquetes');
        File::ensureDirectoryExists($destino);

        $zipRuta = "{$destino}/briela-{$version}.zip";
        File::delete($zipRuta);

        $this->line('');
        $this->info("Empaquetando Briela {$version}");
        $this->line('');

        // Marca de versión: el instalador y el actualizador la leen de aquí.
        File::put(base_path('version.txt'), $version . PHP_EOL);

        $zip = new ZipArchive();
        if ($zip->open($zipRuta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('No se pudo crear el archivo ZIP.');

            return self::FAILURE;
        }

        $archivos = 0;
        $barra = $this->output->createProgressBar();
        $barra->start();

        foreach ($this->recorrer(base_path()) as $ruta => $relativa) {
            $zip->addFile($ruta, $relativa);
            $archivos++;
            if ($archivos % 500 === 0) {
                $barra->advance(500);
            }
        }

        // Las carpetas de datos viajan vacías, con su .gitignore, para que la
        // instalación tenga la estructura y pueda escribir.
        foreach (self::SOLO_ESTRUCTURA as $carpeta) {
            $zip->addEmptyDir($carpeta);
            $gitignore = base_path($carpeta . '/.gitignore');
            if (File::exists($gitignore)) {
                $zip->addFile($gitignore, $carpeta . '/.gitignore');
                $archivos++;
            }
        }

        $zip->close();
        $barra->finish();
        $this->line('');
        $this->line('');

        $hash = hash_file('sha256', $zipRuta);
        File::put("{$zipRuta}.sha256", $hash . PHP_EOL);

        // El instalador de un solo archivo va al lado del paquete: es lo que el
        // cliente sube a su servidor.
        if (File::exists(base_path('installer/instalar.php'))) {
            File::copy(base_path('installer/instalar.php'), "{$destino}/instalar.php");
        }

        $this->line('  <fg=green>·</> Archivos:  ' . number_format($archivos));
        $this->line('  <fg=green>·</> Tamaño:    ' . $this->formatear(filesize($zipRuta)));
        $this->line('  <fg=green>·</> SHA-256:   ' . substr($hash, 0, 32) . '…');
        $this->line('  <fg=green>·</> Paquete:   ' . $zipRuta);
        $this->line('  <fg=green>·</> Instalador: ' . $destino . '/instalar.php');
        $this->line('');
        $this->info('Listo. Sube el ZIP y su .sha256 al origen de descargas.');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Recorre el proyecto devolviendo [ruta absoluta => ruta dentro del zip].
     *
     * @return iterable<string, string>
     */
    private function recorrer(string $base): iterable
    {
        $iterador = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterador as $archivo) {
            /** @var SplFileInfo $archivo */
            $relativa = str_replace('\\', '/', substr($archivo->getPathname(), strlen($base) + 1));

            if ($this->excluida($relativa)) {
                continue;
            }

            if ($archivo->isFile()) {
                yield $archivo->getPathname() => $relativa;
            }
        }
    }

    private function excluida(string $relativa): bool
    {
        foreach (self::EXCLUIR as $patron) {
            if ($relativa === $patron || str_starts_with($relativa, $patron . '/')) {
                return true;
            }
        }

        // El contenido de las carpetas de datos no viaja; la carpeta sí.
        foreach (self::SOLO_ESTRUCTURA as $carpeta) {
            if (str_starts_with($relativa, $carpeta . '/')) {
                return true;
            }
        }

        // Respaldos de base de datos y temporales de editor, por si acaso.
        return (bool) preg_match('/\.(sql|log)$/i', $relativa)
            || str_contains($relativa, '.fuse_hidden');
    }

    /** Detecta paquetes que solo se instalan con `composer install` sin --no-dev. */
    private function vendorEsDeProduccion(): bool
    {
        foreach (['fakerphp', 'phpunit', 'filp/whoops', 'mockery'] as $dev) {
            if (File::isDirectory(base_path("vendor/{$dev}"))) {
                return false;
            }
        }

        return true;
    }

    private function formatear(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1) . ' MB'
            : number_format($bytes / 1024, 0) . ' KB';
    }
}
