<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->json('tipos_contacto')->nullable()->after('notas');
            $table->json('industrias')->nullable()->after('tipos_contacto');
            $table->text('intereses')->nullable()->after('industrias');
            $table->json('proceso_seguimiento')->nullable()->after('intereses');
            $table->json('fuentes_contacto')->nullable()->after('proceso_seguimiento');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['tipos_contacto', 'industrias', 'intereses', 'proceso_seguimiento', 'fuentes_contacto']);
        });
    }
};
