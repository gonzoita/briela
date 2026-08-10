<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user('web');

        // ─── Multi-sede ───────────────────────────────────────────────────────
        // La sede activa vive en la sesión y la resuelve ContextoSede, para que
        // el encabezado muestre exactamente lo mismo que filtran los módulos.
        $sedes        = $user ? $user->sedesAccesibles() : collect();
        $sedeActivaId = $user ? \App\Support\ContextoSede::id() : null;
        $viendoTodas  = $user && $sedeActivaId === null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id'         => $user->id,
                    'name'       => $user->name,
                    'email'      => $user->email,
                    'rol'        => $user->rol,
                    'rol_nombre' => $user->rolConfigurable?->nombre,
                    'sede_id'    => $user->sede_id,
                    'activo'     => $user->activo,
                ] : null,
                'permisos' => $user ? [
                    'esAdmin'               => $user->esAdmin(),
                    'puedeVerTodasOps'      => $user->puedeVerTodasOps(),
                    'puedeCrearOps'         => $user->puedeCrearOps(),
                    'puedeVerificarOps'     => $user->puedeVerificarOps(),
                    'puedeActualizarLineas' => $user->puedeActualizarLineas(),
                    'puedeVerTodasLasSedes' => $user->puedeVerTodasLasSedes(),
                ] : null,
                // Lista de permisos finos ("clientes.ver", ...) con la que el
                // menú decide qué mostrar.
                'permisosLista' => $user ? $user->permisos() : [],
            ],
            // La hora de la sede en la que se está trabajando. Con esto el modo
            // automático decide día o noche por la hora de la sede y no por la del
            // computador de quien mira, que puede estar en otro huso.
            'hora' => [
                'sede'        => (int) \App\Support\HoraSistema::ahoraEnSedeActiva()->format('G'),
                'zona_sede'   => \App\Support\HoraSistema::zonaSedeActiva(),
                'zona_global' => \App\Support\HoraSistema::zonaGlobal(),
            ],
            'sedes' => [
                'disponibles' => $sedes->map(fn ($s) => [
                    'id'     => $s->id,
                    'nombre' => $s->nombre,
                    'codigo' => $s->codigo,
                ])->values(),
                // 0 en el selector = "Todas las sedes"
                'activa_id'    => $viendoTodas ? 0 : $sedeActivaId,
                'viendo_todas' => $viendoTodas,
                'puede_todas'  => $user ? $user->puedeVerTodasLasSedes() : false,
            ],
            // Identidad visual, para que el logo del encabezado y del menú
            // salgan de Ajustes en vez de estar quemados en el código.
            'marca' => [
                'nombre' => \App\Support\Marca::nombreEmpresa(),
                'logo'   => \App\Support\Marca::logoUrl(),
                // Si la empresa subió su logo o si se está viendo el de fábrica.
                // Las pantallas públicas prefieren mostrar el nombre en
                // tipografía antes que un icono genérico que no es de nadie.
                'logo_propio' => \App\Services\ImagenMarcaService::url('empresa_logo') !== null,
                // Versión para el modo de noche: un logo con texto oscuro se
                // pierde sobre fondo oscuro, y eso no se arregla con CSS.
                'logo_oscuro' => \App\Support\Marca::logoOscuroUrl(),
                'color'  => \App\Support\Marca::color(),
                // Para los pies de página de las pantallas públicas (catálogo,
                // seguimiento, aprobación de cotizaciones): antes llevaban el
                // correo y el dominio escritos a mano.
                'email'  => \App\Models\Configuracion::get('empresa_email', ''),
                'web'    => \App\Models\Configuracion::get('empresa_web', ''),
            ],
            // Nombre del asistente de IA, para el menú, y si usa voz natural.
            'asistente' => [
                'nombre'      => \App\Models\Configuracion::get('ia_asistente_nombre', 'Asistente'),
                'voz_natural' => \App\Models\Configuracion::get('ia_voz_natural', '0') === '1',
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
