<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('clientes', 'requiere_anticipo')) {
            Schema::table('clientes', function (Blueprint $table) {
                $table->boolean('requiere_anticipo')->default(false)->after('activo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clientes', 'requiere_anticipo')) {
            Schema::table('clientes', function (Blueprint $table) {
                $table->dropColumn('requiere_anticipo');
            });
        }
    }
};
