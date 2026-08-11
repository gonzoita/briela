<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'waba_id' => env('WHATSAPP_WABA_ID'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    ],

    // ─── Módulo RRSS: programador de publicaciones ────────────────────────
    'meta_rrss' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'redirect_uri' => env('META_REDIRECT_URI'),
    ],

    'linkedin_rrss' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect_uri' => env('LINKEDIN_REDIRECT_URI'),
    ],

    'google_business_rrss' => [
        'client_id' => env('GOOGLE_RRSS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_RRSS_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_RRSS_REDIRECT_URI'),
    ],

    // ─── Consulta de NIT en los datos abiertos del RUES ───────────────────
    // Fuente: datos.gov.co, conjunto c82u-588k, publicado por Confecámaras
    // con los datos del Registro Mercantil sincronizados al RUES.
    //
    // Se usa este y no la API del portal del RUES porque el portal exige un
    // token que Confecámaras no entrega públicamente. Datos abiertos es una
    // API documentada (Socrata/SODA), gratuita y sin credenciales.
    //
    // El token es opcional: solo sube el límite de consultas por hora.
    // Se saca gratis en https://evergreen.data.socrata.com/signup
    'rues' => [
        'activo'  => env('RUES_ACTIVO', true),
        'url'     => env('RUES_URL', 'https://www.datos.gov.co/resource/c82u-588k.json'),
        'token'   => env('RUES_APP_TOKEN', ''),
        'timeout' => env('RUES_TIMEOUT', 6),
    ],

    // ─── Integración de IA (vía OpenRouter) ───────────────────────────────
    // Una sola credencial y un solo saldo para texto e imágenes.
    // Estos valores son el respaldo: si están definidos en Ajustes (base de
    // datos), mandan los de allá.
    'ia' => [
        'api_key'       => env('OPENROUTER_API_KEY'),
        'modelo_texto'  => env('OPENROUTER_MODELO_TEXTO', 'anthropic/claude-sonnet-5'),
        // Para tareas internas rápidas. Vacío = usa el de texto.
        'modelo_rapido' => env('OPENROUTER_MODELO_RAPIDO', ''),
        'modelo_imagen' => env('OPENROUTER_MODELO_IMAGEN', 'openai/gpt-image-2'),
        'modelo_voz'    => env('OPENROUTER_MODELO_VOZ', 'openai/gpt-audio-mini'),
        'voz'           => env('OPENROUTER_VOZ', 'nova'),
    ],

    /*
     * Google Drive — heredado, en retirada.
     *
     * Estos dos valores se leían con env() dentro del servicio, y eso impedía activar
     * la caché de configuración: con la caché puesta, env() deja de ver el .env y los
     * ajustes se perdían en silencio. Aquí sí se pueden cachear.
     */
    'google_drive' => [
        'credenciales' => env('GOOGLE_DRIVE_CREDENTIALS_PATH'),
        'carpeta'      => env('GOOGLE_DRIVE_FOLDER_ID', ''),
    ],

];
