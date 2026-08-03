<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UsuarioController extends Controller
{
    public function index(): Response
    {
        $usuarios = User::with(['sede:id,nombre,codigo', 'rolConfigurable:id,nombre'])
            ->orderBy('name')
            ->paginate(10)
            ->through(fn ($u) => [
                'id'       => $u->id,
                'name'     => $u->name,
                'email'    => $u->email,
                'telefono' => $u->telefono,
                'rol'      => $u->rol,
                'rol_nombre' => $u->rolConfigurable?->nombre,
                'sede'     => $u->sede?->nombre,
                'activo'   => $u->activo,
            ]);

        return Inertia::render('Usuarios/Index', [
            'usuarios' => $usuarios,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Usuarios/Create', $this->opciones());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validar($request);

        $rol = Rol::findOrFail($data['rol_id']);

        $usuario = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'telefono' => $data['telefono'] ?? null,
            'password' => Hash::make($data['password']),
            'rol_id'   => $rol->id,
            // El rol histórico se mantiene sincronizado con el rol base: de él
            // dependen todavía las rutas y varios controladores.
            'rol'      => $rol->rol_base,
            'sede_id'  => $data['sede_id'],
            'activo'   => $data['activo'] ?? true,
        ]);

        $this->sincronizarAlcance($usuario, $data);

        return redirect('/usuarios')->with('success', 'Usuario creado correctamente.');
    }

    public function edit(User $usuario): Response
    {
        return Inertia::render('Usuarios/Edit', array_merge($this->opciones(), [
            'usuario' => [
                'id'       => $usuario->id,
                'name'     => $usuario->name,
                'email'    => $usuario->email,
                'telefono' => $usuario->telefono,
                'rol_id'   => $usuario->rol_id,
                'sede_id'  => $usuario->sede_id,
                'sedes'    => $usuario->sedes()->pluck('sedes.id'),
                'bodegas'  => $usuario->bodegas()->pluck('bodegas.id'),
                'activo'   => $usuario->activo,
            ],
        ]));
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $data = $this->validar($request, $usuario->id);

        $rol = Rol::findOrFail($data['rol_id']);

        $campos = [
            'name'     => $data['name'],
            'email'    => $data['email'],
            'telefono' => $data['telefono'] ?? null,
            'rol_id'   => $rol->id,
            'rol'      => $rol->rol_base,
            'sede_id'  => $data['sede_id'],
            'activo'   => $data['activo'] ?? true,
        ];

        if (!empty($data['password'])) {
            $campos['password'] = Hash::make($data['password']);
        }

        $usuario->update($campos);

        $this->sincronizarAlcance($usuario, $data);

        return redirect('/usuarios')->with('success', 'Usuario actualizado correctamente.');
    }

    public function destroy(User $usuario): RedirectResponse
    {
        $usuario->update(['activo' => false]);

        return redirect('/usuarios')->with('success', 'Usuario desactivado.');
    }

    private function validar(Request $request, ?int $ignorarId = null): array
    {
        return $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => ['required', 'email', Rule::unique('users', 'email')->ignore($ignorarId)],
            'telefono'  => 'nullable|string|max:20',
            'password'  => $ignorarId ? 'nullable|string|min:8|confirmed' : 'required|string|min:8|confirmed',
            'rol_id'    => 'required|exists:roles,id',
            'sede_id'   => 'required|exists:sedes,id',
            'sedes'     => 'array',
            'sedes.*'   => 'exists:sedes,id',
            'bodegas'   => 'array',
            'bodegas.*' => 'exists:bodegas,id',
            'activo'    => 'boolean',
        ]);
    }

    /**
     * Sedes y bodegas a las que accede el usuario. Si no se marca ninguna
     * sede, queda con la suya para no dejarlo sin acceso a nada.
     */
    private function sincronizarAlcance(User $usuario, array $data): void
    {
        $sedes = $data['sedes'] ?? [];

        if (empty($sedes)) {
            $sedes = [$data['sede_id']];
        }

        $usuario->sedes()->sync($sedes);
        $usuario->bodegas()->sync($data['bodegas'] ?? []);
    }

    private function opciones(): array
    {
        return [
            'roles' => Rol::activos()
                ->orderByDesc('es_sistema')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'rol_base', 'todas_las_sedes']),
            'sedes' => Sede::activas()
                ->orderByDesc('es_principal')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'codigo']),
            'bodegas' => Bodega::with('sede:id,nombre')
                ->where('activa', true)
                ->orderBy('sede_id')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'sede_id']),
        ];
    }
}
