<?php

namespace App\Http\Controllers;

use App\Services\ActualizadorService;
use App\Services\LicenciaService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La pantalla de actualización y sus pasos.
 *
 * Cada paso es una petición aparte, igual que en el instalador y por el mismo motivo:
 * copiar 43.000 archivos no cabe en el límite de ejecución de un hosting compartido.
 * El navegador va llamando y mostrando el avance, y si una tanda falla, el proceso se
 * detiene ahí con un mensaje concreto en vez de morir con un error 500 en blanco.
 */
class ActualizacionController extends Controller
{
    public function __construct(
        private ActualizadorService $actualizador,
        private LicenciaService $licencias,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Administracion/Actualizacion', [
            'licencia'    => $this->licencias->paraInterfaz(),
            'comprobacion'=> $this->actualizador->comprobar(),
            'en_curso'    => $this->actualizador->hayProcesoEmpezado(),
            'progreso'    => $this->actualizador->estado(),
        ]);
    }

    /** Fuerza la consulta al servidor, para el botón de "comprobar ahora". */
    public function comprobar(): JsonResponse
    {
        $estado = $this->licencias->refrescar();

        return response()->json([
            'ok'            => true,
            'licencia'      => $this->licencias->paraInterfaz(),
            'actualizacion' => $estado['actualizacion'] ?? null,
        ]);
    }

    public function descargar(): JsonResponse
    {
        $nueva = $this->licencias->actualizacionDisponible();

        if ($nueva === null) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'No hay ninguna versión nueva para esta instalación.',
            ], 422);
        }

        return response()->json($this->actualizador->descargar($nueva['version']));
    }

    public function respaldar(): JsonResponse
    {
        return response()->json($this->actualizador->respaldar());
    }

    public function extraer(): JsonResponse
    {
        return response()->json($this->actualizador->extraerTanda());
    }

    public function copiar(): JsonResponse
    {
        return response()->json($this->actualizador->copiarTanda());
    }

    public function finalizar(): JsonResponse
    {
        return response()->json($this->actualizador->finalizar());
    }

    /** Descarta un proceso a medias para poder empezar de nuevo. */
    public function cancelar(): JsonResponse
    {
        $this->actualizador->limpiar();

        return response()->json(['ok' => true]);
    }

    /** Guarda el serial que entrega Briela. */
    public function guardarSerial(\Illuminate\Http\Request $request): JsonResponse
    {
        $datos = $request->validate([
            'serial' => ['required', 'string', 'max:40'],
        ]);

        $this->licencias->guardarSerial($datos['serial']);
        $estado = $this->licencias->refrescar();

        return response()->json([
            'ok'       => ($estado['valido'] ?? false) === true,
            'licencia' => $this->licencias->paraInterfaz(),
            'mensaje'  => ($estado['valido'] ?? false)
                ? 'Serial verificado.'
                : ($estado['mensaje'] ?? 'No se pudo verificar el serial.'),
        ]);
    }
}
