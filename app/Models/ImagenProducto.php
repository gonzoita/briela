<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImagenProducto extends Model
{
    protected $table = 'imagenes_producto';

    protected $fillable = ['producto_id', 'ruta', 'es_principal', 'orden', 'drive_id'];

    protected $casts = ['es_principal' => 'boolean'];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function getUrlAttribute(): string
    {
        if (str_starts_with($this->ruta, 'http')) {
            return $this->ruta;
        }
        return asset('storage/' . $this->ruta);
    }
}
