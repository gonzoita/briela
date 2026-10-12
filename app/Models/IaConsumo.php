<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una llamada a la IA, tal como la vio esta instalación.
 *
 * No lleva dinero: el costo y el cobro viven en el superadmin. Esto responde «en qué gasto la
 * IA» (tokens, modelo, módulo, agente), y se escribe en un solo sitio: `IaService::llamar()`.
 */
class IaConsumo extends Model
{
    protected $table = 'ia_consumo';

    public $timestamps = false;

    protected $fillable = [
        'fecha', 'origen', 'agente_id', 'ejecucion_id', 'user_id', 'tipo', 'modelo',
        'tokens_entrada', 'tokens_salida', 'ms', 'por_proxy', 'ok', 'created_at',
    ];

    protected $casts = [
        'fecha'      => 'date',
        'por_proxy'  => 'boolean',
        'ok'         => 'boolean',
        'created_at' => 'datetime',
    ];
}
