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
}
