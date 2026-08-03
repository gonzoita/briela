<?php

namespace App\Http\Controllers;

use App\Models\CursoLeccion;
use App\Models\CursoModulo;
use App\Services\GoogleDriveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CursoLeccionController extends Controller
{
    public function store(Request $request, CursoModulo $modulo): JsonResponse
    {
        $data = $request->validate($this->reglas(esNuevo: true));

        $leccion = CursoLeccion::create([
            'curso_modulo_id'  => $modulo->id,
            'nombre'           => $data['nombre'],
            'tipo'             => $data['tipo'],
            'contenido'        => $this->resolverContenido($request, $data['tipo']),
            'duracion_minutos' => $data['duracion_minutos'] ?? null,
            'orden'            => ($modulo->lecciones()->max('orden') ?? 0) + 1,
        ]);

        return response()->json($leccion, 201);
    }

    public function update(Request $request, CursoModulo $modulo, CursoLeccion $leccion): JsonResponse
    {
        abort_if($leccion->curso_modulo_id !== $modulo->id, 404);

        $data = $request->validate($this->reglas(esNuevo: false));

        $leccion->update([
            'nombre'           => $data['nombre'],
            'tipo'             => $data['tipo'],
            'contenido'        => $this->resolverContenido($request, $data['tipo']) ?? $leccion->contenido,
            'duracion_minutos' => $data['duracion_minutos'] ?? null,
        ]);

        return response()->json($leccion);
    }

    public function destroy(CursoModulo $modulo, CursoLeccion $leccion): JsonResponse
    {
        abort_if($leccion->curso_modulo_id !== $modulo->id, 404);

        $leccion->delete();

        return response()->json(['ok' => true]);
    }

    public function reordenar(Request $request, CursoModulo $modulo): JsonResponse
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer',
        ]);

        foreach ($request->ids as $orden => $id) {
            CursoLeccion::where('id', $id)
                ->where('curso_modulo_id', $modulo->id)
                ->update(['orden' => $orden]);
        }

        return response()->json(['ok' => true]);
    }

    private function reglas(bool $esNuevo): array
    {
        return [
            'nombre'            => 'required|string|max:150',
            'tipo'              => 'required|in:video_drive,video_externo,texto,pdf',
            'contenido_url'     => 'required_if:tipo,video_drive,video_externo|nullable|string|max:2000',
            'contenido_texto'   => 'required_if:tipo,texto|nullable|string',
            'archivo_pdf'       => ($esNuevo ? 'required_if:tipo,pdf|' : '') . 'nullable|file|mimes:pdf|max:20480',
            'duracion_minutos'  => 'nullable|integer|min:0',
        ];
    }

    private function resolverContenido(Request $request, string $tipo): ?string
    {
        return match ($tipo) {
            'video_drive', 'video_externo' => $request->input('contenido_url'),
            'texto' => $request->input('contenido_texto'),
            'pdf'   => $request->hasFile('archivo_pdf')
                ? GoogleDriveService::upload($request->file('archivo_pdf'), 'cursos/lecciones')['url']
                : null,
        };
    }
}
