<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use PDO;
use Throwable;

/**
 * Estado y utilidades del asistente de instalación.
 *
 * Todo lo de aquí tiene que funcionar ANTES de que exista la base de datos, así
 * que no puede consultar nada ni depender de la configuración guardada.
 */
class Instalacion
{
    /** Archivo que marca que la instalación ya se completó. */
    public static function rutaMarca(): string
    {
        return storage_path('app/instalada.json');
    }

    /** Se resuelve una vez por petición: el middleware lo consulta en cada ruta. */
    private static ?bool $instalada = null;

    public static function estaInstalada(): bool
    {
        if (static::$instalada !== null) {
            return static::$instalada;
        }

        if (File::exists(static::rutaMarca())) {
            return static::$instalada = true;
        }

        // Una instalación que ya venía funcionando no tiene el archivo marca,
        // porque es más nuevo que ella. Se reconoce por sus datos y se le deja la
        // marca puesta, para no volver a preguntar.
        if (static::baseYaTieneDatos()) {
            static::marcar();

            return static::$instalada = true;
        }

        return static::$instalada = false;
    }

    /**
     * ¿La base es de una instalación que ya está en uso?
     *
     * **Con que existan las tablas no alcanza**: a mitad del asistente la base ya
     * está migrada y todavía no hay administrador. Si esto devolviera true ahí, el
     * asistente se cerraría antes de crear el usuario y la instalación quedaría
     * inservible: base completa y nadie con quien entrar.
     *
     * Lo que distingue a una instalación en uso es que tenga al menos un usuario.
     */
    private static function baseYaTieneDatos(): bool
    {
        if (trim((string) config('database.connections.mysql.database')) === '') {
            return false;
        }

        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('users')) {
                return false;
            }

            return \Illuminate\Support\Facades\DB::table('users')->exists();
        } catch (Throwable) {
            // Sin conexión todavía: es una instalación nueva.
            return false;
        }
    }

    public static function marcar(): void
    {
        File::ensureDirectoryExists(dirname(static::rutaMarca()));

        File::put(static::rutaMarca(), json_encode([
            'instalada_el' => now()->toIso8601String(),
            'version'      => static::version(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** Versión del paquete instalado, si el empaquetador la dejó escrita. */
    public static function version(): string
    {
        $archivo = base_path('version.txt');

        return File::exists($archivo) ? trim(File::get($archivo)) : 'dev';
    }

    // ─── Requisitos del servidor ─────────────────────────────────────────────

    /**
     * @return array<int, array{nombre:string, ok:bool, detalle:string, critico:bool}>
     */
    public static function requisitos(): array
    {
        $r = [];

        $phpOk = version_compare(PHP_VERSION, '8.3.0', '>=');
        $r[] = [
            'nombre'  => 'PHP 8.3 o superior',
            'ok'      => $phpOk,
            'detalle' => 'Tienes PHP ' . PHP_VERSION,
            'critico' => true,
        ];

        foreach (['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'bcmath', 'fileinfo'] as $ext) {
            $r[] = [
                'nombre'  => "Extensión {$ext}",
                'ok'      => extension_loaded($ext),
                'detalle' => extension_loaded($ext) ? 'Disponible' : 'No está instalada',
                'critico' => true,
            ];
        }

        // Para las imágenes de productos y los PDF con logo.
        $imagen = extension_loaded('gd') || extension_loaded('imagick');
        $r[] = [
            'nombre'  => 'Extensión gd o imagick',
            'ok'      => $imagen,
            'detalle' => $imagen ? 'Disponible' : 'Sin una de las dos, las imágenes no se procesan',
            'critico' => false,
        ];

        foreach ([
            'storage/'        => storage_path(),
            'bootstrap/cache/' => base_path('bootstrap/cache'),
            'el archivo .env'  => base_path('.env'),
        ] as $etiqueta => $ruta) {
            $ok = File::exists($ruta) ? is_writable($ruta) : is_writable(dirname($ruta));
            $r[] = [
                'nombre'  => "Permiso de escritura en {$etiqueta}",
                'ok'      => $ok,
                'detalle' => $ok ? 'Se puede escribir' : 'El servidor no puede escribir ahí',
                'critico' => true,
            ];
        }

        return $r;
    }

    public static function requisitosCumplidos(): bool
    {
        foreach (static::requisitos() as $req) {
            if ($req['critico'] && ! $req['ok']) {
                return false;
            }
        }

        return true;
    }

    // ─── Base de datos ───────────────────────────────────────────────────────

    /**
     * Prueba la conexión sin usar la configuración de Laravel.
     *
     * Devuelve null si conectó, o el motivo en español si no. Se usa PDO directo
     * a propósito: en este punto la app todavía no tiene conexión configurada.
     *
     * @param  array{host:string, port:string, database:string, username:string, password:string}  $db
     */
    public static function probarConexion(array $db): ?string
    {
        try {
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']}";

            new PDO($dsn, $db['username'], $db['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT            => 5,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
            ]);

            return null;
        } catch (Throwable $e) {
            return static::traducirErrorDeConexion($e->getMessage());
        }
    }

    /** Mensajes de MySQL en algo que se entienda sin ser programador. */
    private static function traducirErrorDeConexion(string $mensaje): string
    {
        return match (true) {
            str_contains($mensaje, 'Unknown database')     => 'La base de datos no existe. Créala primero en el panel del hosting.',
            str_contains($mensaje, 'Access denied')        => 'El usuario o la contraseña de la base no son correctos.',
            str_contains($mensaje, 'Connection refused')   => 'No hay un servidor MySQL escuchando en ese host y puerto.',
            str_contains($mensaje, 'getaddrinfo')          => 'No se pudo resolver ese host.',
            default                                        => 'No se pudo conectar: ' . $mensaje,
        };
    }

    // ─── Escritura del .env ──────────────────────────────────────────────────

    /**
     * Cambia claves del .env conservando todo lo demás (comentarios incluidos).
     *
     * Si una clave no existe en el archivo, se agrega al final.
     *
     * @param  array<string, string>  $valores
     */
    public static function escribirEnv(array $valores): void
    {
        $ruta = base_path('.env');
        $env  = File::exists($ruta) ? File::get($ruta) : '';

        foreach ($valores as $clave => $valor) {
            // Comillas solo si hace falta: espacios o caracteres que rompen el parseo.
            $escrito = preg_match('/[\s#"\']/', $valor) ? '"' . addcslashes($valor, '"\\') . '"' : $valor;
            $linea   = "{$clave}={$escrito}";

            if (preg_match('/^' . preg_quote($clave, '/') . '=.*$/m', $env)) {
                $env = preg_replace('/^' . preg_quote($clave, '/') . '=.*$/m', $linea, $env);
            } else {
                $env = rtrim($env) . "\n" . $linea . "\n";
            }
        }

        File::put($ruta, $env);
    }

    /** Genera una llave de cifrado nueva para ESTA instalación. */
    public static function generarAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }
}
