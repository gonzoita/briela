<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmActividad extends Model
{
    protected $table = 'crm_actividades';

    protected $fillable = ['lead_id', 'user_id', 'tipo', 'descripcion', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function lead()
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function registrar(int $leadId, string $tipo, string $descripcion, array $meta = []): void
    {
        static::create([
            'lead_id'     => $leadId,
            'user_id'     => auth()->id(),
            'tipo'        => $tipo,
            'descripcion' => $descripcion,
            'meta'        => $meta ?: null,
        ]);
    }
}
