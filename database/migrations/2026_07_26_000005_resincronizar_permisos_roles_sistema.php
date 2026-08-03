<?php

use App\Support\Permisos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Vuelve a sembrar los permisos de los roles de SISTEMA a partir del
    // catálogo. Necesario porque al cerrar las rutas por permiso aparecieron
    // dos casos que faltaban: el vendedor podía crear solicitudes de compra, y
    // borrar pagos era exclusivo del administrador.
    //
    // Solo toca los roles de sistema — los roles que haya creado el usuario
    // quedan intactos.
    public function up(): void
    {
        $roles = DB::table('roles')->where('es_sistema', true)->get(['id', 'rol_base']);

        foreach ($roles as $rol) {
            $permisos = Permisos::porRolLegado($rol->rol_base);

            $yaTiene = DB::table('rol_permiso')
                ->where('rol_id', $rol->id)
                ->pluck('permiso')
                ->all();

            $faltantes = array_diff($permisos, $yaTiene);

            if (empty($faltantes)) {
                continue;
            }

            DB::table('rol_permiso')->insert(
                collect($faltantes)
                    ->map(fn ($permiso) => ['rol_id' => $rol->id, 'permiso' => $permiso])
                    ->all()
            );
        }
    }

    public function down(): void
    {
        // No se revierte: quitar permisos podría dejar gente sin acceso.
    }
};
