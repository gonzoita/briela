<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una vez que un lead se acercó, y por dónde.
 *
 * Un mismo lead puede tener varios: escribió por WhatsApp, después llenó el
 * formulario de la web y más tarde respondió un anuncio. Cada acercamiento queda
 * registrado con su fecha y su campaña, y en el embudo se ven como etiquetas.
 */
class CrmLeadOrigen extends Model
{
    protected $table = 'crm_lead_origenes';

    protected $fillable = [
        'lead_id', 'canal', 'detalle', 'pagina',
        'utm_source', 'utm_medium', 'utm_campaign', 'referencia_externa',
    ];

    /**
     * Los canales que el sistema sabe nombrar, con su etiqueta y su color.
     *
     * El color no es decorativo: en un embudo con doscientas tarjetas, el color del
     * canal es lo que permite ver de un vistazo de dónde está llegando el trabajo.
     * Son tonos apagados a propósito, para no competir con el color de la marca.
     *
     * @return array<string, array{etiqueta:string, color:string, fondo:string}>
     */
    public static function canales(): array
    {
        return [
            'formulario' => ['etiqueta' => 'Formulario',  'color' => '#3538CD', 'fondo' => '#EEF4FF'],
            'web'        => ['etiqueta' => 'Sitio web',   'color' => '#175CD3', 'fondo' => '#EFF8FF'],
            'whatsapp'   => ['etiqueta' => 'WhatsApp',    'color' => '#067647', 'fondo' => '#ECFDF3'],
            'instagram'  => ['etiqueta' => 'Instagram',   'color' => '#C11574', 'fondo' => '#FDF2FA'],
            'facebook'   => ['etiqueta' => 'Facebook',    'color' => '#175CD3', 'fondo' => '#EFF8FF'],
            'google'     => ['etiqueta' => 'Google',      'color' => '#B54708', 'fondo' => '#FFFAEB'],
            'telefono'   => ['etiqueta' => 'Llamada',     'color' => '#344054', 'fondo' => '#F9FAFB'],
            'correo'     => ['etiqueta' => 'Correo',      'color' => '#5925DC', 'fondo' => '#F4F3FF'],
            'referido'   => ['etiqueta' => 'Referido',    'color' => '#026AA2', 'fondo' => '#F0F9FF'],
            'evento'     => ['etiqueta' => 'Feria o evento', 'color' => '#C4320A', 'fondo' => '#FEF6EE'],
            'importado'  => ['etiqueta' => 'Importado',   'color' => '#475467', 'fondo' => '#F9FAFB'],
            'manual'     => ['etiqueta' => 'Cargado a mano', 'color' => '#475467', 'fondo' => '#F9FAFB'],
            'otro'       => ['etiqueta' => 'Otro',        'color' => '#475467', 'fondo' => '#F9FAFB'],
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    /** Cómo se muestra este origen en el embudo. */
    public function comoEtiqueta(): array
    {
        $canal = static::canales()[$this->canal] ?? static::canales()['otro'];

        return [
            'canal'    => $this->canal,
            'etiqueta' => $canal['etiqueta'],
            'color'    => $canal['color'],
            'fondo'    => $canal['fondo'],
            // La campaña se muestra al pasar el ratón: es lo que dice qué anuncio
            // concreto trajo a esta persona.
            'detalle'  => $this->utm_campaign ?: $this->detalle,
            'fecha'    => $this->created_at?->format('d/m/Y'),
        ];
    }
}
