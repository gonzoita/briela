<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `archivos.categoria` es un ENUM de MySQL: hay que ampliarlo para guardar con
 * `categoria='bandeja'` lo que llega y sale por WhatsApp y por las redes.
 *
 * Se marcan aparte —en vez de en 'otro'— por lo mismo que se marcaron los del chat: una foto
 * que un cliente mandó por WhatsApp no es un documento de una OP, y mezclarlas ensucia
 * Multimedia.
 *
 * **De paso se devuelve `'ia'` a la lista.** La migración del chat (ago 2026) reescribió el
 * ENUM completo y la dejó fuera sin querer, así que desde entonces guardar un archivo de la IA
 * falla en MySQL estricto. Reponerla es aditivo y no toca ninguna fila.
 */
return new class extends Migration
{
    private const VALORES = "'plano','foto_calidad','documento','otro','rrss','chat','ia','bandeja'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // El ENUM es propio de MySQL; en otro motor la columna ya es de texto.
        }

        DB::statement('ALTER TABLE archivos MODIFY categoria ENUM(' . self::VALORES . ") NOT NULL DEFAULT 'otro'");
    }

    public function down(): void
    {
        // A propósito vacío: estrechar el ENUM obligaría a reescribir las filas que usan los
        // valores nuevos, y eso es borrar datos de un cliente al que no tenemos acceso.
    }
};
