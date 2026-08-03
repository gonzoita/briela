<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmTarea extends Model
{
    protected $table = 'crm_tareas';

    protected $fillable = [
        'lead_id', 'responsable_id', 'titulo', 'descripcion',
        'tipo', 'fecha_vencimiento', 'completada', 'completada_at',
    ];

    protected $casts = [
        'completada'        => 'boolean',
        'completada_at'     => 'datetime',
        'fecha_vencimiento' => 'date',
    ];

    public function lead()
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }
}
