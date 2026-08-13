<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `productos.descripcion_corta` pasa de 160 a 1000 caracteres.
 *
 * Tres sitios decían cosas distintas sobre el mismo campo: la base aceptaba 160, la
 * pantalla mostraba el contador «/1000» y el generador de fichas con IA produce hasta
 * 380 —el tope que pide el prompt para la introducción comercial—. Resultado: la ficha
 * se generaba bien, se veía bien en pantalla, y al guardar el producto reventaba con un
 * error de validación.
 *
 * Se agranda en vez de recortar la ficha porque 160 caracteres no alcanzan para una
 * introducción que tiene que decir el problema que resuelve, el respaldo técnico y la
 * confiabilidad. Y se deja en 1000 y no en 400 para que quede igual que
 * `ensambles.descripcion_corta`, que ya era 1000: dos campos que se llenan con lo mismo
 * no deberían tener topes distintos.
 *
 * Ampliar un `varchar` no toca ningún dato: lo que ya estaba guardado sigue cabiendo. Es
 * hacia adelante y no destructiva, como pide la regla 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('productos', 'descripcion_corta')) {
            return;
        }

        // Sin `doctrine/dbal` no hay `->change()`, así que va en SQL directo. El tipo se
        // repite completo a propósito: un MODIFY parcial perdería el «null».
        DB::statement('ALTER TABLE `productos` MODIFY `descripcion_corta` VARCHAR(1000) NULL');
    }

    /**
     * Volver atrás **recortaría** las descripciones que ya no caben, y eso sí sería
     * destructivo. Así que no se vuelve: un campo más ancho no le estorba a ninguna
     * versión anterior del código.
     */
    public function down(): void
    {
        // A propósito vacío. Ver el comentario de arriba.
    }
};
