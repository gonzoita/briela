<?php

namespace Database\Seeders;

use App\Models\CategoriaProducto;
use App\Models\EnsambleItem;
use App\Models\Producto;
use Illuminate\Database\Seeder;

class ProductoSeeder extends Seeder
{
    public function run(): void
    {
        // ── Categorías ────────────────────────────────────────────────────────
        $catPuertas   = CategoriaProducto::create(['nombre' => 'Puertas Refrigeradas', 'color' => '#0A4283']);
        $catPaneleria = CategoriaProducto::create(['nombre' => 'Panelería', 'color' => '#1a6bb5']);
        $catAccesorios= CategoriaProducto::create(['nombre' => 'Accesorios', 'color' => '#2980d4']);

        // ── Productos ─────────────────────────────────────────────────────────
        $puertaBatiente = Producto::create([
            'categoria_id'        => $catPuertas->id,
            'tipo'                => 'producto',
            'nombre'              => 'Puerta Batiente Frigorífica 90x200cm',
            'referencia'          => 'PROD-0001',
            'unidad_medida'       => 'unidad',
            'descripcion_corta'   => 'Puerta batiente para cuartos fríos, marco de aluminio anodizado.',
            'inventariable'       => true,
            'stock_minimo'        => 2,
            'stock_maximo'        => 20,
            'stock_almacen1'      => 5,
            'stock_almacen2'      => 2,
            'precio_costo'        => 850000,
            'precio_mayorista'    => 1100000,
            'precio_distribuidor' => 1250000,
            'precio_cliente_final'=> 1500000,
        ]);

        $panelPoliuretano = Producto::create([
            'categoria_id'        => $catPaneleria->id,
            'tipo'                => 'producto',
            'nombre'              => 'Panel Poliuretano 100mm — Cara Blanca',
            'referencia'          => 'PROD-0002',
            'unidad_medida'       => 'm2',
            'descripcion_corta'   => 'Panel sandwich de poliuretano inyectado, densidad 42kg/m3.',
            'inventariable'       => true,
            'stock_minimo'        => 10,
            'stock_maximo'        => 200,
            'stock_almacen1'      => 45,
            'stock_almacen3'      => 20,
            'precio_costo'        => 95000,
            'precio_mayorista'    => 120000,
            'precio_distribuidor' => 135000,
            'precio_cliente_final'=> 160000,
        ]);

        $bisagra = Producto::create([
            'categoria_id'        => $catAccesorios->id,
            'tipo'                => 'producto',
            'nombre'              => 'Bisagra Reforzada Inox para Puerta Frigorífica',
            'referencia'          => 'PROD-0003',
            'unidad_medida'       => 'unidad',
            'descripcion_corta'   => 'Bisagra de acero inoxidable 304, par incluido.',
            'inventariable'       => true,
            'stock_minimo'        => 5,
            'stock_maximo'        => 100,
            'stock_almacen1'      => 30,
            'precio_costo'        => 45000,
            'precio_mayorista'    => 60000,
            'precio_distribuidor' => 68000,
            'precio_cliente_final'=> 85000,
        ]);

        $manija = Producto::create([
            'categoria_id'        => $catAccesorios->id,
            'tipo'                => 'producto',
            'nombre'              => 'Manija Cromada con Cierre Hermético',
            'referencia'          => 'PROD-0004',
            'unidad_medida'       => 'unidad',
            'descripcion_corta'   => 'Manija exterior con sistema de cierre hermético integrado.',
            'inventariable'       => true,
            'stock_minimo'        => 3,
            'stock_maximo'        => 50,
            'stock_almacen1'      => 12,
            'precio_costo'        => 38000,
            'precio_mayorista'    => 52000,
            'precio_distribuidor' => 60000,
            'precio_cliente_final'=> 72000,
        ]);

        $perfilAluminio = Producto::create([
            'categoria_id'        => $catAccesorios->id,
            'tipo'                => 'producto',
            'nombre'              => 'Perfil Aluminio Marco Puerta 3m',
            'referencia'          => 'PROD-0005',
            'unidad_medida'       => 'metros',
            'descripcion_corta'   => 'Perfil de aluminio anodizado plata para marcos de puertas.',
            'inventariable'       => true,
            'stock_minimo'        => 10,
            'stock_maximo'        => 80,
            'stock_almacen2'      => 35,
            'precio_costo'        => 28000,
            'precio_mayorista'    => 38000,
            'precio_distribuidor' => 44000,
            'precio_cliente_final'=> 55000,
        ]);

        // ── Servicios ─────────────────────────────────────────────────────────
        Producto::create([
            'categoria_id'        => $catPuertas->id,
            'tipo'                => 'servicio',
            'nombre'              => 'Instalación de Puerta Frigorífica',
            'referencia'          => 'SERV-0001',
            'unidad_medida'       => 'instalacion',
            'descripcion_corta'   => 'Servicio de instalación completa de puerta, incluye nivelación y sellado.',
            'precio_costo'        => 180000,
            'precio_mayorista'    => 250000,
            'precio_distribuidor' => 280000,
            'precio_cliente_final'=> 350000,
        ]);

        Producto::create([
            'categoria_id'        => $catPaneleria->id,
            'tipo'                => 'servicio',
            'nombre'              => 'Montaje de Panelería por m²',
            'referencia'          => 'SERV-0002',
            'unidad_medida'       => 'm2',
            'descripcion_corta'   => 'Instalación de paneles sandwich por metro cuadrado, incluye sellado de juntas.',
            'precio_costo'        => 35000,
            'precio_mayorista'    => 50000,
            'precio_distribuidor' => 58000,
            'precio_cliente_final'=> 70000,
        ]);

        Producto::create([
            'categoria_id'        => null,
            'tipo'                => 'servicio',
            'nombre'              => 'Mantenimiento Preventivo Cuarto Frío',
            'referencia'          => 'SERV-0003',
            'unidad_medida'       => 'dia',
            'descripcion_corta'   => 'Revisión general, limpieza de sellos, lubricación y reporte de estado.',
            'precio_costo'        => 120000,
            'precio_mayorista'    => 180000,
            'precio_distribuidor' => 200000,
            'precio_cliente_final'=> 250000,
        ]);

        // ── Ensamble ──────────────────────────────────────────────────────────
        $ensamble = Producto::create([
            'categoria_id'    => $catPuertas->id,
            'tipo'            => 'ensamble',
            'nombre'          => 'Kit Puerta Frigorífica Batiente Completa 90x200cm',
            'referencia'      => 'ENS-0001',
            'unidad_medida'   => 'unidad',
            'descripcion_corta' => 'Kit completo incluye puerta, bisagras, manija y perfil de marco.',
            'precio_costo'    => 0,
            'precio_mayorista'=> 1400000,
            'precio_distribuidor'=> 1600000,
        ]);

        $items = [
            [$puertaBatiente,   1, $puertaBatiente->precio_costo],
            [$bisagra,          1, $bisagra->precio_costo],
            [$manija,           1, $manija->precio_costo],
            [$perfilAluminio,   2, $perfilAluminio->precio_costo],
        ];

        foreach ($items as [$comp, $cant, $precio]) {
            EnsambleItem::create([
                'ensamble_id'           => $ensamble->id,
                'componente_id'         => $comp->id,
                'cantidad'              => $cant,
                'precio_costo_snapshot' => $precio,
            ]);
        }

        $ensamble->recalcularPrecioEnsamble();
    }
}
