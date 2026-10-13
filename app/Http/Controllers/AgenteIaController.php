<?php

namespace App\Http\Controllers;

use App\Models\AgenteIa;
use App\Models\CrmLead;
use App\Services\Bandeja\BandejaService;
use App\Services\IA\ConsultasClienteService;
use App\Services\IA\ConsultasPublicasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use App\Support\ContextoSede;
use App\Support\Modulos;
use Inertia\Response;

/**
 * Los agentes que atienden por fuera.
 *
 * Lo que se configura aquí no es «un chatbot»: es a quién atiende cada agente y qué puede ver.
 * El campo `perfil` decide el catálogo de consultas, y por eso las herramientas que ofrece la
 * pantalla cambian con él — un agente público no puede siquiera elegir «cartera del cliente».
 */
class AgenteIaController extends Controller
{
    public function index(ConsultasPublicasService $publicas, ConsultasClienteService $deCliente): Response
    {
        $usuario = auth()->user();

        return Inertia::render('Agentes/Index', [
            // La pestaña «Equipo interno» (agentes que trabajan para la empresa). Apagado el
            // módulo no existe; encendido, cada botón depende de su permiso.
            'equipo' => Modulos::activo('equipo_ia') && $usuario?->tienePermiso('equipo_ia.ver') ? [
                'ver'       => true,
                'ejecutar'  => $usuario->tienePermiso('equipo_ia.ejecutar'),
                'aprobar'   => $usuario->tienePermiso('equipo_ia.aprobar'),
                'gestionar' => $usuario->tienePermiso('equipo_ia.gestionar'),
                'consumo'   => $usuario->tienePermiso('equipo_ia.consumo'),
            ] : null,
            'agentes' => AgenteIa::orderBy('orden')->orderBy('id')->get()
                ->map(fn ($a) => [
                    'id'            => $a->id,
                    'nombre'        => $a->nombre,
                    'descripcion'   => $a->descripcion,
                    'activo'        => $a->activo,
                    'perfil'        => $a->perfil,
                    'canales'       => $a->canales ?? [],
                    'herramientas'  => $a->herramientas ?? [],
                    'instrucciones' => $a->instrucciones,
                    'saludo'        => $a->saludo,
                    'escalamiento'  => $a->escalamiento ?? [],
                    'horario'       => $a->horario ?? ['desde' => '', 'hasta' => ''],
                ]),
            // Qué puede consultar cada perfil. La pantalla las ofrece; el servidor las valida
            // contra estas mismas listas.
            'herramientasPorPerfil' => [
                'publico' => collect($publicas->disponibles())->map(fn ($d, $k) => ['clave' => $k, 'label' => $d['descripcion']])->values(),
                'cliente' => collect($deCliente->disponibles())->map(fn ($d, $k) => ['clave' => $k, 'label' => $d['descripcion']])->values(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        AgenteIa::create($this->validar($request) + [
            'creado_por' => auth()->id(),
            'orden'      => (int) AgenteIa::max('orden') + 1,
        ]);

        return back()->with('success', 'Agente creado.');
    }

    /**
     * La recepción en vivo: quién atiende y quién está llegando.
     *
     * Las personas que «llegan» son las conversaciones activas de la bandeja que se movieron en
     * las últimas 24 horas, con la MISMA consulta de la bandeja (`BandejaService::lista`), así que
     * respetan sus permisos y su sede. Sin `bandeja.ver` no se muestra a nadie por su nombre: la
     * recepción se ve, pero vacía, y el tablero solo cuenta agentes.
     *
     * Del cliente va solo el primer nombre y la inicial: esta pantalla está pensada para dejarla
     * en un televisor, y ahí un nombre completo con su mensaje es un dato de más.
     */
    public function recepcion(BandejaService $bandeja): JsonResponse
    {
        $usuario = auth()->user();

        $agentes = AgenteIa::orderBy('orden')->orderBy('id')->get()->map(fn (AgenteIa $a) => [
            'id'        => $a->id,
            'nombre'    => $a->nombre,
            'activo'    => (bool) $a->activo,
            'canales'   => $a->canales ?? [],
            'en_horario'=> $a->enHorario(),
        ])->values();

        $puedeVer = Modulos::activo('bandeja') && $usuario?->tienePermiso('bandeja.ver');
        $clientes = [];
        $tablero = ['agentes_activos' => $agentes->where('activo', true)->count(), 'activas' => null, 'sin_leer' => null, 'sin_asignar' => null, 'leads_hoy' => null];

        if ($puedeVer) {
            $desde = now()->subDay();

            $clientes = collect($bandeja->lista(['estado' => 'activas'], $usuario)->items())
                ->filter(fn ($c) => $c['ultimo_mensaje_at'] && Carbon::parse($c['ultimo_mensaje_at'])->gte($desde))
                ->take(12)
                ->map(fn ($c) => [
                    'clave'     => $c['clave'],
                    'red'       => $this->redDe($c['canal']),
                    'canal'     => $c['canal_label'],
                    'nombre'    => $this->nombreCorto((string) $c['contacto']),
                    // Esperando = lo último lo escribió la persona y nadie le ha contestado.
                    'esperando' => ! str_starts_with((string) $c['ultimo_texto'], 'Tú:'),
                    'humano'    => $c['asignado_a'] !== null,
                    'escalada'  => $c['escalada'],
                    'cuando'    => Carbon::parse($c['ultimo_mensaje_at'])->toIso8601String(),
                    'texto'     => Str::limit((string) $c['ultimo_texto'], 70),
                ])->values()->all();

            $cuentas = $bandeja->contadores($usuario);
            $tablero['activas']     = $cuentas['total'];
            $tablero['sin_leer']    = $cuentas['sin_leer'];
            $tablero['sin_asignar'] = $cuentas['sin_asignar'];
        }

        if ($usuario?->tienePermiso('crm.ver')) {
            $tablero['leads_hoy'] = ContextoSede::aplicar(CrmLead::query())
                ->where('created_at', '>=', today())->count();
        }

        return response()->json([
            'agentes'         => $agentes,
            'clientes'        => $clientes,
            'puede_ver'       => $puedeVer,
            'tablero'         => $tablero,
        ]);
    }

    /** La red de un canal de la bandeja, en el idioma de los agentes de atención. */
    private function redDe(string $canal): string
    {
        return match (true) {
            str_starts_with($canal, 'instagram') => 'instagram',
            str_starts_with($canal, 'facebook')  => 'facebook',
            $canal === 'whatsapp'                => 'whatsapp',
            default                              => 'web',
        };
    }

    /** «Marta Ruiz López» → «Marta R.» */
    private function nombreCorto(string $nombre): string
    {
        $partes = preg_split('/\s+/', trim($nombre)) ?: [];
        if ($partes === [] || $partes[0] === '' || preg_match('/^\+?\d[\d\s]*$/', $nombre)) {
            return 'Cliente';
        }

        return $partes[0] . (isset($partes[1]) ? ' ' . mb_substr($partes[1], 0, 1) . '.' : '');
    }

    public function update(Request $request, AgenteIa $agente): RedirectResponse
    {
        $agente->update($this->validar($request));

        return back()->with('success', 'Agente actualizado.');
    }

    public function destroy(AgenteIa $agente): RedirectResponse
    {
        $agente->delete();

        return back()->with('success', 'Agente eliminado.');
    }

    /**
     * Valida contra los catálogos, no contra una lista escrita a mano.
     *
     * Una herramienta que no exista en el perfil elegido se descarta en silencio: no es un error
     * del usuario, es una clave que el catálogo de ese perfil no reconoce. Y es la línea que
     * impide que un agente público termine con permiso de leer cartera.
     */
    private function validar(Request $request): array
    {
        $datos = $request->validate([
            'nombre'          => 'required|string|max:120',
            'descripcion'     => 'nullable|string|max:300',
            'activo'          => 'boolean',
            'perfil'          => 'required|in:publico,cliente',
            'canales'         => 'nullable|array',
            'canales.*'       => 'in:web,whatsapp,instagram,facebook',
            'herramientas'    => 'nullable|array',
            'herramientas.*'  => 'string|max:40',
            'instrucciones'   => 'nullable|string|max:8000',
            'saludo'          => 'nullable|string|max:500',
            'escalamiento'    => 'nullable|array',
            'escalamiento.*'  => 'in:lo_pide,no_sabe,fuera_horario,asesor_asignado',
            'horario'         => 'nullable|array',
            'horario.desde'   => 'nullable|date_format:H:i',
            'horario.hasta'   => 'nullable|date_format:H:i',
        ]);

        $permitidas = $datos['perfil'] === 'cliente'
            ? array_keys(app(ConsultasClienteService::class)->disponibles())
            : array_keys(app(ConsultasPublicasService::class)->disponibles());

        $datos['herramientas'] = array_values(array_intersect($datos['herramientas'] ?? [], $permitidas));

        return $datos;
    }
}
