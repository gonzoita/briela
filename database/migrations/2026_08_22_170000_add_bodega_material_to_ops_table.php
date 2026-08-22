<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qué bodega sale el material que consume esta OP.
 *
 * Es la pareja de `bodega_entrega_id`, y responde la pregunta contraria: una dice a dónde entra
 * lo fabricado, esta de dónde salen los insumos que se gastan al fabricarlo.
 *
 * Hasta ahora las dos eran la misma, y eso producía un descuento fantasma: si la OP entregaba
 * en una bodega de producto terminado —que por definición no guarda insumos— el descuento se
 * hacía contra una bodega con cero, `registrarMovimiento()` lo recortaba en `max(0, …)`, y el
 * material seguía figurando entero en la bodega donde de verdad estaba. Sin error, sin stock en
 * rojo: simplemente no pasaba nada.
 *
 * Nullable por lo mismo que la otra: las OPs que ya existen no la tienen, y ponerles una a la
 * fuerza sería inventar de dónde salió algo que ya se gastó. Se vuelve obligatoria al
 * **confirmar** una OP nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->unsignedBigInteger('bodega_material_id')->nullable()->after('bodega_entrega_id');
            $table->foreign('bodega_material_id')->references('id')->on('bodegas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->dropForeign(['bodega_material_id']);
            $table->dropColumn('bodega_material_id');
        });
    }
};
