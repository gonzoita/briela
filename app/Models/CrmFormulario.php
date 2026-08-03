<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CrmFormulario extends Model
{
    protected $table = 'crm_formularios';

    protected $fillable = [
        'nombre', 'slug', 'etapa_id', 'responsable_id', 'fuente',
        'campos', 'titulo_formulario', 'descripcion_formulario',
        'texto_boton', 'mensaje_exito', 'email_notificacion', 'activo',
        'asignacion_tipo', 'responsables_ids', 'round_robin_ultimo', 'responsables_pesos',
        'gracias_tipo', 'gracias_url', 'captcha_activo',
    ];

    protected $casts = [
        'campos'            => 'array',
        'activo'            => 'boolean',
        'responsables_ids'  => 'array',
        'responsables_pesos'=> 'array',
        'captcha_activo'    => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function ($f) {
            if (!$f->slug) {
                $f->slug = Str::slug($f->nombre) . '-' . Str::random(6);
            }
        });
    }

    public function etapa()
    {
        return $this->belongsTo(CrmEtapa::class, 'etapa_id');
    }

    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }
}
