<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->string('token_publico', 40)->nullable()->unique()->after('notas_internas');
        });

        // Generar tokens para registros existentes
        DB::table('cotizaciones')->whereNull('token_publico')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('cotizaciones')
                    ->where('id', $row->id)
                    ->update(['token_publico' => Str::random(40)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn('token_publico');
        });
    }
};
