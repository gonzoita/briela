<?php

namespace App\Http\Controllers;

use App\Models\AgenteIa;
use App\Services\IA\ConsultasClienteService;
use App\Services\IA\ConsultasPublicasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los agentes que atienden por fuera.
 *
 * Lo que se configura aquí no es «un chatbot»: es a quién atiende cada agente y qué puede ver.
 * El campo `perfil` decide el catálogo de consultas, y por eso las herramientas que ofrece la
 * pantalla cambian con él — un agente público no puede siquiera elegir «cartera del cliente».
 */
class AgenteIaController extends Controller
{
    public function index(ConsultasPublicasService $publicas, ConsultasClienteService $deCliente): Response
    {
        return Inertia::render('Agentes/Index', [
            'agentes' => AgenteIa::orderBy('orden')->orderBy('id')->get()
                ->map(fn ($a) => [
                    'id'            => $a->id,
                    'nombre'        => $a->nombre,
                    'descripcion'   => $a->descripcion,
                    'activo'        => $a->activo,
                    'perfil'        => $a->perfil,
                    'canales'       => $a->canales ?? [],
                    'herramientas'  => $a->herramientas ?? [],
                    'instrucciones' => $a->instrucciones,
                    'saludo'        => $a->saludo,
                    'escalamiento'  => $a->escalamiento ?? [],
                    'horario'       => $a->horario ?? ['desde' => '', 'hasta' => ''],
                ]),
            // Qué puede consultar cada perfil. La pantalla las ofrece; el servidor las valida
            // contra estas mismas listas.
            'herramientasPorPerfil' => [
                'publico' => collect($publicas->disponibles())->map(fn ($d, $k) => ['clave' => $k, 'label' => $d['descripcion']])->values(),
                'cliente' => collect($deCliente->disponibles())->map(fn ($d, $k) => ['clave' => $k, 'label' => $d['descripcion']])->values(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        AgenteIa::create($this->validar($request) + [
            'creado_por' => auth()->id(),
            'orden'      => (int) AgenteIa::max('orden') + 1,
        ]);

        return back()->with('success', 'Agente creado.');
    }

    public function update(Request $request, AgenteIa $agente): RedirectResponse
    {
        $agente->update($this->validar($request));

        return back()->with('success', 'Agente actualizado.');
    }

    public function destroy(AgenteIa $agente): RedirectResponse
    {
        $agente->delete();

        return back()->with('success', 'Agente eliminado.');
    }

    /**
     * Valida contra los catálogos, no contra una lista escrita a mano.
     *
     * Una herramienta que no exista en el perfil elegido se descarta en silencio: no es un error
     * del usuario, es una clave que el catálogo de ese perfil no reconoce. Y es la línea que
     * impide que un agente público termine con permiso de leer cartera.
     */
    private function validar(Request $request): array
    {
        $datos = $request->validate([
            'nombre'          => 'required|string|max:120',
            'descripcion'     => 'nullable|string|max:300',
            'activo'          => 'boolean',
            'perfil'          => 'required|in:publico,cliente',
            'canales'         => 'nullable|array',
            'canales.*'       => 'in:web,whatsapp',
            'herramientas'    => 'nullable|array',
            'herramientas.*'  => 'string|max:40',
            'instrucciones'   => 'nullable|string|max:8000',
            'saludo'          => 'nullable|string|max:500',
            'escalamiento'    => 'nullable|array',
            'escalamiento.*'  => 'in:lo_pide,no_sabe,fuera_horario,asesor_asignado',
            'horario'         => 'nullable|array',
            'horario.desde'   => 'nullable|date_format:H:i',
            'horario.hasta'   => 'nullable|date_format:H:i',
        ]);

        $permitidas = $datos['perfil'] === 'cliente'
            ? array_keys(app(ConsultasClienteService::class)->disponibles())
            : array_keys(app(ConsultasPublicasService::class)->disponibles());

        $datos['herramientas'] = array_values(array_intersect($datos['herramientas'] ?? [], $permitidas));

        return $datos;
    }
}
