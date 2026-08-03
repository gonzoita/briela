<?php

namespace App\Http\Controllers;

use App\Models\Operario;
use App\Models\OperarioBono;
use App\Models\OperarioDisciplina;
use App\Models\OperarioHito;
use App\Models\OperarioHoraExtra;
use App\Models\OperarioPermiso;
use App\Models\OperarioTarifaExtra;
use App\Models\PuntoColaborador;
use App\Models\NivelColaborador;
use App\Models\TipoColaborador;
use App\Models\User;
use App\Services\PuntosColaboradorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OperarioController extends Controller
{
    public function index(Request $request): Response
    {
        $mes  = now()->month;
        $anio = now()->year;

        $columnasPermitidas = ['nombre', 'puntos_totales', 'fecha_ingreso', 'estado', 'created_at'];
        $orden = in_array($request->orden, $columnasPermitidas) ? $request->orden : 'puntos_totales';
        $dir   = $request->dir === 'asc' ? 'asc' : 'desc';

        $operarios = \App\Support\ContextoSede::aplicar(Operario::query())
            ->with(['tipoColaborador', 'user'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($q2) =>
                $q2->where('nombre', 'like', "%{$request->buscar}%")
                   ->orWhere('documento', 'like', "%{$request->buscar}%")
                   ->orWhere('especialidad', 'like', "%{$request->buscar}%")
            ))
            ->when($request->filled('tipo_colaborador_id'), fn ($q) =>
                $q->where('tipo_colaborador_id', $request->tipo_colaborador_id)
            )
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('estado', $request->estado)
            )
            ->when($request->filled('fecha_ingreso_desde'), fn ($q) =>
                $q->whereDate('fecha_ingreso', '>=', $request->fecha_ingreso_desde)
            )
            ->when($request->filled('fecha_ingreso_hasta'), fn ($q) =>
                $q->whereDate('fecha_ingreso', '<=', $request->fecha_ingreso_hasta)
            )
            ->when($request->filled('nivel'), fn ($q) =>
                $q->where('nivel', $request->nivel)
            )
            ->when($request->filled('puntos_min'), fn ($q) =>
                $q->where('puntos_totales', '>=', $request->puntos_min)
            )
            ->orderBy($orden, $dir)
            ->get()
            ->map(fn ($op) => [
                'id'                     => $op->id,
                'nombre'                 => $op->nombre,
                'documento'              => $op->documento,
                'telefono'               => $op->telefono,
                'email'                  => $op->email,
                'especialidad'           => $op->especialidad,
                'cargo'                  => $op->cargo,
                'estado'                 => $op->estado,
                'fecha_ingreso'          => $op->fecha_ingreso?->format('Y-m-d'),
                'puntos_totales'         => $op->puntos_totales ?? 0,
                'nivel'                  => $op->nivel ?? 'Bronce',
                'tipo_colaborador'       => $op->tipoColaborador?->nombre,
                'tipo_color'             => $op->tipoColaborador?->color,
                'tiene_usuario'          => !is_null($op->user_id),
                'bono_mes'               => OperarioBono::where('operario_id', $op->id)
                    ->where('periodo_mes', $mes)->where('periodo_anio', $anio)
                    ->value('total_bono') ?? 0,
                'disciplinas_pendientes' => OperarioDisciplina::where('operario_id', $op->id)
                    ->where('firmado', false)->count(),
            ]);

        $metricas = [
            'total_activos'          => \App\Support\ContextoSede::aplicar(Operario::query())
                                            ->where('estado', 'activo')->count(),
            'bonos_calculados_mes'   => OperarioBono::where('periodo_mes', $mes)->where('periodo_anio', $anio)->count(),
            'disciplinas_pendientes' => OperarioDisciplina::where('firmado', false)->count(),
            'horas_extras_mes'       => OperarioHoraExtra::whereMonth('fecha', $mes)->whereYear('fecha', $anio)->sum('horas'),
        ];

        return Inertia::render('RRHH/Operarios/Index', [
            'operarios'         => $operarios,
            'metricas'          => $metricas,
            'tipos_colaborador' => TipoColaborador::where('activo', true)->orderBy('orden')->get(['id', 'nombre', 'color']),
            'niveles'           => NivelColaborador::orderBy('orden')->get(['nombre', 'color']),
            'filters'           => $request->only(['buscar', 'tipo_colaborador_id', 'estado', 'fecha_ingreso_desde', 'fecha_ingreso_hasta', 'nivel', 'puntos_min']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('RRHH/Operarios/Create', [
            'usuarios_operario' => User::where('rol', 'operario')
                ->where('activo', true)
                ->whereNotIn('id', Operario::whereNotNull('user_id')->pluck('user_id'))
                ->get(['id', 'name']),
            'tipos_colaborador' => TipoColaborador::where('activo', true)->orderBy('orden')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rules = [
            'nombre'              => 'required|string|max:200',
            'documento'           => 'required|string|max:50',
            'telefono'            => 'nullable|string|max:20',
            'especialidad'        => 'nullable|string|max:100',
            'cargo'               => 'nullable|string|max:150',
            'estado'              => 'required|in:activo,inactivo',
            'user_id'             => 'nullable|exists:users,id',
            'crear_usuario'       => 'boolean',
            'tipo_colaborador_id' => 'nullable|exists:tipos_colaborador,id',
            'fecha_nacimiento'    => 'nullable|date',
            'fecha_ingreso'       => 'nullable|date',
            'direccion'           => 'nullable|string|max:300',
            'ciudad'              => 'nullable|string|max:100',
            'email'               => 'nullable|email|max:200',
            'numero_eps'          => 'nullable|string|max:100',
            'nombre_eps'          => 'nullable|string|max:200',
            'numero_pension'      => 'nullable|string|max:100',
            'nombre_pension'      => 'nullable|string|max:200',
            'numero_cuenta_bancaria' => 'nullable|string|max:100',
            'banco'               => 'nullable|string|max:150',
            'tipo_cuenta'         => 'nullable|in:ahorros,corriente',
        ];

        if ($request->boolean('crear_usuario')) {
            $rules['usuario_name']     = 'required|string|max:200';
            $rules['usuario_email']    = 'required|email|unique:users,email';
            $rules['usuario_password'] = 'required|string|min:8';
        }

        $data = $request->validate($rules);

        $userId = $data['user_id'] ?? null;
        if ($request->boolean('crear_usuario')) {
            $nuevoUsuario = User::create([
                'name'     => $data['usuario_name'],
                'email'    => $data['usuario_email'],
                'password' => \Illuminate\Support\Facades\Hash::make($data['usuario_password']),
                'rol'      => 'operario',
                'activo'   => true,
            ]);
            $userId = $nuevoUsuario->id;
        }

        $operario = Operario::create([
            'nombre'                 => $data['nombre'],
            'documento'              => $data['documento'],
            'telefono'               => $data['telefono'] ?? null,
            'especialidad'           => $data['especialidad'] ?? null,
            'cargo'                  => $data['cargo'] ?? null,
            'estado'                 => $data['estado'],
            'user_id'                => $userId,
            'tipo_colaborador_id'    => $data['tipo_colaborador_id'] ?? null,
            'fecha_nacimiento'       => $data['fecha_nacimiento'] ?? null,
            'fecha_ingreso'          => $data['fecha_ingreso'] ?? null,
            'direccion'              => $data['direccion'] ?? null,
            'ciudad'                 => $data['ciudad'] ?? null,
            'email'                  => $data['email'] ?? null,
            'numero_eps'             => $data['numero_eps'] ?? null,
            'nombre_eps'             => $data['nombre_eps'] ?? null,
            'numero_pension'         => $data['numero_pension'] ?? null,
            'nombre_pension'         => $data['nombre_pension'] ?? null,
            'numero_cuenta_bancaria' => $data['numero_cuenta_bancaria'] ?? null,
            'banco'                  => $data['banco'] ?? null,
            'tipo_cuenta'            => $data['tipo_cuenta'] ?? null,
        ]);

        return redirect("/rrhh/operarios/{$operario->id}")
            ->with('success', "Colaborador {$operario->nombre} creado." .
                ($request->boolean('crear_usuario') ? ' Usuario de acceso creado.' : ''));
    }

    public function show(Operario $operario): Response
    {
        $mes  = now()->month;
        $anio = now()->year;

        $operario->load(['user', 'bonos' => fn ($q) => $q->orderByDesc('periodo_anio')->orderByDesc('periodo_mes')->limit(6)]);

        $disciplinas = OperarioDisciplina::where('operario_id', $operario->id)
            ->with('creadoPor')
            ->orderByDesc('fecha')
            ->get()
            ->map(fn ($d) => [
                ...$d->toArray(),
                'tipo_label' => $d->tipoLabel(),
            ]);

        $hitos_mes = OperarioHito::where('operario_id', $operario->id)
            ->where('periodo_mes', $mes)
            ->where('periodo_anio', $anio)
            ->get();

        $horas_extras_mes = OperarioHoraExtra::where('operario_id', $operario->id)
            ->whereMonth('fecha', $mes)
            ->whereYear('fecha', $anio)
            ->get();

        $bono_mes = OperarioBono::where('operario_id', $operario->id)
            ->where('periodo_mes', $mes)
            ->where('periodo_anio', $anio)
            ->first();

        $pasos_mes = \App\Models\OpItemTrabajoPaso::where('operario_id', $operario->id)
            ->where('completado', true)
            ->whereMonth('completado_at', $mes)
            ->whereYear('completado_at', $anio)
            ->with(['trabajo.opItem.op'])
            ->orderByDesc('completado_at')
            ->get();

        $historial_bonos = OperarioBono::where('operario_id', $operario->id)
            ->orderByDesc('periodo_anio')
            ->orderByDesc('periodo_mes')
            ->limit(6)
            ->get();

        $permisos_mes = OperarioPermiso::where('operario_id', $operario->id)
            ->whereYear('fecha_inicio', $anio)
            ->whereMonth('fecha_inicio', $mes)
            ->get();

        $historial_puntos = PuntoColaborador::where('operario_id', $operario->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($p) => [
                'id'         => $p->id,
                'puntos'     => $p->puntos,
                'concepto'   => $p->concepto,
                'tipo'       => $p->tipo,
                'created_at' => $p->created_at?->format('d/m/Y H:i'),
            ]);

        return Inertia::render('RRHH/Operarios/Show', [
            'operario'        => array_merge($operario->toArray(), [
                'usuario_nombre' => $operario->user?->name,
                'usuario_email'  => $operario->user?->email,
            ]),
            'disciplinas'     => $disciplinas,
            'hitos_mes'       => $hitos_mes,
            'horas_extras_mes'=> $horas_extras_mes,
            'bono_mes'        => $bono_mes,
            'pasos_mes'       => $pasos_mes,
            'historial_bonos' => $historial_bonos,
            'permisos_mes'    => $permisos_mes,
            'historial_puntos'=> $historial_puntos,
            'mes_actual'      => $mes,
            'anio_actual'     => $anio,
        ]);
    }

    public function edit(Operario $operario): Response
    {
        $operario->load('user');
        return Inertia::render('RRHH/Operarios/Edit', [
            'operario'          => array_merge($operario->toArray(), [
                'usuario_nombre' => $operario->user?->name,
            ]),
            'usuarios_operario' => User::where('rol', 'operario')
                ->where('activo', true)
                ->whereNotIn('id', Operario::where('id', '!=', $operario->id)->whereNotNull('user_id')->pluck('user_id'))
                ->get(['id', 'name']),
            'tipos_colaborador' => TipoColaborador::where('activo', true)->orderBy('orden')->get(),
        ]);
    }

    public function update(Request $request, Operario $operario): RedirectResponse
    {
        $rules = [
            'nombre'              => 'required|string|max:200',
            'documento'           => 'required|string|max:50',
            'telefono'            => 'nullable|string|max:20',
            'especialidad'        => 'nullable|string|max:100',
            'cargo'               => 'nullable|string|max:150',
            'estado'              => 'required|in:activo,inactivo',
            'user_id'             => 'nullable|exists:users,id',
            'crear_usuario'       => 'boolean',
            'tipo_colaborador_id' => 'nullable|exists:tipos_colaborador,id',
            'fecha_nacimiento'    => 'nullable|date',
            'fecha_ingreso'       => 'nullable|date',
            'direccion'           => 'nullable|string|max:300',
            'ciudad'              => 'nullable|string|max:100',
            'email'               => 'nullable|email|max:200',
            'numero_eps'          => 'nullable|string|max:100',
            'nombre_eps'          => 'nullable|string|max:200',
            'numero_pension'      => 'nullable|string|max:100',
            'nombre_pension'      => 'nullable|string|max:200',
            'numero_cuenta_bancaria' => 'nullable|string|max:100',
            'banco'               => 'nullable|string|max:150',
            'tipo_cuenta'         => 'nullable|in:ahorros,corriente',
        ];

        if ($request->boolean('crear_usuario')) {
            $rules['usuario_name']     = 'required|string|max:200';
            $rules['usuario_email']    = 'required|email|unique:users,email';
            $rules['usuario_password'] = 'required|string|min:8';
        }

        $data = $request->validate($rules);

        $userId = ($data['user_id'] ?? '') === '' ? null : ($data['user_id'] ?? null);

        if ($request->boolean('crear_usuario')) {
            $nuevoUsuario = User::create([
                'name'     => $data['usuario_name'],
                'email'    => $data['usuario_email'],
                'password' => \Illuminate\Support\Facades\Hash::make($data['usuario_password']),
                'rol'      => 'operario',
                'activo'   => true,
            ]);
            $userId = $nuevoUsuario->id;
        }

        $operario->update([
            'nombre'                 => $data['nombre'],
            'documento'              => $data['documento'],
            'telefono'               => $data['telefono'] ?? null,
            'especialidad'           => $data['especialidad'] ?? null,
            'cargo'                  => $data['cargo'] ?? null,
            'estado'                 => $data['estado'],
            'user_id'                => $userId,
            'tipo_colaborador_id'    => $data['tipo_colaborador_id'] ?? null,
            'fecha_nacimiento'       => $data['fecha_nacimiento'] ?? null,
            'fecha_ingreso'          => $data['fecha_ingreso'] ?? null,
            'direccion'              => $data['direccion'] ?? null,
            'ciudad'                 => $data['ciudad'] ?? null,
            'email'                  => $data['email'] ?? null,
            'numero_eps'             => $data['numero_eps'] ?? null,
            'nombre_eps'             => $data['nombre_eps'] ?? null,
            'numero_pension'         => $data['numero_pension'] ?? null,
            'nombre_pension'         => $data['nombre_pension'] ?? null,
            'numero_cuenta_bancaria' => $data['numero_cuenta_bancaria'] ?? null,
            'banco'                  => $data['banco'] ?? null,
            'tipo_cuenta'            => $data['tipo_cuenta'] ?? null,
        ]);

        return redirect("/rrhh/operarios/{$operario->id}")
            ->with('success', 'Colaborador actualizado.' .
                ($request->boolean('crear_usuario') ? ' Usuario de acceso creado.' : ''));
    }

    public function destroy(Operario $operario): RedirectResponse
    {
        $operario->delete();
        return redirect('/rrhh/operarios')->with('success', 'Colaborador eliminado.');
    }

    public function subirArchivo(Request $request, Operario $operario): JsonResponse
    {
        try {
            $request->validate([
                'archivo' => 'required|file|max:10240',
                'tipo'    => 'required|string|max:50',
            ]);

            $carpeta = "colaboradores/{$operario->id}";
            $path = $request->file('archivo')->store($carpeta, 'public');
            $url  = asset('storage/' . $path);

            if ($request->tipo === 'otros') {
                $otros = $operario->archivo_otros ?? [];
                $otros[] = [
                    'nombre' => $request->file('archivo')->getClientOriginalName(),
                    'path'   => $path,
                    'url'    => $url,
                ];
                $operario->update(['archivo_otros' => $otros]);
            } else {
                $campo = 'archivo_' . $request->tipo;
                if (in_array($campo, $operario->getFillable())) {
                    $operario->update([$campo => $path]);
                } else {
                    return response()->json([
                        'ok'    => false,
                        'error' => "Campo {$campo} no existe en el modelo"
                    ], 422);
                }
            }

            return response()->json(['ok' => true, 'path' => $path, 'url' => $url]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function eliminarArchivo(Request $request, Operario $operario): JsonResponse
    {
        $request->validate(['campo' => 'required|string']);
        $operario->update([$request->campo => null]);
        return response()->json(['ok' => true]);
    }

    public function storeDisciplina(Request $request, Operario $operario): RedirectResponse
    {
        $data = $request->validate([
            'tipo'               => 'required|in:falla,memorando,llamado_atencion',
            'descripcion'        => 'required|string',
            'fecha'              => 'required|date',
            'penalizacion_valor' => 'nullable|numeric|min:0',
        ]);

        OperarioDisciplina::create([
            ...$data,
            'operario_id'        => $operario->id,
            'creado_por'         => auth()->id(),
            'firmado'            => false,
            'penalizacion_valor' => $data['penalizacion_valor'] ?? 0,
        ]);

        // Nota: la disciplina NO descuenta puntos de gamificación. Los puntos
        // solo suben por lo positivo (trabajo realizado según dificultad y
        // cursos aprobados). El efecto de una sanción es su registro firmado
        // y, si aplica, el descuento en pesos del bono (penalizacion_valor),
        // no bajar el ranking de estrellas. Antes había un mapeo heredado que
        // penalizaba un "llamado de atención" como si fuera una "inasistencia".

        // Aviso al colaborador para que firme el documento.
        if ($operario->user_id) {
            app(\App\Services\NotificacionService::class)->crear(
                $operario->user_id,
                'disciplina_por_firmar',
                'Tienes un documento por firmar',
                'Se registró una sanción disciplinaria que requiere tu firma.',
                '/mi-panel',
            );
        }

        return back()->with('success', 'Registro de disciplina creado.');
    }

    public function firmarDisciplina(Request $request, Operario $operario, OperarioDisciplina $disciplina): RedirectResponse
    {
        $user = auth()->user();
        $puedeAdmin = in_array($user->rol, ['administrador', 'jefe_produccion']);
        $esPropioOperario = $operario->user_id === $user->id;

        if (!$puedeAdmin && !$esPropioOperario) {
            return back()->with('error', 'No autorizado para firmar.');
        }

        $disciplina->update(['firmado' => true, 'firmado_at' => now()]);

        return back()->with('success', 'Documento firmado.');
    }

    public function storeHoraExtra(Request $request, Operario $operario): RedirectResponse
    {
        $data = $request->validate([
            'fecha'       => 'required|date',
            'tipo'        => 'required|in:diurna,nocturna,dominical',
            'horas'       => 'required|numeric|min:0.5|max:24',
            'observacion' => 'nullable|string',
        ]);

        OperarioHoraExtra::create([
            ...$data,
            'operario_id' => $operario->id,
            'aprobado_por'=> auth()->id(),
        ]);

        return back()->with('success', 'Horas extras registradas.');
    }

    public function storePermiso(Request $request, Operario $operario): RedirectResponse
    {
        $data = $request->validate([
            'fecha_inicio'=> 'required|date',
            'fecha_fin'   => 'required|date|after_or_equal:fecha_inicio',
            'motivo'      => 'nullable|string',
        ]);

        OperarioPermiso::create([
            ...$data,
            'operario_id' => $operario->id,
            'aprobado'    => true,
            'aprobado_por'=> auth()->id(),
        ]);

        return back()->with('success', 'Permiso registrado.');
    }

    public function storeHito(Request $request, Operario $operario): RedirectResponse
    {
        $data = $request->validate([
            'nombre'      => 'required|string|max:200',
            'tipo'        => 'required|in:sistema,manual',
            'meta_valor'  => 'required|numeric|min:0',
            'meta_tipo'   => 'required|in:pasos,tiempo,ops',
            'valor_bono'  => 'required|numeric|min:0',
            'periodo_mes' => 'required|integer|min:1|max:12',
            'periodo_anio'=> 'required|integer|min:2020',
        ]);

        OperarioHito::create([
            ...$data,
            'operario_id' => $operario->id,
        ]);

        return back()->with('success', 'Hito creado.');
    }

    public function calcularBono(Request $request, Operario $operario): RedirectResponse
    {
        $request->validate([
            'mes'  => 'required|integer|min:1|max:12',
            'anio' => 'required|integer|min:2020',
        ]);

        $total = $this->procesarBonoOperario($operario, (int) $request->mes, (int) $request->anio);

        return back()->with('success', "Bono calculado: $" . number_format($total, 0, ',', '.'));
    }

    // Calcula el bono de TODOS los colaboradores activos de un mes de una vez,
    // en vez de uno por uno.
    public function calcularBonosTodos(Request $request): RedirectResponse
    {
        $request->validate([
            'mes'  => 'required|integer|min:1|max:12',
            'anio' => 'required|integer|min:2020',
        ]);

        $operarios = Operario::where('activo', true)->get();
        foreach ($operarios as $operario) {
            $this->procesarBonoOperario($operario, (int) $request->mes, (int) $request->anio);
        }

        return back()->with('success', "Bonos calculados para {$operarios->count()} colaborador(es).");
    }

    // Lógica compartida por el cálculo individual y el masivo. Calcula, guarda
    // el bono del mes y avisa al colaborador. Devuelve el total (nunca menor a 0).
    private function procesarBonoOperario(Operario $operario, int $mes, int $anio): float
    {
        // 1. Pasos completados del mes
        $tarifaBase = (float) (\DB::table('configuraciones')->where('clave', 'tarifa_hora_base')->value('valor') ?? 15000);

        $minutosTotal = \App\Models\OpItemTrabajoPaso::where('operario_id', $operario->id)
            ->where('completado', true)
            ->whereMonth('completado_at', $mes)
            ->whereYear('completado_at', $anio)
            ->sum('tiempo_minutos');

        $horasTrabajadas = $minutosTotal / 60;
        $pasosValor = round($horasTrabajadas * $tarifaBase, 2);

        // 2. Hitos del mes — verificar hitos sistema
        $hitos = OperarioHito::where('operario_id', $operario->id)
            ->where('periodo_mes', $mes)
            ->where('periodo_anio', $anio)
            ->get();

        foreach ($hitos as $hito) {
            if ($hito->tipo === 'sistema' && !$hito->cumplido) {
                $valor_actual = match ($hito->meta_tipo) {
                    'pasos' => \App\Models\OpItemTrabajoPaso::where('operario_id', $operario->id)
                        ->where('completado', true)
                        ->whereMonth('completado_at', $mes)->whereYear('completado_at', $anio)->count(),
                    'tiempo' => $horasTrabajadas,
                    'ops'    => \App\Models\OpItemTrabajoPaso::where('operario_id', $operario->id)
                        ->where('completado', true)
                        ->whereMonth('completado_at', $mes)->whereYear('completado_at', $anio)
                        ->with('trabajo.opItem')->get()
                        ->pluck('trabajo.opItem.op_id')->unique()->count(),
                    default  => 0,
                };

                if ($valor_actual >= $hito->meta_valor) {
                    $hito->update(['cumplido' => true, 'cumplido_at' => now()]);
                }
            }
        }

        $hitos->load([]);
        $hitosValor = (float) OperarioHito::where('operario_id', $operario->id)
            ->where('periodo_mes', $mes)->where('periodo_anio', $anio)
            ->where('cumplido', true)->sum('valor_bono');

        // 3. Horas extras del mes
        $tarifas = OperarioTarifaExtra::where('activo', true)->get()->keyBy('tipo');
        $horasExtras = OperarioHoraExtra::where('operario_id', $operario->id)
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)->get();

        $extrasValor = 0;
        foreach ($horasExtras as $he) {
            $tarifa = $tarifas->get($he->tipo);
            if ($tarifa) $extrasValor += $he->horas * $tarifa->valor_hora;
        }
        $extrasValor = round($extrasValor, 2);

        // 4. Penalizaciones
        $valorPenalizacion = (float) (\DB::table('configuraciones')->where('clave', 'penalizacion_ausencia')->value('valor') ?? 0);
        $penalizaciones = (float) OperarioDisciplina::where('operario_id', $operario->id)
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)->sum('penalizacion_valor');

        // 5. Total
        $totalBono = $pasosValor + $hitosValor + $extrasValor - $penalizaciones;

        $detalle = [
            'horas_trabajadas' => round($horasTrabajadas, 2),
            'tarifa_base'      => $tarifaBase,
            'pasos_valor'      => $pasosValor,
            'hitos'            => $hitos->map(fn ($h) => ['nombre' => $h->nombre, 'cumplido' => $h->cumplido, 'valor' => $h->valor_bono]),
            'hitos_valor'      => $hitosValor,
            'extras_valor'     => $extrasValor,
            'penalizaciones'   => $penalizaciones,
            'total_bono'       => $totalBono,
        ];

        OperarioBono::updateOrCreate(
            ['operario_id' => $operario->id, 'periodo_mes' => $mes, 'periodo_anio' => $anio],
            [
                'pasos_valor'    => $pasosValor,
                'hitos_valor'    => $hitosValor,
                'extras_valor'   => $extrasValor,
                'penalizaciones' => $penalizaciones,
                'total_bono'     => max(0, $totalBono),
                'calculado_at'   => now(),
                'detalle'        => $detalle,
            ]
        );

        // Aviso al colaborador de que su bono del mes fue calculado.
        if ($operario->user_id) {
            app(\App\Services\NotificacionService::class)->crear(
                $operario->user_id,
                'bono_calculado',
                'Tu bono del mes fue calculado',
                'Bono de ' . str_pad($mes, 2, '0', STR_PAD_LEFT) . "/{$anio}: $" . number_format(max(0, $totalBono), 0, ',', '.') . '.',
                '/mi-panel',
            );
        }

        return max(0, $totalBono);
    }

    public function misNotificaciones(): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        $operario = Operario::where('user_id', $user->id)->first();

        if (!$operario) return response()->json([]);

        $disciplinas = OperarioDisciplina::where('operario_id', $operario->id)
            ->where('firmado', false)
            ->get()
            ->map(fn ($d) => [
                'id'          => $d->id,
                'tipo'        => $d->tipo,
                'tipo_label'  => $d->tipoLabel(),
                'descripcion' => $d->descripcion,
                'fecha'       => $d->fecha?->format('d/m/Y'),
            ]);

        return response()->json($disciplinas);
    }

    public function puntos(Operario $operario): JsonResponse
    {
        $historial = PuntoColaborador::where('operario_id', $operario->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($p) => [
                'id'         => $p->id,
                'puntos'     => $p->puntos,
                'concepto'   => $p->concepto,
                'tipo'       => $p->tipo,
                'created_at' => $p->created_at?->format('d/m/Y H:i'),
            ]);

        return response()->json([
            'puntos_totales' => $operario->puntos_totales ?? 0,
            'nivel'          => $operario->nivel ?? 'Bronce',
            'historial'      => $historial,
        ]);
    }

    public function agregarPuntosManual(Request $request, Operario $operario): JsonResponse
    {
        $request->validate([
            'puntos'   => 'required|integer',
            'concepto' => 'required|string|max:255',
        ]);

        $svc = app(PuntosColaboradorService::class);
        $svc->registrar(
            $operario->id,
            $request->puntos,
            $request->concepto,
            'manual'
        );

        return response()->json([
            'ok'             => true,
            'puntos_totales' => $operario->fresh()->puntos_totales,
        ]);
    }
}
