<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->decimal('margen_mayorista',    5, 2)->default(25)->after('precio_costo');
            $table->decimal('margen_distribuidor', 5, 2)->default(30)->after('margen_mayorista');
            $table->decimal('margen_cliente_final',5, 2)->default(35)->after('margen_distribuidor');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['margen_mayorista', 'margen_distribuidor', 'margen_cliente_final']);
        });
    }
};
