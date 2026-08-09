<?php

namespace App\Console\Commands;

use App\Models\Bodega;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Deja una instalación nueva lista para entrar, sin datos de nadie más.
 *
 * Es la alternativa a `db:seed` en una instalación real. Los seeders sirven para
 * desarrollo, pero no para producción, por dos razones:
 *
 *   1. Crean cuatro usuarios con la contraseña "password". En un sitio público
 *      eso es una puerta abierta.
 *   2. Siembran productos y plantillas de ensamble de ejemplo, que son del
 *      negocio de otra empresa y no tienen por qué aparecerle a un cliente.
 *
 * Lo estructural (roles, permisos, la sede principal y la configuración) ya lo
 * dejan puesto las migraciones, así que aquí solo falta la bodega principal y
 * el primer administrador.
 *
 * Es idempotente: se puede correr de nuevo sin romper nada.
 */
class Instalar extends Command
{
    protected $signature = 'briela:instalar
                            {--nombre= : Nombre del administrador}
                            {--email= : Correo del administrador}';

    protected $description = 'Prepara una instalación nueva: bodega principal y primer administrador';

    public function handle(): int
    {
        if (! Schema::hasTable('users')) {
            $this->error('Faltan las migraciones. Corre primero: php artisan migrate --force');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('Instalación de Briela');
        $this->line('');

        $sede = $this->sedePrincipal();
        $this->paso("Sede principal: {$sede->nombre}");

        $this->bodegaPrincipal($sede);

        if (! $this->administrador($sede)) {
            $this->line('');
            $this->error('No se creó el administrador. Corrige lo anterior y vuelve a correr el comando.');
            $this->line('');

            return self::FAILURE;
        }

        $this->avisarUsuariosDePrueba();

        $this->line('');
        $this->info('Listo. Ya puedes entrar en /login.');
        $this->line('');

        return self::SUCCESS;
    }

    /** La sede la crea una migración; si no está, se crea para no dejar la instalación a medias. */
    private function sedePrincipal(): Sede
    {
        $sede = Sede::where('es_principal', true)->first() ?? Sede::first();

        if (! $sede) {
            $sede = Sede::create([
                'nombre'           => 'Principal',
                'codigo'           => 'PRI',
                'tiene_ventas'     => true,
                'tiene_produccion' => true,
                'es_principal'     => true,
                'activa'           => true,
            ]);
        }

        return $sede;
    }

    /** Sin al menos una bodega, el inventario no tiene dónde registrar stock. */
    private function bodegaPrincipal(Sede $sede): void
    {
        if (Bodega::count() > 0) {
            $this->paso('Bodegas: ya existen, no se toca nada');

            return;
        }

        Bodega::create([
            'sede_id'      => $sede->id,
            'nombre'       => 'Almacén principal',
            'tipo'         => 'general',
            'es_principal' => true,
            'activa'       => true,
        ]);

        $this->paso('Bodega creada: Almacén principal');
    }

    private function administrador(Sede $sede): bool
    {
        $nombre = $this->option('nombre') ?: $this->ask('Nombre del administrador', 'Administrador');
        $email  = $this->option('email')  ?: $this->ask('Correo del administrador');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Ese correo no es válido.');

            return false;
        }

        // La contraseña se pide siempre por teclado, nunca como argumento: un
        // argumento queda en el historial del shell y en la lista de procesos.
        $clave = $this->secret('Contraseña (no se muestra al escribir)');

        if (strlen((string) $clave) < 8) {
            $this->error('La contraseña debe tener al menos 8 caracteres.');

            return false;
        }

        if ($clave !== $this->secret('Repite la contraseña')) {
            $this->error('Las contraseñas no coinciden.');

            return false;
        }

        $rolAdmin = Rol::where('nombre', 'Administrador')->first();

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name'     => $nombre,
                'password' => $clave, // el modelo lo hashea (cast 'hashed')
                'rol'      => 'administrador',
                'rol_id'   => $rolAdmin?->id,
                'sede_id'  => $sede->id,
                'activo'   => true,
            ]
        );

        $this->paso(($user->wasRecentlyCreated ? 'Administrador creado: ' : 'Administrador actualizado: ') . $email);

        return true;
    }

    /**
     * Si la base pasó por los seeders de desarrollo, quedaron usuarios con una
     * contraseña conocida. En un servidor público hay que sacarlos.
     */
    private function avisarUsuariosDePrueba(): void
    {
        $prueba = User::whereIn('email', [
            'admin@briela.app', 'jefe@briela.app', 'vendedor@briela.app', 'operario@briela.app',
        ])->pluck('email');

        if ($prueba->isEmpty()) {
            return;
        }

        $this->line('');
        $this->warn('Atención: hay usuarios de los seeders de desarrollo, con contraseña conocida:');
        foreach ($prueba as $email) {
            $this->warn("  - {$email}");
        }
        $this->warn('En un servidor público, cámbiales la contraseña o desactívalos.');
    }

    private function paso(string $texto): void
    {
        $this->line("  <fg=green>·</> {$texto}");
    }
}
