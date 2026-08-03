<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Perfil de marca de la empresa, guardado por secciones. Es la fuente de
    // verdad que usa la IA para redactar con la voz de la empresa y para
    // responder preguntas sobre ella.
    //
    // La tabla nace VACÍA a propósito. En el SGI de origen esta migración
    // sembraba el perfil completo de Interfrigo —identidad, historia,
    // propósito, tono de voz—, y en un producto que se instala en el servidor
    // de cada cliente eso significaría que la IA de todos redacta con la voz de
    // otra empresa.
    //
    // Las secciones disponibles las define App\Models\PerfilMarca::catalogo(),
    // así que la pantalla de configuración las muestra igual estando vacías, y
    // cada empresa escribe las suyas. Mientras no haya contenido, el asistente
    // simplemente no usa perfil de marca.
    public function up(): void
    {
        Schema::create('perfil_marca', function (Blueprint $table) {
            $table->id();
            $table->string('seccion', 40)->unique(); // historia, mision, vision...
            $table->longText('contenido')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamp('generado_ia_at')->nullable(); // si lo escribió la IA
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfil_marca');
    }
};
