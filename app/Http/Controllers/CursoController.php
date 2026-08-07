<?php

namespace App\Http\Controllers;

use App\Models\Curso;
use App\Models\CursoLeccion;
use App\Services\ArchivoServidorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CursoController extends Controller
{
    public function index(): Response
    {
        $leccionesPorCurso = CursoLeccion::join('curso_modulos', 'curso_modulos.id', '=', 'curso_lecciones.curso_modulo_id')
            ->selectRaw('curso_modulos.curso_id, count(*) as total')
            ->groupBy('curso_modulos.curso_id')
            ->pluck('total', 'curso_id');

        $cursos = Curso::withCount('modulos')
            ->latest()
            ->get()
            ->map(fn ($curso) => array_merge($curso->toArray(), [
                'lecciones_count' => (int) ($leccionesPorCurso[$curso->id] ?? 0),
            ]));

        return Inertia::render('Capacitacion/Cursos/Index', [
            'cursos' => $cursos,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Capacitacion/Cursos/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->reglas());

        $curso = Curso::create([
            'titulo'            => $data['titulo'],
            'descripcion'       => $data['descripcion'] ?? null,
            'categoria'         => $data['categoria'] ?? null,
            'publico_objetivo'  => $data['publico_objetivo'],
            'obligatorio'       => (bool) $request->boolean('obligatorio'),
            'activo'            => true,
            'imagen_portada'    => $this->subirImagenPortada($request),
            'puntos_otorga'     => $data['publico_objetivo'] === 'colaborador' ? ($data['puntos_otorga'] ?? 0) : 0,
        ]);

        return redirect("/capacitacion/cursos/{$curso->id}")->with('success', 'Curso creado correctamente.');
    }

    public function show(Curso $curso): Response
    {
        $curso->load(['modulos.lecciones', 'modulos.evaluacion.preguntas.opciones', 'evaluacion.preguntas.opciones']);

        return Inertia::render('Capacitacion/Cursos/Show', [
            'curso' => $curso,
        ]);
    }

    public function edit(Curso $curso): Response
    {
        return Inertia::render('Capacitacion/Cursos/Edit', [
            'curso' => $curso,
        ]);
    }

    public function update(Request $request, Curso $curso): RedirectResponse
    {
        $data = $request->validate($this->reglas());

        $curso->update([
            'titulo'           => $data['titulo'],
            'descripcion'      => $data['descripcion'] ?? null,
            'categoria'        => $data['categoria'] ?? null,
            'publico_objetivo' => $data['publico_objetivo'],
            'obligatorio'      => (bool) $request->boolean('obligatorio'),
            'imagen_portada'   => $this->subirImagenPortada($request) ?? $curso->imagen_portada,
            'puntos_otorga'    => $data['publico_objetivo'] === 'colaborador' ? ($data['puntos_otorga'] ?? 0) : 0,
        ]);

        return redirect("/capacitacion/cursos/{$curso->id}")->with('success', 'Curso actualizado correctamente.');
    }

    public function destroy(Curso $curso): RedirectResponse
    {
        $curso->delete();

        return redirect('/capacitacion/cursos')->with('success', 'Curso eliminado.');
    }

    public function toggleActivo(Curso $curso): RedirectResponse
    {
        $curso->update(['activo' => ! $curso->activo]);

        return back()->with('success', $curso->activo ? 'Curso activado.' : 'Curso desactivado.');
    }

    private function reglas(): array
    {
        return [
            'titulo'           => 'required|string|max:200',
            'descripcion'      => 'nullable|string',
            'categoria'        => 'nullable|string|max:100',
            'publico_objetivo' => 'required|in:colaborador,contratista,cliente,todos',
            'obligatorio'      => 'nullable|boolean',
            'imagen_portada'   => 'nullable|image|max:5120',
            'puntos_otorga'    => 'nullable|integer|min:0',
        ];
    }

    private function subirImagenPortada(Request $request): ?string
    {
        if (! $request->hasFile('imagen_portada')) return null;

        return ArchivoServidorService::subir($request->file('imagen_portada'), 'cursos')['url'];
    }
}
