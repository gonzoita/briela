<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_conversaciones')) return;

        Schema::create('whatsapp_conversaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_numero_id')->constrained('whatsapp_numeros');
            $table->string('numero_contacto'); // número del cliente/lead
            $table->string('nombre_contacto')->nullable();
            $table->foreignId('crm_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->timestamp('ultimo_mensaje_at')->nullable();
            $table->boolean('leido')->default(true);
            $table->timestamps();
            $table->unique(['whatsapp_numero_id', 'numero_contacto'], 'whatsapp_conversaciones_numero_contacto_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversaciones');
    }
};
