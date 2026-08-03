<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            if (!Schema::hasColumn('productos', 'es_padre')) {
                $table->boolean('es_padre')->default(false)->after('tipo');
            }
            if (!Schema::hasColumn('productos', 'producto_padre_id')) {
                $table->foreignId('producto_padre_id')->nullable()->after('es_padre')
                    ->constrained('productos')->restrictOnDelete();
            }
            if (!Schema::hasColumn('productos', 'atributo_variante')) {
                $table->string('atributo_variante', 60)->nullable()->after('producto_padre_id');
            }
            if (!Schema::hasColumn('productos', 'valor_variante')) {
                $table->string('valor_variante', 60)->nullable()->after('atributo_variante');
            }
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            if (Schema::hasColumn('productos', 'producto_padre_id')) {
                $table->dropForeign(['producto_padre_id']);
                $table->dropColumn('producto_padre_id');
            }
            if (Schema::hasColumn('productos', 'atributo_variante')) $table->dropColumn('atributo_variante');
            if (Schema::hasColumn('productos', 'valor_variante'))    $table->dropColumn('valor_variante');
            if (Schema::hasColumn('productos', 'es_padre'))          $table->dropColumn('es_padre');
        });
    }
};
