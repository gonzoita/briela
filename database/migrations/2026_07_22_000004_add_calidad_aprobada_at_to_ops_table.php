<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            // Marca cuándo se aprobó el control de calidad — separado del
            // campo `estado` porque calidad ya no salta directo a
            // "despachada": el despacho real ahora lo dispara la remisión,
            // y esta marca de tiempo es el requisito obligatorio para poder
            // generarla.
            $table->timestamp('calidad_aprobada_at')->nullable()->after('motivo_rechazo');
        });
    }

    public function down(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->dropColumn('calidad_aprobada_at');
        });
    }
};
