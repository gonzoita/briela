<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Copias de seguridad de la base de datos.
 *
 * Estaba dentro del controlador y solo se podía disparar a mano desde la
 * pantalla. Se sacó a un servicio para que el comando programado use
 * exactamente el mismo código: un respaldo automático que funcione distinto
 * al manual es un respaldo en el que no se puede confiar.
 */
class BackupService
{
    /** Cuántos días se conservan los respaldos automáticos. */
    public const DIAS_RETENCION = 30;

    public function carpeta(): string
    {
        $ruta = storage_path('app/backups');

        if (! is_dir($ruta)) {
            mkdir($ruta, 0755, true);
        }

        return $ruta;
    }

    /**
     * Genera un respaldo y lo deja en disco.
     *
     * @param  string $origen  'manual' o 'automatico', para distinguirlos en el nombre
     * @return array{ruta:string, nombre:string, bytes:int, metodo:string}
     */
    public function generar(string $origen = 'manual'): array
    {
        $dbName = config('database.connections.mysql.database');
        $fecha  = now()->format('Y-m-d_H-i-s');
        $nombre = "backup_{$origen}_{$dbName}_{$fecha}.sql";
        $ruta   = $this->carpeta() . DIRECTORY_SEPARATOR . $nombre;

        $metodo = 'mysqldump';
        $sql    = $this->conMysqldump();

        if ($sql && strlen($sql) > 100) {
            file_put_contents($ruta, $sql);
        } else {
            // Si mysqldump no está disponible (hosting compartido, por
            // ejemplo), se arma el SQL leyendo las tablas desde PHP. Es más
            // lento pero produce un archivo válido igual.
            $metodo = 'php';
            $this->conPhp($dbName, $ruta);
        }

        $bytes = @filesize($ruta) ?: 0;

        if ($bytes < 100) {
            @unlink($ruta);
            throw new \RuntimeException('El respaldo salió vacío. Revisa la conexión a la base de datos y los permisos de la carpeta de respaldos.');
        }

        return [
            'ruta'   => $ruta,
            'nombre' => $nombre,
            'bytes'  => $bytes,
            'metodo' => $metodo,
        ];
    }

    /**
     * Borra los respaldos más viejos que el límite de retención.
     *
     * Siempre deja al menos uno, aunque sea antiguo: quedarse sin ningún
     * respaldo por una regla de limpieza sería el peor resultado posible.
     *
     * @return int cuántos se borraron
     */
    public function limpiarViejos(int $dias = self::DIAS_RETENCION): int
    {
        $archivos = $this->listar();

        if (count($archivos) <= 1) {
            return 0;
        }

        $limite  = now()->subDays($dias)->getTimestamp();
        $borrados = 0;

        // Se recorre de más viejo a más nuevo y se para si solo queda uno.
        foreach (array_reverse($archivos) as $a) {
            if (count($archivos) - $borrados <= 1) {
                break;
            }
            if ($a['timestamp'] < $limite) {
                @unlink($a['ruta']);
                $borrados++;
            }
        }

        return $borrados;
    }

    /** @return array<int, array{nombre:string,ruta:string,bytes:int,timestamp:int,automatico:bool}> */
    public function listar(): array
    {
        $archivos = glob($this->carpeta() . DIRECTORY_SEPARATOR . '*.sql') ?: [];

        return collect($archivos)
            ->map(fn ($ruta) => [
                'nombre'     => basename($ruta),
                'ruta'       => $ruta,
                'bytes'      => filesize($ruta) ?: 0,
                'timestamp'  => filemtime($ruta) ?: 0,
                'automatico' => str_contains(basename($ruta), '_automatico_'),
            ])
            ->sortByDesc('timestamp')
            ->values()
            ->all();
    }

    /** El último respaldo automático, o null si nunca ha corrido. */
    public function ultimoAutomatico(): ?array
    {
        foreach ($this->listar() as $a) {
            if ($a['automatico']) {
                return $a;
            }
        }

        return null;
    }

    /**
     * Revisa si el servidor puede generar respaldos y por qué camino.
     *
     * Existe porque en hosting compartido suele haber funciones bloqueadas
     * (shell_exec, escapeshellarg) o límites de memoria bajos, y un error 500
     * en blanco no dice cuál de esas cosas es.
     *
     * @return array<string, array{ok:bool, valor:string, nota:?string}>
     */
    public function diagnostico(): array
    {
        $deshabilitadas = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $puedeShell     = function_exists('shell_exec') && ! in_array('shell_exec', $deshabilitadas, true);
        $puedeEscapar   = function_exists('escapeshellarg') && ! in_array('escapeshellarg', $deshabilitadas, true);

        $carpeta   = $this->carpeta();
        $escribible = is_writable($carpeta);

        $binario = ($puedeShell && $puedeEscapar) ? $this->encontrarMysqldump() : null;

        return [
            'carpeta' => [
                'ok'    => $escribible,
                'valor' => $escribible ? 'Se puede escribir' : 'Sin permiso de escritura',
                'nota'  => $escribible ? null : "Revisa los permisos de {$carpeta}",
            ],
            'shell' => [
                'ok'    => $puedeShell && $puedeEscapar,
                'valor' => ($puedeShell && $puedeEscapar) ? 'Disponible' : 'Bloqueado por el hosting',
                'nota'  => ($puedeShell && $puedeEscapar) ? null : 'Se usará el respaldo hecho desde PHP, que es más lento pero sirve igual.',
            ],
            'mysqldump' => [
                'ok'    => (bool) $binario,
                'valor' => $binario ?: 'No encontrado',
                'nota'  => $binario ? null : 'Sin mysqldump el respaldo se arma desde PHP.',
            ],
            'memoria' => [
                'ok'    => true,
                'valor' => (string) ini_get('memory_limit'),
                'nota'  => 'El respaldo desde PHP necesita memoria proporcional al tamaño de la base.',
            ],
            'tiempo_maximo' => [
                'ok'    => true,
                'valor' => ini_get('max_execution_time') . ' s',
                'nota'  => null,
            ],
        ];
    }

    public function tamanoBaseDatos(): string
    {
        $db = config('database.connections.mysql.database');

        $size = DB::select(
            'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
             FROM information_schema.tables WHERE table_schema = ?',
            [$db]
        );

        return ($size[0]->size_mb ?? 0) . ' MB';
    }

    public static function formatearBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }

    // ─── Generación ──────────────────────────────────────────────────────────

    /**
     * Vuelca la base con mysqldump.
     *
     * La contraseña va en un archivo temporal y no en la línea de comandos.
     * Antes iba pegada como -pClave, y eso la deja visible para cualquiera que
     * liste los procesos del servidor; además, una contraseña con caracteres
     * como * o $ la puede alterar el shell antes de que llegue a mysqldump.
     */
    private function conMysqldump(): ?string
    {
        // En hosting compartido estas funciones suelen venir bloqueadas.
        // Llamarlas sin revisar tumba la petición con un error fatal en vez
        // de caer al respaldo hecho desde PHP, que sí funciona.
        if (! function_exists('shell_exec') || ! function_exists('escapeshellarg')) {
            return null;
        }

        $deshabilitadas = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('shell_exec', $deshabilitadas, true) || in_array('escapeshellarg', $deshabilitadas, true)) {
            return null;
        }

        $binario = $this->encontrarMysqldump();

        if (! $binario) {
            return null;
        }

        $cnf = tempnam(sys_get_temp_dir(), 'sgidb');

        try {
            file_put_contents($cnf, sprintf(
                "[client]\nuser=%s\npassword=\"%s\"\nhost=%s\nport=%d\n",
                config('database.connections.mysql.username'),
                str_replace('"', '\\"', (string) config('database.connections.mysql.password')),
                config('database.connections.mysql.host'),
                (int) config('database.connections.mysql.port', 3306),
            ));
            chmod($cnf, 0600);

            $comando = sprintf(
                '%s --defaults-extra-file=%s --single-transaction --quick --skip-lock-tables %s %s',
                escapeshellarg($binario),
                escapeshellarg($cnf),
                escapeshellarg(config('database.connections.mysql.database')),
                PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null'
            );

            return shell_exec($comando);
        } catch (\Throwable $e) {
            Log::warning('mysqldump falló: ' . $e->getMessage());

            return null;
        } finally {
            @unlink($cnf);
        }
    }

    private function encontrarMysqldump(): ?string
    {
        $posibles = ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump'];

        if (PHP_OS_FAMILY === 'Windows') {
            $posibles = array_merge(glob('C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe') ?: [], $posibles);
        }

        foreach ($posibles as $ruta) {
            if (@file_exists($ruta)) {
                return $ruta;
            }
        }

        if (! function_exists('shell_exec')) {
            return null;
        }

        $which = @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where mysqldump 2>NUL' : 'which mysqldump 2>/dev/null');

        return $which ? trim($which) : null;
    }

    /**
     * Respaldo hecho desde PHP, para cuando no hay mysqldump.
     *
     * Escribe directo al archivo en vez de armar todo el SQL en memoria: una
     * base de 6 MB se convierte en bastante más texto al escaparlo, y en un
     * hosting con memory_limit bajo eso tumba la petición sin explicación.
     */
    private function conPhp(string $dbName, string $rutaDestino): void
    {
        $fh = fopen($rutaDestino, 'w');

        if (! $fh) {
            throw new \RuntimeException("No se pudo escribir en {$rutaDestino}. Revisa los permisos de la carpeta.");
        }

        $pdo = DB::getPdo();

        try {
            fwrite($fh, "-- Briela · respaldo de {$dbName}\n");
            fwrite($fh, '-- Generado: ' . now()->toDateTimeString() . "\n\n");

            // La codificación es lo primero, y es lo que faltaba: sin esta línea,
            // un cliente que asuma latin1 al restaurar convierte las tildes y las
            // ñ en caracteres rotos, y el daño solo se ve cuando alguien abre la
            // ficha de un cliente y su nombre está mal escrito.
            fwrite($fh, "SET NAMES utf8mb4;\n");
            fwrite($fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            $tablas = DB::select('SHOW TABLES');
            $llave  = "Tables_in_{$dbName}";

            foreach ($tablas as $tabla) {
                $t = $tabla->$llave;

                $create = DB::select("SHOW CREATE TABLE `{$t}`");
                fwrite($fh, "DROP TABLE IF EXISTS `{$t}`;\n");
                fwrite($fh, $create[0]->{'Create Table'} . ";\n\n");

                // Qué columnas guardan binario. Un valor binario entre comillas
                // no sobrevive al viaje: se escribe en hexadecimal.
                $binarias = [];
                foreach (DB::select("SHOW COLUMNS FROM `{$t}`") as $col) {
                    $tipo = strtolower($col->Type);
                    if (str_contains($tipo, 'blob') || str_contains($tipo, 'binary')) {
                        $binarias[$col->Field] = true;
                    }
                }

                $escribirBloque = function ($filas) use ($fh, $t, $pdo, $binarias) {
                    if ($filas->isEmpty()) {
                        return;
                    }

                    $columnas = '`' . implode('`, `', array_keys((array) $filas->first())) . '`';
                    $valores  = $filas->map(function ($fila) use ($pdo, $binarias) {
                        $escapados = [];

                        foreach ((array) $fila as $campo => $v) {
                            if ($v === null) {
                                $escapados[] = 'NULL';
                            } elseif (isset($binarias[$campo])) {
                                $escapados[] = '0x' . bin2hex((string) $v);
                            } else {
                                // quote() del propio PDO en vez de addslashes:
                                // escapa según la conexión y su juego de
                                // caracteres, que es lo que addslashes no sabe.
                                $escapados[] = $pdo->quote((string) $v);
                            }
                        }

                        return '(' . implode(', ', $escapados) . ')';
                    });

                    fwrite($fh, "INSERT INTO `{$t}` ({$columnas}) VALUES\n" . $valores->implode(",\n") . ";\n\n");
                };

                // Por bloques, no de una: una tabla de movimientos de
                // inventario con miles de filas se comía la memoria.
                //
                // chunkById pagina por llave y no por OFFSET, pero necesita
                // columna id. Las tablas que no la tienen son pivotes
                // pequeños, así que esas se traen enteras sin riesgo.
                $columnas = array_map(fn ($c) => $c->Field, DB::select("SHOW COLUMNS FROM `{$t}`"));

                if (in_array('id', $columnas, true)) {
                    DB::table($t)->orderBy('id')->chunkById(500, $escribirBloque);
                } else {
                    $escribirBloque(DB::table($t)->get());
                }
            }

            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fh);
        }
    }
}
