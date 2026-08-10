<?php

namespace App\Http\Controllers;

use App\Models\SecuenciaDocumento;
use App\Models\Sede;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SedeController extends Controller
{
    public function index()
    {
        return Inertia::render('Configuracion/Sedes', [
            'sedes' => Sede::withCount(['bodegas', 'usuarios'])
                ->orderByDesc('es_principal')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Sede $s) => [
                    'id'               => $s->id,
                    'nombre'           => $s->nombre,
                    'codigo'           => $s->codigo,
                    'tiene_ventas'     => $s->tiene_ventas,
                    'tiene_produccion' => $s->tiene_produccion,
                    'es_principal'     => $s->es_principal,
                    'nit'              => $s->nit,
                    'direccion'        => $s->direccion,
                    'ciudad'           => $s->ciudad,
                    'zona_horaria'     => $s->zona_horaria,
                    'telefono'         => $s->telefono,
                    'email'            => $s->email,
                    'activa'           => $s->activa,
                    'tipo_label'       => $s->tipo_label,
                    'bodegas_count'    => $s->bodegas_count,
                    'usuarios_count'   => $s->usuarios_count,
                ]),

            // Zonas para elegir, y cuál es la hora global: la de la sede principal
            // es la que usa el sistema para guardar las fechas.
            'zonas' => collect(\App\Support\HoraSistema::zonasDisponibles())
                ->map(fn ($etiqueta, $valor) => ['valor' => $valor, 'etiqueta' => $etiqueta])
                ->values(),
            'zona_global' => \App\Support\HoraSistema::zonaGlobal(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);

        $sede = Sede::create($data);

        $this->sincronizarPrincipal($sede, $request->boolean('es_principal'));
        $this->crearSecuenciasPorDefecto($sede);

        return back()->with('success', 'Sede creada. Revisa su numeración en Configuración → Numeración.');
    }

    public function update(Request $request, Sede $sede)
    {
        $data = $this->validar($request, $sede->id);

        $sede->update($data);

        $this->sincronizarPrincipal($sede, $request->boolean('es_principal'));

        return back()->with('success', 'Sede actualizada.');
    }

    public function destroy(Sede $sede)
    {
        if ($sede->es_principal) {
            return back()->with('error', 'No se puede eliminar la sede principal.');
        }

        // Si ya tiene operación asociada, se desactiva en vez de borrarse
        // para no dejar datos huérfanos.
        if ($sede->bodegas()->exists() || $sede->usuarios()->exists()) {
            $sede->update(['activa' => false]);

            return back()->with('success', 'Sede desactivada (tiene bodegas o usuarios asociados).');
        }

        $sede->delete();

        return back()->with('success', 'Sede eliminada.');
    }

    /**
     * Cambia la sede activa del usuario (selector del encabezado). Se guarda
     * en la sesión, no en la base de datos.
     */
    public function cambiarActiva(Request $request)
    {
        $request->validate(['sede_id' => 'required|integer']);

        $sedeId = (int) $request->sede_id;

        // 0 = "Todas las sedes", solo para quien tenga ese permiso.
        if ($sedeId === 0) {
            if (! $request->user()->puedeVerTodasLasSedes()) {
                return back()->with('error', 'No tienes acceso a todas las sedes.');
            }

            session(['sede_activa_id' => 0]);

            return back();
        }

        if (! $request->user()->puedeAccederASede($sedeId)) {
            return back()->with('error', 'No tienes acceso a esa sede.');
        }

        session(['sede_activa_id' => $sedeId]);

        return back();
    }

    private function validar(Request $request, ?int $ignorarId = null): array
    {
        return $request->validate([
            'nombre'           => 'required|string|max:100',
            'codigo'           => ['required', 'string', 'max:10', Rule::unique('sedes', 'codigo')->ignore($ignorarId)],
            'tiene_ventas'     => 'boolean',
            'tiene_produccion' => 'boolean',
            'es_principal'     => 'boolean',
            'nit'              => 'nullable|string|max:50',
            'direccion'        => 'nullable|string|max:200',
            'ciudad'           => 'nullable|string|max:100',
            // La zona decide en qué hora vive esa sede, y la de la sede principal
            // es la hora global del sistema.
            'zona_horaria'     => 'required|string|in:' . implode(',', array_keys(\App\Support\HoraSistema::zonasDisponibles())),
            'telefono'         => 'nullable|string|max:50',
            'email'            => 'nullable|email|max:120',
            'activa'           => 'boolean',
        ], [
            // Sin esto, al elegir una zona que no está en la lista se le muestra
            // "validation.in" al usuario, que no le dice nada.
            'zona_horaria.required' => 'Elige la zona horaria de la sede.',
            'zona_horaria.in'       => 'Esa zona horaria no está entre las disponibles.',
        ]);
    }

    /**
     * Solo puede haber una sede principal.
     */
    private function sincronizarPrincipal(Sede $sede, bool $esPrincipal): void
    {
        if (!$esPrincipal) {
            return;
        }

        Sede::where('id', '!=', $sede->id)->update(['es_principal' => false]);
        $sede->update(['es_principal' => true]);
    }

    /**
     * Una sede nueva arranca con la numeración de todos los documentos,
     * prefijada con su código (ej. "CAL-OP-").
     */
    private function crearSecuenciasPorDefecto(Sede $sede): void
    {
        $porDefecto = [
            'op'               => ['prefijo' => 'OP-',  'anio' => false, 'padding' => 4],
            'cotizacion'       => ['prefijo' => 'COT-', 'anio' => true,  'padding' => 3],
            'remision'         => ['prefijo' => 'REM-', 'anio' => false, 'padding' => 4],
            'solicitud_compra' => ['prefijo' => 'SC-',  'anio' => true,  'padding' => 3],
            'orden_compra'     => ['prefijo' => 'OC-',  'anio' => true,  'padding' => 3],
            'serie_item'       => ['prefijo' => 'IF-',  'anio' => true,  'padding' => 3],
        ];

        foreach ($porDefecto as $tipo => $config) {
            SecuenciaDocumento::firstOrCreate(
                ['sede_id' => $sede->id, 'tipo_documento' => $tipo],
                [
                    'prefijo'          => $sede->es_principal ? $config['prefijo'] : $sede->codigo . '-' . $config['prefijo'],
                    'incluir_anio'     => $config['anio'],
                    'siguiente_numero' => 1,
                    'padding'          => $config['padding'],
                ]
            );
        }
    }
}
