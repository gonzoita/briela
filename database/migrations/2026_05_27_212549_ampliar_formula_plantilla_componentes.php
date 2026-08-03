<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        \DB::statement('ALTER TABLE plantilla_componentes MODIFY COLUMN formula TEXT NOT NULL');
        \DB::statement('ALTER TABLE plantilla_componentes MODIFY COLUMN condicion TEXT NULL');
        \DB::statement('ALTER TABLE plantilla_campos MODIFY COLUMN formula_calculo TEXT NULL');
    }

    public function down(): void
    {
        \DB::statement('ALTER TABLE plantilla_componentes MODIFY COLUMN formula VARCHAR(500) NOT NULL DEFAULT "0"');
        \DB::statement('ALTER TABLE plantilla_componentes MODIFY COLUMN condicion VARCHAR(500) NULL');
        \DB::statement('ALTER TABLE plantilla_campos MODIFY COLUMN formula_calculo VARCHAR(500) NULL');
    }
};
