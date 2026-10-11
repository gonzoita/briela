<?php

namespace Tests\Unit;

use App\Support\Marca;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El color de la marca se tiene que leer en los dos temas.
 *
 * Un azul marino sobre el gris del modo noche quedaba en 1,3:1: los números de las
 * cotizaciones, el menú activo y los botones con borde desaparecían. Ahora cada tema pinta
 * el mismo tono con la luz justa para leerse, y si el color ya se lee, sale tal cual.
 */
class ColorMarcaLegibleTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function colores(): array
    {
        return [
            'azul marino'     => ['#1E2A5A'],
            'casi negro'      => ['#0B1F3A'],
            'azul de fábrica' => ['#2563EB'],
            'naranja'         => ['#F97316'],
            'amarillo'        => ['#FACC15'],
            'negro'           => ['#000000'],
            'blanco'          => ['#FFFFFF'],
        ];
    }

    #[DataProvider('colores')]
    public function test_se_lee_sobre_la_superficie_de_cada_tema(string $color): void
    {
        foreach (Marca::CONTRASTE_MINIMO as $esquema => $minimo) {
            $pintado    = Marca::colorLegible($color, $esquema);
            $superficie = Marca::esquemas()[$esquema]['superficie'];

            $this->assertGreaterThanOrEqual($minimo, Marca::contraste($pintado, $superficie), "{$color} en {$esquema}");
        }
    }

    public function test_un_color_que_ya_se_lee_no_se_toca(): void
    {
        $this->assertSame('#2563EB', Marca::colorLegible('#2563EB', 'claro'));
        $this->assertSame('#1E2A5A', Marca::colorLegible('#1E2A5A', 'claro'));
        $this->assertSame('#FACC15', Marca::colorLegible('#FACC15', 'oscuro'));
    }

    public function test_de_noche_se_aclara_sin_perder_el_tono(): void
    {
        // Sigue siendo azul: más azul que rojo y que verde. Mezclado con blanco quedaba gris.
        [$r, $g, $b] = sscanf(Marca::colorLegible('#1E2A5A', 'oscuro'), '#%02X%02X%02X');

        $this->assertGreaterThan($r + 40, $b);
        $this->assertGreaterThan($g + 40, $b);
    }

    public function test_de_noche_los_fondos_suaves_son_oscuros(): void
    {
        $suave = Marca::paleta('#1E2A5A', 'oscuro')['marca-suave'];

        // Un tinte sobre la superficie, no una caja clara con texto claro encima.
        $this->assertLessThan(0.05, Marca::luminancia($suave));
    }
}
