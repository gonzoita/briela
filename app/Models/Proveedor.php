<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    protected $table = 'proveedores';

    protected $fillable = [
        'nombre', 'nit', 'contacto', 'telefono', 'email',
        'ciudad', 'direccion', 'tipo', 'activo', 'notas',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function ordenesCompra(): HasMany
    {
        return $this->hasMany(OrdenCompra::class);
    }

    public function inventarioItems(): HasMany
    {
        return $this->hasMany(InventarioItem::class);
    }
}
