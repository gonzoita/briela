<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Se eliminó por completo la integración con GoHighLevel (no se va a usar).
// Esta migración quita sus tablas. Los avisos del sistema hoy son 100%
// internos (la campanita), no dependían de GHL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ghl_logs');
        Schema::dropIfExists('ghl_configuracion');
    }

    public function down(): void
    {
        // No se recrean: la integración quedó descontinuada.
    }
};
