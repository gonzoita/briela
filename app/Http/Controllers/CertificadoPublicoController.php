<?php

namespace App\Http\Controllers;

use App\Models\Certificado;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Verificación pública de certificados de capacitación. Cualquiera (un
// cliente, un empleador) puede confirmar si un certificado es real,
// escaneando su QR o escribiendo el código — sin login. Antes el
// certificado traía un código y un QR, pero no existía dónde verificarlo,
// así que no servía como prueba anti-falsificación.
class CertificadoPublicoController extends Controller
{
    public function verificar(Request $request, ?string $codigo = null): Response
    {
        $codigo = trim($codigo ?? $request->query('codigo', ''));

        $certificado = $codigo
            ? Certificado::where('codigo_verificacion', $codigo)
                ->with(['inscripcion.curso', 'inscripcion.inscribible'])
                ->first()
            : null;

        $datos = null;
        if ($certificado) {
            $estudiante = $certificado->inscripcion?->inscribible;
            $datos = [
                'valido'       => true,
                'codigo'       => $certificado->codigo_verificacion,
                'estudiante'   => $estudiante->name ?? $estudiante->nombre ?? '—',
                'curso'        => $certificado->inscripcion?->curso?->titulo ?? '—',
                'fecha_emision'=> $certificado->emitido_at?->format('d/m/Y'),
            ];
        }

        return Inertia::render('Certificados/Verificar', [
            'codigoBuscado' => $codigo,
            'certificado'   => $datos,
            'noEncontrado'  => $codigo !== '' && $datos === null,
        ]);
    }
}
