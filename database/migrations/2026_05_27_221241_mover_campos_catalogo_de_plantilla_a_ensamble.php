<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ensambles', function (Blueprint $table) {
            $table->foreignId('categoria_id')
                  ->nullable()
                  ->constrained('categorias_producto')
                  ->nullOnDelete()
                  ->after('plantilla_id');
            $table->string('descripcion_corta', 160)->nullable()->after('nombre');
            $table->longText('descripcion_larga')->nullable()->after('descripcion_corta');
            $table->string('imagen_principal')->nullable()->after('descripcion_larga');
            $table->json('imagenes_secundarias')->nullable()->after('imagen_principal');
        });

        Schema::table('plantillas_ensamble', function (Blueprint $table) {
            $table->dropForeign(['categoria_id']);
            $table->dropColumn([
                'categoria_id',
                'descripcion_corta',
                'descripcion_larga',
                'imagen_principal',
                'imagenes_secundarias',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('ensambles', function (Blueprint $table) {
            $table->dropForeign(['categoria_id']);
            $table->dropColumn([
                'categoria_id',
                'descripcion_corta',
                'descripcion_larga',
                'imagen_principal',
                'imagenes_secundarias',
            ]);
        });

        Schema::table('plantillas_ensamble', function (Blueprint $table) {
            $table->foreignId('categoria_id')
                  ->nullable()
                  ->constrained('categorias_producto')
                  ->nullOnDelete();
            $table->string('descripcion_corta', 160)->nullable();
            $table->longText('descripcion_larga')->nullable();
            $table->string('imagen_principal')->nullable();
            $table->json('imagenes_secundarias')->nullable();
        });
    }
};
