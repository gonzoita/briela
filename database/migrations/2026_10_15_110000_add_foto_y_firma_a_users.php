<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto de perfil y la firma de cada usuario.
 *
 * La foto va como archivo en el disco público (se muestra en el encabezado y el chat). La firma
 * va en la base como `data:image/png;base64,…`: es pequeña —recortada a la tinta— y así dompdf
 * la imprime sin depender de que el servidor deje leer archivos, que no siempre deja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'foto_path')) {
                $table->string('foto_path')->nullable();
            }
            if (! Schema::hasColumn('users', 'firma')) {
                $table->mediumText('firma')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Hacia adelante solamente.
    }
};
