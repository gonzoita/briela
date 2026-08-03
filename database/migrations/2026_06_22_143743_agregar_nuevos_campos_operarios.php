<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operarios')) {
            Schema::table('operarios', function (Blueprint $table) {
                if (!Schema::hasColumn('operarios', 'tipo_colaborador_id')) {
                    $table->unsignedBigInteger('tipo_colaborador_id')->nullable()->after('id');
                }
                if (!Schema::hasColumn('operarios', 'cargo')) {
                    $table->string('cargo')->nullable()->after('especialidad');
                }
                if (!Schema::hasColumn('operarios', 'fecha_nacimiento')) {
                    $table->date('fecha_nacimiento')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'fecha_ingreso')) {
                    $table->date('fecha_ingreso')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'direccion')) {
                    $table->string('direccion')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'ciudad')) {
                    $table->string('ciudad')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'email')) {
                    $table->string('email')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'numero_eps')) {
                    $table->string('numero_eps')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'nombre_eps')) {
                    $table->string('nombre_eps')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'numero_pension')) {
                    $table->string('numero_pension')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'nombre_pension')) {
                    $table->string('nombre_pension')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'numero_cuenta_bancaria')) {
                    $table->string('numero_cuenta_bancaria')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'banco')) {
                    $table->string('banco')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'tipo_cuenta')) {
                    $table->string('tipo_cuenta')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'archivo_hoja_vida')) {
                    $table->string('archivo_hoja_vida')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'archivo_cedula')) {
                    $table->string('archivo_cedula')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'archivo_eps')) {
                    $table->string('archivo_eps')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'archivo_pension')) {
                    $table->string('archivo_pension')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'archivo_antecedentes')) {
                    $table->string('archivo_antecedentes')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'archivo_certificacion_bancaria')) {
                    $table->string('archivo_certificacion_bancaria')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'archivo_otros')) {
                    $table->json('archivo_otros')->nullable();
                }
                if (!Schema::hasColumn('operarios', 'puntos_totales')) {
                    $table->integer('puntos_totales')->default(0);
                }
                if (!Schema::hasColumn('operarios', 'nivel')) {
                    $table->string('nivel')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('operarios', function (Blueprint $table) {
            $cols = [
                'tipo_colaborador_id','cargo','fecha_nacimiento','fecha_ingreso',
                'direccion','ciudad','email','numero_eps','nombre_eps',
                'numero_pension','nombre_pension','numero_cuenta_bancaria','banco',
                'tipo_cuenta','archivo_hoja_vida','archivo_cedula','archivo_eps',
                'archivo_pension','archivo_antecedentes','archivo_certificacion_bancaria',
                'archivo_otros','puntos_totales','nivel',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('operarios', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
