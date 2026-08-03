<?php

namespace App\Services;

use App\Models\Certificado;
use App\Models\CursoEvaluacion;
use App\Models\EvaluacionIntento;
use App\Models\EvaluacionPregunta;
use App\Models\Inscripcion;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class EvaluacionService
{
    public function calificar(EvaluacionIntento $intento): void
    {
        $evaluacion = $intento->evaluacion()->with('preguntas.opciones')->first();
        $preguntas  = $evaluacion->preguntas;

        if ($preguntas->contains(fn ($p) => $p->tipo === 'abierta')) {
            $intento->update([
                'nota'     => null,
                'aprobado' => null,
                'estado'   => 'pendiente_revision',
            ]);

            // Aviso: hay una evaluación con preguntas abiertas por calificar.
            app(NotificacionService::class)->paraRol(
                ['administrador', 'jefe_produccion'],
                'evaluacion_por_revisar',
                'Evaluación por calificar',
                'Un intento con preguntas abiertas espera revisión.',
                '/revision-evaluaciones',
            );

            return;
        }

        $correctas = $this->contarCorrectasOpcionMultiple($intento, $preguntas);
        $nota      = $preguntas->count() > 0 ? round(($correctas / $preguntas->count()) * 100, 2) : 0;
        $aprobado  = $nota >= $evaluacion->nota_minima_aprobacion;

        $intento->update([
            'nota'     => $nota,
            'aprobado' => $aprobado,
            'estado'   => 'calificado',
        ]);

        if ($aprobado) {
            $this->procesarAprobacion($intento->fresh());
        }
    }

    public function calificarManual(EvaluacionIntento $intento, array $notasAbiertas, int $revisorId): void
    {
        $evaluacion = $intento->evaluacion()->with('preguntas.opciones')->first();
        $preguntas  = $evaluacion->preguntas;

        $correctasMultiple = $this->contarCorrectasOpcionMultiple($intento, $preguntas);
        $correctasAbiertas = collect($notasAbiertas)->filter(fn ($n) => (bool) ($n['correcta'] ?? false))->count();

        $total     = $preguntas->count();
        $nota      = $total > 0 ? round((($correctasMultiple + $correctasAbiertas) / $total) * 100, 2) : 0;
        $aprobado  = $nota >= $evaluacion->nota_minima_aprobacion;

        $intento->update([
            'nota'         => $nota,
            'aprobado'     => $aprobado,
            'estado'       => 'calificado',
            'revisado_por' => $revisorId,
            'revisado_at'  => now(),
        ]);

        if ($aprobado) {
            $this->procesarAprobacion($intento->fresh());
        }
    }

    public function procesarAprobacion(EvaluacionIntento $intento): void
    {
        if (! $intento->evaluacion->esFinal()) {
            // Evaluación de módulo: aprobarla solo desbloquea el siguiente
            // módulo (efecto automático de Inscripcion::moduloDesbloqueado()).
            // No genera certificado, no otorga puntos, no cambia el estado
            // de la inscripción — eso sigue dependiendo solo de la
            // evaluación final del curso.
            return;
        }

        $inscripcion = $intento->inscripcion()->with(['curso', 'inscribible'])->first();

        if (! in_array($inscripcion->estado, ['completado', 'aprobado'])) {
            $inscripcion->marcarCompletado();
        }

        $inscripcion->update(['estado' => 'aprobado']);

        if (! $inscripcion->certificado) {
            $this->generarCertificado($inscripcion);

            // Aviso al colaborador interno de que ya tiene su certificado.
            // (Los estudiantes externos no tienen campanita en el sistema.)
            if ($inscripcion->inscribible instanceof User) {
                app(NotificacionService::class)->crear(
                    $inscripcion->inscribible->id,
                    'certificado_emitido',
                    'Tu certificado está listo',
                    "Aprobaste el curso \"{$inscripcion->curso->titulo}\". Ya puedes descargar tu certificado.",
                    '/mi-capacitacion',
                );
            }
        }

        if ($inscripcion->inscribible instanceof User && $inscripcion->curso->puntos_otorga > 0) {
            $operario = $inscripcion->inscribible->operario;

            if ($operario) {
                app(PuntosColaboradorService::class)->registrar(
                    $operario->id,
                    $inscripcion->curso->puntos_otorga,
                    "Curso completado: {$inscripcion->curso->titulo}",
                    'capacitacion'
                );
            } else {
                Log::warning("Colaborador {$inscripcion->inscribible->id} aprobó el curso '{$inscripcion->curso->titulo}' pero no tiene registro de Operario asociado; no se otorgaron puntos.");
            }
        }
    }

    private function contarCorrectasOpcionMultiple(EvaluacionIntento $intento, Collection $preguntas): int
    {
        $respuestas = collect($intento->respuestas)->keyBy('pregunta_id');
        $correctas  = 0;

        foreach ($preguntas->where('tipo', 'opcion_multiple') as $pregunta) {
            $respuesta = $respuestas->get($pregunta->id);
            if (! $respuesta) continue;

            $opcionCorrecta = $pregunta->opciones->firstWhere('es_correcta', true);

            if ($opcionCorrecta && (int) ($respuesta['opcion_id'] ?? 0) === $opcionCorrecta->id) {
                $correctas++;
            }
        }

        return $correctas;
    }

    private function generarCertificado(Inscripcion $inscripcion): Certificado
    {
        $codigo = Certificado::generarCodigo();

        $certificado = Certificado::create([
            'inscripcion_id'      => $inscripcion->id,
            'codigo_verificacion' => $codigo,
            'emitido_at'          => now(),
        ]);

        $estudiante       = $inscripcion->inscribible;
        $nombreEstudiante = $estudiante->name ?? $estudiante->nombre;

        // El QR apunta a la página pública de verificación (no al código
        // suelto), para que quien lo escanee llegue directo a confirmar que
        // el certificado es auténtico.
        $urlVerificacion = url("/verificar-certificado/{$codigo}");

        $qrBase64 = 'data:image/svg+xml;base64,' . base64_encode(
            QrCode::format('svg')->size(100)->generate($urlVerificacion)
        );

        $pdf = Pdf::loadView('pdf.certificado_capacitacion', [
            'nombreEstudiante'   => $nombreEstudiante,
            'nombreCurso'        => $inscripcion->curso->titulo,
            'fechaEmision'       => now()->format('d/m/Y'),
            'codigoVerificacion' => $codigo,
            'urlVerificacion'    => $urlVerificacion,
            'qrBase64'           => $qrBase64,
            'logoPath'           => public_path('img/logo-interfrigo.png'),
        ])->setPaper('a4', 'landscape');

        $path = "certificados/{$codigo}.pdf";
        Storage::disk('public')->put($path, $pdf->output());

        $certificado->update(['pdf_path' => $path]);

        return $certificado;
    }
}
