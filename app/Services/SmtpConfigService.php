<?php

namespace App\Services;

use App\Models\Configuracion;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class SmtpConfigService
{
    public static function aplicar(): void
    {
        $host     = Configuracion::get('smtp_host', '');
        $port     = Configuracion::get('smtp_port', '465');
        $enc      = Configuracion::get('smtp_encryption', 'ssl');
        $user     = Configuracion::get('smtp_username', '');
        $pass     = Configuracion::get('smtp_password', '');
        // Sin remitente configurado, el nombre de la empresa de la instalación. Un nombre
        // fijo haría que los correos de todos los clientes salieran firmados igual.
        $fromName = Configuracion::get('smtp_from_name') ?: \App\Support\Marca::nombreEmpresa();
        $fromMail = Configuracion::get('smtp_from_email', '');

        // Por el panel de Briela, cuando su dominio de envío está verificado: el proveedor
        // firma con DKIM y el correo no cae en spam. El SMTP de aquí abajo queda de
        // respaldo, por si el panel no responde (ver App\Mail\TransporteBriela).
        $licencias = app(\App\Services\LicenciaService::class);

        if ($licencias->correoPorBriela()) {
            Config::set('mail.mailers.briela', ['transport' => 'briela']);
            Config::set('mail.default', 'briela');
            // El remitente lo pone el panel con el dominio verificado; aquí va para que
            // Laravel tenga uno. El nombre sí es el de la empresa.
            Config::set('mail.from.address', $licencias->correo()['remitentes']['transaccional'] ?? 'notificaciones@briela.app');
            Config::set('mail.from.name', $fromName);

            if ($responder = (Configuracion::get('empresa_email') ?: $fromMail)) {
                Config::set('mail.reply_to', ['address' => $responder, 'name' => $fromName]);
            }

            Mail::purge('briela');
        }

        if (!$host || !$user || !$pass) return;

        Config::set('mail.mailers.smtp.host',       $host);
        Config::set('mail.mailers.smtp.port',       (int) $port);
        Config::set('mail.mailers.smtp.encryption', $enc);
        Config::set('mail.mailers.smtp.username',   $user);
        Config::set('mail.mailers.smtp.password',   $pass);
        if ($licencias->correoPorBriela()) {
            // El SMTP queda solo de respaldo: la ruta principal es la de arriba.
            Mail::purge('smtp');

            return;
        }

        Config::set('mail.from.address',            $fromMail ?: $user);
        Config::set('mail.from.name',               $fromName);
        Config::set('mail.default',                 'smtp');
    }

    public static function probar(string $emailDestino): array
    {
        try {
            static::aplicar();
            Mail::raw('Prueba de configuración SMTP.', function ($m) use ($emailDestino) {
                $m->to($emailDestino)->subject('Prueba SMTP');
            });
            return ['ok' => true, 'mensaje' => 'Email enviado correctamente.'];
        } catch (\Exception $e) {
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        }
    }
}
