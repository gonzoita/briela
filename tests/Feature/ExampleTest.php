<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La raíz nunca muestra contenido: siempre reparte según haya sesión o no.
     * El test que venía de fábrica esperaba un 200 y por eso fallaba — nunca
     * se adaptó a esta aplicación.
     */
    public function test_la_raiz_manda_al_login_si_no_hay_sesion(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_la_raiz_manda_al_dashboard_si_hay_sesion(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect('/dashboard');
    }
}
