<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tipos_colaborador')) {
            return;
        }

        Schema::create('tipos_colaborador', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('color', 7)->default('#6B7280');
            $table->boolean('activo')->default(true);
            $table->integer('orden')->default(0);
            $table->timestamps();
        });

        DB::table('tipos_colaborador')->insert([
            ['nombre' => 'Operario',       'color' => '#3B82F6', 'activo' => true, 'orden' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Técnico',        'color' => '#10B981', 'activo' => true, 'orden' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Comercial',      'color' => '#F59E0B', 'activo' => true, 'orden' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Administrativo', 'color' => '#8B5CF6', 'activo' => true, 'orden' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Ingeniero',      'color' => '#EF4444', 'activo' => true, 'orden' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Directivo',      'color' => '#0A4283', 'activo' => true, 'orden' => 6, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_colaborador');
    }
};
