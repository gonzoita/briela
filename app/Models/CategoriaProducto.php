<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaProducto extends Model
{
    protected $table = 'categorias_producto';

    protected $fillable = ['nombre', 'color', 'descripcion', 'activa'];

    protected $casts = ['activa' => 'boolean'];

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'categoria_id');
    }
}
