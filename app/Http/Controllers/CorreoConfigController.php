<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\CorreoSupresion;
use App\Services\LicenciaService;
use App\Services\SmtpConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración → Correo: por dónde salen los correos y cómo va el mes.
 *
 * Aquí no se escribe ninguna credencial: el correo sale por el panel de Briela, que tiene
 * la del proveedor, y el dominio de envío se configura allá. El SMTP propio, si existe,
 * queda de respaldo (se configura en Configuración, en «Correo saliente»).
 */
class CorreoConfigController extends Controller
{
    public function index(LicenciaService $licencias): Response
    {
        return Inertia::render('Configuracion/Correo', [
            'correo'        => $licencias->correo(),
            'por_briela'    => $licencias->correoPorBriela(),
            'tiene_serial'  => $licencias->serial() !== null,
            'smtp_respaldo' => (string) Configuracion::get('smtp_host', '') !== '',
            'supresiones'   => [
                'total'   => CorreoSupresion::count(),
                'ultimas' => CorreoSupresion::latest()->limit(10)->get(['email', 'motivo', 'created_at']),
            ],
        ]);
    }

    /** Vuelve a preguntar al panel, para no esperar el próximo latido. */
    public function actualizar(LicenciaService $licencias): RedirectResponse
    {
        $licencias->refrescar();

        return back()->with('success', $licencias->correoPorBriela()
            ? 'El correo sale por Briela.'
            : 'Estado actualizado. El correo de esta instalación todavía no sale por Briela.');
    }

    public function probar(Request $request, LicenciaService $licencias): JsonResponse
    {
        $datos = $request->validate(['email' => 'required|email|max:150']);

        $r = SmtpConfigService::probar($datos['email']);
        $r['via'] = $licencias->correoPorBriela() ? 'Briela' : 'SMTP';

        return response()->json($r, $r['ok'] ? 200 : 422);
    }
}
