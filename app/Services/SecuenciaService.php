<?php

namespace App\Services;

use App\Models\SecuenciaDocumento;
use App\Models\Sede;
use Illuminate\Support\Facades\DB;

/**
 * Punto único para generar los consecutivos de todos los documentos
 * (OPs, cotizaciones, remisiones, compras…).
 *
 * Reemplaza los cálculos que cada modelo hacía por su cuenta con
 * "max(numero)+1" o "count()+1", que además tenían el riesgo de entregar el
 * mismo número a dos documentos creados en el mismo instante. Aquí el número
 * se reserva dentro de una transacción con bloqueo de fila.
 */
class SecuenciaService
{
    /**
     * Entrega el siguiente código para un tipo de documento en una sede, y
     * deja el consecutivo listo para el siguiente.
     *
     * @param  string    $tipoDocumento  op | cotizacion | remision | ...
     * @param  int|null  $sedeId         null = usa la sede principal
     */
    public function siguiente(string $tipoDocumento, ?int $sedeId = null): string
    {
        $sedeId = $sedeId ?? Sede::principal()?->id;

        return DB::transaction(function () use ($tipoDocumento, $sedeId) {
            $secuencia = SecuenciaDocumento::where('tipo_documento', $tipoDocumento)
                ->where('sede_id', $sedeId)
                ->lockForUpdate()
                ->first();

            // Si la sede no tiene configurada esa secuencia todavía, se crea
            // sobre la marcha con valores por defecto, para no bloquear la
            // operación del usuario.
            if (!$secuencia) {
                $secuencia = SecuenciaDocumento::create([
                    'sede_id'          => $sedeId,
                    'tipo_documento'   => $tipoDocumento,
                    'prefijo'          => strtoupper(substr($tipoDocumento, 0, 3)) . '-',
                    'incluir_anio'     => false,
                    'siguiente_numero' => 1,
                    'padding'          => 4,
                ]);
            }

            $numero = $secuencia->siguiente_numero;
            $secuencia->increment('siguiente_numero');

            return $this->formatear($secuencia, $numero);
        });
    }

    /**
     * Arma el código sin consumir el consecutivo (para vistas previas).
     */
    public function previsualizar(string $tipoDocumento, ?int $sedeId = null): ?string
    {
        $sedeId = $sedeId ?? Sede::principal()?->id;

        $secuencia = SecuenciaDocumento::where('tipo_documento', $tipoDocumento)
            ->where('sede_id', $sedeId)
            ->first();

        return $secuencia ? $this->formatear($secuencia, $secuencia->siguiente_numero) : null;
    }

    private function formatear(SecuenciaDocumento $secuencia, int $numero): string
    {
        $anio = $secuencia->incluir_anio ? date('Y') . '-' : '';

        return $secuencia->prefijo . $anio . str_pad((string) $numero, $secuencia->padding, '0', STR_PAD_LEFT);
    }
}
