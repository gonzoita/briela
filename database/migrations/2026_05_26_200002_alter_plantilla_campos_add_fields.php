<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plantilla_campos', function (Blueprint $table) {
            if (!Schema::hasColumn('plantilla_campos', 'placeholder')) {
                $table->string('placeholder', 255)->nullable()->after('valor_defecto');
            }
            if (!Schema::hasColumn('plantilla_campos', 'ayuda')) {
                $table->string('ayuda', 500)->nullable()->after('placeholder');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plantilla_campos', function (Blueprint $table) {
            $table->dropColumn(['placeholder', 'ayuda']);
        });
    }
};
