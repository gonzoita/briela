<?php

namespace Database\Seeders;

use App\Models\Bodega;
use Illuminate\Database\Seeder;

class BodegaSeeder extends Seeder
{
    public function run(): void
    {
        $bodegas = [
            ['nombre' => 'Almacén General', 'tipo' => 'general',  'es_principal' => true,  'activa' => true],
            ['nombre' => 'Bodega 1',         'tipo' => 'otra',     'es_principal' => false, 'activa' => true],
            ['nombre' => 'Bodega 2',         'tipo' => 'otra',     'es_principal' => false, 'activa' => true],
            ['nombre' => 'Bodega 3',         'tipo' => 'otra',     'es_principal' => false, 'activa' => true],
        ];

        foreach ($bodegas as $data) {
            Bodega::updateOrCreate(['nombre' => $data['nombre']], $data);
        }
    }
}
