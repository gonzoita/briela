<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('configuraciones', 'tipo')) {
            Schema::table('configuraciones', function (Blueprint $table) {
                $table->string('tipo')->default('string')->after('valor');
                $table->string('grupo')->default('general')->after('tipo');
                $table->string('etiqueta')->nullable()->after('grupo');
            });
        }

        $defaults = [
            ['clave' => 'alerta_op_rojo_dias',    'valor' => '0',  'tipo' => 'integer', 'grupo' => 'alertas', 'etiqueta' => 'Días para alerta roja de OP'],
            ['clave' => 'alerta_op_amarillo_dias', 'valor' => '2',  'tipo' => 'integer', 'grupo' => 'alertas', 'etiqueta' => 'Días para alerta amarilla de OP'],
            ['clave' => 'margen_mayorista',        'valor' => '30', 'tipo' => 'integer', 'grupo' => 'general', 'etiqueta' => 'Margen mayorista por defecto (%)'],
            ['clave' => 'margen_distribuidor',     'valor' => '32', 'tipo' => 'integer', 'grupo' => 'general', 'etiqueta' => 'Margen distribuidor por defecto (%)'],
            ['clave' => 'margen_cliente_final',    'valor' => '35', 'tipo' => 'integer', 'grupo' => 'general', 'etiqueta' => 'Margen cliente final por defecto (%)'],
        ];

        foreach ($defaults as $d) {
            DB::table('configuraciones')->updateOrInsert(
                ['clave' => $d['clave']],
                array_merge($d, ['updated_at' => now(), 'created_at' => now()])
            );
        }
    }

    public function down(): void
    {
        Schema::table('configuraciones', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'grupo', 'etiqueta']);
        });
    }
};
