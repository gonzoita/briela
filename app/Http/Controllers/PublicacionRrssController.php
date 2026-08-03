<?php

namespace App\Http\Controllers;

use App\Models\CuentaRrss;
use App\Models\PublicacionRrss;
use App\Services\Rrss\RrssPublicadorService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PublicacionRrssController extends Controller
{
    public function index(Request $request)
    {
        $query = PublicacionRrss::with(['cuentasDestino.cuenta', 'archivos', 'creadoPor:id,name'])
            ->orderByDesc('fecha_programada');

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $publicaciones = $query->paginate(20)->withQueryString();

        return Inertia::render('Rrss/Index', [
            'publicaciones' => $publicaciones->through(fn (PublicacionRrss $p) => $this->serializar($p)),
            'filtros'       => $request->only(['estado']),
            'cuentasActivas' => CuentaRrss::activas()->count(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Rrss/Crear', [
            'cuentas' => CuentaRrss::activas()->orderBy('red')->get(['id', 'red', 'nombre_cuenta']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validarDatos($request);

        $publicacion = PublicacionRrss::create([
            'contenido'        => $data['contenido'],
            'fecha_programada' => $data['fecha_programada'],
            'estado'           => $data['accion'] === 'programar' ? 'programada' : 'borrador',
            'creado_por'       => auth()->id(),
        ]);

        foreach ($data['cuentas'] as $cuentaId) {
            $publicacion->cuentasDestino()->create(['cuenta_rrss_id' => $cuentaId]);
        }

        if ($request->hasFile('imagen')) {
            $this->guardarImagen($publicacion, $request->file('imagen'));
        }

        return redirect()->route('rrss.index')->with('success',
            $data['accion'] === 'programar' ? 'Publicación programada.' : 'Borrador guardado.'
        );
    }

    public function edit(PublicacionRrss $rr)
    {
        $rr->load(['cuentasDestino.cuenta', 'archivos', 'creadoPor:id,name']);

        return Inertia::render('Rrss/Editar', [
            'publicacion' => $this->serializar($rr),
            'cuentas'     => CuentaRrss::activas()->orderBy('red')->get(['id', 'red', 'nombre_cuenta']),
        ]);
    }

    public function update(Request $request, PublicacionRrss $rr)
    {
        if (in_array($rr->estado, ['publicada', 'publicando'])) {
            return back()->with('error', 'Esta publicación ya se procesó y no se puede editar.');
        }

        $data = $this->validarDatos($request);

        $rr->update([
            'contenido'        => $data['contenido'],
            'fecha_programada' => $data['fecha_programada'],
            'estado'           => $data['accion'] === 'programar' ? 'programada' : 'borrador',
        ]);

        $rr->cuentasDestino()->delete();
        foreach ($data['cuentas'] as $cuentaId) {
            $rr->cuentasDestino()->create(['cuenta_rrss_id' => $cuentaId]);
        }

        if ($request->hasFile('imagen')) {
            $rr->archivos()->delete();
            $this->guardarImagen($rr, $request->file('imagen'));
        }

        return redirect()->route('rrss.index')->with('success', 'Publicación actualizada.');
    }

    public function destroy(PublicacionRrss $rr)
    {
        if ($rr->estado === 'publicando') {
            return back()->with('error', 'No se puede eliminar mientras se está publicando.');
        }

        $rr->delete();

        return back()->with('success', 'Publicación eliminada.');
    }

    /**
     * Publica de inmediato, sin esperar al programador (comando que corre
     * cada minuto).
     */
    public function publicarAhora(PublicacionRrss $rr, RrssPublicadorService $publicador)
    {
        if (!in_array($rr->estado, ['borrador', 'programada', 'fallida', 'parcial'])) {
            return back()->with('error', 'Esta publicación no está en un estado válido para publicar.');
        }

        // Reintenta solo las cuentas que quedaron pendientes/fallidas.
        $rr->cuentasDestino()->where('estado', 'fallida')->update(['estado' => 'pendiente', 'error' => null]);

        try {
            $publicador->publicar($rr->fresh());
        } catch (\Throwable $e) {
            return back()->with('error', 'Error publicando: ' . $e->getMessage());
        }

        return back()->with('success', 'Publicación procesada. Revisa el estado por cuenta.');
    }

    private function validarDatos(Request $request): array
    {
        return $request->validate([
            'contenido'        => 'required|string|max:3000',
            'fecha_programada' => 'required|date',
            'cuentas'          => 'required|array|min:1',
            'cuentas.*'        => 'exists:cuentas_rrss,id',
            'accion'           => 'required|in:borrador,programar',
        ]);
    }

    private function guardarImagen(PublicacionRrss $publicacion, $file): void
    {
        $ext    = strtolower($file->getClientOriginalExtension());
        $nombre = Str::uuid() . '.' . $ext;
        $ruta   = $file->storeAs('rrss', $nombre, 'public');

        $publicacion->archivos()->create([
            'nombre_original' => $file->getClientOriginalName(),
            'nombre_archivo'  => $nombre,
            'ruta'            => $ruta,
            'storage'         => 'local',
            'tipo_mime'       => $file->getMimeType(),
            'extension'       => $ext,
            'tamano'          => $file->getSize(),
            'categoria'       => 'rrss',
            'subido_por'      => auth()->id(),
        ]);
    }

    private function serializar(PublicacionRrss $p): array
    {
        return [
            'id'               => $p->id,
            'contenido'        => $p->contenido,
            'fecha_programada' => $p->fecha_programada->format('Y-m-d H:i'),
            'estado'           => $p->estado,
            'creado_por'       => $p->creadoPor->name ?? '—',
            'imagen_url'       => $p->archivos->first()?->url,
            'cuentas'          => $p->cuentasDestino->map(fn ($d) => [
                'id'          => $d->id,
                'cuenta_id'   => $d->cuenta_rrss_id,
                'red'         => $d->cuenta?->red,
                'nombre'      => $d->cuenta?->nombre_cuenta,
                'estado'      => $d->estado,
                'error'       => $d->error,
                'url'         => $d->url_publicacion,
            ]),
        ];
    }
}
