<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atribución de origen para leads que llegan del plugin de WordPress
 * (Briela Connect). Columnas nuevas y nullable: no rompe leads existentes
 * ni instalaciones que todavía no tengan el plugin conectado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_leads', 'pagina_origen')) {
                $table->string('pagina_origen', 500)->nullable()->after('fuente');
            }
            if (!Schema::hasColumn('crm_leads', 'utm_source')) {
                $table->string('utm_source', 150)->nullable()->after('pagina_origen');
            }
            if (!Schema::hasColumn('crm_leads', 'utm_medium')) {
                $table->string('utm_medium', 150)->nullable()->after('utm_source');
            }
            if (!Schema::hasColumn('crm_leads', 'utm_campaign')) {
                $table->string('utm_campaign', 150)->nullable()->after('utm_medium');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            foreach (['pagina_origen', 'utm_source', 'utm_medium', 'utm_campaign'] as $columna) {
                if (Schema::hasColumn('crm_leads', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
