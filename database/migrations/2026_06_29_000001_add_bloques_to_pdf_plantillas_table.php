<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdf_plantillas', function (Blueprint $table) {
            if (!Schema::hasColumn('pdf_plantillas', 'bloques_header')) {
                $table->json('bloques_header')->nullable()->after('html');
            }
            if (!Schema::hasColumn('pdf_plantillas', 'bloques_body')) {
                $table->json('bloques_body')->nullable()->after('bloques_header');
            }
            if (!Schema::hasColumn('pdf_plantillas', 'bloques_footer')) {
                $table->json('bloques_footer')->nullable()->after('bloques_body');
            }
            if (!Schema::hasColumn('pdf_plantillas', 'modo_editor')) {
                $table->enum('modo_editor', ['visual', 'codigo'])->default('visual')->after('bloques_footer');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdf_plantillas', function (Blueprint $table) {
            $table->dropColumn(['bloques_header', 'bloques_body', 'bloques_footer', 'modo_editor']);
        });
    }
};
