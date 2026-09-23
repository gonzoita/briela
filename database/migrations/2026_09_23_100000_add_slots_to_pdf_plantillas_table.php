<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encabezado y pie de página como piezas propias en el modo código.
 *
 * Aditiva: una plantilla vieja no tiene encabezado ni pie, y sale igual que antes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdf_plantillas', function (Blueprint $table) {
            if (! Schema::hasColumn('pdf_plantillas', 'html_header')) {
                $table->longText('html_header')->nullable()->after('html');
            }
            if (! Schema::hasColumn('pdf_plantillas', 'html_footer')) {
                $table->longText('html_footer')->nullable()->after('html_header');
            }
            if (! Schema::hasColumn('pdf_plantillas', 'alto_header_mm')) {
                $table->unsignedSmallInteger('alto_header_mm')->default(25)->after('alto_mm');
            }
            if (! Schema::hasColumn('pdf_plantillas', 'alto_footer_mm')) {
                $table->unsignedSmallInteger('alto_footer_mm')->default(15)->after('alto_header_mm');
            }
            if (! Schema::hasColumn('pdf_plantillas', 'margen_mm')) {
                $table->unsignedSmallInteger('margen_mm')->default(12)->after('alto_footer_mm');
            }
        });
    }

    public function down(): void
    {
        // Hacia adelante siempre: no se borran columnas en una base de cliente.
    }
};
