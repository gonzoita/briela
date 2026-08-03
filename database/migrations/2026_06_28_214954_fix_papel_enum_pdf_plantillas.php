<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE pdf_plantillas MODIFY COLUMN papel
            ENUM('a4','a5','a3','letter','legal','half-letter',
                 'etiqueta-10x13','etiqueta-10x15','ticket-80',
                 'tarjeta','personalizado') NOT NULL DEFAULT 'a4'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE pdf_plantillas MODIFY COLUMN papel
            ENUM('a4','a5','letter','legal') NOT NULL DEFAULT 'a4'");
    }
};
