<?php
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $claves = [
            ['clave' => 'empresa_nombre',    'valor' => 'Interfrigo SAS', 'tipo' => 'string', 'grupo' => 'empresa', 'etiqueta' => 'Nombre de la empresa'],
            ['clave' => 'empresa_nit',       'valor' => '',               'tipo' => 'string', 'grupo' => 'empresa', 'etiqueta' => 'NIT'],
            ['clave' => 'empresa_ciudad',    'valor' => 'Bogotá',         'tipo' => 'string', 'grupo' => 'empresa', 'etiqueta' => 'Ciudad'],
            ['clave' => 'empresa_telefono',  'valor' => '',               'tipo' => 'string', 'grupo' => 'empresa', 'etiqueta' => 'Teléfono'],
            ['clave' => 'empresa_email',     'valor' => '',               'tipo' => 'string', 'grupo' => 'empresa', 'etiqueta' => 'Email'],
            ['clave' => 'empresa_direccion', 'valor' => '',               'tipo' => 'string', 'grupo' => 'empresa', 'etiqueta' => 'Dirección'],
            ['clave' => 'empresa_logo_url',  'valor' => '',               'tipo' => 'string', 'grupo' => 'empresa', 'etiqueta' => 'URL del logo'],
        ];
        foreach ($claves as $c) {
            \App\Models\Configuracion::firstOrCreate(['clave' => $c['clave']], $c);
        }
    }

    public function down(): void {}
};
