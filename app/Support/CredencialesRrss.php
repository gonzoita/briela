<?php

namespace App\Support;

use App\Models\Configuracion;
use Illuminate\Support\Facades\Crypt;

/**
 * Credenciales de las redes sociales, configurables desde la interfaz.
 *
 * Antes solo se podían poner en el `.env` del servidor, lo que obligaba a
 * entrar por SSH cada vez — algo que el administrador del sistema no tiene por
 * qué saber hacer. Ahora se guardan en `configuraciones`, igual que el SMTP.
 *
 * **Se sigue leyendo el `.env` como respaldo**: si una instalación ya tenía las
 * credenciales ahí, siguen funcionando sin tocar nada. Lo que esté guardado en
 * la base manda sobre el `.env`.
 *
 * El *secret* se guarda cifrado (no en texto plano como el SMTP): es la llave
 * que permite publicar en nombre de la empresa, y el módulo ya cifra los
 * tokens de cada cuenta conectada (ver CuentaRrss).
 */
class CredencialesRrss
{
    /**
     * Mapa de cada red: clave en `configuraciones` => clave en config/services.
     * El orden de los campos es el mismo en las tres redes.
     */
    private const MAPA = [
        'meta' => [
            'id'       => ['rrss_meta_app_id',       'services.meta_rrss.app_id',       'META_APP_ID'],
            'secret'   => ['rrss_meta_app_secret',   'services.meta_rrss.app_secret',   'META_APP_SECRET'],
            'redirect' => ['rrss_meta_redirect_uri', 'services.meta_rrss.redirect_uri', 'META_REDIRECT_URI'],
        ],
        'linkedin' => [
            'id'       => ['rrss_linkedin_client_id',     'services.linkedin_rrss.client_id',     'LINKEDIN_CLIENT_ID'],
            'secret'   => ['rrss_linkedin_client_secret', 'services.linkedin_rrss.client_secret', 'LINKEDIN_CLIENT_SECRET'],
            'redirect' => ['rrss_linkedin_redirect_uri',  'services.linkedin_rrss.redirect_uri',  'LINKEDIN_REDIRECT_URI'],
        ],
        'google' => [
            'id'       => ['rrss_google_client_id',     'services.google_business_rrss.client_id',     'GOOGLE_RRSS_CLIENT_ID'],
            'secret'   => ['rrss_google_client_secret', 'services.google_business_rrss.client_secret', 'GOOGLE_RRSS_CLIENT_SECRET'],
            'redirect' => ['rrss_google_redirect_uri',  'services.google_business_rrss.redirect_uri',  'GOOGLE_RRSS_REDIRECT_URI'],
        ],
        // WhatsApp no publica en un muro, pero se configura igual: una app de
        // Meta con su token. Va acá para que la conexión se cargue desde la
        // interfaz como las demás, en vez de obligar a entrar al servidor.
        'whatsapp' => [
            'id'       => ['whatsapp_phone_number_id', 'services.whatsapp.phone_number_id', 'WHATSAPP_PHONE_NUMBER_ID'],
            'secret'   => ['whatsapp_token',           'services.whatsapp.token',           'WHATSAPP_TOKEN'],
            'redirect' => ['whatsapp_verify_token',    'services.whatsapp.verify_token',    'WHATSAPP_VERIFY_TOKEN'],
        ],
    ];

    /** Solo las redes sociales (WhatsApp se configura en su propia pantalla). */
    public static function redesSociales(): array
    {
        return ['meta', 'linkedin', 'google'];
    }

    public static function redes(): array
    {
        return array_keys(self::MAPA);
    }

    /** @return array{id:string,secret:string,redirect:string} */
    public static function campos(string $red): array
    {
        return array_keys(self::MAPA[$red] ?? []);
    }

    /**
     * Valor efectivo de un campo: primero lo guardado en la interfaz, si no el `.env`.
     */
    public static function valor(string $red, string $campo): string
    {
        [$claveConfiguracion, $claveServices] = self::MAPA[$red][$campo]
            ?? [null, null];

        if (! $claveConfiguracion) {
            return '';
        }

        $guardado = (string) Configuracion::get($claveConfiguracion, '');

        if ($guardado !== '') {
            return $campo === 'secret' ? self::descifrar($guardado) : $guardado;
        }

        return (string) config($claveServices);
    }

    public static function guardar(string $red, string $campo, string $valor): void
    {
        $clave = self::MAPA[$red][$campo][0] ?? null;

        if (! $clave) {
            return;
        }

        $valor = trim($valor);

        Configuracion::set($clave, $valor === '' ? '' : ($campo === 'secret' ? Crypt::encryptString($valor) : $valor));
    }

    /** Nombres de las variables del `.env` que faltan para esa red. */
    public static function faltantes(string $red): array
    {
        $faltantes = [];

        foreach (self::MAPA[$red] ?? [] as $campo => [$claveConfiguracion, $claveServices, $nombreEnv]) {
            if (self::valor($red, $campo) === '') {
                $faltantes[] = $nombreEnv;
            }
        }

        return $faltantes;
    }

    public static function lista(string $red): bool
    {
        return self::faltantes($red) === [];
    }

    /**
     * Un secreto guardado antes de que existiera el cifrado (o escrito a mano
     * en la base) no se puede descifrar. En vez de reventar la pantalla, se
     * devuelve tal cual: sigue sirviendo para autenticar.
     */
    private static function descifrar(string $valor): string
    {
        try {
            return Crypt::decryptString($valor);
        } catch (\Throwable) {
            return $valor;
        }
    }
}
