<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una liquidación: varias comisiones que se pagan de una sola vez.
 *
 * Hasta ahora una comisión se liquidaba sola, cotización por cotización. A un vendedor no se le
 * paga cotización por cotización: se le paga el corte, con lo que haya en él. Sin un documento
 * que las agrupe no hay forma de decir «esto fue lo que se le pagó el 15», ni de imprimirlo, ni
 * de saber qué comisiones entraron en ese pago.
 *
 * La comisión no cambia de dueño: sigue siendo de su cotización. Lo único que se le agrega es a
 * qué liquidación entró — una columna nueva, sin tocar ni una de las que ya existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('liquidaciones_comision')) {
            Schema::create('liquidaciones_comision', function (Blueprint $table) {
                $table->id();
                $table->string('numero', 30)->unique();
                $table->foreignId('user_id')->constrained('users');
                $table->decimal('total', 14, 2)->default(0);
                // borrador → se está armando y todavía se puede deshacer.
                // pagada   → ya salió la plata; las comisiones quedan liquidadas.
                $table->string('estado', 20)->default('borrador');
                $table->date('fecha')->nullable();
                $table->text('notas')->nullable();
                $table->timestamp('pagada_at')->nullable();
                $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['user_id', 'estado']);
            });
        }

        if (! Schema::hasColumn('comisiones_vendedor', 'liquidacion_id')) {
            Schema::table('comisiones_vendedor', function (Blueprint $table) {
                $table->foreignId('liquidacion_id')->nullable()->after('estado')
                    ->constrained('liquidaciones_comision')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Solo la columna nueva: la tabla se queda. Borrarla se llevaría el historial de pagos.
        if (Schema::hasColumn('comisiones_vendedor', 'liquidacion_id')) {
            Schema::table('comisiones_vendedor', function (Blueprint $table) {
                $table->dropConstrainedForeignId('liquidacion_id');
            });
        }
    }
};
