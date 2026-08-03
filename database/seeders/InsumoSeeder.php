<?php

namespace Database\Seeders;

use App\Models\Insumo;
use Illuminate\Database\Seeder;

class InsumoSeeder extends Seeder
{
    public function run(): void
    {
        $insumos = [
            // Láminas y Superficies
            ['categoria' => 'Láminas y Superficies', 'nombre' => 'LAMINA PREPINTADA',     'unidad' => 'M2',  'precio_costo' => 26000,  'orden' => 1],
            ['categoria' => 'Láminas y Superficies', 'nombre' => 'LAMINA DE ACERO 430',   'unidad' => 'M2',  'precio_costo' => 48000,  'orden' => 2],
            ['categoria' => 'Láminas y Superficies', 'nombre' => 'LAMINA DE ACERO 304',   'unidad' => 'M2',  'precio_costo' => 75000,  'orden' => 3],
            ['categoria' => 'Láminas y Superficies', 'nombre' => 'ANGULO Y LAM GALV',     'unidad' => 'M2',  'precio_costo' => 25000,  'orden' => 4],
            ['categoria' => 'Láminas y Superficies', 'nombre' => 'LAMINA DE ALFAJOR',     'unidad' => 'M2',  'precio_costo' => 45000,  'orden' => 5],
            ['categoria' => 'Láminas y Superficies', 'nombre' => 'LAMINA PVC',            'unidad' => 'M2',  'precio_costo' => 75000,  'orden' => 6],

            // Perfiles y Estructuras
            ['categoria' => 'Perfiles y Estructuras', 'nombre' => 'PERFIL PERIMETRAL 70MM',  'unidad' => 'ML', 'precio_costo' => 19000, 'orden' => 1],
            ['categoria' => 'Perfiles y Estructuras', 'nombre' => 'PERFIL PERIMETRAL 92MM',  'unidad' => 'ML', 'precio_costo' => 24000, 'orden' => 2],
            ['categoria' => 'Perfiles y Estructuras', 'nombre' => 'PERFIL VAIVEN',           'unidad' => 'ML', 'precio_costo' => 19000, 'orden' => 3],
            ['categoria' => 'Perfiles y Estructuras', 'nombre' => 'TUBULAR 4 X 1 3/4',       'unidad' => 'ML', 'precio_costo' => 32000, 'orden' => 4],
            ['categoria' => 'Perfiles y Estructuras', 'nombre' => 'TUBULAR 3X1',             'unidad' => 'ML', 'precio_costo' => 20000, 'orden' => 5],
            ['categoria' => 'Perfiles y Estructuras', 'nombre' => 'TUBULAR 2X1 CON ALETA',   'unidad' => 'ML', 'precio_costo' => 19000, 'orden' => 6],

            // Empaques y Sellos
            ['categoria' => 'Empaques y Sellos', 'nombre' => 'EMPAQUE PERIMETRAL',   'unidad' => 'ML', 'precio_costo' => 10000,  'orden' => 1],
            ['categoria' => 'Empaques y Sellos', 'nombre' => 'EMPAQUE BARREDOR',     'unidad' => 'ML', 'precio_costo' => 12500,  'orden' => 2],
            ['categoria' => 'Empaques y Sellos', 'nombre' => 'EMPAQUE VAIVEN',       'unidad' => 'ML', 'precio_costo' => 11000,  'orden' => 3],
            ['categoria' => 'Empaques y Sellos', 'nombre' => 'EMPAQUE TIPO H',       'unidad' => 'ML', 'precio_costo' => 5700,   'orden' => 4],
            ['categoria' => 'Empaques y Sellos', 'nombre' => 'EMPAQUE VENTANILLA',   'unidad' => 'ML', 'precio_costo' => 18500,  'orden' => 5],

            // Sistemas Corrediza y Herrajes
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'POLEAS SE12',                  'unidad' => 'UN', 'precio_costo' => 130000,  'orden' => 1],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'CURVAS SE12',                  'unidad' => 'UN', 'precio_costo' => 20000,   'orden' => 2],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'FINAL DE CARRERA SE12',        'unidad' => 'UN', 'precio_costo' => 23000,   'orden' => 3],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'POLEAS 480',                   'unidad' => 'UN', 'precio_costo' => 170000,  'orden' => 4],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'CURVAS 480',                   'unidad' => 'UN', 'precio_costo' => 9000,    'orden' => 5],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'FINAL DE CARRERA 480',         'unidad' => 'UN', 'precio_costo' => 40000,   'orden' => 6],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'POLEAS SM20',                  'unidad' => 'UN', 'precio_costo' => 180000,  'orden' => 7],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'CURVAS SM20',                  'unidad' => 'UN', 'precio_costo' => 42000,   'orden' => 8],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'FINAL DE CARRERA SM20',        'unidad' => 'UN', 'precio_costo' => 40000,   'orden' => 9],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'RIEL SUPERIOR EXTERNO SM20',   'unidad' => 'ML', 'precio_costo' => 60000,   'orden' => 10],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'RIEL SUPERIOR INTERNO SM20',   'unidad' => 'ML', 'precio_costo' => 15000,   'orden' => 11],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'RIEL SUPERIOR EXTERNO SE12',   'unidad' => 'ML', 'precio_costo' => 47600,   'orden' => 12],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'RIEL SUPERIOR INTERNO SE12',   'unidad' => 'ML', 'precio_costo' => 8500,    'orden' => 13],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'RIEL SUPERIOR EXTERNO 480',    'unidad' => 'ML', 'precio_costo' => 53000,   'orden' => 14],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'RIEL SUPERIOR INTERNO 480',    'unidad' => 'ML', 'precio_costo' => 15000,   'orden' => 15],
            ['categoria' => 'Sistemas Corrediza y Herrajes', 'nombre' => 'RIEL INFERIOR',                'unidad' => 'ML', 'precio_costo' => 23800,   'orden' => 16],

            // Accesorios y Cerraduras
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'CERRADURA SERIE 5000',                        'unidad' => 'UN', 'precio_costo' => 125000,   'orden' => 1],
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'CERRADURA SERIE 6000',                        'unidad' => 'UN', 'precio_costo' => 440000,   'orden' => 2],
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'CERRADURA SERIE 7000',                        'unidad' => 'UN', 'precio_costo' => 150000,   'orden' => 3],
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'CERRADURA SERIE 1800',                        'unidad' => 'UN', 'precio_costo' => 175000,   'orden' => 4],
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'CERRADURA 6000 DE 3 PUNTOS',                  'unidad' => 'UN', 'precio_costo' => 1250000,  'orden' => 5],
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'CERRADURA SERIE 1800 DE 3 PUNTOS',            'unidad' => 'UN', 'precio_costo' => 310000,   'orden' => 6],
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'CERRADURA MINI',                              'unidad' => 'UN', 'precio_costo' => 78000,    'orden' => 7],
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'CERRADURA EXTERNA + ANTIPANICO',              'unidad' => 'UN', 'precio_costo' => 600000,   'orden' => 8],
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'CERRADURA EXTERNA + ANTIPANICO DOBLE',        'unidad' => 'UN', 'precio_costo' => 1200000,  'orden' => 9],
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'MANIJA EXTERNA LATERAL',                      'unidad' => 'UN', 'precio_costo' => 39000,    'orden' => 10],
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'MANIJA INTERNA MEDIANA',                      'unidad' => 'UN', 'precio_costo' => 23000,    'orden' => 11],
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'REGISTRO FIJO',                               'unidad' => 'UN', 'precio_costo' => 45000,    'orden' => 12],
            ['categoria' => 'Accesorios y Cerraduras', 'nombre' => 'TAPARIEL SUPERIOR SE12',                      'unidad' => 'UN', 'precio_costo' => 9750,     'orden' => 13],

            // Bisagras y Fijación
            ['categoria' => 'Bisagras y Fijación', 'nombre' => 'BISAGRAS VAIVEN',           'unidad' => 'UN', 'precio_costo' => 95000,  'orden' => 1],
            ['categoria' => 'Bisagras y Fijación', 'nombre' => 'BISAGRA BATIENTE 2900 ECO', 'unidad' => 'UN', 'precio_costo' => 32000,  'orden' => 2],
            ['categoria' => 'Bisagras y Fijación', 'nombre' => 'BISAGRA 2930 RV',           'unidad' => 'UN', 'precio_costo' => 39000,  'orden' => 3],
            ['categoria' => 'Bisagras y Fijación', 'nombre' => 'BISAGRA OFICINA INOX',      'unidad' => 'UN', 'precio_costo' => 12000,  'orden' => 4],
            ['categoria' => 'Bisagras y Fijación', 'nombre' => 'TORNILLOS',                  'unidad' => 'UN', 'precio_costo' => 8500,   'orden' => 5],
            ['categoria' => 'Bisagras y Fijación', 'nombre' => 'TORNILLOS DE FIJACION SM20', 'unidad' => 'UN', 'precio_costo' => 3400,   'orden' => 6],
            ['categoria' => 'Bisagras y Fijación', 'nombre' => 'BROCA, DISCO Y TORNILLOS',   'unidad' => 'UN', 'precio_costo' => 30000,  'orden' => 7],

            // Consumibles y Especiales
            ['categoria' => 'Consumibles y Especiales', 'nombre' => 'POLIURETANO',                           'unidad' => 'UN', 'precio_costo' => 17500,  'orden' => 1],
            ['categoria' => 'Consumibles y Especiales', 'nombre' => 'CINTA ENMASCARAR, SILICONA, SUPERBONDER','unidad' => 'UN', 'precio_costo' => 20000,  'orden' => 2],
            ['categoria' => 'Consumibles y Especiales', 'nombre' => 'PORTARESISTENCIA',                       'unidad' => 'ML', 'precio_costo' => 8000,   'orden' => 3],
            ['categoria' => 'Consumibles y Especiales', 'nombre' => 'RESISTENCIA',                            'unidad' => 'ML', 'precio_costo' => 12500,  'orden' => 4],
            ['categoria' => 'Consumibles y Especiales', 'nombre' => 'VENTANILLA',                             'unidad' => 'UN', 'precio_costo' => 22000,  'orden' => 5],
            ['categoria' => 'Consumibles y Especiales', 'nombre' => 'APOYO Y GANCHO',                         'unidad' => 'UN', 'precio_costo' => 29500,  'orden' => 6],
            ['categoria' => 'Consumibles y Especiales', 'nombre' => 'BRIDA Y TAPETA',                         'unidad' => 'UN', 'precio_costo' => 17500,  'orden' => 7],

            // Servicios y Operación
            ['categoria' => 'Servicios y Operación', 'nombre' => 'MANO DE OBRA',       'unidad' => 'UN', 'precio_costo' => 350000,  'orden' => 1],
            ['categoria' => 'Servicios y Operación', 'nombre' => 'INSTALACIONES',      'unidad' => 'UN', 'precio_costo' => 110000,  'orden' => 2],
            ['categoria' => 'Servicios y Operación', 'nombre' => 'LIMPIEZA Y EMPAQUE', 'unidad' => 'UN', 'precio_costo' => 60000,   'orden' => 3],

            // Visores y Opcionales
            ['categoria' => 'Visores y Opcionales', 'nombre' => 'VISOR 80MM',          'unidad' => 'UN', 'precio_costo' => 105000,  'orden' => 1],
            ['categoria' => 'Visores y Opcionales', 'nombre' => 'VISOR 40MM',          'unidad' => 'UN', 'precio_costo' => 70000,   'orden' => 2],
            ['categoria' => 'Visores y Opcionales', 'nombre' => 'PALANCA DE MANO 8100','unidad' => 'UN', 'precio_costo' => 440000,  'orden' => 3],
        ];

        foreach ($insumos as $dato) {
            Insumo::updateOrCreate(['nombre' => $dato['nombre']], $dato);
        }
    }
}
