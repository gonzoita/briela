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
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha_vencimiento' => 'date:Y-m-d',
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
