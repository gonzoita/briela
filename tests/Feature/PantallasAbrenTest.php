<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las pantallas abren.
 *
 * Una pantalla que revienta en el servidor y una que revienta en el navegador se ven igual
 * desde afuera —en negro— y se arreglan distinto. Esta prueba separa las dos: si aquí pasa,
 * el problema es del navegador.
 */
class PantallasAbrenTest extends TestCase
{
    use RefreshDatabase;

    public static function pantallas(): array
    {
        return [
            'cotizaciones'  => ['/cotizaciones'],
            'alistamiento'  => ['/produccion/alistamiento'],
            'comisiones'    => ['/comisiones'],
            'liquidaciones' => ['/comisiones/liquidaciones'],
            'ops'           => ['/produccion/ops'],
            'ensamble nuevo'=> ['/ensambles/crear'],
            'agentes'       => ['/configuracion/agentes'],
            'plantillas'    => ['/cotizadores/plantillas'],
            'cartera'       => ['/financiero/cartera'],
            'remision nueva'=> ['/logistica/remisiones/crear'],
            'productos'     => ['/productos'],
            'ensambles'     => ['/ensambles'],
            'clientes'      => ['/clientes'],
            'dashboard'     => ['/dashboard'],
        ];
    }

    public function test_las_pantallas_abren(): void
    {
        $admin = User::factory()->create(['rol' => 'administrador']);
        $fallos = [];

        foreach (static::pantallas() as $nombre => [$ruta]) {
            $r = $this->actingAs($admin)->get($ruta);

            if ($r->status() !== 200) {
                $fallos[] = $nombre . ' (' . $ruta . ') → ' . $r->status()
                    . ($r->exception ? ': ' . $r->exception->getMessage() : '');
            }
        }

        $this->assertSame([], $fallos, "Pantallas que no abren:
" . implode("
", $fallos));
    }
}
