<?php

namespace App\Http\Controllers;

use App\Models\CrmEtapa;
use App\Models\CrmFormulario;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmFormularioController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Crm/Formularios', [
            'formularios' => CrmFormulario::with('etapa:id,nombre', 'responsable:id,name')
                ->orderBy('created_at', 'desc')->get(),
            'etapas'      => CrmEtapa::where('activa', true)->orderBy('orden')->get(['id', 'nombre']),
            'usuarios'    => User::orderBy('name')->get(['id', 'name']),
            'app_url'     => rtrim(config('app.url'), '/'),
        ]);
    }

    private function rules(bool $isUpdate = false): array
    {
        $rules = [
            'nombre'                 => 'required|string|max:200',
            'etapa_id'               => 'nullable|exists:crm_etapas,id',
            'responsable_id'         => 'nullable|exists:users,id',
            'asignacion_tipo'        => 'nullable|in:fijo,round_robin,ponderado',
            'responsables_ids'       => 'nullable|array',
            'responsables_ids.*'     => 'integer|exists:users,id',
            'responsables_pesos'     => 'nullable|array',
            'fuente'                 => 'nullable|string|max:100',
            'campos'                 => 'required|array|min:1',
            'titulo_formulario'      => 'nullable|string|max:200',
            'descripcion_formulario' => 'nullable|string',
            'texto_boton'            => 'nullable|string|max:100',
            'mensaje_exito'          => 'nullable|string|max:300',
            'gracias_tipo'           => 'nullable|in:mensaje,redirect',
            'gracias_url'            => 'nullable|string|max:500',
            'email_notificacion'     => 'nullable|email',
            'captcha_activo'         => 'boolean',
        ];
        if ($isUpdate) {
            $rules['activo'] = 'boolean';
        }
        return $rules;
    }

    public function store(Request $request): JsonResponse
    {
        $data       = $request->validate($this->rules());
        $formulario = CrmFormulario::create($data);

        return response()->json(['formulario' => $formulario->load('etapa:id,nombre', 'responsable:id,name')]);
    }

    public function update(Request $request, CrmFormulario $formulario): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $formulario->update($data);

        return response()->json(['formulario' => $formulario->fresh()->load('etapa:id,nombre', 'responsable:id,name')]);
    }

    public function destroy(CrmFormulario $formulario): JsonResponse
    {
        $formulario->delete();
        return response()->json(['ok' => true]);
    }
}
