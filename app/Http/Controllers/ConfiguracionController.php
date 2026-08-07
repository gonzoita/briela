<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\EstacionTrabajo;
use App\Models\NivelColaborador;
use App\Models\TipoColaborador;
use App\Services\SmtpConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfiguracionController extends Controller
{
    public function index(): Response
    {
        // Catálogo de notificaciones + si cada una está activa (por defecto sí,
        // salvo que exista la clave notif_{tipo} = '0').
        $notificaciones = collect(\App\Services\NotificacionService::catalogo())
            ->map(fn ($items) => collect($items)->map(fn ($n) => [
                'tipo'   => $n['tipo'],
                'label'  => $n['label'],
                'activa' => Configuracion::get("notif_{$n['tipo']}", '1') !== '0',
                'email'  => Configuracion::get("notif_{$n['tipo']}_email", '0') === '1',
            ])->values())
            ->toArray();

        return Inertia::render('Configuracion/Index', [
            'configuraciones'  => Configuracion::orderBy('grupo')->orderBy('clave')->get(),
            'tiposColaborador' => TipoColaborador::orderBy('orden')->get(),
            // Estaciones de la sede activa: son máquinas físicas de una fábrica.
            'estaciones'       => \App\Support\ContextoSede::aplicar(EstacionTrabajo::query())
                                    ->with(['equipos:id,estacion_trabajo_id,nombre,estado'])
                                    ->orderBy('orden')
                                    ->get(),
            'niveles'          => NivelColaborador::orderBy('orden')->get(),
            'notificaciones'   => $notificaciones,
        ]);
    }

    public function saveNotificaciones(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'notificaciones'          => 'required|array',
            'notificaciones.*.tipo'   => 'required|string',
            'notificaciones.*.activa' => 'required|boolean',
            'notificaciones.*.email'  => 'nullable|boolean',
        ]);

        foreach ($data['notificaciones'] as $n) {
            Configuracion::set("notif_{$n['tipo']}", $n['activa'] ? '1' : '0');
            Configuracion::set("notif_{$n['tipo']}_email", ! empty($n['email']) ? '1' : '0');
        }

        return back()->with('success', 'Notificaciones actualizadas.');
    }

    public function saveConfiguraciones(Request $request): RedirectResponse
    {
        foreach ($request->configuraciones as $clave => $valor) {
            Configuracion::set($clave, $valor);
        }
        return back()->with('success', 'Configuración guardada.');
    }

    public function storeTipoColaborador(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100|unique:tipos_colaborador,nombre',
            'color'  => 'required|string|max:7',
            'activo' => 'boolean',
            'orden'  => 'integer|min:0',
        ]);
        TipoColaborador::create($data);
        return back()->with('success', 'Tipo de colaborador creado.');
    }

    public function updateTipoColaborador(Request $request, TipoColaborador $tipo): RedirectResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100|unique:tipos_colaborador,nombre,' . $tipo->id,
            'color'  => 'required|string|max:7',
            'activo' => 'boolean',
            'orden'  => 'integer|min:0',
        ]);
        $tipo->update($data);
        return back()->with('success', 'Tipo de colaborador actualizado.');
    }

    public function destroyTipoColaborador(TipoColaborador $tipo): RedirectResponse
    {
        $tipo->delete();
        return back()->with('success', 'Tipo de colaborador eliminado.');
    }

    public function reordenarTiposColaborador(Request $request): JsonResponse
    {
        foreach ($request->orden as $item) {
            TipoColaborador::where('id', $item['id'])->update(['orden' => $item['orden']]);
        }
        return response()->json(['ok' => true]);
    }

    public function storeEstacion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nombre'               => 'required|string|max:150',
            'descripcion'          => 'nullable|string',
            'color'                => 'required|string|max:7',
            'capacidad_simultanea' => 'required|integer|min:1',
            'activa'               => 'boolean',
            'orden'                => 'integer|min:0',
        ]);
        EstacionTrabajo::create($data);
        return back()->with('success', 'Estación de trabajo creada.');
    }

    public function updateEstacion(Request $request, EstacionTrabajo $estacion): RedirectResponse
    {
        $data = $request->validate([
            'nombre'               => 'required|string|max:150',
            'descripcion'          => 'nullable|string',
            'color'                => 'required|string|max:7',
            'capacidad_simultanea' => 'required|integer|min:1',
            'activa'               => 'boolean',
            'orden'                => 'integer|min:0',
        ]);
        $estacion->update($data);
        return back()->with('success', 'Estación de trabajo actualizada.');
    }

    public function destroyEstacion(EstacionTrabajo $estacion): RedirectResponse
    {
        $estacion->delete();
        return back()->with('success', 'Estación de trabajo eliminada.');
    }

    public function reordenarEstaciones(Request $request): JsonResponse
    {
        foreach ($request->orden as $item) {
            EstacionTrabajo::where('id', $item['id'])->update(['orden' => $item['orden']]);
        }
        return response()->json(['ok' => true]);
    }

    public function storeNivel(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nombre'         => 'required|string|max:100',
            'color'          => 'required|string|max:7',
            'icono'          => 'nullable|string|max:10',
            'puntos_minimos' => 'required|integer|min:0',
            'puntos_maximos' => 'nullable|integer|min:0',
            'orden'          => 'integer|min:0',
        ]);
        NivelColaborador::create($data);
        return back()->with('success', 'Nivel creado.');
    }

    public function updateNivel(Request $request, NivelColaborador $nivel): RedirectResponse
    {
        $data = $request->validate([
            'nombre'         => 'required|string|max:100',
            'color'          => 'required|string|max:7',
            'icono'          => 'nullable|string|max:10',
            'puntos_minimos' => 'required|integer|min:0',
            'puntos_maximos' => 'nullable|integer|min:0',
            'orden'          => 'integer|min:0',
        ]);
        $nivel->update($data);
        return back()->with('success', 'Nivel actualizado.');
    }

    public function destroyNivel(NivelColaborador $nivel): RedirectResponse
    {
        $nivel->delete();
        return back()->with('success', 'Nivel eliminado.');
    }

    public function probarSmtp(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        $resultado = SmtpConfigService::probar($request->email);
        return response()->json($resultado, $resultado['ok'] ? 200 : 422);
    }

    public function subirLogoEmpresa(Request $request): JsonResponse
    {
        $request->validate(['logo' => 'required|image|mimes:png,jpg,jpeg,svg|max:2048']);
        $resultado = \App\Services\ArchivoServidorService::subir($request->file('logo'), 'empresa');
        Configuracion::set('empresa_logo_url', $resultado['url']);
        return response()->json(['url' => $resultado['url']]);
    }
}
