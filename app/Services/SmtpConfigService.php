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
        $fromName = Configuracion::get('smtp_from_name', 'SGI');
        $fromMail = Configuracion::get('smtp_from_email', '');

        if (!$host || !$user || !$pass) return;

        Config::set('mail.mailers.smtp.host',       $host);
        Config::set('mail.mailers.smtp.port',       (int) $port);
        Config::set('mail.mailers.smtp.encryption', $enc);
        Config::set('mail.mailers.smtp.username',   $user);
        Config::set('mail.mailers.smtp.password',   $pass);
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
