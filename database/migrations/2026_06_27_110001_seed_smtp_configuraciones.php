<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $claves = [
            ['clave' => 'smtp_host',       'valor' => 'smtp.hostinger.com', 'tipo' => 'string', 'grupo' => 'email', 'etiqueta' => 'Servidor SMTP'],
            ['clave' => 'smtp_port',       'valor' => '465',                'tipo' => 'string', 'grupo' => 'email', 'etiqueta' => 'Puerto'],
            ['clave' => 'smtp_encryption', 'valor' => 'ssl',                'tipo' => 'string', 'grupo' => 'email', 'etiqueta' => 'Cifrado'],
            ['clave' => 'smtp_username',   'valor' => '',                   'tipo' => 'string', 'grupo' => 'email', 'etiqueta' => 'Usuario (email)'],
            ['clave' => 'smtp_password',   'valor' => '',                   'tipo' => 'string', 'grupo' => 'email', 'etiqueta' => 'Contraseña'],
            ['clave' => 'smtp_from_name',  'valor' => '',     'tipo' => 'string', 'grupo' => 'email', 'etiqueta' => 'Nombre remitente'],
            ['clave' => 'smtp_from_email', 'valor' => '',                   'tipo' => 'string', 'grupo' => 'email', 'etiqueta' => 'Email remitente'],
        ];

        foreach ($claves as $c) {
            \App\Models\Configuracion::firstOrCreate(['clave' => $c['clave']], $c);
        }
    }

    public function down(): void
    {
        $claves = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_name', 'smtp_from_email'];
        \App\Models\Configuracion::whereIn('clave', $claves)->delete();
    }
};
