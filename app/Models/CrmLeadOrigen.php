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
     * Cada canal lleva su par de colores para el modo día y otro para el de noche.
     * Sin el segundo par, las etiquetas quedaban como islas claras sobre el fondo
     * oscuro: el contraste interno era correcto, pero la tarjeta se veía parcheada.
     *
     * @return array<string, array{etiqueta:string, color:string, fondo:string, color_oscuro:string, fondo_oscuro:string}>
     */
    public static function canales(): array
    {
        return [
            'formulario' => ['etiqueta' => 'Formulario',  'color' => '#3538CD', 'fondo' => '#EEF4FF', 'color_oscuro' => '#A4BCFD', 'fondo_oscuro' => '#1F2454'],
            'web'        => ['etiqueta' => 'Sitio web',   'color' => '#175CD3', 'fondo' => '#EFF8FF', 'color_oscuro' => '#84CAFF', 'fondo_oscuro' => '#182A45'],
            'whatsapp'   => ['etiqueta' => 'WhatsApp',    'color' => '#067647', 'fondo' => '#ECFDF3', 'color_oscuro' => '#6CE9A6', 'fondo_oscuro' => '#14302A'],
            'instagram'  => ['etiqueta' => 'Instagram',   'color' => '#C11574', 'fondo' => '#FDF2FA', 'color_oscuro' => '#F670C7', 'fondo_oscuro' => '#3B1A31'],
            'facebook'   => ['etiqueta' => 'Facebook',    'color' => '#175CD3', 'fondo' => '#EFF8FF', 'color_oscuro' => '#84CAFF', 'fondo_oscuro' => '#182A45'],
            'google'     => ['etiqueta' => 'Google',      'color' => '#B54708', 'fondo' => '#FFFAEB', 'color_oscuro' => '#FEC84B', 'fondo_oscuro' => '#33280F'],
            'telefono'   => ['etiqueta' => 'Llamada',     'color' => '#344054', 'fondo' => '#F9FAFB', 'color_oscuro' => '#D9DEE7', 'fondo_oscuro' => '#252B36'],
            'correo'     => ['etiqueta' => 'Correo',      'color' => '#5925DC', 'fondo' => '#F4F3FF', 'color_oscuro' => '#BDB4FE', 'fondo_oscuro' => '#251F41'],
            'referido'   => ['etiqueta' => 'Referido',    'color' => '#026AA2', 'fondo' => '#F0F9FF', 'color_oscuro' => '#7CD4FD', 'fondo_oscuro' => '#0F2A3A'],
            'evento'     => ['etiqueta' => 'Feria o evento', 'color' => '#C4320A', 'fondo' => '#FEF6EE', 'color_oscuro' => '#F7B27A', 'fondo_oscuro' => '#37220F'],
            'importado'  => ['etiqueta' => 'Importado',   'color' => '#475467', 'fondo' => '#F9FAFB', 'color_oscuro' => '#B4BCCA', 'fondo_oscuro' => '#252B36'],
            'manual'     => ['etiqueta' => 'Cargado a mano', 'color' => '#475467', 'fondo' => '#F9FAFB', 'color_oscuro' => '#B4BCCA', 'fondo_oscuro' => '#252B36'],
            'otro'       => ['etiqueta' => 'Otro',        'color' => '#475467', 'fondo' => '#F9FAFB', 'color_oscuro' => '#B4BCCA', 'fondo_oscuro' => '#252B36'],
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
            'color_oscuro' => $canal['color_oscuro'],
            'fondo_oscuro' => $canal['fondo_oscuro'],
            // La campaña se muestra al pasar el ratón: es lo que dice qué anuncio
            // concreto trajo a esta persona.
            'detalle'  => $this->utm_campaign ?: $this->detalle,
            'fecha'    => $this->created_at?->format('d/m/Y'),
        ];
    }
}
