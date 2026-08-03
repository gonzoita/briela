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
