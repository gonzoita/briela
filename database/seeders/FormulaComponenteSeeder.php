<?php

namespace Database\Seeders;

use App\Models\FormulaComponente;
use App\Models\Insumo;
use Illuminate\Database\Seeder;

class FormulaComponenteSeeder extends Seeder
{
    public function run(): void
    {
        // Columnas: M_SUELO, M_TOPE, M_SUELO_D, M_TOPE_D, VAIVEN, VAIVEN_D, INST, INST_D, SE12, SM20, P480
        $tabla = [
            'LAMINA PREPINTADA'              => [4.58,     4.70,    5.5876,   11.468,  4.5,  4.5,  4.5,  4.5,  0,    0,    0],
            'LAMINA DE ACERO 430'            => [4.58,     4.70,    9.16,     9.40,    4.5,  4.5,  4.5,  4.5,  0,    0,    0],
            'LAMINA DE ACERO 304'            => [4.58,     4.70,    5.5876,   11.468,  4.5,  4.5,  4.5,  4.5,  0,    0,    0],
            'LAMINA DE ALFAJOR'              => [1.30,     1.30,    2.00,     2.60,    2.0,  2.0,  2.0,  2.0,  0,    0,    0],
            'LAMINA PVC'                     => [5.424,    5.424,   5.664,    10.848,  4.62, 4.84, 4.62, 4.84, 0,    0,    0],
            'ANGULO Y LAM GALV'              => [1,        1,       2,        2,       0.7,  1.4,  0.7,  1.4,  0,    0,    0],
            'PERFIL PERIMETRAL 70MM'         => [7.304,    7.436,   12,       15.4128, 0,    0,    0,    0,    0,    0,    0],
            'PERFIL PERIMETRAL 92MM'         => [7.304,    7.436,   12,       15.4128, 0,    0,    0,    0,    0,    0,    0],
            'PERFIL VAIVEN'                  => [0,        0,       0,        0,       7,    12,   7,    12,   0,    0,    0],
            'TUBULAR 4 X 1 3/4'              => [6,        7.4,     6,        7.9,     0,    0,    0,    0,    0,    0,    0],
            'TUBULAR 3X1'                    => [0,        0,       0,        0,       6,    6,    0,    0,    0,    0,    0],
            'TUBULAR 2X1 CON ALETA'          => [0,        0,       0,        0,       0,    0,    6,    6,    0,    0,    0],
            'EMPAQUE PERIMETRAL'             => [5.71,     5.83,    10.34,    11.66,   0,    0,    0,    0,    0,    0,    0],
            'EMPAQUE BARREDOR'               => [1.18,     1.18,    1.28,     2.36,    0,    0,    0,    0,    0,    0,    0],
            'EMPAQUE VAIVEN'                 => [0,        0,       0,        0,       7.3,  12.6, 0,    0,    0,    0,    0],
            'EMPAQUE TIPO H'                 => [0,        0,       0,        0,       0,    0,    7,    12,   0,    0,    0],
            'EMPAQUE VENTANILLA'             => [0,        0,       0,        0,       1.7,  3.4,  1.7,  3.4,  0,    0,    0],
            'POLEAS SE12'                    => [0,        0,       0,        0,       0,    0,    0,    0,    2,    0,    0],
            'CURVAS SE12'                    => [0,        0,       0,        0,       0,    0,    0,    0,    1,    0,    0],
            'FINAL DE CARRERA SE12'          => [0,        0,       0,        0,       0,    0,    0,    0,    1,    0,    0],
            'POLEAS 480'                     => [0,        0,       0,        0,       0,    0,    0,    0,    0,    0,    2],
            'CURVAS 480'                     => [0,        0,       0,        0,       0,    0,    0,    0,    0,    0,    1],
            'FINAL DE CARRERA 480'           => [0,        0,       0,        0,       0,    0,    0,    0,    0,    0,    1],
            'POLEAS SM20'                    => [0,        0,       0,        0,       0,    0,    0,    0,    0,    2,    0],
            'CURVAS SM20'                    => [0,        0,       0,        0,       0,    0,    0,    0,    0,    1,    0],
            'FINAL DE CARRERA SM20'          => [0,        0,       0,        0,       0,    0,    0,    0,    0,    1,    0],
            'RIEL SUPERIOR EXTERNO SM20'     => [0,        0,       0,        0,       0,    0,    0,    0,    0,    2.5,  0],
            'RIEL SUPERIOR INTERNO SM20'     => [0,        0,       0,        0,       0,    0,    0,    0,    0,    2.5,  0],
            'RIEL SUPERIOR EXTERNO SE12'     => [0,        0,       0,        0,       0,    0,    0,    0,    2.5,  0,    0],
            'RIEL SUPERIOR INTERNO SE12'     => [0,        0,       0,        0,       0,    0,    0,    0,    2.5,  0,    0],
            'RIEL SUPERIOR EXTERNO 480'      => [0,        0,       0,        0,       0,    0,    0,    0,    0,    0,    2.5],
            'RIEL SUPERIOR INTERNO 480'      => [0,        0,       0,        0,       0,    0,    0,    0,    0,    0,    2.5],
            'RIEL INFERIOR'                  => [0,        0,       0,        0,       0,    0,    0,    0,    1.25, 2.5,  1.25],
            'CERRADURA MINI'                 => [0,        0,       0,        0,       0,    0,    1,    2,    0,    0,    0],
            'CERRADURA EXTERNA + ANTIPANICO' => [0,        0,       0,        0,       0,    0,    1,    0,    0,    0,    0],
            'CERRADURA EXTERNA + ANTIPANICO DOBLE' => [0,  0,       0,        0,       0,    0,    0,    1,    0,    0,    0],
            'MANIJA EXTERNA LATERAL'         => [0,        0,       0,        0,       0,    0,    0,    0,    1,    1,    1],
            'MANIJA INTERNA MEDIANA'         => [0,        0,       0,        0,       0,    0,    0,    0,    1,    1,    1],
            'REGISTRO FIJO'                  => [0,        0,       0,        0,       0,    0,    0,    0,    1,    1,    1],
            'BISAGRAS VAIVEN'                => [0,        0,       0,        0,       3,    6,    0,    0,    0,    0,    0],
            'BISAGRA OFICINA INOX'           => [0,        0,       0,        0,       0,    0,    4,    8,    0,    0,    0],
            'TORNILLOS'                      => [0,        0,       0,        0,       0,    0,    0,    0,    5,    5,    5],
            'TORNILLOS DE FIJACION SM20'     => [0,        0,       0,        0,       0,    0,    0,    0,    0,    4,    0],
            'BROCA, DISCO Y TORNILLOS'       => [1,        1,       2,        2,       1,    2,    1,    2,    0,    0,    0],
            'POLIURETANO'                    => [7.112448, 7.30296, 6.943104, 18.739296,3.872,3.872,3.872,3.872,0,    0,    0],
            'CINTA ENMASCARAR, SILICONA, SUPERBONDER' => [1,1,2,2, 1,2,1,2,  0,0,0],
            'PORTARESISTENCIA'               => [6,        7,       6,        7,       0,    0,    0,    0,    0,    0,    0],
            'RESISTENCIA'                    => [6.4,      7.4,     6.4,      7.4,     0,    0,    0,    0,    0,    0,    0],
            'VENTANILLA'                     => [0,        0,       0,        0,       1,    2,    1,    2,    0,    0,    0],
            'APOYO Y GANCHO'                 => [0,        0,       0,        0,       0,    0,    0,    0,    1,    1,    1],
            'BRIDA Y TAPETA'                 => [0,        0,       0,        0,       0,    0,    0,    0,    1,    1,    1],
            'MANO DE OBRA'                   => [0.9,      0.9,     1.8,      1.8,     0.8,  1.6,  0.8,  1.6,  0,    0,    0],
            'INSTALACIONES'                  => [1,        1,       2,        2,       1,    2,    1,    2,    0,    0,    0],
            'LIMPIEZA Y EMPAQUE'             => [1,        1,       2,        2,       1,    2,    1,    2,    0,    0,    0],
            'TAPARIEL SUPERIOR SE12'         => [0,        0,       0,        0,       0,    0,    0,    0,    1,    0,    0],
            'VISOR 80MM'                     => [0,        0,       0,        0,       0,    0,    0,    0,    0,    0,    0],
            'VISOR 40MM'                     => [0,        0,       0,        0,       0,    0,    0,    0,    0,    0,    0],
            'PALANCA DE MANO 8100'           => [0,        0,       0,        0,       0,    0,    0,    0,    0,    0,    0],
        ];

        $tipos = ['M_SUELO','M_TOPE','M_SUELO_D','M_TOPE_D','VAIVEN','VAIVEN_D','INST','INST_D','SE12','SM20','P480'];

        foreach ($tabla as $nombre => $cantidades) {
            $insumo = Insumo::where('nombre', $nombre)->first();
            if (! $insumo) {
                continue;
            }

            foreach ($tipos as $i => $tipo) {
                FormulaComponente::updateOrCreate(
                    ['insumo_id' => $insumo->id, 'tipo_puerta' => $tipo],
                    ['cantidad' => $cantidades[$i] ?? 0]
                );
            }
        }
    }
}
