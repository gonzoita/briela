<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('plantilla_campos', function (Blueprint $table) {
            if (!Schema::hasColumn('plantilla_campos', 'imagen_referencia')) {
                $table->string('imagen_referencia')->nullable()->after('ayuda')
                      ->comment('Imagen de referencia fija — plano o guía técnica');
            }
            if (!Schema::hasColumn('plantilla_campos', 'imagen_referencia_titulo')) {
                $table->string('imagen_referencia_titulo')->nullable()->after('imagen_referencia')
                      ->comment('Título o descripción del plano de referencia');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plantilla_campos', function (Blueprint $table) {
            $table->dropColumn(['imagen_referencia', 'imagen_referencia_titulo']);
        });
    }
};
