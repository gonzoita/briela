<?php

namespace BrielaConnect\Integrations;

use BrielaConnect\ApiClient;
use BrielaConnect\Utm;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Adaptadores de los plugins de formularios más comunes: cada uno traduce
 * el envío a los campos que espera POST /api/wp/leads y llama a
 * ApiClient::post(). Registrar el hook de un plugin que no está instalado
 * no hace nada — WordPress simplemente nunca dispara esa acción.
 *
 * Elementor Pro Forms tiene además su propia "acción de formulario"
 * ("Enviar a Briela", como Mailchimp/ActiveCampaign de fábrica) — eso es
 * Fase D. Aquí se cubre el envío normal del widget de Elementor (Pro y
 * gratis) además de Contact Form 7, WPForms y Gravity Forms.
 */
class FormsBridge
{
    public static function registrar(): void
    {
        add_action('wpcf7_mail_sent', [self::class, 'contact_form_7']);
        add_action('wpforms_process_complete', [self::class, 'wpforms'], 10, 4);
        add_action('gform_after_submission', [self::class, 'gravity_forms'], 10, 2);
        add_action('elementor_pro/forms/new_record', [self::class, 'elementor'], 10, 2);
    }

    public static function contact_form_7($contact_form): void
    {
        $submission = \WPCF7_Submission::get_instance();
        if (! $submission) {
            return;
        }

        $valores = self::normalizar($submission->get_posted_data());
        self::enviar($valores, $contact_form->title() ?: 'Contact Form 7');
    }

    public static function wpforms($fields, $entry, $form_data, $entry_id): void
    {
        $valores = [];
        foreach ($fields as $campo) {
            $etiqueta = $campo['name'] ?? $campo['type'] ?? '';
            $valores[strtolower((string) $etiqueta)] = $campo['value'] ?? '';
        }

        self::enviar($valores, $form_data['settings']['form_title'] ?? 'WPForms');
    }

    public static function gravity_forms($entry, $form): void
    {
        $valores = [];
        foreach ($form['fields'] as $campo) {
            $etiqueta = $campo->label ?? $campo->id;
            $valores[strtolower((string) $etiqueta)] = rgar($entry, (string) $campo->id);
        }

        self::enviar($valores, $form['title'] ?? 'Gravity Forms');
    }

    public static function elementor($record, $handler): void
    {
        $campos = $record->get('fields');
        $valores = [];

        foreach ($campos as $campo) {
            $etiqueta = $campo['title'] ?? $campo['id'] ?? '';
            $valores[strtolower((string) $etiqueta)] = is_array($campo['value'] ?? null)
                ? implode(', ', $campo['value'])
                : ($campo['value'] ?? '');
        }

        $nombreFormulario = $record->get_form_settings('form_name') ?: 'Elementor Form';
        self::enviar($valores, $nombreFormulario);
    }

    /**
     * Aplana un arreglo asociativo a claves en minúscula, para poder
     * buscar el campo de nombre/teléfono/email sin importar cómo lo haya
     * llamado quien armó el formulario.
     */
    private static function normalizar(array $datos): array
    {
        $planos = [];
        foreach ($datos as $clave => $valor) {
            $planos[strtolower((string) $clave)] = is_array($valor) ? implode(', ', $valor) : $valor;
        }
        return $planos;
    }

    /**
     * Busca el primer campo cuya clave contenga alguno de los nombres
     * candidatos (en español e inglés, que es lo que traen los plugins de
     * formularios por defecto).
     */
    private static function buscar_campo(array $valoresPlanos, array $candidatos): string
    {
        foreach ($valoresPlanos as $clave => $valor) {
            foreach ($candidatos as $candidato) {
                if (strpos($clave, $candidato) !== false) {
                    return (string) $valor;
                }
            }
        }
        return '';
    }

    private static function enviar(array $valoresPlanos, string $nombreFormulario): void
    {
        $nombre = self::buscar_campo($valoresPlanos, ['nombre', 'name', 'your-name']);

        // Sin nombre no hay lead que crear: el ERP lo exige.
        if (trim($nombre) === '') {
            return;
        }

        $utm = Utm::leer();

        $datos = [
            'nombre'        => $nombre,
            'email'         => self::buscar_campo($valoresPlanos, ['email', 'correo', 'e-mail', 'your-email']),
            'telefono'      => self::buscar_campo($valoresPlanos, ['telefono', 'teléfono', 'phone', 'tel', 'celular', 'whatsapp']),
            'empresa'       => self::buscar_campo($valoresPlanos, ['empresa', 'company']),
            'mensaje'       => self::buscar_campo($valoresPlanos, ['mensaje', 'message', 'comentario', 'your-message']),
            'fuente'        => $nombreFormulario,
            'pagina_origen' => $utm['pagina_origen'] ?? (wp_get_referer() ?: ''),
            'utm_source'    => $utm['utm_source'] ?? '',
            'utm_medium'    => $utm['utm_medium'] ?? '',
            'utm_campaign'  => $utm['utm_campaign'] ?? '',
        ];

        ApiClient::post('leads', array_filter($datos, static fn ($v) => $v !== ''));
    }
}
