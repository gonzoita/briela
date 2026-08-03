<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $usuarios = [
            [
                'name'     => 'Administrador',
                'email'    => 'admin@interfrigo.com',
                'password' => Hash::make('password'),
                'rol'      => 'administrador',
                'activo'   => true,
            ],
            [
                'name'     => 'Jefe de Producción',
                'email'    => 'jefe@interfrigo.com',
                'password' => Hash::make('password'),
                'rol'      => 'jefe_produccion',
                'activo'   => true,
            ],
            [
                'name'     => 'Vendedor',
                'email'    => 'vendedor@interfrigo.com',
                'password' => Hash::make('password'),
                'rol'      => 'vendedor',
                'activo'   => true,
            ],
            [
                'name'     => 'Operario',
                'email'    => 'operario@interfrigo.com',
                'password' => Hash::make('password'),
                'rol'      => 'operario',
                'activo'   => true,
            ],
        ];

        foreach ($usuarios as $data) {
            User::updateOrCreate(['email' => $data['email']], $data);
        }

        $this->call(BodegaSeeder::class);
        $this->call(ProductoSeeder::class);
        $this->call(InsumoSeeder::class);
        $this->call(FormulaComponenteSeeder::class);
        $this->call(PlantillaEnsambleSeeder::class);
    }
}
