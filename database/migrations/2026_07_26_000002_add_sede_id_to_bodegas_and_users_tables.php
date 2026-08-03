<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Ata las bodegas y los usuarios que YA existen a la sede principal
    // (Bogotá). Nadie pierde acceso ni datos con este cambio.
    public function up(): void
    {
        $sedePrincipal = DB::table('sedes')->where('es_principal', true)->value('id');

        Schema::table('bodegas', function (Blueprint $table) {
            $table->foreignId('sede_id')->nullable()->after('id')->constrained('sedes')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('sede_id')->nullable()->after('rol')->constrained('sedes')->nullOnDelete();
        });

        if ($sedePrincipal) {
            DB::table('bodegas')->whereNull('sede_id')->update(['sede_id' => $sedePrincipal]);
            DB::table('users')->whereNull('sede_id')->update(['sede_id' => $sedePrincipal]);
        }
    }

    public function down(): void
    {
        Schema::table('bodegas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sede_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sede_id');
        });
    }
};
