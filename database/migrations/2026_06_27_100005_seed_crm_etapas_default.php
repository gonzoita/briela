<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('crm_etapas')->count() === 0) {
            DB::table('crm_etapas')->insert([
                ['nombre' => 'Nuevo Lead',         'color' => '#6B7280', 'orden' => 1, 'accion_automatica' => 'ninguna',    'es_ganado' => false, 'es_perdido' => false, 'activa' => true, 'created_at' => now(), 'updated_at' => now()],
                ['nombre' => 'Prospecto Validado', 'color' => '#3B82F6', 'orden' => 2, 'accion_automatica' => 'ninguna',    'es_ganado' => false, 'es_perdido' => false, 'activa' => true, 'created_at' => now(), 'updated_at' => now()],
                ['nombre' => 'Cliente Nuevo',      'color' => '#8B5CF6', 'orden' => 3, 'accion_automatica' => 'cotizacion', 'es_ganado' => false, 'es_perdido' => false, 'activa' => true, 'created_at' => now(), 'updated_at' => now()],
                ['nombre' => 'Cliente Recurrente', 'color' => '#10B981', 'orden' => 4, 'accion_automatica' => 'ninguna',    'es_ganado' => true,  'es_perdido' => false, 'activa' => true, 'created_at' => now(), 'updated_at' => now()],
                ['nombre' => 'Reactivación',       'color' => '#F59E0B', 'orden' => 5, 'accion_automatica' => 'ninguna',    'es_ganado' => false, 'es_perdido' => false, 'activa' => true, 'created_at' => now(), 'updated_at' => now()],
                ['nombre' => 'Perdido',            'color' => '#EF4444', 'orden' => 6, 'accion_automatica' => 'ninguna',    'es_ganado' => false, 'es_perdido' => true,  'activa' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        DB::table('crm_etapas')->truncate();
    }
};
