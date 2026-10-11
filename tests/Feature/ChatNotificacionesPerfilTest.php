<?php

namespace Tests\Feature;

use App\Models\ChatGrupo;
use App\Models\Cliente;
use App\Models\Comentario;
use App\Models\Cotizacion;
use App\Models\Notificacion;
use App\Models\PerfilMarca;
use App\Models\User;
use App\Services\IA\IaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El chat en vivo, sus notificaciones, los avisos de las cotizaciones, el perfil del usuario y
 * el perfil de marca que la IA completa sola.
 */
class ChatNotificacionesPerfilTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;
    private User $beto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana  = User::factory()->create(['rol' => 'administrador', 'name' => 'Ana']);
        $this->beto = User::factory()->create(['rol' => 'administrador', 'name' => 'Beto']);
        $this->actingAs($this->ana);
    }

    // ─── Chat ────────────────────────────────────────────────────────────────

    public function test_la_conversacion_abierta_trae_solo_lo_nuevo(): void
    {
        $primero = $this->postJson("/api/chat/{$this->beto->id}", ['contenido' => 'Hola', 'tipo' => 'comentario'])->json('mensaje.id');

        $this->actingAs($this->beto)->postJson("/api/chat/{$this->ana->id}", ['contenido' => '¿Qué más?', 'tipo' => 'comentario']);

        $nuevos = $this->actingAs($this->ana)->getJson("/api/chat/{$this->beto->id}?desde={$primero}")->json('mensajes');

        $this->assertCount(1, $nuevos);
        $this->assertSame('¿Qué más?', $nuevos[0]['contenido']);
    }

    public function test_responder_a_un_mensaje_lo_cita(): void
    {
        $original = $this->actingAs($this->beto)->postJson("/api/chat/{$this->ana->id}", ['contenido' => '¿Ya salió la remisión?', 'tipo' => 'comentario'])->json('mensaje.id');

        $respuesta = $this->actingAs($this->ana)->postJson("/api/chat/{$this->beto->id}", [
            'contenido' => 'Sí, esta mañana.', 'tipo' => 'comentario', 'respuesta_a_id' => $original,
        ])->assertCreated()->json('mensaje.respuesta');

        $this->assertSame($original, $respuesta['id']);
        $this->assertSame('Beto', $respuesta['autor']);
        $this->assertSame('¿Ya salió la remisión?', $respuesta['extracto']);
    }

    public function test_no_se_responde_a_un_mensaje_de_otra_conversacion(): void
    {
        $carlos = User::factory()->create(['rol' => 'administrador']);
        $ajeno  = $this->actingAs($carlos)->postJson("/api/chat/{$this->beto->id}", ['contenido' => 'Privado', 'tipo' => 'comentario'])->json('mensaje.id');

        $this->actingAs($this->ana)->postJson("/api/chat/{$this->beto->id}", [
            'contenido' => 'Hola', 'tipo' => 'comentario', 'respuesta_a_id' => $ajeno,
        ])->assertStatus(422);
    }

    public function test_una_imagen_pegada_se_manda_sin_texto(): void
    {
        $this->postJson("/api/chat/{$this->beto->id}", [
            'tipo' => 'comentario',
            'archivos' => [['nombre' => 'captura.png', 'ruta' => 'chat/captura.png', 'mime' => 'image/png', 'extension' => 'png', 'tamano' => 100]],
        ])->assertCreated();

        $this->assertSame(1, Comentario::where('destinatario_id', $this->beto->id)->count());
    }

    public function test_la_notificacion_del_chat_abre_la_conversacion_y_no_da_404(): void
    {
        $this->postJson("/api/chat/{$this->beto->id}", ['contenido' => 'Hola', 'tipo' => 'comentario']);

        $url = Notificacion::where('user_id', $this->beto->id)->value('url');
        $this->assertSame("/chat/{$this->ana->id}", $url);

        $this->actingAs($this->beto)->get($url)->assertRedirect("/dashboard?abrir_chat=u{$this->ana->id}");
    }

    public function test_el_grupo_avisa_con_un_enlace_al_grupo(): void
    {
        $grupo = $this->postJson('/api/chat/grupos', ['nombre' => 'Producción', 'miembros' => [$this->beto->id]])->json('grupo.id');

        $this->postJson("/api/chat/grupos/{$grupo}", ['contenido' => 'Arrancamos', 'tipo' => 'comentario'])->assertCreated();

        $this->assertSame("/chat/grupos/{$grupo}", Notificacion::where('user_id', $this->beto->id)->latest('id')->value('url'));
        $this->actingAs($this->beto)->get("/chat/grupos/{$grupo}")->assertRedirect("/dashboard?abrir_chat=g{$grupo}");
    }

    // ─── Avisos en vivo ──────────────────────────────────────────────────────

    public function test_el_pulso_cambia_cuando_llega_algo(): void
    {
        $antes = $this->actingAs($this->beto)->getJson('/api/avisos/pulso')->json('firma');

        $this->actingAs($this->ana)->postJson("/api/chat/{$this->beto->id}", ['contenido' => 'Hola', 'tipo' => 'comentario']);

        $despues = $this->actingAs($this->beto)->getJson('/api/avisos/pulso')->json('firma');
        $this->assertNotSame($antes, $despues);
    }

    public function test_el_responsable_se_entera_de_un_cambio_de_estado_que_no_hizo(): void
    {
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'Frío Andino', 'tipo_identificacion' => 'NIT']);
        $cot = Cotizacion::create(['cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'enviada', 'responsable_id' => $this->beto->id]);

        $this->post("/cotizaciones/{$cot->id}/estado", ['estado' => 'aprobada']);

        $aviso = Notificacion::where('user_id', $this->beto->id)->where('tipo', 'cotizacion_aprobada')->firstOrFail();
        $this->assertSame("/cotizaciones/{$cot->id}", $aviso->url);
        $this->assertStringContainsString('Ana', $aviso->mensaje);
    }

    public function test_la_aprobacion_del_cliente_avisa_una_sola_vez(): void
    {
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'Frío Andino', 'tipo_identificacion' => 'NIT']);
        $cot = Cotizacion::create(['cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'enviada', 'responsable_id' => $this->beto->id]);

        auth()->logout();
        $this->post("/cotizaciones/{$cot->token_publico}/aprobar");

        $this->assertSame(1, Notificacion::where('user_id', $this->beto->id)->where('tipo', 'cotizacion_aprobada')->count());
    }

    public function test_quien_cambia_su_propia_cotizacion_no_se_avisa_a_si_mismo(): void
    {
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'Frío Andino', 'tipo_identificacion' => 'NIT']);
        $cot = Cotizacion::create(['cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'borrador', 'responsable_id' => $this->ana->id]);

        $this->post("/cotizaciones/{$cot->id}/estado", ['estado' => 'enviada']);

        $this->assertSame(0, Notificacion::where('user_id', $this->ana->id)->count());
    }

    // ─── Perfil ──────────────────────────────────────────────────────────────

    public function test_la_foto_de_perfil_se_cambia_y_se_quita(): void
    {
        Storage::fake('public');

        $this->post('/profile/foto', ['foto' => UploadedFile::fake()->image('yo.jpg', 200, 200)])->assertSessionHas('success');
        $ruta = $this->ana->fresh()->foto_path;
        Storage::disk('public')->assertExists($ruta);

        $this->delete('/profile/foto');
        $this->assertNull($this->ana->fresh()->foto_path);
        Storage::disk('public')->assertMissing($ruta);
    }

    public function test_la_firma_del_usuario_se_guarda_y_sale_en_la_cotizacion(): void
    {
        $firma = 'data:image/png;base64,iVBORw0KGgo=';
        $this->post('/profile/firma', ['firma' => $firma])->assertSessionHas('success');
        $this->assertSame($firma, $this->ana->fresh()->firma);

        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'Frío Andino', 'tipo_identificacion' => 'NIT']);
        $cot = Cotizacion::create(['cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'borrador', 'responsable_id' => $this->ana->id]);

        $datos = \App\Services\PdfVariablesEngine::prepararDatos('cotizacion', $cot->fresh());
        $this->assertStringContainsString($firma, $datos['vendedor.firma']);

        $this->post('/profile/firma', ['firma' => 'javascript:alert(1)'])->assertSessionHasErrors('firma');
        $this->assertSame($firma, $this->ana->fresh()->firma);
    }

    public function test_la_firma_no_viaja_en_las_respuestas_del_usuario(): void
    {
        $this->ana->update(['firma' => 'data:image/png;base64,QUJD']);

        $this->assertArrayNotHasKey('firma', $this->ana->fresh()->toArray());
    }

    // ─── Perfil de marca ─────────────────────────────────────────────────────

    public function test_la_ia_completa_sola_las_secciones_vacias_sin_pisar_las_escritas(): void
    {
        PerfilMarca::create(['seccion' => 'mision', 'contenido' => 'Nuestra misión, escrita a mano.']);

        $this->mock(IaService::class, function ($m) {
            $m->shouldReceive('texto')->once()->andReturn(json_encode([
                'proposito' => ['pregunta' => '¿Para qué existe una fábrica de puertas?', 'respuesta' => 'Hacemos que los espacios funcionen.'],
                'mision'    => ['pregunta' => '¿Cuál es la misión?', 'respuesta' => 'Esto no debería entrar.'],
            ]));
        });

        $r = $this->postJson('/api/perfil-marca/completar', [])->assertOk();

        $this->assertSame(1, $r->json('secciones'));
        $this->assertSame('¿Para qué existe una fábrica de puertas?', $r->json('resultado.0.pregunta'));
        $this->assertSame('Hacemos que los espacios funcionen.', PerfilMarca::where('seccion', 'proposito')->value('contenido'));
        $this->assertSame('Nuestra misión, escrita a mano.', PerfilMarca::where('seccion', 'mision')->value('contenido'));
        $this->assertNotNull(PerfilMarca::where('seccion', 'proposito')->value('generado_ia_at'));
    }
}
