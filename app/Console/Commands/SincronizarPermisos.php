<?php

namespace App\Console\Commands;

use App\Models\Rol;
use App\Support\Permisos;
use Illuminate\Console\Command;

/**
 * Le da a cada rol del sistema los permisos que le corresponden y todavía no tiene.
 *
 * **Este comando existe por un agujero del producto instalable.** El catálogo de permisos vive
 * en código, pero lo que un rol puede hacer vive en la tabla `roles`, escrita una sola vez
 * cuando se creó. Una actualización que agrega un módulo agrega su permiso al catálogo y **no
 * se lo da a nadie**: el menú no lo muestra, las rutas responden 403 y el módulo queda
 * instalado e invisible. Pasó con Alistamiento y con los gráficos del tablero — desplegados,
 * migrados, y sin forma de verlos.
 *
 * Solo **agrega**. Lo que la empresa le quitó a un rol a mano se queda quitado: devolvérselo
 * sería pisar una decisión suya con cada actualización.
 *
 * Toca únicamente los roles de sistema —los que nacieron con la instalación y tienen un
 * `rol_base`—. Un rol que la empresa creó es suyo y se queda como lo dejó.
 */
class SincronizarPermisos extends Command
{
    protected $signature = 'permisos:sincronizar {--simular : Muestra lo que agregaría sin escribir nada}';

    protected $description = 'Agrega a cada rol del sistema los permisos nuevos que le correspondan';

    public function handle(): int
    {
        $simular = (bool) $this->option('simular');
        $filas   = [];
        $total   = 0;

        foreach (Rol::where('es_sistema', true)->whereNotNull('rol_base')->get() as $rol) {
            $deberia = Permisos::porRolLegado($rol->rol_base);
            $tiene   = $rol->permisos();
            $faltan  = array_values(array_diff($deberia, $tiene));

            if ($faltan === []) {
                continue;
            }

            $total += count($faltan);
            $filas[] = [$rol->nombre, count($faltan), implode(', ', array_slice($faltan, 0, 6)) . (count($faltan) > 6 ? '…' : '')];

            if (! $simular) {
                foreach ($faltan as $permiso) {
                    $rol->permisosAsignados()->firstOrCreate(['permiso' => $permiso]);
                }
            }
        }

        if ($filas === []) {
            $this->info('Todos los roles del sistema ya tienen sus permisos. Nada que agregar.');

            return self::SUCCESS;
        }

        $this->table(['Rol', 'Permisos nuevos', 'Cuáles'], $filas);

        $simular
            ? $this->warn("Simulación: se agregarían {$total} permiso(s). Corre el comando sin --simular para escribirlo.")
            : $this->info("{$total} permiso(s) agregado(s).");

        return self::SUCCESS;
    }
}
