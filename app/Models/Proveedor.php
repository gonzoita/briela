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
        // Lo que sale del RUT: ver `LectorRutService`. Mismos nombres que en `clientes`.
        'tipo_persona', 'tipo_identificacion', 'numero_identificacion', 'digito_verificacion',
        'actividad_economica', 'responsabilidades_fiscales', 'datos_rut',
    ];

    protected $casts = [
        'activo'                     => 'boolean',
        'responsabilidades_fiscales' => 'array',
        'datos_rut'                  => 'array',
    ];

    /**
     * ¿Cobra IVA? Lo dice su RUT: la casilla 53 trae el 48 (responsable) o el 49 (no
     * responsable). Sin RUT leído —o con un RUT que no marca ninguno— no se sabe, y se
     * devuelve null: **no se asume**, porque asumir mal es poner IVA donde no hay o dejarlo
     * por fuera donde sí.
     */
    public function responsableDeIva(): ?bool
    {
        $codigos = $this->responsabilidades_fiscales ?? [];

        return match (true) {
            in_array('48', $codigos, true) => true,
            in_array('49', $codigos, true) => false,
            default                        => null,
        };
    }

    /**
     * El IVA con el que debería arrancar una línea de orden de compra, si el RUT lo decide.
     *
     * Solo hay respuesta para «no responsable»: 0 %. Para un responsable la tarifa no se
     * escribe aquí —lo tributario vive en la configuración, no en el código, y hay bienes con
     * tarifa distinta—, así que devuelve null y la línea la pone quien compra.
     */
    public function ivaPorDefecto(): ?float
    {
        return $this->responsableDeIva() === false ? 0.0 : null;
    }

    public function ordenesCompra(): HasMany
    {
        return $this->hasMany(OrdenCompra::class);
    }
}
