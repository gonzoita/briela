<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackupController extends Controller
{
    public function __construct(private BackupService $backups)
    {
    }

    public function index()
    {
        $ultimoAuto = $this->backups->ultimoAutomatico();

        return inertia('Administracion/Backup/Index', [
            'backups' => collect($this->backups->listar())->map(fn ($b) => [
                'filename'   => $b['nombre'],
                'size'       => BackupService::formatearBytes($b['bytes']),
                'fecha'      => date('d/m/Y H:i', $b['timestamp']),
                'timestamp'  => $b['timestamp'],
                'automatico' => $b['automatico'],
            ]),
            'db_size'     => $this->backups->tamanoBaseDatos(),
            // La pantalla mostraba el nombre de la base escrito a mano, así que
            // en cualquier instalación decía el de otra empresa.
            'db_nombre'   => config('database.connections.mysql.database'),
            'diagnostico' => $this->backups->diagnostico(),
            'automatico' => [
                'hora'      => '2:00 a.m.',
                'retencion' => BackupService::DIAS_RETENCION,
                'ultimo'    => $ultimoAuto ? [
                    'fecha' => date('d/m/Y H:i', $ultimoAuto['timestamp']),
                    'hace_horas' => (int) round((time() - $ultimoAuto['timestamp']) / 3600),
                ] : null,
            ],
            // Respaldos que pidió el soporte de Briela desde su panel. Normalmente los
            // ejecuta el cron y nadie los ve aquí; se muestran porque si el hosting no
            // tiene cron, esta pantalla es la única forma de que se hagan.
            'pedidos_briela' => collect(app(\App\Services\OrdenesBrielaService::class)->pendientes())
                ->map(fn ($o) => [
                    'tipo'    => $o['tipo'] ?? '',
                    'recibida' => isset($o['recibida_at'])
                        ? date('d/m/Y H:i', strtotime($o['recibida_at']))
                        : null,
                ])->values(),
        ]);
    }

    /**
     * Ejecuta ahora los respaldos que pidió el soporte de Briela.
     *
     * Existe para el hosting sin cron: sin esto, un respaldo pedido desde el panel se
     * quedaría esperando para siempre y nadie sabría por qué. El resultado se le informa
     * a Briela igual que si lo hubiera hecho la tarea programada.
     */
    public function ejecutarPedidos(\App\Services\OrdenesBrielaService $ordenes)
    {
        $resultados = $ordenes->ejecutarPendientes();

        if ($resultados === []) {
            return redirect('/administracion/backup')->with('error', 'No hay respaldos pedidos por Briela.');
        }

        return redirect('/administracion/backup')->with('success', implode(' · ', $resultados));
    }

    /**
     * Genera el respaldo y lo descarga.
     *
     * El try/catch existe porque antes cualquier problema del servidor
     * (mysqldump bloqueado, sin permisos, sin memoria) salía como una página
     * blanca con "500 Server Error", que no le dice nada a nadie. Ahora
     * devuelve a la pantalla con el motivo real.
     */
    public function descargar()
    {
        try {
            $resultado = $this->backups->generar('manual');
        } catch (\Throwable $e) {
            Log::error('Falló el respaldo manual: ' . $e->getMessage());

            return redirect('/administracion/backup')
                ->with('error', 'No se pudo generar el respaldo: ' . $e->getMessage());
        }

        return response()->download($resultado['ruta'], $resultado['nombre'], [
            'Content-Type' => 'application/sql',
        ]);
    }

    public function restaurar(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|max:51200',
        ]);

        if (strtolower($request->file('archivo')->getClientOriginalExtension()) !== 'sql') {
            return back()->withErrors(['archivo' => 'Solo se permiten archivos .sql']);
        }

        $sql = file_get_contents($request->file('archivo')->getRealPath());

        if (strlen($sql) < 50) {
            return back()->withErrors(['archivo' => 'El archivo SQL parece estar vacío o corrupto.']);
        }

        // Antes de sobrescribir todo, se guarda cómo está la base ahora mismo.
        // Restaurar un archivo equivocado sin poder volver atrás es la forma
        // más rápida de perder la operación de un día completo.
        try {
            $previo = $this->backups->generar('antes-de-restaurar');
        } catch (\Throwable $e) {
            return back()->withErrors([
                'archivo' => 'No se pudo respaldar el estado actual antes de restaurar, así que se canceló por seguridad: ' . $e->getMessage(),
            ]);
        }

        try {
            DB::unprepared($sql);

            return back()->with('success', "Base de datos restaurada. El estado anterior quedó guardado como {$previo['nombre']}.");
        } catch (\Exception $e) {
            return back()->withErrors([
                'archivo' => 'Error al restaurar: ' . $e->getMessage() . ". El estado anterior está en {$previo['nombre']}.",
            ]);
        }
    }

    public function eliminarBackup(Request $request)
    {
        $request->validate(['filename' => 'required|string']);

        $ruta = $this->backups->carpeta() . DIRECTORY_SEPARATOR . basename($request->filename);

        if (file_exists($ruta)) {
            unlink($ruta);
        }

        return back()->with('success', 'Backup eliminado.');
    }
}
