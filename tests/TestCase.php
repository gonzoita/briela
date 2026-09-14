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
}
