<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\Configuracion;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Support\Instalacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

/**
 * Asistente de instalación en tres pasos.
 *
 * Sus vistas son Blade y no Vue —la única excepción del proyecto— porque tienen
 * que dibujarse cuando todavía no hay base de datos ni configuración, y el layout
 * normal consulta las dos cosas para saber la marca de la empresa.
 *
 * Cada paso valida lo suyo y no confía en que el anterior se hizo: alguien puede
 * entrar directo por URL a la mitad del asistente.
 */
class InstaladorController extends Controller
{
    // ─── Paso 1: requisitos del servidor ─────────────────────────────────────

    public function requisitos(): View
    {
        return view('instalador.requisitos', [
            'requisitos' => Instalacion::requisitos(),
            'puedeSeguir' => Instalacion::requisitosCumplidos(),
        ]);
    }

    // ─── Paso 2: base de datos ───────────────────────────────────────────────

    public function baseDatos(): View
    {
        return view('instalador.base-datos', [
            'valores' => [
                'host'     => '127.0.0.1',
                'port'     => '3306',
                'database' => '',
                'username' => '',
            ],
        ]);
    }

    public function guardarBaseDatos(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'host'     => ['required', 'string', 'max:255'],
            'port'     => ['required', 'numeric'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string'],
        ], [], [
            'database' => 'nombre de la base de datos',
            'username' => 'usuario',
            'password' => 'contraseña',
        ]);

        $datos['password'] ??= '';

        if ($error = Instalacion::probarConexion($datos)) {
            return back()->withInput()->withErrors(['database' => $error]);
        }

        // La conexión sirve: se escribe el .env y se deja la app apuntando ahí
        // en esta misma petición, para poder migrar sin recargar la configuración.
        Instalacion::escribirEnv([
            'APP_URL'     => $request->getSchemeAndHttpHost(),
            'APP_ENV'     => 'production',
            'APP_DEBUG'   => 'false',
            'DB_HOST'     => $datos['host'],
            'DB_PORT'     => (string) $datos['port'],
            'DB_DATABASE' => $datos['database'],
            'DB_USERNAME' => $datos['username'],
            'DB_PASSWORD' => $datos['password'],
        ]);

        return redirect('/instalar/base-datos/migrar');
    }

    // ─── Paso 2b: migraciones (por AJAX, porque tardan) ──────────────────────

    public function pantallaMigrar(): View
    {
        return view('instalador.migrar');
    }

    /**
     * Corre las migraciones.
     *
     * Va por AJAX y no dentro del formulario porque en un hosting compartido
     * cargado son decenas de segundos, y una petición normal se cortaría a la
     * mitad dejando la base incompleta.
     */
    public function migrar(): JsonResponse
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $this->aplicarConexionDelEnv();

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'No se pudo conectar a la base de datos. Vuelve al paso anterior.',
            ], 422);
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Las migraciones fallaron: ' . $e->getMessage(),
            ], 500);
        }

        if (! Schema::hasTable('users')) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Las migraciones corrieron pero la base quedó incompleta.',
            ], 500);
        }

        return response()->json([
            'ok'      => true,
            'mensaje' => 'Base de datos lista.',
            'salida'  => trim(Artisan::output()),
        ]);
    }

    // ─── Paso 3: empresa y administrador ─────────────────────────────────────

    public function cuenta(): View|RedirectResponse
    {
        if (! $this->baseLista()) {
            return redirect('/instalar/base-datos')
                ->withErrors(['database' => 'Primero hay que preparar la base de datos.']);
        }

        return view('instalador.cuenta');
    }

    public function guardarCuenta(Request $request): RedirectResponse
    {
        if (! $this->baseLista()) {
            return redirect('/instalar/base-datos');
        }

        $datos = $request->validate([
            'empresa'  => ['required', 'string', 'max:120'],
            'nombre'   => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [], [
            'empresa'  => 'nombre de la empresa',
            'nombre'   => 'tu nombre',
            'password' => 'contraseña',
        ]);

        $sede = Sede::where('es_principal', true)->first() ?? Sede::first();

        if (! $sede) {
            $sede = Sede::create([
                'nombre' => 'Principal', 'codigo' => 'PRI',
                'tiene_ventas' => true, 'tiene_produccion' => true,
                'es_principal' => true, 'activa' => true,
            ]);
        }

        if (Bodega::count() === 0) {
            Bodega::create([
                'sede_id' => $sede->id, 'nombre' => 'Almacén principal',
                'tipo' => 'general', 'es_principal' => true, 'activa' => true,
            ]);
        }

        Configuracion::set('empresa_nombre', $datos['empresa']);

        User::updateOrCreate(
            ['email' => $datos['email']],
            [
                'name'     => $datos['nombre'],
                'password' => $datos['password'], // el modelo lo hashea
                'rol'      => 'administrador',
                'rol_id'   => Rol::where('nombre', 'Administrador')->value('id'),
                'sede_id'  => $sede->id,
                'activo'   => true,
            ]
        );

        // Llave propia de esta instalación: si el paquete traía una, se cambia
        // ahora para que dos instalaciones nunca compartan la misma.
        Instalacion::escribirEnv(['APP_KEY' => Instalacion::generarAppKey()]);

        Instalacion::marcar();

        return redirect('/instalar/listo');
    }

    // ─── Final ───────────────────────────────────────────────────────────────

    public function listo(): View|RedirectResponse
    {
        if (! Instalacion::estaInstalada()) {
            return redirect('/instalar');
        }

        return view('instalador.listo');
    }

    // ─── Apoyo ───────────────────────────────────────────────────────────────

    /**
     * Aplica al vuelo la conexión que quedó escrita en el .env.
     *
     * Hace falta porque la configuración se cargó al arrancar la petición, antes
     * de que el paso anterior escribiera el archivo.
     */
    private function aplicarConexionDelEnv(): void
    {
        $env = [];
        foreach (file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            if (str_starts_with(trim($linea), '#') || ! str_contains($linea, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $linea, 2);
            $env[trim($k)] = trim(trim($v), '"\'');
        }

        Config::set('database.connections.mysql.host', $env['DB_HOST'] ?? '127.0.0.1');
        Config::set('database.connections.mysql.port', $env['DB_PORT'] ?? '3306');
        Config::set('database.connections.mysql.database', $env['DB_DATABASE'] ?? '');
        Config::set('database.connections.mysql.username', $env['DB_USERNAME'] ?? '');
        Config::set('database.connections.mysql.password', $env['DB_PASSWORD'] ?? '');

        DB::purge('mysql');
    }

    private function baseLista(): bool
    {
        $this->aplicarConexionDelEnv();

        try {
            return Schema::hasTable('users') && Schema::hasTable('roles');
        } catch (Throwable) {
            return false;
        }
    }
}
