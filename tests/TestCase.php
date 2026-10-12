<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Los módulos se recuerdan por petición en una variable estática, y todas las pruebas
        // corren en el mismo proceso: `RefreshDatabase` deshace la base, pero no esa memoria.
        // Sin esto, los módulos que apagó una prueba seguían apagados en la siguiente.
        \App\Support\Modulos::olvidar();
    }

    private ?\App\Models\Cliente $clientePrueba = null;

    /**
     * Un cliente para las órdenes y cotizaciones de prueba: desde el 11 oct 2026 ninguna se
     * procesa sin cliente, y casi ninguna prueba es sobre el cliente.
     */
    protected function clientePrueba(): \App\Models\Cliente
    {
        return $this->clientePrueba ??= \App\Models\Cliente::create([
            'tipo' => 'empresa', 'nombre' => 'Cliente de prueba', 'tipo_identificacion' => 'NIT',
            'numero_identificacion' => '900'.random_int(100000, 999999),
        ]);
    }
}
