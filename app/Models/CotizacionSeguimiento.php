<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotizacionSeguimiento extends Model
{
    protected $table = 'cotizacion_seguimientos';

    protected $fillable = ['cotizacion_id', 'user_id', 'nota'];

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
