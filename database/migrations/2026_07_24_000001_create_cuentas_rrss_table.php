<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Cuentas de redes sociales conectadas (Instagram, Facebook, LinkedIn,
    // Google Business Profile). Cada fila es UNA cuenta/página conectada vía
    // OAuth. Los tokens se guardan cifrados (cast "encrypted").
    public function up(): void
    {
        Schema::create('cuentas_rrss', function (Blueprint $table) {
            $table->id();
            $table->enum('red', ['instagram', 'facebook', 'linkedin', 'google_business']);
            $table->string('nombre_cuenta'); // nombre visible, ej. "Interfrigo SAS"
            $table->string('cuenta_id_externo'); // ID de la página/cuenta en la plataforma
            $table->string('cuenta_id_secundario')->nullable(); // ej. IG Business Account ID ligado a la página de FB
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expira_en')->nullable();
            $table->json('datos')->nullable(); // metadata extra devuelta por cada API
            $table->boolean('activa')->default(true);
            $table->foreignId('conectada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('ultimo_error')->nullable();
            $table->timestamp('ultima_publicacion_en')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['red', 'cuenta_id_externo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_rrss');
    }
};
