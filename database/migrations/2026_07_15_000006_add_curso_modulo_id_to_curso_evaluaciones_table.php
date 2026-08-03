<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Habilita evaluaciones por módulo (candado de avance), además de la
 * evaluación final del curso que ya existía.
 *
 * Regla de negocio (no se puede expresar como CHECK nativo en MySQL de forma
 * portable): exactamente uno de (curso_id, curso_modulo_id) debe estar
 * lleno, nunca ambos ni ninguno. Se valida a nivel de aplicación en
 * CursoEvaluacionController.
 *
 * Unicidad:
 * - curso_modulo_id: unique real (NULL no rompe unicidad en MySQL), una
 *   evaluación por módulo.
 * - curso_id: YA NO puede ser unique a nivel de columna porque ahora puede
 *   haber múltiples filas con curso_id NULL (evaluaciones de módulo). La
 *   unicidad "una evaluación final por curso" se valida en el controller
 *   (updateOrCreate sobre curso_id + whereNull('curso_modulo_id')).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('curso_evaluaciones', 'curso_modulo_id')) {
            // El índice unique de curso_id está ligado a su FK constraint;
            // MySQL no permite dropear uno sin el otro. Se quita la FK, se
            // quita el unique, se relaja la columna y se vuelve a crear la
            // FK (ya sin unique).
            Schema::table('curso_evaluaciones', function (Blueprint $table) {
                $table->dropForeign(['curso_id']);
            });

            Schema::table('curso_evaluaciones', function (Blueprint $table) {
                $table->dropUnique(['curso_id']);
            });

            // MODIFY vía SQL crudo: evita depender de doctrine/dbal (no instalado)
            // que Schema::table(...)->change() requeriría.
            DB::statement('ALTER TABLE curso_evaluaciones MODIFY curso_id BIGINT UNSIGNED NULL');

            Schema::table('curso_evaluaciones', function (Blueprint $table) {
                $table->foreign('curso_id')->references('id')->on('cursos')->cascadeOnDelete();
                $table->foreignId('curso_modulo_id')->nullable()->after('curso_id')
                    ->constrained('curso_modulos')->cascadeOnDelete();
                $table->unique('curso_modulo_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('curso_evaluaciones', 'curso_modulo_id')) {
            Schema::table('curso_evaluaciones', function (Blueprint $table) {
                $table->dropConstrainedForeignId('curso_modulo_id');
                $table->dropForeign(['curso_id']);
            });

            DB::statement('ALTER TABLE curso_evaluaciones MODIFY curso_id BIGINT UNSIGNED NOT NULL');

            Schema::table('curso_evaluaciones', function (Blueprint $table) {
                $table->foreign('curso_id')->references('id')->on('cursos')->cascadeOnDelete();
                $table->unique('curso_id');
            });
        }
    }
};
