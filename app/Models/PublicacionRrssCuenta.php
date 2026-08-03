<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicacionRrssCuenta extends Model
{
    protected $table = 'publicaciones_rrss_cuentas';

    protected $fillable = [
        'publicacion_rrss_id', 'cuenta_rrss_id', 'estado', 'publicado_en',
        'id_publicacion_externa', 'url_publicacion', 'error',
    ];

    protected $casts = [
        'publicado_en' => 'datetime',
    ];

    public function publicacion(): BelongsTo
    {
        return $this->belongsTo(PublicacionRrss::class, 'publicacion_rrss_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaRrss::class, 'cuenta_rrss_id');
    }
}
