<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('op_cuotas', 'es_saldo_automatico')) {
            Schema::table('op_cuotas', function (Blueprint $table) {
                $table->boolean('es_saldo_automatico')->default(false)->after('valor_pagado');
            });
        }
    }

    public function down(): void
    {
        Schema::table('op_cuotas', function (Blueprint $table) {
            $table->dropColumn('es_saldo_automatico');
        });
    }
};
