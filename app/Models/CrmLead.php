<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmLead extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'crm_leads';

    protected $fillable = [
        'sede_id', 'etapa_id', 'cliente_id', 'responsable_id', 'titulo',
        'nombre_contacto', 'email_contacto', 'telefono_contacto',
        'empresa_contacto', 'descripcion', 'fuente', 'estado',
        'motivo_cierre', 'fecha_cierre', 'orden_en_etapa',
        // Atribución: de dónde vino el lead cuando entra por el plugin de
        // WordPress (Briela Connect).
        'pagina_origen', 'utm_source', 'utm_medium', 'utm_campaign',
    ];

    protected $casts = [
        'fecha_cierre' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $lead) {
            $lead->sede_id ??= \App\Support\ContextoSede::paraGuardar();
        });
    }

    public function sede()
    {
        return $this->belongsTo(Sede::class);
    }

    public function etapa()
    {
        return $this->belongsTo(CrmEtapa::class, 'etapa_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function tareas()
    {
        return $this->hasMany(CrmTarea::class, 'lead_id')->orderBy('fecha_vencimiento');
    }

    /**
     * Por dónde se ha acercado este lead. Puede ser más de un canal: escribió por
     * WhatsApp, después llenó el formulario y más tarde respondió un anuncio.
     */
    public function origenes()
    {
        // Por id y no por fecha: varios orígenes que entran en el mismo segundo
        // quedarían en orden indefinido, y el primero es el que atribuye el negocio.
        return $this->hasMany(CrmLeadOrigen::class, 'lead_id')->orderBy('id');
    }

    public function notas()
    {
        return $this->hasMany(CrmNota::class, 'lead_id')->latest();
    }

    public function actividades()
    {
        return $this->hasMany(CrmActividad::class, 'lead_id')->latest();
    }

    public function cotizaciones()
    {
        return $this->hasMany(Cotizacion::class, 'lead_id');
    }

    public function nombreParaAuditoria(): string
    {
        return $this->titulo ?? ('#' . $this->id);
    }

    /**
     * Crea la cotización en borrador de este lead, con las condiciones que configuró la empresa.
     *
     * La usan el embudo (al entrar a una etapa de cotización) y la conversión a cliente. Antes
     * las dos escribían su propio texto fijo —«Precios en pesos colombianos…»—, que ignoraba lo
     * configurado y le llegaba igual a una instalación en otro país.
     */
    public function crearCotizacion(): Cotizacion
    {
        // Quien llama ya revisó que el lead tenga cliente: sin él no nace ninguna cotización.
        if (! $this->cliente_id) {
            throw new \LogicException(Cotizacion::SIN_CLIENTE);
        }

        $cot = Cotizacion::create([
            'lead_id'                 => $this->id,
            'cliente_id'              => $this->cliente_id,
            'moneda'                  => \App\Support\Monedas::base(),
            'tasa_cambio'             => 1,
            'responsable_id'          => $this->responsable_id ?? auth()->id(),
            'estado'                  => 'borrador',
            'condiciones_comerciales' => \App\Http\Controllers\CotizacionController::condicionesGenerales(),
            'notas_internas'          => "Generada automáticamente desde el lead \"{$this->titulo}\".",
        ]);

        CrmActividad::registrar($this->id, 'cotizacion', "Cotización {$cot->numero} generada automáticamente", ['cotizacion_id' => $cot->id]);

        return $cot;
    }

    /**
     * Lo que pasó con una cotización del lead mueve el lead. Lo llama `CotizacionObserver`.
     *
     * - Aprobada (por la pantalla, por el enlace del cliente o al generar la orden) → **ganado**.
     * - Rechazada o vencida → **perdido**, con el motivo, pero solo si el lead no tiene otra
     *   cotización viva: un cliente que rechaza la primera versión y está mirando la segunda
     *   sigue siendo una oportunidad.
     *
     * Un lead ganado no se vuelve perdido porque se venza otra cotización suya. Antes nada de
     * esto pasaba: el lead seguía «activo» hasta que alguien lo arrastrara, y los reportes de
     * conversión dependían de que los vendedores se acordaran.
     */
    public function alCambiarCotizacion(Cotizacion $cotizacion): void
    {
        if ($cotizacion->estado === 'aprobada') {
            if ($this->estado !== 'ganado') {
                $this->cerrar('ganado', null, "Cotización {$cotizacion->numero} aprobada");
            }

            return;
        }

        if (! in_array($cotizacion->estado, ['rechazada', 'vencida'], true) || $this->estado !== 'activo') {
            return;
        }

        $otraViva = $this->cotizaciones()
            ->where('id', '!=', $cotizacion->id)
            ->whereIn('estado', ['borrador', 'enviada', 'aprobada'])
            ->exists();

        if ($otraViva) {
            return;
        }

        $motivo = $cotizacion->estado === 'rechazada'
            ? ($cotizacion->motivo_rechazo ?: "El cliente rechazó la cotización {$cotizacion->numero}")
            : "La cotización {$cotizacion->numero} venció sin respuesta";

        $this->cerrar('perdido', $motivo, "Cotización {$cotizacion->numero} " . ($cotizacion->estado === 'rechazada' ? 'rechazada' : 'vencida'));
    }

    /** Cierra el lead como ganado o perdido y lo lleva a la etapa que lo dice, si existe. */
    public function cerrar(string $estado, ?string $motivo, string $porque): void
    {
        $etapa = CrmEtapa::where('activa', true)
            ->where($estado === 'ganado' ? 'es_ganado' : 'es_perdido', true)
            ->orderBy('orden')
            ->first();

        $cambios = ['estado' => $estado, 'fecha_cierre' => now(), 'motivo_cierre' => $motivo];

        if ($etapa && $etapa->id !== $this->etapa_id) {
            $cambios['etapa_id']       = $etapa->id;
            $cambios['orden_en_etapa'] = (int) static::where('etapa_id', $etapa->id)->max('orden_en_etapa') + 1;
        }

        $this->update($cambios);

        CrmActividad::registrar($this->id, 'cierre', "Lead marcado como {$estado}: {$porque}", ['estado' => $estado, 'automatico' => true]);
    }
}
