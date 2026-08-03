<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('op_items', 'estado_item')) {
            DB::statement("ALTER TABLE op_items MODIFY COLUMN estado_item ENUM('pendiente','en_proceso','terminado') DEFAULT 'pendiente'");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('op_items', 'estado_item')) {
            DB::statement("ALTER TABLE op_items MODIFY COLUMN estado_item ENUM('pendiente','en_proceso') DEFAULT 'pendiente'");
        }
    }
};
