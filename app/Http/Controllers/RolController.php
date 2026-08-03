<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use App\Support\Permisos;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class RolController extends Controller
{
    public function index()
    {
        return Inertia::render('Configuracion/Roles', [
            'roles' => Rol::withCount('usuarios')
                ->orderByDesc('es_sistema')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Rol $r) => [
                    'id'              => $r->id,
                    'nombre'          => $r->nombre,
                    'descripcion'     => $r->descripcion,
                    'rol_base'        => $r->rol_base,
                    'es_sistema'      => $r->es_sistema,
                    'todas_las_sedes' => $r->todas_las_sedes,
                    'activo'          => $r->activo,
                    'usuarios_count'  => $r->usuarios_count,
                    'permisos'        => $r->permisos(),
                ]),
            'catalogo'          => Permisos::catalogo(),
            'etiquetasAcciones' => Permisos::etiquetasAcciones(),
            'rolesBase'         => Permisos::rolesBase(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);

        $rol = Rol::create([
            'nombre'          => $data['nombre'],
            'descripcion'     => $data['descripcion'] ?? null,
            'rol_base'        => $data['rol_base'],
            'todas_las_sedes' => $data['todas_las_sedes'] ?? false,
            'activo'          => $data['activo'] ?? true,
            'es_sistema'      => false,
        ]);

        $rol->sincronizarPermisos($this->permisosValidos($data['permisos'] ?? []));

        return back()->with('success', 'Rol creado.');
    }

    public function update(Request $request, Rol $rol)
    {
        $data = $this->validar($request, $rol->id);

        // El rol base de los roles de sistema no se cambia: es lo que mantiene
        // funcionando el control de acceso que ya existe en todo el sistema.
        $rol->update([
            'nombre'          => $data['nombre'],
            'descripcion'     => $data['descripcion'] ?? null,
            'rol_base'        => $rol->es_sistema ? $rol->rol_base : $data['rol_base'],
            'todas_las_sedes' => $data['todas_las_sedes'] ?? false,
            'activo'          => $rol->es_sistema ? true : ($data['activo'] ?? true),
        ]);

        $rol->sincronizarPermisos($this->permisosValidos($data['permisos'] ?? []));

        // Mantiene sincronizado el rol histórico de los usuarios, del que
        // todavía dependen las rutas y varios controladores.
        $rol->usuarios()->update(['rol' => $rol->rol_base]);

        return back()->with('success', 'Rol actualizado.');
    }

    public function destroy(Rol $rol)
    {
        if ($rol->es_sistema) {
            return back()->with('error', 'Los roles originales del sistema no se pueden eliminar.');
        }

        if ($rol->usuarios()->exists()) {
            return back()->with('error', 'Este rol tiene usuarios asignados. Reasígnalos antes de eliminarlo.');
        }

        $rol->delete();

        return back()->with('success', 'Rol eliminado.');
    }

    private function validar(Request $request, ?int $ignorarId = null): array
    {
        return $request->validate([
            'nombre'          => ['required', 'string', 'max:60', Rule::unique('roles', 'nombre')->ignore($ignorarId)],
            'descripcion'     => 'nullable|string|max:200',
            'rol_base'        => ['required', Rule::in(array_keys(Permisos::rolesBase()))],
            'todas_las_sedes' => 'boolean',
            'activo'          => 'boolean',
            'permisos'        => 'array',
            'permisos.*'      => 'string',
        ]);
    }

    /**
     * Descarta cualquier permiso que no exista en el catálogo.
     */
    private function permisosValidos(array $permisos): array
    {
        return array_values(array_intersect($permisos, Permisos::todos()));
    }
}
