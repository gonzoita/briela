<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmNota extends Model
{
    protected $table = 'crm_notas';

    protected $fillable = ['lead_id', 'user_id', 'contenido'];

    public function lead()
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
