<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CuentaRrss extends Model
{
    use SoftDeletes;

    protected $table = 'cuentas_rrss';

    protected $fillable = [
        'red', 'nombre_cuenta', 'cuenta_id_externo', 'cuenta_id_secundario',
        'access_token', 'refresh_token', 'token_expira_en', 'datos',
        'activa', 'conectada_por', 'ultimo_error', 'ultima_publicacion_en',
    ];

    protected $casts = [
        'access_token'          => 'encrypted',
        'refresh_token'         => 'encrypted',
        'datos'                 => 'array',
        'activa'                => 'boolean',
        'token_expira_en'       => 'datetime',
        'ultima_publicacion_en' => 'datetime',
    ];

    // El token nunca debe viajar al frontend.
    protected $hidden = ['access_token', 'refresh_token'];

    public function conectadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conectada_por');
    }

    public function publicaciones(): HasMany
    {
        return $this->hasMany(PublicacionRrssCuenta::class);
    }

    public function scopeActivas($query)
    {
        return $query->where('activa', true);
    }

    public function getEtiquetaRedAttribute(): string
    {
        return match ($this->red) {
            'instagram'       => 'Instagram',
            'facebook'        => 'Facebook',
            'linkedin'        => 'LinkedIn',
            'google_business' => 'Google Business Profile',
            default           => $this->red,
        };
    }
}
