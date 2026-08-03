<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segmentacion_opciones', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', [
                'tipo_contacto',
                'industria',
                'proceso_seguimiento',
                'fuente_contacto',
            ]);
            $table->string('valor', 80);
            $table->string('etiqueta', 100);
            $table->string('color', 10)->nullable();
            $table->integer('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Seed con opciones base
        DB::table('segmentacion_opciones')->insert([
            // Tipos de contacto
            ['tipo' => 'tipo_contacto', 'valor' => 'cliente_directo',    'etiqueta' => 'Cliente directo',    'color' => '#2563EB', 'orden' => 1],
            ['tipo' => 'tipo_contacto', 'valor' => 'distribuidor',       'etiqueta' => 'Distribuidor',       'color' => '#7c3aed', 'orden' => 2],
            ['tipo' => 'tipo_contacto', 'valor' => 'mayorista',          'etiqueta' => 'Mayorista',          'color' => '#dc2626', 'orden' => 3],
            ['tipo' => 'tipo_contacto', 'valor' => 'prospecto',          'etiqueta' => 'Prospecto',          'color' => '#d97706', 'orden' => 4],
            // Industrias
            ['tipo' => 'industria', 'valor' => 'alimentos_bebidas',      'etiqueta' => 'Alimentos y bebidas',  'color' => '#16a34a', 'orden' => 1],
            ['tipo' => 'industria', 'valor' => 'farmaceutica',           'etiqueta' => 'Farmacéutica',         'color' => '#0891b2', 'orden' => 2],
            ['tipo' => 'industria', 'valor' => 'hoteleria_restaurantes', 'etiqueta' => 'Hotelería/Restaurantes','color' => '#ea580c', 'orden' => 3],
            ['tipo' => 'industria', 'valor' => 'supermercados',          'etiqueta' => 'Supermercados',        'color' => '#65a30d', 'orden' => 4],
            ['tipo' => 'industria', 'valor' => 'flores_exportacion',     'etiqueta' => 'Flores/Exportación',   'color' => '#db2777', 'orden' => 5],
            ['tipo' => 'industria', 'valor' => 'logistica_transporte',   'etiqueta' => 'Logística/Transporte', 'color' => '#475569', 'orden' => 6],
            // Proceso de seguimiento
            ['tipo' => 'proceso_seguimiento', 'valor' => 'primer_contacto',   'etiqueta' => 'Primer contacto',    'color' => '#d97706', 'orden' => 1],
            ['tipo' => 'proceso_seguimiento', 'valor' => 'cotizacion_enviada', 'etiqueta' => 'Cotización enviada', 'color' => '#0891b2', 'orden' => 2],
            ['tipo' => 'proceso_seguimiento', 'valor' => 'negociacion',        'etiqueta' => 'En negociación',     'color' => '#7c3aed', 'orden' => 3],
            ['tipo' => 'proceso_seguimiento', 'valor' => 'cliente_activo',     'etiqueta' => 'Cliente activo',     'color' => '#16a34a', 'orden' => 4],
            ['tipo' => 'proceso_seguimiento', 'valor' => 'cliente_inactivo',   'etiqueta' => 'Cliente inactivo',   'color' => '#475569', 'orden' => 5],
            // Fuentes de contacto
            ['tipo' => 'fuente_contacto', 'valor' => 'referido',         'etiqueta' => 'Referido',          'color' => '#2563EB', 'orden' => 1],
            ['tipo' => 'fuente_contacto', 'valor' => 'pagina_web',       'etiqueta' => 'Página web',        'color' => '#16a34a', 'orden' => 2],
            ['tipo' => 'fuente_contacto', 'valor' => 'whatsapp',         'etiqueta' => 'WhatsApp',          'color' => '#16a34a', 'orden' => 3],
            ['tipo' => 'fuente_contacto', 'valor' => 'llamada_fria',     'etiqueta' => 'Llamada fría',      'color' => '#d97706', 'orden' => 4],
            ['tipo' => 'fuente_contacto', 'valor' => 'feria_evento',     'etiqueta' => 'Feria/Evento',      'color' => '#7c3aed', 'orden' => 5],
            ['tipo' => 'fuente_contacto', 'valor' => 'ghl_crm',          'etiqueta' => 'GHL/CRM',           'color' => '#ea580c', 'orden' => 6],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('segmentacion_opciones');
    }
};
