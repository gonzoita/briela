<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmEtapa extends Model
{
    protected $table = 'crm_etapas';

    protected $fillable = [
        'nombre', 'color', 'orden', 'accion_automatica',
        'es_ganado', 'es_perdido', 'activa',
    ];

    protected $casts = [
        'es_ganado'  => 'boolean',
        'es_perdido' => 'boolean',
        'activa'     => 'boolean',
    ];

    public function leads()
    {
        return $this->hasMany(CrmLead::class, 'etapa_id')->orderBy('orden_en_etapa');
    }
}
