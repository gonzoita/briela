<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Le da a los roles del sistema los permisos que las actualizaciones agregaron al catálogo.
 *
 * Alistamiento y los gráficos del tablero se desplegaron, se migraron, y **nadie podía verlos**:
 * el catálogo de permisos vive en código y lo que un rol puede hacer vive en la tabla `roles`,
 * escrita cuando se creó la instalación. Un permiso nuevo no llega solo a ningún rol.
 *
 * Esta migración lo corrige una vez. Para que no vuelva a pasar, el mismo comando corre en cada
 * despliegue: ver `scripts/traer-cambios.sh`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('rol_permiso')) {
            return;
        }

        Artisan::call('permisos:sincronizar');
    }

    /**
     * No se deshace: quitar permisos a ciegas dejaría a alguien sin poder entrar a su módulo,
     * y no hay forma de saber cuáles había antes de esta corrida.
     */
    public function down(): void
    {
    }
};
