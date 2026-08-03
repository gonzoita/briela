<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ampliar ENUM tipo_campo para incluir variable_instancia
        DB::statement("ALTER TABLE plantilla_campos MODIFY COLUMN tipo_campo ENUM('entrada','calculado','variable_instancia') NOT NULL DEFAULT 'entrada'");

        Schema::table('plantilla_campos', function (Blueprint $table) {
            if (! Schema::hasColumn('plantilla_campos', 'subtipo_variable')) {
                $table->string('subtipo_variable', 20)->nullable()->after('tipo_campo');
            }
            if (! Schema::hasColumn('plantilla_campos', 'opciones_selector')) {
                $table->json('opciones_selector')->nullable()->after('subtipo_variable');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plantilla_campos', function (Blueprint $table) {
            $table->dropColumn(['subtipo_variable', 'opciones_selector']);
        });
        DB::statement("ALTER TABLE plantilla_campos MODIFY COLUMN tipo_campo ENUM('entrada','calculado') NOT NULL DEFAULT 'entrada'");
    }
};
