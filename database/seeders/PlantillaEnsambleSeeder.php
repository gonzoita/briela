<?php

namespace Database\Seeders;

use App\Models\PlantillaEnsamble;
use Illuminate\Database\Seeder;

class PlantillaEnsambleSeeder extends Seeder
{
    public function run(): void
    {
        PlantillaEnsamble::where('nombre', 'Puerta Frigorífica')->delete();

        $p = PlantillaEnsamble::create([
            'nombre'      => 'Puerta Frigorífica',
            'descripcion' => 'Plantilla estándar para puertas frigoríficas batientes, vaivén, correderas e institucionales.',
            'activo'      => true,
            'orden'       => 1,
        ]);

        // ── CAMPOS DE ENTRADA ─────────────────────────────────────────────────────
        $campos = [
            [
                'nombre' => 'tipo_puerta', 'etiqueta' => 'Tipo de puerta',
                'tipo' => 'select', 'valor_defecto' => 'BATIENTE_SIMPLE',
                'requerido' => true, 'orden' => 1,
                'opciones' => [
                    ['valor' => 'BATIENTE_SIMPLE',     'etiqueta' => 'Batiente Simple'],
                    ['valor' => 'BATIENTE_DOBLE',      'etiqueta' => 'Batiente Doble'],
                    ['valor' => 'VAIVEN',              'etiqueta' => 'Vaivén Simple'],
                    ['valor' => 'VAIVEN_DOBLE',        'etiqueta' => 'Vaivén Doble'],
                    ['valor' => 'INSTITUCIONAL',       'etiqueta' => 'Institucional Simple'],
                    ['valor' => 'INSTITUCIONAL_DOBLE', 'etiqueta' => 'Institucional Doble'],
                    ['valor' => 'EMERGENCIA',          'etiqueta' => 'Emergencia Simple'],
                    ['valor' => 'EMERGENCIA_DOBLE',    'etiqueta' => 'Emergencia Doble'],
                    ['valor' => 'CORREDERA',           'etiqueta' => 'Corredera'],
                ],
            ],
            ['nombre' => 'ancho_vano', 'etiqueta' => 'Ancho del vano (m)', 'tipo' => 'decimal', 'valor_defecto' => '1.00', 'requerido' => true, 'orden' => 2, 'opciones' => null],
            ['nombre' => 'alto_vano',  'etiqueta' => 'Alto del vano (m)',  'tipo' => 'decimal', 'valor_defecto' => '2.20', 'requerido' => true, 'orden' => 3, 'opciones' => null],
            [
                'nombre' => 'tipo_sello', 'etiqueta' => 'Tipo de sello',
                'tipo' => 'select', 'valor_defecto' => 'SUELO', 'requerido' => true, 'orden' => 4,
                'opciones' => [['valor' => 'SUELO', 'etiqueta' => 'Al suelo'], ['valor' => 'TOPE', 'etiqueta' => 'A tope']],
            ],
            [
                'nombre' => 'temperatura', 'etiqueta' => 'Temperatura',
                'tipo' => 'select', 'valor_defecto' => 'MEDIA', 'requerido' => true, 'orden' => 5,
                'opciones' => [['valor' => 'MEDIA', 'etiqueta' => 'Media (0°C a +10°C)'], ['valor' => 'BAJA', 'etiqueta' => 'Baja (-18°C a -30°C)']],
            ],
            [
                'nombre' => 'lamina', 'etiqueta' => 'Tipo de lámina',
                'tipo' => 'select', 'valor_defecto' => 'PREPINTADA', 'requerido' => true, 'orden' => 6,
                'opciones' => [
                    ['valor' => 'PREPINTADA', 'etiqueta' => 'Lámina Prepintada'],
                    ['valor' => 'ACERO_430',  'etiqueta' => 'Lámina Acero 430'],
                    ['valor' => 'ACERO_304',  'etiqueta' => 'Lámina Acero 304'],
                    ['valor' => 'PVC',        'etiqueta' => 'Lámina PVC'],
                ],
            ],
            ['nombre' => 'con_alfajor', 'etiqueta' => 'Con lámina de alfajor', 'tipo' => 'checkbox', 'valor_defecto' => 'false', 'requerido' => false, 'orden' => 7, 'opciones' => null],
            ['nombre' => 'sin_llave',   'etiqueta' => 'Sin llave',              'tipo' => 'checkbox', 'valor_defecto' => 'false', 'requerido' => false, 'orden' => 8, 'opciones' => null],
            ['nombre' => 'palanca',     'etiqueta' => '+ Palanca de mano 8100', 'tipo' => 'checkbox', 'valor_defecto' => 'false', 'requerido' => false, 'orden' => 9, 'opciones' => null],
            ['nombre' => 'visor',       'etiqueta' => '+ Visor 80mm',           'tipo' => 'checkbox', 'valor_defecto' => 'false', 'requerido' => false, 'orden' => 10, 'opciones' => null],
        ];

        foreach ($campos as $c) {
            $p->campos()->create($c);
        }

        // ── COMPONENTES ───────────────────────────────────────────────────────────
        // producto_id se deja null; el precio se carga desde el campo precio en el producto ligado.
        // El admin puede vincular productos desde la UI del configurador.
        $comps = [
            ['etiqueta' => 'Perfil Perimetral 70mm',         'formula' => '2 * alto_hoja + 2 * ancho_hoja + 0.664', 'condicion' => 'temperatura == "MEDIA"',                                                         'unidad' => 'ML', 'orden' => 1],
            ['etiqueta' => 'Perfil Perimetral 92mm',         'formula' => '2 * alto_hoja + 2 * ancho_hoja + 0.664', 'condicion' => 'temperatura == "BAJA"',                                                          'unidad' => 'ML', 'orden' => 2],
            ['etiqueta' => 'Empaque Perimetral',             'formula' => '2 * alto_hoja + 2 * ancho_hoja + 0.4',  'condicion' => null,                                                                             'unidad' => 'ML', 'orden' => 3],
            ['etiqueta' => 'Empaque Barredor',               'formula' => 'ancho_hoja',                            'condicion' => 'tipo_sello == "SUELO"',                                                          'unidad' => 'ML', 'orden' => 4],
            ['etiqueta' => 'Lámina Prepintada',              'formula' => 'area_hoja * 2',                         'condicion' => 'lamina == "PREPINTADA"',                                                         'unidad' => 'M2', 'orden' => 5],
            ['etiqueta' => 'Lámina Acero 430',               'formula' => 'area_hoja * 2',                         'condicion' => 'lamina == "ACERO_430"',                                                          'unidad' => 'M2', 'orden' => 6],
            ['etiqueta' => 'Lámina Acero 304',               'formula' => 'area_hoja * 2',                         'condicion' => 'lamina == "ACERO_304"',                                                          'unidad' => 'M2', 'orden' => 7],
            ['etiqueta' => 'Lámina PVC',                     'formula' => 'area_hoja * 2',                         'condicion' => 'lamina == "PVC"',                                                                'unidad' => 'M2', 'orden' => 8],
            ['etiqueta' => 'Lámina de Alfajor',              'formula' => 'area_hoja',                             'condicion' => 'con_alfajor == true',                                                            'unidad' => 'M2', 'orden' => 9],
            ['etiqueta' => 'Bisagra 2930 RV (simple)',       'formula' => '3',                                     'condicion' => 'tipo_puerta == "BATIENTE_SIMPLE"',                                               'unidad' => 'UN', 'orden' => 10],
            ['etiqueta' => 'Bisagra 2930 RV (doble)',        'formula' => '6',                                     'condicion' => 'tipo_puerta == "BATIENTE_DOBLE"',                                                'unidad' => 'UN', 'orden' => 11],
            ['etiqueta' => 'Bisagra Vaivén (simple)',        'formula' => '2',                                     'condicion' => 'tipo_puerta == "VAIVEN" or tipo_puerta == "INSTITUCIONAL" or tipo_puerta == "EMERGENCIA"',                    'unidad' => 'UN', 'orden' => 12],
            ['etiqueta' => 'Bisagra Vaivén (doble)',         'formula' => '4',                                     'condicion' => 'tipo_puerta == "VAIVEN_DOBLE" or tipo_puerta == "INSTITUCIONAL_DOBLE" or tipo_puerta == "EMERGENCIA_DOBLE"', 'unidad' => 'UN', 'orden' => 13],
            ['etiqueta' => 'Cerradura Serie 5000',           'formula' => '1',                                     'condicion' => 'sin_llave == false and temperatura == "MEDIA" and tipo_puerta == "BATIENTE_SIMPLE"', 'unidad' => 'UN', 'orden' => 14],
            ['etiqueta' => 'Cerradura Serie 7000',           'formula' => '1',                                     'condicion' => 'sin_llave == false and temperatura == "BAJA" and tipo_puerta == "BATIENTE_SIMPLE"',  'unidad' => 'UN', 'orden' => 15],
            ['etiqueta' => 'Cerradura Serie 1800',           'formula' => '1',                                     'condicion' => 'sin_llave == false and tipo_puerta == "BATIENTE_DOBLE"',                           'unidad' => 'UN', 'orden' => 16],
            ['etiqueta' => 'Palanca de mano 8100',           'formula' => '1',                                     'condicion' => 'palanca == true',                                                                'unidad' => 'UN', 'orden' => 17],
            ['etiqueta' => 'Visor 80mm',                     'formula' => '1',                                     'condicion' => 'visor == true and temperatura == "MEDIA"',                                       'unidad' => 'UN', 'orden' => 18],
            ['etiqueta' => 'Visor 40mm (institucional)',     'formula' => '1',                                     'condicion' => 'tipo_puerta == "INSTITUCIONAL" or tipo_puerta == "EMERGENCIA"',                   'unidad' => 'UN', 'orden' => 19],
            ['etiqueta' => 'Tornillos',                      'formula' => '1',                                     'condicion' => null,                                                                             'unidad' => 'UN', 'orden' => 20],
            ['etiqueta' => 'Poliuretano',                    'formula' => '1',                                     'condicion' => null,                                                                             'unidad' => 'UN', 'orden' => 21],
            ['etiqueta' => 'Cinta, silicona, superbonder',   'formula' => '1',                                     'condicion' => null,                                                                             'unidad' => 'UN', 'orden' => 22],
            ['etiqueta' => 'Apoyo y gancho',                 'formula' => '1',                                     'condicion' => null,                                                                             'unidad' => 'UN', 'orden' => 23],
            ['etiqueta' => 'Mano de obra',                   'formula' => '1',                                     'condicion' => null,                                                                             'unidad' => 'UN', 'orden' => 24],
            ['etiqueta' => 'Limpieza y empaque',             'formula' => '1',                                     'condicion' => null,                                                                             'unidad' => 'UN', 'orden' => 25],
            ['etiqueta' => 'Instalaciones',                  'formula' => '1',                                     'condicion' => null,                                                                             'unidad' => 'UN', 'orden' => 26],
            ['etiqueta' => 'Portaresistencia perimetral',    'formula' => '2 * alto_hoja + 2 * ancho_hoja',        'condicion' => 'temperatura == "BAJA"',                                                          'unidad' => 'ML', 'orden' => 27],
        ];

        foreach ($comps as $c) {
            $p->componentes()->create(array_merge(['producto_id' => null, 'activo' => true], $c));
        }
    }
}
