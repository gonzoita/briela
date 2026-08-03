<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_formularios', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_formularios', 'asignacion_tipo')) {
                $table->enum('asignacion_tipo', ['fijo', 'round_robin', 'ponderado'])
                      ->default('fijo')->after('responsable_id');
            }
            if (!Schema::hasColumn('crm_formularios', 'responsables_ids')) {
                $table->json('responsables_ids')->nullable()->after('asignacion_tipo');
            }
            if (!Schema::hasColumn('crm_formularios', 'round_robin_ultimo')) {
                $table->integer('round_robin_ultimo')->default(0)->after('responsables_ids');
            }
            if (!Schema::hasColumn('crm_formularios', 'responsables_pesos')) {
                $table->json('responsables_pesos')->nullable()->after('round_robin_ultimo');
            }
            if (!Schema::hasColumn('crm_formularios', 'gracias_tipo')) {
                $table->enum('gracias_tipo', ['mensaje', 'redirect'])
                      ->default('mensaje')->after('mensaje_exito');
            }
            if (!Schema::hasColumn('crm_formularios', 'gracias_url')) {
                $table->string('gracias_url', 500)->nullable()->after('gracias_tipo');
            }
            if (!Schema::hasColumn('crm_formularios', 'captcha_activo')) {
                $table->boolean('captcha_activo')->default(false)->after('gracias_url');
            }
        });

        $claves = [
            ['clave' => 'recaptcha_site_key',   'valor' => '', 'tipo' => 'string', 'grupo' => 'seguridad', 'etiqueta' => 'reCAPTCHA Site Key (pública)'],
            ['clave' => 'recaptcha_secret_key',  'valor' => '', 'tipo' => 'string', 'grupo' => 'seguridad', 'etiqueta' => 'reCAPTCHA Secret Key (privada)'],
        ];
        foreach ($claves as $c) {
            \App\Models\Configuracion::firstOrCreate(['clave' => $c['clave']], $c);
        }
    }

    public function down(): void
    {
        Schema::table('crm_formularios', function (Blueprint $table) {
            $cols = ['asignacion_tipo', 'responsables_ids', 'round_robin_ultimo', 'responsables_pesos', 'gracias_tipo', 'gracias_url', 'captcha_activo'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('crm_formularios', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        \App\Models\Configuracion::whereIn('clave', ['recaptcha_site_key', 'recaptcha_secret_key'])->delete();
    }
};
