<?php

namespace App\Http\Controllers;

use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProveedorController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Proveedor::query()
            ->when($request->filled('buscar'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('nombre', 'like', "%{$request->buscar}%")
                  ->orWhere('nit', 'like', "%{$request->buscar}%")
                  ->orWhere('contacto', 'like', "%{$request->buscar}%")
                  ->orWhere('email', 'like', "%{$request->buscar}%");
            }))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->tipo))
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', $request->activo === 'true'))
;

        // El orden lo pide la pantalla. `Orden::aplicar` valida el campo contra esta
        // lista: lo que llegue por `?orden=` y no esté aquí se ignora, así que el
        // parámetro nunca toca el SQL.
        $orden = \App\Support\Orden::aplicar($query, $request, [
            'nombre'     => 'nombre',
            'ciudad'     => 'ciudad',
            'created_at' => 'created_at',
        ]);

        // `datos_rut` es el texto completo que leyó la IA: sirve para revisar, pesa, y la lista
        // no lo muestra. Se esconde aquí; la ficha no lo manda de vuelta, así que al guardar
        // se queda como estaba.
        $proveedores = $query->paginate(20)->withQueryString()
            ->through(fn (Proveedor $p) => $p->makeHidden('datos_rut'));

        return Inertia::render('Compras/Proveedores/Index', [
            'proveedores' => $proveedores,
            'orden'      => $orden,
            'filters'     => $request->only(['buscar', 'tipo', 'activo']),
            'catalogo_fiscal' => \App\Support\Fiscal::catalogo(),
        ]);
    }

    /**
     * Lee un RUT (o una foto de él) y devuelve los campos para llenar la ficha del proveedor.
     *
     * No guarda nada: la pantalla pone los datos para que alguien los revise. Y avisa si ya hay
     * un proveedor con ese número, porque cargar el mismo RUT dos veces es la manera más fácil
     * de tener dos fichas de la misma empresa con precios y órdenes repartidos entre ellas.
     * Pide poder crear o editar, porque cada lectura es una llamada a la IA y cuesta.
     */
    public function leerRut(Request $request, \App\Services\IA\LectorRutService $lector): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->tienePermiso('proveedores.crear') || $usuario->tienePermiso('proveedores.editar'), 403);

        $request->validate([
            'archivo' => 'required|file|max:10240|mimetypes:' . implode(',', \App\Services\IA\LectorRutService::TIPOS),
            'excluir' => 'nullable|integer',
        ], [
            'archivo.mimetypes' => 'El archivo tiene que ser un PDF o una foto (JPG, PNG o WEBP).',
            'archivo.max'       => 'El archivo pesa más de 10 MB.',
        ]);

        try {
            $lectura = $lector->leer($request->file('archivo'));
        } catch (\App\Exceptions\IaException $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        $numero = (string) ($lectura['datos']['numero_identificacion'] ?? '');

        $existente = $numero === '' ? null : Proveedor::query()
            ->when($request->filled('excluir'), fn ($q) => $q->where('id', '!=', $request->integer('excluir')))
            ->where(fn ($q) => $q->where('numero_identificacion', $numero)->orWhere('nit', 'like', "{$numero}%"))
            ->first(['id', 'nombre']);

        return response()->json(['ok' => true, 'existente' => $existente] + $lectura);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nombre'    => 'required|string|max:255',
            'nit'       => 'nullable|string|max:20',
            'contacto'  => 'nullable|string|max:255',
            'telefono'  => 'nullable|string|max:20',
            'email'     => 'nullable|email|max:255',
            'ciudad'    => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:255',
            'tipo'      => 'required|in:materia_prima,insumos,mixto',
            'activo'    => 'boolean',
            'notas'     => 'nullable|string',
            'tipo_persona'               => 'nullable|in:empresa,persona',
            'tipo_identificacion'        => 'nullable|in:CC,NIT,CE,PA,RUT',
            'numero_identificacion'      => 'nullable|string|max:30',
            'digito_verificacion'        => 'nullable|string|max:1',
            'actividad_economica'        => 'nullable|string|max:10',
            'responsabilidades_fiscales' => 'nullable|array',
            'datos_rut'                  => 'nullable|array',
        ]);

        $data = $this->normalizarFiscal($data);

        Proveedor::create($data);

        return back()->with('success', 'Proveedor creado correctamente.');
    }

    public function update(Request $request, Proveedor $proveedor): RedirectResponse
    {
        $data = $request->validate([
            'nombre'    => 'required|string|max:255',
            'nit'       => 'nullable|string|max:20',
            'contacto'  => 'nullable|string|max:255',
            'telefono'  => 'nullable|string|max:20',
            'email'     => 'nullable|email|max:255',
            'ciudad'    => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:255',
            'tipo'      => 'required|in:materia_prima,insumos,mixto',
            'activo'    => 'boolean',
            'notas'     => 'nullable|string',
            'tipo_persona'               => 'nullable|in:empresa,persona',
            'tipo_identificacion'        => 'nullable|in:CC,NIT,CE,PA,RUT',
            'numero_identificacion'      => 'nullable|string|max:30',
            'digito_verificacion'        => 'nullable|string|max:1',
            'actividad_economica'        => 'nullable|string|max:10',
            'responsabilidades_fiscales' => 'nullable|array',
            'datos_rut'                  => 'nullable|array',
        ]);

        $data = $this->normalizarFiscal($data);

        $proveedor->update($data);

        return back()->with('success', 'Proveedor actualizado correctamente.');
    }

    /**
     * Deja los datos del RUT con la forma que el resto del sistema espera.
     *
     * Los códigos de la casilla 53 pasan por `Fiscal::codigos()`, igual que en un cliente. Y
     * el NIT de texto se rellena solo cuando viene del RUT y no se escribió uno a mano:
     * `nit` sigue siendo lo que muestran las listas y lo que busca la gente.
     */
    private function normalizarFiscal(array $data): array
    {
        if (array_key_exists('responsabilidades_fiscales', $data)) {
            $data['responsabilidades_fiscales'] = \App\Support\Fiscal::codigos($data['responsabilidades_fiscales'] ?? []);
        }

        if (blank($data['nit'] ?? null) && filled($data['numero_identificacion'] ?? null)) {
            $dv = filled($data['digito_verificacion'] ?? null) ? '-'.$data['digito_verificacion'] : '';
            $data['nit'] = mb_substr($data['numero_identificacion'].$dv, 0, 20);
        }

        return $data;
    }

    public function destroy(Proveedor $proveedor): RedirectResponse
    {
        $proveedor->update(['activo' => false]);

        return back()->with('success', 'Proveedor desactivado.');
    }
}
