<?php

use App\Support\Permisos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Roles configurables + permisos por rol + alcance por sede y bodega.
    //
    // Clave del diseño: cada rol tiene un "rol_base" que corresponde a uno de
    // los 4 roles históricos. Ese campo mantiene funcionando todo el control
    // de acceso que ya existe (middleware "rol:" y los chequeos dentro de los
    // controladores), mientras los permisos finos se aplican encima. Así el
    // cambio no le quita el acceso a nadie.
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('descripcion')->nullable();
            $table->enum('rol_base', ['administrador', 'jefe_produccion', 'vendedor', 'operario']);
            $table->boolean('es_sistema')->default(false); // los 4 originales: no se borran
            $table->boolean('todas_las_sedes')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('rol_permiso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permiso', 60); // ej. "clientes.crear"

            $table->unique(['rol_id', 'permiso']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('rol_id')->nullable()->after('rol')->constrained('roles')->nullOnDelete();
        });

        // Sedes y bodegas a las que accede cada usuario. Si un usuario no tiene
        // filas aquí, se usa su sede_id directa (comportamiento de la Fase 1).
        Schema::create('usuario_sede', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();

            $table->unique(['user_id', 'sede_id']);
        });

        Schema::create('usuario_bodega', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();

            $table->unique(['user_id', 'bodega_id']);
        });

        $this->sembrarRolesDeSistema();
    }

    /**
     * Crea los 4 roles históricos con los permisos equivalentes a lo que hoy
     * puede hacer cada uno, y enlaza a cada usuario existente con el suyo.
     */
    private function sembrarRolesDeSistema(): void
    {
        foreach (Permisos::rolesBase() as $rolBase => $etiqueta) {
            $rolId = DB::table('roles')->insertGetId([
                'nombre'          => $etiqueta,
                'descripcion'     => 'Rol original del sistema.',
                'rol_base'        => $rolBase,
                'es_sistema'      => true,
                'todas_las_sedes' => $rolBase === 'administrador',
                'activo'          => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $permisos = collect(Permisos::porRolLegado($rolBase))
                ->map(fn ($permiso) => ['rol_id' => $rolId, 'permiso' => $permiso])
                ->all();

            if ($permisos) {
                DB::table('rol_permiso')->insert($permisos);
            }

            // Los usuarios que hoy tienen ese rol quedan enlazados al nuevo.
            DB::table('users')->where('rol', $rolBase)->update(['rol_id' => $rolId]);
        }

        // Cada usuario arranca con acceso a su propia sede.
        $usuarios = DB::table('users')->whereNotNull('sede_id')->get(['id', 'sede_id']);
        $filas = $usuarios->map(fn ($u) => ['user_id' => $u->id, 'sede_id' => $u->sede_id])->all();

        if ($filas) {
            DB::table('usuario_sede')->insert($filas);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_bodega');
        Schema::dropIfExists('usuario_sede');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rol_id');
        });

        Schema::dropIfExists('rol_permiso');
        Schema::dropIfExists('roles');
    }
};
