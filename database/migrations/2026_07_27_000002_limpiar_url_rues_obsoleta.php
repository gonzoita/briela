<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Borra la dirección del RUES guardada en Configuración cuando apunta al
 * portal viejo (ruesapi.rues.org.co), que fue desmantelado.
 *
 * Hace falta porque lo guardado en la app manda sobre el valor por defecto
 * del código: si alguien alcanzó a oprimir "Guardar" con la dirección vieja,
 * esa se queda pegada y el default nuevo nunca entra. Al borrar la fila,
 * vuelve a mandar config('services.rues.url').
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('configuraciones')
            ->where('clave', 'rues_url')
            ->where('valor', 'like', '%ruesapi.rues.org.co%')
            ->delete();

        // La caché pudo guardar respuestas vacías de la dirección muerta.
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => 'rues_cache_version'],
            ['valor' => (string) time()]
        );
    }

    public function down(): void
    {
        // No se restaura: la dirección vieja ya no existe.
    }
};
