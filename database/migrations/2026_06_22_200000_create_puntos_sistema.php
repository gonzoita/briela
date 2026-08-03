<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla niveles
        if (!Schema::hasTable('niveles_colaborador')) {
            Schema::create('niveles_colaborador', function (Blueprint $table) {
                $table->id();
                $table->string('nombre');
                $table->string('color', 7)->default('#6B7280');
                $table->string('icono')->nullable();
                $table->integer('puntos_minimos')->default(0);
                $table->integer('puntos_maximos')->nullable();
                $table->integer('orden')->default(0);
                $table->timestamps();
            });

            DB::table('niveles_colaborador')->insert([
                ['nombre' => 'Bronce',   'color' => '#CD7F32', 'icono' => '🥉', 'puntos_minimos' => 0,    'puntos_maximos' => 500,  'orden' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['nombre' => 'Plata',    'color' => '#C0C0C0', 'icono' => '🥈', 'puntos_minimos' => 501,  'puntos_maximos' => 1500, 'orden' => 2, 'created_at' => now(), 'updated_at' => now()],
                ['nombre' => 'Oro',      'color' => '#FFD700', 'icono' => '🥇', 'puntos_minimos' => 1501, 'puntos_maximos' => 3000, 'orden' => 3, 'created_at' => now(), 'updated_at' => now()],
                ['nombre' => 'Diamante', 'color' => '#00BFFF', 'icono' => '💎', 'puntos_minimos' => 3001, 'puntos_maximos' => null,  'orden' => 4, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        // Tabla historial de puntos
        if (!Schema::hasTable('puntos_colaborador')) {
            Schema::create('puntos_colaborador', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('operario_id');
                $table->integer('puntos');
                $table->string('concepto');
                $table->string('tipo')->default('produccion');
                $table->unsignedBigInteger('op_item_trabajo_paso_id')->nullable();
                $table->unsignedBigInteger('registrado_por')->nullable();
                $table->timestamps();

                $table->foreign('operario_id')->references('id')->on('operarios')->onDelete('cascade');
                $table->foreign('op_item_trabajo_paso_id')->references('id')->on('op_item_trabajo_pasos')->onDelete('set null');
                $table->foreign('registrado_por')->references('id')->on('users')->onDelete('set null');
            });
        }

        // Configuraciones de puntos
        $configs = [
            ['clave' => 'puntos_dificultad_1',              'valor' => '10',  'tipo' => 'integer', 'grupo' => 'puntos', 'etiqueta' => 'Puntos por paso dificultad 1 (Fácil)'],
            ['clave' => 'puntos_dificultad_2',              'valor' => '20',  'tipo' => 'integer', 'grupo' => 'puntos', 'etiqueta' => 'Puntos por paso dificultad 2 (Normal)'],
            ['clave' => 'puntos_dificultad_3',              'valor' => '35',  'tipo' => 'integer', 'grupo' => 'puntos', 'etiqueta' => 'Puntos por paso dificultad 3 (Moderado)'],
            ['clave' => 'puntos_dificultad_4',              'valor' => '50',  'tipo' => 'integer', 'grupo' => 'puntos', 'etiqueta' => 'Puntos por paso dificultad 4 (Difícil)'],
            ['clave' => 'puntos_dificultad_5',              'valor' => '75',  'tipo' => 'integer', 'grupo' => 'puntos', 'etiqueta' => 'Puntos por paso dificultad 5 (Muy difícil)'],
            ['clave' => 'puntos_bonus_tiempo_pct',          'valor' => '20',  'tipo' => 'integer', 'grupo' => 'puntos', 'etiqueta' => 'Bonus por terminar antes del tiempo estimado (%)'],
            ['clave' => 'puntos_bonus_paso_final',          'valor' => '50',  'tipo' => 'integer', 'grupo' => 'puntos', 'etiqueta' => 'Bonus por completar paso final'],
            ['clave' => 'puntos_penalizacion_inasistencia', 'valor' => '30',  'tipo' => 'integer', 'grupo' => 'puntos', 'etiqueta' => 'Puntos negativos por inasistencia'],
            ['clave' => 'puntos_penalizacion_tardanza',     'valor' => '10',  'tipo' => 'integer', 'grupo' => 'puntos', 'etiqueta' => 'Puntos negativos por llegada tarde'],
            ['clave' => 'puntos_penalizacion_calidad',      'valor' => '50',  'tipo' => 'integer', 'grupo' => 'puntos', 'etiqueta' => 'Puntos negativos por trabajo rechazado por calidad'],
        ];

        foreach ($configs as $config) {
            DB::table('configuraciones')->updateOrInsert(
                ['clave' => $config['clave']],
                $config
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('puntos_colaborador');
        Schema::dropIfExists('niveles_colaborador');

        $claves = [
            'puntos_dificultad_1', 'puntos_dificultad_2', 'puntos_dificultad_3',
            'puntos_dificultad_4', 'puntos_dificultad_5', 'puntos_bonus_tiempo_pct',
            'puntos_bonus_paso_final', 'puntos_penalizacion_inasistencia',
            'puntos_penalizacion_tardanza', 'puntos_penalizacion_calidad',
        ];
        DB::table('configuraciones')->whereIn('clave', $claves)->delete();
    }
};
