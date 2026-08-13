<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca qué productos y ensambles salen al sitio web del cliente.
 *
 * Es una decisión del ERP, no del sitio: el plugin Briela Connect lee esta marca y crea
 * o actualiza la ficha en WordPress. Nace apagada en todo el catálogo — publicar cien
 * productos sin que nadie lo pidiera es peor que no publicar ninguno.
 *
 * `publicado_web_at` no es lo mismo que `publicado_web`: guarda cuándo se marcó, para
 * poder ordenar «lo último que salió a la web» y para que el plugin sepa si su copia
 * quedó vieja. Al despublicar se conserva: dice cuándo estuvo publicado por última vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['productos', 'ensambles'] as $tabla) {
            if (! Schema::hasColumn($tabla, 'publicado_web')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->boolean('publicado_web')->default(false)->after('id');
                    $table->timestamp('publicado_web_at')->nullable()->after('publicado_web');
                });
            }
        }

        // El índice es para el endpoint del plugin, que siempre pregunta lo mismo: dame
        // los publicados. Sin él, cada sincronización recorre el catálogo completo.
        foreach (['productos', 'ensambles'] as $tabla) {
            $indice = "{$tabla}_publicado_web_index";

            if (! $this->existeIndice($tabla, $indice)) {
                Schema::table($tabla, fn (Blueprint $table) => $table->index('publicado_web', $indice));
            }
        }
    }

    /**
     * Se quita la marca, no los datos: nada de lo que había antes de esta migración
     * depende de estas dos columnas.
     */
    public function down(): void
    {
        foreach (['productos', 'ensambles'] as $tabla) {
            $indice = "{$tabla}_publicado_web_index";

            if ($this->existeIndice($tabla, $indice)) {
                Schema::table($tabla, fn (Blueprint $table) => $table->dropIndex($indice));
            }

            if (Schema::hasColumn($tabla, 'publicado_web')) {
                Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn(['publicado_web', 'publicado_web_at']));
            }
        }
    }

    /**
     * Sin `doctrine/dbal`: se pregunta a MySQL por el índice.
     *
     * La migración tiene que poder correr sobre cualquier versión anterior soportada,
     * incluida una donde alguien ya haya creado la columna a mano.
     */
    private function existeIndice(string $tabla, string $indice): bool
    {
        $prefijo = \Illuminate\Support\Facades\DB::getTablePrefix();

        return collect(\Illuminate\Support\Facades\DB::select("SHOW INDEX FROM `{$prefijo}{$tabla}`"))
            ->contains(fn ($fila) => $fila->Key_name === $indice);
    }
};
