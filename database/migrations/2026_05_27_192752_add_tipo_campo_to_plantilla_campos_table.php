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
            $table->enum('tipo_campo', ['entrada', 'calculado'])->default('entrada')->after('tipo');
            $table->string('formula_calculo', 500)->nullable()->after('tipo_campo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plantilla_campos', function (Blueprint $table) {
            $table->dropColumn(['tipo_campo', 'formula_calculo']);
        });
    }
};
