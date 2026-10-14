<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `archivos.categoria` es un ENUM de MySQL: hay que ampliarlo para guardar las fichas técnicas
 * de los productos con `categoria='ficha_tecnica'`. Los planos ya tenían su valor (`plano`).
 *
 * Se repite la lista completa —con `ia` y `bandeja`—: una migración que reescribe el ENUM y
 * olvida un valor deja sin poder guardar a quien lo usa, como ya pasó con `ia`.
 */
return new class extends Migration
{
    private const VALORES = "'plano','foto_calidad','documento','otro','rrss','chat','ia','bandeja','ficha_tecnica'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // El ENUM es propio de MySQL; en otro motor la columna ya es de texto.
        }

        DB::statement('ALTER TABLE archivos MODIFY categoria ENUM(' . self::VALORES . ") NOT NULL DEFAULT 'otro'");
    }

    public function down(): void
    {
        // A propósito vacío: estrechar el ENUM obligaría a reescribir las filas que usan el valor
        // nuevo, y eso es borrar datos de un cliente al que no tenemos acceso.
    }
};
