<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plantillas_ensamble', function (Blueprint $table) {
            if (!Schema::hasColumn('plantillas_ensamble', 'descripcion_corta')) {
                $table->text('descripcion_corta')->nullable()->after('descripcion');
            }
            if (!Schema::hasColumn('plantillas_ensamble', 'descripcion_larga')) {
                $table->longText('descripcion_larga')->nullable()->after('descripcion_corta');
            }
            if (!Schema::hasColumn('plantillas_ensamble', 'categoria_id')) {
                $table->foreignId('categoria_id')->nullable()->after('descripcion_larga')
                    ->constrained('categorias_producto')->nullOnDelete();
            }
            if (!Schema::hasColumn('plantillas_ensamble', 'imagen_principal')) {
                $table->string('imagen_principal')->nullable()->after('categoria_id');
            }
            if (!Schema::hasColumn('plantillas_ensamble', 'imagenes_secundarias')) {
                $table->json('imagenes_secundarias')->nullable()->after('imagen_principal');
            }
            if (!Schema::hasColumn('plantillas_ensamble', 'config_salida')) {
                $table->json('config_salida')->nullable()->after('imagenes_secundarias');
            }
            if (!Schema::hasColumn('plantillas_ensamble', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('plantillas_ensamble', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'descripcion_corta', 'descripcion_larga', 'categoria_id',
                'imagen_principal', 'imagenes_secundarias', 'config_salida',
            ]);
        });
    }
};
